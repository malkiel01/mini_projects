<?php
/**
 * בדיקות שלב 3: php payments/tests/flow.php
 * קנייה מלאה מול הסולק המדומה, ניסיון, חידוש, כשל וגבייה, ביטול, ביטול תוך 14 יום, מבצע, שינוי יחסי,
 * הסכמה לחידוש שנתי, טעינה, יחידה, חיוב בלחיצה, החזר, הודעות — ובסוף הדפים עצמם ב-HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-flow-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("PAY_DATA_DIR=$tmp");
putenv('PAY_ALLOW_HTTP=1');
putenv('PAY_BASE_URL=https://mbe-plus.com/mini_projects/payments/');
putenv('PAY_DEV_USER=' . json_encode(['sub' => '1', 'name' => 'המנהל']));

require_once __DIR__ . '/../lib/pages.php';

$fail = 0;
$pass = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) { $pass++; echo "  ✓ $name\n"; }
    else { $fail++; echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function throws(callable $fn, string $code): bool
{
    try { $fn(); } catch (ApiError $e) { return $e->codeName === $code; }
    return false;
}
function section(string $s): void { echo "\n$s\n"; }
$T0 = time();
function at(float $days): void { global $T0; putenv('PAY_NOW=' . ($T0 + (int)round($days * 86400))); }
$mails = [];
$GLOBALS['PAY_MAIL_HOOK'] = function ($to, $subj, $body) use (&$mails) { $mails[] = [$to, $subj, $body]; return true; };
$GLOBALS['PAY_HTTP_HOOK'] = fn() => ['status' => 200, 'body' => 'ok'];

/** "הלקוח שילם בדף הסולק" — כמו pay/index.php → fake.php → return.php */
function pay(array $chk, string $card = '4242424242424242', string $method = 'card'): array
{
    $c = checkout_load($chk['checkout']);
    checkout_start($c, $method);
    $c = checkout_load($c['id']);
    $txn = (new FakeProvider())->payFromPage($c['provider_ref'], $method, $card);
    if ($txn->status !== 'succeeded') return ['status' => 'declined', 'c' => $c];
    return checkout_sync($c);
}

at(0);
$owner = current_tenant();
$mer = merchant_save($owner, ['name' => 'עוסק מורשה בע"מ', 'tax_status' => 'licensed', 'email' => 'billing@example.com']);
$app = client_create($owner, ['id' => 'guarded-browser', 'name' => 'מגדלור', 'kind' => 'internal', 'merchant' => $mer['id']]);
$app = client_update($owner, 'guarded-browser', ['webhook_url' => 'https://example.com/hook']);
$plan = product_save($owner, 'guarded-browser', ['code' => 'apps', 'name' => 'מגדלור — אפליקציות', 'type' => 'plan', 'grants' => ['limits' => ['devices' => 3]]]);
$seat = product_save($owner, 'guarded-browser', ['code' => 'extra_device', 'name' => 'מכשיר נוסף', 'type' => 'seat', 'grants' => ['limits' => ['devices' => 1]]]);
$pro = product_save($owner, 'guarded-browser', ['code' => 'pro', 'name' => 'מגדלור — מכשיר', 'type' => 'plan', 'grants' => ['limits' => ['devices' => 10]]]);
$year = product_save($owner, 'guarded-browser', ['code' => 'yearly', 'name' => 'שנתי', 'type' => 'plan']);
$unit = product_save($owner, 'guarded-browser', ['code' => 'report', 'name' => 'דוח', 'type' => 'unit']);
$pack = product_save($owner, 'guarded-browser', ['code' => 'credits50', 'name' => 'חבילת 50 ₪', 'type' => 'credit_pack']);
$kids = product_save($owner, 'guarded-browser', ['code' => 'active_kids', 'name' => 'ילדים פעילים', 'type' => 'metered', 'unit_label' => 'ילדים']);
price_create($owner, $plan['id'], ['amount' => 3900, 'interval' => 'month', 'trial_days' => 14]);
price_create($owner, $seat['id'], ['amount' => 1000, 'interval' => 'month']);
price_create($owner, $pro['id'], ['amount' => 9900, 'interval' => 'month', 'intro_amount' => 4900, 'intro_periods' => 2]);
price_create($owner, $year['id'], ['amount' => 39000, 'interval' => 'year']);
price_create($owner, $unit['id'], ['amount' => 1500]);
price_create($owner, $pack['id'], ['amount' => 5000]);
price_create($owner, $kids['id'], ['amount' => 800, 'prepaid' => false, 'aggregate' => 'max', 'included' => 3]);
$urls = ['return_url' => 'https://example.com/back', 'cancel_url' => 'https://example.com/cancel'];

