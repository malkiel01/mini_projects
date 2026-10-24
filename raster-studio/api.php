<?php
// ה-API של עיצוב רסטר. כל בקשה: POST עם JSON {"action": …} וכותרת X-Raster-Studio: 1.
// הכותרת היא ההגנה מ-CSRF: טופס או קישור מאתר אחר לא יכולים להוסיף כותרת משלהם.

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rs_fail(string $code, int $status = 400): never
{
    rs_out(['ok' => false, 'error' => $code], $status);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_fail('method', 405);
if (($_SERVER['HTTP_X_RASTER_STUDIO'] ?? '') !== '1') rs_fail('csrf', 403);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) rs_fail('bad_json');
$action = (string)($in['action'] ?? '');

try {
    $me = rs_account();
} catch (Throwable $e) {
    error_log('raster-studio: ' . $e->getMessage());
    rs_fail('server', 500);
}
if (!$me) rs_fail('no_user', 401);
$uid = (int)$me['id'];

try {
    switch ($action) {
        case 'me':
            rs_out(['ok' => true, 'me' => ['name' => $me['name'], 'email' => $me['email'], 'role' => $me['role']]]);
        case 'docs_list':
            rs_out(['ok' => true, 'docs' => rs_docs_list($uid)]);
        case 'doc_create':
            $doc = rs_doc_create($uid, (string)($in['title'] ?? ''), (string)($in['size'] ?? ''), $in['px'] ?? null);
            rs_out(['ok' => true, 'doc' => $doc]);
        case 'doc_delete':
            if (!rs_doc_delete($uid, (int)($in['id'] ?? 0))) rs_fail('not_found', 404);
            rs_out(['ok' => true]);
        default:
            rs_fail('unknown_action');
    }
} catch (InvalidArgumentException $e) {
    rs_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('raster-studio: ' . $e->getMessage());
    rs_fail('server', 500);
}
