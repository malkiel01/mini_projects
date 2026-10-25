<?php
/**
 * בדיקות המחבר של פייפלוס: php payments/tests/payplus.php
 * מול "פייפלוס מדומה" לפי התיעוד הרשמי (‏docs.payplus.co.il) ושמות השדות שבתוסף הרשמי שלהם: ‏generateLink, ‏ipn,
 * חיוב בטוקן, ‏RefundByTransactionUID, וה-callback החתום. הזרימה: חיבור, קנייה, טוקן, מסמך, חידוש, ניסיון חוזר בלי
 * חיוב כפול, ניסיון חינם עם שמירת כרטיס בלי חיוב, ביט, החזר, ועוסק פטור.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-payplus-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("PAY_DATA_DIR=$tmp");
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
$GLOBALS['PAY_MAIL_HOOK'] = fn() => true;

// ---------- פייפלוס מדומה ----------
$P = ['calls' => [], 'pages' => [], 'tx' => [], 'n' => 0, 'decline' => false];
const PP_PAGE = '7a0bc4d4-f35f-4301-a945-926378a2416d';
$GLOBALS['PAY_HTTP_HOOK'] = function (string $url, ?string $post, array $headers) use (&$P) {
    $b = json_decode((string)$post, true) ?: [];
    $P['calls'][] = [$url, $b, $headers];
    $j = fn(array $r, int $st = 200) => ['status' => $st, 'body' => json_encode($r, JSON_UNESCAPED_UNICODE)];
    if (!str_starts_with($url, 'https://restapidev.payplus.co.il/api/v1.0/')) return ['status' => 404, 'body' => ''];
    $h = implode("\n", $headers);
    if (!str_contains($h, 'api-key: key-1') || !str_contains($h, 'secret-key: sec-1')) return $j(['results' => ['status' => 'error', 'code' => 1, 'description' => 'unauthorized']], 401);
    $op = substr($url, strlen('https://restapidev.payplus.co.il/api/v1.0/'));
    $uuid = function () use (&$P) { $P['n']++; return sprintf('%08x-0000-4000-8000-%012x', $P['n'], $P['n']); };
    $txn = function (array $b, bool $tokenCharge) use (&$P, $uuid) {
        $docu = !empty($b['initial_invoice']) ? ['uuid' => $uuid(), 'docu_number' => (string)(90000 + $P['n']), 'original_url' => 'https://inv.test/' . $P['n'], 'status' => 'Success'] : null;
        $t = ['status' => 'approved', 'status_code' => '000', 'transaction_uid' => $uuid(), 'amount' => $b['amount'], 'currency' => 'ILS', 'more_info' => $b['more_info'] ?? '',
            'four_digits' => '9844', 'expiry_month' => '05', 'expiry_year' => '30', 'brand_name' => 'Mastercard', 'method' => 'credit-card', 'type' => 'Charge',
            'token_uid' => (!empty($b['create_token']) && !$tokenCharge) ? 'tok-' . $P['n'] : null, 'invoice' => $docu];
        $P['tx'][] = $t;
        return $t;
    };
    switch ($op) {
        case 'PaymentPages/list':
            return $j(['results' => ['status' => 'success', 'code' => 0], 'data' => [['uid' => PP_PAGE, 'name' => 'Main']]]);
        case 'PaymentPages/generateLink':
            foreach (['payment_page_uid', 'amount', 'currency_code', 'sendEmailApproval', 'sendEmailFailure'] as $k) if (!array_key_exists($k, $b)) return $j(['results' => ['status' => 'error', 'description' => "missing $k"]], 422);
            if ($b['payment_page_uid'] !== PP_PAGE) return $j(['results' => ['status' => 'error', 'description' => 'can-not-find-payment-page']], 422);
            if (!empty($b['token'])) { // חיוב מיידי בטוקן
                if ($P['decline'] || !str_starts_with($b['token'], 'tok-')) return $j(['results' => ['status' => 'error', 'code' => 1, 'description' => 'הכרטיס נדחה'], 'data' => ['status' => 'rejected', 'status_code' => '006']]);
                return $j(['results' => ['status' => 'success', 'code' => 0], 'data' => $txn($b, true)]);
            }
            $uid = $uuid();
            $P['pages'][$uid] = ['req' => $b, 'state' => 'open'];
            return $j(['results' => ['status' => 'success', 'code' => 0, 'description' => 'payment page link is been generated'],
                'data' => ['page_request_uid' => $uid, 'payment_page_link' => "https://paymentsdev.payplus.co.il/$uid"]]);
        case 'PaymentPages/ipn':
            if (isset($b['payment_request_uid'])) {
                $pg = $P['pages'][$b['payment_request_uid']] ?? null;
                if (!$pg || $pg['state'] === 'open') return $j(['results' => ['status' => 'error', 'description' => 'transaction not found']]);
                return $j(['results' => ['status' => 'success', 'code' => 0], 'data' => $pg['result']]);
            }
            foreach (array_reverse($P['tx']) as $t) if (($t['more_info'] ?? '') === ($b['more_info'] ?? '#')) return $j(['results' => ['status' => 'success'], 'data' => $t]);
            return $j(['results' => ['status' => 'error', 'description' => 'transaction not found']]);
        case 'Transactions/RefundByTransactionUID':
            $orig = null;
            foreach ($P['tx'] as $t) if ($t['transaction_uid'] === ($b['transaction_uid'] ?? '')) $orig = $t;
            if (!$orig) return $j(['results' => ['status' => 'error', 'description' => 'transaction not found']]);
            if ((float)$b['amount'] > (float)$orig['amount']) return $j(['results' => ['status' => 'error', 'description' => 'amount too big']]);
            return $j(['results' => ['status' => 'success', 'code' => 0], 'data' => ['transaction' => ['uid' => $uuid(), 'status_code' => '000', 'amount' => $b['amount']]]]);
    }
    return ['status' => 404, 'body' => ''];
};
/** הלקוח משלם בדף של פייפלוס (או נכשל). */
function payplus_pays(string $checkoutId, bool $ok = true, string $method = 'credit-card'): void
{
    global $P;
    foreach ($P['pages'] as $uid => &$pg) {
        if (($pg['req']['more_info'] ?? '') !== $checkoutId) continue;
        $pg['state'] = 'done';
        if (!$ok) { $pg['result'] = ['status' => 'rejected', 'status_code' => '006', 'transaction_uid' => '']; return; }
        $b = $pg['req'];
        $P['n']++;
        $docu = !empty($b['initial_invoice']) ? ['uuid' => 'doc-' . $P['n'], 'docu_number' => (string)(90000 + $P['n']), 'original_url' => 'https://inv.test/' . $P['n'], 'status' => 'Success'] : null;
        $t = ['status' => 'approved', 'status_code' => '000', 'transaction_uid' => sprintf('%08x-1111-4000-8000-%012x', $P['n'], $P['n']), 'amount' => $b['amount'], 'currency' => 'ILS',
            'more_info' => $b['more_info'], 'four_digits' => '9844', 'expiry_month' => '05', 'expiry_year' => '30', 'brand_name' => 'Mastercard', 'method' => $method,
            'type' => $b['charge_method'] === 5 ? 'Check' : 'Charge', 'token_uid' => !empty($b['create_token']) && $method === 'credit-card' ? 'tok-' . $P['n'] : null, 'invoice' => $docu];
        $P['tx'][] = $t;
        $pg['result'] = $t;
        return;
    }
}
function last_call(string $needle): ?array
{
    global $P;
    foreach (array_reverse($P['calls']) as $c) if (str_contains($c[0], $needle)) return $c;
    return null;
}

