<?php
/**
 * נקודת הכניסה לשרת. כל פעולה משנה — POST עם JSON, X-Kol ו-X-CSRF.
 *
 * ⚠️ כל פעולה ב-PUBLIC_ACTIONS חייבת להופיע גם ב-"public" של app.json — אחרת השער חוסם אותה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/members.php';
require_once __DIR__ . '/lib/import.php';
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/agent.php';
require_once __DIR__ . '/lib/library.php';
require_once __DIR__ . '/lib/dev.php';
require_once __DIR__ . '/lib/preview.php';
require_once __DIR__ . '/lib/migrate.php';
require_once __DIR__ . '/lib/build.php';

devlog_begin('api');        // מזהה בקשה, אזהרות PHP, ושורת הבקשה ביומן (lib/devlog.php)

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once __DIR__ . '/lib/csp.php';
csp_api_headers();          // C1: תשובת API לעולם אינה מסמך — default-src 'none'; frame-ancestors 'none'; sandbox

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** מי אני: המשתמש, התפקיד, האם מפתח, והגרסה. כניסה נרשמת ביומן הפעולות. */
function me_state(array $user): array
{
    if ($user['role']) audit_login($user);
    return ['user' => $user + ['developer' => is_developer($user)], 'csrf' => csrf_token($user['sub']), 'roles' => ROLES, 'version' => dev_app_version()];
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// embed_key — המפתח הציבורי לעורך התמלילים (שלב 4). אין בו סוד.
// media — קובץ בכתובת חתומה לזמן מוגבל (התצוגה המבודדת בסטודיו לא שולחת עוגייה). בלי חתימה — 403.
const PUBLIC_ACTIONS = ['embed_key', 'media'];
const POST_ACTIONS = ['member_add', 'member_set', 'member_remove', 'request_dismiss', 'lock', 'draft_save', 'render', 'revision_restore',
                      'upload_init', 'upload_chunk', 'upload_done', 'file_delete', 'disk_cap', 'editor_session', 'longtext_sync',
                      'publish', 'republish', 'version_send', 'key_rotate', 'key_activate', 'site_set', 'site_ping', 'import_run',
                      'ai_suggest', 'secret_set', 'ai_quota_set', 'word_save', 'word_delete', 'site_switch', 'topic_op', 'words_csv_import', 'audio_import', 'word_move',
                      'field_save', 'field_delete',
                      'code_create', 'code_save', 'code_reset', 'agent_token_create', 'agent_token_revoke',
                      'snapshot_create', 'snapshot_restore', 'trash_restore', 'trash_purge',
                      'pf_create', 'pf_write', 'pf_mkdir', 'pf_link', 'pf_rename', 'pf_delete', 'pf_restore', 'pf_purge', 'pf_limits_set', 'pf_migrate', 'pf_blank',
                      'pf_copy', 'pf_version_restore', 'pf_replace', 'pf_zip_import',
                      'rec_duration', 'rec_scan_start', 'rec_scan_step', 'rec_fetch_start', 'rec_fetch_step', 'rec_job_op', 'rec_settings_set',
                      'build_start', 'build_page', 'build_fail', 'build_finish', 'build_send', 'kd_update', 'notice_read'];

try {
    if (in_array($action, POST_ACTIONS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    // upload_chunk ו-dev-attach — גוף גולמי (חלק מקובץ / צילום), לא JSON
    $body = $method === 'POST' && !in_array($action, ['upload_chunk', 'pf_zip_import'], true) && !in_array($action, DEV_RAW_BODY, true) ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    if (!is_array($body)) $body = [];

    // devapi-… — Claude, באסימון (X-Dev-Token) ובלי משתמש מהשער
    $user = in_array($action, PUBLIC_ACTIONS, true) || in_array($action, DEV_TOKEN_ACTIONS, true) ? null : current_user();
    devlog_user($user);
    if ($user && $method === 'POST') check_csrf($user);
    if ($user && $user['role']) snapshot_daily();                 // נקודת שחזור יומית — בבקשה הראשונה של היום

    $result = dev_route($action, $method, $user, $body) ?? match ($action) {
        'embed_key' => wp()->publicKeys(),
        'csp-reports' => (require_developer($user) ? csp_reports($_GET) : []),     // C1: הפרות ה-CSP, מקובצות עם מונה
        'me' => $user
            ? me_state($user)
            : throw new ApiError('נדרשת כניסה דרך הכלים הדיגיטליים', 401),
        'members' => members_state(require_role($user, 'owner')),
        'member_add' => member_add(require_role($user, 'owner'), $body),
        'member_set' => member_set(require_role($user, 'owner'), $body),
        'member_remove' => member_remove(require_role($user, 'owner'), $body),
        'request_dismiss' => request_dismiss(require_role($user, 'owner'), $body),
        'draft' => draft_get(require_role($user, 'editor')),
        'draft_save' => draft_save(require_role($user, 'editor'), $body),
        'lock' => lock_op(require_role($user, 'editor'), $body),
        'render' => (require_role($user, 'editor') ? render_preview($body) : []),
        'revisions' => (require_role($user, 'editor') ? revisions_list() : []),
        'revision_restore' => revision_restore(require_role($user, 'editor'), $body),
        'files' => (require_role($user, 'editor') ? files_list() : []),
        'upload_init' => upload_init(require_role($user, 'editor'), $body),
        'upload_chunk' => upload_chunk(require_role($user, 'editor'), $_GET),
        'upload_done' => upload_done(require_role($user, 'editor'), $body),
        'file_delete' => file_delete(require_role($user, 'editor'), $body),
        'disk_cap' => disk_cap_set(require_role($user, 'owner'), $body),
        'file' => file_send(file_row((string)(require_role($user, 'editor') ? ($_GET['id'] ?? '') : '')), isset($_GET['download'])),
        'media' => file_send(media_check($_GET)),
        'editor_session' => editor_session(require_role($user, 'editor'), $body),
        'longtext_sync' => longtext_sync(require_role($user, 'editor'), $body),
        'publish_preview' => publish_preview(require_role($user, 'publisher')),
        'publish' => publish(require_role($user, 'publisher'), $body),
        'republish' => republish(require_role($user, 'publisher'), $body),
        'version_send' => version_send(require_role($user, 'publisher'), (int)($body['n'] ?? 0)),
        'versions' => (require_role($user, 'editor') ? versions_list($_GET) : []),
        'version_preview' => (require_role($user, 'editor') ? version_preview($_GET) : []),
        'publish_settings' => (require_role($user, 'owner') ? publish_settings() : []),
        'key_rotate' => key_rotate(require_role($user, 'owner')),
        'key_activate' => key_activate(require_role($user, 'owner')),
        'site_set' => site_set(require_role($user, 'owner'), $body),
        'site_ping' => site_ping(require_role($user, 'publisher')),
        'site_switch' => site_switch(require_role($user, 'publisher'), $body),
        'import_run' => import_run(require_role($user, 'owner'), $body),
        'words' => (require_role($user, 'editor') ? words_list() : []),
        'library' => (require_role($user, 'editor') ? library_list() : []),
        // הבאת ההקלטות מהאתר (A2, lib/recordings.php) — בעל האתר בלבד. המשך — לכל עורך (נמדד בהשמעה)
        'rec_duration' => rec_duration(require_role($user, 'editor'), $body),
        'rec_status' => rec_status(require_role($user, 'owner')),
        'rec_fetch_plan' => rec_fetch_plan(require_role($user, 'owner')),
        'rec_scan_start' => rec_scan_start(require_role($user, 'owner')),
        'rec_scan_step' => rec_scan_step(require_role($user, 'owner')),
        'rec_fetch_start' => rec_fetch_start(require_role($user, 'owner'), $body),
        'rec_fetch_step' => rec_fetch_step(require_role($user, 'owner')),
        'rec_job_op' => rec_job_op(require_role($user, 'owner'), $body),
        'rec_settings_set' => rec_settings_set(require_role($user, 'owner'), $body),
        'word_save' => word_save(require_role($user, 'editor'), $body),
        'word_delete' => word_delete(require_role($user, 'editor'), $body),
        'topic_op' => topic_op(require_role($user, 'editor'), $body),
        'words_csv' => (require_role($user, 'editor') ? words_csv() : []),
        'words_csv_import' => words_csv_import(require_role($user, 'editor'), $body),
        'word_move' => word_move(require_role($user, 'editor'), $body),          // N1: גרירה / "העבר ל…" בטבלה
        'fields' => fields_state(require_role($user, 'editor')),                // N2: "מבנה המילה" — קריאה לכולם, שינוי — מפרסם
        'field_save' => field_save(require_role($user, 'publisher'), $body),
        'field_delete' => field_delete(require_role($user, 'publisher'), $body),
        'audio_import' => audio_import(require_role($user, 'owner'), $body),
        'snapshots' => (require_role($user, 'editor') ? snapshots_list($_GET) : []),
        'snapshot_create' => snapshot_manual(require_role($user, 'editor'), $body),
        'snapshot_restore' => snapshot_restore(require_role($user, 'editor'), $body),
        'snapshot_preview' => (require_role($user, 'editor') ? snapshot_preview($_GET) : []),
        'trash' => (require_role($user, 'editor') ? trash_list() : []),
        'trash_restore' => trash_restore(require_role($user, 'editor'), $body),
        'trash_purge' => trash_purge(require_role($user, 'owner'), $body),
        'backup' => backup_download(require_role($user, 'owner')),
        'code' => (require_role($user, 'editor') ? code_state() : []),
        'code_create' => code_create(require_role($user, 'editor')),
        'code_save' => code_save(require_role($user, 'editor'), $body),
        'code_reset' => code_reset(require_role($user, 'editor')),
        'code_history' => (require_role($user, 'editor') ? code_history($_GET) : []),
        'code_history_get' => (require_role($user, 'editor') ? code_history_get($_GET) : []),
        'agent_tokens' => agent_tokens(require_role($user, 'editor')),
        'agent_token_create' => agent_token_create(require_role($user, 'editor'), $body),
        'agent_token_revoke' => agent_token_revoke(require_role($user, 'editor'), $body),
        // מאגר הפרויקט (F2a, lib/project.php). pf_raw — הבייטים עצמם, תמיד בארגז חול
        'pf_tree' => pf_tree(require_role($user, 'editor')),
        'pf_read' => (require_role($user, 'editor') ? pf_read($_GET) : []),
        'pf_versions' => (require_role($user, 'editor') ? pf_versions($_GET) : []),
        'pf_raw' => (require_role($user, 'editor') ? pf_raw($_GET) : []),
        'pf_create' => pf_create(require_role($user, 'editor'), $body),
        'pf_write' => pf_write(require_role($user, 'editor'), $body),
        'pf_mkdir' => pf_mkdir(require_role($user, 'editor'), $body),
        'pf_link' => pf_link(require_role($user, 'editor'), $body),
        'pf_rename' => pf_rename(require_role($user, 'editor'), $body),
        'pf_delete' => pf_delete(require_role($user, 'editor'), $body),
        'pf_trash' => (require_role($user, 'editor') ? pf_trash() : []),
        'pf_restore' => pf_restore(require_role($user, 'editor'), $body),
        'pf_purge' => pf_purge(require_role($user, 'owner'), $body),
        // F2b (lib/project_ops.php). pf_zip_import — גוף גולמי (ה-ZIP עצמו)
        'pf_copy' => pf_copy(require_role($user, 'editor'), $body),
        'pf_version' => (require_role($user, 'editor') ? pf_version($_GET) : []),
        'pf_version_restore' => pf_version_restore(require_role($user, 'editor'), $body),
        'pf_search' => (require_role($user, 'editor') ? pf_search($_GET) : []),
        'pf_replace' => pf_replace(require_role($user, 'editor'), $body),
        'pf_refs' => (require_role($user, 'editor') ? pf_refs($_GET) : []),
        'pf_zip' => pf_zip(require_role($user, 'editor')),
        'pf_zip_import' => pf_zip_import(require_role($user, 'editor'), $_GET, (string)file_get_contents('php://input')),
        // ההסבה (F3, lib/migrate.php) — בעלים בלבד
        'pf_migrate' => pf_migrate(require_role($user, 'owner'), $body),
        'pf_migrate_last' => (require_role($user, 'owner') ? pf_migrate_last() : []),
        'pf_blank' => pf_blank(require_role($user, 'owner')),
        // התצוגה החיה (F4, lib/preview.php): אסימון חתום וקצר-מועד לכתובת המבודדת — רק למי שמורשה לקרוא את הפרויקט
        'pv_token' => pv_token(require_role($user, 'editor')),
        // בנייה ופרסום של פרויקט הקבצים (M1, lib/build.php): בנייה — עורך ומעלה; שליחה — מפרסם, רק גרסה שבנה בעצמו
        'builds' => builds_list(require_role($user, 'editor')),
        'build_get' => build_get(require_role($user, 'editor'), $_GET),
        'build_file' => (require_role($user, 'editor') ? build_file($_GET) : []),
        'build_start' => build_start(require_role($user, 'editor'), $body),
        'build_page' => build_page(require_role($user, 'editor'), $body),
        'build_fail' => build_fail(require_role($user, 'editor'), $body),
        'build_finish' => build_finish(require_role($user, 'editor'), $body),
        'build_send' => build_send(require_role($user, 'publisher'), $body),
        'kd_seed' => (require_role($user, 'owner') ? build_kd_seed() : []),
        'kd_update' => build_kd_update(require_role($user, 'owner'), $body),
        'notices' => notices_list(require_role($user, 'owner')),
        'notice_read' => notice_read(require_role($user, 'owner'), $body),
        'pf_limits' => (require_developer($user) ? pf_limits() : []),
        'pf_limits_set' => pf_limits_set(require_developer($user), $body),
        'import_info' => (require_role($user, 'owner') ? ['imported' => json_decode((string)setting('imported_from', 'null'), true)] : []),
        'ai_status' => ai_status(require_role($user, 'editor')),
        'ai_suggest' => ai_suggest(require_role($user, 'editor'), $body),
        'ai_admin' => (require_role($user, 'owner') ? ai_admin() : []),
        'secret_set' => secret_set(require_role($user, 'owner'), $body),
        'ai_quota_set' => ai_quota_set(require_role($user, 'owner'), $body),
        'audit' => audit_list(require_role($user, 'owner'), $_GET),
        default => throw new ApiError('פעולה לא מוכרת', 404),
    };
    json_out(['ok' => true] + $result);
} catch (ApiError $e) {
    devlog_exception($e);
    $code = $e->getCode() ?: 400;
    // 5xx: המשתמש רואה את מזהה הבקשה — אותו מזהה ביומן ובכותרת X-Request-Id
    json_out(['ok' => false, 'error' => $e->getMessage() . ($code >= 500 ? ' · מזהה ' . devlog_req() : ''), 'req' => devlog_req()] + $e->extra, $code);
} catch (Throwable $e) {
    devlog_exception($e);
    error_log('kol-maroko [' . devlog_req() . ']: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'תקלה בשרת · מזהה ' . devlog_req(), 'req' => devlog_req()], 500);
}
