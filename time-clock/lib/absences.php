<?php
/**
 * היעדרויות (גרסה 3, שלב 1; האפיון: docs/v3-absences.md).
 *
 * - סוגים: חופשה, מחלה, מילואים, ועוד סוגים של החברה. לכל סוג — מאיזו יתרה הוא מוריד (היתרות — שלב 2).
 * - עובד מבקש מהטלפון; מנהל (בעלים, מנהל, מנהל סניף לסניף שלו) מאשר או דוחה, או רושם ישירות — מאושר מיד.
 * - היחידה — החלטת הבעלים: יום מלא בלבד, יום או חצי יום, או שעות ליום.
 * - בחישוב: יום היעדרות מאושר זוקף את התקן שלו (כולו, חציו, או השעות), ורק בימי עבודה — ימים עם תקן שאינם
 *   חג. הדיווחים באותו יום ממשיכים להיספר.
 * - חודש נעול — אין בקשות ואין החלטות. החלטה בחודש שבאישור מבטלת את האישורים של העובד בו, כמו תיקון.
 */

declare(strict_types=1);

require_once __DIR__ . '/attendance.php';

const ABS_BUILTIN = [
    'vacation' => ['name' => 'חופשה', 'icon' => '🌴', 'deducts' => 'vacation'],
    'sick'     => ['name' => 'מחלה', 'icon' => '🤒', 'deducts' => 'sick'],
    'reserve'  => ['name' => 'מילואים', 'icon' => '🪖', 'deducts' => null],
];
const ABS_UNITS = ['day', 'half', 'hours'];
// vacation_table: law | extension (שבוע של 5 ימים; ב-6 ימים — החוק). sick_monthly / sick_max — בימים.
const ABS_DEFAULTS = ['unit' => 'half', 'vacation_table' => 'extension', 'sick_monthly' => 1.5, 'sick_max' => 90, 'cert_years' => 7];
const ABS_MAX_DAYS = 92;

/* ===== הגדרות וסוגים ===== */

function absSettings(int $companyId): array {
    $st = db()->prepare('SELECT settings FROM absence_settings WHERE company_id = ?');
    $st->execute([$companyId]);
    $s = $st->fetchColumn();
    return array_merge(ABS_DEFAULTS, $s ? (json_decode($s, true) ?: []) : []);
}

/** הסוגים של החברה: המובנים, ואחריהם של החברה. $all — גם סוגים שהושבתו (להצגת היסטוריה). */
function absTypes(int $companyId, bool $all = false): array {
    $out = [];
    foreach (ABS_BUILTIN as $k => $t) $out[$k] = ['key' => $k, 'builtin' => true, 'active' => true] + $t;
    $st = db()->prepare('SELECT * FROM absence_types WHERE company_id = ? ORDER BY id');
    $st->execute([$companyId]);
    foreach ($st->fetchAll() as $t) {
        if (!$all && !$t['active']) continue;
        $out['t' . $t['id']] = ['key' => 't' . $t['id'], 'builtin' => false, 'active' => (bool) $t['active'], 'id' => (int) $t['id'],
                                'name' => $t['name'], 'icon' => $t['icon'], 'deducts' => $t['deducts']];
    }
    return $out;
}

function absSettingsGet(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, MANAGE_ROLES);
    if (function_exists('certPurge')) certPurge($companyId);   // משך השמירה של האישורים
    return ['settings' => absSettings($companyId), 'types' => array_values(absTypes($companyId, true)), 'can_edit' => $me['role'] === 'owner'];
}

