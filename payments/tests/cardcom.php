<?php
/**
 * בדיקות המחבר של קארדקום: php payments/tests/cardcom.php
 * מול "קארדקום מדומה" שבנוי לפי התיעוד הרשמי (‏secure.cardcom.solutions/swagger/v11/swagger.json):
 * ‏LowProfile/Create, ‏GetLpResult, ‏Transactions/Transaction (טוקן, ‏ExternalUniqTranId), ‏RefundByTransactionId.
 * בודק את מבנה הבקשות שאנחנו שולחים, ואת כל הזרימה: חיבור, קנייה, טוקן, מסמך, חידוש, כפילות, כשל, ניסיון
 * חינם עם שמירת כרטיס בלי חיוב, ביט, החזר, ועוסק פטור.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-cardcom-' . getmypid();
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

// ---------- קארדקום מדומה ----------
$C = ['calls' => [], 'lp' => [], 'txn' => [], 'uniq' => [], 'n' => 500000, 'doc' => 7000, 'decline' => false];
$GLOBALS['PAY_HTTP_HOOK'] = function (string $url, ?string $post, array $headers) use (&$C) {
    $b = json_decode((string)$post, true) ?: [];
    $C['calls'][] = [$url, $b];
    $j = fn(array $r) => ['status' => 200, 'body' => json_encode($r, JSON_UNESCAPED_UNICODE)];
    if (!str_starts_with($url, 'https://secure.cardcom.solutions/api/v11/')) return ['status' => 404, 'body' => ''];
    $op = substr($url, strlen('https://secure.cardcom.solutions/api/v11/'));
    $withPass = in_array($op, ['Transactions/RefundByTransactionId'], true);
    if ($withPass) {
        if (($b['ApiName'] ?? '') !== 'api-user' || ($b['ApiPassword'] ?? '') !== 'api-pass') return $j(['ResponseCode' => 603, 'Description' => 'שם משתמש או סיסמה שגויים']);
    } elseif (($b['TerminalNumber'] ?? 0) !== 1234 || ($b['ApiName'] ?? '') !== 'api-user') {
        return $j(['ResponseCode' => 603, 'Description' => 'שם משתמש או סיסמה שגויים']);
    }
    $doc = function (array $d, float $amount) use (&$C) {
        if (!$d) return [];
        $sum = array_sum(array_map(fn($p) => (float)($p['TotalLineCost'] ?? $p['UnitCost'] * ($p['Quantity'] ?? 1)), $d['Products'] ?? []));
        if (abs($sum - $amount) > 0.001) return ['DocumentResponse' => 'sum mismatch'];
        $C['doc']++;
        return ['DocumentNumber' => $C['doc'], 'DocumentType' => !empty($d['IsVatFree']) ? 'Receipt' : 'TaxInvoiceAndReceipt', 'DocumentUrl' => 'https://docs.cardcom.test/' . $C['doc']];
    };
    switch ($op) {
        case 'LowProfile/Create':
            foreach (['ReturnValue', 'Amount', 'SuccessRedirectUrl', 'FailedRedirectUrl', 'WebHookUrl'] as $k) if (!isset($b[$k])) return $j(['ResponseCode' => 5000, 'Description' => "חסר $k"]);
            $id = sprintf('%08x-0000-4000-8000-%012x', count($C['lp']) + 1, crc32($b['ReturnValue']));
            $C['lp'][$id] = ['req' => $b, 'state' => 'open'];
            return $j(['ResponseCode' => 0, 'Description' => 'OK', 'LowProfileId' => $id, 'Url' => "https://secure.cardcom.solutions/External/LowProfile.aspx?LowProfileCode=$id", 'UrlToBit' => "https://bit.cardcom.test/$id"]);
        case 'LowProfile/GetLpResult':
            $lp = $C['lp'][$b['LowProfileId'] ?? ''] ?? null;
            if (!$lp) return $j(['ResponseCode' => 5101, 'Description' => 'לא נמצא']);
            if ($lp['state'] === 'open') return $j(['ResponseCode' => 5118, 'Description' => 'העסקה עוד לא הושלמה', 'LowProfileId' => $b['LowProfileId']]);
            return $j($lp['result']);
        case 'Transactions/Transaction':
            $u = $b['ExternalUniqTranId'] ?? '';
            if ($u !== '' && isset($C['uniq'][$u])) {
                return empty($b['ExternalUniqUniqTranIdResponse']) ? $j(['ResponseCode' => 608, 'Description' => 'עסקה כפולה']) : $j($C['txn'][$C['uniq'][$u]]);
            }
            if ($C['decline'] || !str_starts_with((string)($b['Token'] ?? ''), 'tok-') || ($b['CardExpirationMMYY'] ?? '') !== '0929') return $j(['ResponseCode' => 5033, 'Description' => 'כרטיס נדחה']);
            $C['n']++;
            $r = ['ResponseCode' => 0, 'Description' => 'OK', 'TranzactionId' => $C['n'], 'Amount' => $b['Amount'], 'Last4CardDigitsString' => '4580', 'Brand' => 'Visa', 'CardMonth' => 9, 'CardYear' => 29]
                + $doc($b['Document'] ?? [], (float)$b['Amount']);
            $C['txn'][$C['n']] = $r;
            if ($u !== '') $C['uniq'][$u] = $C['n'];
            return $j($r);
        case 'Transactions/RefundByTransactionId':
            $t = $C['txn'][$b['TransactionId'] ?? 0] ?? null;
            if (!$t) return $j(['ResponseCode' => 5100, 'Description' => 'עסקה לא נמצאה']);
            $C['refunded'][$b['TransactionId']] = ($C['refunded'][$b['TransactionId']] ?? 0) + (float)$b['PartialSum'];
            if ($C['refunded'][$b['TransactionId']] > (float)$t['Amount'] + 0.001) return $j(['ResponseCode' => 5200, 'Description' => 'סכום ההחזר גדול מהעסקה']);
            $C['n']++;
            return $j(['ResponseCode' => 0, 'Description' => 'OK', 'NewTranzactionId' => $C['n']]);
    }
    return ['status' => 404, 'body' => ''];
};
/** הלקוח משלם בדף של קארדקום (או נכשל). ‏$bit — בלי טוקן. */
function cardcom_pays(string $checkoutId, bool $ok = true, bool $bit = false): void
{
    global $C;
    foreach ($C['lp'] as $id => &$lp) {
        if ($lp['req']['ReturnValue'] !== $checkoutId) continue;
        $r = $lp['req'];
        $op = $r['Operation'] ?? 'ChargeOnly';
        $lp['state'] = 'done';
        if (!$ok) { $lp['result'] = ['ResponseCode' => 5033, 'Description' => 'כרטיס נדחה', 'LowProfileId' => $id]; return; }
        $C['n']++;
        $tok = in_array($op, ['ChargeAndCreateToken', 'CreateTokenOnly'], true) && !$bit
            ? ['Token' => 'tok-' . $C['n'], 'TokenExDate' => '20291001', 'CardYear' => 2029, 'CardMonth' => 9] : null;
        $t = $op === 'CreateTokenOnly' ? null : ['ResponseCode' => 0, 'TranzactionId' => $C['n'], 'Amount' => $r['Amount'], 'Last4CardDigitsString' => '4580',
            'Brand' => 'Visa', 'CardMonth' => 9, 'CardYear' => 29];
        if ($t) {
            $docInfo = [];
            if (!empty($r['Document'])) {
                $sum = array_sum(array_map(fn($p) => (float)$p['TotalLineCost'], $r['Document']['Products']));
                if (abs($sum - (float)$r['Amount']) < 0.001) { $C['doc']++; $docInfo = ['DocumentNumber' => $C['doc'], 'DocumentType' => !empty($r['Document']['IsVatFree']) ? 'Receipt' : 'TaxInvoiceAndReceipt', 'DocumentUrl' => 'https://docs.cardcom.test/' . $C['doc']]; }
            }
            $t += $docInfo;
            $C['txn'][$C['n']] = $t;
        }
        $lp['result'] = ['ResponseCode' => 0, 'Description' => 'OK', 'LowProfileId' => $id, 'ReturnValue' => $checkoutId, 'Operation' => $op,
            'TokenInfo' => $tok, 'TranzactionInfo' => $t, 'ExternalPaymentVector' => $bit ? 'Bit' : ''];
        return;
    }
}
function last_call(string $needle): ?array
{
    global $C;
    foreach (array_reverse($C['calls']) as $c) if (str_contains($c[0], $needle)) return $c;
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
check('בלי מספר מסוף — נדחה', throws(fn() => provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'cardcom', 'env' => 'test', 'api_name' => 'api-user']), 'missing_credentials'));
check('מסוף לא מספרי — נדחה', throws(fn() => provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'cardcom', 'env' => 'test', 'terminal' => 'abc', 'api_name' => 'api-user']), 'bad_terminal'));
$pa = provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'cardcom', 'env' => 'test', 'terminal' => '1234', 'api_name' => 'wrong', 'methods' => ['card', 'bit']]);
$chk = provider_account_check($owner, $pa['id']);
check('שם משתמש שגוי — בדיקה נכשלת עם הסבר', str_contains($chk['check_error'], 'דחתה'), $chk['check_error']);
$pa = provider_account_save($owner, ['merchant' => $lic['id'], 'provider' => 'cardcom', 'env' => 'test', 'terminal' => '', 'api_name' => 'api-user', 'api_password' => 'api-pass']);
check('בדיקה עוברת', provider_account_check($owner, $pa['id'])['check_error'] === '');
check('הסיסמה לא חוזרת למסך, המסוף מקוצר', $pa['fields']['api_password'] === true && $pa['fields']['terminal'] === '…1234' && !str_contains(json_encode($pa), 'api-pass'));
check('ברירת מחדל: הקבלה מקארדקום', $pa['settings']['documents'] === 'cardcom' && provider($pa['id'])->capabilities()['documents']);
check('בסביבת בדיקות — קארדקום', provider_for($lic, 'test')->id() === $pa['id']);
db_run("INSERT INTO provider_account (id, merchant, provider, env, credentials_enc, settings, created_at) VALUES ('pa_morningxxxxxxxxxxxxx', ?, 'morning', 'test', ?, '{}', 1)", [$lic['id'], seal('{}')]);
check('עם מורנינג לצדה — קארדקום עדיין הסולק (מורנינג למסמכים)', provider_for($lic, 'test')->id() === $pa['id']);
db_run("DELETE FROM provider_account WHERE id = 'pa_morningxxxxxxxxxxxxx'");

