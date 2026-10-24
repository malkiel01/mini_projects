<?php
/**
 * תשלום (שלב 3): סשן קנייה (checkout), חשבונית פנימית, תשלום, אמצעי תשלום שמור, חיוב יזום, החזר.
 * פרקים 2.7, 4.3, 4.6, 9.2, 9.6. המסמך החשבונאי (קבלה / חשבונית מס) — שלב 5, דרך Morning.
 */

declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/notify.php';

const CHECKOUT_TTL = 3600;
const TOPUP_MIN = 500;          // אגורות
const TOPUP_MAX = 500_000;
const CHECKOUT_MODES = ['payment', 'subscription', 'topup', 'setup', 'invoice'];
const RECURRING_TYPES = ['plan', 'seat', 'addon'];
const CHECKOUT_INTERNAL_META = ['unit_amount' => 1, 'link' => 1, 'form' => 1, 'trial_days' => 1];

/** סכום שורה כפי שהלקוח משלם — מחיר "לא כולל מע"מ" עם המע"מ. */
function gross_amount(int $amount, string $taxBehavior, int $vatRate): int
{
    return $taxBehavior === 'exclusive' && $vatRate ? $amount + (int)round($amount * $vatRate / 10_000) : $amount;
}

// ---------- סכומים ----------

/** סכום מחזור של פריט מנוי, לפי מספר המחזור (מחיר מבצע ל-intro_periods הראשונים). */
function item_amount(array $price, int $qty, int $periodIndex): int
{
    $unit = ($price['intro_amount'] !== null && $periodIndex < (int)$price['intro_periods']) ? (int)$price['intro_amount'] : (int)$price['amount'];
    return $unit * $qty;
}

/**
 * חשבונית מהשורות: מע"מ לפי העוסק. שורה "כוללת מע"מ" — המע"מ בתוכה; "לא כוללת" — נוסף.
 * @param array<array{client:string,product:?string,price:?string,description:string,quantity:float|int,amount:int,tax_behavior:string}> $lines
 */
function invoice_totals(array $merchant, array $lines): array
{
    $r = (int)$merchant['vat_rate'];
    $incl = $excl = 0;
    foreach ($lines as $l) {
        if ($l['tax_behavior'] === 'exclusive') $excl += (int)$l['amount'];
        else $incl += (int)$l['amount'];
    }
    $vatIncl = $r ? (int)round($incl * $r / (10_000 + $r)) : 0;
    $vatExcl = $r ? (int)round($excl * $r / 10_000) : 0;
    $total = $incl + $excl + $vatExcl;
    return ['subtotal' => $total - $vatIncl - $vatExcl, 'vat' => $vatIncl + $vatExcl, 'total' => $total, 'vat_rate' => $r];
}

function invoice_create(array $acc, array $lines, string $status, array $extra = []): array
{
    $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
    $t = invoice_totals($m, $lines);
    $id = new_id('inv');
    db_run('INSERT INTO invoice (id, account, merchant, env, subscription, checkout, period_start, period_end, subtotal, vat_rate, vat, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $acc['id'], $m['id'], $acc['env'], $extra['subscription'] ?? null, $extra['checkout'] ?? null,
        $extra['period_start'] ?? null, $extra['period_end'] ?? null, $t['subtotal'], $t['vat_rate'], $t['vat'], $t['total'], $status, now(),
    ]);
    foreach ($lines as $l) {
        db_run('INSERT INTO invoice_line (id, invoice, client, product, price, description, quantity, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [new_id('il'), $id, $l['client'], $l['product'], $l['price'], mb_substr($l['description'], 0, 200), $l['quantity'], (int)$l['amount']]);
    }
    return db_one('SELECT * FROM invoice WHERE id = ?', [$id]);
}

// ---------- סשן קנייה ----------

/**
 * checkout_create (פרק 4.3): ‏{external_id, mode, items?, amount?, return_url, cancel_url, meta?}
 * → {checkout, url, expires_at, amount}. הסכום נקבע **כאן**, מהמחירים שבמסך הניהול — לא מהמערכת ולא מהדפדפן.
 */
