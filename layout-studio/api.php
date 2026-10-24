<?php
// ה-API של העימוד. כל בקשה: POST עם JSON {"action": …} וכותרת X-Layout-Studio: 1.
// הכותרת היא ההגנה מ-CSRF: טופס או קישור מאתר אחר לא יכולים להוסיף כותרת משלהם.

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ls_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ls_fail(string $code, int $status = 400): never
{
    ls_out(['ok' => false, 'error' => $code], $status);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ls_fail('method', 405);
if (($_SERVER['HTTP_X_LAYOUT_STUDIO'] ?? '') !== '1') ls_fail('csrf', 403);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) ls_fail('bad_json');
$action = (string)($in['action'] ?? '');

try {
    $me = ls_account();
} catch (Throwable $e) {
    error_log('layout-studio: ' . $e->getMessage());
    ls_fail('server', 500);
}
if (!$me) ls_fail('no_user', 401);
$uid = (int)$me['id'];

try {
    switch ($action) {
        case 'me':
            ls_out(['ok' => true, 'me' => ['name' => $me['name'], 'email' => $me['email'], 'role' => $me['role']],
                'presets' => array_map(fn($p) => $p['label'], ls_page_presets())]);
        case 'docs_list':
            ls_out(['ok' => true, 'docs' => ls_docs_list($uid)]);
        case 'doc_create':
            $doc = ls_doc_create($uid, (string)($in['title'] ?? ''), (string)($in['size'] ?? ''), $in['mm'] ?? null);
            ls_out(['ok' => true, 'doc' => $doc]);
        case 'doc_delete':
            if (!ls_doc_delete($uid, (int)($in['id'] ?? 0))) ls_fail('not_found', 404);
            ls_out(['ok' => true]);
        default:
            ls_fail('unknown_action');
    }
} catch (InvalidArgumentException $e) {
    ls_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('layout-studio: ' . $e->getMessage());
    ls_fail('server', 500);
}
