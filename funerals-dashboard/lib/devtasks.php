<?php
declare(strict_types=1);

/**
 * DevLoop — המטלות, האימות והיומן הטכני של המפתח (אפיון 01 §2.4.5). המנוע: lib/devloop.php — **עותק מסונכרן**
 * מ-devloop/src שבשורש הריפו (וכך גם assets/js/devloop.js ו-assets/css/devloop.css). לא עורכים אותם כאן.
 *
 * כאן רק החיבור: קידומת FD-, אסימון fdd_, מסד נפרד ב-data/dev/devloop.sqlite (מחוץ לגיבויי המיגרציה של התיקים),
 * והיומן המובנה של DevLoop (data/dev/log.sqlite, 30 יום). מפתח = platform_admin מהשער; לאסימון (בלי השער) —
 * העותק האחרון של הדגל ב-users.platform_admin.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/devloop.php';

/** שגיאה מהמודול — ה-API ממיר אותה לתשובה עם הסטטוס. */
final class FdError extends RuntimeException {}

function devDb(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dir = dataDir() . '/dev';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) reply(503, ['error' => 'לא ניתן ליצור את data/dev/']);
    $pdo = new PDO('sqlite:' . $dir . '/devloop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    return $pdo;
}

devloop_setup([
    'db'           => fn() => devDb(),
    'items_file'   => dirname(__DIR__) . '/dev/open-items.json',
    'data_dir'     => dataDir() . '/dev',
    'prefix'       => 'FD-',
    'token_prefix' => 'fdd_',
    'error'        => fn(string $msg, int $status, array $vars) => new FdError(
        strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(db()->query("SELECT id, CASE WHEN name <> '' THEN name ELSE COALESCE(email, 'משתמש ' || id) END AS name FROM users")->fetchAll(), 'name', 'id'),
    'is_developer' => function (int $id): bool {
        $st = db()->prepare('SELECT 1 FROM users WHERE id = ? AND platform_admin = 1');
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    },
]);

/** פעולות DevLoop עם אסימון fdd_ (בלי השער) — ציבוריות ב-app.json. */
const DEVAPI_ACTIONS = ['devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'];

/** הפעולות של המפתח במסך (עם השער). $dev — ['id', 'name']. null = לא פעולה של DevLoop. */
function devloopAction(string $action, string $method, array $dev): ?array {
    $post = ['dev-task-create', 'dev-task-set', 'dev-comment', 'dev-comment-delete', 'dev-verify', 'dev-report', 'dev-attach',
        'dev-token-create', 'dev-token-revoke', 'dev-log-links', 'dev-task-from-log', 'devloop-client-error'];
    $get = ['dev-items', 'dev-file', 'dev-tokens', 'dev-log', 'dev-item-log'];
    if (!in_array($action, $post, true) && !in_array($action, $get, true)) return null;
    if ($method !== (in_array($action, $post, true) ? 'POST' : 'GET')) reply(405, ['error' => 'שיטה לא מורשית']);
    $in = [];
    if ($method === 'POST' && $action !== 'dev-attach') {
        $raw = (string)file_get_contents('php://input', false, null, 0, 1_000_001);
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    $s = fn(string $k) => is_string($in[$k] ?? null) ? $in[$k] : '';
    return match ($action) {
        'devloop-client-error' => devloop_log_client($in),
        'dev-items'            => devloop_task_list(),
        'dev-task-create'      => devloop_task_create($dev, $in),
        'dev-task-set'         => devloop_task_set($dev, $in),
        'dev-comment'          => devloop_comment_add($s('code'), 'user', $dev['id'], $s('body')),
        'dev-comment-delete'   => devloop_comment_delete($dev, $in),
        'dev-verify'           => devloop_verify($dev, $in),
        'dev-report'           => devloop_report($dev, $in),
        'dev-attach'           => devloop_attach('user:' . $dev['id'], $_GET),
        'dev-file'             => devloop_file_out((int)($_GET['id'] ?? 0)),
        'dev-tokens'           => devloop_token_list(),
        'dev-token-create'     => devloop_token_create($dev, $in),
        'dev-token-revoke'     => devloop_token_revoke($dev, $in),
        'dev-log-links'        => devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])),
        'dev-task-from-log'    => devloop_task_from_log($dev, $in),
        'dev-log'              => devloop_log_view($_GET),
        'dev-item-log'         => devloop_item_log(is_string($_GET['code'] ?? null) ? $_GET['code'] : ''),
    };
}

/** פעולות האסימון (Claude). */
function devapiAction(string $action): array {
    $in = [];
    if ($action === 'devapi-comment') {
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 1_000_001), true);
        if (!is_array($in)) $in = [];
    }
    return match ($action) {
        'devapi-feed'    => devloop_api_feed(devloop_api_auth(), $_GET),
        'devapi-comment' => devloop_api_comment(devloop_api_auth(), $in),
        'devapi-file'    => devloop_api_auth() ? devloop_file_out((int)($_GET['id'] ?? 0)) : [],
        'devapi-attach'  => devloop_attach('claude', $_GET + ['_auth' => devloop_api_auth()]),
    };
}
