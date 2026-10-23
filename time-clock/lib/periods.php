<?php
/**
 * אישור חודשי, נעילה וייצוא (האפיון: E5, M6, M7 — שלבי אישור; שלב 6).
 *
 * - לכל חברה מסלול: שלבים לפי סדר, כל שלב — תפקיד (עובד, מנהל, מנהלת שכר, בעלים/מורשה חתימה).
 *   ברירת מחדל: עובד ← מנהל.
 * - המנהל "פותח לאישור" חודש. השלבים נקבעים ברגע הפתיחה — שינוי בהגדרות לא משנה חודש שכבר בתהליך.
 * - לכל עובד: השלבים לפי הסדר. שלב "עובד" — העובד מהטלפון; אם לא אישר, מנהל יכול לסמן "במקום העובד"
 *   עם הערה (נרשם ככזה). שלב "מנהל" — בעלים, מנהל, או מנהל הסניף של העובד.
 * - תיקון שאושר בחודש שבתהליך מבטל את האישורים של אותו עובד בחודש (הם אישרו נתונים אחרים).
 * - נעילה: חודש נעול לא מקבל תיקונים. פתיחה מחדש — בעלים בלבד, עם סיבה; מבטלת את כל האישורים.
 */

declare(strict_types=1);

require_once __DIR__ . '/corrections.php';

const STEP_ROLES = ['employee', 'manager', 'payroll', 'owner'];
const STEP_LABEL = ['employee' => 'עובד', 'manager' => 'מנהל', 'payroll' => 'מנהלת שכר', 'owner' => 'בעלים / מורשה חתימה'];

/* ===== המסלול של החברה ===== */

function companySteps(int $companyId): array {
    $st = db()->prepare('SELECT role FROM approval_steps WHERE company_id = ? ORDER BY ord');
    $st->execute([$companyId]);
    $r = $st->fetchAll(PDO::FETCH_COLUMN);
    return $r ?: ['employee', 'manager'];
}

function stepsGet(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, MANAGE_ROLES);
    return ['steps' => companySteps($companyId), 'labels' => STEP_LABEL, 'can_edit' => $me['role'] === 'owner'];
}

function stepsSave(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $steps = is_array($in['steps'] ?? null) ? array_values($in['steps']) : [];
    if (!$steps || count($steps) > 4) throw new AppError('בין שלב אחד לארבעה');
    foreach ($steps as $r) if (!is_string($r) || !in_array($r, STEP_ROLES, true)) throw new AppError('שלב לא מוכר');
    if (count(array_unique($steps)) !== count($steps)) throw new AppError('כל תפקיד — פעם אחת');
    $before = companySteps($companyId);
    db()->prepare('DELETE FROM approval_steps WHERE company_id = ?')->execute([$companyId]);
    $ins = db()->prepare('INSERT INTO approval_steps (company_id, ord, role) VALUES (?,?,?)');
    foreach ($steps as $i => $r) $ins->execute([$companyId, $i, $r]);
    audit($companyId, (int) $person['id'], 'steps.save', 'companies:' . $companyId, ['steps' => $before], ['steps' => $steps]);
    return stepsGet($person, $in);
}

/* ===== החודש ===== */

function periodRow(int $companyId, string $month): ?array {
    $st = db()->prepare('SELECT * FROM periods WHERE company_id = ? AND month = ?');
    $st->execute([$companyId, $month]);
    $p = $st->fetch();
    if (!$p) return null;
    $p['steps'] = json_decode($p['steps'], true);
    return $p;
}

