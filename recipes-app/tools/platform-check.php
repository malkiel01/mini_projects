<?php
/**
 * הכניסה דרך הכלים הדיגיטליים (lib/platform.php) — על מסד זמני.
 * הרצה: php recipes-app/tools/platform-check.php
 *
 * מוכיחה את platform/docs/auth.md: קישור לפי sub ואז לפי דוא"ל מאומת, מסך החיבור החד-פעמי (חשבון ישן
 * פעם אחת, 5 ניסיונות → נעילה), חשבון חדש פעיל, הסיסמה הישנה לא נמחקת, ויציאה מהפלטפורמה סוגרת.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-platform-check-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' .
         var_export($got, true) . ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
function expectError(string $label, callable $fn, string $needle = ''): void {
    global $fail;
    try {
        $fn();
        echo "  \u{274C} $label: לא נזרקה שגיאה\n";
        $fail[] = $label;
    } catch (AppError $e) {
        $ok = $needle === '' || str_contains($e->getMessage(), $needle);
        echo ($ok ? "  \u{2705} " : "  \u{274C} ") . "$label: \"{$e->getMessage()}\"\n";
        if (!$ok) $fail[] = $label;
    }
}
function as_platform(?string $sub, string $email = '', bool $verified = true, string $name = ''): void {
    if ($sub === null) { unset($_SERVER['PLATFORM_USER']); return; }
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => $sub, 'name' => $name, 'email' => $email, 'email_verified' => $verified]);
}
function uidOf(string $username): int {
    $st = db()->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$username]);
    return (int) $st->fetchColumn();
}

// חשבונות ישנים, מלפני השער
createUser('owner', 'owner@example.com', 'sod12345');
createUser('dana', 'dana@example.com', 'sod12345');
db()->exec("UPDATE users SET email_verified = 1");
createUser('yossi', 'yossi-old@example.com', 'yossi1234');
db()->exec("UPDATE users SET email_verified = 1");

echo "\n1. בלי זהות מהשער — כמו קודם\n";
as_platform(null);
check('אין זהות', platformIdentity(), null);
check('כניסה בסיסמה עובדת (אפליקציה מנותקת)', login('dana', 'sod12345')['username'], 'dana');
check('currentUser מהסשן', currentUser()['username'], 'dana');
logout();

echo "\n2. איחוד סמוי לפי דוא\"ל מאומת\n";
as_platform('sub-dana', 'DANA@example.com', true, 'דנה');
check('נכנסת לחשבון הישן שלה', currentUser()['username'], 'dana');
check('והוא מקושר ל-sub', db()->query("SELECT platform_sub FROM users WHERE username='dana'")->fetchColumn(), 'sub-dana');
check('הסיסמה הישנה לא נמחקה', password_verify('sod12345', db()->query("SELECT password_hash FROM users WHERE username='dana'")->fetchColumn()), true);

echo "\n3. דוא\"ל שהפלטפורמה לא אימתה — לא מאחדים\n";
as_platform('sub-x', 'owner@example.com', false);
check('אין משתמש — מסך החיבור', currentUser(), null);
check('owner לא קושר', db()->query("SELECT platform_sub FROM users WHERE username='owner'")->fetchColumn(), null);

echo "\n4. מסך החיבור: \"יש לי כבר חשבון\" (דוא\"ל אחר)\n";
as_platform('sub-yossi', 'yossi@new.example', true, 'יוסי');
check('אין משתמש — מסך החיבור', currentUser(), null);
for ($i = 1; $i <= 4; $i++) expectError("ניסיון שגוי $i", fn() => platformLinkOld(platformIdentity(), 'yossi', 'wrong'), 'שגויים');
check('הניסיון הנכון אחרי 4 שגויים', platformLinkOld(platformIdentity(), 'yossi', 'yossi1234')['username'], 'yossi');
check('ועכשיו currentUser', currentUser()['username'], 'yossi');
check('הסיסמה הישנה נשארה', password_verify('yossi1234', db()->query("SELECT password_hash FROM users WHERE username='yossi'")->fetchColumn()), true);

echo "\n5. חשבון שכבר מקושר — לא ניתן לקשר שוב\n";
as_platform('sub-other', 'other@example.com');
expectError('dana מקושרת ל-sub אחר', fn() => platformLinkOld(platformIdentity(), 'dana', 'sod12345'), 'כבר מחובר');

echo "\n6. נעילה: 5 ניסיונות → רבע שעה\n";
as_platform('sub-attacker', 'attacker@example.com');
for ($i = 1; $i <= 4; $i++) expectError("ניסיון $i", fn() => platformLinkOld(platformIdentity(), 'owner', 'x'), 'שגויים');
expectError('החמישי נועל', fn() => platformLinkOld(platformIdentity(), 'owner', 'x'), 'רבע שעה');
expectError('גם הסיסמה הנכונה — נעול', fn() => platformLinkOld(platformIdentity(), 'owner', 'sod12345'), 'בעוד');

echo "\n7. \"אין לי חשבון\" — חשבון חדש, פעיל\n";
as_platform('sub-new', 'new.person@example.com', true, 'אדם חדש');
$u = platformCreateAccount(platformIdentity());
check('שם משתמש מהדוא"ל', $u['username'], 'new.person');
check('מאומת', (int) $u['email_verified'], 1);
check('שם התצוגה מהפלטפורמה', $u['display_name'], 'אדם חדש');
check('currentUser', currentUser()['username'], 'new.person');
check('פעם שנייה — אותו חשבון', platformCreateAccount(platformIdentity())['id'], $u['id']);
as_platform('sub-new2', 'new.person@other.example', true);
check('שם תפוס — מספר', platformCreateAccount(platformIdentity())['username'], 'new.person2');

echo "\n8. מהתפריט: החשבון הישן מקבל את ה-sub, החדש מתנתק\n";
createUser('oldie', 'oldie@example.com', 'oldie1234');
as_platform('sub-new', 'new.person@example.com');
check('לפני: החשבון החדש', currentUser()['username'], 'new.person');
platformLinkOld(platformIdentity(), 'oldie', 'oldie1234');
check('אחרי: החשבון הישן', currentUser()['username'], 'oldie');
check('החדש מנותק', db()->query("SELECT platform_sub FROM users WHERE username='new.person'")->fetchColumn(), null);
check('הישן מאומת (הסיסמה הוכיחה בעלות)', (int) db()->query("SELECT email_verified FROM users WHERE username='oldie'")->fetchColumn(), 1);

echo "\n9. החלפת משתמש בפלטפורמה — הסשן מתחלף\n";
as_platform('sub-dana', 'dana@example.com');
check('דנה', currentUser()['username'], 'dana');
as_platform('sub-yossi', 'yossi@new.example');
check('יוסי באותו סשן', currentUser()['username'], 'yossi');
check('הסשן עצמו התעדכן', $_SESSION['uid'], uidOf('yossi'));

echo "\n10. יציאה מהפלטפורמה — יציאה מהאפליקציה\n";
as_platform(null);
check('אין זהות — אין משתמש', currentUser(), null);
check('הסשן נמחק', $_SESSION['uid'] ?? null, null);

echo "\n11. חסום באפליקציה — חסום גם דרך הפלטפורמה\n";
db()->exec("UPDATE users SET blocked = 1 WHERE username = 'dana'");
as_platform('sub-dana', 'dana@example.com');
check('אין משתמש', currentUser(), null);
check('ולא נפתח חשבון חדש מעצמו', (int) db()->query("SELECT COUNT(*) FROM users WHERE platform_sub='sub-dana'")->fetchColumn(), 1);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media');
@rmdir($tmp);

echo "\n";
if ($fail) {
    echo "\u{274C} נכשלו " . count($fail) . " בדיקות: " . implode(', ', $fail) . "\n";
    exit(1);
}
echo "\u{2705} כל הבדיקות עברו\n";
