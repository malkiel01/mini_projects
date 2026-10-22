<?php
/**
 * תיקונים (האפיון: E4, M5; שלב 5).
 *
 * - עובד מבקש מהטלפון: הוספת דיווח, שינוי שעה, או ביטול דיווח — תמיד עם סיבה. הבקשה ממתינה.
 * - מנהל (בעלים, מנהל, מנהל סניף לסניף שלו) מאשר או דוחה; או מתקן ישירות — מאושר מיד, עם סיבה.
 * - הדיווח המקורי לא משתנה. החישוב (lib/hours.php) מחיל רק תיקונים שאושרו, וכל החלטה נרשמת ביומן.
 * - שינוי שעה — עד יום אחד מהשעה המקורית; תיקון לא יכול להיות בעתיד.
 * - חודש נעול — אין תיקונים. תיקון שנכנס לתוקף בחודש שבאישור מבטל את האישורים של העובד בו (periods.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/attendance.php';

const CORR_EDIT = ['owner', 'manager', 'branch_manager'];

/** "2026-10-21T17:30" בשעון החברה ← UTC. */
function localToUtc(string $local, string $tz): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) throw new AppError('שעה לא תקינה');
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, new DateTimeZone($tz));
    if (!$d) throw new AppError('שעה לא תקינה');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** בדיקת תיקון ובניית השורה. $memberId — של העובד המתוקן. */
function correctionFrom(int $memberId, string $tz, array $in): array {
    $op = str($in, 'op', 5);
    if (!in_array($op, ['add', 'move', 'void'], true)) throw new AppError('סוג תיקון לא מוכר');
    $reason = str($in, 'reason', 200);
    if ($reason === '') throw new AppError('חסרה סיבה');
    $row = ['op' => $op, 'punch_id' => null, 'new_kind' => null, 'new_at' => null, 'reason' => $reason];
    if ($op !== 'void') {
        $row['new_at'] = localToUtc(str($in, 'at', 16), $tz);
        if ($row['new_at'] > utc(nowIso())->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')) throw new AppError('אי אפשר לתקן לשעה עתידית');
    }
    if ($op === 'add') {
        $row['new_kind'] = str($in, 'kind', 3);
        if (!in_array($row['new_kind'], ['in', 'out'], true)) throw new AppError('כניסה או יציאה?');
        return $row;
    }
    $st = db()->prepare('SELECT * FROM punches WHERE id = ? AND member_id = ?');
    $st->execute([intIn($in, 'punch_id'), $memberId]);
    $p = $st->fetch();
    if (!$p) throw new AppError('הדיווח לא נמצא', 404);
    $row['punch_id'] = (int) $p['id'];
    if ($op === 'move' && abs(utc($row['new_at'])->getTimestamp() - utc($p['at_utc'])->getTimestamp()) > 86400) {
        throw new AppError('שינוי שעה — עד יום אחד מהשעה המקורית. אחרת: ביטול והוספה.');
    }
    $st = db()->prepare("SELECT COUNT(*) FROM corrections WHERE punch_id = ? AND status = 'pending'");
    $st->execute([$p['id']]);
    if ((int) $st->fetchColumn() > 0) throw new AppError('כבר יש בקשת תיקון ממתינה לדיווח הזה');
    return $row;
}

function insertCorrection(int $memberId, array $row, ?int $byMember, ?int $byPerson, bool $approved): int {
    $now = nowIso();
    db()->prepare('INSERT INTO corrections (member_id, op, punch_id, new_kind, new_at, reason, by_member, by_person, status, decided_by, decided_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$memberId, $row['op'], $row['punch_id'], $row['new_kind'], $row['new_at'], $row['reason'], $byMember, $byPerson,
                   $approved ? 'approved' : 'pending', $approved ? $byPerson : null, $approved ? $now : null, $now]);
    return (int) db()->lastInsertId();
}

/* ===== העובד ===== */

function empCorrect(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $row = correctionFrom((int) $m['id'], $m['tz'], $in);
    guardLocked((int) $m['company_id'], correctionMonths($row, $m['tz']));
    $id = insertCorrection((int) $m['id'], $row, (int) $m['id'], null, false);
    audit((int) $m['company_id'], null, 'correction.request', 'corrections:' . $id, null, $row);
    return ['id' => $id];
}

/** העובד מבטל בקשה שלו שעוד ממתינה. */
function empCorrectCancel(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $st = db()->prepare("UPDATE corrections SET status = 'cancelled', decided_at = ? WHERE id = ? AND member_id = ? AND status = 'pending' AND by_member = ?");
    $st->execute([nowIso(), intIn($in, 'id'), $m['id'], $m['id']]);
    if ($st->rowCount() !== 1) throw new AppError('הבקשה לא נמצאה', 404);
    return ['ok' => true];
}

