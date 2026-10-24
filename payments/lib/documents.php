<?php
/**
 * מסמכים חשבונאיים (שלב 5, פרק 7). מי מפיק:
 *  - תשלום שעבר בסליקה של מורנינג — מורנינג מפיקה לבד, עם העסקה (שלב 4).
 *  - כל תשלום אחר (ידני: מזומן, העברה, צ'ק, ביט ידני; סולק אחר; הסולק המדומה בבדיקות) — כאן, דרך ממשק המסמכים של
 *    מורנינג, אם לעוסק יש חיבור מורנינג לאותה סביבה. בלי חיבור — אין מסמך, והמנהל רואה "חסר מסמך".
 *  - החזר על תשלום שהמסמך שלו הופק כאן — חשבונית זיכוי (330) שמבטלת את המקור. (החזר בסליקה של מורנינג — בממשק
 *    שלהם, שם נוצר גם הזיכוי.) לעוסק פטור אין חשבונית זיכוי — ביטול הקבלה נעשה בממשק מורנינג.
 * המסמכים נוצרים מהשעון, עם ניסיונות חוזרים — תקלה אצל מורנינג לא עוצרת תשלום.
 */

declare(strict_types=1);

require_once __DIR__ . '/checkout.php';

const DOC_MAX_ATTEMPTS = 8;
const PAY_METHODS = ['card' => 'כרטיס אשראי', 'bit' => 'ביט', 'cash' => 'מזומן', 'transfer' => 'העברה בנקאית', 'cheque' => 'צ\'ק', 'other' => 'אחר'];

/** החיבור שמפיק מסמכים לעוסק בסביבה — מורנינג בלבד בשלב זה. */
function docs_provider(array $merchant, string $env): ?MorningProvider
{
    $pa = db_one("SELECT * FROM provider_account WHERE merchant = ? AND env = ? AND provider = 'morning' AND status = 'active' ORDER BY created_at LIMIT 1", [$merchant['id'], $env]);
    $p = $pa ? provider_from_account($pa) : null;
    return $p instanceof MorningProvider ? $p : null;
}

/** האם התשלום עבר בסליקה שמפיקה מסמך בעצמה (ולכן לא מפיקים כאן). */
function payment_self_documented(array $pay): bool
{
    if (!str_starts_with((string)$pay['provider'], 'pa_')) return false;
    $pa = db_one('SELECT * FROM provider_account WHERE id = ?', [$pay['provider']]);
    try {
        return $pa && provider_from_account($pa)->capabilities()['documents'];
    } catch (ApiError) {
        return false;
    }
}

/** שורת התשלום במסמך — בקודים של מורנינג. */
function doc_payment_row(array $pay): array
{
    $m = $pay['pay_method'] ?: (str_starts_with((string)$pay['provider'], 'pa_') || $pay['provider'] === 'fake' ? 'card' : 'other');
    $row = ['date' => gmdate('Y-m-d', (int)$pay['created_at']), 'price' => round((int)$pay['amount'] / 100, 2), 'currency' => 'ILS'];
    switch ($m) {
        case 'cash': return $row + ['type' => 1];
        case 'cheque': return $row + ['type' => 2, 'chequeNum' => mb_substr($pay['reference'], 0, 20) ?: '0', 'bankName' => '-', 'bankBranch' => '0', 'bankAccount' => '0'];
        case 'transfer': return $row + ['type' => 4] + ($pay['reference'] !== '' ? ['transactionId' => mb_substr($pay['reference'], 0, 60)] : []);
        case 'bit': return $row + ['type' => 10, 'appType' => 1] + ($pay['reference'] !== '' ? ['transactionId' => mb_substr($pay['reference'], 0, 60)] : []);
        case 'card':
            $pm = $pay['method'] ? db_one('SELECT last4 FROM payment_method WHERE id = ?', [$pay['method']]) : null;
            return $row + ['type' => 3, 'dealType' => 1, 'numPayments' => 1] + ($pm && $pm['last4'] !== '' ? ['cardNum' => $pm['last4']] : []);
        default: return $row + ['type' => 11, 'subType' => 9];
    }
}

