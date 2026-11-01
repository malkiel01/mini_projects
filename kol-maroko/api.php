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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// embed_key — המפתח הציבורי לעורך התמלילים (שלב 4). אין בו סוד.
// media — קובץ בכתובת חתומה לזמן מוגבל (התצוגה המבודדת בסטודיו לא שולחת עוגייה). בלי חתימה — 403.
const PUBLIC_ACTIONS = ['embed_key', 'media'];
const POST_ACTIONS = ['member_add', 'member_set', 'member_remove', 'request_dismiss', 'lock', 'draft_save', 'render', 'revision_restore',
                      'upload_init', 'upload_chunk', 'upload_done', 'file_delete', 'disk_cap', 'editor_session', 'longtext_sync',
                      'publish', 'republish', 'version_send', 'key_rotate', 'key_activate', 'site_set', 'site_ping', 'import_run',
                      'ai_suggest', 'secret_set', 'ai_quota_set', 'word_save', 'word_delete', 'site_switch', 'topic_op', 'words_csv_import', 'audio_import',
                      'code_create', 'code_save', 'code_reset', 'agent_token_create', 'agent_token_revoke',
                      'snapshot_create', 'snapshot_restore', 'trash_restore', 'trash_purge'];

try {
    if (in_array($action, POST_ACTIONS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    // upload_chunk — גוף גולמי (חלק מקובץ), לא JSON
    $body = $method === 'POST' && $action !== 'upload_chunk' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    if (!is_array($body)) $body = [];

    $user = in_array($action, PUBLIC_ACTIONS, true) ? null : current_user();
    if ($user && $method === 'POST') check_csrf($user);
    if ($user && $user['role']) snapshot_daily();                 // נקודת שחזור יומית — בבקשה הראשונה של היום

    $result = match ($action) {
        'embed_key' => wp()->publicKeys(),
        'me' => $user
            ? ['user' => $user, 'csrf' => csrf_token($user['sub']), 'roles' => ROLES]
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
        'word_save' => word_save(require_role($user, 'editor'), $body),
        'word_delete' => word_delete(require_role($user, 'editor'), $body),
        'topic_op' => topic_op(require_role($user, 'editor'), $body),
        'words_csv' => (require_role($user, 'editor') ? words_csv() : []),
        'words_csv_import' => words_csv_import(require_role($user, 'editor'), $body),
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
    json_out(['ok' => false, 'error' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('kol-maroko: ' . $e);
    json_out(['ok' => false, 'error' => 'תקלה בשרת'], 500);
}
