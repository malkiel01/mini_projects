<?php
/**
 * עוזרים משותפים לכל ה-lib: קריאת קלט, תאריך ושעון ישראל. אין כאן לוגיקה של נושא (עובדים, חברות…).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** אזור הזמן של החברות. נשמר גם כברירת מחדל בעמודה companies.tz (lib/db.php). */
const DEFAULT_TZ = 'Asia/Jerusalem';

function str(array $in, string $key, int $max = 120): string {
    $v = $in[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function intIn(array $in, string $key): int {
    $v = $in[$key] ?? 0;
    return is_numeric($v) ? (int) $v : 0;
}

function validDate(string $d, string $label): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
        throw new AppError($label . ' — תאריך לא תקין');
    }
    return $d;
}

/** היום בשעון החברה — "מאיזה תאריך" נקבע לפי ישראל, לא לפי UTC. */
function todayIn(string $tz = DEFAULT_TZ): string {
    return (new DateTimeImmutable(nowIso()))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
}
