<?php
/**
 * בדיקות קליטת דיווחי מורים (lib/report-ingest.php). נכלל מתוך tests/run.php — משתמש ב-check() שם.
 * בלי רשת. תיקיות זמניות מחוץ לריפו. כל ה-fixtures סינתטיים ("לומד דוגמה").
 */

require_once __DIR__ . '/../lib/report-ingest.php';

$ingTok = 'ingest-test-token-0123456789abcdef';
$ingDir = sys_get_temp_dir() . '/ca-ingest-' . getmypid();
@mkdir($ingDir, 0700);
chmod($ingDir, 0700);
$ingFile = "$ingDir/token";
file_put_contents($ingFile, $ingTok . "\n");
chmod($ingFile, 0600);
$ingOpen = "$ingDir/open-token";   // קריא לכולם — חייב להידחות
file_put_contents($ingOpen, $ingTok);
chmod($ingOpen, 0644);
$iprog = sys_get_temp_dir() . '/ca-ingest-progress-' . getmypid();
@mkdir($iprog, 0700);
chmod($iprog, 0700);

$ingReport = json_decode((string) file_get_contents(__DIR__ . '/fixtures/reports/valid.json'), true);
$ingReport2 = $ingReport;
$ingReport2['taskId'] = 'ACA-DEMO-02';
$ingBad = json_decode((string) file_get_contents(__DIR__ . '/fixtures/reports/broken/has-status.json'), true);

$icfg = array_replace($def, ['progress_dir' => $iprog, 'ingest' => ['token_file' => $ingFile]]);
$IOK = ['HTTP_AUTHORIZATION' => "Bearer $ingTok"];
$I = fn(mixed $body, array $server = [], ?array $cfg = null, string $method = 'POST', string $ct = 'application/json', ?string $raw = null) =>
    academy_api_report_ingest($method, $cfg ?? $icfg, $ct, $raw ?? json_encode($body, JSON_UNESCAPED_UNICODE),
        ['server' => $server + $IOK, 'now' => '2026-11-23T12:00:00Z']);
$body1 = fn(array ...$reports) => ['learnerId' => 'lrn-1', 'reports' => $reports];
$stored = fn(): int => count((academy_progress_load($iprog, 'lrn-1') ?? ['reports' => []])['reports']);

// ---------- אסימון: חייב להיות תקין, מחוץ לאתר, ולא קריא לכולם ----------
check('ingest: בלי ingest.token_file → 503 גנרי', academy_api_report_ingest('POST', $def, 'application/json', '{}', ['server' => $IOK])[0] === 503);
check('ingest: אסימון בקונפיג → נדחה (token_in_config)', academy_ingest_token(array_replace($icfg, ['ingest' => ['token_file' => $ingFile, 'token' => $ingTok]])) === null);
check('ingest: קובץ בתוך public_html → נדחה (secret_in_webroot)', academy_ingest_token(['ingest' => ['token_file' => '/home/u/public_html/tok']]) === null);
check('ingest: קובץ קריא לכולם → נדחה (secret_insecure_perms)', academy_ingest_token(['ingest' => ['token_file' => $ingOpen]]) === null);
check('ingest: קובץ תקין → האסימון נקרא', academy_ingest_token($icfg) === $ingTok);

// ---------- כללי בקשה ----------
check('ingest: GET → 405', $I(null, [], null, 'GET')[0] === 405);
check('ingest: text/plain → 415', $I(null, [], null, 'POST', 'text/plain', '{}')[0] === 415);
check('ingest: Origin → 403 (לא מדפדפן)', $I($body1($ingReport), ['HTTP_ORIGIN' => 'https://mbe-plus.com'])[0] === 403);
check('ingest: Sec-Fetch-Site → 403', $I($body1($ingReport), ['HTTP_SEC_FETCH_SITE' => 'cross-site'])[0] === 403);
check('ingest: אסימון שגוי → 401', $I($body1($ingReport), ['HTTP_AUTHORIZATION' => 'Bearer nope-nope-nope-nope-nope'])[0] === 401);
check('ingest: בלי Authorization → 401', $I($body1($ingReport), ['HTTP_AUTHORIZATION' => ''])[0] === 401);
check('ingest: Authorization בלי Bearer → 401', $I($body1($ingReport), ['HTTP_AUTHORIZATION' => $ingTok])[0] === 401);
check('ingest: JSON שבור → 400', $I(null, [], null, 'POST', 'application/json', '{nope')[0] === 400);
check('ingest: מזהה לומד לא תקין → 400', $I(['learnerId' => '../x', 'reports' => [$ingReport]])[0] === 400);
check('ingest: בלי reports → 400', $I(['learnerId' => 'lrn-1'])[0] === 400);
check('ingest: יותר מ-50 דיווחים → 400', $I($body1(...array_fill(0, 51, $ingReport)))[0] === 400);
check('ingest: שגיאות אינן מכילות את האסימון', !str_contains(json_encode($I($body1($ingBad))[1], JSON_UNESCAPED_UNICODE), $ingTok));

// ---------- קליטה, כפילויות, קבוצה מעורבת ----------
$before = $stored();
[$st, $res] = $I($body1($ingBad));
check('ingest: דיווח לא תקין (has-status) → invalid, לא נכתב', $st === 200 && $res['results'][0]['status'] === 'invalid' && $stored() === $before);

[$st, $res] = $I($body1($ingReport));
check('ingest: דיווח תקין → stored', $st === 200 && $res['results'][0]['status'] === 'stored' && $res['results'][0]['reportId'] === $ingReport['taskId'] . '-T' . $ingReport['teacherId']);
check('ingest: הדיווח נשמר בהתקדמות של הלומד', $stored() === $before + 1);

[$st, $res] = $I($body1($ingReport));
check('ingest: אותו דיווח שוב → duplicate, לא נכתב שוב', $st === 200 && $res['results'][0]['status'] === 'duplicate' && $stored() === $before + 1);
check('ingest: כפילות נרשמת ביומן', str_contains((string) @file_get_contents("$iprog/audit.log"), 'report-duplicate'));

[$st, $res] = $I($body1($ingBad, $ingReport2, $ingReport));
$statuses = array_column($res['results'], 'status', 'index');
check('ingest: קבוצה מעורבת — invalid, stored, duplicate לפי הסדר',
    $st === 200 && ($statuses[0] ?? null) === 'invalid' && ($statuses[1] ?? null) === 'stored' && ($statuses[2] ?? null) === 'duplicate');
check('ingest: בקבוצה מעורבת נכתב רק הדיווח החדש', $stored() === $before + 2);

// ---------- רישום יחיד: התשובה לא מדליפה תוכן דיווח ----------
check('ingest: תשובה לא מכילה את תוכן הממצאים', !str_contains(json_encode($I($body1($ingReport2))[1], JSON_UNESCAPED_UNICODE), (string) ($ingReport['findings'][0] ?? '~~')));
