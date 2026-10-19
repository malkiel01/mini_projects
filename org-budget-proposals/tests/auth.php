<?php
/**
 * בדיקת שלב 1: הרשמה, אימות, אישור מפתח, כניסה, נעילה, איפוס, ופעולות המפתח.
 * הרצה: php org-budget-proposals/tests/auth.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

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
/** האסימון מהמייל האחרון שנשלח לכתובת. */
function last_token(string $to, string $kind): string
{
    $lines = array_reverse(file(OB_MAIL_SINK, FILE_IGNORE_NEW_LINES));
    foreach ($lines as $l) {
        $m = json_decode($l, true);
        if ($m['to'] === $to && preg_match("/#$kind=([0-9a-f]{48})/", $m['body'], $mm)) return $mm[1];
    }
    throw new RuntimeException("אין מייל $kind ל-$to");
}

echo "הרשמה ואימות\n";
auth_register(['email' => 'Dev@Example.com', 'password' => 'secret-123', 'name' => 'המפתח']);
$r = auth_verify(['token' => last_token('dev@example.com', 'verify')]);
check($r['status'] === 'active', 'החשבון הראשון פעיל מיד');
$dev = db()->query("SELECT * FROM users WHERE email = 'dev@example.com'")->fetch();
check((int)$dev['is_developer'] === 1, 'והוא המפתח');
expect_error(fn() => auth_verify(['token' => last_token('dev@example.com', 'verify')]), 410, 'קישור אימות לא עובד פעמיים');

auth_register(['email' => 'user@example.com', 'password' => 'secret-456', 'name' => 'גזבר']);
$r2 = auth_register(['email' => 'user@example.com', 'password' => 'secret-456', 'name' => 'גזבר']);
check(str_contains($r2['message'], 'שלחנו'), 'הרשמה כפולה — אותה תשובה (לא מגלה שהכתובת רשומה)');
expect_error(fn() => auth_login(['email' => 'user@example.com', 'password' => 'secret-456']), 401, 'לפני אימות — אין כניסה (הסיסמה נקבעת רק באימות)');
$r = auth_verify(['token' => last_token('user@example.com', 'verify')]);
check($r['status'] === 'pending', 'החשבון השני ממתין לאישור');
expect_error(fn() => auth_login(['email' => 'user@example.com', 'password' => 'secret-456']), 403, 'ממתין — אין כניסה');

expect_error(fn() => auth_register(['email' => 'bad', 'password' => 'secret-456', 'name' => 'x y']), 422, 'מייל לא תקין');
expect_error(fn() => auth_register(['email' => 'a@b.co', 'password' => 'short', 'name' => 'שם']), 422, 'סיסמה קצרה');

echo "פעולות המפתח\n";
$user = db()->query("SELECT * FROM users WHERE email = 'user@example.com'")->fetch();
$devSession = current_session(auth_login(['email' => 'dev@example.com', 'password' => 'secret-123'])['token']);
check($devSession && (bool)$devSession['is_developer'], 'המפתח נכנס');
expect_error(fn() => dev_user_action(['user_id' => $dev['id'], 'op' => 'block', 'reason' => 'ניסיון'], $devSession), 409, 'המפתח לא חוסם את עצמו');
dev_user_action(['user_id' => $user['id'], 'op' => 'approve'], $devSession);
$tok = auth_login(['email' => 'user@example.com', 'password' => 'secret-456'])['token'];
check(current_session($tok) !== null, 'אחרי אישור — נכנס');
expect_error(fn() => dev_user_action(['user_id' => $user['id'], 'op' => 'block'], $devSession), 422, 'חסימה בלי סיבה נדחית');
dev_user_action(['user_id' => $user['id'], 'op' => 'block', 'reason' => 'בדיקת חסימה'], $devSession);
check(current_session($tok) === null, 'חסימה מנתקת מיד');
expect_error(fn() => auth_login(['email' => 'user@example.com', 'password' => 'secret-456']), 403, 'חסום — אין כניסה');
dev_user_action(['user_id' => $user['id'], 'op' => 'unblock'], $devSession);
$audit = dev_audit_list()['audit'];
check(count($audit) === 3 && $audit[1]['reason'] === 'בדיקת חסימה', 'יומן האכיפה: 3 פעולות, עם הסיבה');
check(count(dev_users()['users']) === 2, 'רשימת המשתמשים');

echo "נעילה אחרי 5 כשלים\n";
for ($i = 0; $i < 5; $i++) {
    try { auth_login(['email' => 'user@example.com', 'password' => 'wrong-pass']); } catch (ApiError) {}
}
expect_error(fn() => auth_login(['email' => 'user@example.com', 'password' => 'secret-456']), 429, 'נעול גם עם הסיסמה הנכונה');
db()->exec('DELETE FROM login_attempts');

echo "איפוס סיסמה\n";
$tok = auth_login(['email' => 'user@example.com', 'password' => 'secret-456'])['token'];
auth_forgot(['email' => 'user@example.com']);
auth_reset(['token' => last_token('user@example.com', 'reset'), 'password' => 'new-secret-789']);
check(current_session($tok) === null, 'איפוס מנתק את כל המכשירים');
expect_error(fn() => auth_login(['email' => 'user@example.com', 'password' => 'secret-456']), 401, 'הסיסמה הישנה לא עובדת');
check(isset(auth_login(['email' => 'user@example.com', 'password' => 'new-secret-789'])['token']), 'הסיסמה החדשה עובדת');
$r = auth_forgot(['email' => 'nobody@example.com']);
check(str_contains($r['message'], 'אם הכתובת רשומה'), 'שכחתי סיסמה — לא מגלה אם הכתובת רשומה');

echo "החלפת סיסמה מההגדרות\n";
$a = auth_login(['email' => 'user@example.com', 'password' => 'new-secret-789'])['token'];
$b = auth_login(['email' => 'user@example.com', 'password' => 'new-secret-789'])['token'];
$s = current_session($a);
expect_error(fn() => me_password($s, ['current' => 'nope-nope', 'new' => 'another-1234']), 403, 'סיסמה נוכחית שגויה');
$r = me_password($s, ['current' => 'new-secret-789', 'new' => 'another-1234']);
check($r['other_sessions'] >= 1 && current_session($b) === null && current_session($a) !== null, 'מנתק את השאר, נשאר מחובר כאן');

echo "לוג המעקב\n";
dev_track($s, 'login', microtime(true), 401, new ApiError('מייל או סיסמה שגויים', 401));
$log = dev_log_list(['errors' => 1]);
check(count($log['rows']) === 1 && $log['rows'][0]['status'] === 401, 'שגיאה נרשמת ונמצאת בסינון "רק שגיאות"');
check(dev_health()['health']['data_protected'] === true, 'data/ מוגנת ב-.htaccess');

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
echo $fails ? "\n✕ $fails בדיקות נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
