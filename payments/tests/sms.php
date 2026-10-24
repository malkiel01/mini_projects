<?php
/**
 * בדיקות המסרונים: php payments/tests/sms.php
 * מול אינפורו וטוויליו מדומים, לפי התיעוד הרשמי שלהם: מבנה הבקשה, הרשאה, מספרים, שמירה, ושליחה מהתור.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-sms-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("PAY_DATA_DIR=$tmp");
putenv('PAY_BASE_URL=https://mbe-plus.com/mini_projects/payments/');
putenv('PAY_DEV_USER=' . json_encode(['sub' => '1', 'name' => 'המנהל']));

require_once __DIR__ . '/../lib/pages.php';
require_once __DIR__ . '/../lib/jobs.php';

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
$GLOBALS['PAY_MAIL_HOOK'] = fn() => true;

$calls = [];
$GLOBALS['PAY_HTTP_HOOK'] = function (string $url, ?string $post, array $headers) use (&$calls) {
    $auth = '';
    foreach ($headers as $h) if (stripos($h, 'Authorization: Basic ') === 0) $auth = base64_decode(substr($h, 21));
    $calls[] = ['url' => $url, 'post' => $post, 'auth' => $auth, 'headers' => $headers];
    if ($url === 'https://capi.inforu.co.il/api/v2/SMS/SendSms') {
        if ($auth !== 'user1:tok1') return ['status' => 200, 'body' => json_encode(['StatusId' => -2, 'StatusDescription' => 'Bad user name or password'])];
        $b = json_decode((string)$post, true);
        $ok = isset($b['Data']['Message'], $b['Data']['Recipients'][0]['Phone'], $b['Data']['Settings']['Sender']);
        return ['status' => 200, 'body' => json_encode($ok ? ['StatusId' => 1, 'StatusDescription' => 'Success', 'Data' => ['Recipients' => 1]] : ['StatusId' => -6, 'StatusDescription' => 'RecipientsDataNotExists'])];
    }
    if (str_starts_with($url, 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json')) {
        parse_str((string)$post, $f);
        if ($auth !== 'AC123:sek') return ['status' => 401, 'body' => json_encode(['code' => 20003, 'message' => 'Authenticate'])];
        return ['status' => 201, 'body' => json_encode(['sid' => 'SM1', 'status' => 'queued', 'to' => $f['To']])];
    }
    return ['status' => 404, 'body' => ''];
};

$owner = current_tenant();
$m = merchant_save($owner, ['name' => 'עוסק', 'tax_status' => 'exempt']);
$app = client_create($owner, ['id' => 'app', 'name' => 'אפליקציה', 'merchant' => $m['id']]);
customer_upsert($app, 'test', ['external_id' => 'u:1', 'email' => 'u@example.com']);
$acc = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.external_id = 'u:1'");
db_run("UPDATE account SET phone = '054-123-4567' WHERE id = ?", [$acc['id']]);

section('מספרים');
check('מקומי לאינפורו, בינלאומי לטוויליו', sms_phone('054-123-4567', false) === '0541234567' && sms_phone('054-123-4567', true) === '+972541234567'
    && sms_phone('+972 54 1234567', false) === '0541234567' && sms_phone('+1 415 555 0100', true) === '+14155550100' && sms_phone('abc', true) === null);

section('בלי ספק');
notify($acc['id'], 'sms', 'renewal_sms', 'test1', '', 'המנוי מסתיים בעוד 21 יום.');
notifications_send();
check('בלי ספק — "אין ספק", לא נשלח', db_one("SELECT status FROM notification WHERE dedupe = 'test1'")['status'] === 'no_provider' && !$calls);

section('שמירה');
check('ספק לא מוכר — נדחה', throws(fn() => sms_save($owner, ['provider' => 'x']), 'bad_provider'));
check('חסר טוקן — נדחה', throws(fn() => sms_save($owner, ['provider' => 'inforu', 'username' => 'user1', 'sender' => 'MyBrand']), 'missing_credentials'));
check('שולח עם רווח — נדחה', throws(fn() => sms_save($owner, ['provider' => 'inforu', 'username' => 'user1', 'token' => 'tok1', 'sender' => 'My Brand']), 'bad_sender'));
$pub = sms_save($owner, ['provider' => 'inforu', 'username' => 'user1', 'token' => 'tok1', 'sender' => 'MyBrand']);
check('הטוקן לא חוזר למסך, והוא מוצפן במסד', $pub['fields']['token'] === true && !str_contains(json_encode($pub), 'tok1')
    && !str_contains((string)db_one("SELECT value FROM setting WHERE key = ?", ['sms:' . $owner['id']])['value'], 'tok1'));
check('הודעה שחיכתה לספק — חזרה לתור', db_one("SELECT status FROM notification WHERE dedupe = 'test1'")['status'] === 'pending');

section('אינפורו');
notifications_send();
$c = end($calls);
$b = json_decode($c['post'], true);
check('נשלח לפי התיעוד: כתובת, הרשאה, הודעה, נמען ושולח', $c['url'] === 'https://capi.inforu.co.il/api/v2/SMS/SendSms' && $c['auth'] === 'user1:tok1'
    && $b['Data']['Recipients'][0]['Phone'] === '0541234567' && $b['Data']['Settings']['Sender'] === 'MyBrand' && str_contains($b['Data']['Message'], '21 יום'));
check('בכל מסרון — דרכי הביטול (§14ט)', str_contains($b['Data']['Message'], 'ביטול המנוי'));
check('נרשם "נשלח"', db_one("SELECT status FROM notification WHERE dedupe = 'test1'")['status'] === 'sent');
sms_save($owner, ['provider' => 'inforu', 'username' => '', 'token' => 'wrong', 'sender' => '']);
$t = sms_test($owner, '0541234567');
check('קודים שגויים — הבדיקה מציגה את השגיאה שלהם', str_contains($t['check_error'], 'Bad user name'));
sms_save($owner, ['provider' => 'inforu', 'token' => 'tok1']);
check('בדיקה עוברת', sms_test($owner, '0541234567')['check_error'] === '');

section('טוויליו');
sms_save($owner, ['provider' => 'twilio', 'account_sid' => 'AC123', 'auth_token' => 'sek', 'from' => '+12025550100']);
notify($acc['id'], 'sms', 'renewal_sms', 'test2', '', 'בדיקה');
notifications_send();
$c = end($calls);
parse_str($c['post'], $f);
check('נשלח לפי התיעוד: חשבון בכתובת, הרשאה, טופס, ומספר בינלאומי', str_contains($c['url'], '/Accounts/AC123/Messages.json') && $c['auth'] === 'AC123:sek'
    && $f['To'] === '+972541234567' && $f['From'] === '+12025550100' && $f['Body'] !== '' && in_array('Content-Type: application/x-www-form-urlencoded', $c['headers'], true));
check('נרשם "נשלח"', db_one("SELECT status FROM notification WHERE dedupe = 'test2'")['status'] === 'sent');
sms_save($owner, ['provider' => '']);
check('כיבוי — אין ספק', sms_config($owner['id']) === null);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
