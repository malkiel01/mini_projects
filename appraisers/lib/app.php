<?php
/**
 * הנתב של ה-API. api.php הוא רק המעטפת (כותרות, קריאת הגוף, JSON) — כל ההיגיון כאן, כדי שהבדיקות יריצו אותו בלי שרת.
 * מי רשאי לכל פעולה, ואיזו מהן קוראת בלבד (GET): lib/access.php — המקום היחיד שמכריע.
 *
 * כל משתמש:        me · office-switch {office} · office-create {name, role, op} · my-invites · invite-info {token|invite}
 *                  · invite-accept {token|invite}
 * חבר משרד:        office · office-leave
 * מנהל משרד:       office-rename {name} · office-audit · invite-create {email, role, is_admin} · invite-link {invite}
 *                  · invite-revoke {invite} · member-update {user, role?, is_admin?, status?, title?, license_no?}
 *                  · member-remove {user}
 * מנהל המערכת:     sys-offices · sys-office {office} · sys-office-create {name, admin_email, role?} · sys-office-status
 *                  {office, status, reason} · sys-users · sys-user-status {user, status, reason} · sys-user-flags
 *                  {user, is_sysadmin} · sys-settings · sys-setting-set {name, value}
 * המפתח:           dev-health · dev-audit · dev-jobs · dev-job-retry {job} · dev-job-cancel {job} · dev-cron-run
 *                  · dev-… של DevLoop (אותם שמות כמו בכל אפליקציה)
 * בלי משתמש:       health · devapi-… (באסימון X-Dev-Token) · platform_key · login-config · sso-start · sso-callback
 *                  · login {email, password} · forgot {email} · reset {token, password}
 * אישור חוזר:      פעולה רגישה (lib/confirm.php) נושאת confirm במצב מחוברת; במנותקת — confirm-request ואז
 *                  confirm-verify {code}. עוד למשתמש: logout (מנותקת) · password-set {password}. מנהל: invite-resend {invite}
 *
 * כל בקשה משנה: POST, גוף JSON, והכותרת X-CSRF עם האסימון מ-me. כל מזהה בבקשה הוא pid; מזהה המשתמש ומזהה המשרד
 * של הבקשה עצמה לא נלקחים מהדפדפן אף פעם. פעולת משרד יכולה לשאת office (pid) — הוא חייב להיות המשרד הפעיל.
 *
 * $env: platform_user (ה-JSON מהשער, או null), cookie (apr_session), csrf (X-CSRF), ua, cron_url, ip, sso_state (העוגייה apr_sso).
 * פעולה שמחזירה ['_redirect' => כתובת] — api.php מפנה אליה במקום לענות ב-JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/offices.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/devtasks.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/login.php';
require_once __DIR__ . '/confirm.php';

/** פעולות של DevLoop עונות בחוזה של המודול ({error: "…"}), לא בחוזה של האפליקציה. */
function apr_is_devloop(string $action): bool
{
    return str_starts_with($action, 'devapi-') || str_starts_with($action, 'devloop-')
        || (str_starts_with($action, 'dev-') && !in_array($action, ['dev-health', 'dev-audit', 'dev-jobs', 'dev-job-retry', 'dev-job-cancel', 'dev-cron-run', 'dev-mail'], true));
}

function apr_me(array $ctx): array
{
    $u = $ctx['user'];
    return [
        'user' => ['pid' => $u['pid'], 'name' => $u['name'], 'email' => $u['email'], 'email_verified' => (bool)$u['email_verified'],
            'is_sysadmin' => (bool)$u['is_sysadmin'], 'is_developer' => (bool)$u['is_developer']],
        'csrf' => $ctx['csrf'],
        'offices' => array_map(fn($o) => ['pid' => $o['pid'], 'name' => $o['name'], 'role' => $o['role'], 'is_admin' => (bool)$o['is_admin'],
            'status' => $o['office_status'] !== 'active' ? 'office_suspended' : $o['status']], $ctx['offices']),
        'office' => $ctx['office']['pid'] ?? null,
        'can_create_office' => (bool)$u['is_sysadmin'] || apr_setting('office_self_create'),
        'version' => apr_app_version(),
        'mode' => $ctx['mode'],                          // connected / detached — במנותקת יש "יציאה"
        'has_password' => $u['password_hash'] !== null,
        'confirm_word' => APR_CONFIRM_WORD,
    ];
}

