<?php
/**
 * בדיקת הגנה יזומה (lib/selftest.php) — שלב 12 ג: בקשה ← מקרי בדיקה ← תוצאות מהמכשיר ← פסיקה בשרת.
 *
 *   php guarded-browser/tests/selftest.php
 *
 * הפסיקה מהנתונים הגולמיים: מכשיר שמכריע אחרת מהשרת — "נכשל", גם כשהוא מדווח שהכול בסדר.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-selftest-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/selftest.php';

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
q("UPDATE accounts SET status = 'active' WHERE id = ?", [$acc]);
[$kid] = childCreate($acc, 'נועה');
childRuleAdd($kid, 'https://wikipedia.org/', 'domain', 'ויקיפדיה', 'allow');
childRuleAdd($kid, 'https://bad.example/', 'domain', 'אתר רע', 'deny');
childPolicySave($kid, ['types' => ['video']]);
appsReport($kid, [['p' => 'com.game.fun', 'n' => 'משחק'], ['p' => 'com.school.app', 'n' => 'בית ספר']]);
appsSaveRule($kid, 'com.game.fun', 'block');
$user = fn(int $devRow) => childOf($acc, $kid) + ['device_row_id' => $devRow];

echo "\n— בקשה —\n";
check('בלי מכשיר — אין למי לשלוח', selftestRequest($kid), 0);
registerDevice($kid, 'Galaxy', 'g1', 5);
$dev = (int) one("SELECT id FROM devices WHERE device_id = 'g1'")['id'];
check('אין בקשה — אין בדיקה במדיניות', selftestFor($user($dev)), null);
$rev0 = policyRev($kid);
check('בקשה למכשיר אחד', selftestRequest($kid), 1);
check('הבקשה משנה את טביעת המדיניות — כל מסלול סנכרון ימשוך אותה', policyRev($kid) !== $rev0, true);
$t = selftestFor($user($dev));
check('יש בדיקה, עם מזהה', is_string($t['id'] ?? null) && $t['id'] !== '', true);

echo "\n— מקרי הבדיקה —\n";
$by = [];
foreach ($t['web'] as $w) $by[$w['what']] = [$w['allow'], $w['code']];
check('כלל חסימה — השרת מצפה לחסום', $by['כלל "חסום": אתר רע'] ?? null, [false, 'rule_deny']);
check('כלל היתר — מותר', $by['כלל "מותר": ויקיפדיה'] ?? null, [true, 'rule_allow']);
check('אתר שאינו ברשימה — לפי ברירת המחדל', isset($by['אתר שאינו ברשימה']), true);
check('סוג תוכן חסום — חסום', $by['סוג תוכן חסום: ווידאו'] ?? null, [false, 'type_blocked']);
check('כתובות הבדיקה לא קיימות ברשת (‎.invalid), חוץ מכללי ההורה עצמם',
      count(array_filter($t['web'], fn($w) => str_contains($w['url'], '.invalid'))), 2);
check('אפליקציה חסומה ומותקנת — נבדקת; מותרת — לא', array_column($t['apps'], 'p'), ['com.game.fun']);
check('תבנית עם * — לא הופכת לכתובת בדיקה', selftestRuleUrl('*.example.com'), '');

echo "\n— תוצאות —\n";
$result = fn(array $over = []) => $over + [
    'id' => $t['id'], 'owner' => true, 'access' => true, 'uninstall' => true, 'sync_ago_sec' => 60, 'version' => 120,
    'web' => array_map(fn($w) => ['url' => $w['url'], 'what' => $w['what'], 'want_allow' => $w['allow'], 'want_code' => $w['code'],
                                  'got_allow' => $w['allow'], 'got_code' => $w['code']], $t['web']),
    'apps' => [['p' => 'com.game.fun', 'n' => 'משחק', 'state' => 'suspended']],
    'settings' => ['camera' => 'verified', 'vpn' => 'verified'],
];
check('תשובה למזהה אחר — נדחית', selftestSave($dev, $result(['id' => 'old'])), 'אין בקשת בדיקה פתוחה');
check('תשובה נכונה — נשמרת', selftestSave($dev, $result()), '');
check('...והבקשה נסגרה — אין בדיקה במדיניות', selftestFor($user($dev)), null);
check('...ותשובה נוספת לאותה בקשה — עדיין מתקבלת (חזרה על שליחה), לא כפילות', selftestSave($dev, $result()), '');
$rep = selftestReport($kid)[0];
check('הכול תקין — אין כשלים ואין אזהרות', [$rep['fail'], $rep['warn'], $rep['waiting']], [0, 0, false]);

echo "\n— פסיקה בשרת, לא אמון במכשיר —\n";
$settings = deviceSettings($kid);
$v = function (array $over) use ($result, $settings) {
    $r = $result($over);
    return selftestVerdict(['owner' => $r['owner'], 'access' => $r['access'], 'uninstall' => $r['uninstall'], 'sync_ago' => $r['sync_ago_sec'],
                            'web' => $r['web'], 'apps' => $r['apps'], 'settings' => $r['settings']], $settings);
};
$first = fn(array $checks) => [$checks[0]['label'], $checks[0]['state']];
$web = $result()['web'];
$web[0]['got_allow'] = !$web[0]['got_allow']; $web[0]['got_code'] = 'posture_allow';
check('המכשיר מכריע אחרת מהשרת — נכשל, ראשון ברשימה', $first($v(['web' => $web])), [$web[0]['what'], 'fail']);
$web = $result()['web'];
$web[0]['got_allow'] = true; $web[0]['got_code'] = 'outside_window';
$c = $v(['web' => $web]);
check('הפרש בגלל שעות — אזהרה, לא כשל', [$c[0]['state'], str_contains($c[0]['detail'], 'שעות')], ['warn', true]);
check('אפליקציה שלא נחסמת — נכשל', $first($v(['apps' => [['p' => 'com.game.fun', 'n' => 'משחק', 'state' => 'open']]])), ['משחק', 'fail']);
check('נחסמת בנגישות — תקין', $v(['apps' => [['p' => 'com.game.fun', 'n' => 'משחק', 'state' => 'access']]])[0]['state'] ?? '', 'ok');
check('הגדרה שנקראה ולא תואמת — נכשל', $first($v(['settings' => ['camera' => 'fail']])), ['📷 מצלמה', 'fail']);
check('אפשר להסיר את מגדלור — נכשל', $first($v(['uninstall' => false])), ['הגנה מהסרת מגדלור', 'fail']);
check('בלי בעל מכשיר ובלי נגישות — נגישות נכשלת', in_array(['שירות הנגישות', 'fail'],
      array_map(fn($c) => [$c['label'], $c['state']], $v(['owner' => false, 'access' => false, 'uninstall' => null])), true), true);
check('סנכרון לפני יום — אזהרה', $first($v(['sync_ago_sec' => 86400])), ['סנכרון אחרון', 'warn']);

echo "\n— תוצאה מזויפת —\n";
selftestRequest($kid);
$t2 = selftestFor($user($dev));
selftestSave($dev, ['id' => $t2['id'], 'settings' => ['camera' => 'hacked', 'nope' => 'verified'], 'apps' => [['p' => '../x', 'state' => 'x']]]);
$stored = json_decode(one('SELECT selftest_result FROM devices WHERE id = ?', [$dev])['selftest_result'], true);
check('ערכים לא מוכרים — נזרקים', [$stored['settings'], $stored['apps']], [[], []]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
