<?php
/**
 * ההרשאות — המקום היחיד שמכריע (אפיון 01 §7). הנתב קורא ל-apr_authorize() לפני כל פעולה; המסכים רק מסתירים
 * כפתורים. פעולה שאינה בטבלה — לא קיימת.
 *
 * בידוד משרדים (00-overview §4.5): המשרד של הבקשה נקבע בשרת — המשרד הפעיל שב-session, אם החברות עדיין בתוקף.
 * בקשה יכולה לשאת office (pid): הוא חייב להיות המשרד הפעיל. משרד שאינני חבר בו ומשרד שלא קיים — אותה תשובה (404).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/auth.php';

const APR_ROLES = ['appraiser', 'trainee', 'staff'];

/**
 * מי רשאי: public (בלי משתמש) · token (אסימון DevLoop) · user (כל משתמש פעיל) · member (חבר פעיל במשרד פעיל) ·
 * admin (is_admin במשרד) · sysadmin · developer.
 */
const APR_ACCESS = [
    'health'              => 'public',
    'platform_key'        => 'public',
    'login-config'        => 'public', 'sso-start' => 'public', 'sso-callback' => 'public',
    'login'               => 'public', 'forgot' => 'public', 'reset' => 'public',
    // הפורטל לנמען (07): בלי משתמש — כל פעולה מאמתת את האסימון של הקישור בעצמה (lib/portal.php)
    'portal-open' => 'public', 'portal-otp-send' => 'public', 'portal-otp-verify' => 'public', 'portal-upload' => 'public',
    'portal-respond' => 'public', 'portal-file' => 'public', 'portal-client-error' => 'public',
    // קליטה נכנסת (08): webhook חתום — החתימה על הגוף הגולמי היא האימות (lib/inbound.php)
    'inbound-relay'       => 'public',
    'devapi-feed'         => 'token', 'devapi-comment' => 'token', 'devapi-file' => 'token', 'devapi-attach' => 'token',

    'me'                  => 'user',
    'office-switch'       => 'user',
    'office-create'       => 'user',
    'my-invites'          => 'user',
    'invite-info'         => 'user',
    'invite-accept'       => 'user',
    'devloop-client-error' => 'user',
    'logout'              => 'user',
    'password-set'        => 'user',
    'confirm-request'     => 'user',
    'confirm-verify'      => 'user',

    // פרופילים (03): ההרשאה לכל פרופיל מוכרעת ב-apr_profile_level() — מומלץ נראה גם למי שאין לו משרד
    'profile-lists'       => 'user', 'profiles' => 'user', 'profile' => 'user', 'profile-version' => 'user', 'profile-versions' => 'user',
    'profile-compare'     => 'user', 'profile-create' => 'user', 'profile-update' => 'user', 'profile-draft-open' => 'user',
    'profile-draft-save'  => 'user', 'profile-draft-info' => 'user', 'profile-draft-discard' => 'user', 'profile-check' => 'user',
    'profile-publish'     => 'user', 'profile-archive' => 'user', 'profile-delete' => 'user', 'profile-duplicate' => 'user',
    'profile-try'         => 'user', 'profile-shares' => 'user', 'profile-share-preview' => 'user', 'profile-share' => 'user',
    'profile-share-revoke' => 'user',
    'office-required-profile' => 'admin',

    // תיקים (04): חבר פעיל במשרד; ההרשאה לכל תיק — שורה ב-case_members (apr_case_get)
    'case-options' => 'member', 'case-create' => 'member', 'case-update' => 'member', 'case-plan' => 'member',
    'case-start' => 'member', 'case-status' => 'member', 'case-delete' => 'member', 'case' => 'member',
    'cases' => 'member', 'case-activity' => 'member', 'case-member-add' => 'member', 'case-member-update' => 'member',
    'case-member-remove' => 'member', 'case-profile-diff' => 'member', 'case-profile-apply' => 'member', 'case-identifier-add' => 'member',
    'case-identifier-update' => 'member', 'case-identifier-remove' => 'member', 'case-identifiers-conflict' => 'member', 'contacts' => 'member',
    'contact-save' => 'member', 'contact-delete' => 'member', 'case-party-add' => 'member', 'case-party-update' => 'member',
    'case-party-remove' => 'member', 'task-create' => 'member', 'task-update' => 'member', 'my-tasks' => 'member',
    'requirement-add' => 'member', 'requirement-update' => 'member',
    // מסמכים (06): ההרשאה לכל פעולה — documents / review_docs בתיק (lib/documents.php)
    'case-docs' => 'member', 'file' => 'member', 'file-thumb' => 'member', 'docs-search' => 'member', 'docs-pending' => 'member',
    'file-upload' => 'member', 'file-assign' => 'member', 'file-update' => 'member', 'file-delete' => 'member', 'file-review' => 'member',
    'requirement-status' => 'member', 'requirement-order' => 'member', 'requirement-split' => 'member',
    // בקשות (07): view בתיק לצפייה; contact ליצירה, שליחה, שליחה מחדש וביטול (lib/requests.php)
    'case-requests' => 'member', 'requests-failed' => 'member', 'request-preview' => 'member', 'request-send' => 'member',
    'request-resend' => 'member', 'request-revoke' => 'member', 'office-portal-otp' => 'admin',
    'office-cases' => 'admin', 'office-case-transfer' => 'admin',
    // תזכורות, ערוצים וקליטה נכנסת (08): contact בתיק לעצירה ולבדיקה; תור השיוך — מנהל המשרד ומי שהוסמך (lib/inbound.php)
    'reminders-stop' => 'member', 'message-check' => 'member', 'inbox' => 'member', 'inbox-assign' => 'member', 'inbox-dismiss' => 'member',
    'office-channels' => 'admin', 'office-channel-save' => 'admin', 'office-channel-status' => 'admin', 'office-channel-test' => 'admin',
    'office-inbox-handler' => 'admin',
    'sys-channels' => 'sysadmin', 'sys-channel-save' => 'sysadmin', 'sys-channel-status' => 'sysadmin', 'sys-channel-test' => 'sysadmin',
    'sys-channel-grant' => 'sysadmin', 'sys-inbox' => 'sysadmin', 'sys-inbox-dismiss' => 'sysadmin', 'sys-inbox-route' => 'sysadmin',
    'sys-inbox-reply' => 'sysadmin',

    'office'              => 'member',
    'office-leave'        => 'member',
    'office-rename'       => 'admin',
    'office-audit'        => 'admin',
    'invite-create'       => 'admin',
    'invite-link'         => 'admin',
    'invite-revoke'       => 'admin',
    'invite-resend'       => 'admin',
    'member-update'       => 'admin',
    'member-remove'       => 'admin',

    'sys-offices'         => 'sysadmin',
    'sys-office-create'   => 'sysadmin',
    'sys-settings'        => 'sysadmin',
    'sys-setting-set'     => 'sysadmin',
    'sys-office'          => 'sysadmin',
    'sys-office-status'   => 'sysadmin',
    'sys-users'           => 'sysadmin',
    'sys-user-status'     => 'sysadmin',
    'sys-user-flags'      => 'sysadmin',
    'sys-doc-types'       => 'sysadmin',
    'sys-doc-type-save'   => 'sysadmin',

    'dev-health'          => 'developer',
    'dev-audit'           => 'developer',
    'dev-jobs'            => 'developer',
    'dev-job-retry'       => 'developer',
    'dev-job-cancel'      => 'developer',
    'dev-cron-run'        => 'developer',
    'dev-mail'            => 'developer',
    'dev-items'           => 'developer', 'dev-task-create' => 'developer', 'dev-task-set' => 'developer', 'dev-comment' => 'developer',
    'dev-comment-delete'  => 'developer', 'dev-verify' => 'developer', 'dev-report' => 'developer', 'dev-attach' => 'developer',
    'dev-file'            => 'developer', 'dev-tokens' => 'developer', 'dev-token-create' => 'developer', 'dev-token-revoke' => 'developer',
    'dev-item-log'        => 'developer', 'dev-log-links' => 'developer', 'dev-task-from-log' => 'developer', 'dev-log' => 'developer',
];

