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
 * כל המזהים — שיטות הקוד בלבד. בונה השיטות נמחק (21.1, ✅29): שורות ישנות של methods.kind = 'defined' נשארות במסד
 * (וגם method_versions), אבל לא נטענות — השיטה לא קיימת לאפליקציה, ומה שמפנה אליה מתנהג כמו אל שיטה שהוסרה.
 */
function ts_method_ids(): array
{
    return array_keys(ts_methods_all());
}

function ts_method_is_code(mixed $id): bool
{
    return is_string($id) && isset(ts_methods_all()[$id]);
}

function ts_method_exists(mixed $id): bool
{
    return is_string($id) && isset(ts_methods_all()[$id]);
}

/** המופעים שכבר נבנו בבקשה הזו (id → TsMethod) */
function &ts_method_cache(): array
{
    static $made = [];
    return $made;
}

/** אחרי שמירת הגדרות או החלה (tune) — שהבקשה הנוכחית תראה את הגרסה החדשה */
function ts_method_forget(): void
{
    $made = &ts_method_cache();
    $made = [];
}

function ts_method(string $id): TsMethod
{
    $made = &ts_method_cache();
    if (isset($made[$id])) return $made[$id];
    $cls = ts_methods_all()[$id] ?? throw new InvalidArgumentException('שיטה לא מוכרת');
    $m = new $cls();
    $m->tune = ts_method_tune($id);
    return $made[$id] = $m;
}

/**
 * methods.tune של השיטה (18.7). בלי קובץ מסד — אף החלה לא קרתה, ובדיקות טהורות לא פותחות מסד.
 */
function ts_method_tune(string $id): int
{
    if (!function_exists('ts_db') || !is_file(ts_db_path())) return 0;
    $st = ts_db()->prepare('SELECT tune FROM methods WHERE id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}