function checkout_create(array $client, string $env, array $in, array $internal = []): array
{
    $cus = customer_find($client, $env, external_id($in));
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
    $mode = (string)($in['mode'] ?? '');
    if (!in_array($mode, CHECKOUT_MODES, true)) throw new ApiError('mode: payment, subscription, topup או setup', 'bad_mode');
    foreach (['return_url', 'cancel_url'] as $k) {
        if (!valid_url((string)($in[$k] ?? ''))) throw new ApiError("$k — כתובת https מלאה", 'bad_url');
    }
    $items = [];
    $amount = 0;
    $taxLines = [];
    $trialDays = 0;
    if ($mode === 'payment' || $mode === 'subscription') {
        $raw = $in['items'] ?? null;
        if (!is_array($raw) || !$raw || count($raw) > 20) throw new ApiError('items — 1 עד 20 פריטים', 'bad_items');
        $interval = null;
        foreach ($raw as $it) {
            [$p, $pr] = current_price($client['id'], (string)($it['product'] ?? ''));
            $qty = (int)($it['quantity'] ?? 1);
            if ($qty < 1 || $qty > 10_000) throw new ApiError('כמות לא תקינה', 'bad_quantity');
            $recurring = in_array($p['type'], RECURRING_TYPES, true);
            if ($mode === 'subscription' && !$recurring) throw new ApiError("{$p['code']} אינו מוצר מנוי", 'bad_items');
            if ($mode === 'payment' && !in_array($p['type'], ['unit', 'credit_pack'], true)) throw new ApiError("{$p['code']} — לא לקנייה חד-פעמית", 'bad_items');
            if ($mode === 'subscription') {
                $key = $pr['interval'] . ':' . $pr['interval_count'];
                if ($interval !== null && $interval !== $key) throw new ApiError('כל פריטי המנוי באותו מחזור', 'mixed_intervals');
                $interval = $key;
                $trialDays = max($trialDays, (int)$pr['trial_days']);
            }
            $items[] = ['product' => $p['code'], 'price' => $pr['id'], 'quantity' => $qty];
            // סכום לבחירת הלקוח — רק מקישור תשלום (פנימי), אף פעם לא מה-API
            $unit = $mode === 'payment' && isset($internal['unit_amount']) ? (int)$internal['unit_amount'] : (int)$pr['amount'];
            $taxLines[] = ['amount' => $mode === 'subscription' ? item_amount($pr, $qty, 0) : $unit * $qty, 'tax_behavior' => $pr['tax_behavior']];
        }
        // מחיר "לא כולל מע"מ" — הלקוח משלם כולל; אותו חישוב כמו בחשבונית, כדי שהסכום אצל הסולק יתאים לה
        $amount = invoice_totals(db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]), $taxLines)['total'];
        // ניסיון חינם — רק בפעם הראשונה במערכת הזו
        if ($trialDays && db_one('SELECT 1 FROM subscription WHERE customer = ?', [$cus['id']])) $trialDays = 0;
        if ($mode === 'subscription' && $trialDays) $amount = 0;
    } elseif ($mode === 'topup') {
        $amount = (int)($in['amount'] ?? 0);
        if ($amount < TOPUP_MIN || $amount > TOPUP_MAX) throw new ApiError('סכום טעינה: ₪5 עד ₪5,000', 'bad_amount');
    } elseif ($mode === 'invoice') {
        // תשלום חשבונית פתוחה (חידוש שנכשל) — מהחשבון שלי או מקישור במייל
        $inv = db_one("SELECT * FROM invoice WHERE id = ? AND account = ? AND status = 'open'", [(string)($in['invoice'] ?? ''), $acc['id']]);
        if (!$inv) throw new ApiError('אין חשבונית פתוחה לתשלום', 'not_found', 404);
        $amount = (int)$inv['total'];
        $invoiceId = $inv['id'];
    }
    // כללים (פרק 12.4): חסימה לפני שנוצרת קנייה
    if (function_exists('rules_eval')) {
        $rr = rules_eval($client['tenant'], ['client' => $client['id'], 'env' => $env, 'mode' => $mode, 'amount' => $amount,
            'customer_type' => $acc['customer_type'], 'products' => array_column($items, 'product')]);
        if ($rr['block'] !== null) throw new ApiError($rr['block'], 'blocked_by_rule', 403);
    }
    // ‏meta של המערכת — רק להחזרה אליה. המפתחות הפנימיים (סכום מקישור, ניסיון) נקבעים כאן בלבד
    $meta = array_diff_key(is_array($in['meta'] ?? null) ? $in['meta'] : [], CHECKOUT_INTERNAL_META);
    $meta = array_intersect_key($internal, CHECKOUT_INTERNAL_META) + $meta;
    if ($trialDays) $meta['trial_days'] = $trialDays;
    $id = new_id('chk');
    db_run('INSERT INTO checkout (id, client, env, customer, account, mode, items, amount, return_url, cancel_url, meta, invoice, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $client['id'], $env, $cus['id'], $acc['id'], $mode, json_encode($items), $amount,
        $in['return_url'], $in['cancel_url'], json_encode((object)$meta, JSON_UNESCAPED_UNICODE),
        $invoiceId ?? null, now() + CHECKOUT_TTL, now(),
    ]);
    return ['checkout' => $id, 'url' => base_url() . 'pay/?c=' . $id, 'expires_at' => now() + CHECKOUT_TTL, 'amount' => $amount, 'trial_days' => $trialDays];
}

