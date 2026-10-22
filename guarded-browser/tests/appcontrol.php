<?php
/**
 * בדיקות לניהול אפליקציות המכשיר (שלב 11): רמת חשבון, דיווח, הכרעות, ברירת מחדל, בידוד.
 *
 *   php guarded-browser/tests/appcontrol.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-app-' . getmypid();
@mkdir($tmp, 0775, true); putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/appcontrol.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
db();
[$aApps] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$aBrowser] = accountCreate(['name' => 'לוי', 'level_max' => 'browser'], 'avi@x.com', 'אבי');
[$kid] = childCreate($aApps, 'נועה');
[$kidB] = childCreate($aBrowser, 'דני');
$user = fn(int $id, int $acc) => ['id' => $id, 'account_id' => $acc] + (array) one('SELECT * FROM users WHERE id = ?', [$id]);

echo "\n— רמת החשבון —\n";
check('apps — מותר', accountAppsAllowed($aApps), true);
check('browser — לא', accountAppsAllowed($aBrowser), false);
check('חשבון דפדפן — payload manage=false', appsPayload($user($kidB, $aBrowser)), ['manage' => false]);
check('חבילה לא תקינה נדחית', cleanPackage('Not A Package'), '');
check('חבילה תקינה', cleanPackage('com.whatsapp'), 'com.whatsapp');

echo "\n— דיווח מהמכשיר —\n";
check('נקלטו 3', appsReport($kid, [
    ['p' => 'com.whatsapp', 'n' => 'וואטסאפ'],
    ['p' => 'com.android.chrome', 'n' => 'Chrome', 's' => 0],
    ['p' => 'com.supercell.clashofclans', 'n' => 'Clash'],
    ['p' => 'bad pkg', 'n' => 'x']]), 3);
check('רשימת ההורה — 3 אפליקציות', count(appsList($aApps, $kid)['apps']), 3);
check('ממוינות לפי שם', array_column(appsList($aApps, $kid)['apps'], 'label'), ['Chrome', 'Clash', 'וואטסאפ']);

echo "\n— ברירת מחדל והכרעות —\n";
check('ברירת מחדל allow_all — כלום לא חסום', appsPayload($user($kid, $aApps))['blocked'], []);
check('allow_all + בלי כלל — לא חסום', appBlocked('allow_all', ''), false);
check('allow_all + block — חסום', appBlocked('allow_all', 'block'), true);
check('deny_all + בלי כלל — חסום', appBlocked('deny_all', ''), true);
check('deny_all + allow — מותר', appBlocked('deny_all', 'allow'), false);
appsSaveRule($kid, 'com.supercell.clashofclans', 'block');
check('חסימת משחק', appsPayload($user($kid, $aApps))['blocked'], ['com.supercell.clashofclans']);
$l = appsList($aApps, $kid);
check('ברשימת ההורה — מסומן חסום', array_values(array_filter($l['apps'], fn($a) => $a['package'] === 'com.supercell.clashofclans'))[0]['blocked'], true);
check('מונה חסומות', $l['blocked_count'], 1);

echo "\n— deny_all —\n";
check('מעבר ל-deny_all', appsSavePosture($kid, 'deny_all'), '');
appsSaveRule($kid, 'com.whatsapp', 'allow');
$p = appsPayload($user($kid, $aApps));
check('רק וואטסאפ מותר (ומסך ההתקנה — תמיד)', [$p['posture'], $p['allowed']], ['deny_all', array_merge(['com.whatsapp'], UPDATE_PACKAGES, SHELL_PACKAGES)]);
check('מסך הבית של סמסונג — אי אפשר לחסום', appsSaveRule($kid, 'com.sec.android.app.launcher', 'block'), 'תמיד פתוח — מסך הבית או המקלדת');
check('מסך ההתקנה — אי אפשר לחסום (דרכו מגדלור מתעדכן)', appsSaveRule($kid, 'com.google.android.packageinstaller', 'block'),
      'תמיד פתוח — דרכו מגדלור מתעדכן');
check('מגדלור עצמה — אי אפשר לחסום', appsSaveRule($kid, SELF_PACKAGE, 'block'), 'תמיד פתוח — זו מגדלור עצמה');
q("INSERT INTO app_rules (child_id, package, action, created_at) VALUES (?, ?, 'block', ?)", [$kid, SELF_PACKAGE, nowIso()]);
check('...גם כלל ישן שחוסם אותה לא נשלח למכשיר', in_array(SELF_PACKAGE, appsPayload($user($kid, $aApps))['blocked'], true), false);
q('DELETE FROM app_rules WHERE child_id = ? AND package = ?', [$kid, SELF_PACKAGE]);
appsReport($kid, [['p' => SELF_PACKAGE, 'n' => 'מגדלור']]);
$me = array_values(array_filter(appsList($aApps, $kid)['apps'], fn($a) => $a['package'] === SELF_PACKAGE))[0] ?? [];
check('...ואצל ההורה — "תמיד", לא חסומה גם ב"רק מה שאישרתי"', [$me['locked'] ?? null, $me['blocked'] ?? null, $me['why'] ?? null],
      [true, false, 'זו מגדלור עצמה']);
check('ברירת מחדל לא מוכרת', appsSavePosture($kid, 'x'), 'ברירת מחדל לא מוכרת');

echo "\n— הסתרה (👻) —\n";
check('הסתרת משחק', appsSaveRule($kid, 'com.supercell.clashofclans', 'hide'), '');
$p = appsPayload($user($kid, $aApps));
check('נשלחת כמוסתרת — וגם כחסומה (גרסה ישנה / בלי בעל מכשיר חוסמים אותה)',
      [$p['hidden'], in_array('com.supercell.clashofclans', $p['blocked'], true)], [['com.supercell.clashofclans'], true]);
$l = appsList($aApps, $kid);
$g = array_values(array_filter($l['apps'], fn($a) => $a['package'] === 'com.supercell.clashofclans'))[0];
check('אצל ההורה: 👻, נחשבת חסומה, ומונה מוסתרות', [$g['action'], $g['blocked'], $l['hidden_count']], ['hide', true, 1]);
check('בלי מכשיר עם "בעל מכשיר" — owner_missing לפי המכשירים', $l['owner_missing'],
      (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ? AND (perms & 32) = 0', [$kid])['n']);
check('גם ב"הכול פתוח" — מוסתרת חסומה', appBlocked('allow_all', 'hide'), true);
check('ההגדרות — אי אפשר להסתיר', appsSaveRule($kid, 'com.android.settings', 'hide'),
      'את ההגדרות אפשר לחסום, לא להסתיר — מגדלור צריך אותן להקמה ולעדכון');
check('...אבל אפשר לחסום', appsSaveRule($kid, 'com.android.settings', 'block'), '');
appsSaveRule($kid, 'com.android.settings', '');
check('מסך הבית — אי אפשר להסתיר', appsSaveRule($kid, 'com.sec.android.app.launcher', 'hide'), 'תמיד פתוח — מסך הבית או המקלדת');
check('פעולה לא מוכרת', appsSaveRule($kid, 'com.whatsapp', 'vanish'), 'פעולה לא מוכרת');
check('ביטול ההסתרה — יוצאת מהרשימה', [appsSaveRule($kid, 'com.supercell.clashofclans', 'block'),
      appsPayload($user($kid, $aApps))['hidden']], ['', []]);

echo "\n— ניקוי כלל —\n";
appsSaveRule($kid, 'com.whatsapp', '');
check('הוסר הכלל', isset(appRules($kid)['com.whatsapp']), false);

echo "\n— בידוד —\n";
check('חשבון דפדפן — דיווח לא נשמר דרך ה-API (appsReport ישיר כן, אבל appsList חסום)', appsList($aBrowser, $kidB), ['manage' => false]);
check('הילד של לוי לא רואה אפליקציות של נועה', count(appsList($aApps, $kid)['apps']), 4);
childDelete($kid);
check('מחיקת ילד מוחקת אפליקציות וכללים',
      (int) one('SELECT COUNT(*) n FROM device_apps WHERE child_id = ?', [$kid])['n']
    + (int) one('SELECT COUNT(*) n FROM app_rules WHERE child_id = ?', [$kid])['n'], 0);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
