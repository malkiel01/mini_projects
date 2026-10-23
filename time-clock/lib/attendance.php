<?php
/**
 * נוכחות — הפעולות של העובד (מהטלפון) ושל המנהל (המסך "היום" והחודש של עובד). שלב 3.
 */

declare(strict_types=1);

require_once __DIR__ . '/overtime.php';
require_once __DIR__ . '/session.php';

/** החברוּת של העובד מתוך העוגייה — רק אחת שהמכשיר הזה מחובר אליה. */
function sessionMember(int $memberId): array {
    foreach (sessionMembers() as $m) if ((int) $m['id'] === $memberId) return $m;
    throw new AppError('המכשיר הזה לא מחובר. פתחו את הקישור שהמנהל שלח.', 401);
}

function empPunch(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $p = recordPunch((int) $m['id'], str($in, 'kind', 3), 'phone', (int) $m['device_id']);
    return ['punch' => $p, 'status' => nowStatus((int) $m['id'], $m['tz'])];
}

function empMonth(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $month = str($in, 'month', 7) ?: substr(todayIn($m['tz']), 0, 7);
    return monthReport((int) $m['id'], $month, $m['tz']) + ['period' => empPeriodInfo((int) $m['company_id'], (int) $m['id'], $month)];
}

/** המסך "היום" של המנהל: מי בעבודה, מי לא דיווח ביום עבודה, ומי שכח לצאת אתמול. מנהל סניף — הסניף שלו. */
function todayBoard(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_VIEW);
    $tz = companyRow($companyId)['tz'];
    $sql = "SELECT m.id, m.site_id, m.employee_no, p.name FROM members m JOIN people p ON p.id = m.person_id
            WHERE m.company_id = ? AND m.role = 'employee' AND m.status = 'active'" . ($site !== null ? ' AND m.site_id = ?' : '') . ' ORDER BY p.name';
    $st = db()->prepare($sql);
    $st->execute($site !== null ? [$companyId, $site] : [$companyId]);
    $today = todayIn($tz);
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $rows = []; $count = ['in' => 0, 'none' => 0, 'missing' => 0];
    foreach ($st->fetchAll() as $e) {
        $s = nowStatus((int) $e['id'], $tz);
        $y = daysReport((int) $e['id'], $yesterday, $yesterday, $tz)[0];
        $state = $s['open_since'] ? 'in' : ($s['punches'] ? 'done' : ($s['standard_today'] > 0 ? 'none' : 'off'));
        $missing = $y['missing_out'] + $y['missing_in'];
        if ($state === 'in') $count['in']++;
        if ($state === 'none') $count['none']++;
        if ($missing) $count['missing']++;
        $first = $s['punches'][0]['at'] ?? null;
        $rows[] = ['id' => (int) $e['id'], 'name' => $e['name'], 'site_id' => $e['site_id'] === null ? null : (int) $e['site_id'],
                   'state' => $state, 'since' => $s['open_since'], 'first' => $first, 'minutes' => $s['minutes_today'],
                   'standard' => $s['standard_today'], 'missing_yesterday' => $missing > 0];
    }
    return ['today' => $today, 'count' => $count, 'employees' => $rows, 'site_only' => $site];
}

/** החודש של עובד — לכרטיס העובד אצל המנהל. */
function empMonthManager(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_VIEW);
    $e = empRow($companyId, intIn($in, 'id'), $site);
    $tz = companyRow($companyId)['tz'];
    $month = str($in, 'month', 7) ?: substr(todayIn($tz), 0, 7);
    return monthReport((int) $e['id'], $month, $tz);
}
