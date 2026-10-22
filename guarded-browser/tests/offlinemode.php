<?php
/**
 * מצב לא מקוון מתוכנן (שלב 12 ו) — ההגדרה אצל ההורה, ושמירתה. האכיפה במכשיר: OfflineMathTest.
 *
 *   php guarded-browser/tests/offlinemode.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-offmode-' . getmypid();
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
$pol = fn() => policyFor($kid);

echo "\n— ברירת מחדל — כמו שהיה —\n";
childPolicySave($kid, ['daily_quota_min' => 60]);
check('24 שעות, כל מה שמותר', [(int) $pol()['offline_grace_h'], $pol()['offline_content']], [24, 'all']);

echo "\n— שמירה —\n";
check('רק מה שאישרתי, 4 שעות', childPolicySave($kid, ['offline_content' => 'approved', 'offline_grace_h' => 4]), '');
check('...נשמר', [(int) $pol()['offline_grace_h'], $pol()['offline_content']], [4, 'approved']);
check('ערך לא מוכר — נדחה', childPolicySave($kid, ['offline_content' => 'everything']) !== '', true);
check('0 שעות — נדחה', childPolicySave($kid, ['offline_grace_h' => 0]) !== '', true);
check('יותר משלושה ימים — נדחה', childPolicySave($kid, ['offline_grace_h' => 100]) !== '', true);
check('...וההגדרה הקודמת נשארה', [(int) $pol()['offline_grace_h'], $pol()['offline_content']], [4, 'approved']);

echo "\n— היסטוריה —\n";
policyVersionRecord($kid, 0, 'בסיס');
childPolicySave($kid, ['offline_content' => 'none']);
policyVersionRecord($kid, 0, 'child_save');
$h = policyHistory($kid)[0];
check('השינוי מופיע בהיסטוריה בשם קריא', (bool) array_filter($h['changes'], fn($c) => str_contains($c, 'זמין בלי חיבור')), true);

echo "\n— מנוע החיפוש —\n";
check('ברירת מחדל — מגדלור', (string) policyFor($kid)['search_engine'], 'migdalor');
check('גוגל — נשמר', [childPolicySave($kid, ['search_engine' => 'google']), (string) policyFor($kid)['search_engine']], ['', 'google']);
check('מנוע לא מוכר — נדחה', childPolicySave($kid, ['search_engine' => 'bing']), 'מנוע חיפוש לא מוכר');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
