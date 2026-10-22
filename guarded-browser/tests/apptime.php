<?php
/**
 * זמן מסך לאפליקציות (lib/apptime.php) — 2026-10-22: מגבלה יומית, דיווח מהמכשיר, ותצוגה להורה.
 * האכיפה במכשיר — AppTimeMathTest.
 *
 *   php guarded-browser/tests/apptime.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-apptime-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/apptime.php';
require_once __DIR__ . '/../lib/simulator.php';
require_once __DIR__ . '/../lib/history.php';

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
appsReport($kid, [['p' => 'com.game.fun', 'n' => 'משחק'], ['p' => 'com.whatsapp', 'n' => 'וואטסאפ']]);
$payload = fn() => appsPayload(childOf($acc, $kid));
$row = function (string $pkg) use ($acc, $kid) {
    foreach (appsList($acc, $kid)['apps'] as $a) if ($a['package'] === $pkg) return $a;
    return [];
};

echo "\n— מגבלה —\n";
check('בלי מגבלות — אובייקט ריק למכשיר (לא מערך)', json_encode($payload()['limits']), '{}');
$rev0 = policyRev($kid);
check('30 דקות למשחק', appLimitSave($kid, 'com.game.fun', 30), '');
check('...מגיעה למכשיר', json_encode($payload()['limits']), '{"com.game.fun":30}');
check('...ומשנה את טביעת המדיניות — המכשיר ימשוך', policyRev($kid) !== $rev0, true);
check('מגדלור עצמו — אי אפשר להגביל', str_contains(appLimitSave($kid, 'com.mbeplus.guardedbrowser', 10), 'אי אפשר'), true);
check('מעל 12 שעות — נדחה', appLimitSave($kid, 'com.game.fun', 800) !== '', true);
check('שם חבילה פגום — נדחה', appLimitSave($kid, '../x', 10) !== '', true);

echo "\n— דיווח מהמכשיר —\n";
$today = appUsageDay($kid);
check('דיווח של היום — נשמר', appUsageSave($kid, ['day' => $today, 'apps' => ['com.game.fun' => 600, 'com.whatsapp' => 120]]), 2);
check('...ההורה רואה מגבלה וזמן', [$row('com.game.fun')['limit_min'], $row('com.game.fun')['used_sec']], [30, 600]);
appUsageSave($kid, ['day' => $today, 'apps' => ['com.game.fun' => 300]]);
check('דיווח ישן (פחות זמן) — לא מוריד', $row('com.game.fun')['used_sec'], 600);
appUsageSave($kid, ['day' => $today, 'apps' => ['com.game.fun' => 900]]);
check('דיווח חדש — מעדכן', $row('com.game.fun')['used_sec'], 900);
check('יום ישן מדי — נדחה', appUsageSave($kid, ['day' => '2020-01-01', 'apps' => ['com.game.fun' => 5]]), 0);
check('יותר מיממה בשניות — נחתך', (appUsageSave($kid, ['day' => $today, 'apps' => ['com.whatsapp' => 999999]]) && $row('com.whatsapp')['used_sec'] === 86400), true);
check('אתמול — מתקבל (סיכום אחרי חצות), לא נספר כהיום',
      [appUsageSave($kid, ['day' => appUsageDay($kid, time() - 86400), 'apps' => ['com.game.fun' => 50]]), $row('com.game.fun')['used_sec']], [1, 900]);

echo "\n— סימולטור —\n";
check('15 מתוך 30 דקות — פתוחה, עם הזמן שנותר', [simulateApp(childOf($acc, $kid), 'com.game.fun')['allow'],
      str_contains(simulateApp(childOf($acc, $kid), 'com.game.fun')['reason'], 'עוד 15 דק׳')], [true, true]);
appUsageSave($kid, ['day' => $today, 'apps' => ['com.game.fun' => 1800]]);
check('30 מתוך 30 — נגמר הזמן להיום', [simulateApp(childOf($acc, $kid), 'com.game.fun')['allow'], simulateApp(childOf($acc, $kid), 'com.game.fun')['where']],
      [false, '📲 אפליקציות — זמן מסך']);

echo "\n— היסטוריה —\n";
policyVersionRecord($kid, 0, 'בסיס');
appLimitSave($kid, 'com.whatsapp', 60);
policyVersionRecord($kid, 0, 'app_limit');
$v = policyHistory($kid);
check('השינוי נרשם בשם קריא', in_array('+ ⏱️ com.whatsapp — 60 דק׳ ביום', $v[0]['changes'], true), true);
policyRestore($kid, $v[1]['id'], 0);
check('שחזור לגרסה קודמת — המגבלה של וואטסאפ בוטלה, של המשחק נשארה', appLimits($kid), ['com.game.fun' => 30]);
check('0 — בלי מגבלה', [appLimitSave($kid, 'com.game.fun', 0), appLimits($kid)], ['', []]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
