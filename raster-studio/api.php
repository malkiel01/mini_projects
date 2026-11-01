<?php
// ה-API של עיצוב רסטר. כל בקשה: POST עם JSON {"action": …} וכותרת X-Raster-Studio: 1.
// הכותרת היא ההגנה מ-CSRF: טופס או קישור מאתר אחר לא יכולים להוסיף כותרת משלהם.
// שני יוצאים מן הכלל:
// - העלאת ‎.mbe: ‏POST ‎?action=doc_upload&id=…&base=…‎ עם הקובץ עצמו בגוף (לא JSON — בלי base64 שמנפח פי 4/3).
// - תמונה ממוזערת: ‏GET ‎?action=doc_preview&id=…‎ — לתגית <img>, שלא יכולה לשלוח כותרת. קריאה בלבד, של הבעלים.

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docs.php';
require_once __DIR__ . '/lib/store.php';

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

/** קובץ בתשובה (במקום JSON) */
function rs_send_file(string $path, string $type, string $name = ''): never
{
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    if ($name !== '') header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
    readfile($path);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$qAction = (string)($_GET['action'] ?? '');

// ענן החבילה — מודול הכוננים (suite-cloud/drives): חיבור, אסימון לדפדפן (קבצים ו-AI), ומפתח ציבורי לענן.
// לפני הבדיקה שלנו (POST + X-Raster-Studio): drives.js קורא ב-GET / JSON, והענן מוריד את drives_key שרת-לשרת.
// עותק מסונכרן — לא עורכים את lib/drives/. drives.sqlite (המפתח הפרטי והחיבורים) — ליד המסד, ב-data/ (מוחרג בפריסה).
if (str_starts_with($qAction, 'drives_')) {
    require_once __DIR__ . '/lib/drives/Drives.php';
    (new \SuiteCloud\Drives([
        'client'   => 'raster-studio', 'name' => 'עיצוב רסטר',
        'dataDir'  => dirname(rs_db_path()),
        'endpoint' => 'https://mbe-plus.com/mini_projects/raster-studio/api.php',
        'user'     => fn() => ($u = rs_account()) ? (string)$u['id'] : null,
        'back'     => 'https://mbe-plus.com/mini_projects/raster-studio/',
    ]))->serve($qAction);
}
if ($method === 'GET' && $qAction === 'doc_preview') {
    $action = 'doc_preview';
    $in = ['id' => (int)($_GET['id'] ?? 0)];
} else {
    if ($method !== 'POST') rs_fail('method', 405);
    if (($_SERVER['HTTP_X_RASTER_STUDIO'] ?? '') !== '1') rs_fail('csrf', 403);
    if ($qAction === 'doc_upload') {
        $action = 'doc_upload';
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > rs_mbe_max()) rs_fail('too_big', 413);   // לפני שקוראים את הגוף
        $in = ['id' => (int)($_GET['id'] ?? 0), 'base' => isset($_GET['base']) && $_GET['base'] !== '' ? (int)$_GET['base'] : null,
            'force' => ($_GET['force'] ?? '') === '1'];
    } else {
        $in = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($in)) rs_fail('bad_json');
        $action = (string)($in['action'] ?? '');
    }
}

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
            rs_out(['ok' => true, 'me' => ['name' => $me['name'], 'email' => $me['email'], 'role' => $me['role']], 'maxUpload' => rs_mbe_max()]);
        case 'docs_list':
            rs_out(['ok' => true, 'docs' => rs_docs_list($uid)]);
        case 'doc_get':
            $doc = rs_doc_get($uid, (int)($in['id'] ?? 0));
            if (!$doc) rs_fail('not_found', 404);
            rs_out(['ok' => true, 'doc' => $doc]);
        case 'doc_create':
            $doc = rs_doc_create($uid, (string)($in['title'] ?? ''), (string)($in['size'] ?? ''), $in['px'] ?? null);
            rs_out(['ok' => true, 'doc' => $doc]);
        case 'doc_delete':
            if (!rs_doc_delete($uid, (int)($in['id'] ?? 0))) rs_fail('not_found', 404);
            rs_out(['ok' => true]);
        case 'doc_upload':
            $r = rs_doc_save($uid, $in['id'], file_get_contents('php://input') ?: '', $in['base'], $in['force']);
            rs_out(['ok' => true] + $r);
        case 'doc_versions':
            rs_out(['ok' => true, 'versions' => rs_doc_versions($uid, (int)($in['id'] ?? 0))]);
        case 'doc_file':
            $n = isset($in['v']) ? (int)$in['v'] : null;
            $path = rs_doc_file($uid, (int)($in['id'] ?? 0), $n);
            if (!$path) rs_fail('not_found', 404);
            rs_send_file($path, 'application/vnd.mbe+zip');
        case 'doc_preview':
            $path = rs_doc_preview($uid, (int)$in['id']);
            if (!$path) { http_response_code(404); exit; }
            header('Cache-Control: private, no-cache');
            rs_send_file($path, 'image/png');
        default:
            rs_fail('unknown_action');
    }
} catch (RsMbeRejected $e) {
    rs_out(['ok' => false, 'error' => 'mbe_invalid', 'issues' => array_map(fn($i) => ['code' => $i['code'], 'message' => $i['message']], $e->issues)], 422);
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage();
    rs_fail($code, ['not_found' => 404, 'conflict' => 409, 'too_big' => 413][$code] ?? 400);
} catch (Throwable $e) {
    error_log('raster-studio: ' . $e->getMessage());
    rs_fail('server', 500);
}