/** פעולות שרק קוראות — GET. כל השאר: POST עם גוף JSON ועם X-CSRF. */
const APR_READS = ['inbox', 'office-channels', 'sys-channels', 'sys-inbox', 'case-requests', 'requests-failed', 'case-docs', 'file', 'file-thumb', 'docs-search', 'docs-pending', 'case-options', 'case-plan', 'case', 'cases', 'case-activity', 'case-profile-diff', 'contacts', 'my-tasks', 'office-cases',
    'profile-lists', 'profiles', 'profile', 'profile-version', 'profile-versions', 'profile-compare', 'profile-draft-info', 'profile-check',
    'profile-shares', 'sys-doc-types', 'health', 'platform_key', 'login-config', 'sso-start', 'sso-callback', 'dev-mail', 'devapi-feed', 'devapi-file', 'me', 'my-invites', 'office', 'office-audit', 'sys-offices', 'sys-office', 'sys-users', 'sys-settings',
    'dev-health', 'dev-audit', 'dev-jobs', 'dev-items', 'dev-file', 'dev-tokens', 'dev-item-log', 'dev-log'];

/**
 * ההקשר של הבקשה: המשתמש, ה-session והמשרד הפעיל.
 * $env: platform_user (ה-JSON מהשער או null), cookie, csrf, ua.
 * מחוברת: הזהות מהשער. מנותקת (אפיון 02 §5): ה-session לפי העוגייה בלבד — בלי session, 401 login_required.
 * 503 כשהמעטפת לא עונה; 401 בלי זהות או בלי הרשאה; 403 לחשבון מושעה.
 */
