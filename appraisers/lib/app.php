<?php
/**
 * הנתב של ה-API. api.php הוא רק המעטפת (כותרות, קריאת הגוף, JSON) — כל ההיגיון כאן, כדי שהבדיקות יריצו אותו בלי שרת.
 *
 * GET  me                          המשתמש, אסימון ה-CSRF, המשרדים שלי והמשרד הפעיל
 * POST office-switch {office_id}   מעבר למשרד אחר שלי
 * GET  members                     חברי המשרד שלי
 * POST member-invite {email, name, role} | member-update {user_id, role?, status?} | member-remove {user_id}   מנהל משרד
 * GET  office-audit                יומן הפעולות של המשרד שלי — מנהל משרד
 *
 * מנהל הפלטפורמה (בלי גישה לתוכן של משרד):
 * GET  offices                     כל המשרדים — שם, מצב, מספר חברים
 * POST office-create {name, admin_email, admin_name?} | office-update {id, name?, status?}
 *
 * המפתח:
 * GET  dev-health                  בדיקות תקינות
 * GET  dev-audit                   יומן הפעולות של כל האפליקציה
 * dev-… / devapi-… / devloop-client-error   DevLoop — אותם שמות פעולה כמו בכל אפליקציה (.claude/skills/devloop)
 *
 * כל בקשה משנה: POST, גוף JSON, והכותרת X-CSRF עם האסימון מ-me. הזהות — רק מהשער (lib/auth.php); מזהה המשתמש
 * ומזהה המשרד לא נלקחים מהדפדפן אף פעם.
 *
 * $env: platform_user (ה-JSON מהשער, או null), cookie (apr_session), csrf (הכותרת X-CSRF).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/offices.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/devtasks.php';

/** פעולות של Claude — בלי עוגייה ובלי שער (ב-"public" של app.json), באסימון X-Dev-Token של המפתח. */
const APR_TOKEN_ACTIONS = ['devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'];

/** ההקשר של הבקשה: המשתמש, ה-session והמשרד. זורק 401 בלי זהות, 403 לחשבון מושעה. */
function apr_ctx(array $env): array
{
    $cookie = (string)($env['cookie'] ?? '');
    $id = apr_identity($env['platform_user'] ?? null);
    if (!$id) {
        if ($cookie !== '') {
            apr_session_drop($cookie);
            $GLOBALS['apr_cookie_out'] = ['clear' => true];
        }
        throw new AprError('נדרשת כניסה דרך הכלים הדיגיטליים', 401);
    }
    $user = apr_user_for($id);
    if ($user['status'] !== 'active') throw new AprError('החשבון מושעה — פנו למנהל', 403);
    $s = apr_session_for($user, $cookie);
    if ($s['new_token'] !== null) $GLOBALS['apr_cookie_out'] = ['token' => $s['new_token']];
    $offices = apr_memberships((int)$user['id']);
    return ['user' => $user, 'session' => $s['token_hash'], 'csrf' => $s['csrf'], 'offices' => $offices,
        'office' => apr_active_membership($offices, $s['office_id'])];
}

function apr_me(array $ctx): array
{
    $u = $ctx['user'];
    $office = fn(array $m) => ['id' => (int)$m['office_id'], 'name' => $m['name'], 'role' => $m['role']];
    return [
        'user' => ['id' => (int)$u['id'], 'name' => $u['display_name'], 'email' => $u['email'],
            'is_developer' => (bool)$u['is_developer'], 'is_platform_manager' => (bool)$u['is_platform_manager']],
        'csrf' => $ctx['csrf'],
        'offices' => array_map($office, $ctx['offices']),
        'office' => $ctx['office'] ? $office($ctx['office']) : null,
        'roles' => APR_ROLES,
        'version' => apr_app_version(),
    ];
}