function checkout_load(string $id): ?array
{
    $c = db_one('SELECT * FROM checkout WHERE id = ?', [$id]);
    if (!$c) return null;
    if ($c['status'] === 'open' && (int)$c['expires_at'] < now()) {
        if (db_run("UPDATE checkout SET status = 'expired' WHERE id = ? AND status = 'open'", [$id]) === 1 && ($link = json_obj($c['meta'])['link'] ?? '') !== '') {
            db_run('UPDATE payment_link SET uses = MAX(0, uses - 1) WHERE code = ? AND client = ? AND env = ?', [(string)$link, $c['client'], $c['env']]);
        }
        $c['status'] = 'expired';
        $cus = db_one('SELECT * FROM customer WHERE id = ?', [$c['customer']]);
        webhook_emit($c['client'], $c['env'], 'checkout.expired', $cus['external_id'], ['checkout' => $id]);
    }
    return $c;
}

/** מה הלקוח רואה בדף התשלום — שורות, סכום, ונוסח הגילוי שהחוק מחייב (§13ג). */
function checkout_view(array $c): array
{
    $client = db_one('SELECT * FROM client WHERE id = ?', [$c['client']]);
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$c['account']]);
    $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
    $meta = json_obj($c['meta']);
    $lines = [];
    $disclose = [];
    $recurring = 0;
    $interval = 'month';
    $per = 'לחודש';
    foreach (json_decode($c['items'], true) ?: [] as $it) {
        $pr = db_one('SELECT pr.*, p.name, p.type, p.code FROM price pr JOIN product p ON p.id = pr.product WHERE pr.id = ?', [$it['price']]);
        $qty = (int)$it['quantity'];
        $now = $c['mode'] === 'subscription' ? item_amount($pr, $qty, 0) : (int)($meta['unit_amount'] ?? $pr['amount']) * $qty;
        $lines[] = ['name' => $pr['name'] . ($qty > 1 ? " × $qty" : ''), 'amount' => gross_amount($now, $pr['tax_behavior'], (int)$m['vat_rate'])];
        if ($c['mode'] === 'subscription') {
            $interval = $pr['interval'];
            $per = $interval === 'year' ? 'לשנה' : ($interval === 'week' ? 'לשבוע' : 'לחודש');
            $recurring += gross_amount((int)$pr['amount'] * $qty, $pr['tax_behavior'], (int)$m['vat_rate']);
            if ($pr['intro_amount'] !== null && (int)$pr['intro_periods'] > 0) {
                $n = (int)$pr['intro_periods'];
                $unit = $pr['interval'] === 'year' ? ['לשנה הראשונה', 'השנים הראשונות'] : ['לחודש הראשון', 'החודשים הראשונים'];
                $disclose[] = 'מחיר מבצע ' . ($n === 1 ? $unit[0] : "ל-$n {$unit[1]}") . ', ואחר כך ' . money_text(gross_amount((int)$pr['amount'] * $qty, $pr['tax_behavior'], (int)$m['vat_rate'])) . " $per — " . $pr['name'] . '.';
            }
            if ($pr['renewal'] === 'consent') $disclose[] = 'המנוי לתקופה קצובה. לפני סוף התקופה תקבל הודעה, והוא יחודש רק אם תאשר.';
        }
    }
    if ($c['mode'] === 'topup') $lines[] = ['name' => 'טעינת יתרה', 'amount' => (int)$c['amount']];
    if ($c['mode'] === 'setup') $lines[] = ['name' => 'עדכון אמצעי תשלום — בלי חיוב', 'amount' => 0];
    if ($c['mode'] === 'invoice') foreach (db_all('SELECT description, amount FROM invoice_line WHERE invoice = ?', [$c['invoice']]) as $l) $lines[] = ['name' => $l['description'], 'amount' => (int)$l['amount']];
    if ($c['mode'] === 'subscription') {
        $trial = (int)($meta['trial_days'] ?? 0);
        array_unshift($disclose, $trial
            ? "ניסיון חינם ל-$trial ימים. בסופו יחויב " . money_text($recurring) . " $per, אלא אם תבטל לפני כן."
            : 'יחויב עכשיו, ואחר כך ' . money_text($recurring) . " $per בכל מחזור, עד שתבטל.");
        $disclose[] = 'אפשר לבטל בכל עת בעמוד "החשבון שלי" — החיוב נעצר מסוף התקופה ששולמה.';
        $disclose[] = 'ביטול תוך 14 ימים מהעסקה: החזר יחסי, בניכוי דמי ביטול של 5% או ₪100, הנמוך מביניהם.';
    }
    $vat = (int)$m['vat_rate'];
    return [
        'client' => $client['name'], 'merchant' => $m['name'], 'mode' => $c['mode'], 'lines' => $lines, 'amount' => (int)$c['amount'],
        'vat_note' => $vat ? 'המחירים כוללים מע"מ ' . ($vat / 100) . '%' : 'עוסק פטור — ללא מע"מ',
        'disclose' => $disclose, 'status' => $c['status'], 'env' => $c['env'],
        'methods' => checkout_methods($c, $m),
        'seller' => array_filter(['name' => $m['name'], 'tax_id' => $m['tax_id'], 'address' => $m['address'], 'phone' => $m['phone'], 'email' => $m['email']]),
        'terms_url' => $client['terms_url'], 'privacy_url' => $client['privacy_url'],
    ];
}

