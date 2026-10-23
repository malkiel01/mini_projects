<?php
/**
 * חגים לפי דת העובד (גרסה 2; האפיון: docs/v2-overtime-holidays.md פרק 5).
 *
 * - יהודי: לוח מובנה, מחושב מהלוח העברי (tools/gen-holidays.php ← lib/holidays/jewish.json) — ימי המנוחה
 *   של סעיף 18א לפקודת סדרי השלטון והמשפט ויום העצמאות.
 * - מוסלמי, נוצרי, דרוזי, אחר: החגים נקבעים בהחלטת ממשלה, ולא מצאנו רשימה רשמית של תאריכים (החגים
 *   המוסלמיים גם תלויים בראיית הירח). לכן החברה מזינה אותם: "+ יום" עם הדתות שהוא חל עליהן.
 * - ימים של החברה חלים בנוסף ללוח המובנה.
 */

declare(strict_types=1);

require_once __DIR__ . '/overtime.php';

function jewishHolidays(): array {
    static $days = null;
    if ($days === null) $days = json_decode((string) file_get_contents(__DIR__ . '/holidays/jewish.json'), true)['days'] ?? [];
    return $days;
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
    return $religion === 'jewish' ? (jewishHolidays()[$date] ?? null) : null;
}

/** הלוח של שנה: המובנה (יהודי) וימי החברה. */
function holidaysGet(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, MANAGE_ROLES);
    $year = intIn($in, 'year') ?: (int) substr(todayIn(companyRow($companyId)['tz']), 0, 4);
    if ($year < 2000 || $year > 2100) throw new AppError('שנה לא תקינה');
    $built = array_filter(jewishHolidays(), fn($d) => str_starts_with($d, (string) $year), ARRAY_FILTER_USE_KEY);
    return ['year' => $year, 'jewish' => array_map(fn($d, $n) => ['date' => $d, 'name' => $n], array_keys($built), $built),
            'company' => array_map(fn($h) => ['id' => (int) $h['id'], 'date' => $h['date'], 'name' => $h['name'],
                'religions' => $h['religions'] === 'all' ? 'all' : explode(',', $h['religions'])], companyHolidays($companyId, "$year-01-01", "$year-12-31")),
            'religions' => RELIGIONS, 'can_edit' => in_array($me['role'], ['owner', 'manager'], true),
            'built_in_until' => max(array_keys(jewishHolidays()))];
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