section('ניסיון חינם ומנוי');
customer_upsert($app, 'test', ['external_id' => 'family:1', 'name' => 'משפחת כהן', 'email' => 'cohen@example.com']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'subscription', 'items' => [['product' => 'apps'], ['product' => 'extra_device', 'quantity' => 2]]] + $urls);
check('ניסיון 14 יום — לתשלום עכשיו 0', $chk['amount'] === 0 && $chk['trial_days'] === 14);
$v = checkout_view(checkout_load($chk['checkout']));
check('גילוי: ניסיון, הסכום אחריו, ביטול, 14 יום', str_contains(implode(' ', $v['disclose']), '₪59.00') && str_contains(implode(' ', $v['disclose']), '14 ימים'));
check('מנוי — רק כרטיס (ביט לא מחייב שוב)', $v['methods'] === ['card']);
check('ביט במנוי — נדחה', throws(fn() => checkout_start(checkout_load($chk['checkout']), 'bit'), 'bad_method'));
$done = pay($chk);
check('נשמר כרטיס, קנייה נסגרה', $done['status'] === 'completed' && db_one("SELECT default_method FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:1'")['default_method'] !== null);
$res = json_obj($done['result']);
$sub = db_one('SELECT * FROM subscription WHERE id = ?', [$res['subscription']]);
check('מנוי בניסיון, בלי חשבונית', $sub['status'] === 'trialing' && !isset($res['invoice']));
$e = entitlement($app, 'test', ['external_id' => 'family:1']);
check('בניסיון — זכאי, 3+2 מכשירים', $e['active'] && $e['state'] === 'trialing' && $e['limits']->devices == 5);
check('סגירה שנייה — לא כופלת', checkout_sync(checkout_load($chk['checkout']))['status'] === 'completed' && (int)db_one('SELECT COUNT(*) AS n FROM subscription')['n'] === 1);
$chk2 = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'subscription', 'items' => [['product' => 'apps']]] + $urls);
check('ניסיון שני — אין', $chk2['trial_days'] === 0 && $chk2['amount'] === 3900);

section('סוף ניסיון וחידוש');
at(11.5);
check('3 ימים לפני סוף הניסיון — הודעה (פעם אחת)', subscriptions_notices() >= 1 && subscriptions_notices() === 0);
at(14.1);
check('חידוש: נגבה 59 ₪', subscriptions_renew() === 1);
$sub = db_one('SELECT * FROM subscription WHERE id = ?', [$sub['id']]);
$inv = db_one('SELECT * FROM invoice WHERE subscription = ? ORDER BY created_at DESC', [$sub['id']]);
check('מנוי פעיל, חשבונית שולמה', $sub['status'] === 'active' && $inv['status'] === 'paid' && (int)$inv['total'] === 5900);
check('מע"מ 18% מתוך הסכום', (int)$inv['vat'] === 900 && (int)$inv['subtotal'] === 5000);
check('תקופה חדשה — חודש מסוף הניסיון', (int)$sub['current_period_end'] === period_add((int)$sub['current_period_start'], 'month', 1, (int)gmdate('j', (int)$sub['trial_end'])));
check('חידוש כפול — לא מחייב שוב', subscriptions_renew() === 0);

section('שימוש בדיעבד בחשבונית');
foreach ([2, 6, 5] as $i => $n) usage_record($app, 'test', ['external_id' => 'family:1', 'product' => 'active_kids', 'quantity' => $n, 'idempotency_key' => "k$i"]);
at(14.1 + 31);
subscriptions_renew();
$inv = db_one('SELECT * FROM invoice WHERE subscription = ? ORDER BY created_at DESC', [$sub['id']]);
$lines = db_all('SELECT * FROM invoice_line WHERE invoice = ?', [$inv['id']]);
check('שיא 6, כלולים 3 → 3 × 8 ₪ נוספו', (int)$inv['total'] === 5900 + 2400 && count($lines) === 3, json_encode(array_column($lines, 'amount')));

