<?php
// ה-API של העימוד. פעולות: me, docs_list, doc_create, doc_get, doc_save, doc_rename, doc_delete, account_* (ui-core).
// כל בקשה: POST עם JSON {"action": …} וכותרת X-Layout-Studio: 1.
// תמונות: העלאה — POST ?action=media_upload, גוף = הקובץ עצמו (עם אותה כותרת); הצגה — GET ?media=<sha256>.<ext>.
// PDF: POST ?action=pdf, גוף = רשימת הציור (JSON דחוס ב-gzip, assets/js/pdf.js) → application/pdf.
// ‎.mbe: POST ?action=doc_import&name=<שם הקובץ>, גוף = הקובץ → מסמך חדש; POST ?action=mbe_source&id=<מסמך>,
// גוף = הקובץ שירד (קובץ המקור להורדה הבאה); GET ?mbe_source=<מסמך> → הקובץ, או 404.
// הכותרת היא ההגנה מ-CSRF: טופס או קישור מאתר אחר לא יכולים להוסיף כותרת משלהם.

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docs.php';
require_once __DIR__ . '/lib/model.php';
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/pdf.php';
require_once __DIR__ . '/lib/mbeio.php';
require_once __DIR__ . '/lib/ui-core/account/account.php';   // 👤 תפריט המשתמש — עותק מסונכרן של ui-core

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

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ☁️ ענן החבילה — מודול הכוננים (suite-cloud/drives; עותק מסונכרן ב-lib/drives — לא עורכים כאן): חיבור, ואסימון לדפדפן.
// לפני הבדיקה שלנו (POST + X-Layout-Studio): drives.js קורא ב-GET / JSON, והענן מוריד את drives_key שרת-לשרת.
// drives.sqlite (המפתח הפרטי והחיבורים) — ליד המסד, ב-data/ (מוחרג בפריסה: data/*.sqlite*).
$qAction = (string)($_GET['action'] ?? '');
if (str_starts_with($qAction, 'drives_')) {
    require_once __DIR__ . '/lib/drives/Drives.php';
    (new \SuiteCloud\Drives([
        'client'   => 'layout-studio', 'name' => 'עימוד',
        'dataDir'  => dirname(ls_db_path()),
        'endpoint' => 'https://mbe-plus.com/mini_projects/layout-studio/api.php',
        'user'     => fn() => ($u = ls_account()) ? (string)$u['id'] : null,
        'back'     => 'https://mbe-plus.com/mini_projects/layout-studio/',
    ]))->serve($qAction);
}

$isMedia = $method === 'GET' && (isset($_GET['media']) || isset($_GET['mbe_source']));
if ($method !== 'POST' && !$isMedia) ls_fail('method', 405);
// קריאה (GET של תמונה) לא משנה כלום — בלי כותרת, כדי ש-<img> יוכל לטעון. כל השאר — רק עם הכותרת (CSRF).
if (!$isMedia && ($_SERVER['HTTP_X_LAYOUT_STUDIO'] ?? '') !== '1') ls_fail('csrf', 403);

try {
    $me = ls_account();
} catch (Throwable $e) {
    error_log('layout-studio: ' . $e->getMessage());
    ls_fail('server', 500);
}
if (!$me) ls_fail('no_user', 401);
$uid = (int)$me['id'];

if ($isMedia && isset($_GET['mbe_source'])) {
    $src = ls_mbe_source_get($uid, (int)$_GET['mbe_source']);
    if ($src === null) { http_response_code(404); exit; }
    header('Content-Type: application/vnd.mbe+zip');
    header('Content-Length: ' . strlen($src));
    header("Content-Security-Policy: default-src 'none'");
    echo $src;
    exit;
}
if ($isMedia) ls_media_send($uid, (string)$_GET['media']);