section('קנייה — מנוי');
customer_upsert($app, 'test', ['external_id' => 'family:1', 'name' => 'משפחת כהן', 'email' => 'cohen@example.com', 'tax_id' => '123456789']);
$c = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'subscription', 'items' => [['product' => 'apps']]] + $urls);
check('מנוי — כרטיס (גם ביט לא, כי חיוב חוזר)', checkout_view(checkout_load($c['checkout']))['methods'] === ['card']);
$url = checkout_start(checkout_load($c['checkout']), 'card');
$req = last_call('LowProfile/Create')[1];
check('הפניה לדף של קארדקום', str_starts_with($url, 'https://secure.cardcom.solutions/External/LowProfile.aspx'));
check('נשלח: חיוב ושמירת כרטיס, ₪39, שקל, עברית', $req['Operation'] === 'ChargeAndCreateToken' && $req['Amount'] == 39 && $req['ISOCoinId'] === 1 && $req['Language'] === 'he');
check('נשלח: אסמכתא, חזרה, כישלון, ביטול והודעה', $req['ReturnValue'] === $c['checkout'] && str_contains($req['SuccessRedirectUrl'], 'pay/return.php?c=')
    && str_contains($req['FailedRedirectUrl'], 'failed=1') && $req['CancelRedirectUrl'] === 'https://example.com/cancel' && str_contains($req['WebHookUrl'], 'hooks/cardcom.php?pa=' . $pa['id']));
