<?php
/**
 * DevLoop — דוגמה מינימלית לחיבור באפליקציה שאין בה כלום (PHP + SQLite), עם היומן המובנה.
 * מעתיקים לאפליקציה ומחליפים את שלושת הדברים המסומנים ⟵: המסד, מי המשתמש, ומי מפתח.
 * הנתב משתמש באותם שמות פעולות בכל אפליקציה (dev-…, devapi-…) — כך הסקיל ו-Claude עובדים אותו דבר בכל מקום.
 */

declare(strict_types=1);

require_once __DIR__ . '/devloop.php';

final class AppError extends RuntimeException {}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
$pdo = new PDO('sqlite:' . $dataDir . '/app.sqlite', null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); // ⟵ המסד של האפליקציה

// ⟵ המשתמש המחובר. בדוגמה — מפתח אחד קבוע; באפליקציה אמיתית: הסשן שלה
$user = ['id' => 1, 'username' => 'dev', 'name' => 'המפתח', 'developer' => true];

devloop_setup([
    'db'           => fn() => $pdo,
    'items_file'   => __DIR__ . '/dev/open-items.json',
    'data_dir'     => $dataDir . '/devloop',          // צילומים ויומן — לא לדרוס בפריסה
    'prefix'       => 'EX-',
    'token_prefix' => 'exd_',
    'error'        => fn(string $msg, int $status, array $vars) => new AppError(strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => [1 => 'המפתח'],          // ⟵ id => שם
    'is_developer' => fn(int $id) => $id === 1,         // ⟵ מפתח פעיל
    // בלי 'log' — היומן המובנה (30 יום). יש לאפליקציה יומן? 'log' => ['rows' => …, 'max_id' => …, 'write' => …]
]);
devloop_log_begin($user);

header('Content-Type: application/json; charset=utf-8');
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$in = $method === 'POST' && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

try {
    $isDev = !empty($user['developer']);
    $dev = fn() => $isDev ? $user : throw new AppError('רק המפתח', 403);
    $out = match ("$method $action") {
        // בלי עוגייה, באסימון (X-Dev-Token) — Claude
        'GET devapi-feed'          => devloop_api_feed(devloop_api_auth(), $_GET),
        'POST devapi-comment'      => devloop_api_comment(devloop_api_auth(), $in),
        'GET devapi-file'          => (devloop_api_auth() and devloop_file_out((int)($_GET['id'] ?? 0))),
        'POST devapi-attach'       => devloop_attach('claude', $_GET + ['_auth' => devloop_api_auth()]),
        // שגיאות מהדפדפן — ליומן המובנה
        'POST devloop-client-error' => devloop_log_client($in),
        // המפתח
        'GET dev-items'            => ($dev() ? devloop_task_list() : null),
        'POST dev-task-create'     => devloop_task_create($dev(), $in),
        'POST dev-task-set'        => devloop_task_set($dev(), $in),
        'POST dev-comment'         => devloop_comment_add((string)($in['code'] ?? ''), 'user', (int)$dev()['id'], (string)($in['body'] ?? '')),
        'POST dev-comment-delete'  => devloop_comment_delete($dev(), $in),
        'POST dev-verify'          => devloop_verify($dev(), $in),
        'POST dev-report'          => devloop_report($dev(), $in),
        'POST dev-attach'          => devloop_attach('user:' . $dev()['id'], $_GET),
        'GET dev-file'             => ($dev() ? devloop_file_out((int)($_GET['id'] ?? 0)) : null),
        'GET dev-tokens'           => ($dev() ? devloop_token_list() : null),
        'POST dev-token-create'    => devloop_token_create($dev(), $in),
        'POST dev-token-revoke'    => devloop_token_revoke($dev(), $in),
        'GET dev-item-log'         => ($dev() ? devloop_item_log((string)($_GET['code'] ?? '')) : null),
        'POST dev-log-links'       => ($dev() ? devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])) : null),
        'POST dev-task-from-log'   => devloop_task_from_log($dev(), $in),
        'GET dev-log'              => ($dev() ? devloop_log_view($_GET) : null),
        default                    => throw new AppError('פעולה לא מוכרת', 404),
    };
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    devloop_log_error($e);
    $code = $e instanceof AppError ? ($e->getCode() ?: 400) : 500;
    http_response_code($code >= 400 && $code < 600 ? $code : 400);
    echo json_encode(['error' => $e instanceof AppError ? $e->getMessage() : 'תקלה בשרת'], JSON_UNESCAPED_UNICODE);
}
