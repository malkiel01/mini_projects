<?php
/**
 * בדיקת הכניסה דרך הפלטפורמה (lib/platform.php): קישור לפי sub ולפי מייל, המסך החד-פעמי,
 * חשבון חדש, חסימת הכניסה בסיסמה כשמחוברת, ומצב מנותק.
 * הרצה: php org-budget-proposals/tests/platform.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
define('OB_PLATFORM_CONFIG', $tmp . '/config.json');   // "הפלטפורמה" — קובץ שהבדיקה כותבת
$_SERVER['REMOTE_ADDR'] = '10.0.0.3';
$_SERVER['CONTENT_TYPE'] = 'application/json';

require __DIR__ . '/../lib/dev.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✕ ') . $what . "\n";
    if (!$ok) $fails++;
}
function expect_error(callable $fn, int $status, string $what): void
{
    try { $fn(); check(false, "$what (לא נזרקה שגיאה)"); }
    catch (ApiError $e) { check($e->status === $status, "$what → {$e->status} {$e->getMessage()}"); }
}
/** בקשה חדשה: זהות מהשער (או בלי), ועוגייה. */
function req(?array $id, ?string $cookie = null): array
{
    if ($id) $_SERVER['PLATFORM_USER'] = json_encode($id + ['email_verified' => true], JSON_UNESCAPED_UNICODE);
    else unset($_SERVER['PLATFORM_USER']);
    if ($cookie) $_COOKIE[OB_COOKIE] = $cookie; else unset($_COOKIE[OB_COOKIE]);
    return resolve_session();
}
function config(?bool $connected): void
{
    @unlink(OB_DATA_DIR . '/platform-config.json');
    if ($connected === null) @unlink(OB_PLATFORM_CONFIG);
    else file_put_contents(OB_PLATFORM_CONFIG, json_encode(['app' => 'org-budget-proposals', 'connected' => $connected]));
}
function make_user(string $email, string $pass, string $status = 'active', int $dev = 0): int
{
    db()->prepare('INSERT INTO users (email, password_hash, display_name, is_developer, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), 'ישן ' . $email, $dev, $status, now_iso()]);
    return (int)db()->lastInsertId();
}

$devId = make_user('dev@x.co', 'devpass123', 'active', 1);
$oldId = make_user('old@other.co', 'oldpass123', 'pending');

echo "קישור לפי מייל\n";
[$s, $p] = req(['sub' => '1', 'name' => 'המפתח', 'email' => 'DEV@x.co']);
check($s && (int)$s['id'] === $devId && $p['state'] === 'ok', 'מייל זהה → נכנס לחשבון הקיים (בלי תלות באותיות)');
check(user_by('id', (string)$devId)['platform_sub'] === '1', 'החשבון מקושר ל-sub');
check(!$p['can_link'], 'חשבון ישן — אין "חיבור חשבון ישן" בתפריט');
$cookie = $_COOKIE[OB_COOKIE];
[$s2] = req(['sub' => '1', 'name' => 'המפתח', 'email' => 'dev@x.co'], $cookie);
check($s2 && $s2['token_hash'] === $s['token_hash'], 'בקשה הבאה — אותו session');

echo "החלפת משתמש באותו דפדפן\n";
[$s3, $p3] = req(['sub' => '2', 'name' => 'דנה לוי', 'email' => 'dana@x.co'], $cookie);
check($s3 === null && $p3['state'] === 'link', 'זהות אחרת, אין חשבון → המסך החד-פעמי, בלי ה-session של הקודם');
check(current_session($cookie) === null, 'ה-session של הקודם נמחק');

echo "מייל לא מאומת בפלטפורמה — לא מאחדים\n";
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '9', 'name' => 'x', 'email' => 'old@other.co', 'email_verified' => false]);
check(resolve_session()[1]['state'] === 'link', 'email_verified=false → המסך החד-פעמי');

