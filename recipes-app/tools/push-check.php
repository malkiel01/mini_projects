<?php
/**
 * בדיקת התראות לטלפון (lib/push.php) — על מסד זמני, בלי לשלוח באמת (שולח מדומה).
 * הרצה: php recipes-app/tools/push-check.php
 *
 * מוכיחה: מפתח VAPID נוצר פעם אחת; JWT חתום ב-ES256 שעובר אימות, עם aud של השירות;
 * רק שירותי התראות מוכרים (לא כל כתובת); עד 10 מכשירים; צלצול רק כשיש פעילות חדשה, ופעם
 * אחת; מה שהיה לפני ההרשמה — לא; push-pending מחזיר ומסמן; 410 מוחק מכשיר, 5 כשלונות — גם.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-push-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/push.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' . var_export($got, true) .
         ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
function expectError(string $label, callable $fn, string $needle = ''): void {
    global $fail;
    try { $fn(); echo "  \u{274C} $label: לא נזרקה שגיאה\n"; $fail[] = $label; }
    catch (AppError $e) {
        $ok = $needle === '' || str_contains($e->getMessage(), $needle);
        echo ($ok ? "  \u{2705} " : "  \u{274C} ") . "$label: \"{$e->getMessage()}\"\n";
        if (!$ok) $fail[] = $label;
    }
}
$mk = function (string $u, string $name) {
    $r = createUser($u, "$u@x.com", 'sod12345', $name);
    if (!empty($r['token'])) verifyEmail($r['token']);
    return ['id' => $r['id'], 'role' => 'user', 'username' => $u, 'display_name' => $name];
};
$dana = $mk('dana', 'דנה'); $noa = $mk('noa', 'נועה'); $dan = $mk('dan', 'דן');
$FCM = 'https://fcm.googleapis.com/fcm/send/';

echo "\n1. מפתח וחתימה\n";
$k = pushKeys();
$pub = b64uDecode($k['public']);
check('מפתח ציבורי: נקודה לא דחוסה (65 בתים, 0x04)', [strlen($pub), ord($pub[0])], [65, 4]);
check('נוצר פעם אחת — אותו מפתח בקריאה שנייה', pushKeys()['public'], $k['public']);
$jwt = pushJwt($FCM . 'abc', $k['private'], 1_700_000_000);
[$h, $b, $sig] = explode('.', $jwt);
check('JWT: ES256, ו-aud של השירות', [json_decode(b64uDecode($h), true)['alg'], json_decode(b64uDecode($b), true)['aud']], ['ES256', 'https://fcm.googleapis.com']);
$raw = b64uDecode($sig);
$int = fn(string $x) => (ord(ltrim($x, "\0")[0] ?? "\0") > 127 ? "\0" : '') . ltrim($x, "\0");
$r = $int(substr($raw, 0, 32)); $s = $int(substr($raw, 32));
$der = "\x30" . chr(4 + strlen($r) + strlen($s)) . "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
$pem = openssl_pkey_get_details(openssl_pkey_get_private($k['private']))['key'];
check('החתימה (64 בתים) עוברת אימות במפתח הציבורי', [strlen($raw), openssl_verify("$h.$b", $der, $pem, OPENSSL_ALGO_SHA256)], [64, 1]);

echo "\n2. מכשירים\n";
check('שירותים מוכרים — כן; כל כתובת אחרת — לא',
      [pushEndpointOk($FCM . 'x'), pushEndpointOk('https://abc.notify.windows.com/w/?token=1'), pushEndpointOk('http://fcm.googleapis.com/x'),
       pushEndpointOk('https://evil.com/x'), pushEndpointOk('https://fcm.googleapis.com.evil.com/x'), pushEndpointOk('https://127.0.0.1/x')],
      [true, true, false, false, false, false]);
expectError('הרשמה לכתובת לא מוכרת — נדחית', fn() => pushSubscribe($GLOBALS['noa'], ['endpoint' => 'https://evil.com/x']), 'אינו נתמך');
for ($i = 0; $i < 12; $i++) pushSubscribe($noa, ['endpoint' => $FCM . "noa$i"]);
$subs = fn(array $u) => array_column(db()->query("SELECT endpoint FROM push_subs WHERE user_id = {$u['id']} ORDER BY id")->fetchAll(), 'endpoint');
check('עד 10 מכשירים — הישנים יוצאים', [count($subs($noa)), $subs($noa)[0]], [10, $FCM . 'noa2']);
pushSubscribe($dan, ['endpoint' => $FCM . 'noa11']);
check('אותו מכשיר נרשם בחשבון אחר — עובר אליו', [in_array($FCM . 'noa11', $subs($noa)), $subs($dan)], [false, [$FCM . 'noa11']]);
pushUnsubscribe($dan, $FCM . 'noa11');
foreach ($subs($noa) as $e) if ($e !== $FCM . 'noa10') pushUnsubscribe($noa, $e);
check('ביטול', $subs($noa), [$FCM . 'noa10']);

echo "\n3. צלצול ומסירה\n";
$sent = [];
$send = function (string $endpoint) use (&$sent) { $sent[] = $endpoint; return 201; };
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$old = saveRecipe(['title' => 'שותף לפני ההרשמה', 'visibility' => 'private', 'sections' => $sec], $dana);
db()->exec("UPDATE push_state SET pinged_at = '2100-01-01T00:00:00Z', delivered_at = '2100-01-01T00:00:00Z'");   // "עכשיו" — אחרי כל מה שקורה כאן
shareRecipe($old, [$noa['id']], $dana);
check('פעילות מלפני ההרשמה — לא מצלצלת', [pushFlush($send), $sent], [0, []]);
db()->exec("UPDATE push_state SET pinged_at = '2000-01-01T00:00:00Z', delivered_at = '2000-01-01T00:00:00Z'");
check('יש פעילות חדשה — צלצול לנועה', [pushFlush($send), $sent], [1, [$FCM . 'noa10']]);
check('ושוב — לא (כל דבר מצלצל פעם אחת)', pushFlush($send), 0);
$p = pushPending($noa);
check('push-pending: מי ומה, וקישור', [$p[0]['title'], $p[0]['body'], $p[0]['link']], ['דנה שיתף/ה איתך מתכון', 'שותף לפני ההרשמה', "#/r/$old"]);
check('ובפעם הבאה — כבר נמסר', pushPending($noa), []);

echo "\n4. מכשיר שנעלם\n";
db()->exec("UPDATE push_state SET pinged_at = '2000-01-01T00:00:00Z'");
pushFlush(fn() => 410);
check('410 — המכשיר נמחק', $subs($noa), []);
pushSubscribe($noa, ['endpoint' => $FCM . 'flaky']);
for ($i = 0; $i < 6; $i++) { db()->exec("UPDATE push_state SET pinged_at = '2000-01-01T00:00:00Z'"); pushFlush(fn() => 500); }
check('5 כשלונות ברצף — נמחק', $subs($noa), []);
pushSubscribe($noa, ['endpoint' => $FCM . 'ok']);
db()->exec("UPDATE users SET blocked = 1 WHERE id = {$noa['id']}");
db()->exec("UPDATE push_state SET pinged_at = '2000-01-01T00:00:00Z'");
$sent = [];
check('משתמש חסום — לא מצלצלים', [pushFlush($send), $sent], [0, []]);

echo "\n";
@array_map('unlink', glob("$tmp/*") ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
