<?php
/**
 * נקודת הכניסה לשרת. כל פעולה משנה — POST עם JSON, X-Kol ו-X-CSRF.
 *
 * ⚠️ כל פעולה ב-PUBLIC_ACTIONS חייבת להופיע גם ב-"public" של app.json — אחרת השער חוסם אותה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/members.php';
require_once __DIR__ . '/lib/draft.php';

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
const PUBLIC_ACTIONS = ['embed_key'];
const POST_ACTIONS = ['member_add', 'member_set', 'member_remove', 'request_dismiss', 'lock', 'draft_save', 'render', 'revision_restore'];

try {
    if (in_array($action, POST_ACTIONS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $body = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    if (!is_array($body)) $body = [];

    $user = in_array($action, PUBLIC_ACTIONS, true) ? null : current_user();
    if ($user && $method === 'POST') check_csrf($user);

    $result = match ($action) {
        'embed_key' => ['client' => 'kol-maroko', 'keys' => []],
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
