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
require_once __DIR__ . '/elliott-w3.php';
require_once __DIR__ . '/defined.php';

const TS_METHODS = [
    'sample-ma-cross' => TsMethodSampleMaCross::class,
    'elliott-w3' => TsMethodElliottW3::class,   // spec-stage-a 17.1.9 — כבויה עד שמנהל מפעיל
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

/**
 * שיטות מוגדרות (17.2, ✅19) — מהמסד (methods.kind = 'defined'), לצד שיטות הקוד. נטענות רק כשמבקשים שיטה שאינה בקוד,
 * כך שבדיקות טהורות (בלי מסד) לא נוגעות במסד. id → [definition, definition_version].
 */
function ts_defined_rows(bool $reload = false): array
{
    static $rows = null;
    if ($reload) $rows = null;
    if ($rows !== null) return $rows;
    $rows = [];
    if (!function_exists('ts_db')) return $rows;
    foreach (ts_db()->query("SELECT id, definition, definition_version FROM methods WHERE kind = 'defined' AND definition IS NOT NULL ORDER BY id") as $r) {
        $rows[$r['id']] = [json_decode($r['definition'], true), (int)$r['definition_version']];
    }
    return $rows;
}

/** כל המזהים: שיטות הקוד, ואחריהן המוגדרות */
function ts_method_ids(): array
{
    return array_merge(array_keys(ts_methods_all()), array_keys(ts_defined_rows()));
}

function ts_method_is_code(mixed $id): bool
{
    return is_string($id) && isset(ts_methods_all()[$id]);
}

function ts_method_exists(mixed $id): bool
{
    return is_string($id) && (isset(ts_methods_all()[$id]) || isset(ts_defined_rows()[$id]));
}

/** המופעים שכבר נבנו בבקשה הזו (id → TsMethod) */
function &ts_method_cache(): array
{
    static $made = [];
    return $made;
}

/** אחרי שמירת הגדרה — שהבקשה הנוכחית תראה את הגרסה החדשה */
function ts_method_forget(): void
{
    ts_defined_rows(true);
    $made = &ts_method_cache();
    $made = [];
}

function ts_method(string $id): TsMethod
{
    $made = &ts_method_cache();
    if (isset($made[$id])) return $made[$id];
    if (isset(ts_methods_all()[$id])) return $made[$id] = new (ts_methods_all()[$id])();
    $row = ts_defined_rows()[$id] ?? throw new InvalidArgumentException('שיטה לא מוכרת');
    return $made[$id] = new TsDefinedMethod(['version' => $row[1]] + $row[0]);
}
