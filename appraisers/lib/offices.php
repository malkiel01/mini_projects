<?php
/**
 * משרדים, חברות במשרד ותפקידים (אפיון 0.6, פרק 3).
 *
 * הכלל שהכול נשען עליו: **המשרד של הבקשה נקבע בשרת** — מה-session ומהחברות של המשתמש — ולעולם לא ממזהה שהדפדפן שלח.
 * כל שאילתה על נתוני משרד מקבלת את המזהה מ-apr_office_id($ctx), ולכן משרד לא רואה נתונים של משרד אחר.
 *
 * התפקידים במשרד: office_admin (מנהל משרד), appraiser (שמאי), associate (שמאי שותף / מתמחה), staff (עובד משרד).
 * מנהל הפלטפורמה מנהל משרדים (פותח, משעה, ממנה מנהל ראשון) — ואינו מקבל מעצם תפקידו גישה לתוכן של משרד. כדי
 * לעבוד בתוך משרד הוא צריך חברות בו, כמו כל אחד, והיא נרשמת ביומן הפעולות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

const APR_ROLES = ['office_admin' => 'מנהל משרד', 'appraiser' => 'שמאי', 'associate' => 'שמאי שותף / מתמחה', 'staff' => 'עובד משרד'];

/** החברויות הפעילות של המשתמש, במשרדים פעילים. */
function apr_memberships(int $userId): array
{
    return apr_rows("SELECT m.office_id, m.role, o.name FROM memberships m JOIN offices o ON o.id = m.office_id
        WHERE m.user_id = ? AND m.status = 'active' AND o.status = 'active' ORDER BY o.name, o.id", [$userId]);
}

/**
 * המשרד שהמשתמש עובד בו עכשיו: זה שנבחר ב-session, אם החברות עדיין בתוקף; אחרת — היחיד שיש לו.
 * כמה משרדים ולא נבחר אחד → null, והמסך מבקש לבחור (אין ניחוש).
 */
function apr_active_membership(array $memberships, ?int $sessionOffice): ?array
{
    foreach ($memberships as $m) {
        if ($sessionOffice !== null && (int)$m['office_id'] === $sessionOffice) return $m;
    }
    return count($memberships) === 1 ? $memberships[0] : null;
}

function apr_office_id(array $ctx): int
{
    if (!$ctx['office']) throw new AprError($ctx['offices'] ? 'בחרו משרד' : 'אינכם משויכים למשרד', 403);
    return (int)$ctx['office']['office_id'];
}

function apr_require_role(array $ctx, string ...$roles): int
{
    $office = apr_office_id($ctx);
    if (!in_array($ctx['office']['role'], $roles, true)) throw new AprError('אין לכם הרשאה לפעולה הזו', 403);
    return $office;
}

function apr_require_pm(array $ctx): void
{
    if (!(int)$ctx['user']['is_platform_manager']) throw new AprError('פעולה של מנהל הפלטפורמה בלבד', 403);
}

function apr_require_dev(array $ctx): array
{
    if (!(int)$ctx['user']['is_developer']) throw new AprError('מסך המפתח — למפתח בלבד', 403);
    return $ctx['user'];
}

function apr_clean_name(mixed $v, string $what, int $max = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', is_string($v) ? $v : '') ?? '');
    if ($s === '') throw new AprError("חסר $what");
    if (mb_strlen($s) > $max) throw new AprError("$what ארוך מדי (עד $max תווים)");
    return $s;
}

function apr_clean_email(mixed $v): string
{
    $s = mb_strtolower(trim(is_string($v) ? $v : ''));
    if ($s === '' || mb_strlen($s) > 200 || !filter_var($s, FILTER_VALIDATE_EMAIL)) throw new AprError('כתובת מייל לא תקינה');
    return $s;
}

function apr_clean_role(mixed $v): string
{
    if (!is_string($v) || !isset(APR_ROLES[$v])) throw new AprError('תפקיד לא מוכר');
    return $v;
}

// ── בחירת משרד ───────────────────────────────────────────────

/** מעבר למשרד אחר שלי. המזהה מהדפדפן נבדק מול החברויות — לא סומכים עליו. */
function apr_office_switch(array $ctx, mixed $officeId): array
{
    $id = (int)$officeId;
    foreach ($ctx['offices'] as $m) {
        if ((int)$m['office_id'] === $id) {
            apr_exec('UPDATE sessions SET office_id = ? WHERE token_hash = ?', [$id, $ctx['session']]);
            return ['ok' => true];
        }
    }
    throw new AprError('המשרד לא נמצא', 404);   // אותה תשובה למשרד שלא קיים ולמשרד שאינני חבר בו
}

// ── מנהל הפלטפורמה: משרדים ───────────────────────────────────

/** כל המשרדים — שם, מצב וכמה חברים. בלי שום תוכן של המשרד. */
function apr_offices_all(array $ctx): array
{
    apr_require_pm($ctx);
    return ['offices' => apr_rows("SELECT o.id, o.name, o.status, o.created_at,
            (SELECT COUNT(*) FROM memberships m WHERE m.office_id = o.id AND m.status = 'active') AS members,
            (SELECT GROUP_CONCAT(u.display_name || CASE WHEN u.platform_sub IS NULL THEN ' (הוזמן)' ELSE '' END, ', ') FROM memberships m JOIN users u ON u.id = m.user_id
               WHERE m.office_id = o.id AND m.role = 'office_admin' AND m.status = 'active') AS admins
        FROM offices o ORDER BY o.status, o.name, o.id")];
}

/** משרד חדש, עם מנהל משרד ראשון לפי מייל (אפשר גם אני עצמי — חברות מפורשת, מתועדת). */
function apr_office_create(array $ctx, array $in): array
{
    apr_require_pm($ctx);
    $name = apr_clean_name($in['name'] ?? null, 'שם המשרד');
    $email = apr_clean_email($in['admin_email'] ?? null);
    $adminName = trim((string)($in['admin_name'] ?? ''));
    return apr_tx(function () use ($ctx, $name, $email, $adminName) {
        apr_exec('INSERT INTO offices (name, created_by, created_at) VALUES (?, ?, ?)', [$name, $ctx['user']['id'], apr_now()]);
        $office = (int)apr_db()->lastInsertId();
        apr_audit($ctx['user'], $office, 'office.created', 'office:' . $office, ['name' => $name]);
        $member = apr_membership_add($ctx['user'], $office, $email, $adminName, 'office_admin');
        return ['id' => $office, 'admin' => $member];
    });
}

function apr_office_update(array $ctx, array $in): array
{
    apr_require_pm($ctx);
    $office = apr_row('SELECT * FROM offices WHERE id = ?', [(int)($in['id'] ?? 0)]) ?? throw new AprError('המשרד לא נמצא', 404);
    $name = array_key_exists('name', $in) ? apr_clean_name($in['name'], 'שם המשרד') : $office['name'];
    $status = $in['status'] ?? $office['status'];
    if (!in_array($status, ['active', 'suspended'], true)) throw new AprError('מצב לא מוכר');
    apr_exec('UPDATE offices SET name = ?, status = ? WHERE id = ?', [$name, $status, $office['id']]);
    $changed = array_filter(['name' => $name !== $office['name'] ? $name : null, 'status' => $status !== $office['status'] ? $status : null]);
    if ($changed) apr_audit($ctx['user'], (int)$office['id'], 'office.updated', 'office:' . $office['id'], $changed);
    return ['ok' => true];
}

// ── חברי המשרד ───────────────────────────────────────────────

/** מוסיף חבר למשרד לפי מייל. אין חשבון כזה → נפתח חשבון "הוזמן", שיתקשר לאדם בכניסה הראשונה שלו דרך הפלטפורמה. */
function apr_membership_add(array $by, int $office, string $email, string $name, string $role): array
{
    $user = apr_row('SELECT * FROM users WHERE email = ? ORDER BY platform_sub IS NULL, id LIMIT 1', [$email]);
    if (!$user) {
        apr_exec('INSERT INTO users (email, display_name, created_at) VALUES (?, ?, ?)', [$email, mb_substr($name !== '' ? $name : $email, 0, 120), apr_now()]);
        $user = apr_row('SELECT * FROM users WHERE id = ?', [(int)apr_db()->lastInsertId()]);
    }
    if (apr_row('SELECT 1 FROM memberships WHERE office_id = ? AND user_id = ?', [$office, $user['id']])) {
        throw new AprError('המשתמש כבר חבר במשרד', 409);
    }
    apr_exec('INSERT INTO memberships (office_id, user_id, role, invited_by, created_at) VALUES (?, ?, ?, ?, ?)',
        [$office, $user['id'], $role, $by['id'], apr_now()]);
    apr_audit($by, $office, 'member.added', 'user:' . $user['id'], ['role' => $role]);
    return ['user_id' => (int)$user['id'], 'pending' => $user['platform_sub'] === null];
}

/** חברי המשרד שלי. כל חבר רואה את הצוות; רק מנהל המשרד משנה. */
function apr_members(array $ctx): array
{
    $office = apr_office_id($ctx);
    $rows = apr_rows('SELECT u.id, u.display_name, u.email, u.last_login_at, u.platform_sub IS NULL AS pending, u.status AS user_status, m.role, m.status
        FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ? ORDER BY m.status, u.display_name, u.id', [$office]);
    return ['office' => ['id' => $office, 'name' => $ctx['office']['name']], 'can_manage' => $ctx['office']['role'] === 'office_admin',
        'roles' => APR_ROLES,
        'members' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['display_name'], 'email' => $r['email'], 'role' => $r['role'],
            'status' => $r['user_status'] === 'suspended' ? 'suspended' : $r['status'], 'pending' => (bool)$r['pending'],
            'last_login_at' => $r['last_login_at'], 'me' => (int)$r['id'] === (int)$ctx['user']['id']], $rows)];
}

function apr_member_invite(array $ctx, array $in): array
{
    $office = apr_require_role($ctx, 'office_admin');
    $email = apr_clean_email($in['email'] ?? null);
    $role = apr_clean_role($in['role'] ?? null);
    $name = trim((string)($in['name'] ?? ''));
    return apr_tx(fn() => apr_membership_add($ctx['user'], $office, $email, $name, $role));
}

/** לפחות מנהל משרד פעיל אחד — אחרת אף אחד לא יכול לנהל את המשרד. */
function apr_guard_last_admin(int $office, int $userId): void
{
    $others = (int)apr_row("SELECT COUNT(*) AS n FROM memberships WHERE office_id = ? AND role = 'office_admin' AND status = 'active' AND user_id <> ?",
        [$office, $userId])['n'];
    if (!$others) throw new AprError('זה מנהל המשרד היחיד. קודם ממנים מנהל נוסף.', 409);
}

/** שינוי תפקיד או השעיה של חבר במשרד שלי. החבר מזוהה בתוך המשרד שלי בלבד — מזהה ממשרד אחר הוא "לא נמצא". */
function apr_member_update(array $ctx, array $in): array
{
    $office = apr_require_role($ctx, 'office_admin');
    $uid = (int)($in['user_id'] ?? 0);
    return apr_tx(function () use ($ctx, $in, $office, $uid) {
        $m = apr_row('SELECT * FROM memberships WHERE office_id = ? AND user_id = ?', [$office, $uid]) ?? throw new AprError('החבר לא נמצא', 404);
        $role = array_key_exists('role', $in) ? apr_clean_role($in['role']) : $m['role'];
        $status = $in['status'] ?? $m['status'];
        if (!in_array($status, ['active', 'suspended'], true)) throw new AprError('מצב לא מוכר');
        if ($m['role'] === 'office_admin' && $m['status'] === 'active' && ($role !== 'office_admin' || $status !== 'active')) {
            apr_guard_last_admin($office, $uid);
        }
        apr_exec('UPDATE memberships SET role = ?, status = ? WHERE id = ?', [$role, $status, $m['id']]);
        $changed = array_filter(['role' => $role !== $m['role'] ? $role : null, 'status' => $status !== $m['status'] ? $status : null]);
        if ($changed) apr_audit($ctx['user'], $office, 'member.updated', 'user:' . $uid, $changed);
        return ['ok' => true];
    });
}

function apr_member_remove(array $ctx, array $in): array
{
    $office = apr_require_role($ctx, 'office_admin');
    $uid = (int)($in['user_id'] ?? 0);
    return apr_tx(function () use ($ctx, $office, $uid) {
        $m = apr_row('SELECT * FROM memberships WHERE office_id = ? AND user_id = ?', [$office, $uid]) ?? throw new AprError('החבר לא נמצא', 404);
        if ($m['role'] === 'office_admin' && $m['status'] === 'active') apr_guard_last_admin($office, $uid);
        apr_exec('DELETE FROM memberships WHERE id = ?', [$m['id']]);
        apr_exec('UPDATE sessions SET office_id = NULL WHERE user_id = ? AND office_id = ?', [$uid, $office]);
        apr_audit($ctx['user'], $office, 'member.removed', 'user:' . $uid, ['role' => $m['role']]);
        return ['ok' => true];
    });
}