section('כשל בחיוב וגבייה');
$c3 = customer_upsert($app, 'test', ['external_id' => 'family:3', 'email' => 'f3@example.com']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:3', 'mode' => 'subscription', 'items' => [['product' => 'extra_device']]] + $urls);
$done = pay($chk, '4000000000000341'); // עובר עכשיו, נדחה בחיוב הבא
$sub3 = db_one('SELECT * FROM subscription WHERE id = ?', [json_obj($done['result'])['subscription']]);
check('תשלום ראשון עבר — פעיל', $sub3['status'] === 'active' && $sub3['first_paid_at'] !== null);
at(14.1 + 31 + 32);
subscriptions_renew();
$sub3 = db_one('SELECT * FROM subscription WHERE id = ?', [$sub3['id']]);
check('החידוש נדחה — באיחור, עדיין זכאי (חסד)', $sub3['status'] === 'past_due' && entitlement($app, 'test', ['external_id' => 'family:3'])['active']);
$open = db_one("SELECT * FROM invoice WHERE subscription = ? AND status = 'open'", [$sub3['id']]);
check('ניסיון חוזר נקבע ליום אחרי', (int)$open['next_attempt_at'] === (int)$sub3['past_due_since'] + 86400);
at(14.1 + 31 + 32 + 1.1);
invoices_retry();
check('ניסיון 2 נכשל', (int)db_one('SELECT attempts FROM invoice WHERE id = ?', [$open['id']])['attempts'] === 2);
at(14.1 + 31 + 32 + 8);
check('אחרי 7 ימי חסד — לא זכאי', !entitlement($app, 'test', ['external_id' => 'family:3'])['active']);
// הלקוח מעדכן כרטיס → ניסיון מיידי
$setup = checkout_create($app, 'test', ['external_id' => 'family:3', 'mode' => 'setup'] + $urls);
pay($setup);
$sub3 = db_one('SELECT * FROM subscription WHERE id = ?', [$sub3['id']]);
check('כרטיס חדש → החשבונית נגבתה, פעיל שוב', $sub3['status'] === 'active' && db_one('SELECT status FROM invoice WHERE id = ?', [$open['id']])['status'] === 'paid');
check('הודעת כשל נשלחה ללקוח', notifications_send() > 0 && (bool)array_filter($mails, fn($m) => $m[0] === 'f3@example.com' && str_contains($m[1], 'לא עבר')));
check('בכל הודעה — דרכי הביטול', (bool)array_filter($mails, fn($m) => str_contains($m[2], 'ביטול המנוי')));

section('14 יום בלי תשלום → המנוי מסתיים');
$c4 = customer_upsert($app, 'test', ['external_id' => 'family:4']);
at(100);
$chk = checkout_create($app, 'test', ['external_id' => 'family:4', 'mode' => 'subscription', 'items' => [['product' => 'extra_device']]] + $urls);
$sub4 = json_obj(pay($chk, '4000000000000341')['result'])['subscription'];
at(131);
subscriptions_renew();
for ($d = 132; $d <= 146; $d++) { at($d); invoices_retry(); }
$s4 = db_one('SELECT * FROM subscription WHERE id = ?', [$sub4]);
check('הסתיים: payment_failed, החשבונית "לא נגבתה"', $s4['status'] === 'canceled' && $s4['cancel_reason'] === 'payment_failed'
    && db_one('SELECT status FROM invoice WHERE subscription = ? ORDER BY created_at DESC', [$sub4])['status'] === 'uncollectible');

section('ביטול');
at(200);
customer_upsert($app, 'test', ['external_id' => 'family:5', 'email' => 'f5@example.com']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:5', 'mode' => 'subscription', 'items' => [['product' => 'extra_device']]] + $urls);
$sub5 = json_obj(pay($chk)['result'])['subscription'];
$cus5 = customer_find($app, 'test', 'family:5');
at(210);
subscription_cancel($cus5, $sub5, 'period_end', 'test');
check('ביטול לסוף התקופה — עדיין זכאי', entitlement($app, 'test', ['external_id' => 'family:5'])['active']);
subscription_resume($cus5, $sub5);
check('המשך — בוטל הביטול', !(int)db_one('SELECT cancel_at_period_end AS c FROM subscription WHERE id = ?', [$sub5])['c']);
subscription_cancel($cus5, $sub5, 'period_end', 'test');
at(232);
subscriptions_renew();
check('בסוף התקופה — הסתיים, בלי חיוב', db_one('SELECT status FROM subscription WHERE id = ?', [$sub5])['status'] === 'canceled'
    && (int)db_one('SELECT COUNT(*) AS n FROM invoice WHERE subscription = ?', [$sub5])['n'] === 1);

section('ביטול עסקה תוך 14 יום');
at(300);
customer_upsert($app, 'test', ['external_id' => 'family:6']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:6', 'mode' => 'subscription', 'items' => [['product' => 'yearly']]] + $urls);
$sub6 = json_obj(pay($chk)['result'])['subscription'];
$cus6 = customer_find($app, 'test', 'family:6');
at(300 + 36.5); // עשירית שנה
check('יותר מ-14 יום — לא זכאי', throws(fn() => subscription_cooling_off($cus6, $sub6), 'not_eligible'));
at(300 + 10);
$q = cooling_off_quote(db_one('SELECT * FROM subscription WHERE id = ?', [$sub6]));
check('הצעה: שימוש יחסי + דמי ביטול ₪100 (5% מ-390 = 19.5 → הנמוך)', $q['fee'] === 1950 && $q['refund'] === 39000 - $q['used'] - 1950, json_encode($q));
$r = subscription_cooling_off($cus6, $sub6);
$p6 = db_one('SELECT * FROM payment WHERE id = ?', [$r['payment']]);
check('הוחזר דרך הסולק, המנוי נעצר', (int)$p6['refunded'] === $r['refund'] && db_one('SELECT status FROM subscription WHERE id = ?', [$sub6])['status'] === 'canceled'
    && (int)db_one("SELECT SUM(amount) AS s FROM fake_txn WHERE kind = 'refund'")['s'] === $r['refund']);

section('שנתי — חידוש רק בהסכמה (§13א)');
at(400);
customer_upsert($app, 'test', ['external_id' => 'family:7', 'email' => 'f7@example.com', 'name' => 'שבע']);
db_run("UPDATE account SET phone = '0501234567' WHERE id = (SELECT account FROM customer WHERE external_id = 'family:7')");
$chk = checkout_create($app, 'test', ['external_id' => 'family:7', 'mode' => 'subscription', 'items' => [['product' => 'yearly']]] + $urls);
check('גילוי: תקופה קצובה, חידוש רק באישור', str_contains(implode(' ', checkout_view(checkout_load($chk['checkout']))['disclose']), 'רק אם תאשר'));
$sub7 = json_obj(pay($chk)['result'])['subscription'];
$end7 = (int)db_one('SELECT current_period_end AS e FROM subscription WHERE id = ?', [$sub7])['e'];
putenv('PAY_NOW=' . ($end7 - 61 * 86400));
subscriptions_notices();
check('61 יום לפני — עוד אין הודעה', !db_one("SELECT 1 FROM notification WHERE dedupe = ?", ["renew60:$sub7:$end7"]));
putenv('PAY_NOW=' . ($end7 - 45 * 86400));
subscriptions_notices();
check('45 יום לפני — הודעה במייל', (bool)db_one("SELECT 1 FROM notification WHERE dedupe = ? AND channel = 'email'", ["renew60:$sub7:$end7"]));
putenv('PAY_NOW=' . ($end7 - 20 * 86400));
subscriptions_notices();
notifications_send();
check('21 יום לפני — מסרון (אין ספק → מסומן לטיפול)', db_one("SELECT status FROM notification WHERE dedupe = ?", ["renew21:$sub7:$end7"])['status'] === 'no_provider');
putenv('PAY_NOW=' . ($end7 + 60));
subscriptions_renew();
check('בלי הסכמה — הסתיים, לא חויב', db_one('SELECT cancel_reason FROM subscription WHERE id = ?', [$sub7])['cancel_reason'] === 'no_consent'
    && (int)db_one('SELECT COUNT(*) AS n FROM invoice WHERE subscription = ?', [$sub7])['n'] === 1);
// עם הסכמה
at(900);
customer_upsert($app, 'test', ['external_id' => 'family:8']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:8', 'mode' => 'subscription', 'items' => [['product' => 'yearly']]] + $urls);
$sub8 = json_obj(pay($chk)['result'])['subscription'];
subscription_consent(customer_find($app, 'test', 'family:8'), $sub8);
putenv('PAY_NOW=' . ((int)db_one('SELECT current_period_end AS e FROM subscription WHERE id = ?', [$sub8])['e'] + 60));
subscriptions_renew();
check('עם הסכמה — חודש ונגבה', db_one('SELECT status FROM subscription WHERE id = ?', [$sub8])['status'] === 'active'
    && (int)db_one("SELECT COUNT(*) AS n FROM invoice WHERE subscription = ? AND status = 'paid'", [$sub8])['n'] === 2);

section('מחיר מבצע');
at(1500);
customer_upsert($app, 'test', ['external_id' => 'family:9']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:9', 'mode' => 'subscription', 'items' => [['product' => 'pro']]] + $urls);
check('חודש ראשון במחיר מבצע 49 ₪', $chk['amount'] === 4900);
check('גילוי: המחיר אחרי המבצע', str_contains(implode(' ', checkout_view(checkout_load($chk['checkout']))['disclose']), '₪99.00'));
$sub9 = json_obj(pay($chk)['result'])['subscription'];
$totals = [];
for ($i = 1; $i <= 3; $i++) {
    putenv('PAY_NOW=' . ((int)db_one('SELECT current_period_end AS e FROM subscription WHERE id = ?', [$sub9])['e'] + 60));
    subscriptions_renew();
    $totals[] = (int)db_one('SELECT total FROM invoice WHERE subscription = ? ORDER BY created_at DESC', [$sub9])['total'];
}
check('מבצע לשני מחזורים: 49 → 49 → 99 → 99', $totals === [4900, 9900, 9900], json_encode($totals));

section('שינוי יחסי');
at(2000);
customer_upsert($app, 'test', ['external_id' => 'family:10']);
$chk = checkout_create($app, 'test', ['external_id' => 'family:10', 'mode' => 'subscription', 'items' => [['product' => 'extra_device']]] + $urls);
$sub10 = json_obj(pay($chk)['result'])['subscription'];
$s10 = db_one('SELECT * FROM subscription WHERE id = ?', [$sub10]);
putenv('PAY_NOW=' . ((int)$s10['current_period_start'] + intdiv((int)$s10['current_period_end'] - (int)$s10['current_period_start'], 2)));
$u = subscription_update($app, 'test', ['external_id' => 'family:10', 'subscription' => $sub10, 'items' => [['product' => 'extra_device', 'quantity' => 3]]]);
check('שדרוג באמצע: חיוב יחסי ~חצי מ-20 ₪', $u['effective'] === 'now' && abs($u['charged'] - 1000) <= 1, json_encode($u));
check('הזכאות עודכנה מיד', entitlement($app, 'test', ['external_id' => 'family:10'])['limits']->devices == 3);
$u = subscription_update($app, 'test', ['external_id' => 'family:10', 'subscription' => $sub10, 'items' => [['product' => 'extra_device', 'quantity' => 1]]]);
check('שנמוך — מסוף התקופה', $u['effective'] === 'period_end' && entitlement($app, 'test', ['external_id' => 'family:10'])['limits']->devices == 3);
putenv('PAY_NOW=' . ((int)$s10['current_period_end'] + 60));
subscriptions_renew();
check('אחרי החידוש — מכשיר אחד, 10 ₪', entitlement($app, 'test', ['external_id' => 'family:10'])['limits']->devices == 1
    && (int)db_one('SELECT total FROM invoice WHERE subscription = ? ORDER BY created_at DESC', [$sub10])['total'] === 1000);

section('טעינה, חבילה, יחידה, חיוב בלחיצה');
at(2100);
customer_upsert($app, 'test', ['external_id' => 'user:1']);
check('טעינה מתחת למינימום — נדחה', throws(fn() => checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'topup', 'amount' => 100] + $urls), 'bad_amount'));
$chk = checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'topup', 'amount' => 2500] + $urls);
check('טעינה — גם ביט', checkout_view(checkout_load($chk['checkout']))['methods'] === ['card', 'bit']);
pay($chk, 'ok', 'bit');
check('טעינה בביט → 25 ₪ ביתרה', customer_view(customer_find($app, 'test', 'user:1'))['account']['balance'] === 2500);
check('ביט — לא נשמר אמצעי', db_one("SELECT default_method FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'user:1'")['default_method'] === null);
$r = charge_api($app, 'test', ['external_id' => 'user:1', 'items' => [['product' => 'report']]] + $urls);
check('חיוב בלחיצה בלי כרטיס — מפנה לדף', $r['status'] === 'requires_action' && str_contains($r['url'], '/pay/?c='));
$chk = checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'payment', 'items' => [['product' => 'credits50'], ['product' => 'report', 'quantity' => 2]]] + $urls);
check('חבילה + 2 דוחות = 80 ₪', $chk['amount'] === 8000);
pay($chk);
$e = entitlement($app, 'test', ['external_id' => 'user:1']);
check('חבילה → +50 ₪ ביתרה; 2 דוחות נקנו', $e['balance'] === 7500 && $e['purchased']->report == 2);
$r = charge_api($app, 'test', ['external_id' => 'user:1', 'items' => [['product' => 'report']], 'idempotency_key' => 'rep-1']);
$r2 = charge_api($app, 'test', ['external_id' => 'user:1', 'items' => [['product' => 'report']], 'idempotency_key' => 'rep-1']);
check('חיוב בלחיצה מכרטיס שמור, ובלי כפילות', $r['status'] === 'succeeded' && !empty($r2['duplicate']) && entitlement($app, 'test', ['external_id' => 'user:1'])['purchased']->report == 3);
check('מנוי ב"קנייה" — נדחה', throws(fn() => checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'payment', 'items' => [['product' => 'apps']]] + $urls), 'bad_items'));
$fake = checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'payment', 'items' => [['product' => 'credits50']], 'meta' => ['unit_amount' => 1_000_000, 'link' => 'XXXXXXXXXX', 'trial_days' => 3650, 'order' => 7]] + $urls);
$fm = json_obj(checkout_load($fake['checkout'])['meta']);
check('meta של המערכת לא משנה סכום, קרדיט או ניסיון', $fake['amount'] === 5000 && !isset($fm['unit_amount']) && !isset($fm['link']) && !isset($fm['trial_days']) && $fm['order'] === 7);
$bal = entitlement($app, 'test', ['external_id' => 'user:1'])['balance'];
pay($fake);
check('…והקרדיט — לפי מה ששולם', entitlement($app, 'test', ['external_id' => 'user:1'])['balance'] === $bal + 5000);
$trialSub = checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'subscription', 'items' => [['product' => 'apps']], 'meta' => ['trial_days' => 3650]] + $urls);
check('ניסיון של 10 שנים מ-meta — לא', (json_obj(checkout_load($trialSub['checkout'])['meta'])['trial_days'] ?? 0) !== 3650);
$excl = product_save($owner, 'guarded-browser', ['code' => 'consult', 'name' => 'ייעוץ', 'type' => 'unit']);
price_create($owner, $excl['id'], ['amount' => 10000, 'tax_behavior' => 'exclusive']);
$ex = checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'payment', 'items' => [['product' => 'consult']]] + $urls);
check('מחיר "לא כולל מע"מ" — הלקוח משלם כולל (₪118), גם בדף', $ex['amount'] === 11800 && checkout_view(checkout_load($ex['checkout']))['lines'][0]['amount'] === 11800);
pay($ex);
$exInv = db_one('SELECT * FROM invoice WHERE checkout = ?', [$ex['checkout']]);
$exPay = db_one('SELECT * FROM payment WHERE invoice = ?', [$exInv['id']]);
check('…והחשבונית תואמת את מה שנגבה', (int)$exInv['total'] === 11800 && (int)$exPay['amount'] === 11800 && (int)$exInv['vat'] === 1800);
$n = (int)db_one('SELECT COUNT(*) AS n FROM payment')['n'];
db_run("UPDATE invoice SET status = 'open' WHERE checkout = 'charge:rep-1'"); // כאילו הקריאה הראשונה עדיין באמצע החיוב
$r3 = charge_api($app, 'test', ['external_id' => 'user:1', 'items' => [['product' => 'report']], 'idempotency_key' => 'rep-1']);
check('חיוב במקביל עם אותו מפתח — "בטיפול", בלי חיוב נוסף', $r3['status'] === 'processing' && !empty($r3['duplicate']) && (int)db_one('SELECT COUNT(*) AS n FROM payment')['n'] === $n);
db_run("UPDATE invoice SET status = 'paid' WHERE checkout = 'charge:rep-1'");
check('ייצוא: תא שמתחיל ב-= נשמר כטקסט', csv_text('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && csv_text('דנה') === 'דנה' && csv_text('-5') === "'-5");
check('הסכום נקבע אצלנו — לא מהבקשה', checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'payment', 'items' => [['product' => 'report']], 'amount' => 1] + $urls)['amount'] === 1500);

