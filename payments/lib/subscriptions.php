<?php
/**
 * מנויים מלאים (שלב 3): חידוש, גבייה של כשלים, שינוי יחסי, ביטול, ביטול תוך 14 יום, והודעות שהחוק מחייב.
 * פרקים 2.6, 9.2–9.3, 10.6. כל פעולה כאן בטוחה להרצה כפולה.
 */

declare(strict_types=1);

require_once __DIR__ . '/checkout.php';

const DUNNING_DAYS = [1, 3, 7];   // ניסיונות חוזרים אחרי כשל, בימים מהכשל הראשון
const DUNNING_CANCEL_DAYS = 14;
const COOLING_OFF_DAYS = 14;      // §14ג(ג)(2)
const CANCEL_FEE_RATE = 500;      // 5% (בסיס 10,000)
const CANCEL_FEE_MAX = 10_000;    // ₪100

function sub_items(string $subId): array
{
    return db_all('SELECT si.*, pr.amount, pr.intro_amount, pr.intro_periods, pr.interval, pr.interval_count, pr.renewal, pr.tax_behavior, pr.grace_days,
            p.id AS pid, p.code, p.name, p.type
        FROM subscription_item si JOIN price pr ON pr.id = si.price JOIN product p ON p.id = pr.product WHERE si.subscription = ?', [$subId]);
}

function sub_customer(array $s): array
{
    return db_one('SELECT * FROM customer WHERE id = ?', [$s['customer']]);
}

function sub_event(array $s, string $type, array $data = []): void
{
    $cus = sub_customer($s);
    webhook_emit($s['client'], $cus['env'], $type, $cus['external_id'], ['subscription' => $s['id']] + $data);
    webhook_emit($s['client'], $cus['env'], 'entitlement.changed', $cus['external_id'], []);
}

function sub_end(array $s, string $reason): void
{
    db_run("UPDATE subscription SET status = 'canceled', canceled_at = ?, cancel_reason = ? WHERE id = ?", [now(), $reason, $s['id']]);
    audit('system', 'renew', 'subscription.canceled', 'subscription', $s['id'], ['reason' => $reason]);
    sub_event($s, 'subscription.canceled', ['reason' => $reason]);
}

/** סכום המחזור + שימוש בדיעבד של המחזור שנגמר (מעל הכלול), כשורות חשבונית. */
function renewal_lines(array $s, int $periodIndex, int $usedFrom, int $usedTo): array
{
    $lines = [];
    foreach (sub_items($s['id']) as $it) {
        $lines[] = ['client' => $s['client'], 'product' => $it['pid'], 'price' => $it['price'], 'description' => $it['name'] . ((int)$it['quantity'] > 1 ? ' × ' . $it['quantity'] : ''),
            'quantity' => (int)$it['quantity'], 'amount' => item_amount($it, (int)$it['quantity'], $periodIndex), 'tax_behavior' => $it['tax_behavior']];
    }
    // שימוש מדוד בדיעבד (prepaid = 0) של המערכת הזו, במחזור שנגמר
    foreach (db_all("SELECT p.id, p.code, p.name, p.unit_label FROM product p WHERE p.client = ? AND p.type = 'metered'", [$s['client']]) as $p) {
        $pr = db_one('SELECT * FROM price WHERE product = ? AND active = 1 ORDER BY version DESC LIMIT 1', [$p['id']]);
        if (!$pr || $pr['prepaid']) continue;
        $ev = db_all('SELECT quantity, meta FROM usage_event WHERE customer = ? AND product = ? AND occurred_at >= ? AND occurred_at < ?', [$s['customer'], $p['id'], $usedFrom, $usedTo]);
        if (!$ev) continue;
        $billable = max(0, aggregate_quantity($ev, $pr['aggregate']) - (int)$pr['included']);
        if ($billable <= 0) continue;
        $packs = (int)ceil($billable / max(1, (int)$pr['package_size']));
        $amount = $pr['amount_micros'] ? micros_to_agorot((int)round($billable * (int)$pr['amount_micros'] / max(1, (int)$pr['package_size']))) : $packs * (int)$pr['amount'];
        if ($amount <= 0) continue;
        $lines[] = ['client' => $s['client'], 'product' => $p['id'], 'price' => $pr['id'], 'description' => $p['name'] . ': ' . $billable . ' ' . $p['unit_label'],
            'quantity' => $billable, 'amount' => $amount, 'tax_behavior' => $pr['tax_behavior']];
    }
    return $lines;
}

/** מנוי שהחידוש שלו דורש הסכמה (שנתי / תקופה קצובה — §13א). */
function sub_needs_consent(array $s): bool
{
    foreach (sub_items($s['id']) as $it) if ($it['renewal'] === 'consent') return true;
    return false;
}

/**
 * חידוש: מנויים שהמחזור שלהם נגמר → חשבונית → חיוב. החיוב — מחוץ לטרנזקציה (סולק אמיתי הוא קריאת רשת),
 * והמנוי "נתפס" לפני כן (העברת התקופה), כך שהרצה כפולה לא מחייבת פעמיים.
 */
function subscriptions_renew(int $limit = 100): int
{
    $n = 0;
    foreach (db_all("SELECT * FROM subscription WHERE status IN ('active','trialing') AND current_period_end IS NOT NULL AND current_period_end <= ? LIMIT ?", [now(), $limit]) as $row) {
        $inv = db_tx(function () use ($row) {
            $s = db_one('SELECT * FROM subscription WHERE id = ?', [$row['id']]);
            if (!in_array($s['status'], ['active', 'trialing'], true) || (int)$s['current_period_end'] > now()) return null;
            if ($s['cancel_at_period_end']) { sub_end($s, 'requested'); return null; }
            if ($s['status'] === 'active' && sub_needs_consent($s) && (int)($s['renew_consent_until'] ?? 0) < (int)$s['current_period_end']) {
                sub_end($s, 'no_consent');
                notify($s['account'], 'email', 'ended_no_consent', 'ended:' . $s['id'], 'המנוי הסתיים', "שלום,\n\nתקופת המנוי הסתיימה ולא אישרת חידוש, לכן לא חויבת. אפשר להירשם מחדש בכל עת.");
                return null;
            }
            if ($s['pending_items']) {
                db_run('DELETE FROM subscription_item WHERE subscription = ?', [$s['id']]);
                foreach (json_decode($s['pending_items'], true) as $it) db_run('INSERT INTO subscription_item (id, subscription, price, quantity) VALUES (?, ?, ?, ?)', [new_id('si'), $s['id'], $it['price'], $it['quantity']]);
                db_run('UPDATE subscription SET pending_items = NULL WHERE id = ?', [$s['id']]);
            }
            $items = sub_items($s['id']);
            $first = $items[0];
            $trialEnding = $s['status'] === 'trialing';
            $index = $trialEnding ? 0 : (int)$s['period_index'] + 1;
            $start = (int)$s['current_period_end'];
            // יום העיגון: היום שבו התחילה התקופה הראשונה ששולמה (סוף הניסיון, או ההרשמה)
            $anchor = (int)gmdate('j', (int)($s['trial_end'] ?: $s['created_at']));
            $end = period_add($start, $first['interval'], (int)$first['interval_count'], $anchor);
            $lines = renewal_lines($s, $index, (int)$s['current_period_start'], $start);
            db_run('UPDATE subscription SET current_period_start = ?, current_period_end = ?, period_index = ? WHERE id = ?', [$start, $end, $index, $s['id']]);
            $acc = db_one('SELECT * FROM account WHERE id = ?', [$s['account']]);
            return invoice_create($acc, $lines, 'open', ['subscription' => $s['id'], 'period_start' => $start, 'period_end' => $end]) + ['_trial' => $trialEnding];
        });
        if (!$inv) continue;
        invoice_settle($inv);
        $n++;
    }
    return $n;
}

/** ניסיון חיוב לחשבונית של מנוי, והמשמעות: הצלחה → פעיל; כשל → באיחור ולוח ניסיונות. */
function invoice_settle(array $inv): void
{
    $s = $inv['subscription'] ? db_one('SELECT * FROM subscription WHERE id = ?', [$inv['subscription']]) : null;
    if ((int)$inv['total'] === 0) {
        db_run("UPDATE invoice SET status = 'paid', paid_at = ? WHERE id = ?", [now(), $inv['id']]);
        $txn = new Txn('', 'succeeded', 0);
    } else {
        $txn = invoice_charge($inv);
        $inv = db_one('SELECT * FROM invoice WHERE id = ?', [$inv['id']]); // מספר הניסיונות עודכן
    }
    if (!$s) return;
    $cus = sub_customer($s);
    if ($txn->status === 'succeeded') {
        db_run("UPDATE subscription SET status = 'active', past_due_since = NULL, first_paid_at = COALESCE(first_paid_at, ?) WHERE id = ?", [(int)$inv['total'] ? now() : null, $s['id']]);
        sub_event($s, 'subscription.renewed', ['invoice' => $inv['id'], 'amount' => (int)$inv['total'], 'period_end' => (int)$inv['period_end']]);
        if ((int)$inv['total']) {
            webhook_emit($s['client'], $cus['env'], 'invoice.paid', $cus['external_id'], ['invoice' => $inv['id'], 'amount' => (int)$inv['total']]);
            notify($s['account'], 'email', 'receipt', 'receipt-inv:' . $inv['id'], 'חיוב המנוי — ' . money_text((int)$inv['total']),
                "שלום,\n\nחויבת " . money_text((int)$inv['total']) . ' עבור התקופה ' . date_text((int)$inv['period_start']) . '–' . date_text((int)$inv['period_end']) . ".\nהקבלה תישלח בנפרד.");
        }
        return;
    }
    $since = (int)($s['past_due_since'] ?? 0) ?: now();
    $tries = (int)$inv['attempts'];
    $next = $tries <= count(DUNNING_DAYS) ? $since + DUNNING_DAYS[$tries - 1] * 86400 : null;
    db_run("UPDATE subscription SET status = 'past_due', past_due_since = ? WHERE id = ?", [$since, $s['id']]);
    db_run('UPDATE invoice SET next_attempt_at = ? WHERE id = ?', [$next, $inv['id']]);
    webhook_emit($s['client'], $cus['env'], 'payment.failed', $cus['external_id'], ['invoice' => $inv['id'], 'code' => $txn->failureCode]);
    if ($tries === 1) sub_event($s, 'subscription.past_due', ['invoice' => $inv['id']]);
    notify($s['account'], 'email', 'payment_failed', 'failed:' . $inv['id'] . ':' . $tries, 'החיוב לא עבר',
        "שלום,\n\nהחיוב של " . money_text((int)$inv['total']) . " לא עבר ({$txn->failureMessage}).\nכדי שהשירות לא ייעצר — היכנס לעמוד \"החשבון שלי\" באפליקציה ולחץ \"תשלום עכשיו\".");
}

/** גבייה של כשלים: ניסיון חוזר לפי הלוח; אחרי 14 יום — המנוי מסתיים והחשבונית "לא נגבתה". */
function invoices_retry(int $limit = 100): int
{
    $n = 0;
    foreach (db_all("SELECT * FROM invoice WHERE status = 'open' AND subscription IS NOT NULL AND next_attempt_at IS NOT NULL AND next_attempt_at <= ? LIMIT ?", [now(), $limit]) as $inv) {
        if (db_run('UPDATE invoice SET next_attempt_at = NULL WHERE id = ? AND next_attempt_at = ?', [$inv['id'], $inv['next_attempt_at']]) !== 1) continue;
        invoice_settle(db_one('SELECT * FROM invoice WHERE id = ?', [$inv['id']]));
        $n++;
    }
    foreach (db_all("SELECT * FROM subscription WHERE status = 'past_due' AND past_due_since <= ?", [now() - DUNNING_CANCEL_DAYS * 86400]) as $s) {
        db_run("UPDATE invoice SET status = 'uncollectible', next_attempt_at = NULL WHERE subscription = ? AND status = 'open'", [$s['id']]);
        sub_end($s, 'payment_failed');
        notify($s['account'], 'email', 'ended_unpaid', 'ended-unpaid:' . $s['id'], 'המנוי הופסק', "שלום,\n\nלא הצלחנו לגבות את התשלום במשך שבועיים, ולכן המנוי הופסק. אפשר לחדש בכל עת.");
    }
    return $n;
}

/** אחרי עדכון אמצעי תשלום — ניסיון מיידי של כל החשבוניות הפתוחות של החשבון. */
function invoices_retry_account(string $accountId): void
{
    foreach (db_all("SELECT * FROM invoice WHERE account = ? AND status = 'open' AND subscription IS NOT NULL", [$accountId]) as $inv) invoice_settle($inv);
}

/** ההודעות שהחוק מחייב (פרק 10.6) + סוף ניסיון. כל הודעה — פעם אחת (dedupe). */
function subscriptions_notices(): int
{
    $n = 0;
    $day = 86400;
    foreach (db_all("SELECT * FROM subscription WHERE status IN ('trialing','active','past_due') AND current_period_end IS NOT NULL") as $s) {
        $left = (int)$s['current_period_end'] - now();
        $end = (int)$s['current_period_end'];
        $client = db_one('SELECT name FROM client WHERE id = ?', [$s['client']])['name'];
        $items = sub_items($s['id']);
        $next = 0;
        foreach ($items as $it) $next += item_amount($it, (int)$it['quantity'], $s['status'] === 'trialing' ? 0 : (int)$s['period_index'] + 1);
        if ($s['status'] === 'trialing' && $left <= 3 * $day && $left > 0) {
            if (notify($s['account'], 'email', 'trial_ending', "trial:{$s['id']}:$end", 'תקופת הניסיון מסתיימת',
                "שלום,\n\nהניסיון ב$client מסתיים ב-" . date_text($end) . '. מאותו יום יחויב ' . money_text($next) . ' בכל מחזור, אלא אם תבטל לפני כן.')) {
                sub_event($s, 'subscription.trial_ending', ['trial_end' => $end]);
                $n++;
            }
        }
        if ($s['status'] !== 'trialing' && sub_needs_consent($s) && (int)($s['renew_consent_until'] ?? 0) < $end) {
            // §13א: הודעה בחלון 60–30 יום לפני הסיום, ומסרון 21 יום לפני
            if ($left <= 60 * $day && $left > 0) {
                $n += (int)notify($s['account'], 'email', 'renewal_notice', "renew60:{$s['id']}:$end", 'המנוי שלך מסתיים ב-' . date_text($end),
                    "שלום,\n\nתקופת המנוי ב$client מסתיימת ב-" . date_text($end) . ".\nהמנוי **לא** יחודש אוטומטית. כדי להמשיך — אשר חידוש בעמוד \"החשבון שלי\". מחיר התקופה הבאה: " . money_text($next) . '.');
            }
            if ($left <= 21 * $day && $left > 0) {
                $n += (int)notify($s['account'], 'sms', 'renewal_sms', "renew21:{$s['id']}:$end", '', "המנוי ב$client מסתיים ב-" . date_text($end) . '. לחידוש — "החשבון שלי".');
            }
        }
        // סוף מחיר מבצע: המחיר אחרי המבצע גולה בכתב בהרשמה; הודעה לפני השינוי
        foreach ($items as $it) {
            if ($it['intro_amount'] === null || (int)$it['intro_periods'] === 0 || $s['status'] === 'trialing') continue;
            if ((int)$s['period_index'] !== (int)$it['intro_periods'] - 1) continue;
            $window = ($end - (int)$s['created_at']) > 120 * $day ? 60 * $day : 7 * $day;
            if ($left <= $window && $left > 0) {
                $n += (int)notify($s['account'], 'email', 'intro_ending', "intro:{$s['id']}:{$it['id']}:$end", 'מחיר המבצע מסתיים',
                    "שלום,\n\nמחיר המבצע עבור {$it['name']} מסתיים ב-" . date_text($end) . '. מאותו יום המחיר יהיה ' . money_text((int)$it['amount'] * (int)$it['quantity']) . ' לכל מחזור.');
            }
        }
    }
    return $n;
}

// ---------- פעולות הלקוח / המערכת ----------

function sub_for_customer(array $cus, string $subId): array
{
    $s = db_one('SELECT * FROM subscription WHERE id = ? AND customer = ?', [$subId, $cus['id']]);
    if (!$s) throw new ApiError('המנוי לא נמצא', 'not_found', 404);
    return $s;
}

/** ביטול: בסוף התקופה ששולמה (ברירת מחדל), או מיד. */
function subscription_cancel(array $cus, string $subId, string $at, string $actor): array
{
    $s = sub_for_customer($cus, $subId);
    if ($s['status'] === 'canceled') return $s;
    if ($at === 'now' || $s['status'] === 'trialing') {
        sub_end($s, 'requested');
    } else {
        db_run('UPDATE subscription SET cancel_at_period_end = 1, cancel_at = current_period_end WHERE id = ?', [$s['id']]);
        sub_event($s, 'subscription.updated', ['cancel_at' => (int)$s['current_period_end']]);
    }
    audit('customer', $actor, 'subscription.cancel', 'subscription', $s['id'], ['at' => $at]);
    notify($s['account'], 'email', 'canceled', 'cancel:' . $s['id'], 'המנוי בוטל',
        "שלום,\n\nהמנוי בוטל" . ($at === 'now' || $s['status'] === 'trialing' ? '.' : ' — השירות ממשיך עד ' . date_text((int)$s['current_period_end']) . ', ולא תחויב שוב.'));
    return db_one('SELECT * FROM subscription WHERE id = ?', [$s['id']]);
}

function subscription_resume(array $cus, string $subId): array
{
    $s = sub_for_customer($cus, $subId);
    if ($s['status'] === 'canceled') throw new ApiError('מנוי שהסתיים — נרשמים מחדש', 'canceled', 409);
    db_run('UPDATE subscription SET cancel_at_period_end = 0, cancel_at = NULL WHERE id = ?', [$s['id']]);
    sub_event($s, 'subscription.updated', ['cancel_at' => null]);
    return db_one('SELECT * FROM subscription WHERE id = ?', [$s['id']]);
}

/** הסכמה לחידוש מנוי בתקופה קצובה (§13א) — עד סוף התקופה הבאה. */
function subscription_consent(array $cus, string $subId): array
{
    $s = sub_for_customer($cus, $subId);
    db_run('UPDATE subscription SET renew_consent_until = ? WHERE id = ?', [(int)$s['current_period_end'] + 1, $s['id']]);
    audit('customer', $cus['id'], 'subscription.renew_consent', 'subscription', $s['id'], ['until' => (int)$s['current_period_end']]);
    return db_one('SELECT * FROM subscription WHERE id = ?', [$s['id']]);
}

/**
 * שינוי פריטים: שדרוג — מיד, עם חיוב יחסי על מה שנותר מהתקופה; שנמוך — מסוף התקופה (ברירת מחדל).
 * ‏proration: ‏now | period_end.
 */
function subscription_update(array $client, string $env, array $in): array
{
    $cus = customer_find($client, $env, external_id($in));
    $s = sub_for_customer($cus, (string)($in['subscription'] ?? ''));
    if (!in_array($s['status'], ['active', 'trialing'], true)) throw new ApiError('אפשר לשנות רק מנוי פעיל', 'not_active', 409);
    $new = [];
    $interval = null;
    foreach ((array)($in['items'] ?? []) as $it) {
        [$p, $pr] = current_price($client['id'], (string)($it['product'] ?? ''));
        if (!in_array($p['type'], RECURRING_TYPES, true)) throw new ApiError("{$p['code']} אינו מוצר מנוי", 'bad_items');
        $interval ??= $pr['interval'];
        if ($interval !== $pr['interval']) throw new ApiError('כל פריטי המנוי באותו מחזור', 'mixed_intervals');
        $new[] = ['price' => $pr['id'], 'quantity' => max(1, min(10_000, (int)($it['quantity'] ?? 1))), 'pr' => $pr];
    }
    if (!$new) throw new ApiError('items חסר', 'bad_items');
    $oldAmount = array_sum(array_map(fn($i) => item_amount($i, (int)$i['quantity'], (int)$s['period_index']), sub_items($s['id'])));
    $newAmount = array_sum(array_map(fn($i) => item_amount($i['pr'], $i['quantity'], (int)$s['period_index']), $new));
    $mode = (string)($in['proration'] ?? ($newAmount > $oldAmount ? 'now' : 'period_end'));
    $store = array_map(fn($i) => ['price' => $i['price'], 'quantity' => $i['quantity']], $new);
    if ($mode === 'period_end' || $s['status'] === 'trialing') {
        db_run('UPDATE subscription SET pending_items = ? WHERE id = ?', [json_encode($store), $s['id']]);
        if ($s['status'] === 'trialing') { // בניסיון — מיד, בלי חיוב
            db_run('DELETE FROM subscription_item WHERE subscription = ?', [$s['id']]);
            foreach ($store as $it) db_run('INSERT INTO subscription_item (id, subscription, price, quantity) VALUES (?, ?, ?, ?)', [new_id('si'), $s['id'], $it['price'], $it['quantity']]);
            db_run('UPDATE subscription SET pending_items = NULL WHERE id = ?', [$s['id']]);
        }
        sub_event($s, 'subscription.updated', ['effective' => $s['status'] === 'trialing' ? 'now' : 'period_end']);
        return ['subscription' => $s['id'], 'effective' => $s['status'] === 'trialing' ? 'now' : 'period_end', 'charged' => 0];
    }
    $span = max(1, (int)$s['current_period_end'] - (int)$s['current_period_start']);
    $left = max(0, (int)$s['current_period_end'] - now());
    $diff = (int)floor(($newAmount - $oldAmount) * $left / $span); // עיגול לטובת הלקוח
    $charged = 0;
    if ($diff > 0) {
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$s['account']]);
        $inv = invoice_create($acc, [['client' => $client['id'], 'product' => null, 'price' => null, 'description' => 'שינוי תכנית — חיוב יחסי עד ' . date_text((int)$s['current_period_end']),
            'quantity' => 1, 'amount' => $diff, 'tax_behavior' => $new[0]['pr']['tax_behavior']]], 'open', ['subscription' => null]);
        $txn = invoice_charge($inv);
        if ($txn->status !== 'succeeded') {
            db_run("UPDATE invoice SET status = 'void' WHERE id = ?", [$inv['id']]);
            throw new ApiError('החיוב היחסי לא עבר — התכנית לא שונתה (' . $txn->failureMessage . ')', 'card_declined', 402);
        }
        $charged = $diff;
    } elseif ($diff < 0) {
        db_tx(fn() => wallet_post($s['account'], agorot_to_micros(-$diff), 'grant', 'subscription', $s['id'], 'זיכוי יחסי על שינוי תכנית'));
    }
    db_tx(function () use ($s, $store) {
        db_run('DELETE FROM subscription_item WHERE subscription = ?', [$s['id']]);
        foreach ($store as $it) db_run('INSERT INTO subscription_item (id, subscription, price, quantity) VALUES (?, ?, ?, ?)', [new_id('si'), $s['id'], $it['price'], $it['quantity']]);
        db_run('UPDATE subscription SET pending_items = NULL WHERE id = ?', [$s['id']]);
    });
    sub_event($s, 'subscription.updated', ['effective' => 'now', 'charged' => $charged, 'credited' => $diff < 0 ? -$diff : 0]);
    return ['subscription' => $s['id'], 'effective' => 'now', 'charged' => $charged, 'credited' => $diff < 0 ? -$diff : 0];
}

