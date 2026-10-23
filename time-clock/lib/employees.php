<?php
/**
 * עובדים — צד הניהול (האפיון: M3, M4; שלב 2).
 *
 * מי מנהל עובדים: בעלים ומנהל — כל החברה; מנהל סניף — רק עובדי הסניף שלו (ועובד חדש נכנס לסניף שלו);
 * מנהלת שכר — צפייה בלבד.
 *
 * עובד אינו משתמש פלטפורמה: הוא נכנס בקישור הזמנה אישי (חד-פעמי, 7 ימים) ובוחר קוד. מכשיר חדש —
 * המנהל שולח קישור חדש, והקישורים והמכשירים הקודמים של העובד בחברה הזו מתבטלים.
 */

declare(strict_types=1);

require_once __DIR__ . '/company.php';

const INVITE_DAYS = 7;
const EMP_VIEW = ['owner', 'manager', 'branch_manager', 'payroll'];
const EMP_EDIT = ['owner', 'manager', 'branch_manager'];

function tokenHash(string $token): string {
    return hash('sha256', $token);
}

function newToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function validDate(string $d, string $label): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
        throw new AppError($label . ' — תאריך לא תקין');
    }
    return $d;
}

/** היום בשעון החברה — "מאיזה תאריך" נקבע לפי ישראל, לא לפי UTC. */
function todayIn(string $tz = 'Asia/Jerusalem'): string {
    return (new DateTimeImmutable(nowIso()))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
}

/** טלפון ישראלי: ספרות בלבד, 05X → 9725X. ריק — מותר. */
function normPhone(string $p): string {
    $d = preg_replace('/\D+/', '', $p);
    if ($d === '') return '';
    if (str_starts_with($d, '0')) $d = '972' . substr($d, 1);
    if (!preg_match('/^972\d{8,9}$/', $d)) throw new AppError('מספר טלפון לא תקין');
    return $d;
}

/** החברוּת של המבקש, ואם הוא מנהל סניף — הסניף שאליו הוא מוגבל. */
function empScope(array $person, int $companyId, array $roles): array {
    $me = requireMember($person, $companyId, $roles);
    return [$me, $me['role'] === 'branch_manager' ? (int) $me['site_id'] : null];
}