section('החזר');
$pay1 = db_one("SELECT p.* FROM payment p JOIN invoice i ON i.id = p.invoice WHERE i.total = 8000 AND p.status = 'succeeded'");
check('החזר בלי סיבה — נדחה', throws(fn() => payment_refund($pay1['id'], 1500, '', 'admin:x'), 'note_required'));
check('החזר גדול מהתשלום — נדחה', throws(fn() => payment_refund($pay1['id'], 9000, 'x', 'admin:x'), 'bad_amount'));
$rf = payment_refund($pay1['id'], 1500, 'דוח כפול', 'admin:x');
check('החזר חלקי', $rf['payment_status'] === 'partially_refunded');
payment_refund($pay1['id'], 6500, 'ביטול', 'admin:x');
check('השלמה להחזר מלא', db_one('SELECT status FROM payment WHERE id = ?', [$pay1['id']])['status'] === 'refunded');
check('אחרי החזר מלא — הדוחות לא נספרים', entitlement($app, 'test', ['external_id' => 'user:1'])['purchased']->report == 1);

section('השעון');
db_run("UPDATE setting SET value = '0' WHERE key = 'tick_at'");
check('ריצה ראשונה', tick('test') !== null);
check('שנייה תוך דקה — מדלגת', tick('test') === null);
check('בכוח (משימה מתוזמנת) — רצה', tick('cron', true) !== null);
check('קנייה ששולמה בלי חזרה — נסגרת מהשעון', (function () use ($app, $urls) {
    $chk = checkout_create($app, 'test', ['external_id' => 'user:1', 'mode' => 'topup', 'amount' => 1000] + $urls);
    $c = checkout_load($chk['checkout']);
    checkout_start($c, 'card');
    (new FakeProvider())->payFromPage(checkout_load($c['id'])['provider_ref'], 'card', '4242424242424242');
    checkouts_reconcile();
    return checkout_load($c['id'])['status'] === 'completed';
})());
check('בדיקת שלמות: יתרה = סכום התנועות', (function () {
    foreach (db_all('SELECT b.micros, (SELECT COALESCE(SUM(amount),0) FROM wallet_tx t WHERE t.account = b.account) AS s FROM wallet_balance b') as $r) if ((int)$r['micros'] !== (int)$r['s']) return false;
    return true;
})());

