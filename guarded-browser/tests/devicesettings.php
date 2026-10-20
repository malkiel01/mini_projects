<?php
/**
 * בדיקות להגדרות המכשיר (lib/devicesettings.php) — ברירות מחדל, שמירה, במדיניות ובמצב האכיפה.
 *
 *   php guarded-browser/tests/devicesettings.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-devset-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp);
});
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
[$a] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
[$kid] = childCreate($a, 'נועה');

echo "\n— ברירות מחדל —\n";
$s = deviceSettings($kid);
check('מה שהיה נעול לפני הפאנל — חסום', [$s['date_time'], $s['accounts'], $s['vpn'], $s['location']], [true, true, true, true]);
check('השאר — מותר', [$s['wifi'], $s['camera'], $s['install_apps']], [false, false, false]);
check('כל ההגדרות בתצוגה, לפי הסדר — בלי Wi‑Fi', array_column(deviceSettingsView($kid), 'key'),
      array_values(array_diff(array_keys(DEVICE_SETTINGS), DEVICE_NEVER_BLOCK)));

echo "\n— שמירה —\n";
check('חסימת מצלמה', deviceSettingSave($kid, 'camera', true), '');
check('...נשמרה', deviceSettings($kid)['camera'], true);
check('...ושאר ההגדרות לא זזו', deviceSettings($kid)['vpn'], true);
check('פתיחת VPN', deviceSettingSave($kid, 'vpn', false), '');
check('...נשמרה', deviceSettings($kid)['vpn'], false);
check('מפתח לא מוכר', deviceSettingSave($kid, 'nuke', true), 'הגדרה לא מוכרת');
check('Wi‑Fi — אי אפשר לחסום', deviceSettingSave($kid, 'wifi', true), 'Wi‑Fi תמיד מותר — בלי רשת אי אפשר לשחרר את המכשיר מרחוק');
q('UPDATE policies SET device_settings = ? WHERE user_id = ?', [json_encode(['wifi' => true]), $kid]);
check('...גם חסימה ישנה נשלחת כמותר', deviceSettings($kid)['wifi'], false);
$rev = policyRev($kid);
deviceSettingSave($kid, 'bluetooth', true);
check('שינוי מחליף את טביעת המדיניות (המכשיר מתעדכן בפעימה)', policyRev($kid) !== $rev, true);

echo "\n— מצב האכיפה (לפי הדוח מהמכשיר) —\n";
registerDevice($kid, 'Galaxy', 'g1', 2);
$row = fn() => array_values(array_filter(enforcementState($a, $kid)['rows'], fn($r) => $r['key'] === 'device'))[0];
$dev = (int) one("SELECT id FROM devices WHERE device_id = 'g1'")['id'];
$okAll = fn() => array_fill_keys(array_keys(array_filter(deviceSettings($kid))), 'ok');
check('המכשיר עוד לא דיווח — לא "נאכף"', $row()['status'], 'unknown');
enforceReportSave($dev, ['rev' => policyRev($kid), 'owner' => false, 'access' => true]);
check('בלי "בעל מכשיר" — לא נאכף', $row()['status'], 'none');
check('...והסיכום מסמן', enforcementState($a, $kid)['summary'], 'needs_owner');
enforceReportSave($dev, ['rev' => policyRev($kid), 'owner' => true, 'access' => true, 'settings' => $okAll()]);
check('"בעל מכשיר", וכל ההגדרות הוחלו — נאכף', $row()['status'], 'full');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
