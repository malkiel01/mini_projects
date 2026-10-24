<?php
// ה-API של העימוד. פעולות: me, docs_list, doc_create, doc_get, doc_save, doc_delete.
// כל בקשה: POST עם JSON {"action": …} וכותרת X-Layout-Studio: 1.
// הכותרת היא ההגנה מ-CSRF: טופס או קישור מאתר אחר לא יכולים להוסיף כותרת משלהם.

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docs.php';
require_once __DIR__ . '/lib/model.php';

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
        case 'doc_get':
            $got = ls_doc_get($uid, (int)($in['id'] ?? 0));
            if (!$got) ls_fail('not_found', 404);
            ls_out(['ok' => true] + $got);
        case 'doc_save':
            // המודל מגיע כמחרוזת JSON — נשמר בדיוק כמו שנשלח, בלי פענוח וקידוד מחדש שמשנים אותו
            $rev = ls_doc_save($uid, (int)($in['id'] ?? 0), (int)($in['rev'] ?? -1), $in['model'] ?? null);
            ls_out(['ok' => true, 'rev' => $rev]);
        case 'doc_delete':
            if (!ls_doc_delete($uid, (int)($in['id'] ?? 0))) ls_fail('not_found', 404);
            ls_out(['ok' => true]);
        default:
            ls_fail('unknown_action');
    }
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage();
    ls_fail($code, match ($code) { 'not_found' => 404, 'conflict' => 409, 'too_big' => 413, default => 400 });
} catch (Throwable $e) {
    error_log('layout-studio: ' . $e->getMessage());
    ls_fail('server', 500);
}