/**
 * ביטול עסקה תוך 14 יום (§14ג(ג)(2), §14ה): החזר יחסי על מה שלא נוצל, בניכוי דמי ביטול — 5% או ₪100, הנמוך.
 * מחזיר null אם לא זכאי (עברו 14 יום / לא שולם).
 */
function cooling_off_quote(array $s): ?array
{
    if (!$s['first_paid_at'] || now() - (int)$s['first_paid_at'] > COOLING_OFF_DAYS * 86400 || $s['status'] === 'canceled') return null;
    $inv = db_one("SELECT * FROM invoice WHERE subscription = ? AND status = 'paid' AND total > 0 ORDER BY created_at LIMIT 1", [$s['id']]);
    if (!$inv) return null;
    $pay = db_one("SELECT * FROM payment WHERE invoice = ? AND status IN ('succeeded','partially_refunded') LIMIT 1", [$inv['id']]);
    if (!$pay) return null;
    $paid = (int)$pay['amount'] - (int)$pay['refunded'];
    $span = max(1, (int)$inv['period_end'] - (int)$inv['period_start']);
    $used = (int)ceil($paid * min($span, max(0, now() - (int)$inv['period_start'])) / $span);
    $fee = min((int)round($paid * CANCEL_FEE_RATE / 10_000), CANCEL_FEE_MAX);
    return ['payment' => $pay['id'], 'paid' => $paid, 'used' => $used, 'fee' => $fee, 'refund' => max(0, $paid - $used - $fee)];
}

function subscription_cooling_off(array $cus, string $subId): array
{
    $s = sub_for_customer($cus, $subId);
    $q = cooling_off_quote($s);
    if (!$q) throw new ApiError('עברו 14 יום מהעסקה — אפשר לבטל רק מסוף התקופה', 'not_eligible', 409);
    sub_end($s, 'cooling_off');
    if ($q['refund'] > 0) payment_refund($q['payment'], $q['refund'], 'ביטול עסקה תוך 14 יום', 'customer:' . $cus['id']);
    return $q;
}
