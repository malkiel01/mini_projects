<?php
/**
 * בדיקות הכניסה דרך הפלטפורמה: קישור לפי sub ולפי מייל, המסך החד-פעמי,
 * חיבור חשבון ישן (ואיחוד), נעילה אחרי 5 ניסיונות, ומצב מנותק.
 *
 *   php claude-tasks/tests/login.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();   // session_regenerate_id נכשל אחרי פלט

$tmp = sys_get_temp_dir() . '/ctl-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('PLATFORM_CFG_FILE', $tmp . '/platform-config.json');
ini_set('session.save_path', $tmp);
ini_set('session.use_cookies', '0');

require_once __DIR__ . '/../lib/platform.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, string $needle = '') {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $needle === '' || str_contains($e->getMessage(), $needle), true); }
}
function asPlatform(string $sub, string $email, string $name = 'אורח'): array {
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => $sub, 'name' => $name, 'email' => $email, 'email_verified' => true]);
    return platformIdentity();
}

echo "\n— זהות —\n";
unset($_SERVER['PLATFORM_USER']);
check('בלי זהות', platformIdentity(), null);
$_SERVER['PLATFORM_USER'] = '{"name":"x"}';
check('בלי sub — אין זהות', platformIdentity(), null);

echo "\n— משתמש ראשון דרך הפלטפורמה —\n";
$a = asPlatform('sub-a', 'Dana@Example.com', 'דנה');
check('אין חשבון → המסך', platformResolve($a)['user'], null);
$r = platformCreateAccount($a);
check('הראשון — מנהל', $r['user']['role'], 'admin');
check('שם משתמש מהמייל', $r['user']['username'], 'dana');
check('מייל באותיות קטנות', $r['user']['email'], 'dana@example.com');
check('ההצעה לחשבון ישן נשארת', (int) $r['user']['link_offer'], 1);
check('בלי סיסמה', login('Dana', ''), null);
check('כניסה חוזרת לפי sub', (int) platformResolve($a)['user']['id'], (int) $r['user']['id']);

echo "\n— משתמש שני —\n";
$b = asPlatform('sub-b', 'dana@other.org', 'דנה ב');
$rb = platformCreateAccount($b);
check('השני — member', $rb['user']['role'], 'member');
check('שם תפוס → סיומת', $rb['user']['username'], 'dana-2');
check('session מוחלף כשמישהו אחר נכנס', (int) $_SESSION['uid'], (int) $rb['user']['id']);

echo "\n— קישור לפי מייל —\n";
$old = createUser('yossi', 'oldpassword', 'יוסי');
db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['yossi@example.com', $old['id']]);
$y = asPlatform('sub-y', 'YOSSI@example.com');
$ry = platformResolve($y);
check('נמצא לפי מייל', (int) $ry['user']['id'], $old['id']);
check('ולא מציע חשבון ישן', (int) $ry['user']['link_offer'], 0);
check('הסיסמה הישנה לא נמחקה', (bool) login('yossi', 'oldpassword'), true);

echo "\n— חשבון ישן בלי מייל: המסך החד-פעמי —\n";
$legacy = createUser('moshe', 'mosheword', 'משה');
$m = asPlatform('sub-m', 'moshe@new.com');
check('אין התאמה', platformResolve($m)['user'], null);
throws('סיסמה שגויה', fn() => platformLinkOld($m, 'moshe', 'wrong'), 'שגויים');
$rm = platformLinkOld($m, 'moshe', 'mosheword');
check('מקושר לחשבון הישן', (int) $rm['user']['id'], $legacy['id']);
check('המייל נשמר', $rm['user']['email'], 'moshe@new.com');
check('הסיסמה הישנה נשארת', (bool) login('moshe', 'mosheword'), true);
check('אחר כך — לפי sub', (int) platformResolve($m)['user']['id'], $legacy['id']);
$z = asPlatform('sub-z', 'z@z.com');
throws('חשבון מקושר לא מתקשר שוב', fn() => platformLinkOld($z, 'moshe', 'mosheword'), 'כבר מקושר');

echo "\n— חיבור מאוחר מההגדרות: איחוד —\n";
$legacy2 = createUser('rina', 'rinapassword', 'רינה');
$n = asPlatform('sub-n', 'rina@new.com');
$auto = platformCreateAccount($n)['user'];
db()->prepare('INSERT INTO tasks (title, created_by, created_at, updated_at) VALUES (?,?,?,?)')
    ->execute(['מטלה מהחשבון החדש', $auto['id'], time(), time()]);
$rn = platformLinkOld($n, 'rina', 'rinapassword');
check('נכנס לחשבון הישן', (int) $rn['user']['id'], $legacy2['id']);
check('המטלה עברה', (int) db()->query("SELECT created_by FROM tasks WHERE title = 'מטלה מהחשבון החדש'")->fetch()['created_by'], $legacy2['id']);
check('החשבון החדש נמחק', db()->query('SELECT COUNT(*) c FROM users WHERE id = ' . (int) $auto['id'])->fetch()['c'], 0);
check('ההצעה כבתה', (int) $rn['user']['link_offer'], 0);

echo "\n— נעילה אחרי 5 ניסיונות —\n";
$legacy3 = createUser('avi', 'avipassword', 'אבי');
$l = asPlatform('sub-l', 'l@l.com');
for ($i = 0; $i < 5; $i++) { try { platformLinkOld($l, 'avi', 'bad'); } catch (InvalidArgumentException $e) {} }
throws('נעול גם בסיסמה נכונה', fn() => platformLinkOld($l, 'avi', 'avipassword'), 'יותר מדי');
db()->exec("UPDATE login_locks SET locked_until = 1");
check('הנעילה פגה', (int) platformLinkOld($l, 'avi', 'avipassword')['user']['id'], $legacy3['id']);

echo "\n— מצב מחובר / מנותק —\n";
unset($_SERVER['PLATFORM_USER']);
$cfg = $tmp . '/sso.json';
configSet(['platform_config_url' => 'file://' . $cfg]);
function mode(string $json): array {
    global $cfg;
    file_put_contents($cfg, $json);
    @unlink(PLATFORM_CFG_FILE);
    return platformFetchConfig() ?? ['state' => 'unknown'];
}
check('מחוברת', mode('{"connected":true,"login":{"password":false}}')['state'], 'connected');
check('מנותקת, סיסמה פתוחה', mode('{"connected":false,"login":{"password":true}}'), ['state' => 'detached', 'password' => true]);
check('מנותקת, סיסמה כבויה', mode('{"connected":false,"login":{"password":false}}')['password'], false);
check('תשובה פגומה', mode('<html>'), ['state' => 'unknown']);
@unlink($cfg);
check('פלטפורמה לא זמינה, בלי מטמון → לא זמינה', str_contains((string) passwordLoginBlocked(), 'לא זמינה'), true);

asPlatform('sub-a', 'dana@example.com');
check('עם זהות — סיסמה חסומה', passwordLoginBlocked(), 'הכניסה היא דרך הכלים הדיגיטליים');

echo "\n— שם תצוגה ומחיקת חשבון נוסף —\n";
$me = db()->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
check('שם תצוגה מתעדכן', setDisplayName($me, '  מלכיאל  '), 'מלכיאל');
throws('שם ריק — נדחה', fn () => setDisplayName($me, '   '));
$extra = createUser('Malkiel2', 'pass-12345', 'Malkiel');
db()->prepare('INSERT INTO tasks (title, created_by, created_at, updated_at) VALUES (?,?,?,?)')->execute(['של הנוסף', $extra['id'], 1, 1]);
$tid = (int) db()->lastInsertId();
$member = ['id' => $extra['id'], 'role' => 'member', 'username' => 'Malkiel2'];
throws('לא מנהל — לא מוחק', fn () => adminRemoveUser($member, (int) $me['id']));
throws('לא את עצמך', fn () => adminRemoveUser($me, (int) $me['id']));
$linked = db()->query("SELECT id FROM users WHERE platform_sub <> '' AND id <> {$me['id']} LIMIT 1")->fetch();
check('יש חשבון מקושר אחר לבדיקה', (bool) $linked, true);
if ($linked) throws('חשבון מקושר לכלים הדיגיטליים — לא נמחק', fn () => adminRemoveUser($me, (int) $linked['id']));
adminRemoveUser($me, (int) $extra['id']);
check('החשבון נמחק', db()->query("SELECT COUNT(*) c FROM users WHERE id = {$extra['id']}")->fetch()['c'], 0);
check('המטלה שלו עברה אליי', (int) db()->query("SELECT created_by FROM tasks WHERE id = $tid")->fetch()['created_by'], (int) $me['id']);

echo "\n$pass עברו, $fail נכשלו\n";
ob_end_flush();
exit($fail ? 1 : 0);
