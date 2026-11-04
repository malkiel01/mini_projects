<?php
/**
 * חגים לפי דת העובד (גרסה 2; האפיון: docs/v2-overtime-holidays.md פרק 5, docs/v3b-cleanup.md חלק ב).
 *
 * לכל דת לוח מובנה בקובץ lib/holidays/<דת>.json: {"religion", "source", "verified_at", "days": {תאריך: שם}}.
 * - יהודי: מחושב מהלוח העברי (tools/gen-holidays.php) — ימי המנוחה של סעיף 18א לפקודת סדרי השלטון והמשפט ויום העצמאות.
 * - מוסלמי, נוצרי, דרוזי: הקבצים מתמלאים מפרסום רשמי (tools/import-holidays.php), עם מקור ותאריך אימות.
 *   חגים אלה נקבעים בהחלטת ממשלה, והמוסלמיים והדרוזיים גם בראיית הירח — לכן מתעדכנים ידנית, מדי שנה.
 * - אחר: אין לוח מובנה.
 * - ימים של החברה חלים בנוסף ללוח המובנה.
 * שורש הקבצים אפשר להחליף בבדיקות עם HOLIDAYS_DIR.
 */

declare(strict_types=1);

require_once __DIR__ . '/overtime.php';

function holidaysDir(): string {
    return defined('HOLIDAYS_DIR') ? HOLIDAYS_DIR : __DIR__ . '/holidays';
}

/** הלוח המובנה של דת: ['source', 'verified_at', 'days'], או לוח ריק אם אין קובץ. נטען פעם אחת לדת. */
function builtinCalendar(string $religion): array {
    static $cache = [];
    if (!isset($cache[$religion])) {
        $file = holidaysDir() . '/' . $religion . '.json';
        $d = isset(RELIGIONS[$religion]) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $d = is_array($d) ? $d : [];
        $cache[$religion] = ['source' => $d['source'] ?? null, 'verified_at' => $d['verified_at'] ?? null,
                             'days' => is_array($d['days'] ?? null) ? $d['days'] : []];
    }
    return $cache[$religion];
}

/** מצב כל הלוחות המובנים: כמה ימים, טווח, מקור ואימות — להצגה ולאזהרות. */
function calendarsInfo(): array {
    $out = [];
    foreach (RELIGIONS as $rel => $label) {
        $c = builtinCalendar($rel);
        $dates = array_keys($c['days']);
        sort($dates);
        $out[$rel] = ['label' => $label, 'count' => count($dates), 'first' => $dates[0] ?? null, 'last' => $dates ? end($dates) : null,
                      'source' => $c['source'], 'verified_at' => $c['verified_at'], 'has_file' => $rel !== 'other'];
    }
    return $out;
}

/** ימי החברה בטווח: [date => [[name, religions[]], …]]. */
function companyHolidays(int $companyId, string $from, string $to): array {
    $st = db()->prepare('SELECT id, date, name, religions FROM company_holidays WHERE company_id = ? AND date BETWEEN ? AND ? ORDER BY date');
    $st->execute([$companyId, $from, $to]);
    return $st->fetchAll();
}

/** החג של עובד מהדת הזו ביום הזה — שם, או null. (נקרא מ-overtime.php: holidayName.) */
function holidayOn(int $companyId, string $religion, string $date): ?string {
    // בלי מטמון: הסיווג שואל פעם אחת לכל תאריך (overtime.php), והחברה יכולה להוסיף יום באמצע.
    foreach (companyHolidays($companyId, $date, $date) as $h) {
        $rel = $h['religions'] === 'all' ? null : explode(',', $h['religions']);
        if ($rel === null || in_array($religion, $rel, true)) return $h['name'];
    }
    return builtinCalendar($religion)['days'][$date] ?? null;
}

/** הלוח של שנה: הלוחות המובנים לכל דת, מצבם, וימי החברה. */
function holidaysGet(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, MANAGE_ROLES);
    $year = intIn($in, 'year') ?: (int) substr(companyToday($companyId), 0, 4);
    if ($year < 2000 || $year > 2100) throw new AppError('שנה לא תקינה');
    $builtin = [];
    foreach (RELIGIONS as $rel => $_) {
        $days = array_filter(builtinCalendar($rel)['days'], fn($d) => str_starts_with($d, (string) $year), ARRAY_FILTER_USE_KEY);
        ksort($days);
        $builtin[$rel] = array_map(fn($d, $n) => ['date' => $d, 'name' => $n], array_keys($days), $days);
    }
    return ['year' => $year, 'builtin' => $builtin, 'calendars' => calendarsInfo(),
            'company' => array_map(fn($h) => ['id' => (int) $h['id'], 'date' => $h['date'], 'name' => $h['name'],
                'religions' => $h['religions'] === 'all' ? 'all' : explode(',', $h['religions'])], companyHolidays($companyId, "$year-01-01", "$year-12-31")),
            'religions' => RELIGIONS, 'can_edit' => in_array($me['role'], ['owner', 'manager'], true)];
}

function holidayAdd(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner', 'manager']);
    $date = validDate(str($in, 'date', 10), 'תאריך');
    $name = str($in, 'name', 60);
    if ($name === '') throw new AppError('חסר שם לחג');
    $rel = $in['religions'] ?? 'all';
    if ($rel !== 'all') {
        if (!is_array($rel) || !$rel) throw new AppError('לאילו דתות?');
        foreach ($rel as $r) if (!isset(RELIGIONS[$r])) throw new AppError('דת לא מוכרת');
        $rel = implode(',', array_values(array_unique($rel)));
    }
    guardLockedDate($companyId, $date);
    db()->prepare('INSERT INTO company_holidays (company_id, date, name, religions, created_by, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$companyId, $date, $name, $rel, $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'holiday.add', 'company_holidays:' . db()->lastInsertId(), null, ['date' => $date, 'name' => $name, 'religions' => $rel]);
    return holidaysGet($person, $in + ['year' => (int) substr($date, 0, 4)]);
}

function holidayDelete(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner', 'manager']);
    $st = db()->prepare('SELECT * FROM company_holidays WHERE id = ? AND company_id = ?');
    $st->execute([intIn($in, 'id'), $companyId]);
    $h = $st->fetch();
    if (!$h) throw new AppError('היום לא נמצא', 404);
    guardLockedDate($companyId, $h['date']);
    db()->prepare('DELETE FROM company_holidays WHERE id = ?')->execute([$h['id']]);
    audit($companyId, (int) $person['id'], 'holiday.delete', 'company_holidays:' . $h['id'], ['date' => $h['date'], 'name' => $h['name']], null);
    return holidaysGet($person, $in + ['year' => (int) substr($h['date'], 0, 4)]);
}

/** חג בחודש נעול משנה סיווג של חודש שכבר אושר — נחסם, כמו תיקון. */
function guardLockedDate(int $companyId, string $date): void {
    if (function_exists('guardLocked')) guardLocked($companyId, [substr($date, 0, 7)]);
}
