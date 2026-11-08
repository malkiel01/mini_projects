<?php
/**
 * ה-cron של ההתראות (spec-stage-a 8.2) — CLI בלבד. אין כתובת רשת שמפעילה אותו: הקובץ יוצא מיד כשלא רץ משורת
 * הפקודה, ו-bin/.htaccess חוסם גישה. מגדירים ב-cPanel (בעל המערכת), כל 15 דקות:
 *
 *     php /home/<user>/public_html/mini_projects/trading-strategies/bin/alerts-cron.php
 *
 * --now=<unix>, --budget=<ספק>:<n> — לבדיקות (זמן מדומה, תקציב קטן). הפלט — שורת JSON אחת לסיכום, ללוג של ה-cron.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

unset($_SERVER['PLATFORM_USER']);   // אין משתמש: ה-cron לא פועל בשם אף אחד
require_once dirname(__DIR__) . '/lib/alerts.php';

$now = null; $budget = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--now=(\d{9,11})$/', $arg, $m)) $now = (int)$m[1];
    if (preg_match('/^--budget=([a-z0-9]+):(\d{1,6})$/', $arg, $m)) $budget[$m[1]] = (int)$m[2];
}
$sum = ts_alerts_run($now, $budget);
echo json_encode($sum, JSON_UNESCAPED_UNICODE) . "\n";
exit(!empty($sum['locked']) ? 2 : 0);