at(0);
$owner = current_tenant();
$exempt = merchant_save($owner, ['name' => 'עוסק פטור', 'tax_status' => 'exempt', 'email' => 'a@example.com']);
$lic = merchant_save($owner, ['name' => 'חברה בע"מ', 'tax_status' => 'company', 'email' => 'b@example.com']);
$app = client_create($owner, ['id' => 'guarded-browser', 'name' => 'מגדלור', 'kind' => 'internal', 'merchant' => $lic['id']]);
$plan = product_save($owner, 'guarded-browser', ['code' => 'apps', 'name' => 'מגדלור — אפליקציות', 'type' => 'plan']);
$trialPlan = product_save($owner, 'guarded-browser', ['code' => 'trial_plan', 'name' => 'עם ניסיון', 'type' => 'plan']);
$unit = product_save($owner, 'guarded-browser', ['code' => 'report', 'name' => 'דוח', 'type' => 'unit']);
$excl = product_save($owner, 'guarded-browser', ['code' => 'consult', 'name' => 'ייעוץ', 'type' => 'unit']);
price_create($owner, $plan['id'], ['amount' => 3900, 'interval' => 'month']);
price_create($owner, $trialPlan['id'], ['amount' => 2900, 'interval' => 'month', 'trial_days' => 7]);
price_create($owner, $unit['id'], ['amount' => 1500]);
price_create($owner, $excl['id'], ['amount' => 10000, 'tax_behavior' => 'exclusive']);
$urls = ['return_url' => 'https://example.com/back', 'cancel_url' => 'https://example.com/cancel'];

