<?php
/**
 * בדיקות שלב 4 — המחבר של מורנינג: php payments/tests/morning.php
 * מול "מורנינג מדומה" שבנוי לפי התיעוד הרשמי (developers.morning.co): כניסה, לקוחות, דף תשלום, חיפוש מסמכים,
 * טוקנים וחיוב. בודק את מבנה הבקשות שאנחנו שולחים, ואת כל הזרימה: קנייה, טוקן, מסמך, חידוש, כשל, ניסיון בלי
 * כרטיס, תשלום חשבונית פתוחה, החזר ידני, ואימות הודעה חתומה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-morning-' . getmypid();
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

// ---------- מורנינג מדומה ----------
$M = ['calls' => [], 'clients' => [], 'docs' => [], 'tokens' => [], 'forms' => [], 'token_issued' => 0, 'decline' => false, 'n' => 1000];
$GLOBALS['PAY_HTTP_HOOK'] = function (string $url, ?string $post, array $headers) use (&$M) {
    $body = $post !== null ? json_decode($post, true) : null;
    $auth = '';
    foreach ($headers as $h) if (stripos($h, 'Authorization: Bearer ') === 0) $auth = substr($h, 22);
    $path = (string)parse_url($url, PHP_URL_PATH);
    $M['calls'][] = [$url, $body];
    $j = fn(int $st, array $b) => ['status' => $st, 'body' => json_encode($b, JSON_UNESCAPED_UNICODE)];
    if ($path === '/idp/v1/oauth/token') {
        if (!str_starts_with($url, 'https://api.sandbox.morning.dev')) return $j(500, ['error' => 'wrong host']);
        if (($body['client_id'] ?? '') !== 'cid-1' || ($body['client_secret'] ?? '') !== 'sec-1' || ($body['grant_type'] ?? '') !== 'client_credentials') return $j(401, ['error' => 'invalid_client']);
        $M['token_issued']++;
        return $j(200, ['accessToken' => 'jwt-' . $M['token_issued'], 'tokenType' => 'Bearer', 'expiresAt' => (int)getenv('PAY_NOW') + 3600]);
    }
    if (!str_starts_with($url, 'https://sandbox.d.greeninvoice.co.il/api/v1/')) return $j(500, ['errorMessage' => 'wrong api host']);
    if (!str_starts_with($auth, 'jwt-')) return $j(401, ['errorMessage' => 'no token']);
    $api = substr($path, strlen('/api/v1'));
    if ($api === '/clients') {
        $id = 'mcl-' . count($M['clients']);
        $M['clients'][$id] = $body;
        return $j(201, ['id' => $id, 'name' => $body['name']] + $body);
    }
    if ($api === '/payments/form') {
        foreach (['description', 'type', 'amount', 'currency', 'vatType', 'lang', 'client'] as $k) if (!isset($body[$k])) return $j(400, ['errorMessage' => "missing $k"]);
        $M['forms'][$body['custom']] = $body;
        return $j(201, ['success' => true, 'errorCode' => 0, 'url' => 'https://pay.morning.test/form/' . $body['custom']]);
    }
    if ($api === '/documents/search') {
        $items = array_values(array_filter($M['docs'], fn($d) => str_contains($d['description'], (string)($body['description'] ?? ''))));
        return $j(200, ['total' => count($items), 'page' => 1, 'pageSize' => 20, 'from' => 0, 'to' => count($items), 'pages' => 1, 'items' => $items]);
    }
    if ($api === '/payments/tokens/search') {
        $items = array_values(array_filter($M['tokens'], fn($t) => !isset($body['externalKey']) || $t['externalKey'] === $body['externalKey']));
        return $j(200, ['total' => count($items), 'page' => 1, 'pageSize' => 25, 'from' => 0, 'to' => count($items), 'pages' => 1, 'items' => $items]);
    }
    if (preg_match('#^/payments/tokens/([^/]+)/charge$#', $api, $mm)) {
        if ($M['decline'] || !isset($M['tokens'][$mm[1]])) return $j(400, ['errorCode' => 2011, 'errorMessage' => 'כרטיס נדחה']);
        $M['n']++;
        $doc = ['id' => 'mdoc-' . $M['n'], 'type' => $body['type'], 'number' => (string)$M['n'], 'description' => $body['description'], 'amount' => $body['amount'], 'creationDate' => (int)getenv('PAY_NOW')];
        $M['docs'][] = $doc;
        return $j(200, $doc + ['signed' => true, 'lang' => 'he', 'client' => ['id' => $M['tokens'][$mm[1]]['externalKey']], 'url' => ['he' => 'https://docs.morning.test/' . $doc['id']], 'transactionId' => 'tx-' . $M['n']]);
    }
    if ($api === '/documents') {
        if (!empty($M['docs_down'])) return $j(503, ['errorMessage' => 'תחזוקה']);
        foreach (['type', 'lang', 'currency', 'vatType', 'income'] as $k) if (!isset($body[$k])) return $j(400, ['errorMessage' => "missing $k"]);
        $M['n']++;
        $M['issued'][] = $body;
        return $j(201, ['id' => 'mdoc-' . $M['n'], 'type' => $body['type'], 'number' => (string)$M['n'], 'url' => ['he' => 'https://docs.morning.test/mdoc-' . $M['n']]]);
    }
    return $j(404, ['errorMessage' => "unknown $api"]);
};
/** הלקוח משלם בדף של מורנינג: נוצר מסמך (ואם כרטיס — טוקן) */
function morning_pays(string $checkoutId, bool $card = true): void
{
    global $M;
    $f = $M['forms'][$checkoutId];
    $M['n']++;
    $M['docs'][] = ['id' => 'mdoc-' . $M['n'], 'type' => $f['type'], 'number' => (string)$M['n'], 'description' => $f['description'], 'amount' => $f['amount'], 'creationDate' => (int)getenv('PAY_NOW'), 'allocationNumber' => ''];
    if ($card) $M['tokens']['tok-' . $M['n']] = ['id' => 'tok-' . $M['n'], 'externalKey' => $f['client']['id'], 'status' => 1, 'cardType' => 2, 'number' => '1234', 'expires' => '0929', 'creationDate' => (int)getenv('PAY_NOW')];
}
function last_call(string $needle): ?array
{
    global $M;
    foreach (array_reverse($M['calls']) as $c) if (str_contains($c[0], $needle)) return $c;
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
price_create($owner, $plan['id'], ['amount' => 3900, 'interval' => 'month']);
price_create($owner, $trialPlan['id'], ['amount' => 2900, 'interval' => 'month', 'trial_days' => 7]);
price_create($owner, $unit['id'], ['amount' => 1500]);
$urls = ['return_url' => 'https://example.com/back', 'cancel_url' => 'https://example.com/cancel'];

section('חיבור');
check('חיבור בלי סוד — נדחה', throws(fn() => provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'morning', 'env' => 'test', 'client_id' => 'cid-1']), 'missing_credentials'));
$pa = provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'morning', 'env' => 'test', 'client_id' => 'cid-1', 'client_secret' => 'bad', 'webhook_secret' => 'whs-1', 'methods' => ['card', 'bit']]);
check('הסודות לא חוזרים למסך', $pa['client_id'] === '…id-1' && $pa['has_secret'] && !str_contains(json_encode($pa), 'whs-1'));
$chk = provider_account_check($owner, $pa['id']);
check('קודים שגויים — בדיקה נכשלת עם הסבר', $chk['check_error'] !== '' && str_contains($chk['check_error'], 'דחתה'));
$pa = provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'morning', 'env' => 'test', 'client_id' => '', 'client_secret' => 'sec-1']);
check('עדכון סוד בלי לגעת במזהה', $pa['client_id'] === '…id-1');
check('בדיקה עוברת', provider_account_check($owner, $pa['id'])['check_error'] === '');
check('המסד: הקודים מוצפנים', !str_contains((string)db_one('SELECT credentials_enc FROM provider_account WHERE id = ?', [$pa['id']])['credentials_enc'], 'sec-1'));
check('בסביבת בדיקות — מורנינג במקום הסולק המדומה', provider_for($lic, 'test')->id() === $pa['id']);
check('לעוסק אחר — עדיין המדומה', provider_for($exempt, 'test')->id() === 'fake');
check('חי בלי חיבור — אין סליקה', throws(fn() => provider_for($lic, 'live'), 'no_provider'));

