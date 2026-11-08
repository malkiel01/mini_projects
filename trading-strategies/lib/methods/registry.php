<?php
/**
 * רשימת השיטות שבקוד (spec-stage-a 6.1, 6.7). שיטה חדשה = קובץ ב-lib/methods/<id>.php + שורה כאן.
 * היא מופיעה בניהול השיטות **כבויה**, עד שמנהל מפעיל.
 *
 * בבדיקות בלבד (TS_DEV + TS_TEST_METHODS=<קובץ>) — אפשר להוסיף שיטות בדיקה, כדי לבדוק התראות ושקלול על שיטה שאינה
 * שיטת דוגמה. בשרת TS_DEV לא מוגדר, והרשימה היא רק TS_METHODS.
 */

declare(strict_types=1);

require_once __DIR__ . '/sample-ma-cross.php';

const TS_METHODS = [
    'sample-ma-cross' => TsMethodSampleMaCross::class,
];

/** id → מחלקה */
function ts_methods_all(): array
{
    static $all = null;
    if ($all !== null) return $all;
    $all = TS_METHODS;
    if (defined('TS_DEV') && ($f = getenv('TS_TEST_METHODS')) && is_file($f)) $all += (require $f);
    return $all;
}

function ts_method_exists(mixed $id): bool
{
    return is_string($id) && isset(ts_methods_all()[$id]);
}

function ts_method(string $id): TsMethod
{
    static $made = [];
    if (!ts_method_exists($id)) throw new InvalidArgumentException('שיטה לא מוכרת');
    return $made[$id] ??= new (ts_methods_all()[$id])();
}