// ---------------------------------------------------------------
section('הדפים ב-HTTP');
putenv('PAY_NOW');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock); // פורט פנוי
$docroot = dirname(__DIR__);
$base = "http://127.0.0.1:$port/";
$srv = proc_open("exec env PAY_DATA_DIR=$tmp PAY_ALLOW_HTTP=1 PAY_BASE_URL=$base php -S 127.0.0.1:$port -t " . escapeshellarg($docroot), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400_000);
putenv("PAY_BASE_URL=$base");
$get = function (string $path, ?array $post = null, array &$cookies = []) use ($base) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8]);
    if ($cookies) curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies)));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hs);
    if (preg_match_all('/^Set-Cookie: ([^=]+)=([^;]*)/mi', $head, $m)) foreach ($m[1] as $i => $k) $cookies[$k] = $m[2][$i];
    preg_match('/^Location: (.*)$/mi', $head, $loc);
    return [$code, substr($raw, $hs), trim($loc[1] ?? '')];
};
$chk = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'report']]] + $urls);
[$code, $html] = $get('pay/?c=' . $chk['checkout']);
check('דף התשלום נטען', $code === 200 && str_contains($html, 'מגדלור') && str_contains($html, '₪15.00') && str_contains($html, 'סביבת בדיקות'));
[$code] = $get('pay/?c=chk_xxxxxxxxxxxxxxxxxxxx');
check('קנייה לא קיימת — שגיאה', $code === 400);
[$code, $html] = $get('pay/?c=' . $chk['checkout'], ['t' => 'bad', 'agree' => 1, 'method' => 'card']);
check('טופס בלי אסימון — נדחה', $code === 400);
[$code, $html] = $get('pay/?c=' . $chk['checkout'], ['t' => form_token($chk['checkout']), 'method' => 'card']);
check('בלי אישור תנאים — נשאר בדף', $code === 200 && str_contains($html, 'לאשר את התנאים'));
[$code, , $loc] = $get('pay/?c=' . $chk['checkout'], ['t' => form_token($chk['checkout']), 'agree' => 1, 'method' => 'card']);
check('אישור → הפניה לסולק', $code === 303 && str_contains($loc, 'pay/fake.php?s='));
$ref = explode('s=', $loc)[1];
[$code, $html] = $get('pay/fake.php?s=' . $ref, ['t' => form_token($ref), 'method' => 'card', 'card' => '1234 5678 9012 3456']);
check('כרטיס אמיתי — לא מתקבל בסולק המדומה', $code === 200 && str_contains($html, 'רק כרטיסי בדיקה'));
[$code, , $loc] = $get('pay/fake.php?s=' . $ref, ['t' => form_token($ref), 'method' => 'card', 'card' => '4242 4242 4242 4242']);
check('תשלום → חזרה', $code === 303 && str_contains($loc, 'return.php'));
[$code, , $loc] = $get('pay/return.php?c=' . $chk['checkout']);
[$code, $html] = $get('pay/?c=' . $chk['checkout']);
check('אחרי החזרה — "שולם" וקישור חזרה', $code === 200 && str_contains($html, 'שולם') && str_contains($html, 'example.com/back?checkout='));
check('והקנייה נסגרה במסד', checkout_load($chk['checkout'])['status'] === 'completed');