section('חיבור');
check('בלי מפתח סודי — נדחה', throws(fn() => provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'payplus', 'env' => 'test', 'api_key' => 'key-1', 'payment_page_uid' => PP_PAGE]), 'missing_credentials'));
check('מזהה דף לא תקין — נדחה', throws(fn() => provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'payplus', 'env' => 'test', 'api_key' => 'key-1', 'secret_key' => 'x', 'payment_page_uid' => 'abc']), 'bad_page_uid'));
$pa = provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'payplus', 'env' => 'test', 'api_key' => 'key-1', 'secret_key' => 'wrong', 'payment_page_uid' => PP_PAGE, 'methods' => ['card', 'bit']]);
check('קודים שגויים — בדיקה נכשלת עם הסבר', str_contains(provider_account_check($owner, $pa['id'])['check_error'], 'דחתה'));
$pa = provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'payplus', 'env' => 'test', 'api_key' => '', 'secret_key' => 'sec-1', 'payment_page_uid' => '']);
check('בדיקה עוברת', provider_account_check($owner, $pa['id'])['check_error'] === '');
check('המפתח הסודי לא חוזר למסך', $pa['fields']['secret_key'] === true && !str_contains(json_encode($pa), 'sec-1'));
check('ברירת מחדל: הקבלה מפייפלוס; הסולק — פייפלוס', $pa['settings']['documents'] === 'payplus' && provider_for($lic, 'test')->id() === $pa['id']);

section('קנייה — מנוי');
customer_upsert($app, 'test', ['external_id' => 'family:1', 'name' => 'משפחת כהן', 'email' => 'cohen@example.com', 'tax_id' => '123456789']);
$c = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'subscription', 'items' => [['product' => 'apps']]] + $urls);
$url = checkout_start(checkout_load($c['checkout']), 'card');
[$u, $req, $hdr] = last_call('generateLink');
check('הפניה לדף של פייפלוס, בסביבת הבדיקות', str_starts_with($url, 'https://paymentsdev.payplus.co.il/'));
check('נשלח: מפתחות בכותרות, דף, חיוב (J4), ₪39, שמירת כרטיס', in_array('api-key: key-1', $hdr, true) && $req['payment_page_uid'] === PP_PAGE && $req['charge_method'] === 1
    && $req['amount'] == 39 && $req['currency_code'] === 'ILS' && $req['create_token'] === true && $req['sendEmailApproval'] === false);
check('נשלח: אסמכתא, חזרה, כישלון, ביטול והודעה', $req['more_info'] === $c['checkout'] && str_contains($req['refURL_success'], 'pay/return.php?c=')
    && str_contains($req['refURL_failure'], 'failed=1') && $req['refURL_cancel'] === 'https://example.com/cancel' && str_contains($req['refURL_callback'], 'hooks/payplus.php?pa=' . $pa['id']));