function apr_dispatch(string $method, string $action, array $get, array $in, array $env): mixed
{
    $route = "$method $action";

    // Claude, באסימון — לפני כל דבר שנוגע בזהות
    if (in_array($action, APR_TOKEN_ACTIONS, true)) {
        return match ($route) {
            'GET devapi-feed'     => devloop_api_feed(devloop_api_auth(), $get),
            'POST devapi-comment' => devloop_api_comment(devloop_api_auth(), $in),
            'GET devapi-file'     => (devloop_api_auth() and devloop_file_out((int)($get['id'] ?? 0))),
            'POST devapi-attach'  => devloop_attach('claude', $get + ['_auth' => devloop_api_auth()]),
            default               => throw new AprError('פעולה לא מוכרת', 404),
        };
    }

    $ctx = apr_ctx($env);
    $user = $ctx['user'];
    devloop_log_user(['id' => (int)$user['id'], 'username' => $user['display_name']]);

    // שגיאת JavaScript מהדפדפן של כל משתמש מחובר — ליומן. בלי CSRF: היא רק נרשמת, והיא עלולה לקרות לפני ש-me נטען
    if ($route === 'POST devloop-client-error') return devloop_log_client($in);

    if ($method === 'POST') {
        if (!hash_equals($ctx['csrf'], (string)($env['csrf'] ?? ''))) throw new AprError('הדף לא מעודכן — רעננו ונסו שוב', 403);
    } elseif ($method !== 'GET') {
        throw new AprError('שיטה לא נתמכת', 405);
    }
    $dev = fn() => apr_require_dev($ctx);

    return match ($route) {
        'GET me'               => apr_me($ctx),
        'POST office-switch'   => apr_office_switch($ctx, $in['office_id'] ?? 0),
        'GET members'          => apr_members($ctx),
        'POST member-invite'   => apr_member_invite($ctx, $in),
        'POST member-update'   => apr_member_update($ctx, $in),
        'POST member-remove'   => apr_member_remove($ctx, $in),
        'GET office-audit'     => ['rows' => apr_audit_rows(apr_require_role($ctx, 'office_admin'), $get)],

        'GET offices'          => apr_offices_all($ctx),
        'POST office-create'   => apr_office_create($ctx, $in),
        'POST office-update'   => apr_office_update($ctx, $in),

        'GET dev-health'       => ($dev() ? apr_health() : null),
        'GET dev-audit'        => ($dev() ? ['rows' => apr_audit_rows(null, $get)] : null),

        // DevLoop — המפתח
        'GET dev-items'           => ($dev() ? devloop_task_list() : null),
        'POST dev-task-create'    => devloop_task_create($dev(), $in),
        'POST dev-task-set'       => devloop_task_set($dev(), $in),
        'POST dev-comment'        => devloop_comment_add((string)($in['code'] ?? ''), 'user', (int)$dev()['id'], (string)($in['body'] ?? '')),
        'POST dev-comment-delete' => devloop_comment_delete($dev(), $in),
        'POST dev-verify'         => devloop_verify($dev(), $in),
        'POST dev-report'         => devloop_report($dev(), $in),
        'POST dev-attach'         => devloop_attach('user:' . $dev()['id'], $get),
        'GET dev-file'            => ($dev() ? devloop_file_out((int)($get['id'] ?? 0)) : null),
        'GET dev-tokens'          => ($dev() ? devloop_token_list() : null),
        'POST dev-token-create'   => devloop_token_create($dev(), $in),
        'POST dev-token-revoke'   => devloop_token_revoke($dev(), $in),
        'GET dev-item-log'        => ($dev() ? devloop_item_log((string)($get['code'] ?? '')) : null),
        'POST dev-log-links'      => ($dev() ? devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])) : null),
        'POST dev-task-from-log'  => devloop_task_from_log($dev(), $in),
        'GET dev-log'             => ($dev() ? devloop_log_view($get) : null),

        default                => throw new AprError('פעולה לא מוכרת', 404),
    };
}
