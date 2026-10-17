<?php
/**
 * בדיקות לגרסת האפליקציה ולעדכון לפי בקשת ההורה.
 *
 *   php guarded-browser/tests/appversion.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-ver-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/appversion.php';

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
$tNew = registerDevice($kid, 'Galaxy', 'g1', 3);
$tOld = registerDevice($kid, 'Redmi ישן', 'r1', 3);
$dev = fn(string $t) => one('SELECT * FROM devices WHERE token_hash = ?', [tokenHash($t)]);

echo "\n— הגרסה האחרונה —\n";
$calls = 0;
$ok = function () use (&$calls) { $calls++; return ['status' => 200, 'body' => '{"version_code": 45, "version_name": "1.45"}']; };
check('נקראת מ-latest.json', latestApp($ok), ['code' => 45, 'name' => '1.45']);
check('ובמטמון — בלי פנייה נוספת', [latestApp($ok), $calls], [['code' => 45, 'name' => '1.45'], 1]);
q("UPDATE app_meta SET updated_at = '2020-01-01T00:00:00Z'");
$bad = fn() => ['status' => 404, 'body' => 'Not Found'];
check('שליפה שנכשלה — נשארים עם הישן', latestApp($bad), ['code' => 45, 'name' => '1.45']);
q('DELETE FROM app_meta');
check('אין מידע בכלל — null', latestApp($bad), null);
latestApp($ok);

echo "\n— הגרסה בכל מכשיר —\n";
$_SERVER['HTTP_X_MIGDALOR_APP'] = '43';
userByToken($tNew);
unset($_SERVER['HTTP_X_MIGDALOR_APP']);
userByToken($tOld);
check('אפליקציה חדשה מדווחת', (int) $dev($tNew)['app_version'], 43);
check('ישנה — לא ידוע', (int) $dev($tOld)['app_version'], 0);
$latest = latestApp();
check('43 מול 45 — יש עדכון', deviceAppState($dev($tNew), $latest)['status'], 'outdated');
check('ישנה — unknown', deviceAppState($dev($tOld), $latest)['status'], 'unknown');
check('שם הגרסה', deviceAppState($dev($tNew), $latest)['version_name'], '1.43');

echo "\n— עדכון לאפליקציה חדשה —\n";
check('בלי בקשה — אין הצעה', deviceUpdateOffer((int) $dev($tNew)['id']), null);
check('בקשת עדכון', requestDeviceUpdate($kid, (int) $dev($tNew)['id']), '');
check('...מסומנת', deviceAppState($dev($tNew), $latest)['requested'], true);
check('המכשיר מקבל הצעה עם הקישור', deviceUpdateOffer((int) $dev($tNew)['id']),
      ['version_code' => 45, 'version_name' => '1.45', 'url' => APK_URL]);
check('אפליקציה חדשה לא נחסמת', upgradeBlock((int) $dev($tNew)['id']), '');
$_SERVER['HTTP_X_MIGDALOR_APP'] = '45';
userByToken($tNew);
unset($_SERVER['HTTP_X_MIGDALOR_APP']);
check('אחרי העדכון — אין הצעה', deviceUpdateOffer((int) $dev($tNew)['id']), null);
check('...והבקשה נמחקה', $dev($tNew)['update_requested_at'], '');
check('מעודכן — אין מה לבקש', requestDeviceUpdate($kid, (int) $dev($tNew)['id']), 'המכשיר כבר בגרסה האחרונה');

echo "\n— אפליקציה ישנה —\n";
check('בלי בקשה — לא נחסמת', upgradeBlock((int) $dev($tOld)['id']), '');
requestDeviceUpdate($kid, (int) $dev($tOld)['id']);
check('אחרי בקשה — נחסמת עם הוראות', str_contains(upgradeBlock((int) $dev($tOld)['id']), 'מוחקים קודם את הישנה'), true);
check('ולא מקבלת update (היא לא מכירה אותו)', deviceUpdateOffer((int) $dev($tOld)['id']), null);
cancelDeviceUpdate($kid, (int) $dev($tOld)['id']);
check('ביטול — נפתחת שוב', upgradeBlock((int) $dev($tOld)['id']), '');

echo "\n— בידוד —\n";
[$a2] = accountCreate(['name' => 'לוי'], 'avi@x.com', 'אבי');
[$kid2] = childCreate($a2, 'דני');
check('מכשיר של ילד אחר — לא נמצא', requestDeviceUpdate($kid2, (int) $dev($tOld)['id']), 'המכשיר לא נמצא');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