/** מסמכים שחסרים: תשלומים שעברו בלי מסמך, והחזרים בלי זיכוי. מהשעון. */
function tax_docs_issue(int $limit = 20): int
{
    $n = 0;
    $rows = db_all("SELECT p.* FROM payment p WHERE p.status IN ('succeeded','partially_refunded','refunded') AND p.amount > 0 AND p.provider <> 'none'
        AND NOT EXISTS (SELECT 1 FROM tax_document d WHERE d.payment = p.id AND d.refund IS NULL) AND p.doc_attempts < ? AND p.created_at > ?
        ORDER BY p.created_at LIMIT ?", [DOC_MAX_ATTEMPTS, now() - 30 * 86400, $limit]);
    foreach ($rows as $pay) {
        if (payment_self_documented($pay)) continue;
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$pay['account']]);
        $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
        $dp = docs_provider($m, $pay['env']);
        if (!$dp) continue; // אין מי שיפיק — מוצג למנהל כ"חסר מסמך"
        try {
            $lines = $pay['invoice'] ? invoice_lines_for_provider($pay['invoice']) : [['description' => 'תשלום', 'quantity' => 1, 'amount' => (int)$pay['amount'], 'tax_behavior' => 'inclusive']];
            $doc = $dp->issueDocument($acc, $m['tax_status'] === 'exempt' ? 400 : 320, $lines, [doc_payment_row($pay)]);
            db_run('INSERT OR IGNORE INTO tax_document (id, payment, invoice, provider, external_id, type, number, url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [new_id('doc'), $pay['id'], $pay['invoice'], $dp->id(), $doc['id'], $doc['type'], $doc['number'], $doc['url'], now()]);
            db_run("UPDATE payment SET doc_error = '' WHERE id = ?", [$pay['id']]);
            $n++;
        } catch (ApiError $e) {
            db_run('UPDATE payment SET doc_attempts = doc_attempts + 1, doc_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 300), $pay['id']]);
        }
    }
    // זיכויים
    $refunds = db_all("SELECT r.*, p.account, p.env, p.invoice, p.provider AS pay_provider FROM refund r JOIN payment p ON p.id = r.payment
        WHERE NOT EXISTS (SELECT 1 FROM tax_document d WHERE d.refund = r.id) AND r.doc_attempts < ? AND r.created_at > ? LIMIT ?", [DOC_MAX_ATTEMPTS, now() - 30 * 86400, $limit]);
    foreach ($refunds as $r) {
        $orig = db_one('SELECT * FROM tax_document WHERE payment = ? AND refund IS NULL', [$r['payment']]);
        if (!$orig || payment_self_documented(['provider' => $r['pay_provider']])) continue; // בסליקת מורנינג — הזיכוי בממשק שלהם
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$r['account']]);
        $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
        if ($m['tax_status'] === 'exempt') continue; // לפטור אין חשבונית זיכוי
        $dp = docs_provider($m, $r['env']);
        if (!$dp) continue;
        try {
            $doc = $dp->issueDocument($acc, 330, [['description' => 'זיכוי: ' . $r['reason'], 'quantity' => 1, 'amount' => (int)$r['amount'], 'tax_behavior' => 'inclusive']], [], [$orig['external_id']], $r['reason']);
            db_run('INSERT OR IGNORE INTO tax_document (id, payment, invoice, refund, provider, external_id, type, number, url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [new_id('doc'), $r['payment'], $r['invoice'], $r['id'], $dp->id(), $doc['id'], $doc['type'], $doc['number'], $doc['url'], now()]);
            $n++;
        } catch (ApiError $e) {
            db_run('UPDATE refund SET doc_attempts = doc_attempts + 1, doc_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 300), $r['id']]);
        }
    }
    return $n;
}

/**
 * תשלום ידני (מזומן, העברה, צ'ק, ביט שהגיע ישירות): המנהל רושם, והמסמך יוצא מהשעון.
 * ‏purpose: ‏topup — לארנק; ‏invoice — סוגר חשבונית פתוחה (ומחזיר מנוי באיחור לפעיל).
 */
function manual_payment(array $tenant, array $in): array
{
    $acc = db_one('SELECT a.* FROM account a JOIN merchant m ON m.id = a.merchant WHERE a.id = ? AND m.tenant = ?', [(string)($in['account'] ?? ''), $tenant['id']]);
    if (!$acc) throw new ApiError('החשבון לא נמצא', 'not_found', 404);
    $method = (string)($in['method'] ?? '');
    if (!isset(PAY_METHODS[$method]) || $method === 'card') throw new ApiError('אמצעי: מזומן, העברה, צ\'ק, ביט או אחר', 'bad_method');
    $ref = mb_substr(trim((string)($in['reference'] ?? '')), 0, 60);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 240);
    $purpose = (string)($in['purpose'] ?? 'topup');
    return db_tx(function () use ($tenant, $acc, $method, $ref, $note, $purpose, $in) {
        $cus = db_one('SELECT * FROM customer WHERE account = ? LIMIT 1', [$acc['id']]);
        if ($purpose === 'invoice') {
            $inv = db_one("SELECT * FROM invoice WHERE id = ? AND account = ? AND status = 'open'", [(string)($in['invoice'] ?? ''), $acc['id']]);
            if (!$inv) throw new ApiError('אין חשבונית פתוחה כזו', 'not_found', 404);
            $amount = (int)$inv['total'];
        } else {
            $amount = (int)($in['agorot'] ?? 0);
            if ($amount <= 0 || $amount > 10_000_000) throw new ApiError('סכום לא תקין', 'bad_amount');
            $inv = invoice_create($acc, [['client' => $cus['client'], 'product' => null, 'price' => null, 'description' => 'טעינת יתרה (' . PAY_METHODS[$method] . ')', 'quantity' => 1, 'amount' => $amount, 'tax_behavior' => 'inclusive']], 'open');
        }
        $id = new_id('pay');
        db_run('INSERT INTO payment (id, account, env, invoice, provider, amount, status, pay_method, reference, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $acc['id'], $acc['env'], $inv['id'], 'manual', $amount, 'succeeded', $method, $ref, now()]);
        db_run("UPDATE invoice SET status = 'paid', paid_at = ?, next_attempt_at = NULL WHERE id = ?", [now(), $inv['id']]);
        if ($purpose === 'topup') {
            $bal = wallet_post($acc['id'], agorot_to_micros($amount), 'topup', 'invoice', $inv['id'], PAY_METHODS[$method] . ($ref !== '' ? " $ref" : ''), 'admin:' . $tenant['id']);
            foreach (db_all('SELECT * FROM customer WHERE account = ?', [$acc['id']]) as $c) {
                webhook_emit($c['client'], $c['env'], 'wallet.topped_up', $c['external_id'], ['amount' => $amount, 'balance' => micros_to_agorot($bal)]);
                webhook_emit($c['client'], $c['env'], 'entitlement.changed', $c['external_id'], []);
            }
        } elseif ($inv['subscription']) {
            db_run("UPDATE subscription SET status = 'active', past_due_since = NULL, first_paid_at = COALESCE(first_paid_at, ?) WHERE id = ? AND status IN ('past_due','active')", [now(), $inv['subscription']]);
            $s = db_one('SELECT * FROM subscription WHERE id = ?', [$inv['subscription']]);
            $sc = db_one('SELECT * FROM customer WHERE id = ?', [$s['customer']]);
            webhook_emit($s['client'], $sc['env'], 'subscription.renewed', $sc['external_id'], ['subscription' => $s['id'], 'invoice' => $inv['id'], 'amount' => $amount]);
            webhook_emit($s['client'], $sc['env'], 'entitlement.changed', $sc['external_id'], []);
        }
        audit('admin', $tenant['id'], 'payment.manual', 'payment', $id, ['method' => $method, 'amount' => $amount, 'reference' => $ref, 'note' => $note], $tenant['id']);
        return ['payment' => $id, 'invoice' => $inv['id'], 'amount' => $amount];
    });
}