function absSettingsSave(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $before = absSettings($companyId);
    $s = $before;
    if (array_key_exists('unit', $in)) {
        $s['unit'] = str($in, 'unit', 5);
        if (!in_array($s['unit'], ABS_UNITS, true)) throw new AppError('יחידה לא מוכרת');
    }
    if (array_key_exists('vacation_table', $in)) {
        $s['vacation_table'] = str($in, 'vacation_table', 10);
        if (!in_array($s['vacation_table'], ['law', 'extension'], true)) throw new AppError('טבלת חופשה לא מוכרת');
    }
    foreach (['sick_monthly' => [0, 5], 'sick_max' => [0, 365], 'cert_years' => [1, 50]] as $k => [$lo, $hi]) {
        if (!array_key_exists($k, $in)) continue;
        if (!is_numeric($in[$k]) || $in[$k] < $lo || $in[$k] > $hi) throw new AppError("ערך לא תקין: $k");
        $s[$k] = round((float) $in[$k], 2);
    }
    db()->prepare('INSERT INTO absence_settings (company_id, settings, updated_by, updated_at) VALUES (?,?,?,?)
        ON CONFLICT (company_id) DO UPDATE SET settings = excluded.settings, updated_by = excluded.updated_by, updated_at = excluded.updated_at')
        ->execute([$companyId, json_encode($s), $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'absence_settings.save', 'companies:' . $companyId, $before, $s);
    return absSettingsGet($person, $in);
}

/** סוג של החברה: הוספה (בלי id) או עדכון. סוג לא נמחק — מושבת, כדי שהיעדרויות ישנות יישארו עם שם. */
function absTypeSave(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $name = str($in, 'name', 30);
    if ($name === '') throw new AppError('חסר שם לסוג');
    $icon = str($in, 'icon', 4) ?: '📌';
    $deducts = $in['deducts'] ?? null;
    if ($deducts === '') $deducts = null;
    if ($deducts !== null && !in_array($deducts, ['vacation', 'sick'], true)) throw new AppError('יתרה לא מוכרת');
    $active = !array_key_exists('active', $in) || !empty($in['active']);
    if ($id = intIn($in, 'id')) {
        $st = db()->prepare('SELECT * FROM absence_types WHERE id = ? AND company_id = ?');
        $st->execute([$id, $companyId]);
        $before = $st->fetch();
        if (!$before) throw new AppError('הסוג לא נמצא', 404);
        db()->prepare('UPDATE absence_types SET name = ?, icon = ?, deducts = ?, active = ? WHERE id = ?')->execute([$name, $icon, $deducts, (int) $active, $id]);
    } else {
        $before = null;
        db()->prepare('INSERT INTO absence_types (company_id, name, icon, deducts, active, created_at) VALUES (?,?,?,?,1,?)')
            ->execute([$companyId, $name, $icon, $deducts, nowIso()]);
        $id = (int) db()->lastInsertId();
    }
    audit($companyId, (int) $person['id'], 'absence_type.save', 'absence_types:' . $id, $before ?: null,
          ['name' => $name, 'icon' => $icon, 'deducts' => $deducts, 'active' => $active]);
    return absSettingsGet($person, $in);
}

/* ===== ימי עבודה וזיכוי ===== */

function memberRow(int $memberId): array {
    $st = db()->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$memberId]);
    return $st->fetch() ?: throw new AppError('העובד לא נמצא', 404);
}

/** כמה דקות היעדרות זוקפת ביום: 0 ביום בלי תקן או בחג. */
function absCredit(array $a, array $member, string $date): int {
    $std = standardMinutes((int) $member['id'], $date);
    if (!$std || holidayName((int) $member['company_id'], $member['religion'] ?? null, $date)) return 0;
    return match ($a['part']) {
        'half'  => intdiv($std, 2),
        'hours' => min($std, (int) $a['minutes']),
        default => $std,
    };
}

/** ימי העבודה בטווח, וכמה דקות הם זוקפים. */
function absSpan(array $a, array $member): array {
    $days = 0; $minutes = 0;
    for ($d = new DateTimeImmutable($a['from_date']); $d->format('Y-m-d') <= $a['to_date']; $d = $d->modify('+1 day')) {
        $c = absCredit($a, $member, $d->format('Y-m-d'));
        if ($c) { $days++; $minutes += $c; }
    }
    return ['workdays' => $days, 'credit' => $minutes];
}

function absMonths(string $from, string $to): array {
    $out = [];
    for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = (new DateTimeImmutable($m . '-01'))->modify('+1 month')->format('Y-m'))
        $out[] = $m;
    return $out;
}

/**
 * ההיעדרויות של עובד בטווח, לכל יום: [date => [...]]. מאושרת — עם הזיכוי; ממתינה — רק לסימון.
 * נקרא מ-monthReport (hours.php) דרך function_exists, כמו החגים.
 */