check('נשלח: הלקוח, ושורות כוללות מע"מ בסכום המלא', $req['customer']['customer_name'] === 'משפחת כהן' && $req['customer']['vat_number'] === '123456789'
    && $req['items'][0]['price'] == 39 && $req['items'][0]['vat_type'] === 0 && $req['initial_invoice'] === true);
check('בלי תשלום — הקנייה פתוחה', checkout_sync(checkout_load($c['checkout']))['status'] === 'open');
payplus_pays($c['checkout']);
check('אחרי תשלום בפייפלוס — נסגרה', checkout_sync(checkout_load($c['checkout']))['status'] === 'completed');
$acc = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:1'");
$pm = db_one('SELECT * FROM payment_method WHERE id = ?', [$acc['default_method']]);
check('הכרטיס נשמר: טוקן, 4 ספרות, תוקף', str_starts_with(unseal($pm['token_enc']), 'tok-') && $pm['last4'] === '9844' && $pm['exp'] === '05/30');
$pay = db_one('SELECT * FROM payment WHERE checkout = ?', [$c['checkout']]);
$doc = db_one('SELECT * FROM tax_document WHERE payment = ?', [$pay['id']]);
check('התשלום עם מזהה העסקה, והקבלה של פייפלוס נשמרה', str_starts_with($pay['provider_txn'], 'txn:') && $doc && $doc['url'] !== '' && tax_docs_issue() === 0);

section('הודעה מפייפלוס');
$pp = provider($pa['id']);
$raw = json_encode(['transaction_type' => 'Charge', 'transaction' => ['payment_request_uid' => 'x']]);
check('חתימה נכונה — מתקבלת; שגויה או בלי סוכן — נדחית', $pp->verifyCallback($raw, base64_encode(hash_hmac('sha256', $raw, 'sec-1', true)), 'PayPlus')
    && !$pp->verifyCallback($raw, base64_encode(hash_hmac('sha256', $raw, 'other', true)), 'PayPlus') && !$pp->verifyCallback($raw, base64_encode(hash_hmac('sha256', $raw, 'sec-1', true)), 'curl'));

section('חידוש מטוקן');
at(31);
subscriptions_renew();
[, $ch] = last_call('generateLink');
$sub = db_one("SELECT s.* FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:1'");
check('חיוב: הטוקן, ₪39, המפתח שלנו באסמכתא, וקבלה', $ch['token'] === unseal($pm['token_enc']) && $ch['amount'] == 39 && str_starts_with($ch['more_info'], 'inv_') && $ch['initial_invoice'] === true);
check('המנוי פעיל, והקבלה נשמרה', $sub['status'] === 'active' && (int)db_one('SELECT COUNT(*) AS n FROM tax_document')['n'] === 2);
$n = count($P['tx']);
$again = $pp->chargeToken(unseal($pm['token_enc']), 3900, $ch['more_info'], []);
check('ניסיון חוזר עם אותו מפתח — מוצא את העסקה, בלי חיוב נוסף', $again->status === 'succeeded' && count($P['tx']) === $n);
check('…ובסכום אחר — לא נסגר', $pp->chargeToken(unseal($pm['token_enc']), 5000, $ch['more_info'], [])->failureCode === 'amount_mismatch');

section('כשל בחידוש');
$P['decline'] = true;
at(62);
subscriptions_renew();
$P['decline'] = false;
check('נדחה — באיחור', db_one('SELECT status FROM subscription WHERE id = ?', [$sub['id']])['status'] === 'past_due');
at(63.1);
invoices_retry();
check('ניסיון חוזר עבר — פעיל שוב', db_one('SELECT status FROM subscription WHERE id = ?', [$sub['id']])['status'] === 'active');

