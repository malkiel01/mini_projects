<?php
/**
 * ה-cron של ההתראות (spec-stage-a 8.2) — CLI בלבד. אין כתובת רשת שמפעילה אותו: הקובץ יוצא מיד כשלא רץ משורת
 * הפקודה, ו-bin/.htaccess חוסם גישה. מותקן בשרת דרך הפריסה (crontab, #1278), כל 15 דקות:
 *
 *     php ~/public_html/mini_projects/trading-strategies/bin/alerts-cron.php >> ~/public_html/mini_projects/trading-strategies/data/alerts-cron.log 2>&1
 *
 * הפלט — שורת JSON אחת לכל ריצה, ללוג ב-data/ (מוחרגת מהפריסה ונעולה ב-.htaccess). הלוג מתקצר לבד: מעל ~1MB נשארות
 * רק 1,000 השורות האחרונות. --now=<unix>, --budget=<ספק>:<n> — לבדיקות (זמן מדומה, תקציב קטן).
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
ts_cron_log_trim();
$sum = ts_alerts_run($now, $budget);
echo json_encode($sum, JSON_UNESCAPED_UNICODE) . "\n";
exit(!empty($sum['locked']) ? 2 : 0);