section('קנייה');
customer_upsert($app, 'test', ['external_id' => 'family:1', 'name' => 'משפחת כהן', 'email' => 'cohen@example.com', 'tax_id' => '123456789']);
$c = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'subscription', 'items' => [['product' => 'apps']]] + $urls);
$v = checkout_view(checkout_load($c['checkout']));
check('מנוי — רק כרטיס', $v['methods'] === ['card']);
$url = checkout_start(checkout_load($c['checkout']), 'card');
check('הפניה לדף של מורנינג', $url === 'https://pay.morning.test/form/' . $c['checkout']);
$f = last_call('/payments/form')[1];
check('נשלח: חשבונית מס/קבלה (320), ₪39, עברית, אשראי', $f['type'] === 320 && $f['amount'] == 39 && $f['currency'] === 'ILS' && $f['lang'] === 'he' && $f['group'] === 100);
check('נשלח: שורה כוללת מע"מ', $f['income'][0]['vatType'] === 1 && $f['income'][0]['price'] == 39 && $f['income'][0]['quantity'] === 1);
check('נשלח: לקוח שנוצר במורנינג, עם ח"פ ומייל', str_starts_with($f['client']['id'], 'mcl-') && $M['clients'][$f['client']['id']]['taxId'] === '123456789' && $M['clients'][$f['client']['id']]['emails'] === ['cohen@example.com']);
check('נשלח: חזרה, כישלון, הודעה ואסמכתא', str_contains($f['successUrl'], 'pay/return.php?c=') && str_contains($f['failureUrl'], 'failed=1') && str_contains($f['notifyUrl'], 'hooks/morning.php?pa=') && $f['custom'] === $c['checkout']);
check('בלי תשלום — הקנייה פתוחה', checkout_sync(checkout_load($c['checkout']))['status'] === 'open');
morning_pays($c['checkout']);
$after = checkout_sync(checkout_load($c['checkout']));
check('אחרי תשלום במורנינג — נסגרה', $after['status'] === 'completed');
$acc = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:1'");
$pm = db_one('SELECT * FROM payment_method WHERE id = ?', [$acc['default_method']]);
check('הכרטיס של מורנינג נשמר (טוקן, 4 ספרות, תוקף)', $pm && $pm['provider'] === $pa['id'] && $pm['last4'] === '1234' && $pm['exp'] === '09/29' && unseal($pm['token_enc']) !== '');
$doc = db_one('SELECT * FROM tax_document');
check('המסמך החשבונאי של מורנינג נשמר', $doc && (int)$doc['type'] === 320 && $doc['provider'] === $pa['id']);
check('לקוח שני באותו חשבון — לא נוצר שוב במורנינג', (function () use ($app, $urls, &$M) {
    $n = count($M['clients']);
    $c2 = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'report']]] + $urls);
    checkout_start(checkout_load($c2['checkout']), 'bit');
    return count($M['clients']) === $n && last_call('/payments/form')[1]['group'] === 120;
})());
check('טוקן הכניסה נשמר ומשמש שוב', $M['token_issued'] <= 3, (string)$M['token_issued']);