/** אמצעים לפי המטריצה (פרק 6.3): מנוי וטעינה אוטומטית — רק מה שאפשר לחייב שוב. */
function checkout_methods(array $c, array $m): array
{
    $p = provider_for($m, $c['env']);
    // סולק שלא שומר כרטיס בלי חיוב: ניסיון חינם מתחיל בלי כרטיס (התשלום — בסוף הניסיון, בקישור), והחלפת כרטיס — בתשלום הבא
    if ((int)$c['amount'] === 0 && !$p->capabilities()['zero_amount']) return $c['mode'] === 'subscription' ? ['none'] : [];
    $out = [];
    foreach ($p->methods() as $method => $modes) if (in_array($c['mode'], $modes, true)) $out[] = $method;
    // כללים: הסתרת אמצעים / רק אמצעים מסוימים
    if (function_exists('rules_eval')) {
        $rr = rules_eval(db_one('SELECT tenant FROM client WHERE id = ?', [$c['client']])['tenant'], checkout_rule_ctx($c));
        $out = array_values(array_diff($out, $rr['hide']));
        if ($rr['only'] !== null) $out = array_values(array_intersect($out, $rr['only']));
    }
    return $out;
}

/** הלקוח אישר בדף התשלום → סשן אצל הסולק → כתובת להפניה. */
function checkout_start(array $c, string $method): string
{
    if ($c['status'] !== 'open') throw new ApiError('הקנייה כבר נסגרה או פגה', 'closed', 409);
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$c['account']]);
    $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
    if (!in_array($method, checkout_methods($c, $m), true)) throw new ApiError('אמצעי התשלום לא זמין כאן', 'bad_method');
    if ($method === 'none') { // ניסיון בלי כרטיס
        db_run('UPDATE checkout SET provider = ?, consent_at = ? WHERE id = ?', ['none', now(), $c['id']]);
        db_tx(fn() => checkout_complete(db_one('SELECT * FROM checkout WHERE id = ?', [$c['id']]), new Txn('', 'succeeded', 0)));
        return base_url() . 'pay/?c=' . $c['id'];
    }
    $p = provider_for($m, $c['env']);
    $save = $method === 'card';
    [$url, $ref] = $p->createSession($c, (int)$c['amount'], $save, [$method], base_url() . 'pay/return.php?c=' . $c['id']);
    db_run('UPDATE checkout SET provider = ?, provider_ref = ?, consent_at = ? WHERE id = ?', [$p->id(), $ref, now(), $c['id']]);
    return $url;
}

/**
 * שואל את הסולק מה קרה, ואם שולם — סוגר את הקנייה (פעם אחת בלבד). נקרא מהחזרה מהדפדפן,
 * ומהעבודה המתוזמנת לקניות פתוחות (אם הלקוח סגר את החלון לפני החזרה).
 */
function checkout_sync(array $c): array
{
    if ($c['status'] !== 'open' || $c['provider_ref'] === '') return $c;
    $txn = provider($c['provider'])->query($c['provider_ref']);
    if (!$txn || $txn->status !== 'succeeded') return $c;
    if ($txn->amount !== (int)$c['amount'] || $txn->currency !== $c['currency']) {
        audit('system', 'checkout', 'checkout.amount_mismatch', 'checkout', $c['id'], ['want' => (int)$c['amount'], 'got' => $txn->amount]);
        throw new ApiError('הסכום אצל הסולק לא תואם — הקנייה לא נסגרה. פנה לתמיכה.', 'amount_mismatch', 409);
    }
    db_tx(fn() => checkout_complete($c, $txn));
    // אמצעי תשלום חדש — ניסיון מיידי של חשבוניות פתוחות (מחוץ לטרנזקציה: חיוב הוא קריאה לסולק)
    if ($c['mode'] === 'setup' && function_exists('invoices_retry_account')) invoices_retry_account($c['account']);
    return db_one('SELECT * FROM checkout WHERE id = ?', [$c['id']]);
}