function absenceDays(int $memberId, string $from, string $to): array {
    $member = memberRow($memberId);
    $types = absTypes((int) $member['company_id'], true);
    $st = db()->prepare("SELECT * FROM absences WHERE member_id = ? AND status IN ('approved','pending') AND from_date <= ? AND to_date >= ? ORDER BY status, id");
    $st->execute([$memberId, $to, $from]);
    $out = [];
    foreach ($st->fetchAll() as $a) {
        $t = $types[$a['type']] ?? ['name' => 'היעדרות', 'icon' => '📌'];
        for ($d = new DateTimeImmutable(max($a['from_date'], $from)); $d->format('Y-m-d') <= min($a['to_date'], $to); $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            if (isset($out[$k]) && $out[$k]['status'] === 'approved') continue;   // מאושרת גוברת על ממתינה
            $credit = absCredit($a, $member, $k);
            if (!$credit && $a['status'] === 'approved') continue;               // לא יום עבודה — לא מסמנים
            $out[$k] = ['id' => (int) $a['id'], 'type' => $a['type'], 'name' => $t['name'], 'icon' => $t['icon'], 'part' => $a['part'],
                        'status' => $a['status'], 'credit' => $a['status'] === 'approved' ? $credit : 0];
        }
    }
    return $out;
}

/* ===== בקשה ורישום ===== */

/** בדיקת היעדרות ובניית השורה. */
function absenceFrom(array $member, string $tz, array $in): array {
    $company = (int) $member['company_id'];
    $types = absTypes($company);
    $type = str($in, 'type', 12);
    if (!isset($types[$type])) throw new AppError('סוג היעדרות לא מוכר');
    $from = validDate(str($in, 'from', 10), 'מתאריך');
    $to = validDate(str($in, 'to', 10) ?: $from, 'עד תאריך');
    if ($to < $from) throw new AppError('"עד" לפני "מ"');
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= ABS_MAX_DAYS) throw new AppError('עד ' . ABS_MAX_DAYS . ' ימים בבקשה אחת');
    if ($type === 'sick' && $from > todayIn($tz)) throw new AppError('על מחלה מדווחים ביום עצמו או אחריו');
    $unit = absSettings($company)['unit'];
    $part = str($in, 'part', 5) ?: 'full';
    $allowed = ['day' => ['full'], 'half' => ['full', 'half'], 'hours' => ['full', 'hours']][$unit];
    if (!in_array($part, $allowed, true)) throw new AppError('ההגדרה של החברה לא מאפשרת את המשך הזה');
    $minutes = null;
    if ($part === 'hours') {
        $minutes = intIn($in, 'minutes');
        if ($minutes < 15 || $minutes > 720) throw new AppError('כמה שעות? בין 0:15 ל-12:00');
    }
    $note = str($in, 'note', 200);
    $row = ['type' => $type, 'from_date' => $from, 'to_date' => $to, 'part' => $part, 'minutes' => $minutes, 'note' => $note ?: null];
    if (!absSpan($row, $member)['workdays']) throw new AppError('אין ימי עבודה בטווח הזה');
    $st = db()->prepare("SELECT COUNT(*) FROM absences WHERE member_id = ? AND status IN ('pending','approved') AND from_date <= ? AND to_date >= ?");
    $st->execute([$member['id'], $to, $from]);
    if ((int) $st->fetchColumn() > 0) throw new AppError('כבר יש היעדרות (או בקשה) בחלק מהימים האלה');
    return $row;
}

function insertAbsence(int $memberId, array $row, ?int $byMember, ?int $byPerson, bool $approved): int {
    $now = nowIso();
    db()->prepare('INSERT INTO absences (member_id, type, from_date, to_date, part, minutes, note, by_member, by_person, status, decided_by, decided_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$memberId, $row['type'], $row['from_date'], $row['to_date'], $row['part'], $row['minutes'], $row['note'], $byMember, $byPerson,
                   $approved ? 'approved' : 'pending', $approved ? $byPerson : null, $approved ? $now : null, $now]);
    return (int) db()->lastInsertId();
}

/** כמה ימים בקשה מורידה מהיתרה (ימי עבודה מלאים, חצאים, או שעות חלקי התקן). */
function absDays(array $a): float {
    $member = memberRow($a['member_id']);
    $row = ['part' => $a['part'], 'minutes' => $a['minutes']];
    $sum = 0.0;
    for ($d = new DateTimeImmutable($a['from']); $d->format('Y-m-d') <= $a['to']; $d = $d->modify('+1 day')) $sum += absDeduction($row, $member, $d->format('Y-m-d'));
    return $sum;
}

