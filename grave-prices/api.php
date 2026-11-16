<?php
/**
 * ה-API היחיד: api.php?action=… → JSON. docs/spec/01-foundation-print.md סעיף 4, 02-editing.md סעיף 3.
 * שגיאה — {"error": "<עברית>"} וקוד HTTP. כל POST — עם X-CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/editions.php';
require_once __DIR__ . '/lib/edit.php';
require_once __DIR__ . '/lib/devloop.php';

devloop_setup([
    'db'           => fn() => db(),
    'items_file'   => __DIR__ . '/dev/open-items.json',
    'data_dir'     => gp_data_dir() . '/devloop',
    'prefix'       => 'GP-',
    'token_prefix' => 'gpd_',
    'error'        => fn(string $msg, int $status, array $vars) => new ApiError(strtr($msg, array_combine(
        array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(db()->query('SELECT id, name FROM users')->fetchAll(), 'name', 'id'),
    'is_developer' => function (int $id) {
        $st = db()->prepare('SELECT is_dev FROM users WHERE id = ?');
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    },
]);

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // בלי משתמש: החזרה מהכניסה, ו-Claude באסימון (ב-"public" של app.json)
    if ($action === 'sso_start') gp_sso_start();
    if ($action === 'sso_callback') gp_sso_callback($_GET);
    $user = str_starts_with($action, 'devapi-') ? null : gp_user();
    devloop_log_begin($user ? ['id' => (int)$user['id'], 'name' => $user['name']] : null);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $in = [];
    if ($method === 'POST' && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $in = [];
    }
    if (!str_starts_with($action, 'devapi-')) {
        if (!$user) throw new ApiError('צריך להיכנס', 401);
        if ($method === 'POST') gp_check_csrf();
    }
    $uid = $user ? (int)$user['id'] : 0;
    $dev = fn() => $user && $user['is_dev'] ? ['id' => $uid, 'name' => $user['name']] : throw new ApiError('למפתח בלבד', 403);

    $out = match ("$method $action") {
        'GET me'         => ['name' => $user['name'], 'is_dev' => (bool)$user['is_dev'], 'csrf' => gp_csrf(),
                             'version' => (string)(json_decode((string)file_get_contents(__DIR__ . '/app.json'), true)['version'] ?? '')],
        'GET editions'   => gp_editions((string)($_GET['audience'] ?? '')),
        'GET edition'    => gp_edition((string)($_GET['pid'] ?? '')),
        'POST print-log' => gp_print_log($uid, $in),
        'GET history'    => gp_history((string)($_GET['pid'] ?? '')),

        // עריכה — פרוסה 02 (lib/edit.php)
        'POST price-set'       => gp_price_set($uid, $in),
        'POST row-save'        => gp_row_save($uid, $in),
        'POST row-delete'      => gp_row_delete($uid, $in),
        'POST row-move'        => gp_row_move($uid, $in),
        'GET settings'         => ['signatures' => gp_signatures()],
        'POST signatures-set'  => gp_signatures_set($uid, $in),   // הגדרות — סט אחד לכל ההדפסות
        'POST edition-create'  => gp_edition_create($uid, $in),
        'POST edition-update'  => gp_edition_update($uid, $in),
        'POST edition-delete'  => gp_edition_delete($uid, $in),
        'GET health'     => $dev() ? gp_health() : null,
        'GET audit'      => $dev() ? gp_audit_list() : null,

        // DevLoop — אותם שמות בכל אפליקציה (devloop/README.md)
        'GET devapi-feed'           => devloop_api_feed(devloop_api_auth(), $_GET),
        'POST devapi-comment'       => devloop_api_comment(devloop_api_auth(), $in),
        'GET devapi-file'           => (devloop_api_auth() and devloop_file_out((int)($_GET['id'] ?? 0))),
        'POST devapi-attach'        => devloop_attach('claude', $_GET + ['_auth' => devloop_api_auth()]),
        'POST devloop-client-error' => devloop_log_client($in),
        'GET dev-items'             => $dev() ? devloop_task_list() : null,
        'POST dev-task-create'      => devloop_task_create($dev(), $in),
        'POST dev-task-set'         => devloop_task_set($dev(), $in),
        'POST dev-comment'          => devloop_comment_add((string)($in['code'] ?? ''), 'user', (int)$dev()['id'], (string)($in['body'] ?? '')),
        'POST dev-comment-delete'   => devloop_comment_delete($dev(), $in),
        'POST dev-verify'           => devloop_verify($dev(), $in),
        'POST dev-report'           => devloop_report($dev(), $in),
        'POST dev-attach'           => devloop_attach('user:' . $dev()['id'], $_GET),
        'GET dev-file'              => $dev() ? devloop_file_out((int)($_GET['id'] ?? 0)) : null,
        'GET dev-tokens'            => $dev() ? devloop_token_list() : null,
        'POST dev-token-create'     => devloop_token_create($dev(), $in),
        'POST dev-token-revoke'     => devloop_token_revoke($dev(), $in),
        'GET dev-item-log'          => $dev() ? devloop_item_log((string)($_GET['code'] ?? '')) : null,
        'POST dev-log-links'        => $dev() ? devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])) : null,
        'POST dev-task-from-log'    => devloop_task_from_log($dev(), $in),
        'GET dev-log'               => $dev() ? devloop_log_view($_GET) : null,
        default                     => throw new ApiError('פעולה לא מוכרת', 404),
    };
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('devloop_log_error')) devloop_log_error($e);
    $code = $e instanceof ApiError ? ($e->getCode() ?: 400) : 500;
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code >= 400 && $code < 600 ? $code : 400);
    echo json_encode($e instanceof ApiError ? ['error' => $e->getMessage()] + $e->extra : ['error' => 'תקלה בשרת'], JSON_UNESCAPED_UNICODE);
}

/** {il, abroad, mode} — לפחות אחד מהשניים; כל אחד חייב להיות מהקבוצה שלו. שורה אחת ביומן. */
function gp_print_log(int $uid, array $in): array
{
    $mode = (string)($in['mode'] ?? '');
    if (!in_array($mode, GP_PRINT_MODES, true)) throw new ApiError('תצורת הדפסה לא מוכרת');
    $eds = [];
    foreach (array_keys(GP_AUDIENCES) as $a) {
        if (($in[$a] ?? '') === '' || $in[$a] === null) continue;
        $e = gp_edition_row((string)$in[$a]);
        if ($e['audience'] !== $a) throw new ApiError('המחירון לא שייך ל' . GP_AUDIENCES[$a]);
        $eds[$a] = $e;
    }
    if (!$eds) throw new ApiError('לא נבחר מחירון');
    if ($mode !== 'one' && count($eds) < 2) throw new ApiError('להדפסה משותפת צריך מחירון מכל קבוצה');
    $first = reset($eds);
    gp_audit($uid, 'print', (int)$first['id'], ['mode' => $mode] + array_map(fn($e) => $e['effective_from'], $eds));
    return ['ok' => true];
}