function apr_ctx(array $env): array
{
    $raw = $env['platform_user'] ?? null;
    $mode = apr_platform_mode($raw);
    if ($mode === 'unavailable') apr_fail('login_unavailable');
    if ($mode === 'connected') {
        $id = apr_platform_identity($raw);
        if (!$id || !$id['granted']) apr_fail('unauthenticated');
        $user = apr_user_for($id);
        apr_actor((int)$user['id']);
        if ($user['status'] !== 'active') apr_fail('account_suspended');   // ההשעיה גוברת — גם על מפתח
        $s = apr_session_for($user, (string)($env['cookie'] ?? ''), (string)($env['ua'] ?? ''));
    } else {
        [$user, $s] = apr_session_user((string)($env['cookie'] ?? '')) ?? apr_fail('login_required');
        apr_actor((int)$user['id']);
        if ($user['status'] !== 'active') apr_fail('account_suspended');
        apr_session_grant_check($user, $s);
    }
    if ($s['new_token'] !== null) $GLOBALS['apr_cookie_out'] = $s['new_token'];

    $offices = apr_my_offices((int)$user['id']);
    $active = null;
    foreach ($offices as $o) {
        if ($s['active_office_id'] !== null && (int)$o['office_id'] === (int)$s['active_office_id']) $active = $o;
    }
    // המשרד הפעיל האחרון כבר לא זמין (או שעוד לא נבחר) — הראשון הזמין
    if (!$active || $active['office_status'] !== 'active' || $active['status'] !== 'active') {
        foreach ($offices as $o) {
            if ($o['office_status'] === 'active' && $o['status'] === 'active') { $active = $o; break; }
        }
        $active ??= $offices[0] ?? null;
        if ($active && (int)$active['office_id'] !== (int)($s['active_office_id'] ?? 0)) {
            apr_exec('UPDATE sessions SET active_office_id = ? WHERE id = ?', [$active['office_id'], $s['id']]);
        }
    }
    return ['user' => $user, 'session' => (int)$s['id'], 'csrf' => $s['csrf'], 'offices' => $offices, 'office' => $active, 'mode' => $mode];
}

