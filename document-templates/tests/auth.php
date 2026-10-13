<?php
/**
 * בדיקות שלב 2: חשבונות. מסד ומיילים זמניים — לא נוגע בנתונים אמיתיים.
 * הרצה: php document-templates/tests/auth.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-auth-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/auth.php';
ensure_data_dir($tmp);

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}
function throws(string $name, callable $fn, ?int $status = null): void
{
    try { $fn(); check($name, false); }
    catch (ApiError $e) { check($name . " ({$e->getMessage()})", $status === null || $e->status === $status); }
}
function last_link(string $kind): string
{
    $lines = file(DT_MAIL_SINK, FILE_IGNORE_NEW_LINES);
    $m = json_decode(end($lines), true);
    preg_match("/#$kind=([0-9a-f]+)/", $m['body'], $mm);
    return $mm[1] ?? '';
}
function status_of(string $email): string
{
    $st = db()->prepare('SELECT status FROM users WHERE email = ?');
    $st->execute([$email]);
    return (string)$st->fetchColumn();
}
function next_ip(): void { $_SERVER['REMOTE_ADDR'] = '10.0.0.' . random_int(1, 250); }

// ── המפתח: הראשון שמאמת ──
next_ip();
auth_register(['name' => 'מלכיאל', 'email' => 'Dev@Example.com', 'password' => 'devpass123']);
check('הרשמה: unverified', status_of('dev@example.com') === 'unverified');
throws('לא נכנסים לפני אימות', fn() => auth_login(['email' => 'dev@example.com', 'password' => 'devpass123']), 401);
$r = auth_verify(['token' => last_link('verify')]);
check('הראשון שמאמת — פעיל ומפתח', $r['status'] === 'active');
throws('קישור אימות לא עובד פעמיים', fn() => auth_verify(['token' => last_link('verify')]), 410);
$devToken = auth_login(['email' => 'dev@example.com', 'password' => 'devpass123'])['token'];
$dev = current_session($devToken);
check('כניסה: יש session, ומפתח', $dev && (bool)$dev['is_developer']);

// ── משתמש רגיל ──
next_ip();
auth_register(['name' => 'נועה', 'email' => 'noa@example.com', 'password' => 'noapass123']);
$noaLink = last_link('verify');
// מישהו אחר מנסה להירשם עם אותה כתובת וסיסמה אחרת — אחרי נועה.
next_ip();
auth_register(['name' => 'תוקף', 'email' => 'noa@example.com', 'password' => 'evilpass123']);
$r = auth_verify(['token' => $noaLink]);
check('השני שמאמת — ממתין לאישור', $r['status'] === 'pending');
throws('ממתין — לא נכנס', fn() => auth_login(['email' => 'noa@example.com', 'password' => 'noapass123']), 403);
$noaId = (int)db()->query("SELECT id FROM users WHERE email = 'noa@example.com'")->fetchColumn();
dev_user_action(['user_id' => $noaId, 'op' => 'approve'], $dev);
check('הסיסמה של מי שלחץ על הקישור היא שנקבעה (לא של התוקף)',
    !empty(auth_login(['email' => 'noa@example.com', 'password' => 'noapass123'])['token']));
throws('סיסמת התוקף לא עובדת', fn() => auth_login(['email' => 'noa@example.com', 'password' => 'evilpass123']), 401);
$noaToken = auth_login(['email' => 'noa@example.com', 'password' => 'noapass123'])['token'];

// ── הגבלת ניסיונות ──
next_ip();
for ($i = 0; $i < LOGIN_MAX_FAILS; $i++) {
    try { auth_login(['email' => 'noa@example.com', 'password' => 'wrong']); } catch (ApiError) {}
}
throws('אחרי 5 כשלים — נעול', fn() => auth_login(['email' => 'noa@example.com', 'password' => 'noapass123']), 429);
db()->exec('DELETE FROM login_attempts');

// ── המפתח: רשימה, ניתוק, חסימה ──
$list = dev_users()['users'];
check('רשימת המפתח: 2 חשבונות', count($list) === 2);
check('רשימת המפתח: אין שדות תוכן', !array_diff(array_keys($list[0]),
    ['id', 'email', 'display_name', 'is_developer', 'status', 'created_at', 'last_login_at', 'last_seen_at', 'devices', 'online']));
throws('המפתח לא חוסם את עצמו', fn() => dev_user_action(['user_id' => $dev['id'], 'op' => 'block'], $dev), 409);
dev_user_action(['user_id' => $noaId, 'op' => 'logout'], $dev);
check('ניתוק: ה-session של נועה בוטל', current_session($noaToken) === null);
dev_user_action(['user_id' => $noaId, 'op' => 'block'], $dev);
throws('חסומה — לא נכנסת', fn() => auth_login(['email' => 'noa@example.com', 'password' => 'noapass123']), 403);
throws('אי אפשר לאשר חסומה', fn() => dev_user_action(['user_id' => $noaId, 'op' => 'approve'], $dev), 409);
dev_user_action(['user_id' => $noaId, 'op' => 'unblock'], $dev);

// ── איפוס סיסמה ──
$noaToken = auth_login(['email' => 'noa@example.com', 'password' => 'noapass123'])['token'];
next_ip();
$r = auth_forgot(['email' => 'nobody@example.com']);
$r2 = auth_forgot(['email' => 'noa@example.com']);
check('שכחתי סיסמה: אותה תשובה לכתובת רשומה ולא רשומה', $r === $r2);
$reset = last_link('reset');
auth_reset(['token' => $reset, 'password' => 'newpass456']);
check('איפוס: כל המכשירים נותקו', current_session($noaToken) === null);
check('איפוס: הסיסמה החדשה עובדת', !empty(auth_login(['email' => 'noa@example.com', 'password' => 'newpass456'])['token']));
throws('איפוס: הקישור לשימוש אחד', fn() => auth_reset(['token' => $reset, 'password' => 'again12345']), 410);
dev_user_action(['user_id' => $noaId, 'op' => 'reset'], $dev);
check('המפתח שולח איפוס — מגיע מייל', last_link('reset') !== $reset && last_link('reset') !== '');

// ── הרשמה: לא מגלה מי רשום ──
next_ip();
$a = auth_register(['name' => 'מישהו', 'email' => 'noa@example.com', 'password' => 'whatever12']);
$b = auth_register(['name' => 'מישהו', 'email' => 'new@example.com', 'password' => 'whatever12']);
check('הרשמה: אותה תשובה לכתובת קיימת וחדשה', $a === $b);
check('הרשמה לכתובת פעילה לא משנה אותה', status_of('noa@example.com') === 'active');

// ── מחיקה רכה ──
$newId = (int)db()->query("SELECT id FROM users WHERE email = 'new@example.com'")->fetchColumn();
dev_user_action(['user_id' => $newId, 'op' => 'reject'], $dev);
check('דחייה = מחיקה רכה (השורה נשארת)', status_of('new@example.com') === 'deleted');

throws('מייל לא תקין', fn() => valid_email('not-an-email'), 422);
throws('סיסמה קצרה', fn() => valid_password('short'), 422);

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
