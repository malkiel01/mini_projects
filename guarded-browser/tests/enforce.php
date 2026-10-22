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
/** השורה כולה, ומצבה באוצר המצבים (SETTING_STATES). */
function rowOf(array $e, string $key): array {
    foreach ($e['rows'] as $r) if ($r['key'] === $key) return $r;
    return [];
}
function stt(array $e, string $key): string { return rowOf($e, $key)['state'] ?? 'missing'; }
function dev(int $kid, string $id, int $perms) {
    $t = registerDevice($kid, $id, $id, 5);
    q('UPDATE devices SET perms = ? WHERE device_id = ?', [$perms, $id]);
}

/** דוח מהמכשיר, כאילו נשלח עכשיו על המדיניות הנוכחית. */
function report(int $kid, string $id, array $r) {
    $row = one('SELECT id FROM devices WHERE device_id = ?', [$id]);
    enforceReportSave((int) $row['id'], $r + ['rev' => policyRev($kid)]);
}
/** כל ההגדרות שההורה חסם (כולל ברירות המחדל) — הוחלו. */
function okSettings(int $kid, array $over = []): array {
    return $over + array_fill_keys(array_keys(array_filter(deviceSettings($kid))), 'ok');
}
function hint(array $e, string $key): string {
    foreach ($e['rows'] as $r) if ($r['key'] === $key) return $r['hint'];
    return '';
}

db();
[$aApps] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$aBrowser] = accountCreate(['name' => 'לוי', 'level_max' => 'browser'], 'avi@x.com', 'אבי');
[$kid] = childCreate($aApps, 'נועה');
$all = PERM_NOTIF | PERM_BATTERY | PERM_ADMIN | PERM_INSTALL | PERM_ACCESS | PERM_OWNER;

echo "\n— בלי מכשירים —\n";
check('אין מכשיר', enforcementState($aApps, $kid)['summary'], 'no_device');
check('...וכל שורה: נשמר בשרת, ולא יותר', stt(enforcementState($aApps, $kid), 'time'), 'saved');

echo "\n— הרשאה אינה אימות —\n";
dev($kid, 'd1', $all);
appsSaveRule($kid, 'com.supercell.clashofclans', 'block');
$e = enforcementState($aApps, $kid);
check('כל הביטים דלוקים, אבל המכשיר לא דיווח — לא "נאכף"', [st($e, 'apps'), st($e, 'uninstall'), st($e, 'web_other')],
      ['unknown', 'unknown', 'unknown']);
check('...והסיכום: לא אומת', $e['summary'], 'unverified');
// עד 2026-10-22: "נאכף" ברגע שהיה מכשיר — הצלחה על סמך כוונה. עכשיו — רק אחרי דוח על המדיניות הנוכחית.
check('זמנים ואתרים — בלי דוח מהמכשיר: לא דווח, לא "נאכף"', [st($e, 'time'), st($e, 'web')], ['unknown', 'unknown']);
check('...ובאוצר המצבים: ממתין למכשיר (עוד לא משך)', stt($e, 'time'), 'pending');
q("UPDATE devices SET policy_rev = ? WHERE device_id = 'd1'", [policyRev($kid)]);
check('משך את המדיניות ולא דיווח — התקבל במכשיר', stt(enforcementState($aApps, $kid), 'time'), 'received');

echo "\n— דוח עדכני: הכול הוחל —\n";
report($kid, 'd1', ['owner' => true, 'access' => true, 'settings' => okSettings($kid), 'suspend_failed' => [],
                    'browsers' => [['p' => 'com.android.chrome', 'n' => 'Chrome', 'blocked' => true]]]);
$e = enforcementState($aApps, $kid);
check('אפליקציות, דפדפנים והגנה מהסרה — נאכפים', [st($e, 'apps'), st($e, 'web_other'), st($e, 'uninstall')], ['full', 'full', 'full']);
check('דוח עדכני — זמנים ואתרים נאכפים, ובמצב "הוחל"', [st($e, 'time'), st($e, 'web'), stt($e, 'time')], ['full', 'full', 'applied']);
check('הכול נאכף', $e['summary'], 'full');
check('מועד הדיווח מוצג', $e['devices'][0]['state'] === 'fresh' && $e['devices'][0]['reported_at'] !== '', true);

echo "\n— חסימת משחק אינה חסימת דפדפנים —\n";
report($kid, 'd1', ['owner' => true, 'access' => true, 'suspend_failed' => [],
                    'browsers' => [['p' => 'com.android.chrome', 'n' => 'Chrome', 'blocked' => false],
                                   ['p' => 'org.mozilla.firefox', 'n' => 'Firefox', 'blocked' => true]]]);
$e = enforcementState($aApps, $kid);
check('משחק חסום, Chrome פתוח — דפדפנים אחרים: לא נאכף', st($e, 'web_other'), 'none');
check('...וההורה רואה מה פתוח', str_contains(hint($e, 'web_other'), 'Chrome') && !str_contains(hint($e, 'web_other'), 'Firefox'), true);

