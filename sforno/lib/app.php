<?php
/**
 * הנתב של ספורנו — כל הפעולות, בלי HTTP (api.php הוא המעטפת הדקה; הבדיקות קוראות לכאן ישירות).
 *
 * סדר כל בקשה: שם ושיטה (404 / 405) → זיהוי המנהל (§5.2: 401 / 403 / 503) → CSRF וגוף JSON בכל POST (403 / 415)
 * → ההרשאה (lib/access.php) → הפעולה. כל קובץ שנטען כאן נמצא בתוך lib/ — **אין קריאה לקובץ מחוץ לתיקייה.**
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/devtasks.php';

/**
 * $env: platform_user (ה-JSON מהשער או null), cookie, csrf, ua, content_type.
 * מחזיר את גוף התשובה (200). שגיאה — חריגה; התרגום לתשובה ב-sf_error_response.
 */
function sf_dispatch(string $method, string $action, array $get, array $in, array $env): array
{
    sf_actor(null, true);
    $spec = sf_action_spec($method, $action);
    $staff = $session = null;
    if ($spec['who'] !== 'public') {
        ['staff' => $staff, 'session' => $session] = sf_authenticate($env);
        devloop_log_user(['id' => (int)$staff['id'], 'name' => $staff['name']]);
        if ($method === 'POST') {
            if (!hash_equals((string)$session['csrf'], (string)($env['csrf'] ?? ''))) sf_fail('csrf');
            if (empty($spec['body']) && !str_contains(strtolower((string)($env['content_type'] ?? '')), 'application/json')) sf_fail('content_type');
        }
    }
    sf_access($spec, $staff);
    $out = sf_run($action, $get, $in, $staff, $session, $env);
    return !empty($spec['raw']) ? (is_array($out) ? $out : ['ok' => true]) : ['ok' => true] + (array)$out;
}

function sf_run(string $action, array $get, array $in, ?array $staff, ?array $session, array $env): mixed
{
    $me = $staff ? (int)$staff['id'] : 0;
    $dev = $staff ? ['id' => $me, 'name' => $staff['name'], 'username' => $staff['name']] : null;
    $str = fn(array $a, string $k) => is_string($a[$k] ?? null) ? $a[$k] : '';
    return match ($action) {
        'health'               => sf_health_public(),
        'devapi-feed'          => devloop_api_feed(devloop_api_auth(), $get),
        'devapi-comment'       => devloop_api_comment(devloop_api_auth(), $in),
        'devapi-file'          => devloop_api_auth() && devloop_file_out((int)($get['id'] ?? 0)),
        'devapi-attach'        => devloop_attach('claude', $get + ['_auth' => devloop_api_auth()]),

        'me'                   => ['staff' => sf_staff_public($staff, $me), 'csrf' => $session['csrf'], 'version' => sf_version()],
        'logout'               => sf_session_drop((string)($env['cookie'] ?? '')) ?? [],
        'staff-list'           => sf_staff_list($me),
        'staff-suspend'        => sf_staff_set_status($in['staff'] ?? null, 'suspended', $me),
        'staff-unsuspend'      => sf_staff_set_status($in['staff'] ?? null, 'active', $me),
        'devloop-client-error' => devloop_log_client($in),

        'dev-health'           => sf_health(),
        'dev-mail-test'        => sf_mail_test($staff),
        'dev-audit'            => sf_audit_view($get),
        'dev-jobs'             => sf_jobs_view(),
        'dev-job-retry'        => sf_job_retry($in['job'] ?? null),
        'dev-job-cancel'       => sf_job_cancel($in['job'] ?? null),
        'dev-cron-rotate'      => sf_cron_rotate(),

        'dev-items'            => devloop_task_list(),
        'dev-task-create'      => devloop_task_create($dev, $in),
        'dev-task-set'         => devloop_task_set($dev, $in),
        'dev-comment'          => devloop_comment_add($str($in, 'code'), 'user', $me, $str($in, 'body')),
        'dev-comment-delete'   => devloop_comment_delete($dev, $in),
        'dev-verify'           => devloop_verify($dev, $in),
        'dev-report'           => devloop_report($dev, $in),
        'dev-attach'           => devloop_attach('user:' . $me, $get),
        'dev-file'             => devloop_file_out((int)($get['id'] ?? 0)),
        'dev-tokens'           => devloop_token_list(),
        'dev-token-create'     => devloop_token_create($dev, $in),
        'dev-token-revoke'     => devloop_token_revoke($dev, $in),
        'dev-item-log'         => devloop_item_log($str($get, 'code')),
        'dev-log-links'        => devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])),
        'dev-task-from-log'    => devloop_task_from_log($dev, $in),
        'dev-log'              => devloop_log_view($get),
    };
}

/**
 * חריגה ליומן הטכני, עם מזהה הבקשה. DevLoop רושם לבד רק חריגה שאינה RuntimeException (או 5xx); תקלת מסד
 * (PDOException) היא RuntimeException — נרשמת כאן. גוף הבקשה לא נרשם.
 */
function sf_log_exception(Throwable $e): void
{
    devloop_log_error($e);
    $logged = !($e instanceof RuntimeException) || $e->getCode() >= 500;
    if (!$logged && !($e instanceof SfError)) {
        devloop_log_write('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(), 'data' => ['file' => basename($e->getFile()),
            'line' => $e->getLine(), 'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 8)]]);
    }
}
