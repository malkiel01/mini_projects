<?php
/**
 * אישור לפי משימה (lib/tasks.php) — שלב 12 ד: דפים ומשאבים לזמן מוגבל, במקום היתר קבוע.
 *
 *   php guarded-browser/tests/tasks.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-tasks-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/tasks.php';
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
childPolicySave($kid, ['posture' => 'deny_all', 'window_start' => '', 'window_end' => '', 'daily_quota_min' => 0, 'types' => ['video']]);
childRuleAdd($kid, 'https://school.example/admin', 'exact', 'ניהול', 'deny');
$child = fn() => childOf($acc, $kid);
/** הכרעה על משאב (לא ניווט). */
$res = function (string $url) use ($child) {
    $c = $child(); $p = policyFor((int) $c['id']);
    $acc = one('SELECT status, expires_at FROM accounts WHERE id = ?', [(int) $c['account_id']]);
    return evaluate($c + ['account_status' => $acc['status'], 'account_expires_at' => $acc['expires_at']], $p, ruleSetFor((int) $c['id']),
                    nowIn((string) $p['timezone']), ['url' => $url, 'main_frame' => false, 'used_today' => 0])['allow'];
};
$nav = fn(string $url) => simulateSite($child(), $url, '', -1, 0);

echo "\n— לפני המשימה —\n";
check('"רק מה שאישרתי" — הדף חסום', $nav('https://school.example/hw/1')['allow'], false);
check('...והמשאב שלו חסום', $res('https://cdn.lib.example/app.js'), false);

echo "\n— קלט —\n";
check('בלי שם', taskCreate($kid, '', [['url' => 'school.example']], [], 30, 1)[1] !== '', true);
check('יותר מ-4 שעות — לא', taskCreate($kid, 'x', [['url' => 'school.example']], [], 500, 1)[1] !== '', true);
check('יוטיוב — לא כאן, עם הסבר', str_contains(taskCreate($kid, 'x', [['url' => 'https://www.youtube.com/watch?v=abc']], [], 30, 1)[1], 'יוטיוב'), true);
check('כתובת לא תקינה', taskCreate($kid, 'x', [['url' => 'not a url']], [], 30, 1)[1] !== '', true);
check('שום כלל לא נוצר מקלט שנדחה', (int) one('SELECT COUNT(*) n FROM rules WHERE task_id > 0')['n'], 0);

echo "\n— משימה —\n";
[$tid, $err] = taskCreate($kid, 'שיעורי בית', [['url' => 'https://school.example/hw/1', 'scope' => 'exact']],
                          ['cdn.lib.example', 'https://fonts.example/css', 'school.example', 'bad host!', 'i.ytimg.com'], 30, 7);
check('נוצרה', [$tid > 0, $err], [true, '']);
check('הדף שאושר — נפתח', [$nav('https://school.example/hw/1')['allow'], $nav('https://school.example/hw/1')['code']], [true, 'rule_allow']);
check('...ובסימולטור רואים שזו המשימה', str_contains($nav('https://school.example/hw/1')['rule'], 'שיעורי בית'), true);
check('דף אחר באותו אתר — לא (רק הדף הזה)', $nav('https://school.example/hw/2')['allow'], false);
check('המשאב נטען בתוך הדף', $res('https://cdn.lib.example/app.js'), true);
check('...אבל אי אפשר לגלוש אליו', $nav('https://cdn.lib.example/')['allow'], false);
check('משאב שלא אושר — חסום', $res('https://tracker.example/x.js'), false);
check('לא מחלישה חסימה: איסור מפורש גובר', $nav('https://school.example/admin')['allow'], false);
check('לא מחלישה חסימה: סוג תוכן חסום גובר', $res('https://cdn.lib.example/movie.mp4'), false);
$t = tasksOf($kid)[0];
check('המשאבים: נקיים, בלי האתר עצמו, בלי קלט פגום ובלי יוטיוב', $t['resources'], ['cdn.lib.example', 'fonts.example']);
check('פעילה, עם הזמן שנותר', [$t['active'], $t['left_min']], [true, 30]);
check('הכללים פוקעים יחד — כולם עם אותו סוף', (int) one('SELECT COUNT(DISTINCT expires_at) n FROM rules WHERE task_id = ?', [$tid])['n'], 1);
check('הדף — אריח לילד; המשאב — לא', array_column(all('SELECT scope, show_tile FROM rules WHERE task_id = ? ORDER BY id', [$tid]), 'show_tile', 'scope'),
      ['exact' => 1, 'resource' => 0]);

echo "\n— פקיעה וסיום —\n";
q("UPDATE rules SET expires_at = '2020-01-01T00:00:00Z' WHERE task_id = ?", [$tid]);
check('פג הזמן — הדף שוב חסום', $nav('https://school.example/hw/1')['allow'], false);
check('...והמשאב', $res('https://cdn.lib.example/app.js'), false);
[$tid2] = taskCreate($kid, 'מבחן', [['url' => 'quiz.example']], [], 60, 7);
check('אתר שלם (domain)', $nav('https://quiz.example/any/page')['allow'], true);
check('סיום מוקדם', taskEnd($kid, $tid2), true);
check('...הכללים נמחקו — חסום מיד', $nav('https://quiz.example/any/page')['allow'], false);
check('...ולא מסיימים פעמיים', taskEnd($kid, $tid2), false);
check('משימה של ילד אחר — לא', taskEnd($kid + 999, $tid), false);

echo "\n— מה הדף צריך —\n";
$html = '<html><script src="https://cdn.lib.example/a.js"></script><link href="//fonts.example/f.css" rel=stylesheet>'
      . '<img src="https://school.example/logo.png"><img data-src="https://img.cdn.example/1.jpg"><img src="https://img.cdn.example/2.jpg">'
      . '<a href="https://elsewhere.example/">קישור — לא משאב</a><iframe src="https://player.example/embed/1"></iframe>';
[$hosts, $err] = taskScan('school.example/hw/1', fn($u) => ['status' => 200, 'body' => $html]);
check('שרתים שהדף טוען מהם — לא הדף עצמו ולא קישורים', [$err, $hosts[0], count($hosts), in_array('elsewhere.example', $hosts, true)],
      ['', 'img.cdn.example', 4, false]);
check('דף שלא נטען — שגיאה', taskScan('school.example', fn($u) => ['status' => 0, 'body' => '', 'error' => 'כתובת פנימית'])[1] !== '', true);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
