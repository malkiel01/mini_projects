<?php
/**
 * בדיקות למצב האכיפה (שלב 11): מה באמת נאכף לפי יכולות המכשירים.
 *   php guarded-browser/tests/enforce.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-enf-' . getmypid();
@mkdir($tmp, 0775, true); putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/enforce.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
/** מצב של פרמטר לפי המפתח. */
function st(array $e, string $key): string {
    foreach ($e['rows'] as $r) if ($r['key'] === $key) return $r['status'];
    return 'missing';
}
function dev(int $kid, string $id, int $perms) {
    $t = registerDevice($kid, $id, $id, 5);
    q('UPDATE devices SET perms = ? WHERE device_id = ?', [$perms, $id]);
}

db();
[$aApps] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$aBrowser] = accountCreate(['name' => 'לוי', 'level_max' => 'browser'], 'avi@x.com', 'אבי');
[$kid] = childCreate($aApps, 'נועה');

echo "\n— בלי מכשירים —\n";
check('אין מכשיר', enforcementState($aApps, $kid)['summary'], 'no_device');

echo "\n— מכשיר בסיסי (בלי נגישות/בעלות) —\n";
dev($kid, 'd1', PERM_NOTIF | PERM_BATTERY);   // 3
$e = enforcementState($aApps, $kid);
check('זמנים נאכפים', st($e, 'time'), 'full');
check('אתרים בתוך מגדלור נאכפים', st($e, 'web'), 'full');
check('הגנה מהסרה — לא נאכפת', st($e, 'uninstall'), 'none');
check('מניעת איפוס — לא נאכפת', st($e, 'tamper'), 'none');
check('סיכום — דורש בעל מכשיר', $e['summary'], 'needs_owner');

echo "\n— חוסמים אפליקציה, בלי נגישות —\n";
appsSaveRule($kid, 'com.supercell.clashofclans', 'block');
$e = enforcementState($aApps, $kid);
check('חסימת אפליקציות — לא נאכפת (אין נגישות)', st($e, 'apps'), 'none');
check('חסימת דפדפנים אחרים — יש חסום אבל אין נגישות', st($e, 'web_other'), 'none');

echo "\n— מוסיפים נגישות —\n";
q("UPDATE devices SET perms = ? WHERE device_id = 'd1'", [PERM_NOTIF | PERM_BATTERY | PERM_ACCESS]);  // 19
$e = enforcementState($aApps, $kid);
check('חסימת אפליקציות — נאכפת (רך)', st($e, 'apps'), 'full');
check('...אבל עדיין לא הרמטי', $e['summary'], 'needs_owner');

echo "\n— בעל מכשיר —\n";
q("UPDATE devices SET perms = ? WHERE device_id = 'd1'", [PERM_NOTIF | PERM_BATTERY | PERM_ACCESS | PERM_OWNER]);  // 51
$e = enforcementState($aApps, $kid);
check('הגנה מהסרה — נאכפת', st($e, 'uninstall'), 'full');
check('מניעת איפוס — נאכפת', st($e, 'tamper'), 'full');
check('חסימת אפליקציות — נאכפת', st($e, 'apps'), 'full');
check('הכול נאכף', $e['summary'], 'full');
check('מונה מכשירי בעלות', [$e['owner_devices'], $e['total_devices']], [1, 1]);

echo "\n— שני מכשירים, אחד חלש —\n";
dev($kid, 'd2', PERM_NOTIF | PERM_BATTERY);   // בלי נגישות/בעלות
$e = enforcementState($aApps, $kid);
check('הגנה מהסרה — חלקי (רק מכשיר אחד בעלות)', st($e, 'uninstall'), 'partial');
check('חסימת אפליקציות — חלקי', st($e, 'apps'), 'partial');
check('סיכום — לא הרמטי', $e['summary'], 'needs_owner');

echo "\n— חשבון דפדפן בלבד —\n";
[$kidB] = childCreate($aBrowser, 'דני');
dev($kidB, 'b1', PERM_NOTIF | PERM_BATTERY);
$e = enforcementState($aBrowser, $kidB);
check('אין שורת חסימת אפליקציות', st($e, 'apps'), 'missing');
check('זמן ואתרים נאכפים', [st($e, 'time'), st($e, 'web')], ['full', 'full']);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