$ps = portal_session_create($app, 'test', ['external_id' => 'family:1', 'return_url' => 'https://example.com/app']);
$token = explode('s=', $ps['url'])[1];
$jar = [];
[$code, , $loc] = $get('portal/?s=' . $token, null, $jar);
check('קישור לחשבון → עוגייה והפניה', $code === 302 && isset($jar[PORTAL_COOKIE]));
$jar2 = [];
[$code] = $get('portal/?s=' . $token, null, $jar2);
check('הקישור חד-פעמי', $code === 400);
[$code, $html] = $get('portal/', null, $jar);
check('"החשבון שלי" — מנויים, יתרה, כרטיס, תשלומים, דרכי ביטול', $code === 200 && str_contains($html, 'המנויים שלי') && str_contains($html, '•••• 4242') && str_contains($html, 'ביטול המנוי:'));
$sid = hash('sha256', $jar[PORTAL_COOKIE]);
$subA = db_one("SELECT s.id FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:1'")['id'];
[$code, $html] = $get('portal/', ['t' => form_token($sid), 'do' => 'cancel', 'sub' => $subA], $jar);
check('ביטול מהחשבון — בשני קליקים', $code === 200 && str_contains($html, 'המנוי בוטל') && (int)db_one('SELECT cancel_at_period_end AS c FROM subscription WHERE id = ?', [$subA])['c'] === 1);
$none = [];
[$code] = $get('portal/', null, $none);
check('בלי עוגייה — אין גישה', $code === 400);
$sk = key_create($owner, 'guarded-browser', 'test', 'sk');
$signed = function (string $action, array $payload) use ($base, $sk) {
    $body = json_encode($payload);
    [$kid, $secret] = explode('.', $sk['key'], 2);
    $time = (string)time(); $nonce = bin2hex(random_bytes(16));
    $ch = curl_init($base . "api.php?action=$action");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => [
        'Content-Type: application/json', "X-Pay-Key: $kid", "X-Pay-Time: $time", "X-Pay-Nonce: $nonce",
        'X-Pay-Signature: ' . hash_hmac('sha256', "$action\n$time\n$nonce\n" . hash('sha256', $body), $secret)]]);
    $out = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return $out;
};
foreach ([
    'checkout_create' => ['external_id' => 'family:1', 'mode' => 'topup', 'amount' => 1000] + $urls,
    'portal_session' => ['external_id' => 'family:1', 'return_url' => 'https://example.com/app'],
    'subscription_list' => ['external_id' => 'family:1'],
    'invoice_list' => ['external_id' => 'family:1'],
    'charge' => ['external_id' => 'family:1', 'items' => [['product' => 'report']], 'idempotency_key' => 'http-1'],
] as $action => $payload) {
    $r = $signed($action, $payload);
    check("ב-HTTP: $action", is_array($r) && !isset($r['error']), json_encode($r, JSON_UNESCAPED_UNICODE));
}
[$code, $body] = $get('api.php?action=tick');
check('שעון חיצוני — פתוח, בלי מידע', $code === 200 && str_contains($body, '"ok":true'));
proc_terminate($srv);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
