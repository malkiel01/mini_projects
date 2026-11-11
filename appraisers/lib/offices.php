<?php
/**
 * משרדים, חברות והזמנות (אפיון 01 §6). כל פעולה משנה רצה בטרנזקציה אחת עם השורה שלה ביומן הפעולות.
 * ההרשאה נבדקת לפני כן, ב-lib/access.php; כאן — הכללים של התחום (מנהל אחרון, הזמנה בתוקף).
 * מזהה המשרד מגיע תמיד מההקשר (apr_office_id) — אף פעם לא מהדפדפן.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/invites.php';

const APR_INVITE_TTL = 14 * 86400;

// ── הקמה, החלפה, שם, עזיבה ───────────────────────────────────

/**
 * הקמה עצמית: כל משתמש פעיל מקים משרד, והופך לחבר פעיל עם is_admin. המשרד פעיל מיד. נשלטת בהגדרת המערכת
 * office_self_create (ברירת המחדל: דלוק). op — מזהה פעולה: לחיצה כפולה = משרד אחד.
 */
function apr_office_create(array $ctx, array $in): array
{
    $name = apr_clean_name($in['name'] ?? null);
    $role = apr_clean_role($in['role'] ?? 'appraiser');
    $op = is_string($in['op'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $in['op']) ? $in['op'] : null;
    $uid = (int)$ctx['user']['id'];
    if (!(int)$ctx['user']['is_sysadmin'] && !apr_setting('office_self_create')) apr_fail('self_create_off');
    return apr_tx(function () use ($ctx, $name, $role, $op, $uid) {
        if ($op !== null) {
            $prev = apr_row("SELECT entity_pid FROM audit_log WHERE action = 'office.create' AND actor_user_id = ? AND json_extract(meta, '$.op') = ?", [$uid, $op]);
            if ($prev) return ['office' => $prev['entity_pid'], 'repeat' => true];
        }
        $now = apr_now();
        $pid = apr_pid();
        $office = apr_insert('offices', ['pid' => $pid, 'name' => $name, 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
        apr_insert('memberships', ['office_id' => $office, 'user_id' => $uid, 'role' => $role, 'is_admin' => 1, 'created_at' => $now, 'updated_at' => $now]);
        apr_audit('office.create', 'office', $pid, array_filter(['op' => $op]), office: $office);
        apr_audit('member.join', 'user', $ctx['user']['pid'], ['role' => $role, 'is_admin' => true, 'via' => 'create'], office: $office);
        apr_exec('UPDATE sessions SET active_office_id = ? WHERE id = ?', [$office, $ctx['session']]);
        return ['office' => $pid];
    });
}

/** החלפת המשרד הפעיל — רק למשרד שאני חבר בו (גם מנהל המערכת). כל השאר: "לא נמצא". */
function apr_office_switch(array $ctx, array $in): array
{
    foreach ($ctx['offices'] as $o) {
        if ($o['pid'] === ($in['office'] ?? null)) {
            apr_exec('UPDATE sessions SET active_office_id = ? WHERE id = ?', [$o['office_id'], $ctx['session']]);
            return [];
        }
    }
    apr_fail('not_found');
}

function apr_office_rename(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $name = apr_clean_name($in['name'] ?? null);
    return apr_tx(function () use ($ctx, $office, $name) {
        if ($name !== $ctx['office']['name']) {
            apr_exec('UPDATE offices SET name = ?, updated_at = ? WHERE id = ?', [$name, apr_now(), $office]);
            apr_audit('office.rename', 'office', $ctx['office']['pid'], [], office: $office);
        }
        return [];
    });
}

function apr_active_admins(int $office, int $exceptUser = 0): int
{
    return (int)apr_val("SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id
        WHERE m.office_id = ? AND m.is_admin = 1 AND m.status = 'active' AND m.deleted_at IS NULL AND u.status = 'active' AND m.user_id <> ?", [$office, $exceptUser]);
}

function apr_office_leave(array $ctx): array
{
    $office = apr_office_id($ctx);
    $uid = (int)$ctx['user']['id'];
    return apr_tx(function () use ($ctx, $office, $uid) {
        if ((int)$ctx['office']['is_admin'] && !apr_active_admins($office, $uid)) apr_fail('last_admin_leave');
        apr_exec('UPDATE memberships SET deleted_at = ?, updated_at = ?, is_admin = 0 WHERE id = ?', [apr_now(), apr_now(), $ctx['office']['membership_id']]);
        apr_exec('UPDATE sessions SET active_office_id = NULL WHERE user_id = ? AND active_office_id = ?', [$uid, $office]);
        apr_audit('member.leave', 'user', $ctx['user']['pid'], [], office: $office);
        if (function_exists('apr_profiles_on_leave')) apr_profiles_on_leave($office, $uid);
        if (function_exists('apr_cases_on_member_out')) apr_cases_on_member_out($office, $uid);
        return [];
    });
}

// ── המשרד שלי: צוות והזמנות ──────────────────────────────────

/** חבר רואה שם ותפקיד של הצוות; מנהל רואה גם מייל, מצב, והזמנות ממתינות. */
function apr_office_view(array $ctx): array
{
    $office = apr_office_id($ctx);
    $admin = (bool)$ctx['office']['is_admin'];
    $rows = apr_rows('SELECT u.pid, u.name, u.email, u.last_seen_at, u.status AS user_status, m.role, m.is_admin, m.status, m.title, m.license_no
        FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ? AND m.deleted_at IS NULL' . ($admin ? '' : " AND m.status = 'active'") . '
        ORDER BY m.status, u.name, u.id', [$office]);
    $out = ['office' => ['pid' => $ctx['office']['pid'], 'name' => $ctx['office']['name'], 'portal_always_otp' => (bool)apr_val('SELECT portal_always_otp FROM offices WHERE id = ?', [$office])], 'can_manage' => $admin,
        'members' => array_map(function ($r) use ($ctx, $admin) {
            $m = ['pid' => $r['pid'], 'name' => $r['name'], 'role' => $r['role'], 'is_admin' => (bool)$r['is_admin'], 'title' => $r['title'],
                'me' => $r['pid'] === $ctx['user']['pid']];
            // account_suspended — החשבון עצמו הושעה בידי מנהל המערכת; מנהל המשרד רואה, אבל לא יכול לבטל
            return $admin ? $m + ['email' => $r['email'], 'status' => $r['status'], 'account_suspended' => $r['user_status'] === 'suspended', 'license_no' => $r['license_no'], 'last_seen_at' => $r['last_seen_at']] : $m;
        }, $rows)];
    if ($admin) {
        apr_invites_expire();
        $out['invites'] = array_map(fn($i) => ['pid' => $i['pid'], 'email' => $i['email'], 'role' => $i['role'], 'is_admin' => (bool)$i['is_admin'],
            'expires_at' => $i['expires_at'], 'created_at' => $i['created_at']] + apr_invite_state($i),
            apr_rows("SELECT * FROM invitations WHERE office_id = ? AND status = 'pending' ORDER BY id DESC", [$office]));
    }
    return $out;
}

/** חבר של המשרד שלי לפי pid. חבר של משרד אחר, או pid שלא קיים — אותה תשובה. */
function apr_member(int $office, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT m.*, u.pid AS user_pid, u.status AS user_status FROM memberships m JOIN users u ON u.id = m.user_id
        WHERE m.office_id = ? AND u.pid = ? AND m.deleted_at IS NULL', [$office, $pid]) : null) ?? apr_fail('not_found');
}

/** שינוי תפקיד, ניהול, השעיה, תיאור ומספר רישיון. נכנס לתוקף בבקשה הבאה של אותו משתמש. */
function apr_member_update(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    return apr_tx(function () use ($ctx, $in, $office) {
        $m = apr_member($office, $in['user'] ?? null);
        $self = (int)$m['user_id'] === (int)$ctx['user']['id'];
        $role = array_key_exists('role', $in) ? apr_clean_role($in['role']) : $m['role'];
        $isAdmin = array_key_exists('is_admin', $in) ? (int)($in['is_admin'] === true) : (int)$m['is_admin'];
        $status = $in['status'] ?? $m['status'];
        if (!in_array($status, ['active', 'suspended'], true)) apr_fail('status_invalid');
        $title = array_key_exists('title', $in) ? apr_clean_text($in['title'], 80) : $m['title'];
        $license = array_key_exists('license_no', $in) ? apr_clean_text($in['license_no'], 30) : $m['license_no'];

        if ($self && $status === 'suspended') apr_fail('self_action');
        $wasAdmin = (int)$m['is_admin'] && $m['status'] === 'active';
        if ($wasAdmin && (!$isAdmin || $status !== 'active') && !apr_active_admins($office, (int)$m['user_id'])) apr_fail('last_admin');

        // אחראי על תיק חייב להיות שמאי: לא מורידים מתפקיד שמאי את מי שאחראי על תיקים פתוחים — קודם מעבירים אחריות
        if ($m['role'] === 'appraiser' && $role !== 'appraiser' && function_exists('apr_cases_responsible_open')
            && ($n = apr_cases_responsible_open($office, (int)$m['user_id'])) > 0) {
            throw new AprError('responsible_has_cases', "החבר אחראי על $n תיקים פתוחים — העבירו את האחריות לפני שינוי התפקיד");
        }

        apr_exec('UPDATE memberships SET role = ?, is_admin = ?, status = ?, title = ?, license_no = ?, updated_at = ? WHERE id = ?',
            [$role, $isAdmin, $status, $title, $license, apr_now(), $m['id']]);
        $log = fn(string $action, array $meta = []) => apr_audit($action, 'user', $m['user_pid'], $meta, office: $office);
        if ($role !== $m['role']) $log('member.role', ['from' => $m['role'], 'to' => $role]);
        if ($isAdmin !== (int)$m['is_admin']) $log($isAdmin ? 'member.admin_on' : 'member.admin_off');
        if ($status !== $m['status']) $log($status === 'suspended' ? 'member.suspend' : 'member.unsuspend');
        // חבר שהושעה במשרד מאבד את ההשתתפות בכל תיקי המשרד
        if ($status === 'suspended' && $m['status'] !== 'suspended' && function_exists('apr_cases_on_member_out')) apr_cases_on_member_out($office, (int)$m['user_id']);
        return [];
    });
}

/** הסרה — מחיקה רכה של החברות; החשבון נשאר. */
function apr_member_remove(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    return apr_tx(function () use ($ctx, $in, $office) {
        $m = apr_member($office, $in['user'] ?? null);
        if ((int)$m['user_id'] === (int)$ctx['user']['id']) apr_fail('self_action');
        if ((int)$m['is_admin'] && $m['status'] === 'active' && !apr_active_admins($office, (int)$m['user_id'])) apr_fail('last_admin');
        apr_exec('UPDATE memberships SET deleted_at = ?, updated_at = ?, is_admin = 0, inbox_handler = 0 WHERE id = ?', [apr_now(), apr_now(), $m['id']]);   // גם ההסמכה לתור השיוך (08)
        apr_exec('UPDATE sessions SET active_office_id = NULL WHERE user_id = ? AND active_office_id = ?', [$m['user_id'], $office]);
        apr_audit('member.remove', 'user', $m['user_pid'], ['role' => $m['role']], office: $office);
        if (function_exists('apr_profiles_on_leave')) apr_profiles_on_leave($office, (int)$m['user_id']);
        if (function_exists('apr_cases_on_member_out')) apr_cases_on_member_out($office, (int)$m['user_id']);
        return [];
    });
}

// ── הזמנות ───────────────────────────────────────────────────

/** הזמנות שעבר זמנן מסומנות "פג". */
function apr_invites_expire(): int
{
    return apr_exec("UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at <= ?", [apr_now()]);
}

/**
 * הזמנה לפי מייל, בתוקף 14 יום. האסימון חוזר **פעם אחת** (לקישור שהמנהל מעתיק); במסד נשמר רק ה-hash.
 * הזמנה חדשה לאותו מייל באותו משרד מבטלת את הקודמת. אחרי השמירה — פתיחת חשבון במעטפת ומייל (lib/invites.php).
 * op — מזהה פעולה: אותה בקשה פעמיים = הזמנה אחת, בקשת provision אחת ומייל אחד.
 */
function apr_invite_create(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $email = apr_clean_email($in['email'] ?? null);
    $role = apr_clean_role($in['role'] ?? null);
    $isAdmin = (int)(($in['is_admin'] ?? false) === true);
    $name = apr_clean_text($in['name'] ?? '', 120) ?? '';
    $op = is_string($in['op'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $in['op']) ? $in['op'] : null;
    $r = apr_tx(function () use ($ctx, $office, $email, $role, $isAdmin, $name, $op) {
        if ($op !== null && ($prev = apr_row('SELECT * FROM invitations WHERE office_id = ? AND op = ?', [$office, $op]))) {
            if ($prev['provision_status'] !== null || $prev['status'] !== 'pending') {
                return ['invite' => $prev['pid'], 'expires_at' => $prev['expires_at'], 'repeat' => true] + apr_invite_state($prev);
            }
            // הבקשה הקודמת נקטעה אחרי השמירה ולפני המשלוח (קריסה): משלימים עכשיו, עם קישור חדש — האסימון הקודם לא נשמר
            $token = bin2hex(random_bytes(24));
            apr_exec('UPDATE invitations SET token_hash = ? WHERE id = ?', [hash('sha256', $token), $prev['id']]);
            return ['id' => (int)$prev['id'], 'invite' => $prev['pid'], 'token' => $token, 'expires_at' => $prev['expires_at']];
        }
        // המוזמן כבר חבר — לא נשלחת בקשה למעטפת
        if (apr_val('SELECT 1 FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ? AND u.email = ? AND m.deleted_at IS NULL', [$office, $email])) {
            apr_fail('already_member');
        }
        // שלוש תקרות ליממה: למשרד, למשתמש המזמין (אחרת מקימים עוד משרד ועוקפים), ולמערכת כולה
        $since = apr_iso(apr_time() - 86400);
        if ((int)apr_val('SELECT COUNT(*) FROM invitations WHERE office_id = ? AND created_at > ?', [$office, $since]) >= APR_INVITES_PER_DAY
            || (int)apr_val('SELECT COUNT(*) FROM invitations WHERE invited_by = ? AND created_at > ?', [$ctx['user']['id'], $since]) >= APR_INVITES_PER_DAY
            || (int)apr_val('SELECT COUNT(*) FROM invitations WHERE created_at > ?', [$since]) >= apr_setting('invites_per_day')) {
            apr_fail('invite_limit');
        }
        return apr_invite_insert($office, $email, $role, $isAdmin, (int)$ctx['user']['id'], $name, $op);
    });
    if (!empty($r['repeat'])) return $r;
    $id = (int)$r['id'];
    unset($r['id']);
    return $r + apr_invite_deliver($id, $r['token']);
}

/** יוצר הזמנה (בתוך טרנזקציה), ומבטל הזמנה ממתינה קודמת לאותו מייל באותו משרד. מחזיר את האסימון — פעם אחת. */
function apr_invite_insert(int $office, string $email, string $role, int $isAdmin, int $by, string $name = '', ?string $op = null): array
{
    $now = apr_now();
    foreach (apr_rows("SELECT pid FROM invitations WHERE office_id = ? AND email = ? AND status = 'pending'", [$office, $email]) as $old) {
        apr_exec("UPDATE invitations SET status = 'revoked', revoked_at = ? WHERE pid = ?", [$now, $old['pid']]);
        apr_audit('invite.revoke', 'invitation', $old['pid'], ['by' => 'replaced'], office: $office);
    }
    $token = bin2hex(random_bytes(24));
    $pid = apr_pid();
    $until = apr_iso(apr_time() + APR_INVITE_TTL);
    $id = apr_insert('invitations', ['pid' => $pid, 'office_id' => $office, 'email' => $email, 'role' => $role, 'is_admin' => $isAdmin, 'name' => $name, 'op' => $op,
        'token_hash' => hash('sha256', $token), 'invited_by' => $by, 'expires_at' => $until, 'created_at' => $now]);
    apr_audit('invite.create', 'invitation', $pid, ['role' => $role, 'is_admin' => (bool)$isAdmin], office: $office);
    return ['id' => $id, 'invite' => $pid, 'token' => $token, 'expires_at' => $until];
}

function apr_invite_mine(int $office, mixed $pid): array
{
    apr_invites_expire();
    return (apr_is_pid($pid) ? apr_row("SELECT * FROM invitations WHERE office_id = ? AND pid = ? AND status = 'pending'", [$office, $pid]) : null) ?? apr_fail('not_found');
}

/** "העתקת קישור" להזמנה קיימת: במסד יש רק hash, ולכן מונפק אסימון חדש — והקישור הקודם מפסיק לעבוד. */
function apr_invite_link(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    return apr_tx(function () use ($in, $office) {
        $i = apr_invite_mine($office, $in['invite'] ?? null);
        $token = bin2hex(random_bytes(24));
        apr_exec('UPDATE invitations SET token_hash = ? WHERE id = ?', [hash('sha256', $token), $i['id']]);
        return ['invite' => $i['pid'], 'token' => $token, 'expires_at' => $i['expires_at']];
    });
}

function apr_invite_revoke(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    return apr_tx(function () use ($in, $office) {
        $i = apr_invite_mine($office, $in['invite'] ?? null);
        apr_exec("UPDATE invitations SET status = 'revoked', revoked_at = ? WHERE id = ?", [apr_now(), $i['id']]);
        apr_audit('invite.revoke', 'invitation', $i['pid'], [], office: $office);
        return [];
    });
}

/**
 * ההזמנה שהמשתמש מנסה לקבל — לפי אסימון (הקישור) או לפי pid (מהרשימה "ההזמנות שלי", רק למייל מאומת).
 * פגה, בוטלה, נוצלה או לא קיימת → אותה תשובה בדיוק. מייל אחר → "נשלחה לכתובת אחרת", בלי לחשוף לאיזו.
 */
function apr_invite_for(array $ctx, array $in): array
{
    apr_invites_expire();
    $u = $ctx['user'];
    $i = null;
    if (is_string($in['token'] ?? null) && preg_match('/^[a-f0-9]{48}$/', $in['token'])) {
        $i = apr_row("SELECT * FROM invitations WHERE token_hash = ? AND status = 'pending'", [hash('sha256', $in['token'])]);
        // ה-sub שהמעטפת החזירה כשפתחה את החשבון מזהה את המוזמן גם בלי התאמת מייל
        if ($i && !apr_invite_is_for($i, $u) && ($u['email'] === null || $u['email'] !== $i['email'])) apr_fail('invite_other_email');
    } elseif (apr_is_pid($in['invite'] ?? null)) {
        $i = apr_row("SELECT * FROM invitations WHERE pid = ? AND status = 'pending'", [$in['invite']]);
        if ($i && !apr_invite_is_for($i, $u) && !($u['email'] !== null && (int)$u['email_verified'] && $u['email'] === $i['email'])) $i = null;
    }
    $i ??= apr_fail('invite_invalid');
    $o = apr_row("SELECT * FROM offices WHERE id = ? AND status = 'active'", [$i['office_id']]) ?? apr_fail('invite_invalid');
    return [$i, $o];
}

/** ההזמנה נפתחה במעטפת בשביל החשבון הזה (invitee_sub = ה-sub שלו). */
function apr_invite_is_for(array $i, array $u): bool
{
    return $i['invitee_sub'] !== null && $u['platform_sub'] !== null && hash_equals((string)$i['invitee_sub'], (string)$u['platform_sub']);
}

function apr_invite_info(array $ctx, array $in): array
{
    [$i, $o] = apr_invite_for($ctx, $in);
    return ['invite' => ['office_name' => $o['name'], 'role' => $i['role'], 'is_admin' => (bool)$i['is_admin'], 'expires_at' => $i['expires_at']]];
}

/** ההזמנות הממתינות לי: למייל שלי כשהוא מאומת, ולחשבון שהמעטפת פתחה בשבילי (invitee_sub) — גם בלי מייל מאומת. */
function apr_my_invites(array $ctx): array
{
    $u = $ctx['user'];
    $verified = $u['email'] !== null && (int)$u['email_verified'];
    apr_invites_expire();
    return ['email' => $u['email'], 'verified' => (bool)$verified, 'invites' => array_map(
        fn($r) => ['pid' => $r['pid'], 'office_name' => $r['name'], 'role' => $r['role'], 'is_admin' => (bool)$r['is_admin'], 'expires_at' => $r['expires_at']],
        apr_rows("SELECT i.pid, i.role, i.is_admin, i.expires_at, o.name FROM invitations i JOIN offices o ON o.id = i.office_id
            WHERE (i.email = ? OR (i.invitee_sub IS NOT NULL AND i.invitee_sub = ?)) AND i.status = 'pending' AND o.status = 'active' ORDER BY i.id DESC",
            [$verified ? $u['email'] : '', (string)$u['platform_sub']]))];
}

function apr_invite_accept(array $ctx, array $in): array
{
    return apr_tx(function () use ($ctx, $in) {
        [$i, $o] = apr_invite_for($ctx, $in);
        $uid = (int)$ctx['user']['id'];
        $now = apr_now();
        $office = (int)$o['id'];
        $m = apr_row('SELECT * FROM memberships WHERE office_id = ? AND user_id = ?', [$office, $uid]);
        apr_exec("UPDATE invitations SET status = 'accepted', accepted_by = ?, accepted_at = ? WHERE id = ?", [$uid, $now, $i['id']]);
        apr_audit('invite.accept', 'invitation', $i['pid'], [], office: $office);
        if ($m && $m['deleted_at'] === null) {
            // כבר חבר/ה — ההזמנה מסומנת כנוצלה, והחברות לא משתנה
            return ['office' => $o['pid'], 'already' => true];
        }
        if ($m) {
            apr_exec("UPDATE memberships SET role = ?, is_admin = ?, status = 'active', deleted_at = NULL, invited_by = ?, updated_at = ? WHERE id = ?",
                [$i['role'], $i['is_admin'], $i['invited_by'], $now, $m['id']]);
        } else {
            apr_insert('memberships', ['office_id' => $office, 'user_id' => $uid, 'role' => $i['role'], 'is_admin' => $i['is_admin'],
                'invited_by' => $i['invited_by'], 'created_at' => $now, 'updated_at' => $now]);
        }
        apr_audit('member.join', 'user', $ctx['user']['pid'], ['role' => $i['role'], 'is_admin' => (bool)$i['is_admin'], 'via' => 'invite'], office: $office);
        apr_exec('UPDATE sessions SET active_office_id = ? WHERE id = ?', [$office, $ctx['session']]);
        return ['office' => $o['pid']];
    });
}
