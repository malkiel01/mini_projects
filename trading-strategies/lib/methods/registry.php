<?php
/**
 * רשימת השיטות שבקוד (spec-stage-a 6.1, 6.7). שיטה חדשה = קובץ ב-lib/methods/<id>.php + שורה כאן.
 * היא מופיעה בניהול השיטות **כבויה**, עד שמנהל מפעיל.
 */

declare(strict_types=1);

require_once __DIR__ . '/sample-ma-cross.php';

const TS_METHODS = [
    'sample-ma-cross' => TsMethodSampleMaCross::class,
];

function ts_method(string $id): TsMethod
{
    static $made = [];
    if (!isset(TS_METHODS[$id])) throw new InvalidArgumentException('שיטה לא מוכרת');
    return $made[$id] ??= new (TS_METHODS[$id])();
}
