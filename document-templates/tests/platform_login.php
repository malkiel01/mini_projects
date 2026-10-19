<?php
/**
 * בדיקות: כניסה דרך הכלים הדיגיטליים (lib/platform.php; platform/docs/auth.md, sso.md סעיף 0).
 * מנותקת — כמו קודם. מחוברת — רק PLATFORM_USER: קישור לפי sub ← לפי מייל ← מסך חיבור חד-פעמי
 * (חשבון ישן בסיסמה, או חשבון חדש פעיל). הרשמה, כניסה ו"שכחתי סיסמה" חסומות; הסיסמה הישנה לא נמחקת.
 * הרצה: php document-templates/tests/platform_login.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-plat-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/participants.php';
require __DIR__ . '/../lib/platform.php';
ensure_data_dir($tmp);
ini_set('error_log', "$tmp/error.log");   // רישום הקישורים — לא לפלט הבדיקה

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}
function throws(string $name, callable $fn, int $status): void
{
    try { $fn(); check($name, false); }
    catch (ApiError $e) { check($name . " ({$e->getMessage()})", $e->status === $status); }
}
function user(string $email, string $status = 'active', string $pass = 'oldpass123'): array
{
    db()->prepare('INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $email, $status, now_iso()]);
    return platform_user((int)db()->lastInsertId());
}
/** בקשה חדשה: הזהות מהשער, והעוגייה של הדפדפן. */
function as_platform(?array $id, ?string $cookie = null): void
{
    if ($id) $_SERVER['PLATFORM_USER'] = json_encode($id); else unset($_SERVER['PLATFORM_USER']);
    if ($cookie) $_COOKIE[DT_COOKIE] = $cookie; else unset($_COOKIE[DT_COOKIE]);
    $_SERVER['HTTP_X_DT_PLATFORM'] = '1';
}
function hash_of(int $id): string { return (string)db()->query("SELECT password_hash FROM users WHERE id = $id")->fetchColumn(); }

$dana = user('dana@x.com');
db()->exec('UPDATE users SET is_developer = 1 WHERE id = ' . (int)$dana['id']);   // יש כבר מפתח — כמו במערכת אמיתית
$old = user('old@x.com');
$pend = user('pend@x.com', 'pending');
$blk = user('blk@x.com', 'blocked');

// מנותקת — כמו קודם
check('מנותקת (ברירת מחדל): הכניסה בסיסמה עובדת', isset(auth_login(['email' => 'dana@x.com', 'password' => 'oldpass123'])['token']));
check('מנותקת: platform_sync לא נוגע', platform_sync()[1]['mode'] === 'detached');

// מחוברת
putenv('DT_PLATFORM=connected');
foreach (['login', 'register', 'forgot', 'reset', 'verify', 'me_password'] as $a) throws("מחוברת: $a חסום", fn() => platform_guard($a), 409);
platform_guard('fill_get');   // משתתף מבחוץ — לא נחסם
check('מחוברת: פעולות של משתתף מבחוץ — לא נחסמות', true);

as_platform(['sub' => '17', 'name' => 'דנה', 'email' => 'DANA@x.com']);
[$s, $info] = platform_sync();
check('לפי מייל (בלי תלות באותיות): נכנסת לחשבון הקיים, והוא מקושר', $s && (int)$s['id'] === (int)$dana['id'] && platform_user((int)$dana['id'])['platform_sub'] === '17');
check('הסיסמה הישנה לא נמחקה', password_verify('oldpass123', hash_of((int)$dana['id'])));
$tok = $_COOKIE[DT_COOKIE];
as_platform(['sub' => '17', 'email' => 'other@x.com'], $tok);
[$s2] = platform_sync();
check('לפי sub — גם כשהמייל בפלטפורמה השתנה; אותו session', $s2 && (int)$s2['id'] === (int)$dana['id'] && $_COOKIE[DT_COOKIE] === $tok);

// session של מישהו אחר באותו מכשיר — מוחלף
as_platform(['sub' => '30', 'email' => 'pend@x.com'], $tok);
[$s3] = platform_sync();
check('ממתין לאישור + הרשאה בפלטפורמה ← פעיל', $s3 && (int)$s3['id'] === (int)$pend['id'] && platform_user((int)$pend['id'])['status'] === 'active');
check('ה-session הקודם (של דנה) נמחק — הוחלף', current_session($tok) === null);

