<?php
/**
 * בדיקת db.php ו-auth.php על מסד זמני.
 * הרצה: php recipes-app/tools/auth-check.php
 *
 * הבדיקה מריצה את הקוד, ולא בודקת תחביר. היא מוכיחה את מה שהאפיון
 * מתחייב לו בסעיף 2, ובמיוחד את מה שקל לשבור בשקט: כניסה לפני אימות,
 * אסימון שנוצל פעמיים, ואסימון שנשמר כ-hash ולא כטקסט.
 */

declare(strict_types=1);

// מסד זמני — הבדיקה לא נוגעת בנתונים האמיתיים.
$tmp = sys_get_temp_dir() . '/recipes-check-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');

// בסביבת הבדיקה אין MTA, ולא אמור להיות. sendmail_path הוא PHP_INI_SYSTEM,
// ולכן ini_set כאן אינו משנה אותו: mail() תיכשל, וזה בסדר — הבדיקות
// מוכיחות שהתוצאה (כשל או הצלחה) **נרשמת**, לא שהיא הצלחה. הריצה שבה
// ה-MTA "מקבל" (דרך -d sendmail_path=/bin/true) היא ב-api-check.sh.
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

echo "\n1. יצירת חשבונות\n";
$admin = createUser('malkiel', 'a@example.com', 'sod12345', 'מלכיאל');
check('המשתמש הראשון נעשה admin', $admin['role'], 'admin');
check('והוא מאומת מראש — אחרת קיפאון כש-mail() לא פועל',
      (int) db()->query("SELECT email_verified FROM users WHERE username='malkiel'")->fetch()['email_verified'], 1);
check('הוא יכול להיכנס מיד', login('malkiel', 'sod12345')['role'], 'admin');
$user = createUser('mali', 'm@example.com', 'sod12345');
check('השני נעשה user', $user['role'], 'user');
check('שם התצוגה מתמלא משם המשתמש',
      db()->query("SELECT display_name FROM users WHERE username='mali'")->fetch()['display_name'],
      'mali');

echo "\n2. ולידציה\n";
expectError('שם משתמש קצר', fn() => createUser('ab', 'x@example.com', 'sod12345'), 'שם משתמש');
expectError('דוא"ל פסול',   fn() => createUser('okname', 'not-an-email', 'sod12345'), 'דוא"ל');
expectError('סיסמה קצרה',   fn() => createUser('okname', 'x@example.com', 'short'), '8 תווים');
expectError('שם תפוס',      fn() => createUser('mali', 'other@example.com', 'sod12345'), 'כבר בשימוש');
expectError('כתובת תפוסה',  fn() => createUser('another', 'm@example.com', 'sod12345'), 'כבר בשימוש');

echo "\n3. אימות דוא\"ל חוסם כניסה\n";
expectError('כניסה לפני אימות', fn() => login('mali', 'sod12345'), 'טרם אומת');
verifyEmail($user['token']);
check('אחרי אימות הכניסה עוברת', login('mali', 'sod12345')['username'], 'mali');
check('סיסמה שגויה מחזירה null', login('mali', 'wrong-password'), null);
check('משתמש שאינו קיים מחזיר null', login('nobody', 'sod12345'), null);

echo "\n4. האסימון נשמר כ-hash ולא כטקסט\n";
$stored = db()->query("SELECT token_hash FROM user_tokens ORDER BY id DESC LIMIT 1")->fetch();
check('הטקסט אינו במסד', $stored['token_hash'] === $user['token'], false);
check('ה-hash תואם', $stored['token_hash'], hash('sha256', $user['token']));
expectError('אסימון שנוצל', fn() => verifyEmail($GLOBALS['user']['token'] ?? ''), 'כבר נוצל');

echo "\n5. איפוס סיסמה\n";
requestPasswordReset('lo-kayam@example.com');   // לא אמור לזרוק
check('כתובת שאינה רשומה אינה מדליפה כלום', true, true);
$t = issueToken((int) $user['id'], 'reset_password', 1);
resetPassword($t, 'sisma-hadasha');
check('הסיסמה החדשה עובדת', login('mali', 'sisma-hadasha')['username'], 'mali');
check('הישנה כבר לא', login('mali', 'sod12345'), null);
expectError('אסימון איפוס שנוצל שוב', fn() => resetPassword($GLOBALS['t'], 'x12345678'), 'נוצל');
expectError('סיסמה חדשה קצרה', fn() => resetPassword(issueToken((int) $GLOBALS['user']['id'], 'reset_password', 1), 'abc'), '8 תווים');