check('נשלח: מסמך על שם הלקוח, עם ח"פ ומייל, שורות = הסכום', $req['Document']['Name'] === 'משפחת כהן' && $req['Document']['TaxId'] === '123456789'
    && $req['Document']['Email'] === 'cohen@example.com' && $req['Document']['Products'][0]['TotalLineCost'] == 39 && !isset($req['Document']['IsVatFree']));
check('פרטי הלקוח ממולאים בדף', $req['UIDefinition']['CardOwnerEmailValue'] === 'cohen@example.com');
check('בלי תשלום — הקנייה פתוחה', checkout_sync(checkout_load($c['checkout']))['status'] === 'open');
cardcom_pays($c['checkout']);
$after = checkout_sync(checkout_load($c['checkout']));
check('אחרי תשלום בקארדקום — נסגרה', $after['status'] === 'completed');
$acc = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:1'");
$pm = db_one('SELECT * FROM payment_method WHERE id = ?', [$acc['default_method']]);
$tk = json_decode(unseal($pm['token_enc']), true);
check('הכרטיס נשמר: טוקן + תוקף (קארדקום דורשת אותו בחיוב), 4 ספרות, מותג', $tk['t'] === 'tok-' . $C['n'] && $tk['e'] === '0929' && $pm['last4'] === '4580' && $pm['brand'] === 'visa' && $pm['exp'] === '09/29');
$pay = db_one('SELECT * FROM payment WHERE checkout = ?', [$c['checkout']]);
$doc = db_one('SELECT * FROM tax_document WHERE payment = ?', [$pay['id']]);
check('התשלום נרשם עם מזהה העסקה, והמסמך של קארדקום נשמר', str_starts_with($pay['provider_txn'], 'txn:') && $doc && (int)$doc['type'] === 320 && $doc['url'] !== '');
check('לא יוצא מסמך נוסף מהשעון', payment_self_documented($pay) && tax_docs_issue() === 0);