function empRow(int $companyId, int $id, ?int $siteOnly): array {
    $st = db()->prepare("SELECT m.*, p.name, p.phone, p.email FROM members m JOIN people p ON p.id = m.person_id
        WHERE m.id = ? AND m.company_id = ? AND m.role = 'employee'");
    $st->execute([$id, $companyId]);
    $e = $st->fetch();
    if (!$e || ($siteOnly !== null && (int) $e['site_id'] !== $siteOnly)) throw new AppError('העובד לא נמצא', 404);
    return $e;
}

function termsAt(int $memberId, string $date): ?array {
    $st = db()->prepare('SELECT * FROM work_terms WHERE member_id = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1');
    $st->execute([$memberId, $date]);
    return $st->fetch() ?: null;
}

function termsOut(?array $t): ?array {
    return $t ? ['id' => (int) $t['id'], 'effective_from' => $t['effective_from'], 'days_per_week' => (int) $t['days_per_week'],
                 'daily_minutes' => (int) $t['daily_minutes'], 'pay_type' => $t['pay_type']] : null;
}

/** מצב ההזמנה: active (נכנס ממכשיר), pending (קישור בתוקף), expired, none. */
function inviteStatus(int $memberId): string {
    $st = db()->prepare('SELECT COUNT(*) FROM devices WHERE member_id = ? AND revoked_at IS NULL');
    $st->execute([$memberId]);
    if ((int) $st->fetchColumn() > 0) return 'active';
    $st = db()->prepare('SELECT expires_at FROM invites WHERE member_id = ? AND used_at IS NULL AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$memberId]);
    $exp = $st->fetchColumn();
    if ($exp === false) return 'none';
    return $exp > nowIso() ? 'pending' : 'expired';
}

function empOut(array $e, string $today): array {
    return ['id' => (int) $e['id'], 'name' => $e['name'], 'phone' => $e['phone'], 'email' => $e['email'],
            'employee_no' => $e['employee_no'], 'site_id' => $e['site_id'] === null ? null : (int) $e['site_id'],
            'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'status' => $e['status'],
            'has_pin' => $e['pin_hash'] !== null, 'invite' => inviteStatus((int) $e['id']),
            'terms' => termsOut(termsAt((int) $e['id'], $today)),
            'religion' => $e['religion'] ?? null, 'rest_day' => isset($e['rest_day']) ? (int) $e['rest_day'] : null];
}

/** דת ויום מנוחה (גרסה 2). ריק — ברירת המחדל (יהודי, שבת). יום מנוחה ריק — לפי הדת. */
function religionFields(array $in): array {
    $rel = str($in, 'religion', 12);
    if ($rel !== '' && !in_array($rel, ['jewish', 'muslim', 'christian', 'druze', 'other'], true)) throw new AppError('דת לא מוכרת');
    $rd = $in['rest_day'] ?? '';
    if ($rd === '' || $rd === null) $rd = null;
    elseif (!is_numeric($rd) || (int) $rd < 0 || (int) $rd > 6) throw new AppError('יום מנוחה לא תקין');
    else $rd = (int) $rd;
    return [$rel ?: null, $rd];
}

function empList(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $sql = "SELECT m.*, p.name, p.phone, p.email FROM members m JOIN people p ON p.id = m.person_id
            WHERE m.company_id = ? AND m.role = 'employee'" . ($site !== null ? ' AND m.site_id = ?' : '') . ' ORDER BY m.status, p.name';
    $st = db()->prepare($sql);
    $st->execute($site !== null ? [$companyId, $site] : [$companyId]);
    $today = todayIn(companyRow($companyId)['tz']);
    return ['employees' => array_map(fn($e) => empOut($e, $today), $st->fetchAll()),
            'can_edit' => in_array($me['role'], EMP_EDIT, true), 'site_only' => $site];
}

function empGet(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [$me, $site] = empScope($person, $companyId, EMP_VIEW);
    $e = empRow($companyId, intIn($in, 'id'), $site);
    $st = db()->prepare('SELECT * FROM work_terms WHERE member_id = ? ORDER BY effective_from DESC');
    $st->execute([$e['id']]);
    return ['employee' => empOut($e, todayIn(companyRow($companyId)['tz'])),
            'terms_history' => array_map('termsOut', $st->fetchAll()),
            'can_edit' => in_array($me['role'], EMP_EDIT, true)];
}

/** פרטים משותפים להוספה ולעדכון. */
function empFields(int $companyId, array $in, ?int $siteOnly, ?int $selfId = null): array {
    $name = str($in, 'name', 80);
    if ($name === '') throw new AppError('חסר שם');
    $phone = normPhone(str($in, 'phone', 30));
    $email = mb_strtolower(str($in, 'email', 120));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('כתובת מייל לא תקינה');
    $no = str($in, 'employee_no', 20);
    if ($no !== '') {
        $st = db()->prepare("SELECT COUNT(*) FROM members WHERE company_id = ? AND role = 'employee' AND status = 'active' AND employee_no = ? AND id <> ?");
        $st->execute([$companyId, $no, $selfId ?? 0]);
        if ((int) $st->fetchColumn() > 0) throw new AppError('מספר העובד הזה כבר בשימוש');
    }
    $siteId = intIn($in, 'site_id') ?: null;
    if ($siteOnly !== null) $siteId = $siteOnly;           // מנהל סניף — תמיד הסניף שלו
    if ($siteId !== null) siteOf($companyId, $siteId);
    $start = str($in, 'start_date', 10);
    $start = $start === '' ? null : validDate($start, 'תאריך התחלה');
    return [$name, $phone ?: null, $email ?: null, $no ?: null, $siteId, $start];
}

function termsFrom(array $in): array {
    [$days, $minutes] = standardFrom($in);
    $pay = str($in, 'pay_type', 10) ?: 'monthly';
    if (!in_array($pay, ['monthly', 'hourly'], true)) throw new AppError('סוג שכר: חודשי או שעתי');
    return [$days, $minutes, $pay];
}

/** הוספת עובד: אדם חדש, חברוּת, תנאים מתאריך ההתחלה (או מהיום) — וקישור הזמנה. */
function empAdd(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_EDIT);
    [$name, $phone, $email, $no, $siteId, $start] = empFields($companyId, $in, $site);
    [$days, $minutes, $pay] = termsFrom($in);
    $company = companyRow($companyId);

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO people (name, email, phone, created_at) VALUES (?,?,?,?)')->execute([$name, $email, $phone, nowIso()]);
    $pid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO members (company_id, person_id, role, site_id, employee_no, start_date, created_at) VALUES (?,?,'employee',?,?,?,?)")
        ->execute([$companyId, $pid, $siteId, $no, $start, nowIso()]);
    $mid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO work_terms (member_id, effective_from, days_per_week, daily_minutes, pay_type, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$mid, $start ?? todayIn($company['tz']), $days, $minutes, $pay, $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'employee.add', 'members:' . $mid, null, ['name' => $name, 'site_id' => $siteId]);
    $token = createInvite($mid, (int) $person['id']);
    $pdo->commit();
    return empGet($person, ['company_id' => $companyId, 'id' => $mid]) + ['invite_token' => $token];
}

function empUpdate(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_EDIT);
    $e = empRow($companyId, intIn($in, 'id'), $site);
    [$name, $phone, $email, $no, $siteId, $start] = empFields($companyId, $in, $site, (int) $e['id']);
    db()->prepare('UPDATE people SET name = ?, phone = ?, email = ? WHERE id = ?')->execute([$name, $phone, $email, $e['person_id']]);
    db()->prepare('UPDATE members SET employee_no = ?, site_id = ?, start_date = ? WHERE id = ?')->execute([$no, $siteId, $start, $e['id']]);
    if (array_key_exists('religion', $in) || array_key_exists('rest_day', $in)) {
        [$rel, $rd] = religionFields($in);
        db()->prepare('UPDATE members SET religion = ?, rest_day = ? WHERE id = ?')->execute([$rel, $rd, $e['id']]);
        audit($companyId, (int) $person['id'], 'employee.religion', 'members:' . $e['id'], ['religion' => $e['religion'], 'rest_day' => $e['rest_day']], ['religion' => $rel, 'rest_day' => $rd]);
    }
    audit($companyId, (int) $person['id'], 'employee.update', 'members:' . $e['id'],
          ['name' => $e['name'], 'phone' => $e['phone'], 'email' => $e['email'], 'employee_no' => $e['employee_no'], 'site_id' => $e['site_id'], 'start_date' => $e['start_date']],
          ['name' => $name, 'phone' => $phone, 'email' => $email, 'employee_no' => $no, 'site_id' => $siteId, 'start_date' => $start]);
    return empGet($person, ['company_id' => $companyId, 'id' => $e['id']]);
}