section('ניסיון חינם — שמירת כרטיס בלי חיוב');
customer_upsert($app, 'test', ['external_id' => 'family:2', 'email' => 'f2@example.com']);
$t = checkout_create($app, 'test', ['external_id' => 'family:2', 'mode' => 'subscription', 'items' => [['product' => 'trial_plan']]] + $urls);
check('ניסיון בפייפלוס — כרטיס', checkout_view(checkout_load($t['checkout']))['methods'] === ['card']);
checkout_start(checkout_load($t['checkout']), 'card');
[, $req] = last_call('generateLink');
check('נשלח: שמירת כרטיס בלי חיוב (J2), בלי שורות ובלי קבלה', $req['charge_method'] === 5 && $req['create_token'] === true && !isset($req['items']) && $req['initial_invoice'] === false);
payplus_pays($t['checkout']);
check('הכרטיס נשמר, המנוי בניסיון, ואין תשלום', checkout_sync(checkout_load($t['checkout']))['status'] === 'completed'
    && db_one("SELECT s.status FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:2'")['status'] === 'trialing'
    && !db_one('SELECT 1 FROM payment WHERE checkout = ?', [$t['checkout']]));

section('ביט, וקנייה שנכשלה');
$b = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'report']]] + $urls);
checkout_start(checkout_load($b['checkout']), 'bit');
[, $req] = last_call('generateLink');
check('ביט — הדף נפתח על ביט, בלי שמירת כרטיס', $req['charge_default'] === 'bit' && $req['create_token'] === false && $req['hide_other_charge_methods'] === true);
payplus_pays($b['checkout'], true, 'bit');
checkout_sync(checkout_load($b['checkout']));
check('ביט עבר — נרשם', (bool)db_one("SELECT 1 FROM payment WHERE checkout = ? AND status = 'succeeded'", [$b['checkout']]));
$f = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'report']]] + $urls);
checkout_start(checkout_load($f['checkout']), 'card');
payplus_pays($f['checkout'], false);
check('נדחה בדף — הקנייה נשארת פתוחה', checkout_sync(checkout_load($f['checkout']))['status'] === 'open');

section('מחיר לא כולל מע"מ');
$x = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'consult']]] + $urls);
checkout_start(checkout_load($x['checkout']), 'card');
[, $req] = last_call('generateLink');
check('₪118 בחיוב ובשורות', $req['amount'] == 118 && array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $req['items'])) == 118);

section('החזר');
$rf = payment_refund($pay['id'], 1000, 'החזר חלקי', 'admin:x');
[, $rq] = last_call('RefundByTransactionUID');
check('החזר חלקי דרך פייפלוס — העסקה והסכום', $rf['payment_status'] === 'partially_refunded' && $rq['transaction_uid'] === substr($pay['provider_txn'], 4) && $rq['amount'] == 10);
check('גדול מהתשלום — נדחה אצלנו לפני פייפלוס', throws(fn() => payment_refund($pay['id'], 999999, 'x', 'admin:x'), 'bad_amount'));

section('עוסק פטור, והקבלה ממורנינג');
$app2 = client_create($owner, ['id' => 'recipes-app', 'name' => 'מתכונים', 'merchant' => $exempt['id']]);
$pr = product_save($owner, 'recipes-app', ['code' => 'book', 'name' => 'ספר', 'type' => 'unit']);
price_create($owner, $pr['id'], ['amount' => 5000]);
customer_upsert($app2, 'test', ['external_id' => 'u:1', 'email' => 'u1@example.com']);
provider_account_save($owner, ['merchant' => $exempt['id'], 'provider' => 'payplus', 'env' => 'test', 'api_key' => 'key-1', 'secret_key' => 'sec-1', 'payment_page_uid' => PP_PAGE]);
$e = checkout_create($app2, 'test', ['external_id' => 'u:1', 'mode' => 'payment', 'items' => [['product' => 'book']]] + $urls);
checkout_start(checkout_load($e['checkout']), 'card');
check('פטור — שורות בלי מע"מ', last_call('generateLink')[1]['items'][0]['vat_type'] === 2);
provider_account_save($owner, ['merchant' => $exempt['id'], 'provider' => 'payplus', 'env' => 'test', 'documents' => 'morning']);
$e2 = checkout_create($app2, 'test', ['external_id' => 'u:1', 'mode' => 'payment', 'items' => [['product' => 'book']]] + $urls);
checkout_start(checkout_load($e2['checkout']), 'card');
check('"הקבלה ממורנינג" — בלי קבלה בפייפלוס', last_call('generateLink')[1]['initial_invoice'] === false);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