function save_method(array $acc, string $provider, Txn $txn): ?string
{
    if (!$txn->token) return null;
    $id = new_id('pm');
    db_run('INSERT INTO payment_method (id, account, provider, kind, token_enc, brand, last4, exp, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $acc['id'], $provider, $txn->method, seal($txn->token), $txn->brand, $txn->last4, $txn->exp, now()]);
    db_run('UPDATE account SET default_method = ? WHERE id = ?', [$id, $acc['id']]);
    return $id;
}

function payment_record(array $acc, string $provider, Txn $txn, ?string $invoice, ?string $checkout, ?string $method): string
{
    $id = new_id('pay');
    if ($txn->status === 'succeeded' && $txn->doc) {
        db_run('INSERT OR IGNORE INTO tax_document (id, payment, invoice, provider, external_id, type, number, allocation_number, url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            new_id('doc'), $id, $invoice, $provider, $txn->doc['id'], (int)$txn->doc['type'], (string)$txn->doc['number'], (string)($txn->doc['allocation'] ?? ''), (string)($txn->doc['url'] ?? ''), now(),
        ]);
    }
    db_run('INSERT INTO payment (id, account, env, invoice, checkout, method, provider, amount, status, provider_txn, failure_code, failure_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $acc['id'], $acc['env'], $invoice, $checkout, $method, $provider, $txn->amount,
        $txn->status === 'succeeded' ? 'succeeded' : 'failed', $txn->ref, $txn->failureCode, $txn->failureMessage, now(),
    ]);
    return $id;
}