section('חידוש מטוקן');
at(31);
subscriptions_renew();
$ch = last_call('/charge');
check('חיוב: הטוקן שנשמר, ₪39, מסמך 320', $ch && str_contains($ch[0], '/payments/tokens/' . unseal($pm['token_enc']) . '/charge') && $ch[1]['amount'] == 39 && $ch[1]['type'] === 320);
$sub = db_one("SELECT s.* FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:1'");
check('המנוי פעיל, והמסמך נשמר', $sub['status'] === 'active' && (int)db_one('SELECT COUNT(*) AS n FROM tax_document')['n'] === 2);

section('כשל — ובלי חיוב כפול');
at(62);
$M['decline'] = true;
subscriptions_renew();
$sub = db_one('SELECT * FROM subscription WHERE id = ?', [$sub['id']]);
check('נדחה → באיחור', $sub['status'] === 'past_due');
$open = db_one("SELECT * FROM invoice WHERE subscription = ? AND status = 'open'", [$sub['id']]);
// מורנינג חייבה בפועל בניסיון שנראה כנכשל (ניתוק) — מסמך עם האסמכתא כבר קיים
$M['decline'] = false;
$M['n']++;
$M['docs'][] = ['id' => 'mdoc-x', 'type' => 320, 'number' => '9999', 'description' => 'מגדלור · אסמכתא ' . substr($open['id'], -10), 'amount' => 39, 'creationDate' => (int)getenv('PAY_NOW')];
$charges = count(array_filter($M['calls'], fn($c) => str_contains($c[0], '/charge')));
at(63.1);
invoices_retry();
check('הניסיון הבא מוצא את החיוב הקודם — לא מחייב שוב', count(array_filter($M['calls'], fn($c) => str_contains($c[0], '/charge'))) === $charges
    && db_one('SELECT status FROM invoice WHERE id = ?', [$open['id']])['status'] === 'paid' && db_one('SELECT status FROM subscription WHERE id = ?', [$sub['id']])['status'] === 'active');

