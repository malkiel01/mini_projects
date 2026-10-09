<?php
/**
 * העובד של אינדקס החיפוש (lib/web_index.php): מעדכן אתרים שהגיע זמנם, חלק
 * sitemap אחד בכל צעד, עד כמה דקות, ואז מעיר עותק חדש של עצמו.
 * נקרא מהוספת אתר, מ"רענן עכשיו", משומר-הסף ב-api.php, ומ-cron אם הוגדר.
 *   index-worker.php?key=…          ריצה רגילה
 *   index-worker.php?key=…&once=1   צעד אחד (בדיקות)
 * המפתח — אותו מפתח של עובד הסורק (scout_job.worker_key).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/web_index.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$job = scoutJobRow();
$key = (string) ($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string) $job['worker_key'], $key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}
if (!empty($_GET['once'])) {
    echo indexWorkerRun(true), "\n";
    exit;
}

ignore_user_abort(true);
@set_time_limit(INDEX_WORKER_BUDGET + 120);
$out = "ok\n";
if (function_exists('fastcgi_finish_request')) { echo $out; fastcgi_finish_request(); }
elseif (function_exists('litespeed_finish_request')) { echo $out; litespeed_finish_request(); }
else {
    header('Connection: close');
    header('Content-Length: ' . strlen($out));
    echo $out;
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
}
indexWorkerRun(false);