/** סגירת קנייה ששולמה — בתוך טרנזקציה, אידמפוטנטי (קנייה שנסגרה לא נסגרת שוב). */
function checkout_complete(array $c, Txn $txn): void
{
    $fresh = db_one('SELECT * FROM checkout WHERE id = ?', [$c['id']]);
    if ($fresh['status'] !== 'open') return;
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$c['account']]);
    $cus = db_one('SELECT * FROM customer WHERE id = ?', [$c['customer']]);
    $methodId = save_method($acc, $c['provider'], $txn);
    $items = json_decode($c['items'], true) ?: [];
    $result = ['mode' => $c['mode']];
    $lineFor = function (array $it, int $amount) use ($c) {
        $pr = db_one('SELECT pr.*, p.name, p.code, p.type, p.id AS pid FROM price pr JOIN product p ON p.id = pr.product WHERE pr.id = ?', [$it['price']]);
        return ['client' => $c['client'], 'product' => $pr['pid'], 'price' => $pr['id'], 'description' => $pr['name'], 'quantity' => $it['quantity'], 'amount' => $amount, 'tax_behavior' => $pr['tax_behavior'], 'pr' => $pr];
    };

    if ($c['mode'] === 'subscription') {
        $meta = json_obj($c['meta']);
        $trial = (int)($meta['trial_days'] ?? 0);
        $first = db_one('SELECT * FROM price WHERE id = ?', [$items[0]['price']]);
        $start = now();
        $end = $trial ? $start + $trial * 86400 : period_add($start, $first['interval'], (int)$first['interval_count'], (int)gmdate('j', $start));
        $subId = new_id('sub');
        db_run('INSERT INTO subscription (id, account, customer, client, status, source, current_period_start, current_period_end, trial_end, first_paid_at, renew_consent_until, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $subId, $acc['id'], $cus['id'], $c['client'], $trial ? 'trialing' : 'active', 'checkout', $start, $end,
            $trial ? $end : null, $trial ? null : now(), null, now(),
        ]);
        foreach ($items as $it) db_run('INSERT INTO subscription_item (id, subscription, price, quantity) VALUES (?, ?, ?, ?)', [new_id('si'), $subId, $it['price'], $it['quantity']]);
        if (!$trial) {
            $lines = array_map(fn($it) => $lineFor($it, item_amount(db_one('SELECT * FROM price WHERE id = ?', [$it['price']]), (int)$it['quantity'], 0)), $items);
            $inv = invoice_create($acc, $lines, 'paid', ['subscription' => $subId, 'checkout' => $c['id'], 'period_start' => $start, 'period_end' => $end]);
            db_run('UPDATE invoice SET paid_at = ? WHERE id = ?', [now(), $inv['id']]);
            $result['invoice'] = $inv['id'];
            $result['payment'] = payment_record($acc, $c['provider'], $txn, $inv['id'], $c['id'], $methodId);
        }
        $result['subscription'] = $subId;
        webhook_emit($c['client'], $c['env'], 'subscription.created', $cus['external_id'], ['subscription' => $subId, 'status' => $trial ? 'trialing' : 'active', 'period_end' => $end]);
    } elseif ($c['mode'] === 'payment') {
        $meta = json_obj($c['meta']);
        $lines = array_map(fn($it) => $lineFor($it, (int)($meta['unit_amount'] ?? db_one('SELECT amount FROM price WHERE id = ?', [$it['price']])['amount']) * (int)$it['quantity']), $items);
        $inv = invoice_create($acc, $lines, 'paid', ['checkout' => $c['id']]);
        db_run('UPDATE invoice SET paid_at = ? WHERE id = ?', [now(), $inv['id']]);
        $result['invoice'] = $inv['id'];
        $result['payment'] = payment_record($acc, $c['provider'], $txn, $inv['id'], $c['id'], $methodId);
        foreach ($lines as $l) {
            if ($l['pr']['type'] === 'credit_pack') wallet_post($acc['id'], agorot_to_micros($l['amount']), 'topup', 'invoice', $inv['id'], $l['description']);
        }
    } elseif ($c['mode'] === 'topup') {
        $inv = invoice_create($acc, [['client' => $c['client'], 'product' => null, 'price' => null, 'description' => 'טעינת יתרה', 'quantity' => 1, 'amount' => (int)$c['amount'], 'tax_behavior' => 'inclusive']], 'paid', ['checkout' => $c['id']]);
        db_run('UPDATE invoice SET paid_at = ? WHERE id = ?', [now(), $inv['id']]);
        $result['invoice'] = $inv['id'];
        $result['payment'] = payment_record($acc, $c['provider'], $txn, $inv['id'], $c['id'], $methodId);
        $bal = wallet_post($acc['id'], agorot_to_micros((int)$c['amount']), 'topup', 'invoice', $inv['id'], 'טעינה');
        webhook_emit($c['client'], $c['env'], 'wallet.topped_up', $cus['external_id'], ['amount' => (int)$c['amount'], 'balance' => micros_to_agorot($bal)]);
    } elseif ($c['mode'] === 'invoice') {
        $inv = db_one('SELECT * FROM invoice WHERE id = ?', [$c['invoice']]);
        $result['invoice'] = $inv['id'];
        $result['payment'] = payment_record($acc, $c['provider'], $txn, $inv['id'], $c['id'], $methodId);
        db_run("UPDATE invoice SET status = 'paid', paid_at = ?, next_attempt_at = NULL WHERE id = ? AND status = 'open'", [now(), $inv['id']]);
        if ($inv['subscription']) {
            db_run("UPDATE subscription SET status = 'active', past_due_since = NULL, first_paid_at = COALESCE(first_paid_at, ?) WHERE id = ? AND status IN ('past_due','active','trialing')", [now(), $inv['subscription']]);
            $result['subscription'] = $inv['subscription'];
            webhook_emit($c['client'], $c['env'], 'subscription.renewed', $cus['external_id'], ['subscription' => $inv['subscription'], 'invoice' => $inv['id'], 'amount' => (int)$inv['total']]);
        }
    } elseif ($c['mode'] === 'setup') {
        $result['method'] = $methodId;
    }

    db_run("UPDATE checkout SET status = 'completed', result = ? WHERE id = ?", [json_encode($result), $c['id']]);
    audit('customer', $cus['id'], 'checkout.completed', 'checkout', $c['id'], $result, db_one('SELECT tenant FROM client WHERE id = ?', [$c['client']])['tenant']);
    webhook_emit($c['client'], $c['env'], 'checkout.completed', $cus['external_id'], ['checkout' => $c['id']] + $result + ['meta' => json_obj($c['meta'])]);
    if (isset($result['payment'])) {
        webhook_emit($c['client'], $c['env'], 'payment.succeeded', $cus['external_id'], ['payment' => $result['payment'], 'amount' => (int)$c['amount']]);
        notify($acc['id'], 'email', 'receipt', 'receipt:' . $result['payment'], 'התשלום התקבל — ' . money_text((int)$c['amount']),
            "שלום,\n\nהתקבל תשלום של " . money_text((int)$c['amount']) . ' עבור ' . db_one('SELECT name FROM client WHERE id = ?', [$c['client']])['name'] . ".\nהקבלה תישלח בנפרד.");
    }
    webhook_emit($c['client'], $c['env'], 'entitlement.changed', $cus['external_id'], []);
}