/** החברויות שלי שלא הוסרו — כולל משרד מושעה וחברות מושעית (כדי להסביר למשתמש מה קרה). */
function apr_my_offices(int $userId): array
{
    return apr_rows('SELECT m.id AS membership_id, m.office_id, m.role, m.is_admin, m.status, m.title, o.pid, o.name, o.status AS office_status
        FROM memberships m JOIN offices o ON o.id = m.office_id WHERE m.user_id = ? AND m.deleted_at IS NULL
        ORDER BY (o.status = \'active\' AND m.status = \'active\') DESC, o.name, o.id', [$userId]);
}

/** בודק שהמשתמש רשאי לפעולה. זורק not_found לפעולה שלא קיימת — וגם למי שאינו מפתח / מנהל מערכת (לא רומזים שהיא קיימת). */
function apr_authorize(array $ctx, string $action, array $in = []): void
{
    $rule = APR_ACCESS[$action] ?? apr_fail('not_found');
    $u = $ctx['user'];
    switch ($rule) {
        case 'user':
            return;
        case 'developer':
            if (!(int)$u['is_developer']) apr_fail('not_found');
            return;
        case 'sysadmin':
            if (!(int)$u['is_sysadmin']) apr_fail('not_found');
            return;
        case 'member':
        case 'admin':
            $o = apr_office($ctx, $in['office'] ?? null);
            if ($rule === 'admin' && !(int)$o['is_admin']) apr_fail('forbidden');
            return;
    }
    apr_fail('not_found');
}

/**
 * המשרד הפעיל של הבקשה — אחרי כל הבדיקות. $pid, אם נשלח, חייב להיות שלו:
 * משרד אחר שלי → office_changed (הלשונית לא מעודכנת); משרד שאינני חבר בו או שלא קיים → not_found.
 */
function apr_office(array $ctx, mixed $pid = null): array
{
    if ($pid !== null && $pid !== '') {
        $mine = array_filter($ctx['offices'], fn($o) => $o['pid'] === $pid);
        if (!$mine) apr_fail('not_found');
        if (!$ctx['office'] || $ctx['office']['pid'] !== $pid) apr_fail('office_changed');
    }
    $o = $ctx['office'] ?? apr_fail('no_office');
    if ($o['office_status'] !== 'active') apr_fail('office_suspended');
    if ($o['status'] !== 'active') apr_fail('forbidden');
    return $o;
}

function apr_office_id(array $ctx): int
{
    return (int)apr_office($ctx)['office_id'];
}

// ── ניקוי קלט ────────────────────────────────────────────────

function apr_clean_name(mixed $v, int $max = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', is_string($v) ? $v : '') ?? '');
    if ($s === '') apr_fail('name_required');
    if (mb_strlen($s) > $max) apr_fail('name_too_long');
    return $s;
}

function apr_clean_text(mixed $v, int $max): ?string
{
    $s = trim(preg_replace('/\s+/u', ' ', is_string($v) ? $v : '') ?? '');
    if (mb_strlen($s) > $max) apr_fail('text_too_long');
    return $s === '' ? null : $s;
}

function apr_clean_email(mixed $v): string
{
    $s = mb_strtolower(trim(is_string($v) ? $v : ''));
    if ($s === '' || mb_strlen($s) > 200 || !filter_var($s, FILTER_VALIDATE_EMAIL)) apr_fail('email_invalid');
    return $s;
}

function apr_clean_role(mixed $v): string
{
    if (!is_string($v) || !in_array($v, APR_ROLES, true)) apr_fail('role_invalid');
    return $v;
}
