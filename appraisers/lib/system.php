<?php
/**
 * מנהל המערכת (אפיון 01 §7): רשימת המשרדים והמשתמשים — **נתונים תפעוליים בלבד**. על משרד הוא רואה שם, מצב, תאריך
 * הקמה, מספר חברים, פעילות אחרונה, ורשימת החברים (שם, מייל, תפקיד, מצב). לא יותר — ואין לו דרך להיכנס למשרד שאינו
 * חבר בו. השעיה וביטול השעיה — עם נימוק. תמיד נשאר מנהל מערכת פעיל אחד לפחות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/offices.php';

function apr_sys_offices(): array
{
    $rows = apr_rows("SELECT o.pid, o.name, o.status, o.created_at,
            (SELECT COUNT(*) FROM memberships m WHERE m.office_id = o.id AND m.deleted_at IS NULL) AS members,
            (SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = o.id AND m.deleted_at IS NULL AND m.status = 'active' AND u.status = 'active') AS active_members,
            (SELECT MAX(a.at) FROM audit_log a WHERE a.office_id = o.id) AS last_activity
        FROM offices o ORDER BY o.status, o.name, o.id");
    return ['offices' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name'], 'status' => $r['status'], 'created_at' => $r['created_at'],
        'members' => (int)$r['members'], 'no_active_members' => (int)$r['active_members'] === 0, 'last_activity' => $r['last_activity']], $rows)];
}

function apr_sys_office_row(mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM offices WHERE pid = ?', [$pid]) : null) ?? apr_fail('not_found');
}

function apr_sys_office(array $q): array
{
    $o = apr_sys_office_row($q['office'] ?? null);
    return ['office' => ['pid' => $o['pid'], 'name' => $o['name'], 'status' => $o['status'], 'created_at' => $o['created_at']],
        'members' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'],
            'is_admin' => (bool)$r['is_admin'], 'status' => $r['user_status'] === 'suspended' ? 'suspended' : $r['status']],
            apr_rows('SELECT u.pid, u.name, u.email, u.status AS user_status, m.role, m.is_admin, m.status FROM memberships m JOIN users u ON u.id = m.user_id
                WHERE m.office_id = ? AND m.deleted_at IS NULL ORDER BY u.name, u.id', [$o['id']]))];
}

function apr_sys_office_status(array $in): array
{
    $status = in_array($in['status'] ?? null, ['active', 'suspended'], true) ? $in['status'] : apr_fail('status_invalid');
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($in, $status, $reason) {
        $o = apr_sys_office_row($in['office'] ?? null);
        if ($o['status'] !== $status) {
            apr_exec('UPDATE offices SET status = ?, updated_at = ? WHERE id = ?', [$status, apr_now(), $o['id']]);
            apr_audit($status === 'suspended' ? 'office.suspend' : 'office.unsuspend', 'office', $o['pid'], [], $reason, (int)$o['id']);
        }
        return [];
    });
}

function apr_sys_users(): array
{
    return ['users' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name'], 'email' => $r['email'], 'status' => $r['status'],
        'is_sysadmin' => (bool)$r['is_sysadmin'], 'is_developer' => (bool)$r['is_developer'], 'offices' => (int)$r['offices'],
        'last_seen_at' => $r['last_seen_at'], 'created_at' => $r['created_at']],
        apr_rows('SELECT u.*, (SELECT COUNT(*) FROM memberships m WHERE m.user_id = u.id AND m.deleted_at IS NULL) AS offices FROM users u ORDER BY u.status, u.name, u.id'))];
}

function apr_sys_user_row(mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM users WHERE pid = ?', [$pid]) : null) ?? apr_fail('not_found');
}

function apr_active_sysadmins(int $except): int
{
    return (int)apr_val("SELECT COUNT(*) FROM users WHERE is_sysadmin = 1 AND status = 'active' AND id <> ?", [$except]);
}

function apr_sys_user_status(array $in): array
{
    $status = in_array($in['status'] ?? null, ['active', 'suspended'], true) ? $in['status'] : apr_fail('status_invalid');
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($in, $status, $reason) {
        $u = apr_sys_user_row($in['user'] ?? null);
        if ($u['status'] === $status) return [];
        if ($status === 'suspended' && (int)$u['is_sysadmin'] && !apr_active_sysadmins((int)$u['id'])) apr_fail('last_sysadmin');
        apr_exec('UPDATE users SET status = ?, updated_at = ? WHERE id = ?', [$status, apr_now(), $u['id']]);
        apr_audit($status === 'suspended' ? 'user.suspend' : 'user.unsuspend', 'user', $u['pid'], [], $reason);
        return [];
    });
}

/** הענקה וביטול של is_sysadmin. (is_developer אינו ניתן כאן — הוא שווה תמיד ל-platform_admin שבזהות.) */
function apr_sys_user_flags(array $in): array
{
    return apr_tx(function () use ($in) {
        $u = apr_sys_user_row($in['user'] ?? null);
        $on = (int)(($in['is_sysadmin'] ?? null) === true);
        if (!array_key_exists('is_sysadmin', $in) || $on === (int)$u['is_sysadmin']) return [];
        if (!$on && $u['status'] === 'active' && !apr_active_sysadmins((int)$u['id'])) apr_fail('last_sysadmin');
        apr_exec('UPDATE users SET is_sysadmin = ?, updated_at = ? WHERE id = ?', [$on, apr_now(), $u['id']]);
        apr_audit($on ? 'user.grant_sysadmin' : 'user.revoke_sysadmin', 'user', $u['pid']);
        return [];
    });
}

/**
 * מנהל המערכת מקים משרד: שם, ומייל של המנהל הראשון. נוצר משרד פעיל **בלי אף חבר**, והזמנה למייל הזה עם is_admin.
 * מנהל המערכת אינו הופך לחבר במשרד, ואינו רואה את תוכנו. האסימון של ההזמנה חוזר פעם אחת — לקישור שהוא מוסר.
 */
function apr_sys_office_create(array $ctx, array $in): array
{
    $name = apr_clean_name($in['name'] ?? null);
    $email = apr_clean_email($in['admin_email'] ?? null);
    $role = apr_clean_role($in['role'] ?? 'appraiser');
    return apr_tx(function () use ($ctx, $name, $email, $role) {
        $now = apr_now();
        $pid = apr_pid();
        $office = apr_insert('offices', ['pid' => $pid, 'name' => $name, 'created_by' => $ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now]);
        apr_audit('office.create', 'office', $pid, ['by' => 'sysadmin'], office: $office);
        return ['office' => $pid] + apr_invite_insert($office, $email, $role, 1, (int)$ctx['user']['id']);
    });
}

function apr_sys_settings(): array
{
    $out = [];
    foreach (APR_SETTINGS as $name => $default) $out[$name] = apr_setting($name);
    return ['settings' => $out];
}

function apr_sys_setting_set(array $in): array
{
    $name = is_string($in['name'] ?? null) && array_key_exists($in['name'], APR_SETTINGS) ? $in['name'] : apr_fail('setting_invalid');
    if (!is_bool($in['value'] ?? null)) apr_fail('setting_invalid');
    return apr_tx(function () use ($name, $in) {
        if (apr_setting($name) !== $in['value']) {
            apr_meta_set('setting.' . $name, $in['value'] ? '1' : '0');
            apr_audit('setting.change', 'setting', $name, ['to' => $in['value']]);
        }
        return [];
    });
}