/** סוף מחזור: אותו יום בחודש (31 → סוף החודש הקצר), או שנה, או שבוע. */
function period_add(int $start, string $interval, int $count, int $anchorDay): int
{
    $d = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('UTC'));
    if ($interval === 'week') return $start + 7 * 86400 * $count;
    $months = $interval === 'year' ? 12 * $count : $count;
    $y = (int)$d->format('Y');
    $mo = (int)$d->format('n') + $months;
    $y += intdiv($mo - 1, 12);
    $mo = ($mo - 1) % 12 + 1;
    $last = (int)(new DateTimeImmutable("$y-$mo-01"))->format('t');
    return (new DateTimeImmutable(sprintf('%04d-%02d-%02d %s', $y, $mo, min($anchorDay, $last), $d->format('H:i:s')), new DateTimeZone('UTC')))->getTimestamp();
}

// ---------- חיוב יזום ----------

/**
 * מחייב חשבונית פתוחה מהאמצעי ששמור (חידוש, יחידה, טעינה). ‏idempotency אצל הסולק = חשבונית:ניסיון.
 * מחזיר את ה-Txn. לא נכשל בזריקה — כשל הוא תוצאה.
 */
function invoice_charge(array $inv): Txn
{
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$inv['account']]);
    $pm = $acc['default_method'] ? db_one("SELECT * FROM payment_method WHERE id = ? AND status = 'active'", [$acc['default_method']]) : null;
    $attempt = (int)$inv['attempts'] + 1;
    db_run('UPDATE invoice SET attempts = ? WHERE id = ?', [$attempt, $inv['id']]);
    if (!$pm) {
        $txn = new Txn('', 'failed', (int)$inv['total'], 'ILS', null, 'card', '', '', '', 'no_payment_method', 'אין אמצעי תשלום שמור');
    } else {
        $txn = provider($pm['provider'])->chargeToken(unseal($pm['token_enc']), (int)$inv['total'], $inv['id'] . ':' . $attempt, ['invoice' => $inv['id']]);
    }
    payment_record($acc, $pm['provider'] ?? 'none', $txn, $inv['id'], null, $pm['id'] ?? null);
    if ($txn->status === 'succeeded') db_run("UPDATE invoice SET status = 'paid', paid_at = ?, next_attempt_at = NULL WHERE id = ?", [now(), $inv['id']]);
    return $txn;
}

/** charge (API): קנייה לפי יחידה בלחיצה — מאמצעי שמור, בלי דף. אין אמצעי → requires_action + קנייה רגילה. */
function charge_api(array $client, string $env, array $in): array
{
    $cus = customer_find($client, $env, external_id($in));
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
    $raw = $in['items'] ?? null;
    if (!is_array($raw) || !$raw) throw new ApiError('items חסר', 'bad_items');
    if (!$acc['default_method']) {
        if (empty($in['return_url'])) return ['status' => 'requires_action', 'code' => 'no_payment_method'];
        $c = checkout_create($client, $env, ['mode' => 'payment'] + $in);
        return ['status' => 'requires_action', 'url' => $c['url'], 'checkout' => $c['checkout']];
    }
    $lines = [];
    foreach ($raw as $it) {
        [$p, $pr] = current_price($client['id'], (string)($it['product'] ?? ''));
        if (!in_array($p['type'], ['unit', 'credit_pack'], true)) throw new ApiError("{$p['code']} — לא לקנייה חד-פעמית", 'bad_items');
        $q = max(1, min(10_000, (int)($it['quantity'] ?? 1)));
        $lines[] = ['client' => $client['id'], 'product' => $p['id'], 'price' => $pr['id'], 'description' => $p['name'], 'quantity' => $q, 'amount' => (int)$pr['amount'] * $q, 'tax_behavior' => $pr['tax_behavior'], 'type' => $p['type']];
    }
    $key = (string)($in['idempotency_key'] ?? '');
    $ref = $key !== '' ? 'charge:' . mb_substr($key, 0, 120) : null;
    // שלב 1, בטרנזקציה קצרה: בדיקת כפילות ופתיחת החשבונית — שתי קריאות במקביל עם אותו מפתח לא יחייבו פעמיים
    $inv = db_tx(function () use ($acc, $lines, $ref) {
        $dup = $ref ? db_one('SELECT * FROM invoice WHERE account = ? AND checkout = ?', [$acc['id'], $ref]) : null;
        return $dup ? ['dup' => $dup] : invoice_create($acc, $lines, 'open', ['checkout' => $ref]);
    });
    if (isset($inv['dup'])) {
        $d = $inv['dup'];
        return ['status' => $d['status'] === 'paid' ? 'succeeded' : ($d['status'] === 'open' ? 'processing' : 'failed'), 'invoice' => $d['id'], 'duplicate' => true];
    }
    // שלב 2, מחוץ לטרנזקציה: החיוב אצל הסולק (קריאת רשת — לא נועלים את המסד בזמנה)
    $txn = invoice_charge($inv);
    // שלב 3: רישום התוצאה
    return db_tx(function () use ($acc, $lines, $client, $env, $cus, $inv, $txn) {
        if ($txn->status !== 'succeeded') {
            db_run("UPDATE invoice SET status = 'void' WHERE id = ?", [$inv['id']]);
            webhook_emit($client['id'], $env, 'payment.failed', $cus['external_id'], ['invoice' => $inv['id'], 'code' => $txn->failureCode]);
            return ['status' => 'failed', 'code' => $txn->failureCode, 'error' => $txn->failureMessage, 'invoice' => $inv['id']];
        }
        foreach ($lines as $l) if ($l['type'] === 'credit_pack') wallet_post($acc['id'], agorot_to_micros($l['amount']), 'topup', 'invoice', $inv['id'], $l['description']);
        webhook_emit($client['id'], $env, 'payment.succeeded', $cus['external_id'], ['invoice' => $inv['id'], 'amount' => (int)$inv['total']]);
        webhook_emit($client['id'], $env, 'entitlement.changed', $cus['external_id'], []);
        return ['status' => 'succeeded', 'invoice' => $inv['id'], 'amount' => (int)$inv['total']];
    });
}