as_platform(['sub' => '31', 'email' => 'blk@x.com']);
[$s4, $i4] = platform_sync();
check('חשבון חסום באפליקציה — נשאר חסום', $s4 === null && !empty($i4['blocked']));

// יציאה מהפלטפורמה = יציאה מכאן
as_platform(['sub' => '17']);
[$sd] = platform_sync();
$dt = $_COOKIE[DT_COOKIE];
as_platform(null, $dt);
[$sx, $ix] = platform_sync();
check('בלי זהות (יצא מהפלטפורמה) — אין משתמש, וה-session נמחק', $sx === null && current_session($dt) === null && str_contains($ix['login_url'], '?next='));

// מסך חיבור חד-פעמי
as_platform(['sub' => '40', 'name' => 'אבי', 'email' => 'avi@new.com']);
[$s5, $i5] = platform_sync();
check('אין חשבון באותו מייל — מסך חיבור (לא נפתח חשבון לבד)', $s5 === null && !empty($i5['link']) && $i5['email'] === 'avi@new.com');
unset($_SERVER['HTTP_X_DT_PLATFORM']);
throws('חיבור בלי הכותרת של האפליקציה — נדחה', fn() => platform_new([]), 403);
$_SERVER['HTTP_X_DT_PLATFORM'] = '1';
for ($i = 0; $i < LOGIN_MAX_FAILS; $i++) {
    try { platform_link(null, ['email' => 'old@x.com', 'password' => 'wrong']); } catch (ApiError) { /* */ }
}
throws('5 ניסיונות שגויים — נעילה של רבע שעה', fn() => platform_link(null, ['email' => 'old@x.com', 'password' => 'oldpass123']), 429);
db()->exec('DELETE FROM login_attempts');
throws('חשבון שכבר מקושר — לא ניתן לקשר שוב', fn() => platform_link(null, ['email' => 'dana@x.com', 'password' => 'oldpass123']), 409);
platform_link(null, ['email' => 'old@x.com', 'password' => 'oldpass123']);
[$s6] = platform_sync();
check('"יש לי כבר חשבון": החשבון הישן מקושר ל-sub, ונכנסים אליו', $s6 && (int)$s6['id'] === (int)$old['id']);
check('…והסיסמה הישנה נשארה (רק חסומה)', password_verify('oldpass123', hash_of((int)$old['id'])));

as_platform(['sub' => '50', 'name' => 'רותי', 'email' => 'ruti@new.com']);
platform_new([]);
[$s7] = platform_sync();
check('"אין לי חשבון": חשבון חדש, פעיל, בלי אישור', $s7 && $s7['email'] === 'ruti@new.com' && $s7['status'] === 'active' && !$s7['is_developer']);
throws('ושוב — כבר יש חשבון', fn() => platform_new([]), 409);

// מתפריט המשתמש: חשבון שנפתח עכשיו (ריק) ← חשבון ישן
$legacy = user('legacy@x.com');
check('חשבון חדש וריק — אפשר עוד לחבר חשבון ישן', platform_can_relink($s7));
platform_link($s7, ['email' => 'legacy@x.com', 'password' => 'oldpass123']);
as_platform(['sub' => '50', 'email' => 'ruti@new.com'], $_COOKIE[DT_COOKIE]);
[$s8] = platform_sync();
check('אחרי חיבור מהתפריט — נכנסים לחשבון הישן; החדש הריק נסגר', $s8 && (int)$s8['id'] === (int)$legacy['id'] && platform_user((int)$s7['id'])['status'] === 'deleted');
check('חשבון ישן (לא נפתח דרך הפלטפורמה) — לא מוחלף מהתפריט', !platform_can_relink($s8));

// בלי מייל בפלטפורמה
as_platform(['sub' => '60', 'name' => 'בלי מייל', 'email' => null]);
platform_new([]);
[$s9] = platform_sync();
check('זהות בלי מייל — חשבון חדש בכתובת פנימית', $s9 && str_ends_with($s9['email'], '@users.invalid'));

// הפלטפורמה לא זמינה
putenv('DT_PLATFORM=unavailable');
unset($_SERVER['PLATFORM_USER']);
throws('לא זמינה — "הכניסה לא זמינה כרגע"', fn() => platform_guard('login'), 503);

// מנותקת שוב — הסיסמה הישנה עובדת
putenv('DT_PLATFORM=detached');
check('מנותקת שוב — הכניסה בסיסמה הישנה חוזרת לעבוד', isset(auth_login(['email' => 'old@x.com', 'password' => 'oldpass123'])['token']));

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