echo "\n6. אסימון שני מבטל את הראשון\n";
$first  = issueToken((int) $user['id'], 'reset_password', 1);
$second = issueToken((int) $user['id'], 'reset_password', 1);
expectError('הראשון בוטל', fn() => resetPassword($GLOBALS['first'], 'x12345678'), 'נוצל');
resetPassword($second, 'x12345678');
check('השני עובד', login('mali', 'x12345678')['username'], 'mali');

echo "\n7. אסימון שפג תוקף\n";
$expired = issueToken((int) $user['id'], 'reset_password', 1);
db()->prepare('UPDATE user_tokens SET expires_at = ? WHERE token_hash = ?')
    ->execute(['2020-01-01T00:00:00Z', hash('sha256', $expired)]);
expectError('פג תוקף', fn() => resetPassword($GLOBALS['expired'], 'x12345678'), 'פג תוקף');

echo "\n8. זריעת הצירים הסגורים\n";
$axes = [];
foreach (db()->query("SELECT axis, COUNT(*) c FROM tags GROUP BY axis") as $row) {
    $axes[$row['axis']] = (int) $row['c'];
}
check('חמישה צירים', count($axes), 5);
check('נושא — 11 ערכים', $axes['topic'], 11);
check('כשרות — 3 ערכים', $axes['kosher'], 3);
db()->exec("DELETE FROM tags WHERE axis='kosher' AND name='פרווה'");
seedTags(db());
check('זריעה חוזרת אינה מחזירה תג שנמחק',
      (int) db()->query("SELECT COUNT(*) c FROM tags WHERE name='פרווה'")->fetch()['c'], 0);

echo "\n9. נרמול טקסט לחיפוש\n";
check('ניקוד מוסר', normalizeText('עוּגַת גְּבִינָה'), 'עוגת גבינה');
check('גרשיים מוסרים', normalizeText('ק"ג'), 'קג');
check('אחוז נשמר', normalizeText('גבינה 5%'), 'גבינה 5%');
check('רווחים מכווצים', normalizeText("  עוגה   טובה \n"), 'עוגה טובה');

echo "\n10. כשל שליחת דוא\"ל אינו מפיל הרשמה\n";
@ini_set('sendmail_path', '/nonexistent/sendmail');
$third = createUser('third', 't@example.com', 'sod12345');
check('החשבון נוצר בכל זאת', $third['id'] > 0, true);
check('mail_sent מדווח false ולא מתיימר', $third['mail_sent'], false);
check('והחשבון נשאר לא מאומת',
      (int) db()->query("SELECT email_verified FROM users WHERE username='third'")->fetch()['email_verified'], 0);
@ini_set('sendmail_path', '/bin/true');

echo "\n12. שליחה חוזרת של דוא\"ל האימות\n";
$fourth = createUser('fourth', 'f@example.com', 'sod12345');
$row = fn() => db()->query("SELECT last_mail_at, last_mail_ok FROM users WHERE username='fourth'")->fetch();
check('ההרשמה רשמה את תוצאת ה-MTA (ולא השאירה NULL)', (int) $row()['last_mail_ok'], (int) $fourth['mail_sent']);
check('ומתי', $row()['last_mail_at'] !== null, true);
check('מיד אחרי ההרשמה — קירור, לא נשלח', resendVerification('fourth'), null);
db()->exec("UPDATE users SET last_mail_at = '2000-01-01T00:00:00+00:00', last_mail_ok = NULL WHERE username='fourth'");
$again = resendVerification('f@example.com');
check('אחרי הקירור — נשלח (bool, לא null)', is_bool($again), true);
check('והתוצאה נרשמה', (int) $row()['last_mail_ok'], (int) $again);
check('והזמן התעדכן', $row()['last_mail_at'] > '2001', true);
check('משתמש שאינו קיים — null, בלי שגיאה', resendVerification('nobody'), null);
check('משתמש מאומת — null', resendVerification('mali'), null);
$tok = db()->query("SELECT COUNT(*) FROM user_tokens WHERE user_id = {$fourth['id']} AND kind='verify_email' AND used_at IS NULL")->fetchColumn();
check('רק אסימון אחד פתוח — הקישור הישן מהרשמה בוטל', (int) $tok, 1);
expectError('ואסימון ההרשמה הישן נדחה', fn() => consumeToken($GLOBALS['fourth']['token'], 'verify_email'), 'כבר נוצל');

echo "\n11. חסימה בידי המנהל\n";
db()->exec("UPDATE users SET blocked = 1 WHERE username = 'mali'");
expectError('כניסה של חסום', fn() => login('mali', 'x12345678'), 'חסום');

// ניקוי
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media');
@rmdir($tmp);

echo "\n";
if ($fail) {
    echo "\u{274C} נכשלו " . count($fail) . " בדיקות: " . implode(', ', $fail) . "\n";
    exit(1);
}
echo "\u{2705} כל הבדיקות עברו\n";