section('ניסיון חינם בלי כרטיס');
at(100);
customer_upsert($app, 'test', ['external_id' => 'family:2', 'name' => 'לוי', 'email' => 'levi@example.com']);
$c = checkout_create($app, 'test', ['external_id' => 'family:2', 'mode' => 'subscription', 'items' => [['product' => 'trial_plan']]] + $urls);
check('ניסיון — "בלי כרטיס עכשיו" (מורנינג לא שומרת כרטיס בלי חיוב)', checkout_view(checkout_load($c['checkout']))['methods'] === ['none']);
checkout_start(checkout_load($c['checkout']), 'none');
$sub2 = db_one("SELECT s.* FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:2'");
check('הניסיון התחיל', $sub2['status'] === 'trialing' && entitlement($app, 'test', ['external_id' => 'family:2'])['active']);
at(107.1);
subscriptions_renew();
$sub2 = db_one('SELECT * FROM subscription WHERE id = ?', [$sub2['id']]);
check('סוף הניסיון בלי כרטיס → באיחור, חשבונית פתוחה', $sub2['status'] === 'past_due');
$inv2 = db_one("SELECT * FROM invoice WHERE subscription = ? AND status = 'open'", [$sub2['id']]);
$c = checkout_create($app, 'test', ['external_id' => 'family:2', 'mode' => 'invoice', 'invoice' => $inv2['id']] + $urls);
check('תשלום החשבונית הפתוחה — ₪29, כרטיס או ביט', $c['amount'] === 2900 && checkout_view(checkout_load($c['checkout']))['methods'] === ['card', 'bit']);
checkout_start(checkout_load($c['checkout']), 'card');
morning_pays($c['checkout']);
checkout_sync(checkout_load($c['checkout']));
$sub2 = db_one('SELECT * FROM subscription WHERE id = ?', [$sub2['id']]);
check('שולם → פעיל, החשבונית שולמה, הכרטיס נשמר לחידוש הבא', $sub2['status'] === 'active' && db_one('SELECT status FROM invoice WHERE id = ?', [$inv2['id']])['status'] === 'paid'
    && db_one("SELECT default_method FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:2'")['default_method'] !== null);
check('חשבונית של לקוח אחר — לא לתשלום', throws(fn() => checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'invoice', 'invoice' => $inv2['id']] + $urls), 'not_found'));

section('עוסק פטור — קבלה (400)');
$app2 = client_create($owner, ['id' => 'recipes-app', 'name' => 'מתכונים', 'merchant' => $exempt['id']]);
$pa2 = provider_account_save($owner, ['merchant' => $exempt['id'], 'provider' => 'morning', 'env' => 'test', 'client_id' => 'cid-1', 'client_secret' => 'sec-1']);
$p2 = product_save($owner, 'recipes-app', ['code' => 'premium', 'name' => 'פרימיום', 'type' => 'plan']);
price_create($owner, $p2['id'], ['amount' => 1900, 'interval' => 'month']);
customer_upsert($app2, 'test', ['external_id' => 'user:9']);
$c = checkout_create($app2, 'test', ['external_id' => 'user:9', 'mode' => 'subscription', 'items' => [['product' => 'premium']]] + $urls);
checkout_start(checkout_load($c['checkout']), 'card');
check('פטור → קבלה 400', last_call('/payments/form')[1]['type'] === 400);