/** האישורים התקפים: [member_id][step] => שורה. */
function signoffs(int $periodId): array {
    $st = db()->prepare('SELECT s.*, pe.name AS by_name FROM period_signoffs s LEFT JOIN people pe ON pe.id = s.by_person
        WHERE s.period_id = ? AND s.revoked_at IS NULL ORDER BY s.step');
    $st->execute([$periodId]);
    $out = [];
    foreach ($st->fetchAll() as $s) $out[(int) $s['member_id']][(int) $s['step']] = $s;
    return $out;
}

/** השלב הבא שממתין לעובד (אינדקס), או null אם הכול אושר. */
function nextStep(array $period, array $mine): ?int {
    foreach ($period['steps'] as $i => $_) if (!isset($mine[$i])) return $i;
    return null;
}

/** מי רשאי לאשר שלב: מנהל — בעלים/מנהל/מנהל הסניף; מנהלת שכר — היא או הבעלים; בעלים — רק הבעלים. */
function mayApprove(array $me, string $role, array $emp): bool {
    return match ($role) {
        'manager'  => in_array($me['role'], ['owner', 'manager'], true)
                      || ($me['role'] === 'branch_manager' && (int) $me['site_id'] === (int) $emp['site_id']),
        'payroll'  => in_array($me['role'], ['owner', 'payroll'], true),
        'owner'    => $me['role'] === 'owner',
        'employee' => in_array($me['role'], ['owner', 'manager'], true),   // רק "במקום העובד", עם הערה
        default    => false,
    };
}

/** העובדים של החודש: מי שהועסק בו (התחיל עד סופו, ולא סיים לפני תחילתו). מנהל סניף — הסניף שלו. */
function periodEmployees(int $companyId, string $month, ?int $site): array {
    [$first, $last] = monthBounds($month);
    $sql = "SELECT m.id, m.site_id, m.employee_no, m.status, p.name FROM members m JOIN people p ON p.id = m.person_id
            WHERE m.company_id = ? AND m.role = 'employee' AND (m.start_date IS NULL OR m.start_date <= ?)
              AND (m.end_date IS NULL OR m.end_date >= ?)" . ($site !== null ? ' AND m.site_id = ?' : '') . ' ORDER BY p.name';
    $st = db()->prepare($sql);
    $st->execute($site !== null ? [$companyId, $last, $first, $site] : [$companyId, $last, $first]);
    return $st->fetchAll();
}

function monthOf(array $in, string $tz): string {
    $month = str($in, 'month', 7) ?: substr(todayIn($tz), 0, 7);
    monthBounds($month);
    return $month;
}

function periodView(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $tz = companyRow($companyId)['tz'];
    $month = monthOf($in, $tz);
    $period = periodRow($companyId, $month);
    $so = $period ? signoffs((int) $period['id']) : [];
    $steps = $period ? $period['steps'] : companySteps($companyId);
    $rows = [];
    $done = 0;
    foreach (periodEmployees($companyId, $month, $site) as $e) {
        $r = monthReportOt((int) $e['id'], $month, $tz);
        $st = db()->prepare("SELECT COUNT(*) FROM corrections WHERE member_id = ? AND status = 'pending'");
        $st->execute([$e['id']]);
        $mine = $so[(int) $e['id']] ?? [];
        $next = $period ? nextStep($period, $mine) : 0;
        if ($period && $next === null) $done++;
        $rows[] = ['id' => (int) $e['id'], 'name' => $e['name'], 'employee_no' => $e['employee_no'],
                   'site_id' => $e['site_id'] === null ? null : (int) $e['site_id'], 'summary' => $r['summary'],
                   'pending' => (int) $st->fetchColumn(), 'next' => $next,
                   'can_approve' => $period && $period['status'] === 'review' && $next !== null && mayApprove($me, $steps[$next], $e),
                   'signoffs' => array_map(fn($s) => ['step' => (int) $s['step'], 'at' => $s['at'], 'by' => $s['by_member'] ? 'employee' : $s['by_name'],
                                                      'on_behalf' => (bool) $s['on_behalf'], 'note' => $s['note']], array_values($mine))];
    }
    $manage = in_array($me['role'], ['owner', 'manager'], true);
    return ['month' => $month, 'today' => todayIn($tz), 'status' => $period['status'] ?? 'open', 'steps' => $steps, 'labels' => STEP_LABEL,
            'employees' => $rows, 'done' => $done, 'site_only' => $site, 'my_role' => $me['role'],
            'can' => ['open' => $manage && !$period && $month <= substr(todayIn($tz), 0, 7),
                      'lock' => $manage && $period && $period['status'] === 'review',
                      'reopen' => $me['role'] === 'owner' && $period && $period['status'] === 'locked',
                      'export' => true],
            'locked_at' => $period['locked_at'] ?? null];
}

function periodOpen(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner', 'manager']);
    $tz = companyRow($companyId)['tz'];
    $month = monthOf($in, $tz);
    if ($month > substr(todayIn($tz), 0, 7)) throw new AppError('אי אפשר לפתוח לאישור חודש עתידי');
    if (periodRow($companyId, $month)) throw new AppError('החודש כבר בתהליך אישור');
    $steps = companySteps($companyId);
    db()->prepare("INSERT INTO periods (company_id, month, status, steps, opened_by, opened_at) VALUES (?,?,'review',?,?,?)")
        ->execute([$companyId, $month, json_encode($steps), $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'period.open', 'periods:' . $month, null, ['steps' => $steps]);
    return periodView($person, $in);
}

/** אישור השלב הבא לעובדים שנבחרו (אחד או כמה). שלב "עובד" — רק עם on_behalf והערה. */
function periodApprove(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $month = monthOf($in, companyRow($companyId)['tz']);
    $period = periodRow($companyId, $month);
    if (!$period || $period['status'] !== 'review') throw new AppError('החודש לא פתוח לאישור');
    $ids = array_map('intval', is_array($in['member_ids'] ?? null) ? $in['member_ids'] : []);
    if (!$ids) throw new AppError('לא נבחרו עובדים');
    $note = str($in, 'note', 200);
    $behalf = !empty($in['on_behalf']);
    $emps = [];
    foreach (periodEmployees($companyId, $month, $site) as $e) $emps[(int) $e['id']] = $e;
    $so = signoffs((int) $period['id']);
    $n = 0;
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($ids as $id) {
        $e = $emps[$id] ?? throw new AppError('העובד לא נמצא', 404);
        $next = nextStep($period, $so[$id] ?? []);
        if ($next === null) continue;
        $role = $period['steps'][$next];
        if (!mayApprove($me, $role, $e)) throw new AppError("אין לך הרשאה לשלב \"" . STEP_LABEL[$role] . "\" של {$e['name']}", 403);
        if ($role === 'employee' && (!$behalf || $note === '')) throw new AppError("{$e['name']} עוד לא אישר. אישור במקומו — עם הערה.");
        $pdo->prepare('INSERT INTO period_signoffs (period_id, member_id, step, by_person, on_behalf, note, at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$period['id'], $id, $next, $person['id'], $role === 'employee' ? 1 : 0, $note ?: null, nowIso()]);
        $n++;
    }
    audit($companyId, (int) $person['id'], 'period.approve', 'periods:' . $month, null, ['members' => $ids, 'count' => $n]);
    $pdo->commit();
    return periodView($person, $in);
}

/** נעילה. כשלא כולם אישרו — רק בעלים, עם סיבה. */
function periodLock(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, ['owner', 'manager']);
    $month = monthOf($in, companyRow($companyId)['tz']);
    $period = periodRow($companyId, $month);
    if (!$period || $period['status'] !== 'review') throw new AppError('החודש לא בתהליך אישור');
    $so = signoffs((int) $period['id']);
    $missing = array_filter(periodEmployees($companyId, $month, null), fn($e) => nextStep($period, $so[(int) $e['id']] ?? []) !== null);
    $reason = str($in, 'reason', 200);
    if ($missing && ($me['role'] !== 'owner' || $reason === '')) {
        throw new AppError(count($missing) . ' עובדים עוד לא אישרו את כל השלבים. נעילה בכל זאת — רק בעלים, עם סיבה.');
    }
    db()->prepare("UPDATE periods SET status = 'locked', locked_by = ?, locked_at = ? WHERE id = ?")->execute([$person['id'], nowIso(), $period['id']]);
    audit($companyId, (int) $person['id'], 'period.lock', 'periods:' . $month, null, ['missing' => count($missing), 'reason' => $reason]);
    return periodView($person, $in);
}

/** פתיחה מחדש של חודש נעול — בעלים, עם סיבה. כל האישורים מתבטלים (ונשמרים כמבוטלים). */
function periodReopen(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $month = monthOf($in, companyRow($companyId)['tz']);
    $period = periodRow($companyId, $month);
    if (!$period || $period['status'] !== 'locked') throw new AppError('החודש לא נעול');
    $reason = str($in, 'reason', 200);
    if ($reason === '') throw new AppError('חסרה סיבה לפתיחה מחדש');
    db()->prepare("UPDATE periods SET status = 'review', locked_by = NULL, locked_at = NULL WHERE id = ?")->execute([$period['id']]);
    db()->prepare('UPDATE period_signoffs SET revoked_at = ? WHERE period_id = ? AND revoked_at IS NULL')->execute([nowIso(), $period['id']]);
    audit($companyId, (int) $person['id'], 'period.reopen', 'periods:' . $month, null, ['reason' => $reason]);
    return periodView($person, $in);
}

/* ===== שמירה על חודש נעול, וביטול אישורים שנפגעו ===== */

/** החודשים (המקומיים) שתיקון נוגע בהם. */
function correctionMonths(array $row, string $tz): array {
    $ats = array_filter([$row['new_at'] ?? null]);
    if (!empty($row['punch_id'])) {
        $st = db()->prepare('SELECT at_utc FROM punches WHERE id = ?');
        $st->execute([$row['punch_id']]);
        if ($a = $st->fetchColumn()) $ats[] = $a;
    }
    return array_values(array_unique(array_map(fn($a) => substr(localDate($a, $tz), 0, 7), $ats)));
}

/** תיקון בחודש נעול — נחסם. נקרא לפני בקשה, לפני אישור ולפני תיקון ישיר. */
function guardLocked(int $companyId, array $months): void {
    foreach ($months as $m) {
        $p = periodRow($companyId, $m);
        if ($p && $p['status'] === 'locked') throw new AppError("חודש $m נעול. פתיחה מחדש — אצל הבעלים.");
    }
}

/** תיקון שנכנס לתוקף בחודש שבתהליך — האישורים של העובד בחודש הזה מתבטלים. */
function revokeAfterCorrection(int $companyId, int $memberId, array $months): void {
    foreach ($months as $m) {
        $p = periodRow($companyId, $m);
        if ($p && $p['status'] === 'review') {
            db()->prepare('UPDATE period_signoffs SET revoked_at = ? WHERE period_id = ? AND member_id = ? AND revoked_at IS NULL')
                ->execute([nowIso(), $p['id'], $memberId]);
        }
    }
}

/* ===== העובד: אישור החודש שלו ===== */

/** מצב החודש לעובד: האם בתהליך, השלבים, מה כבר אושר, והאם עכשיו תורו. */
function empPeriodInfo(int $companyId, int $memberId, string $month): ?array {
    $p = periodRow($companyId, $month);
    if (!$p) return null;
    $mine = signoffs((int) $p['id'])[$memberId] ?? [];
    $next = nextStep($p, $mine);
    return ['status' => $p['status'], 'steps' => $p['steps'], 'labels' => STEP_LABEL, 'done' => array_map('intval', array_keys($mine)),
            'my_turn' => $p['status'] === 'review' && $next !== null && $p['steps'][$next] === 'employee'];
}

/** חודשים שממתינים לאישור של העובד (לכרטיס בבית). */
function empPendingMonths(int $companyId, int $memberId): array {
    $st = db()->prepare("SELECT month FROM periods WHERE company_id = ? AND status = 'review' ORDER BY month");
    $st->execute([$companyId]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($m) => empPeriodInfo($companyId, $memberId, $m)['my_turn'] ?? false));
}

function empApprove(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $month = monthOf($in, $m['tz']);
    $p = periodRow((int) $m['company_id'], $month);
    $info = empPeriodInfo((int) $m['company_id'], (int) $m['id'], $month);
    if (!$p || !$info['my_turn']) throw new AppError('אין כרגע מה לאשר בחודש הזה');
    $next = nextStep($p, signoffs((int) $p['id'])[(int) $m['id']] ?? []);
    db()->prepare('INSERT INTO period_signoffs (period_id, member_id, step, by_member, at) VALUES (?,?,?,?,?)')
        ->execute([$p['id'], $m['id'], $next, $m['id'], nowIso()]);
    audit((int) $m['company_id'], null, 'period.employee_approve', 'members:' . $m['id'], null, ['month' => $month]);
    return ['period' => empPeriodInfo((int) $m['company_id'], (int) $m['id'], $month)];
}

/* ===== ייצוא ===== */

/** CSV לאקסל (UTF-8 עם BOM): שורה לכל עובד ויום עבודה או יום עם דיווח. */
function periodCsv(array $person, array $in): string {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_VIEW);
    $tz = companyRow($companyId)['tz'];
    $month = monthOf($in, $tz);
    $t = fn(?string $iso) => $iso ? utc($iso)->setTimezone(new DateTimeZone($tz))->format('H:i') : '';
    $hm = fn(int $m) => ($m < 0 ? '-' : '') . intdiv(abs($m), 60) . ':' . str_pad((string) (abs($m) % 60), 2, '0', STR_PAD_LEFT);
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    // הסיווג (גרסה 2) — עמודה לכל סל, עם השיעור שבכללי החברה בכותרת
    $rules = otRulesAt($companyId, monthBounds($month)[1]);
    $otCols = ['reg' => 'רגילות', 'ot1' => "נוספות {$rules['rate_ot1']}%", 'ot2' => "נוספות {$rules['rate_ot2']}%",
               'rest' => "מנוחה/חג {$rules['rate_rest']}%", 'rest_ot1' => "מנוחה {$rules['rate_rest_ot1']}%",
               'rest_ot2' => "מנוחה {$rules['rate_rest_ot2']}%", 'holiday_rest' => "חג במנוחה {$rules['rate_holiday_rest']}%"];
    fputcsv($f, array_merge(['עובד', 'מס׳ עובד', 'תאריך', 'כניסה', 'יציאה', 'סה״כ', 'תקן', 'הפרש'], array_values($otCols), ['היעדרות', 'זיכוי', 'יום', 'תוקן', 'הערה']), ',', '"', '');
    foreach (periodEmployees($companyId, $month, $site) as $e) {
        $r = monthReportOt((int) $e['id'], $month, $tz);
        foreach ($r['days'] as $d) {
            if (!$d['pairs'] && !$d['standard'] && !$d['missing_in'] && !$d['missing_out']) continue;
            if ($d['date'] > $r['today']) continue;
            $ab = $d['absence'] ?? null;
            $credit = $ab['credit'] ?? 0;
            $note = trim(($d['missing_out'] ? 'חסר יציאה ' : '') . ($d['missing_in'] ? 'חסר כניסה ' : '') . (!$d['pairs'] && $d['standard'] && !$credit ? 'לא דווח ' : '')
                         . ($ab && $ab['status'] === 'pending' ? 'היעדרות ממתינה לאישור' : ''));
            $absCol = $credit ? $ab['name'] . ['full' => '', 'half' => ' ½', 'hours' => ' ' . $hm($credit)][$ab['part']] : '';
            $ins = implode(' ', array_map(fn($p) => $t($p['in']), $d['pairs']));
            $outs = implode(' ', array_map(fn($p) => $t($p['out']), $d['pairs']));
            $ot = $d['ot']['b'] ?? array_fill_keys(OT_BUCKETS, 0);
            $kind = trim(($d['ot']['holiday'] ?? '') . (!empty($d['ot']['rest']) ? ' מנוחה' : '') . (!empty($d['ot']['night']) ? ' לילה' : ''));
            fputcsv($f, array_merge([$e['name'], $e['employee_no'] ?? '', $d['date'], $ins, $outs, $hm($d['minutes']), $hm($d['standard']), $hm($d['minutes'] + $credit - $d['standard'])],
                                    array_map(fn($k) => $ot[$k] ? $hm($ot[$k]) : '', array_keys($otCols)), [$absCol, $credit ? $hm($credit) : '', $kind, $d['corrected'] ? 'כן' : '', $note]), ',', '"', '');
        }
        $s = $r['summary'];
        fputcsv($f, array_merge([$e['name'], $e['employee_no'] ?? '', 'סה״כ ' . $month, '', '', $hm($s['minutes']), $hm($s['standard']), $hm($s['diff'])],
                                array_map(fn($k) => $hm($s['ot'][$k]), array_keys($otCols)), ['', $s['absence'] ? $hm($s['absence']) : '', '', '', $s['days'] . ' ימים']), ',', '"', '');
    }
    rewind($f);
    return stream_get_contents($f);
}
