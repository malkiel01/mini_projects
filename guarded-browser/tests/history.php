<?php
/**
 * היסטוריית מדיניות, קבלות ושחזור (lib/history.php) — שלב 12 א+ז.
 *
 *   php guarded-browser/tests/history.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-hist-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/history.php';
require_once __DIR__ . '/../lib/enforce.php';
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
[$acc] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$kid] = childCreate($acc, 'נועה');
$parent = (int) one('SELECT id FROM parents WHERE account_id = ?', [$acc])['id'];
q("UPDATE parents SET name = 'דנה' WHERE id = ?", [$parent]);
$tok = registerDevice($kid, 'Galaxy', 'g1', 5);
$dev = (int) one("SELECT id FROM devices WHERE device_id = 'g1'")['id'];
$fetch = function () use ($kid, $dev) {          // כמו do=policy
    q('UPDATE devices SET policy_rev = ? WHERE id = ?', [policyRev($kid), $dev]);
    receiptReceived($kid, $dev);
};
$report = function (array $settings, array $extra = []) use ($kid, $dev) {   // כמו do=enforce_report
    $r = ['rev' => policyRev($kid), 'owner' => true, 'settings' => $settings] + $extra;
    enforceReportSave($dev, $r);
    receiptApplied($dev, $r);
};

echo "\n— גרסאות —\n";
$v0 = policyVersionRecord($kid, 0, 'לפני תחילת התיעוד');
check('גרסת בסיס', $v0 > 0, true);
check('בלי שינוי — אין גרסה חדשה', policyVersionRecord($kid, $parent, 'child_save'), 0);
childRuleAdd($kid, 'https://wikipedia.org/', 'domain', 'ויקיפדיה', 'allow');
$v1 = policyVersionRecord($kid, $parent, 'rule_add');
$h = policyHistory($kid);
check('גרסה חדשה — מי, ומה השתנה', [$h[0]['id'], $h[0]['by'], $h[0]['changes']], [$v1, 'דנה', ['+ ✅ https://wikipedia.org/']]);
deviceSettingSave($kid, 'camera', true);
childPolicySave($kid, ['daily_quota_min' => 90]);
$v2 = policyVersionRecord($kid, $parent, 'child_save');
$c = policyHistory($kid)[0]['changes'];
check('הגדרת מכשיר ומכסה — בשפה של ההורה', [in_array('📷 מצלמה: חסום', $c, true), in_array('מכסה יומית (דק׳): 0 ← 90', $c, true)], [true, true]);
q("INSERT INTO rules (user_id, label, pattern, scope, action, show_tile, sort_order, created_at, expires_at) VALUES (?, '', 'temp.example', 'domain', 'allow', 0, 0, ?, ?)",
  [$kid, nowIso(), gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
check('היתר זמני אינו "שינוי מדיניות"', policyVersionRecord($kid, $parent, 'request_decide'), 0);

echo "\n— קבלה: מהשרת עד המכשיר —\n";
check('לפני שהמכשיר משך — ממתין', policyHistory($kid)[0]['devices'][0]['state'], 'pending');
$fetch();
$d = policyHistory($kid)[0]['devices'][0];
check('משך — התקבל, עם שעה', [$d['state'], $d['received_at'] !== ''], ['received', true]);
$report(['camera' => 'verified']);
$d = policyHistory($kid)[0]['devices'][0];
check('דיווח שקרא בחזרה — אומת, עם שעה', [$d['state'], $d['applied_at'] !== ''], ['verified', true]);
check('גרסה שהמכשיר לא קיבל לפני שבאה חדשה — הוחלפה', policyHistory($kid)[1]['devices'][0]['state'], 'superseded');

childRuleAdd($kid, 'https://bad.example/', 'domain', '', 'deny');
policyVersionRecord($kid, $parent, 'rule_add');
$fetch();
$report(['camera' => 'fail'], ['suspend_failed' => ['com.roblox.client']]);
$d = policyHistory($kid)[0]['devices'][0];
check('נכשל — עם מה נכשל', [$d['state'], $d['detail']], ['failed', 'נכשל: 📷 מצלמה, השעיה: com.roblox.client']);

echo "\n— דוח על מדיניות ישנה לא נוגע בקבלה —\n";
childRuleAdd($kid, 'https://new.example/', 'domain', '', 'allow');
policyVersionRecord($kid, $parent, 'rule_add');
$fetch();
$old = ['rev' => 'old-rev', 'owner' => true, 'settings' => ['camera' => 'verified']];
receiptApplied($dev, $old);
check('דוח עם rev אחר — הקבלה נשארת "התקבל"', policyHistory($kid)[0]['devices'][0]['state'], 'received');

echo "\n— השוואה ושחזור —\n";
$cmp = policyCompare($kid, $v1);
check('השוואה לגרסה ישנה — מה ישתנה', $cmp, ['מכסה יומית (דק׳): 90 ← 0', '📷 מצלמה: מותר',
      '− 🚫 https://bad.example/', '− ✅ https://new.example/']);
check('...בלי הגדרות שלא השתנו (מיקום חסום כברירת מחדל — לא "מותר")', in_array('📍 מיקום: מותר', $cmp, true), false);
appsSaveRule($kid, 'com.roblox.client', 'block');
policyVersionRecord($kid, $parent, 'app_rule');
[$rv, $err] = policyRestore($kid, $v1, $parent);
check('שחזור — גרסה חדשה', [$err, $rv > 0], ['', true]);
check('המצב חזר: רק ויקיפדיה, בלי חסימת המצלמה, בלי כלל האפליקציה',
      [array_column(all("SELECT pattern FROM rules WHERE user_id = ? AND expires_at = '' ORDER BY id", [$kid]), 'pattern'),
       deviceSettings($kid)['camera'], appRules($kid)],
      [['https://wikipedia.org/'], false, []]);
check('היתר זמני לא נמחק בשחזור', (int) one("SELECT COUNT(*) n FROM rules WHERE user_id = ? AND pattern = 'temp.example'", [$kid])['n'], 1);
check('השחזור עצמו בהיסטוריה', policyHistory($kid)[0]['source'], "שחזור לגרסה $v1");
check('...ותמונת המצב זהה לגרסה ששוחזרה', policyCompare($kid, $v1), []);
check('גרסה של ילד אחר — לא נמצאה', policyRestore($kid, 999999, $parent)[1], 'הגרסה לא נמצאה');

echo "\n— שינוי מפאנל הניהול —\n";
childRuleAdd($kid, 'https://admin-added.example/', 'domain', '', 'deny');
policyVersionRecord($kid, 0, 'admin:add_rule');
$h = policyHistory($kid)[0];
check('נרשם כגרסה, וההורה רואה שמנהל המערכת שינה', [$h['by'], (bool) array_filter($h['changes'], fn($c) => str_contains($c, 'admin-added'))],
      ['מנהל המערכת', true]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