section('החזר');
$pay = db_one("SELECT * FROM payment WHERE provider = ? AND status = 'succeeded' ORDER BY created_at LIMIT 1", [$pa['id']]);
check('החזר דרך הממשק — מורנינג לא תומכת, הסבר', throws(fn() => payment_refund($pay['id'], 1000, 'בדיקה', 'admin:x'), 'manual_refund'));
$r = payment_refund($pay['id'], 1000, 'החזר שבוצע בממשק מורנינג', 'admin:x', true);
check('רישום החזר ידני', $r['payment_status'] === 'partially_refunded' && (int)db_one('SELECT manual FROM refund WHERE id = ?', [$r['refund']])['manual'] === 1);

section('הודעה חתומה ממורנינג');
$mp = provider($pa['id']);
$raw = '{"id":"x","custom":"' . $c['checkout'] . '"}';
check('חתימה נכונה — מתקבלת', $mp->verifyWebhook($raw, hash_hmac('sha256', $raw, 'whs-1')));
check('חתימה שגויה — נדחית', !$mp->verifyWebhook($raw, hash_hmac('sha256', $raw, 'other')));
check('גוף ששונה — נדחה', !$mp->verifyWebhook($raw . ' ', hash_hmac('sha256', $raw, 'whs-1')));
check('בלי סוד מוגדר — נדחית', !provider($pa2['id'])->verifyWebhook($raw, hash_hmac('sha256', $raw, '')));

section('מסמכים לתשלומים שלא דרך מורנינג (שלב 5)');
$M['issued'] = [];
at(200);
$issued0 = tax_docs_issue();
check('תשלומים שעברו בסליקה של מורנינג — לא מקבלים מסמך נוסף', $issued0 === 0 && !$M['issued'], json_encode($M['issued'], JSON_UNESCAPED_UNICODE));
$accL = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:1'");
$mp1 = manual_payment($owner, ['account' => $accL['id'], 'method' => 'cash', 'agorot' => 5000, 'purpose' => 'topup', 'note' => 'שילם במשרד']);
check('תשלום ידני במזומן → ₪50 ביתרה', customer_view(customer_find($app, 'test', 'family:1'))['account']['balance'] === 5000);
check('תשלום ידני בכרטיס — נדחה (רק דרך סולק)', throws(fn() => manual_payment($owner, ['account' => $accL['id'], 'method' => 'card', 'agorot' => 100]), 'bad_method'));
$M['docs_down'] = true;
tax_docs_issue();
check('מורנינג לא זמינה — נרשם ניסיון ושגיאה, בלי לעצור', (int)db_one('SELECT doc_attempts FROM payment WHERE id = ?', [$mp1['payment']])['doc_attempts'] === 1
    && db_one('SELECT doc_error FROM payment WHERE id = ?', [$mp1['payment']])['doc_error'] !== '');