echo "\n— השעיה שנכשלה —\n";
report($kid, 'd1', ['owner' => true, 'access' => true, 'suspend_failed' => ['com.supercell.clashofclans'],
                    'browsers' => []]);
$e = enforcementState($aApps, $kid);
check('כישלון השעיה מוצג', st($e, 'apps'), 'none');
check('...כ"נכשל" (החלה שנכשלה, לא יכולת חסרה)', stt($e, 'apps'), 'failed');
check('...עם שם החבילה והבטחת ניסיון חוזר', str_contains(hint($e, 'apps'), 'com.supercell.clashofclans') && str_contains(hint($e, 'apps'), 'ינסה שוב'), true);

echo "\n— נגישות: מה שהמכשיר מדווח, לא הביט —\n";
q('UPDATE devices SET perms = ? WHERE device_id = ?', [PERM_NOTIF | PERM_ACCESS, 'd1']);
report($kid, 'd1', ['owner' => false, 'access' => false, 'browsers' => []]);
check('הביט אומר נגישות, המכשיר מדווח שהיא כבויה — לא נאכף', st(enforcementState($aApps, $kid), 'apps'), 'none');
report($kid, 'd1', ['owner' => false, 'access' => true, 'browsers' => []]);
$e = enforcementState($aApps, $kid);
check('נגישות פעילה בלי בעלות — אפליקציות נאכפות (רך), הגנה מהסרה לא', [st($e, 'apps'), st($e, 'uninstall')], ['full', 'none']);
check('...והגנה מהסרה: נדרשת פעולה במכשיר, עם התווית', [stt($e, 'uninstall'), rowOf($e, 'uninstall')['state_label']], ['needs_device', 'נדרשת פעולה במכשיר']);
check('...והסיכום: לא הרמטי', $e['summary'], 'needs_owner');

echo "\n— דוח ישן אינו אימות עדכני —\n";
report($kid, 'd1', ['owner' => true, 'access' => true, 'browsers' => []]);
q("UPDATE devices SET enforce_at = ? WHERE device_id = 'd1'", [gmdate('Y-m-d\TH:i:s\Z', time() - ENFORCE_STALE_SEC - 60)]);
$e = enforcementState($aApps, $kid);
check('דוח מלפני יותר מיממה — לא דווח', [st($e, 'apps'), $e['devices'][0]['state']], ['unknown', 'stale']);
report($kid, 'd1', ['owner' => true, 'access' => true, 'browsers' => []]);
appsSaveRule($kid, 'com.whatsapp', 'block');   // ההורה שינה — המדיניות חדשה, הדוח על הישנה
$e = enforcementState($aApps, $kid);
check('ההורה שינה אחרי הדוח — עוד לא אומת', [st($e, 'apps'), $e['devices'][0]['state']], ['unknown', 'pending']);

echo "\n— הגדרות המכשיר —\n";
deviceSettingSave($kid, 'camera', true);
report($kid, 'd1', ['owner' => true, 'access' => true, 'settings' => okSettings($kid, ['camera' => 'fail']), 'browsers' => []]);
$e = enforcementState($aApps, $kid);
check('מצלמה חסומה אבל ההחלה נכשלה — לא נאכף', st($e, 'device'), 'none');
check('...עם שם ההגדרה', str_contains(hint($e, 'device'), 'מצלמה'), true);
report($kid, 'd1', ['owner' => true, 'access' => true, 'settings' => okSettings($kid), 'browsers' => []]);
check('הוחלה — נאכף', st(enforcementState($aApps, $kid), 'device'), 'full');

echo "\n— שני מכשירים, אחד חלש —\n";
dev($kid, 'd2', $all);
report($kid, 'd2', ['owner' => false, 'access' => true, 'browsers' => []]);
$e = enforcementState($aApps, $kid);
check('הגנה מהסרה — חלקי', st($e, 'uninstall'), 'partial');
check('הגדרות המכשיר — חלקי', st($e, 'device'), 'partial');

echo "\n— דוח מזויף לא נשמר כמו שהוא —\n";
$id = (int) one("SELECT id FROM devices WHERE device_id = 'd2'")['id'];
enforceReportSave($id, ['settings' => ['nuke' => 'ok', 'camera' => 'maybe'], 'suspend_failed' => ['not a pkg', 'com.ok.app'],
                        'browsers' => [['p' => '../../etc', 'blocked' => true]]]);
$r = json_decode(one('SELECT enforce_report FROM devices WHERE id = ?', [$id])['enforce_report'], true);
check('מפתחות וערכים לא מוכרים נזרקים', [$r['settings'], $r['suspend_failed'], $r['browsers']], [[], ['com.ok.app'], []]);

echo "\n— חשבון דפדפן בלבד —\n";
[$kidB] = childCreate($aBrowser, 'דני');
dev($kidB, 'b1', 3);
$e = enforcementState($aBrowser, $kidB);
check('אין שורת חסימת אפליקציות', st($e, 'apps'), 'missing');
check('זמן ואתרים — בלי דוח: לא דווח', [st($e, 'time'), st($e, 'web')], ['unknown', 'unknown']);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