section('הודעה מקארדקום');
$lpId = substr(checkout_load($c['checkout'])['provider_ref'], 3);
check('ההודעה — רק המזהה, מ-JSON או מטופס', CardcomProvider::hookLowProfileId(json_encode(['LowProfileId' => strtoupper($lpId)]), []) === $lpId
    && CardcomProvider::hookLowProfileId('lowprofilecode=' . $lpId . '&x=1', []) === $lpId && CardcomProvider::hookLowProfileId('{"LowProfileId":"../../x"}', []) === '');

section('חידוש מטוקן');
at(31);
subscriptions_renew();
$ch = last_call('Transactions/Transaction')[1];
$sub = db_one("SELECT s.* FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:1'");
check('חיוב: הטוקן והתוקף, ₪39, מפתח ייחודיות, ומסמך', $ch['Token'] === $tk['t'] && $ch['CardExpirationMMYY'] === '0929' && $ch['Amount'] == 39
    && str_starts_with($ch['ExternalUniqTranId'], 'inv_') && $ch['ExternalUniqUniqTranIdResponse'] === true && $ch['Document']['Products'][0]['TotalLineCost'] == 39);
check('המנוי פעיל, והמסמך נשמר', $sub['status'] === 'active' && (int)db_one('SELECT COUNT(*) AS n FROM tax_document')['n'] === 2);
$p = provider($pa['id']);
$n = count($C['txn']);
$again = $p->chargeToken(unseal($pm['token_enc']), 3900, $ch['ExternalUniqTranId'], []);
check('אותו מפתח פעמיים — אותה עסקה, בלי חיוב נוסף', $again->status === 'succeeded' && count($C['txn']) === $n && $again->ref === 'txn:' . $C['uniq'][$ch['ExternalUniqTranId']]);
$other = $p->chargeToken(unseal($pm['token_enc']), 5000, $ch['ExternalUniqTranId'], []);
check('אותו מפתח בסכום אחר — לא נסגר', $other->status === 'failed' && $other->failureCode === 'amount_mismatch');

section('כשל בחידוש');
$C['decline'] = true;
at(62);
subscriptions_renew();
$C['decline'] = false;
$sub = db_one('SELECT * FROM subscription WHERE id = ?', [$sub['id']]);
check('נדחה — באיחור', $sub['status'] === 'past_due');
at(63.1);
invoices_retry();
check('ניסיון חוזר עבר — פעיל שוב', db_one('SELECT status FROM subscription WHERE id = ?', [$sub['id']])['status'] === 'active');

section('ניסיון חינם — שמירת כרטיס בלי חיוב');
customer_upsert($app, 'test', ['external_id' => 'family:2', 'email' => 'f2@example.com']);
$t = checkout_create($app, 'test', ['external_id' => 'family:2', 'mode' => 'subscription', 'items' => [['product' => 'trial_plan']]] + $urls);
check('ניסיון בקארדקום — כרטיס (לא "בלי כרטיס")', $t['amount'] === 0 && checkout_view(checkout_load($t['checkout']))['methods'] === ['card']);
checkout_start(checkout_load($t['checkout']), 'card');
$req = last_call('LowProfile/Create')[1];
check('נשלח: שמירת כרטיס בלי חיוב (J2), בלי מסמך', $req['Operation'] === 'CreateTokenOnly' && $req['AdvancedDefinition']['JValidateType'] === 2 && !isset($req['Document']));
cardcom_pays($t['checkout']);
check('הכרטיס נשמר, המנוי בניסיון, ואין תשלום', checkout_sync(checkout_load($t['checkout']))['status'] === 'completed'
    && db_one("SELECT s.status FROM subscription s JOIN customer c ON c.id = s.customer WHERE c.external_id = 'family:2'")['status'] === 'trialing'
    && !db_one('SELECT 1 FROM payment WHERE checkout = ?', [$t['checkout']])
    && db_one("SELECT a.default_method FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'family:2'")['default_method'] !== null);

