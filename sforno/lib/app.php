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
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/reader.php';

/**
 * $env: platform_user (ה-JSON מהשער או null), cookie, csrf, ua, content_type.
 * מחזיר את גוף התשובה (200). שגיאה — חריגה; התרגום לתשובה ב-sf_error_response.
 */
function sf_dispatch(string $method, string $action, array $get, array $in, array $env): array
{
    sf_actor(null, true);
    $spec = sf_action_spec($method, $action);
    $staff = $session = null;
    if ($spec['who'] !== 'public' && $spec['who'] !== 'reader') {
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

        'read-titles'          => sf_read_titles(),
        'read-edition'         => sf_read_edition($get),
        'read-catalog'         => sf_read_catalog(),
        'read-toc'             => sf_read_toc($get),
        'read-revision'        => sf_read_revision($get),
        'read-search'          => sf_read_search($get),
        'read-file'            => sf_send_file(sf_read_file_path($get)),
        'read-pages'           => sf_send_file(sf_read_pages_path($get)),

        'me'                   => ['staff' => sf_staff_public($staff, $me), 'csrf' => $session['csrf'], 'version' => sf_version()],
        'logout'               => sf_session_drop((string)($env['cookie'] ?? '')) ?? [],
        'staff-list'           => sf_staff_list($me),
        'staff-suspend'        => sf_staff_set_status($in['staff'] ?? null, 'suspended', $me),
        'staff-unsuspend'      => sf_staff_set_status($in['staff'] ?? null, 'active', $me),
        'devloop-client-error' => devloop_log_client($in),
        'content-titles'       => sf_content_titles(),
        'content-title'        => sf_title_view($get),
        'content-title-save'   => sf_title_save($in),
        'content-title-public' => sf_title_public($in),
        'content-edition-save' => sf_edition_save($in),
        'content-edition'      => sf_edition_view($get),
        'content-revision'     => sf_revision_view($get),
        'content-check'        => sf_content_check($in),
        'content-save'         => sf_content_save($in, $me),
        'content-publish'      => sf_content_publish($in, $me),
        'content-restore'      => sf_content_restore($in, $me),
        'content-discard'      => sf_content_discard($in, $me),
        'content-aliases'      => sf_aliases(),
        'content-alias-add'    => sf_alias_add($in, $me),
        'content-alias-remove' => sf_alias_remove($in),
        'content-file'         => sf_send_file(sf_content_file_path($get)),
        'import-list'          => sf_import_list($get),
        'import-status'        => sf_import_view($get),
        'import-upload'        => sf_import_upload($get, $env, $me),
        'import-run'           => sf_import_run($in),
        'import-retry'         => sf_import_retry($in),
        'import-pdf-text'      => sf_import_pdf_text($in),
        'import-map'           => sf_import_map($in),
        'import-apply'         => sf_import_apply($in, $me),
        'import-text-checked'  => sf_import_checked($in),
        'import-source'        => sf_send_file(sf_import_source_path($get)),
        'customs-list'         => sf_customs_list(),
        'custom-save'          => sf_custom_save($in),
        'siddur-items'         => sf_siddur_items($get),
        'siddur-item-save'     => sf_siddur_item_save($in),
        'siddur-item-move'     => sf_siddur_item_move($in),
        'siddur-item-available' => sf_siddur_item_available($in),
        'customs-checks'       => sf_customs_checks($get),
        'customs-check-approve' => sf_customs_check_approve($in, $me),
        'content-pages-set'    => sf_content_pages_set($in),
        'content-sample'       => sf_content_sample($me),

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