$M['docs_down'] = false;
tax_docs_issue();
$d = end($M['issued']);
check('המסמך הופק: חשבונית מס/קבלה, תשלום במזומן (1), ₪50', $d['type'] === 320 && $d['payment'][0]['type'] === 1 && $d['payment'][0]['price'] == 50 && $d['income'][0]['price'] == 50);
check('ונשמר אצלנו', (bool)db_one('SELECT 1 FROM tax_document WHERE payment = ? AND refund IS NULL', [$mp1['payment']]));
check('ריצה נוספת — לא מפיקה שוב', tax_docs_issue() === 0);
$mp2 = manual_payment($owner, ['account' => $accL['id'], 'method' => 'transfer', 'agorot' => 2000, 'reference' => 'ASM-77', 'purpose' => 'topup']);
tax_docs_issue();
$d = end($M['issued']);
check('העברה בנקאית (4) עם אסמכתא', $d['payment'][0]['type'] === 4 && $d['payment'][0]['transactionId'] === 'ASM-77');
// החזר על תשלום ידני → זיכוי 330 שמבטל את המקור
$r = payment_refund($mp1['payment'], 1000, 'החזר חלקי', 'admin:x');
check('החזר על תשלום ידני — נרשם ידנית', (int)db_one('SELECT manual FROM refund WHERE id = ?', [$r['refund']])['manual'] === 1);
tax_docs_issue();
$d = end($M['issued']);
$orig = db_one('SELECT external_id FROM tax_document WHERE payment = ? AND refund IS NULL', [$mp1['payment']])['external_id'];
check('חשבונית זיכוי (330) שמבטלת את המקור, ₪10', $d['type'] === 330 && $d['linkedDocumentIds'] === [$orig] && $d['linkType'] === 'cancel' && $d['income'][0]['price'] == 10);
// עוסק פטור: קבלה (400), בלי חשבונית זיכוי
$accE = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'user:9'");
$mp3 = manual_payment($owner, ['account' => $accE['id'], 'method' => 'bit', 'agorot' => 1900, 'reference' => 'BIT-1', 'purpose' => 'topup']);
tax_docs_issue();
$d = end($M['issued']);
check('פטור: קבלה (400), ביט כאפליקציית תשלום (10/1)', $d['type'] === 400 && $d['payment'][0]['type'] === 10 && $d['payment'][0]['appType'] === 1);
$before = count($M['issued']);
payment_refund($mp3['payment'], 1900, 'ביטול', 'admin:x');
tax_docs_issue();
check('פטור — אין חשבונית זיכוי', count($M['issued']) === $before);
// עוסק בלי חיבור — אין מסמך, ואין שגיאה
$noDoc = merchant_save($owner, ['name' => 'בלי מורנינג', 'tax_status' => 'licensed']);
client_create($owner, ['id' => 'nodoc', 'name' => 'בלי', 'merchant' => $noDoc['id']]);
customer_upsert(client_get($owner, 'nodoc'), 'test', ['external_id' => 'z']);
$accZ = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'z'");
$mp4 = manual_payment($owner, ['account' => $accZ['id'], 'method' => 'cash', 'agorot' => 100, 'purpose' => 'topup']);
$before = count($M['issued']);
tax_docs_issue();
check('עוסק בלי חיבור — אין מסמך, אין שגיאה (מסומן "חסר מסמך")', count($M['issued']) === $before && (int)db_one('SELECT doc_attempts FROM payment WHERE id = ?', [$mp4['payment']])['doc_attempts'] === 0);
// תשלום ידני על חשבונית פתוחה מחזיר מנוי לפעיל
at(240);
$M['decline'] = true;
subscriptions_renew();
$M['decline'] = false;
$open = db_one("SELECT i.* FROM invoice i JOIN subscription s ON s.id = i.subscription JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:1' AND i.status = 'open'");
manual_payment($owner, ['account' => $accL['id'], 'method' => 'transfer', 'purpose' => 'invoice', 'invoice' => $open['id'], 'reference' => 'X']);
check('העברה על חשבונית פתוחה → המנוי חוזר לפעיל', db_one('SELECT status FROM subscription WHERE id = ?', [$open['subscription']])['status'] === 'active'
    && db_one('SELECT status FROM invoice WHERE id = ?', [$open['id']])['status'] === 'paid');

section('מוכנות לכסף אמיתי');
require_once __DIR__ . '/../lib/readiness.php';
$st = fn() => array_column(readiness($owner)['items'], 'state', 'key');
$r = $st();
check('ניסיון דרך הסולק ומסמך — מוכן', $r['provider_test'] === 'ok' && $r['sandbox_payment'] === 'ok' && $r['sandbox_document'] === 'ok');
check('בלי חיבור חי, תנאים ובדיקה בכסף אמיתי — פתוח', $r['provider_live'] === 'todo' && $r['terms'] === 'todo' && $r['live_test'] === 'todo' && $r['merchant'] === 'todo');
check('משימה מתוזמנת — רק המלצה', $r['clock'] === 'warn');
check('פריט ידני לא מוכר — נדחה', throws(fn() => readiness_mark($owner, 'nope', true), 'bad_key'));
readiness_mark($owner, 'legal_review', true);
check('סימון ידני נשמר, וביטול שלו', $st()['legal_review'] === 'ok' && readiness_mark($owner, 'legal_review', false)['items'] && $st()['legal_review'] === 'todo');
$pv = checkout_view(db_one("SELECT * FROM checkout WHERE client = 'guarded-browser' ORDER BY created_at DESC LIMIT 1"));
check('דף התשלום מקבל את פרטי המוכר', ($pv['seller']['name'] ?? '') === 'חברה בע"מ' && ($pv['seller']['email'] ?? '') === 'b@example.com' && $pv['terms_url'] === '');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
