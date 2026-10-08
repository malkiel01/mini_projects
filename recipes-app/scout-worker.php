<?php
/**
 * העובד של הייבוא ברקע (lib/scout_job.php). נקרא מ:
 *   - "התחל ייבוא ברקע" בסורק,
 *   - עצמו — בסוף כל תקציב זמן הוא מעיר עותק חדש (שרשרת),
 *   - שומר-הסף ב-api.php, כשהשרשרת נקטעה,
 *   - cron של cPanel, אם הוגדר (הכתובת מוצגת בסורק, למפתח בלבד).
 *
 * עונה מיד וממשיך לעבוד אחרי שהחיבור נסגר. בלי המפתח הנכון — 403.
 *   scout-worker.php?key=…          ריצה רגילה (עד כמה דקות, ואז שרשור)
 *   scout-worker.php?key=…&once=1   צעד אחד, בלי שינה ובלי שרשור — לבדיקה
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/scout_job.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$key = (string) ($_GET['key'] ?? '');
$job = scoutJobRow();
if ($key === '' || !hash_equals((string) $job['worker_key'], $key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}
$once = !empty($_GET['once']);

if ($once) {
    echo scoutWorkerRun(true), "\n";
    exit;
}

// לענות מיד ולהמשיך: מי שקרא (הדפדפן, השרשרת, cron) לא מחכה לייבוא
ignore_user_abort(true);
@set_time_limit(SCOUT_WORKER_BUDGET + 120);
$out = $job['state'] . "\n";
if (function_exists('fastcgi_finish_request')) {          // PHP-FPM
    echo $out;
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {  // LiteSpeed (נפוץ באחסון משותף)
    echo $out;
    litespeed_finish_request();
} else {
    header('Connection: close');
    header('Content-Length: ' . strlen($out));
    echo $out;
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
}
scoutWorkerRun(false);
