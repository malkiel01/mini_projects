<?php
/**
 * בדיקת שכבת ההגדרות על מסד זמני.
 * הרצה: php recipes-app/tools/settings-check.php
 *
 * מוכיחה את שרשרת הפתרון (דריסה → ציבורי → ברירת מחדל), את ההפרדה בין
 * מנהל למפתח, ואת הבאג שנתפס: כתיבה שאינה נראית בקריאה שאחריה.
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-scheck-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/media.php';

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
$MB = 1048576;

$dev  = createUser('malkiel', 'd@x.com', 'sod12345', 'מלכיאל');   // הראשון = מפתח
$mali = createUser('mali', 'm@x.com', 'sod12345', 'מלי'); verifyEmail($mali['token']);
$devU  = ['id' => $dev['id'],  'role' => 'admin'];
$maliU = ['id' => $mali['id'], 'role' => 'user'];

echo "\n1. ברירות מחדל — המסד ריק, הקוד עונה\n";
check('סרטון 20MB', effectiveLimit($maliU, 'video_max_bytes'), 20 * $MB);
check('מקצב 200MB', effectiveLimit($maliU, 'quota_bytes'), 200 * $MB);
check('תמונה 5MB',  effectiveLimit($maliU, 'image_max_bytes'), 5 * $MB);

echo "\n2. מי מפתח\n";
check('הראשון הוא המפתח', isDeveloper($devU), true);
check('משתמש רגיל אינו', isDeveloper($maliU), false);
db()->exec("UPDATE users SET role='admin' WHERE id={$mali['id']}");
check('admin שני אינו מפתח — "מנהל" ו"מפתח" הם שני דברים', isDeveloper(['id' => $mali['id'], 'role' => 'admin']), false);
db()->exec("UPDATE users SET role='user' WHERE id={$mali['id']}");

echo "\n3. הגדרה ציבורית — והבאג של המטמון\n";
setAppSetting('video_max_bytes', 50 * $MB, $devU);
check('כתיבה נראית בקריאה שאחריה, באותה בקשה', effectiveLimit($maliU, 'video_max_bytes'), 50 * $MB);
check('וגם למפתח עצמו', effectiveLimit($devU, 'video_max_bytes'), 50 * $MB);
expectError('משתמש רגיל אינו כותב', fn() => setAppSetting('video_max_bytes', 30 * $GLOBALS['MB'], $GLOBALS['maliU']), 'מפתח');
expectError('מתחת למינימום נדחה', fn() => setAppSetting('video_max_bytes', 100, $GLOBALS['devU']), 'בין');
expectError('מפתח לא מוכר נדחה', fn() => setAppSetting('hack', 1, $GLOBALS['devU']), 'לא מוכרת');

echo "\n4. דריסה אישית\n";
setUserLimit((int) $mali['id'], 'video_max_bytes', 100 * $MB, $devU);
check('למלי 100MB', effectiveLimit($maliU, 'video_max_bytes'), 100 * $MB);
check('למפתח עדיין הציבורי 50MB', effectiveLimit($devU, 'video_max_bytes'), 50 * $MB);
setUserLimit((int) $mali['id'], 'video_max_bytes', null, $devU);
check('null מחזיר לציבורי', effectiveLimit($maliU, 'video_max_bytes'), 50 * $MB);
expectError('משתמש רגיל אינו דורס', fn() => setUserLimit(1, 'video_max_bytes', 5 * $GLOBALS['MB'], $GLOBALS['maliU']), 'מפתח');
expectError('משתמש שאינו קיים', fn() => setUserLimit(999, 'video_max_bytes', 5 * $GLOBALS['MB'], $GLOBALS['devU']), 'אינו קיים');

echo "\n5. המדיה מכבדת את ההגדרה — לא את הקבוע הישן\n";
// 12MB: מעל רצפת הסבירות (10MB) ורחוק מברירת המחדל (200MB), כדי שיהיה
// ברור שהערך הגיע מהדריסה ולא מהקבוע הישן.
setUserLimit((int) $mali['id'], 'quota_bytes', 12 * $MB, $devU);
$lim = mediaLimits($maliU);
check('mediaLimits מדווח את המקצב האישי (12MB)', $lim['quota'], 12 * $MB);
check('ו-free נגזר ממנו', $lim['free'], 12 * $MB);
expectError('מקצב מתחת לרצפה נדחה — גם למשתמש בודד', fn() => setUserLimit((int) $GLOBALS['mali']['id'], 'quota_bytes', 1 * $GLOBALS['MB'], $GLOBALS['devU']), 'בין');

echo "\n6. ניהול משתמשים\n";
$users = listUsers($devU);
check('שני משתמשים', count($users), 2);
$m = array_values(array_filter($users, fn($u) => $u['username'] === 'mali'))[0];
check('הדריסה של מלי מוצגת', $m['limit_quota'], 12 * $MB);
check('סרטון: null = יורש, והמופיע בפועל הוא הציבורי', [$m['limit_video'], $m['effective_video']], [null, 50 * $MB]);
check('המפתח מסומן', array_values(array_filter($users, fn($u) => $u['username'] === 'malkiel'))[0]['is_developer'], true);
echo "\n6ב. ריפוי: מערכת בלי מנהל — הוותיק הופך למנהל ולמפתח\n";
db()->exec("UPDATE users SET role = 'user'");
check('לפני: אין מפתח', isDeveloper(['id' => $dev['id'], 'role' => 'user']), false);
migrate(db());
check('אחרי migrate: הראשון שוב מנהל', db()->query("SELECT role FROM users WHERE id={$dev['id']}")->fetchColumn(), 'admin');
check('והוא המפתח', isDeveloper($devU), true);
check('השני נשאר משתמש', db()->query("SELECT role FROM users WHERE id={$mali['id']}")->fetchColumn(), 'user');
migrate(db());
check('migrate נוסף אינו משנה דבר', (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(), 1);

echo "\n7. שליחה חוזרת בידי המפתח\n";
$pending = createUser('pending', 'p@x.com', 'sod12345', 'ממתין');
$pRow = fn() => db()->query("SELECT last_mail_at, last_mail_ok FROM users WHERE username='pending'")->fetch();
db()->exec("UPDATE users SET last_mail_at = NULL, last_mail_ok = NULL WHERE username='pending'");
// בלי MTA בסביבת הבדיקה — ראה auth-check. מוכיחים רישום, לא הצלחה.
$sent = resendVerificationFor((int) $pending['id'], $devU);
check('המפתח שולח שוב — בלי קירור (bool)', is_bool($sent), true);
check('והתוצאה נרשמה', (int) $pRow()['last_mail_ok'], (int) $sent);
$p = array_values(array_filter(listUsers($devU), fn($u) => $u['username'] === 'pending'))[0];
check('הרשימה חושפת את מצב הדוא״ל', [$p['last_mail_ok'], $p['last_mail_at'] !== null], [$sent, true]);
expectError('למאומת אין מה לשלוח', fn() => resendVerificationFor((int) $GLOBALS['mali']['id'], $GLOBALS['devU']), 'כבר מאומת');
expectError('משתמש שאינו קיים', fn() => resendVerificationFor(9999, $GLOBALS['devU']), 'אינו קיים');
expectError('משתמש רגיל אינו רשאי', fn() => resendVerificationFor((int) $GLOBALS['pending']['id'], $GLOBALS['maliU']), 'מפתח');

setUserBlocked((int) $mali['id'], true, $devU);
check('חסימה', (int) db()->query("SELECT blocked FROM users WHERE username='mali'")->fetchColumn(), 1);
expectError('המפתח אינו חוסם את עצמו', fn() => setUserBlocked((int) $GLOBALS['dev']['id'], true, $GLOBALS['devU']), 'המפתח');
expectError('משתמש רגיל אינו רואה רשימה', fn() => listUsers($GLOBALS['maliU']), 'מפתח');

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