if (in_array($_GET['action'] ?? '', ['doc_import', 'mbe_source'], true)) {
    @ini_set('memory_limit', '512M');
    try {
        $body = file_get_contents('php://input') ?: '';
        if ($_GET['action'] === 'doc_import') ls_out(['ok' => true, 'doc' => ls_mbe_import($uid, $body, (string)($_GET['name'] ?? ''))]);
        ls_mbe_source_put($uid, (int)($_GET['id'] ?? 0), $body);
        ls_out(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        $code = $e->getMessage();
        ls_fail($code, match (true) { $code === 'not_found' => 404, $code === 'too_big' => 413, default => 400 });
    } catch (Throwable $e) {
        error_log('layout-studio: ' . $e->getMessage());
        ls_fail('server', 500);
    }
}

// גופן של המשתמש (מהענן או מהמכשיר): POST ?action=font_upload, גוף = קובץ ה-TTF
if (($_GET['action'] ?? '') === 'font_upload') {
    try {
        ls_out(['ok' => true] + ls_font_put($uid, file_get_contents('php://input') ?: ''));
    } catch (InvalidArgumentException $e) {
        ls_fail($e->getMessage(), $e->getMessage() === 'too_big' ? 413 : 400);
    } catch (Throwable $e) {
        error_log('layout-studio: ' . $e->getMessage());
        ls_fail('server', 500);
    }
}
if (($_GET['action'] ?? '') === 'media_upload') {
    try {
        ls_out(['ok' => true] + ls_media_put($uid, file_get_contents('php://input') ?: ''));
    } catch (InvalidArgumentException $e) {
        ls_fail($e->getMessage(), $e->getMessage() === 'too_big' ? 413 : 400);
    } catch (Throwable $e) {
        error_log('layout-studio: ' . $e->getMessage());
        ls_fail('server', 500);
    }
}

if (($_GET['action'] ?? '') === 'pdf') {
    @set_time_limit(120);
    @ini_set('memory_limit', '512M');
    try {
        $pdf = ls_pdf($uid, file_get_contents('php://input') ?: '');
    } catch (InvalidArgumentException $e) {
        $code = $e->getMessage();
        if ($code === 'too_big') ls_fail('too_big', 413);
        if ($code === 'bad_list') ls_fail('bad_list');
        ls_out(['ok' => false, 'error' => 'pdf', 'detail' => $code], 400);   // של pdf-core: מה חסר ואיפה
    } catch (Throwable $e) {
        error_log('layout-studio: ' . $e->getMessage());
        ls_fail('server', 500);
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) ls_fail('bad_json');
$action = (string)($in['action'] ?? '');

// 👤 תפריט המשתמש (ui-core): הגדרות אישיות במסד שלנו (uic_prefs), "ייצוא כל הנתונים שלי" ו"מחיקת החשבון"
if (str_starts_with($action, 'account_')) {
    try {
        ls_out(['ok' => true] + uic_account_api($action, $in, [
            'app' => 'layout-studio',
            'pdo' => fn() => ls_db(),
            'user' => fn() => $uid,
            'export' => fn($u) => ls_account_export((int)$u),
            'delete' => fn($u) => ls_account_delete((int)$u),
        ]));
    } catch (UicAccountError $e) {
        ls_out(['ok' => false, 'error' => $e->getMessage()], $e->status);
    } catch (Throwable $e) {
        error_log('layout-studio: ' . $e->getMessage());
        ls_fail('server', 500);
    }
}

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
            $rev = ls_doc_save($uid, (int)($in['id'] ?? 0), (int)($in['rev'] ?? -1), $in['model'] ?? null,
                fn(string $src) => ls_media_path($uid, substr($src, 6)) !== null);
            ls_out(['ok' => true, 'rev' => $rev]);
        case 'doc_by_mbe':   // "פנים": PDF מוחתם שחזר (📂) → המסמך שממנו יצא
            $d = ls_doc_by_mbe_id($uid, (string)($in['mbe_id'] ?? ''));
            if (!$d) ls_fail('not_found', 404);
            ls_out(['ok' => true, 'doc' => $d]);
        case 'doc_rename':
            ls_out(['ok' => true, 'doc' => ls_doc_rename($uid, (int)($in['id'] ?? 0), (string)($in['title'] ?? ''))]);
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