/** גרסת תנאים חדשה מתאריך. אותו תאריך — מחליף את הגרסה של אותו תאריך (תיקון טעות), והיומן שומר את הקודמת. */
function empTermsAdd(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_EDIT);
    $e = empRow($companyId, intIn($in, 'id'), $site);
    $from = validDate(str($in, 'effective_from', 10), 'בתוקף מתאריך');
    [$days, $minutes, $pay] = termsFrom($in);
    $st = db()->prepare('SELECT * FROM work_terms WHERE member_id = ? AND effective_from = ?');
    $st->execute([$e['id'], $from]);
    $before = $st->fetch() ?: null;
    db()->prepare('INSERT INTO work_terms (member_id, effective_from, days_per_week, daily_minutes, pay_type, created_by, created_at) VALUES (?,?,?,?,?,?,?)
        ON CONFLICT (member_id, effective_from) DO UPDATE SET days_per_week = excluded.days_per_week, daily_minutes = excluded.daily_minutes,
        pay_type = excluded.pay_type, created_by = excluded.created_by, created_at = excluded.created_at')
        ->execute([$e['id'], $from, $days, $minutes, $pay, $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'terms.save', 'members:' . $e['id'], termsOut($before),
          ['effective_from' => $from, 'days_per_week' => $days, 'daily_minutes' => $minutes, 'pay_type' => $pay]);
    return empGet($person, ['company_id' => $companyId, 'id' => $e['id']]);
}

/** קישור חדש. מבטל את הקישורים הקודמים ואת המכשירים של העובד בחברה הזו — "החליף טלפון". */
function createInvite(int $memberId, int $by): string {
    $now = nowIso();
    db()->prepare('UPDATE invites SET revoked_at = ? WHERE member_id = ? AND used_at IS NULL AND revoked_at IS NULL')->execute([$now, $memberId]);
    db()->prepare('UPDATE devices SET revoked_at = ? WHERE member_id = ? AND revoked_at IS NULL')->execute([$now, $memberId]);
    $token = newToken();
    db()->prepare('INSERT INTO invites (member_id, token_hash, created_by, created_at, expires_at) VALUES (?,?,?,?,?)')
        ->execute([$memberId, tokenHash($token), $by, $now, gmdate('Y-m-d\TH:i:s\Z', time() + INVITE_DAYS * 86400)]);
    return $token;
}

function empInvite(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_EDIT);
    $e = empRow($companyId, intIn($in, 'id'), $site);
    if ($e['status'] !== 'active') throw new AppError('העובד סיים את עבודתו');
    $token = createInvite((int) $e['id'], (int) $person['id']);
    audit($companyId, (int) $person['id'], 'employee.invite', 'members:' . $e['id'], null, null);
    return empGet($person, ['company_id' => $companyId, 'id' => $e['id']]) + ['invite_token' => $token];
}

/** סיום העסקה — סימון, לא מחיקה. הקישורים והמכשירים מתבטלים. */
function empEnd(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, EMP_EDIT);
    $e = empRow($companyId, intIn($in, 'id'), $site);
    $end = validDate(str($in, 'end_date', 10), 'תאריך סיום');
    $now = nowIso();
    db()->prepare("UPDATE members SET status = 'left', end_date = ? WHERE id = ?")->execute([$end, $e['id']]);
    db()->prepare('UPDATE invites SET revoked_at = ? WHERE member_id = ? AND used_at IS NULL AND revoked_at IS NULL')->execute([$now, $e['id']]);
    db()->prepare('UPDATE devices SET revoked_at = ? WHERE member_id = ? AND revoked_at IS NULL')->execute([$now, $e['id']]);
    audit($companyId, (int) $person['id'], 'employee.end', 'members:' . $e['id'], ['status' => $e['status']], ['status' => 'left', 'end_date' => $end]);
    return empGet($person, ['company_id' => $companyId, 'id' => $e['id']]);
}