echo "המסך החד-פעמי: יש לי כבר חשבון\n";
req(['sub' => '3', 'name' => 'יוסי', 'email' => 'yossi@new.co']);
for ($i = 0; $i < 4; $i++) {
    try { platform_link(['email' => 'old@other.co', 'password' => 'wrong']); } catch (ApiError) {}
}
expect_error(fn() => platform_link(['email' => 'old@other.co', 'password' => 'wrong']), 401, 'סיסמה שגויה');
expect_error(fn() => platform_link(['email' => 'old@other.co', 'password' => 'oldpass123']), 429, 'אחרי 5 ניסיונות — נעילה');
db()->exec('DELETE FROM login_attempts');
platform_link(['email' => 'old@other.co', 'password' => 'oldpass123']);
$old = user_by('id', (string)$oldId);
check($old['platform_sub'] === '3' && $old['status'] === 'active', 'החשבון הישן מקושר, וממתין → פעיל (ההרשאה היא האישור)');
check(password_verify('oldpass123', $old['password_hash']), 'הסיסמה הישנה לא נמחקה');
[$s4] = req(['sub' => '3', 'name' => 'יוסי', 'email' => 'yossi@new.co']);
check($s4 && (int)$s4['id'] === $oldId, 'נכנס לחשבון הישן');
req(['sub' => '2', 'name' => 'דנה לוי', 'email' => 'dana@x.co']);
expect_error(fn() => platform_link(['email' => 'old@other.co', 'password' => 'oldpass123']), 409, 'חשבון שכבר מקושר — לא מקשרים שוב');

echo "המסך החד-פעמי: אין לי חשבון\n";
platform_new();
[$s5, $p5] = req(['sub' => '2', 'name' => 'דנה לוי', 'email' => 'dana@x.co']);
check($s5 && $s5['email'] === 'dana@x.co' && $s5['status'] === 'active' && !$s5['is_developer'], 'חשבון חדש: פעיל, משתמש רגיל');
check($p5['can_link'], 'ההצעה נשארת בתפריט');
$danaId = (int)$s5['id'];
$dana2 = make_user('dana.old@x.co', 'danapass1');
platform_link(['email' => 'dana.old@x.co', 'password' => 'danapass1']);
[$s6] = req(['sub' => '2', 'name' => 'דנה לוי', 'email' => 'dana@x.co']);
check($s6 && (int)$s6['id'] === $dana2, 'מהתפריט: החשבון הישן מחליף את החדש');
check(user_by('id', (string)$danaId)['platform_sub'] === null, 'החשבון שנפתח לבד — מנותק, לא נמחק');

echo "חשבון חסום באפליקציה\n";
db()->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$oldId]);
[$s7, $p7] = req(['sub' => '3', 'name' => 'יוסי', 'email' => 'yossi@new.co']);
check($s7 === null && $p7['state'] === 'blocked', 'חסום → אין כניסה');

echo "בלי מפתח — החשבון הראשון הוא המפתח (כמו בהרשמה)\n";
db()->exec('UPDATE users SET is_developer = 0');
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '4', 'name' => '', 'email' => null, 'email_verified' => false]);
platform_new();
$u4 = user_by('platform_sub', '4');
check((bool)$u4['is_developer'] && str_ends_with($u4['email'], '@users.invalid') && $u4['display_name'] === 'משתמש', 'בלי מייל ושם — כתובת פנימית, ומפתח');

echo "כניסה בסיסמה\n";
req(['sub' => '1', 'name' => 'המפתח', 'email' => 'dev@x.co']);
expect_error('require_password_login', 403, 'עם PLATFORM_USER — חסומה');
config(true);
[$s8, $p8] = req(null, $cookie);
check($s8 === null && $p8['state'] === 'login', 'מחוברת, בלי זהות (דף ציבורי) → אין משתמש');
expect_error('require_password_login', 403, 'מחוברת — חסומה');

$tok = (function () { $r = auth_login(['email' => 'dana.old@x.co', 'password' => 'danapass1']); return $r['token']; })();
config(false);
[$s9, $p9] = req(null, $tok);
check($s9 && (int)$s9['id'] === $dana2 && $p9['state'] === 'off', 'מנותקת → ה-session בסיסמה עובד כמו קודם');
require_password_login();
check(true, 'מנותקת — הכניסה בסיסמה פתוחה');
config(true);
[$s10] = req(null, $tok);
check($s10 === null && current_session($tok) === null, 'חזרה למחוברת — ה-session בסיסמה נמחק (יציאה מהפלטפורמה = יציאה מכאן)');

config(null);
[, $p11] = req(null);
check($p11['state'] === 'unavailable', 'הפלטפורמה לא ענתה אף פעם → "הכניסה לא זמינה"');
expect_error('require_password_login', 503, 'ובלי כניסה בסיסמה');
config(false);
req(null);
unlink(OB_PLATFORM_CONFIG);
touch(OB_DATA_DIR . '/platform-config.json', time() - 3600);
check(req(null)[1]['state'] === 'off', 'לא עונה, אבל ענתה פעם → התשובה האחרונה');

echo "בקשה שאינה JSON\n";
$_SERVER['CONTENT_TYPE'] = 'text/plain';
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '5', 'name' => 'x', 'email' => 'x@x.co', 'email_verified' => true]);
expect_error('platform_new', 415, 'platform_new בלי JSON');

echo $fails ? "\n✕ $fails נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
