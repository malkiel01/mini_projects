<?php
/**
 * הנתב של ספורנו — כל הפעולות, בלי HTTP (api.php הוא המעטפת הדקה; הבדיקות קוראות לכאן ישירות).
 *
 * סדר כל בקשה: שם ושיטה (404 / 405) → זיהוי המנהל (§5.2: 401 / 403 / 503) → CSRF וגוף JSON בכל POST (403 / 415)
 * → ההרשאה (lib/access.php) → הפעולה. פעולות הקוראים (guest / account, 05) — זיהוי מעוגיית sf_reader בלבד, CSRF מהכותרת
 * X-Reader-CSRF; הן לא קוראות את זהות המנהל, ופעולות המנהל לא קוראות את עוגיית הקורא. כל קובץ שנטען כאן נמצא בתוך lib/ — **אין קריאה לקובץ מחוץ לתיקייה.**
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sso.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/devtasks.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/reader.php';
require_once __DIR__ . '/readers.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/daily.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/backup.php';

/**
 * $env: platform_user (ה-JSON מהשער או null), cookie, csrf, ua, content_type; לקוראים — reader_cookie, reader_csrf, ip;
 * לתזכורות של אורח (08) — push_cookie (sf_push).
 * מחזיר את גוף התשובה (200). שגיאה — חריגה; התרגום לתשובה ב-sf_error_response.
 */
