<?php
/**
 * רובריקות דף הבית (lib/policy.php, HOME_SECTIONS): שמירה, ברירת מחדל, ויוטיוב כבוי — סגור בשני המנועים.
 *
 *   php guarded-browser/tests/homesections.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-home-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
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
$user = ['status' => 'active', 'expires_at' => ''];
q("INSERT INTO platform_rules (user_id, platform, mode, created_at) VALUES (?, 'youtube', 'full', ?)", [$kid, nowIso()]);
$yt = fn() => evaluate($user, policyFor($kid), ruleSetFor($kid), new DateTimeImmutable('2026-10-22 12:00'),
                       ['url' => 'https://www.youtube.com/watch?v=abc'])['code'];

echo "\n— ברירת מחדל —\n";
check('ילד חדש — כל הרובריקות', homeSections(policyFor($kid)), HOME_SECTIONS);
check('יוטיוב "הכול מותר" — פתוח', $yt(), 'yt_full');

echo "\n— שמירה —\n";
check('בלי יוטיוב ובלי בקשות', childPolicySave($kid, ['home_sections' => ['search', 'sites', 'apps', 'favorites', 'tasks']]), '');
check('נשמר, בסדר הקבוע', homeSections(policyFor($kid)), ['search', 'sites', 'apps', 'favorites', 'tasks']);
check('רובריקה לא מוכרת — מתעלמים', [childPolicySave($kid, ['home_sections' => ['search', 'casino']]), homeSections(policyFor($kid))], ['', ['search']]);
check('הכול כבוי — ריק (לא "הכול")', [childPolicySave($kid, ['home_sections' => []]), homeSections(policyFor($kid))], ['', []]);
check('הכול דלוק — נשמר כברירת מחדל (ריק)', [childPolicySave($kid, ['home_sections' => HOME_SECTIONS]), (string) policyFor($kid)['home_sections']], ['', '']);

echo "\n— יוטיוב כבוי = סגור —\n";
childPolicySave($kid, ['home_sections' => ['search', 'sites']]);
check('הרובריקה כבויה — יוטיוב חסום, גם כשההגדרה "הכול מותר"', $yt(), 'yt_off');
check('...וההגדרה של ההורה עצמה לא נמחקה', (string) platformRulesFor($kid)['youtube']['mode'], 'full');
$r1 = policyRev($kid);
childPolicySave($kid, ['home_sections' => HOME_SECTIONS]);
check('החזרה — פתוח שוב, והמכשיר מושך מחדש', [$yt(), policyRev($kid) !== $r1], ['yt_full', true]);

echo "\n— היסטוריה —\n";
$snap = policySnapshot($kid); $was = $snap; $was['policy']['home_sections'] = 'search';
check('שינוי מופיע בשם קריא', count(array_filter(policyDiff($was, $snap), fn($l) => str_contains($l, 'רובריקות'))), 1);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