function gp_health(): array
{
    $dir = gp_data_dir();
    $probe = $dir . '/.write-test';
    $writable = @file_put_contents($probe, now_iso()) !== false;
    if ($writable) @unlink($probe);
    return ['checks' => [
        ['name' => 'המסד נפתח', 'ok' => (int)db()->query('SELECT 1')->fetchColumn() === 1, 'value' => 'SQLite ' . db()->query('SELECT sqlite_version()')->fetchColumn()],
        ['name' => 'כתיבה לתיקיית הנתונים', 'ok' => $writable, 'value' => $writable ? 'תקין' : 'אין הרשאת כתיבה'],
        ['name' => 'גרסה', 'ok' => true, 'value' => (string)(json_decode((string)file_get_contents(__DIR__ . '/app.json'), true)['version'] ?? '?')],
        ['name' => 'מחירונים', 'ok' => true, 'value' => (string)db()->query('SELECT COUNT(*) FROM editions')->fetchColumn()],
        ['name' => 'גרסת המסד', 'ok' => true, 'value' => (string)db()->query('PRAGMA user_version')->fetchColumn()],
    ]];
}

function gp_audit_list(): array
{
    $rows = db()->query('SELECT a.at, a.action, a.details, u.name AS user, e.effective_from FROM audit a
        LEFT JOIN users u ON u.id = a.user_id LEFT JOIN editions e ON e.id = a.edition_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
    return ['audit' => array_map(fn($r) => ['at' => $r['at'], 'action' => $r['action'], 'user' => $r['user'] ?? '—',
        'edition' => $r['effective_from'], 'details' => json_decode($r['details'], true) ?: []], $rows)];
}
