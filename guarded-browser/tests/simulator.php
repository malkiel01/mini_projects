<?php
/**
 * סימולטור הרשאות (lib/simulator.php) — שלב 12 ב: ההכרעה והכלל שקבע אותה, בלי לכתוב דבר.
 *
 *   php guarded-browser/tests/simulator.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-sim-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/simulator.php';

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
$child = fn() => childOf($acc, $kid);
childRuleAdd($kid, 'https://wikipedia.org/', 'domain', 'ויקיפדיה', 'allow');
childRuleAdd($kid, 'https://wikipedia.org/wiki/Bad', 'exact', 'דף אחד', 'deny');
childPolicySave($kid, ['window_start' => '08:00', 'window_end' => '20:00', 'daily_quota_min' => 60]);
$audit0 = (int) one('SELECT COUNT(*) n FROM audit')['n'];

echo "\n— אתר —\n";
$r = simulateSite($child(), 'wikipedia.org/wiki/Cats', '10:00', -1, 0);
check('אתר שהותר — מותר, ובאיזה כלל', [$r['allow'], $r['code'], $r['rule']], [true, 'rule_allow', 'https://wikipedia.org/ (ויקיפדיה)']);
check('...ואיפה בהגדרות', $r['where'], '🌐 אתרים — כלל "מותר"');
$r = simulateSite($child(), 'https://wikipedia.org/wiki/Bad', '10:00', -1, 0);
check('איסור מפורש גובר — והכלל שאסר', [$r['allow'], $r['code'], $r['rule']], [false, 'rule_deny', 'https://wikipedia.org/wiki/Bad (דף אחד)']);
$r = simulateSite($child(), 'example.net', '10:00', -1, 0);
check('אתר שלא ברשימה — ברירת המחדל', [$r['allow'], $r['code'], $r['where']], [false, 'not_listed', '🔐 גישה — ברירת המחדל "רק מה שאישרתי"']);

echo "\n— שעה ומכסה —\n";
$r = simulateSite($child(), 'wikipedia.org', '21:30', -1, 0);
check('מחוץ לחלון — נחסם בגלל הזמן', [$r['allow'], $r['code'], $r['where']], [false, 'outside_window', '⏰ זמנים — שעות הגלישה']);
check('...בשעה שנבחרה', substr($r['at'], -5), '21:30');
$r = simulateSite($child(), 'wikipedia.org', '10:00', -1, 60);
check('מכסה שנוצלה — נחסם', [$r['allow'], $r['code']], [false, 'quota_spent']);
childPolicySave($kid, ['days' => [0, 1, 2, 3, 4]]);
$r = simulateSite($child(), 'wikipedia.org', '10:00', 6, 0);
check('יום שלא הוגדר (שבת)', [$r['allow'], $r['code'], $r['day']], [false, 'day_blocked', 6]);

echo "\n— אפליקציה —\n";
appsReport($kid, [['p' => 'com.roblox.client', 'n' => 'Roblox']]);
check('ברירת מחדל "הכול פתוח"', [simulateApp($child(), 'com.roblox.client')['allow'], simulateApp($child(), 'com.roblox.client')['where']],
      [true, '📲 אפליקציות — ברירת המחדל']);
appsSaveRule($kid, 'com.roblox.client', 'block');
$a = simulateApp($child(), 'com.roblox.client');
check('חסומה — הכלל עליה, בשם שלה', [$a['allow'], $a['label'], $a['reason']], [false, 'Roblox', 'חסמתם את האפליקציה הזו']);
check('ואומר שזמנים לא חלים על אפליקציות', str_contains($a['note'], 'לא על אפליקציות'), true);
check('מסך ההתקנה — תמיד פתוח', simulateApp($child(), 'com.android.packageinstaller')['where'], 'תמיד פתוח');

echo "\n— בלי תופעות לוואי —\n";
check('לא נכתב ליומן', (int) one('SELECT COUNT(*) n FROM audit')['n'], $audit0);
check('לא נצבר זמן', usedTodaySeconds($kid, 'Asia/Jerusalem'), 0);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