/** רשימת היעדרויות, עם מי, מה, כמה ימי עבודה, ומה הוחלט. */
function absencesOf(string $where, array $args): array {
    $st = db()->prepare("SELECT a.*, pe.name, m.site_id, m.company_id, m.religion, rp.name AS by_name,
        (SELECT COUNT(*) FROM absence_certs c WHERE c.absence_id = a.id AND c.deleted_at IS NULL) AS certs,
        (SELECT COUNT(*) FROM absence_certs c WHERE c.absence_id = a.id) AS certs_ever
        FROM absences a JOIN members m ON m.id = a.member_id JOIN people pe ON pe.id = m.person_id
        LEFT JOIN people rp ON rp.id = a.by_person
        WHERE $where ORDER BY a.from_date DESC, a.id DESC LIMIT 200");
    $st->execute($args);
    $types = [];
    return array_map(function ($a) use (&$types) {
        $types[$a['company_id']] ??= absTypes((int) $a['company_id'], true);
        $t = $types[$a['company_id']][$a['type']] ?? ['name' => 'היעדרות', 'icon' => '📌'];
        $span = absSpan($a, ['id' => $a['member_id'], 'company_id' => $a['company_id'], 'religion' => $a['religion']]);
        return ['id' => (int) $a['id'], 'member_id' => (int) $a['member_id'], 'name' => $a['name'],
                'site_id' => $a['site_id'] === null ? null : (int) $a['site_id'], 'type' => $a['type'], 'type_name' => $t['name'], 'icon' => $t['icon'],
                'from' => $a['from_date'], 'to' => $a['to_date'], 'part' => $a['part'], 'minutes' => $a['minutes'] === null ? null : (int) $a['minutes'],
                'note' => $a['note'], 'status' => $a['status'], 'by' => $a['by_member'] !== null ? 'employee' : 'manager', 'by_name' => $a['by_name'],
                'decision_note' => $a['decision_note'], 'created_at' => $a['created_at'], 'decided_at' => $a['decided_at'],
                'certs' => (int) $a['certs'], 'cert_shown' => $a['certs_ever'] > 0] + $span;
    }, $st->fetchAll());
}

/* ===== העובד ===== */

/** מה שהעובד צריך לטופס ולרשימה: הסוגים, היחידה, והבקשות שלו (ממתינות, ומ-90 הימים האחרונים). */
function empAbsences(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $company = (int) $m['company_id'];
    $since = (new DateTimeImmutable(todayIn($m['tz'])))->modify('-90 days')->format('Y-m-d');
    return ['types' => array_values(absTypes($company)), 'unit' => absSettings($company)['unit'], 'today' => todayIn($m['tz']),
            'balances' => balancesToday((int) $m['id']),
            'absences' => absencesOf("a.member_id = ? AND a.status <> 'cancelled' AND (a.status = 'pending' OR a.to_date >= ?)", [$m['id'], $since])];
}

function empAbsenceRequest(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $row = absenceFrom(memberRow((int) $m['id']), $m['tz'], $in);
    guardLocked((int) $m['company_id'], absMonths($row['from_date'], $row['to_date']));
    $id = insertAbsence((int) $m['id'], $row, (int) $m['id'], null, false);
    audit((int) $m['company_id'], null, 'absence.request', 'absences:' . $id, null, $row);
    return ['id' => $id];
}

/** העובד מבטל בקשה שלו שעוד ממתינה. */
function empAbsenceCancel(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $st = db()->prepare("UPDATE absences SET status = 'cancelled', decided_at = ? WHERE id = ? AND member_id = ? AND status = 'pending' AND by_member = ?");
    $st->execute([nowIso(), intIn($in, 'id'), $m['id'], $m['id']]);
    if ($st->rowCount() !== 1) throw new AppError('הבקשה לא נמצאה', 404);
    audit((int) $m['company_id'], null, 'absence.cancel', 'absences:' . intIn($in, 'id'), null, null);
    return ['ok' => true];
}

/* ===== המנהל ===== */

function absList(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $where = "m.company_id = ? AND a.status = 'pending'" . ($site !== null ? ' AND m.site_id = ?' : '');
    $list = absencesOf($where, $site !== null ? [$companyId, $site] : [$companyId]);
    // מה יישאר ביתרה אם הבקשה תאושר — כדי שהמנהל יראה חריגה לפני שהוא מאשר
    $types = absTypes($companyId, true);
    foreach ($list as &$a) {
        $kind = $types[$a['type']]['deducts'] ?? null;
        if (!$kind) continue;
        $b = memberBalance(memberRow($a['member_id']), $kind, todayIn(companyRow($companyId)['tz']));
        $a['balance'] = ['kind' => $kind, 'name' => BAL_KINDS[$kind], 'now' => $b['balance'], 'after' => round($b['balance'] - $b['planned'] - absDays($a), 2)];
    }
    unset($a);
    return ['absences' => $list, 'can_decide' => in_array($me['role'], CORR_EDIT, true), 'can_certs' => in_array($me['role'], CERT_VIEW, true)];
}

/** היעדרות אחת בתחום של המנהל. */
function absOne(int $companyId, int $id, ?int $site): array {
    $a = absencesOf('a.id = ? AND m.company_id = ?', [$id, $companyId])[0] ?? null;
    if (!$a || ($site !== null && $a['site_id'] !== $site)) throw new AppError('ההיעדרות לא נמצאה', 404);
    return $a;
}

function absDecide(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CORR_EDIT);
    $a = absOne($companyId, intIn($in, 'id'), $site);
    if ($a['status'] !== 'pending') throw new AppError('כבר הוחלט על הבקשה הזו');
    $approve = !empty($in['approve']);
    $months = absMonths($a['from'], $a['to']);
    if ($approve) guardLocked($companyId, $months);
    $note = str($in, 'note', 200);
    if (!$approve && $note === '') throw new AppError('בדחייה — כותבים לעובד למה');
    db()->prepare('UPDATE absences SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?')
        ->execute([$approve ? 'approved' : 'rejected', $person['id'], nowIso(), $note ?: null, $a['id']]);
    audit($companyId, (int) $person['id'], $approve ? 'absence.approve' : 'absence.reject', 'absences:' . $a['id'], null, ['note' => $note]);
    if ($approve) revokeAfterCorrection($companyId, $a['member_id'], $months);
    return absList($person, $in);
}

