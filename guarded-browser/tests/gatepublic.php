<?php
/**
 * כל פעולה של המכשיר (api/index.php?do=…) ציבורית בשער (app.json → public) — 2026-10-22.
 *
 *   php guarded-browser/tests/gatepublic.php
 *
 * המכשיר לא מחובר לכלים הדיגיטליים; הוא מזדהה באסימון משלו (requireUser). פעולה שלא ברשימה נחסמת בשער
 * (platform/lib/gate.php, decide) עם 401 — בשקט: הדוח לא מגיע, וההורה רואה "לא דווח". כך קרה ל-7 פעולות
 * (enforce_report, update_state, app_request, app_request_applied, selftest, app_usage, yt_learn_report).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

$cfg = json_decode((string) file_get_contents(__DIR__ . '/../app.json'), true);
$public = [];
foreach ($cfg['public'] as $p) {
    if (preg_match('#^api/index\.php\?do=(.+)$#', $p, $m)) $public = array_merge($public, explode('|', $m[1]));
}
preg_match_all("/\\\$do === '([a-z_]+)'/", (string) file_get_contents(__DIR__ . '/../api/index.php'), $m);
$actions = array_unique($m[1]);
sort($actions);
check('נמצאו פעולות מכשיר בקוד', count($actions) > 10, true);
check('כל פעולה של המכשיר — ציבורית בשער', array_values(array_diff($actions, $public)), []);

// והאפליקציה קוראת רק לפעולות שקיימות בשרת (Api.kt).
preg_match_all('/call\("([a-z_]+)"/', (string) file_get_contents(__DIR__ . '/../android/app/src/main/java/com/mbeplus/guardedbrowser/Api.kt'), $k);
check('כל קריאה מהאפליקציה — קיימת בשרת', array_values(array_diff(array_unique($k[1]), $actions)), []);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
