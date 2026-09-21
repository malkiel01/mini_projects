<?php
/**
 * בדיקת log.php — היומן והטוקן — על מסד זמני.
 * הרצה: php recipes-app/tools/log-check.php
 *
 * מוכיחה: רישום עם סינון קלט (סיסמה לעולם לא נשמרת), סינון וקריאה, ניקוי,
 * טוקן עם תוקף — יצירה, פענוח, פקיעה, ביטול — ושבמסד יש רק hash.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-log-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/log.php';

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

$dev  = createUser('malkiel', 'd@x.com', 'sod12345', 'מלכיאל');
$devU = ['id' => $dev['id'], 'role' => 'admin', 'username' => 'malkiel'];

echo "\n1. רישום — והרשמה כבר רשמה את הדוא\"ל\n";
$mailRows = listLog(['action' => 'mail']);
check('createUser רשם אירוע mail', count($mailRows), 1);
check('ברמה לפי תוצאת ה-MTA', in_array($mailRows[0]['level'], ['info', 'error'], true), true);
db()->exec("DELETE FROM app_log WHERE action = 'mail'");   // הרמה תלויה ב-MTA; מכאן סופרים בלעדיה
logEvent('info', 'recipe-save', '', ['id' => 7], $devU, 42);
logEvent('warn', 'login', 'שם משתמש או סיסמה שגויים', ['username' => 'ghost', 'status' => 401]);
logEvent('error', 'exception', 'RuntimeException: boom', ['file' => 'x.php', 'line' => 3], $devU);
logEvent('bogus', 'x');   // רמה לא חוקית → info, לא חריגה
$all = listLog();
check('ארבע שורות, החדשה ראשונה', [count($all), $all[0]['action']], [4, 'x']);
check('רמה לא חוקית נפלה ל-info', $all[0]['level'], 'info');
check('משך ומזהה בקשה נשמרו', [$all[3]['duration_ms'], strlen((string) $all[3]['request_id'])], [42, 12]);
check('כל השורות מאותה ריצה חולקות request_id', count(array_unique(array_column($all, 'request_id'))), 1);

echo "\n2. סינון הקלט — סיסמה, טקסט ודוא\"ל לעולם לא נשמרים\n";
$safe = logSafeInput(['username' => 'dani', 'password' => 'secret123', 'email' => 'a@b.c',
                      'text' => 'תגובה', 'recipe_id' => 5, 'sections' => [[1]], 'q' => str_repeat('א', 200)]);
check('רק השדות מהרשימה', array_keys($safe), ['recipe_id', 'q', 'username']);
check('מחרוזת ארוכה נקצצת', mb_strlen($safe['q']), 80);
check('אין סיסמה', isset($safe['password']), false);

echo "\n3. קריאה עם מסננים\n";
check('לפי רמה: warn כולל error', count(listLog(['level' => 'warn'])), 2);
check('לפי רמה: error בלבד', count(listLog(['level' => 'error'])), 1);
check('לפי פעולה', listLog(['action' => 'login'])[0]['message'], 'שם משתמש או סיסמה שגויים');
check('לפי משתמש', count(listLog(['user' => 'malkiel'])), 2);
check('חיפוש חופשי בהודעה', count(listLog(['q' => 'boom'])), 1);
check('חיפוש חופשי במטא', count(listLog(['q' => 'ghost'])), 1);
$ids = array_column(listLog(), 'id');
check('דפדוף אחורה (before)', count(listLog(['before' => $ids[1]])), 2);
check('limit נאכף', count(listLog([], 2)), 2);
$stats = logStats();
check('סטטיסטיקה: בעיות ב-24h', [$stats['problems_24h'], $stats['errors_24h']], [2, 1]);
check('רשימת הפעולות', in_array('exception', $stats['actions'], true), true);
$text = logAsText(listLog(['action' => 'login']));
check('ייצוא טקסט: שורה אחת עם רמה והודעה', [substr_count($text, "\n"), str_contains($text, 'WARN') && str_contains($text, 'ghost')], [1, true]);

echo "\n4. ניקוי\n";
db()->exec("UPDATE app_log SET at = '2020-01-01T00:00:00Z' WHERE action = 'x'");
logPrune(true);
check('שורה ישנה מ-30 יום נמחקה', count(listLog(['action' => 'x'])), 0);
check('השאר נשארו', count(listLog()), 3);

echo "\n5. טוקן צפייה\n";
expectError('תוקף קצר מדי', fn() => createLogToken('x', 1, $GLOBALS['devU']), 'קצר');
expectError('תוקף ארוך מדי', fn() => createLogToken('x', 60 * 24 * 91, $GLOBALS['devU']), 'ארוך');
$t = createLogToken('  לקלוד  ', 60, $devU);
check('הטוקן הוחזר: 48 הקס', preg_match('/^[0-9a-f]{48}$/', $t['token']), 1);
check('השם נוקה', $t['label'], 'לקלוד');
check('במסד נשמר hash ולא הטוקן',
      db()->query('SELECT token_hash FROM log_tokens')->fetchColumn() === hash('sha256', $t['token']), true);
check('היצירה נרשמה ביומן', listLog(['action' => 'log-token-create'])[0]['meta']['ttl_minutes'], 60);
$resolved = resolveLogToken($t['token']);
check('פענוח מחזיר את השורה', $resolved['label'] ?? null, 'לקלוד');
check('ושימוש נספר', (int) db()->query('SELECT uses FROM log_tokens')->fetchColumn(), 1);
check('טוקן שגוי — null', resolveLogToken(str_repeat('0', 48)), null);
check('צורה לא חוקית — null, בלי שאילתה', resolveLogToken('abc'), null);
$list = listLogTokens();
check('הרשימה: פעיל, עם שם היוצר', [$list[0]['active'], $list[0]['created_by'], $list[0]['uses']], [true, 'malkiel', 1]);
db()->exec("UPDATE log_tokens SET expires_at = '2020-01-01T00:00:00Z' WHERE id = {$t['id']}");
check('פג — פענוח נכשל', resolveLogToken($t['token']), null);
check('ומסומן לא פעיל', listLogTokens()[0]['active'], false);
$t2 = createLogToken('שני', 30, $devU);
revokeLogToken($t2['id'], $devU);
check('בוטל — פענוח נכשל', resolveLogToken($t2['token']), null);
expectError('ביטול חוזר', fn() => revokeLogToken($GLOBALS['t2']['id'], $GLOBALS['devU']), 'כבר בוטל');
expectError('ביטול של לא קיים', fn() => revokeLogToken(9999, $GLOBALS['devU']), 'אינו קיים');
$t3 = createLogToken('שלישי', 30, $devU);
revokeLogToken($t3['id'], $devU);
db()->exec("UPDATE log_tokens SET revoked_at = '2020-01-01T00:00:00Z' WHERE id = {$t2['id']}");
logPrune(true);
check('ניקוי מוחק טוקנים שמתו לפני יותר משבוע, ומשאיר את שבוטל עכשיו',
      array_column(db()->query('SELECT label FROM log_tokens')->fetchAll(), 'label'), ['שלישי']);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