/** רישום ישיר של מנהל — מאושר מיד, ונרשם ביומן. */
function absDirect(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CORR_EDIT);
    $e = empRow($companyId, intIn($in, 'member_id'), $site);
    $row = absenceFrom($e, companyRow($companyId)['tz'], $in);
    $months = absMonths($row['from_date'], $row['to_date']);
    guardLocked($companyId, $months);
    $id = insertAbsence((int) $e['id'], $row, null, (int) $person['id'], true);
    revokeAfterCorrection($companyId, (int) $e['id'], $months);
    audit($companyId, (int) $person['id'], 'absence.direct', 'absences:' . $id, null, $row);
    return ['id' => $id];
}

/** ביטול היעדרות שאושרה (טעות, או שהעובד חזר מוקדם) — עם סיבה. הרשומה נשמרת כ"בוטלה". */
function absVoid(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CORR_EDIT);
    $a = absOne($companyId, intIn($in, 'id'), $site);
    if ($a['status'] !== 'approved') throw new AppError('אפשר לבטל רק היעדרות שאושרה');
    $note = str($in, 'note', 200);
    if ($note === '') throw new AppError('חסרה סיבה');
    $months = absMonths($a['from'], $a['to']);
    guardLocked($companyId, $months);
    db()->prepare("UPDATE absences SET status = 'cancelled', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?")
        ->execute([$person['id'], nowIso(), $note, $a['id']]);
    revokeAfterCorrection($companyId, $a['member_id'], $months);
    audit($companyId, (int) $person['id'], 'absence.void', 'absences:' . $a['id'], ['status' => 'approved'], ['status' => 'cancelled', 'note' => $note]);
    return ['ok' => true];
}

/** ההיעדרויות של עובד — לכרטיס העובד, עם מה שצריך לטופס. */
function absHistory(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $e = empRow($companyId, intIn($in, 'member_id'), $site);
    return ['absences' => absencesOf('a.member_id = ?', [$e['id']]), 'types' => array_values(absTypes($companyId)),
            'balances' => balancesToday((int) $e['id']), 'openings' => openingHistory((int) $e['id']),
            'unit' => absSettings($companyId)['unit'], 'can_edit' => in_array($me['role'], CORR_EDIT, true) && $e['status'] === 'active',
            'can_certs' => in_array($me['role'], CERT_VIEW, true)];
}

require_once __DIR__ . '/corrections.php';   // CORR_EDIT, guardLocked, revokeAfterCorrection