section('ביט, וקנייה שנכשלה');
$b = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'report']]] + $urls);
$burl = checkout_start(checkout_load($b['checkout']), 'bit');
check('ביט — ישר לדף ביט של קארדקום, חיוב בלבד', str_starts_with($burl, 'https://bit.cardcom.test/') && last_call('LowProfile/Create')[1]['Operation'] === 'ChargeOnly');
cardcom_pays($b['checkout'], true, true);
checkout_sync(checkout_load($b['checkout']));
$bp = db_one('SELECT * FROM payment WHERE checkout = ?', [$b['checkout']]);
check('ביט עבר — נרשם, בלי כרטיס חדש', $bp && $bp['status'] === 'succeeded' && (int)db_one("SELECT COUNT(*) AS n FROM payment_method WHERE account = ?", [$acc['id']])['n'] === 1);
$f = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'report']]] + $urls);
checkout_start(checkout_load($f['checkout']), 'card');
cardcom_pays($f['checkout'], false);
check('נדחה בדף — הקנייה נשארת פתוחה (אפשר לנסות שוב)', checkout_sync(checkout_load($f['checkout']))['status'] === 'open');

section('מחיר לא כולל מע"מ');
$x = checkout_create($app, 'test', ['external_id' => 'family:1', 'mode' => 'payment', 'items' => [['product' => 'consult']]] + $urls);
checkout_start(checkout_load($x['checkout']), 'card');
$req = last_call('LowProfile/Create')[1];
check('₪118 בחיוב ובשורות המסמך', $req['Amount'] == 118 && array_sum(array_column($req['Document']['Products'], 'TotalLineCost')) == 118);

section('החזר');
$rf = payment_refund($pay['id'], 1000, 'החזר חלקי', 'admin:x');
$rq = last_call('RefundByTransactionId')[1];
check('החזר חלקי דרך קארדקום — העסקה, הסכום, וסיסמת הממשק', $rf['payment_status'] === 'partially_refunded' && $rq['TransactionId'] === (int)substr($pay['provider_txn'], 4)
    && $rq['PartialSum'] == 10 && $rq['ApiPassword'] === 'api-pass' && $rq['AllowMultipleRefunds'] === true && !isset($rq['TerminalNumber']));
check('הזיכוי — אצל קארדקום (לא נשלח למורנינג)', tax_docs_issue() === 0);
$pa2 = provider_account_save($owner, ['merchant' => $exempt['id'], 'provider' => 'cardcom', 'env' => 'test', 'terminal' => '1234', 'api_name' => 'api-user']);
check('בלי סיסמת ממשק — החזר רק ידני, עם הסבר', !provider($pa2['id'])->capabilities()['refund_api']
    && throws(fn() => provider($pa2['id'])->refund('txn:1', 100, 'x'), 'manual_refund'));

section('עוסק פטור, והקבלה ממורנינג');
$app2 = client_create($owner, ['id' => 'recipes-app', 'name' => 'מתכונים', 'merchant' => $exempt['id']]);
$pr = product_save($owner, 'recipes-app', ['code' => 'book', 'name' => 'ספר', 'type' => 'unit']);
price_create($owner, $pr['id'], ['amount' => 5000]);
customer_upsert($app2, 'test', ['external_id' => 'u:1', 'email' => 'u1@example.com']);
$e = checkout_create($app2, 'test', ['external_id' => 'u:1', 'mode' => 'payment', 'items' => [['product' => 'book']]] + $urls);
checkout_start(checkout_load($e['checkout']), 'card');
check('פטור — המסמך בלי מע"מ', last_call('LowProfile/Create')[1]['Document']['IsVatFree'] === true);
provider_account_save($owner, ['merchant' => $exempt['id'], 'provider' => 'cardcom', 'env' => 'test', 'terminal' => '', 'api_name' => '', 'documents' => 'morning']);
$e2 = checkout_create($app2, 'test', ['external_id' => 'u:1', 'mode' => 'payment', 'items' => [['product' => 'book']]] + $urls);
checkout_start(checkout_load($e2['checkout']), 'card');
check('"הקבלה ממורנינג" — בלי מסמך בקארדקום', !isset(last_call('LowProfile/Create')[1]['Document']));
cardcom_pays($e2['checkout']);
checkout_sync(checkout_load($e2['checkout']));
$p2 = db_one('SELECT * FROM payment WHERE checkout = ?', [$e2['checkout']]);
check('…והתשלום מסומן "חסר מסמך" עד שמורנינג תפיק', !payment_self_documented($p2) && !db_one('SELECT 1 FROM tax_document WHERE payment = ?', [$p2['id']]));

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