// ---------- החזר ----------

/** החזר מלא או חלקי, דרך אותו סולק. ‏$actor: ‏admin:<id> / customer:<id> / system. */
function payment_refund(string $paymentId, int $amount, string $reason, string $actor, bool $manual = false): array
{
    return db_tx(function () use ($paymentId, $amount, $reason, $actor, $manual) {
        $p = db_one('SELECT * FROM payment WHERE id = ?', [$paymentId]);
        if (!$p || !in_array($p['status'], ['succeeded', 'partially_refunded'], true)) throw new ApiError('התשלום לא נמצא או לא ניתן להחזר', 'not_refundable', 409);
        $left = (int)$p['amount'] - (int)$p['refunded'];
        if ($amount <= 0 || $amount > $left) throw new ApiError('סכום ההחזר לא תקין (נשאר ' . money_text($left) . ')', 'bad_amount');
        if (trim($reason) === '') throw new ApiError('חובה לכתוב סיבה', 'note_required');
        $id = new_id('rf');
        // ‏manual — ההחזר כבר בוצע בממשק הסולק (למשל מורנינג, שאין בה החזר דרך הממשק); כאן רק רושמים
        $manual = $manual || in_array($p['provider'], ['manual', 'none'], true); // תשלום ידני — ההחזר ידני
        $ref = $manual ? 'manual' : provider($p['provider'])->refund($p['provider_txn'], $amount, $id);
        db_run('INSERT INTO refund (id, payment, amount, reason, provider_ref, actor, manual, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$id, $paymentId, $amount, mb_substr($reason, 0, 240), $ref, $actor, (int)$manual, now()]);
        $refunded = (int)$p['refunded'] + $amount;
        db_run('UPDATE payment SET refunded = ?, status = ?, refund_pending = MAX(0, refund_pending - ?) WHERE id = ?', [$refunded, $refunded >= (int)$p['amount'] ? 'refunded' : 'partially_refunded', $amount, $paymentId]);
        // החזר על טעינת יתרה — יורד מהארנק (רק מה שלא נוצל; נבדק לפני הקריאה במסך הניהול)
        $inv = $p['invoice'] ? db_one('SELECT * FROM invoice WHERE id = ?', [$p['invoice']]) : null;
        if ($inv && db_one("SELECT 1 FROM wallet_tx WHERE ref_type = 'invoice' AND ref_id = ? AND kind = 'topup'", [$inv['id']])) {
            wallet_post($p['account'], -agorot_to_micros($amount), 'refund', 'refund', $id, $reason, $actor);
        }
        audit(explode(':', $actor)[0] === 'admin' ? 'admin' : 'system', $actor, 'payment.refunded', 'payment', $paymentId, ['amount' => $amount, 'reason' => $reason]);
        foreach (db_all('SELECT * FROM customer WHERE account = ?', [$p['account']]) as $cus) {
            webhook_emit($cus['client'], $cus['env'], 'payment.refunded', $cus['external_id'], ['payment' => $paymentId, 'amount' => $amount]);
        }
        notify($p['account'], 'email', 'refund', 'refund:' . $id, 'החזר כספי — ' . money_text($amount), "שלום,\n\nבוצע החזר של " . money_text($amount) . ".\nסיבה: $reason");
        return ['refund' => $id, 'amount' => $amount, 'payment_status' => $refunded >= (int)$p['amount'] ? 'refunded' : 'partially_refunded'];
    });
}
