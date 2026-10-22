<?php
/**
 * מצב הביצוע של כל הגדרת מכשיר (lib/enforce.php, deviceSettingsStatus) — 2026-10-22.
 *
 *   php guarded-browser/tests/settingstatus.php
 *
 * "אין להציג הצלחה על סמך שמירה בשרת בלבד". כאן: נשמר ← ממתין ← התקבל ← הוחל / אומת / נכשל, לפי מה שהמכשיר
 * משך ודיווח — וגם להגדרה *מותרת* (התקלה המדווחת: היתר התקנה שלא פתח את ההתקנה, ובפאנל לא נראה דבר).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-setst-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/enforce.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$acc] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$kid] = childCreate($acc, 'נועה');
$st = fn(string $k) => deviceSettingsStatus($kid)[$k]['state'];
$hint = fn(string $k) => deviceSettingsStatus($kid)[$k]['hint'];
/** המכשיר משך את המדיניות הנוכחית (כמו do=policy). */
$fetched = fn(string $id) => q('UPDATE devices SET policy_rev = ? WHERE device_id = ?', [policyRev($kid), $id]);
/** דוח מהמכשיר על המדיניות הנוכחית. */
$report = function (string $id, array $settings) use ($kid) {
    $row = one('SELECT id FROM devices WHERE device_id = ?', [$id]);
    enforceReportSave((int) $row['id'], ['rev' => policyRev($kid), 'owner' => true, 'access' => true, 'settings' => $settings]);
};

echo "\n— הקטלוג —\n";
check('התקנה מקובץ — הגדרה נפרדת, חסומה כברירת מחדל (כמו שהייתה בפועל)', deviceSettings($kid)['install_unknown'], true);
check('התקנה מהחנות — מותרת כברירת מחדל', deviceSettings($kid)['install_apps'], false);

echo "\n— לפני שיש מכשיר —\n";
check('נשמר בשרת — ולא יותר מזה', $st('install_apps'), 'saved');

echo "\n— מסלול ההגדרה —\n";
registerDevice($kid, 'Galaxy', 'g1', 5);
check('יש מכשיר שלא משך — ממתין למכשיר', $st('install_apps'), 'pending');
$fetched('g1');
check('משך את המדיניות — התקבל במכשיר', $st('install_apps'), 'received');
$report('g1', ['install_apps' => 'verified', 'install_unknown' => 'verified', 'camera' => 'applied']);
check('נקרא בחזרה ותואם — אומת', $st('install_apps'), 'verified');
check('...עם זמן האימות', deviceSettingsStatus($kid)['install_apps']['at'] !== '', true);
check('הקריאה הצליחה אבל אי אפשר לבדוק — הוחל, לא אומת', $st('camera'), 'applied');

echo "\n— התקלה המדווחת: היתר התקנה שלא פתח —\n";
$report('g1', ['install_apps' => 'fail']);
check('מותר, ונשארה הגבלה — נכשל (לא "נשמר")', [$st('install_apps'), $hint('install_apps')],
      ['failed', 'המכשיר לא הצליח לפתוח — נשארה הגבלה']);
$report('g1', ['install_apps' => 'blocked_by_apps']);
check('מותר, אבל Google Play חסומה ברשימת האפליקציות — נדרשת פעולה', $st('install_apps'), 'needs_action');
deviceSettingSave($kid, 'install_unknown', false);
$fetched('g1');
$report('g1', ['install_unknown' => 'needs_device']);
check('APK מותר — אומר במפורש שאנדרואיד מבקש אישור במכשיר', [$st('install_unknown'), str_contains($hint('install_unknown'), 'אנדרואיד מבקש')],
      ['needs_device', true]);
deviceSettingSave($kid, 'install_apps', true);
$fetched('g1');
$report('g1', ['install_unknown' => 'needs_device']);
check('...וכש"התקנת אפליקציות" חסומה — אומר שגם זה חסום', str_contains($hint('install_unknown'), 'ולכן גם זה חסום'), true);

echo "\n— שינוי אחרי הדוח —\n";
deviceSettingSave($kid, 'camera', true);
check('דוח על מדיניות קודמת אינו "אומת" — חוזר לממתין', $st('camera'), 'pending');

echo "\n— גרסה ישנה וחוסר בעל מכשיר —\n";
$fetched('g1');
$report('g1', ['camera' => 'ok']);
check('"ok" של גרסה ישנה — הוחל, לא אומת', $st('camera'), 'applied');
$report('g1', ['camera' => 'no_owner']);
check('בלי בעל מכשיר — נדרשת פעולה במכשיר', [$st('camera'), $hint('camera')], ['needs_device', 'דורש הקמה מלאה ("בעל מכשיר")']);
$report('g1', ['date_time' => 'unsupported']);
check('אין הגבלה כזו באנדרואיד — לא נתמך', $st('date_time'), 'unsupported');

echo "\n— שני מכשירים: מוצג הגרוע —\n";
registerDevice($kid, 'Redmi', 'r1', 5);
$fetched('g1'); $fetched('r1');
$report('g1', ['camera' => 'verified']);
$report('r1', ['camera' => 'fail']);
check('אחד אומת ואחד נכשל — נכשל, עם שם המכשיר', [$st('camera'), str_contains($hint('camera'), 'Redmi')], ['failed', true]);

echo "\n— דוח ישן —\n";
q("UPDATE devices SET enforce_at = '2020-01-01T00:00:00Z'");
check('דוח בן יותר מיממה — לא "אומת"', $st('camera'), 'received');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