/** הבקשות של העובד (ממתינות, והחלטות מ-30 הימים האחרונים). */
function empCorrections(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    return ['corrections' => correctionsOf("c.member_id = ? AND (c.status = 'pending' OR c.decided_at >= ?)",
        [$m['id'], utc(nowIso())->modify('-30 days')->format('Y-m-d\TH:i:s\Z')])];
}

/* ===== המנהל ===== */

/** רשימת תיקונים עם כל מה שצריך כדי להבין אותם: מי, מה היה ומה יהיה, למה. */
function correctionsOf(string $where, array $args): array {
    $st = db()->prepare("SELECT c.*, p.at_utc AS old_at, p.kind AS old_kind, pe.name, m.site_id, rp.name AS by_name
        FROM corrections c JOIN members m ON m.id = c.member_id JOIN people pe ON pe.id = m.person_id
        LEFT JOIN punches p ON p.id = c.punch_id LEFT JOIN people rp ON rp.id = c.by_person
        WHERE $where ORDER BY c.created_at DESC, c.id DESC LIMIT 200");
    $st->execute($args);
    return array_map(fn($c) => ['id' => (int) $c['id'], 'member_id' => (int) $c['member_id'], 'name' => $c['name'],
        'site_id' => $c['site_id'] === null ? null : (int) $c['site_id'], 'op' => $c['op'],
        'kind' => $c['new_kind'] ?? $c['old_kind'], 'old_at' => $c['old_at'], 'new_at' => $c['new_at'], 'reason' => $c['reason'],
        'status' => $c['status'], 'by' => $c['by_member'] !== null ? 'employee' : 'manager', 'by_name' => $c['by_name'],
        'note' => $c['note'], 'created_at' => $c['created_at'], 'decided_at' => $c['decided_at']], $st->fetchAll());
}

function corrList(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $where = "m.company_id = ? AND c.status = 'pending'" . ($site !== null ? ' AND m.site_id = ?' : '');
    return ['corrections' => correctionsOf($where, $site !== null ? [$companyId, $site] : [$companyId]),
            'can_decide' => in_array($me['role'], CORR_EDIT, true)];
}

function corrDecide(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CORR_EDIT);
    $c = correctionsOf('c.id = ? AND m.company_id = ?', [intIn($in, 'id'), $companyId])[0] ?? null;
    if (!$c || ($site !== null && $c['site_id'] !== $site)) throw new AppError('הבקשה לא נמצאה', 404);
    if ($c['status'] !== 'pending') throw new AppError('כבר הוחלט על הבקשה הזו');
    $approve = !empty($in['approve']);
    $tz = companyRow($companyId)['tz'];
    $months = array_values(array_unique(array_map(fn($a) => substr(localDate($a, $tz), 0, 7), array_filter([$c['new_at'], $c['old_at']]))));
    if ($approve) guardLocked($companyId, $months);
    $note = str($in, 'note', 200);
    if (!$approve && $note === '') throw new AppError('בדחייה — כותבים לעובד למה');
    db()->prepare('UPDATE corrections SET status = ?, decided_by = ?, decided_at = ?, note = ? WHERE id = ?')
        ->execute([$approve ? 'approved' : 'rejected', $person['id'], nowIso(), $note ?: null, $c['id']]);
    audit($companyId, (int) $person['id'], $approve ? 'correction.approve' : 'correction.reject', 'corrections:' . $c['id'], null, ['note' => $note]);
    if ($approve) revokeAfterCorrection($companyId, $c['member_id'], $months);
    return corrList($person, $in);
}

/** תיקון ישיר של מנהל — מאושר מיד, עם סיבה, ונרשם ביומן. */
function corrDirect(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CORR_EDIT);
    $e = empRow($companyId, intIn($in, 'member_id'), $site);
    $tz = companyRow($companyId)['tz'];
    $row = correctionFrom((int) $e['id'], $tz, $in);
    $months = correctionMonths($row, $tz);
    guardLocked($companyId, $months);
    // בקשה ממתינה לאותו דיווח חוסמת (correctionFrom) — מחליטים עליה קודם.
    $id = insertCorrection((int) $e['id'], $row, null, (int) $person['id'], true);
    revokeAfterCorrection($companyId, (int) $e['id'], $months);
    audit($companyId, (int) $person['id'], 'correction.direct', 'corrections:' . $id, null, $row);
    return ['id' => $id];
}

/** היסטוריית התיקונים של עובד — לכרטיס העובד. */
function corrHistory(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_VIEW);
    $e = empRow($companyId, intIn($in, 'member_id'), $site);
    return ['corrections' => correctionsOf('c.member_id = ?', [$e['id']])];
}

require_once __DIR__ . '/periods.php';   // guardLocked, revokeAfterCorrection — הקשר בין התיקונים לחודש