function apr_dispatch(string $method, string $action, array $get, array $in, array $env): mixed
{
    $rule = APR_ACCESS[$action] ?? null;
    $read = in_array($action, APR_READS, true);

    // בלי משתמש: בדיקת החיים, ו-Claude באסימון — לפני כל דבר שנוגע בזהות. לא נוגעים ב-session ולא יוצרים חדש.
    if ($rule === 'public' || $rule === 'token') {
        if ($method !== ($read ? 'GET' : 'POST')) apr_fail('method');
        return match ($action) {
            'health'         => apr_health_public(),
            'platform_key'   => apr_pkey_public(),
            'login-config'   => apr_login_config($env),
            'sso-start'      => apr_sso_start($env),
            'sso-callback'   => apr_sso_callback($get, $env),
            'login'          => apr_login($in, $env),
            'forgot'         => apr_forgot($in, $env),
            'reset'          => apr_reset($in, $env),
            'devapi-feed'    => devloop_api_feed(devloop_api_auth(), $get),
            'devapi-comment' => devloop_api_comment(devloop_api_auth(), $in),
            'devapi-file'    => (devloop_api_auth() and devloop_file_out((int)($get['id'] ?? 0))),
            'devapi-attach'  => devloop_attach('claude', $get + ['_auth' => devloop_api_auth()]),
        };
    }

    $ctx = apr_ctx($env);
    $user = $ctx['user'];
    devloop_log_user(['id' => (int)$user['id'], 'username' => $user['name']]);

    $p = $read ? $get : $in;
    apr_authorize($ctx, $action, $p);
    if ($method !== ($read ? 'GET' : 'POST')) apr_fail('method');
    // שגיאת JavaScript מהדפדפן רק נרשמת, ועלולה לקרות לפני ש-me נטען — בלי CSRF
    if (!$read && $action !== 'devloop-client-error' && !hash_equals($ctx['csrf'], (string)($env['csrf'] ?? ''))) apr_fail('csrf');

    apr_confirm_check($ctx, $action, $p);   // פעולה רגישה בלי אישור חוזר — 403 confirm_required

    return match ($action) {
        'me'                 => apr_me($ctx),
        'logout'             => apr_logout($ctx),
        'password-set'       => apr_password_set($ctx, $p),
        'confirm-request'    => apr_confirm_request($ctx),
        'confirm-verify'     => apr_confirm_verify($ctx, $p),
        'office-switch'      => apr_office_switch($ctx, $p),
        'office-create'      => apr_office_create($ctx, $p),
        'my-invites'         => apr_my_invites($ctx),
        'invite-info'        => apr_invite_info($ctx, $p),
        'invite-accept'      => apr_invite_accept($ctx, $p),

        'office'             => apr_office_view($ctx),
        'office-leave'       => apr_office_leave($ctx),
        'office-rename'      => apr_office_rename($ctx, $p),
        'office-audit'       => ['rows' => apr_audit_rows(apr_office_id($ctx), $p)],
        'invite-create'      => apr_invite_create($ctx, $p),
        'invite-link'        => apr_invite_link($ctx, $p),
        'invite-revoke'      => apr_invite_revoke($ctx, $p),
        'invite-resend'      => apr_invite_resend($ctx, $p),
        'member-update'      => apr_member_update($ctx, $p),
        'member-remove'      => apr_member_remove($ctx, $p),

        'sys-offices'        => apr_sys_offices(),
        'sys-office-create'  => apr_sys_office_create($ctx, $p),
        'sys-settings'       => apr_sys_settings(),
        'sys-setting-set'    => apr_sys_setting_set($p),
        'sys-office'         => apr_sys_office($p),
        'sys-office-status'  => apr_sys_office_status($p),
        'sys-users'          => apr_sys_users(),
        'sys-user-status'    => apr_sys_user_status($p),
        'sys-user-flags'     => apr_sys_user_flags($p),

        'dev-health'         => apr_health(),
        'dev-audit'          => ['rows' => apr_audit_rows(null, $p)],
        'dev-jobs'           => apr_jobs_view(($env['cron_url'] ?? '') !== '' ? $env['cron_url'] . '?token=' . apr_cron_token() : ''),
        'dev-job-retry'      => apr_job_retry($p),
        'dev-job-cancel'     => apr_job_cancel($p),
        'dev-cron-run'       => apr_jobs_run(10.0, 'manual'),
        'dev-mail'           => apr_mail_rows($p),

        // DevLoop
        'devloop-client-error' => devloop_log_client(array_intersect_key($in, array_flip(['kind', 'message', 'source', 'line', 'col', 'stack', 'page']))),
        'dev-items'          => devloop_task_list(),
        'dev-task-create'    => devloop_task_create($user, $in),
        'dev-task-set'       => devloop_task_set($user, $in),
        'dev-comment'        => devloop_comment_add((string)($in['code'] ?? ''), 'user', (int)$user['id'], (string)($in['body'] ?? '')),
        'dev-comment-delete' => devloop_comment_delete($user, $in),
        'dev-verify'         => devloop_verify($user, $in),
        'dev-report'         => devloop_report($user, $in),
        'dev-attach'         => devloop_attach('user:' . $user['id'], $get),
        'dev-file'           => devloop_file_out((int)($get['id'] ?? 0)),
        'dev-tokens'         => devloop_token_list(),
        'dev-token-create'   => devloop_token_create($user, $in),
        'dev-token-revoke'   => devloop_token_revoke($user, $in),
        'dev-item-log'       => devloop_item_log((string)($get['code'] ?? '')),
        'dev-log-links'      => devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])),
        'dev-task-from-log'  => devloop_task_from_log($user, $in),
        'dev-log'            => devloop_log_view($get),
    };
}

/** חריגה → [status, גוף התשובה]. חריגה לא צפויה לא חושפת כלום: רק "תקלה בשרת" ומזהה הבקשה. */
function apr_error_response(Throwable $e, string $action): array
{
    if ($e instanceof AprError) {
        [$status, $code, $msg] = [$e->status, $e->errorCode, $e->getMessage()];
    } elseif (apr_is_busy($e)) {
        [$status, $code, $msg] = [503, 'busy', APR_ERRORS['busy'][1]];
    } else {
        [$status, $code, $msg] = [500, 'server', APR_ERRORS['server'][1]];
    }
    $status = $status >= 400 && $status < 600 ? $status : 500;
    return [$status, apr_is_devloop($action) ? ['error' => $msg, 'code' => $code, 'req' => apr_req_id()]
        : ['ok' => false, 'error' => ['code' => $code, 'message' => $msg], 'req' => apr_req_id()]];
}