function sf_dispatch(string $method, string $action, array $get, array $in, array $env): array
{
    sf_actor(null, true);
    $spec = sf_action_spec($method, $action);
    if (function_exists('sf_in_maintenance') && sf_in_maintenance()) sf_fail('upgrading');   // 10: שחזור רץ — אף בקשה לא כותבת למסד
    $staff = $session = null;
    if ($spec['who'] === 'guest' || $spec['who'] === 'account') {
        $ra = sf_reader_auth($env);
        if ($spec['who'] === 'account' && $ra === null) sf_fail('reader_login');
        if ($method === 'POST') {
            // CSRF בלי אסימון (כניסה, הרשמה): סוג מדיה מדויק — בקשה "פשוטה" מאתר אחר לא יכולה לשלוח application/json —
            // ובנוסף הדפדפן מצהיר מאיפה הבקשה (Sec-Fetch-Site), ובלעדיו — Origin
            if (empty($spec['body']) && sf_media_type($env['content_type'] ?? '') !== 'application/json') sf_fail('content_type');
            if (!sf_same_site($env)) sf_fail('csrf');
            if (($spec['who'] === 'account' || ($ra && !empty($spec['rcsrf']))) && !hash_equals((string)$ra['session']['csrf'], (string)($env['reader_csrf'] ?? ''))) sf_fail('csrf');
        }
        $env['_reader'] = $ra;
    } elseif (!in_array($spec['who'], SF_NOT_STAFF, true)) {
        ['staff' => $staff, 'session' => $session] = sf_authenticate($env);
        sf_note_origin();   // 10: הכתובת הציבורית — לקישורים במיילים שנשלחים מ-cron / CLI
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

/** סוג המדיה בלי פרמטרים, באותיות קטנות ("Application/JSON; charset=utf-8" → "application/json"). */
function sf_media_type(mixed $ct): string
{
    return strtolower(trim(explode(';', (string)$ct, 2)[0]));
}

/** הבקשה מהאתר שלנו? Sec-Fetch-Site (same-origin / none), ואם אין — Origin עם המארח שלנו. בלי שניהם (לא דפדפן) — כן. */
function sf_same_site(array $env): bool
{
    $site = strtolower(trim((string)($env['sec_fetch_site'] ?? '')));
    if ($site !== '') return in_array($site, ['same-origin', 'none'], true);
    $origin = trim((string)($env['origin'] ?? ''));
    if ($origin === '') return true;
    return strtolower((string)parse_url($origin, PHP_URL_HOST)) === strtolower((string)parse_url(sf_origin(), PHP_URL_HOST));
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
        'store-webhook'        => sf_store_webhook($env),
        'sso-start'            => sf_sso_start($env),
        'sso-finish'           => sf_sso_finish($in, $env),
        'platform-key'         => sf_platform_key_out(),

        'read-titles'          => sf_read_titles(),
        'read-edition'         => sf_read_edition($get),
        'read-catalog'         => sf_read_catalog(),
        'read-toc'             => sf_read_toc($get),
        'read-revision'        => sf_read_revision($get),
        'read-search'          => sf_read_search($get),
        'read-file'            => sf_send_file(sf_read_file_path($get)),
        'read-pages'           => sf_send_file(sf_read_pages_path($get)),
        'read-package'         => sf_read_package($get),
        'read-zmanim-methods'  => sf_zm_methods_public(),
        'read-halacha'         => sf_read_halacha($get),
        'read-halacha-archive' => sf_read_halacha_archive($get),
        'read-store-catalog'   => sf_store_catalog($get),
        'read-store-product'   => sf_store_product($get),
        'read-support'         => sf_read_support(),

        'account-me'           => sf_account_me($env['_reader']),
        'account-register'     => sf_account_register($in, $env),
        'account-login'        => sf_account_login($in, $env),
        'account-google'       => sf_account_google($in, $env),
        'account-verify'       => sf_account_verify($in),
        'account-reset-request' => sf_account_reset_request($in, $env),
        'account-reset'        => sf_account_reset($in, $env),
        'account-verify-resend' => sf_account_verify_resend($env['_reader']),
        'account-logout'       => sf_account_logout($env['_reader']),
        'account-devices'      => sf_account_devices($env['_reader']),
        'account-device-revoke' => sf_account_device_revoke($env['_reader'], $in),
        'account-export'       => sf_account_export($env['_reader']),
        'account-delete'       => sf_account_delete($env['_reader'], $in),
        'sync-push'            => sf_sync_push($env['_reader'], $in),
        'sync-pull'            => sf_sync_pull($env['_reader'], $get),
        'push-key'             => sf_push_key($env),
        'push-subscribe'       => sf_push_subscribe($in, $env),
        'push-unsubscribe'     => sf_push_unsubscribe($in, $env),
        'push-test'            => sf_push_test($in, $env),
        'reminders-guest-sync' => sf_rem_guest_sync($in, $env),
        'reminders-status'     => sf_rem_status($env),
        'store-out'            => sf_store_out($in, $env),
        'report-create'        => sf_report_create($in, $env),
        'metrics-open'         => sf_metrics_open($in, $env),

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
        'zmanim-method-list'   => sf_zm_methods_admin(),
        'zmanim-method-save'   => sf_zm_method_save($in, $me),
        'zmanim-method-status' => sf_zm_method_status($in),
        'zmanim-method-default' => sf_zm_method_default($in),
        'halacha-list'         => sf_halacha_admin_list(),
        'halacha-get'          => sf_halacha_get($get),
        'halacha-save'         => sf_halacha_save($in, $me),
        'halacha-publish'      => sf_halacha_publish($in, $me),
        'halacha-unpublish'    => sf_halacha_unpublish($in),
        'store-admin'          => sf_store_admin(),
        'store-map-save'       => sf_store_map_save($in),
        'store-sync-now'       => sf_store_sync_now($in),
        'store-sample'         => sf_store_sample($in),
        'store-link-set'       => sf_store_link_set($in),
        'ops-dashboard'        => sf_ops_dashboard($get, $staff),
        'ops-metrics'          => sf_ops_metrics($get),
        'ops-reader'           => sf_ops_reader($get),
        'reports-list'         => sf_reports_list($get),
        'report-get'           => sf_report_get($get),
        'report-set'           => sf_report_set($in),

        'dev-health'           => sf_health(),
        'dev-mail-test'        => sf_mail_test($staff),
        'dev-audit'            => sf_audit_view($get),
        'dev-jobs'             => sf_jobs_view(),
        'dev-job-retry'        => sf_job_retry($in['job'] ?? null),
        'dev-job-cancel'       => sf_job_cancel($in['job'] ?? null),
        'dev-cron-rotate'      => sf_cron_rotate(),
        'dev-google-set'       => sf_dev_google_set($in),
        'dev-reminders'        => sf_rem_dev(),
        'dev-store-set'        => sf_dev_store_set($in),
        'dev-store-test'       => sf_dev_store_test(),
        'dev-backups'          => sf_dev_backups(),
        'dev-backup-now'       => sf_dev_backup_now(),
        'dev-backup-verify'    => sf_dev_backup_verify($in),
        'dev-backup-download'  => sf_dev_backup_download($in),
        'dev-ops-set'          => sf_dev_ops_set($in),

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
