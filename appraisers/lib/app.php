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
 * מסמכים (06):     case-docs {case} · file {file, inline?} · file-thumb {file} · docs-search {q, case?} · docs-pending
 *                  · file-upload (גוף גולמי; הפרמטרים בכתובת: case, requirement?, relation?, name, key, dup?, source_note?, doc_date?)
 *                  · file-assign · file-update · file-delete · file-review · requirement-status · requirement-order · requirement-split
 * בקשות (07):      case-requests {case} · requests-failed · request-preview {case, party, requirements[], perms{}, days, subject?, body?,
 *                  channel, contact_ok} או {request, channel, contact_ok, days?} · request-send {…אותו תוכן…, preview, op}
 *                  · request-resend {request, channel, contact_ok, days?, preview, op} · request-revoke {request} · office-portal-otp {on}
 * הפורטל (07):     portal-open · portal-otp-send · portal-otp-verify {code} · portal-respond {requirement, kind, text} · portal-file
 *                  {file, inline} · portal-client-error — כולן POST עם token בגוף; portal-upload — גוף גולמי, האסימון ב-X-Portal-Token
 * תזכורות (08):    reminders-stop {request} · message-check {message} · inbox · inbox-assign {item, case, requirement?} · inbox-dismiss
 *                  {item, reason} · office-channels · office-channel-save {connection?, provider, label, config{}} · office-channel-status
 *                  {connection, status} · office-channel-test {connection} · office-inbox-handler {user, on} · sys-channels · sys-channel-…
 *                  (אותם שדות) · sys-channel-grant {connection, all_offices} או {connection, office, on} · sys-inbox · sys-inbox-dismiss
 *                  {item, reason} · sys-inbox-route {item, office} · sys-inbox-reply {item}
 * נתונים (10):     case-facts {case} · fact {fact} · case-conflicts {case} · conflict {conflict} · file-facts {file} · fact-add {case,
 *                  attribute, value, unit?, area_kind?, estimated?, zero_ok?, source{}, note?, state?} · fact-correct {fact, rev, …}
 *                  · fact-state {fact, rev, do, reason? / note?} · fact-delete {fact, rev, reason} · conflict-resolve {conflict, rev,
 *                  purpose, fact, reasoning} · conflict-dismiss {conflict, rev, reasoning, fixes?[]} · sys-attributes · sys-attribute-save
 * webhook (08):    inbound-relay?c=<חיבור> — POST, גוף JSON חתום (X-Relay-Signature); ציבורי. הפורמט והסדר: lib/inbound.php
 * אישור חוזר:      פעולה רגישה (lib/confirm.php) נושאת confirm במצב מחוברת; במנותקת — confirm-request ואז
 *                  confirm-verify {code}. עוד למשתמש: logout (מנותקת) · password-set {password}. מנהל: invite-resend {invite}
 *
 * כל בקשה משנה: POST, גוף JSON, והכותרת X-CSRF עם האסימון מ-me. כל מזהה בבקשה הוא pid; מזהה המשתמש ומזהה המשרד
 * של הבקשה עצמה לא נלקחים מהדפדפן אף פעם. פעולת משרד יכולה לשאת office (pid) — הוא חייב להיות המשרד הפעיל.
 *
 * $env: platform_user (ה-JSON מהשער, או null), cookie (apr_session), csrf (X-CSRF), ua, cron_url, ip, sso_state (העוגייה apr_sso),
 *       content_type, origin, fetch_site (לבדיקת המקור של POST ציבורי), portal_token (X-Portal-Token), portal_cookie (apr_portal).
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
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/case_parts.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/requests.php';
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/inbound.php';
require_once __DIR__ . '/facts.php';

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
        // POST ציבורי (login, forgot, reset) — אין session ואין X-CSRF, ולכן: רק JSON (טופס חוצה-אתרים לא יכול לשלוח
        // אותו בלי preflight), ורק מהאפליקציה עצמה. בלי זה אתר זר מכניס קורבן לחשבון של התוקף.
        if ($rule === 'public' && !$read) {
            $site = (string)($env['fetch_site'] ?? '');
            $origin = (string)($env['origin'] ?? '');
            // portal-upload: הגוף הוא הקובץ עצמו, והאסימון בכותרת X-Portal-Token — כותרת שטופס חוצה-אתרים אינו יכול לשלוח
            if (($action !== 'portal-upload' && !str_starts_with(strtolower((string)($env['content_type'] ?? '')), 'application/json'))
                || ($site !== '' && !in_array($site, ['same-origin', 'none'], true))
                || ($origin !== '' && !hash_equals(apr_app_origin(), $origin))) apr_fail('csrf');
        }
        // הפורטל: מכאן לא נקרא שום דבר מ-platform_user, מהעוגייה apr_session או מ-X-CSRF — רק האסימון של הקישור
        $penv = array_intersect_key($env, array_flip(['ip', 'portal_token', 'portal_name', 'portal_cookie', 'upload', 'upload_reader', 'upload_error']));
        return match ($action) {
            // webhook נכנס: מהבקשה מגיעים רק ה-IP, החתימה, מזהה החיבור שבכתובת וקורא הגוף הגולמי — שום זהות ושום עוגייה
            'inbound-relay'       => apr_inbound_hook('relay', ['c' => $get['c'] ?? null], array_intersect_key($env, array_flip(['ip', 'signature', 'raw_reader']))),
            'portal-open'         => apr_portal_open($in, $penv),
            'portal-otp-send'     => apr_portal_otp_send($in, $penv),
            'portal-otp-verify'   => apr_portal_otp_verify($in, $penv),
            'portal-upload'       => apr_portal_upload($in, $penv),
            'portal-respond'      => apr_portal_respond($in, $penv),
            'portal-file'         => apr_portal_file($in, $penv),
            'portal-client-error' => apr_portal_client_error($in, $penv),
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

        'profile-lists'      => apr_profile_lists(),
        'profiles'           => apr_profiles_list($ctx, $p),
        'profile'            => apr_profile_view($ctx, $p),
        'profile-version'    => apr_profile_version($ctx, $p),
        'profile-versions'   => apr_profile_versions($ctx, $p),
        'profile-compare'    => apr_profile_compare($ctx, $p),
        'profile-create'     => apr_profile_create($ctx, $p),
        'profile-update'     => apr_profile_update($ctx, $p),
        'profile-draft-open' => apr_profile_draft_open($ctx, $p),
        'profile-draft-save' => apr_profile_draft_save($ctx, $p),
        'profile-draft-info' => apr_profile_draft_info($ctx, $p),
        'profile-draft-discard' => apr_profile_draft_discard($ctx, $p),
        'profile-check'      => apr_profile_check($ctx, $p),
        'profile-publish'    => apr_profile_publish($ctx, $p),
        'profile-archive'    => apr_profile_archive($ctx, $p),
        'profile-delete'     => apr_profile_delete($ctx, $p),
        'profile-duplicate'  => apr_profile_duplicate($ctx, $p),
        'profile-try'        => apr_profile_try_action($ctx, $p),
        'profile-shares'     => apr_profile_shares($ctx, $p),
        'profile-share-preview' => apr_profile_share_preview($ctx, $p),
        'profile-share'      => apr_profile_share($ctx, $p),
        'profile-share-revoke' => apr_profile_share_revoke($ctx, $p),
        'office-required-profile' => apr_office_required_profile($ctx, $p),

        'case-options' => apr_case_options($ctx),
        'case-create' => apr_case_create($ctx, $p),
        'case-update' => apr_case_update($ctx, $p),
        'case-plan' => apr_case_plan($ctx, $p),
        'case-start' => apr_case_start($ctx, $p),
        'case-status' => apr_case_status($ctx, $p),
        'case-delete' => apr_case_delete($ctx, $p),
        'case' => apr_case_view($ctx, $p),
        'cases' => apr_cases_list($ctx, $p),
        'case-activity' => apr_case_activity($ctx, $p),
        'case-member-add' => apr_case_member_add($ctx, $p),
        'case-member-update' => apr_case_member_update($ctx, $p),
        'case-member-remove' => apr_case_member_remove($ctx, $p),
        'case-profile-diff' => apr_case_profile_diff($ctx, $p),
        'case-profile-apply' => apr_case_profile_apply($ctx, $p),
        'case-identifier-add' => apr_case_identifier_add($ctx, $p),
        'case-identifier-update' => apr_case_identifier_update($ctx, $p),
        'case-identifier-remove' => apr_case_identifier_remove($ctx, $p),
        'case-identifiers-conflict' => apr_case_identifiers_conflict($ctx, $p),
        'contacts' => apr_contacts($ctx, $p),
        'contact-save' => apr_contact_save($ctx, $p),
        'contact-delete' => apr_contact_delete($ctx, $p),
        'case-party-add' => apr_case_party_add($ctx, $p),
        'case-party-update' => apr_case_party_update($ctx, $p),
        'case-party-remove' => apr_case_party_remove($ctx, $p),
        'task-create' => apr_task_create($ctx, $p),
        'task-update' => apr_task_update($ctx, $p),
        'my-tasks' => apr_my_tasks($ctx),
        'requirement-add' => apr_requirement_add($ctx, $p),
        'requirement-update' => apr_requirement_update($ctx, $p),
        'case-docs' => apr_case_docs($ctx, $p),
        'file' => apr_file_serve($ctx, $p),
        'file-thumb' => apr_file_thumb($ctx, $p),
        'docs-search' => apr_docs_search($ctx, $p),
        'docs-pending' => apr_docs_pending($ctx),
        'file-upload' => apr_file_upload($ctx, $p, $env),
        // נתונים וסתירות (10)
        'case-facts' => apr_case_facts($ctx, $p),
        'fact' => apr_fact_view($ctx, $p),
        'case-conflicts' => apr_case_conflicts($ctx, $p),
        'conflict' => apr_conflict_view($ctx, $p),
        'file-facts' => apr_file_facts($ctx, $p),
        'fact-add' => apr_fact_add($ctx, $p),
        'fact-correct' => apr_fact_correct($ctx, $p),
        'fact-state' => apr_fact_state($ctx, $p),
        'fact-delete' => apr_fact_delete($ctx, $p),
        'conflict-resolve' => apr_conflict_resolve($ctx, $p),
        'conflict-dismiss' => apr_conflict_dismiss($ctx, $p),
        'sys-attributes' => apr_sys_attributes(),
        'sys-attribute-save' => apr_sys_attribute_save($p),
        'file-assign' => apr_file_assign($ctx, $p),
        'file-update' => apr_file_update($ctx, $p),
        'file-delete' => apr_file_delete($ctx, $p),
        'file-review' => apr_file_review($ctx, $p),
        'requirement-status' => apr_requirement_status($ctx, $p),
        'requirement-order' => apr_requirement_order($ctx, $p),
        'requirement-split' => apr_requirement_split($ctx, $p),
        'case-requests' => apr_case_requests($ctx, $p),
        'requests-failed' => apr_requests_failed($ctx),
        'request-preview' => apr_request_preview($ctx, $p),
        'request-send' => apr_request_send($ctx, $p),
        'request-resend' => apr_request_resend($ctx, $p),
        'request-revoke' => apr_request_revoke($ctx, $p),
        'office-portal-otp' => apr_office_portal_otp($ctx, $p),
        'reminders-stop' => apr_reminders_stop($ctx, $p),
        'message-check' => apr_message_check($ctx, $p),
        'inbox' => apr_inbox($ctx),
        'inbox-assign' => apr_inbox_assign($ctx, $p),
        'inbox-dismiss' => apr_inbox_dismiss($ctx, $p),
        'office-channels' => apr_office_channels($ctx),
        'office-channel-save' => apr_channel_save(apr_office_id($ctx), (int)$user['id'], $p),
        'office-channel-status' => apr_channel_status(apr_office_id($ctx), $p),
        'office-channel-test' => apr_channel_test(apr_office_id($ctx), $p),
        'office-inbox-handler' => apr_office_inbox_handler($ctx, $p),
        'sys-channels' => apr_sys_channels(),
        'sys-channel-save' => apr_channel_save(null, (int)$user['id'], $p),
        'sys-channel-status' => apr_channel_status(null, $p),
        'sys-channel-test' => apr_channel_test(null, $p),
        'sys-channel-grant' => apr_sys_channel_grant($p),
        'sys-inbox' => apr_sys_inbox(),
        'sys-inbox-dismiss' => apr_sys_inbox_dismiss($ctx, $p),
        'sys-inbox-route' => apr_sys_inbox_route($p),
        'sys-inbox-reply' => apr_sys_inbox_reply($p),
        'office-cases' => apr_office_cases($ctx),
        'office-case-transfer' => apr_office_case_transfer($ctx, $p),

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
        'sys-doc-types'      => apr_doc_types(),
        'sys-doc-type-save'  => apr_doc_type_save($p),

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
        : ['ok' => false, 'error' => ['code' => $code, 'message' => $msg] + ($e instanceof AprPublishBlocked ? ['checks' => $e->checks] : []) + ($e instanceof AprCaseIncomplete ? ['missing' => $e->missing] : []) + ($e instanceof AprReviewed ? ['review' => $e->review] : []) + ($e instanceof AprFactChanged ? ['current' => $e->current] : []), 'req' => apr_req_id()]];
}
