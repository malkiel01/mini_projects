<?php
/**
 * API של ענן החבילה. בקשה אחת = action אחד (ב-query או בגוף ה-JSON).
 *
 * me, quota — מי נכנס וכמה מקום.
 * קבצים: list, stat, read (GET), mkdir, write, upload_start, upload_chunk (PUT, גוף בינארי), upload_finish,
 *         rename, move, trash, restore, trash_list, purge, trash_empty.
 * ניהול (רק admin): admin, admin_settings, admin_account.
 *
 * בשלב הזה — רק לדף של הענן, מאחורי השער (הזהות מ-PLATFORM_USER). חיבור אפליקציות בחתימה ובאסימון — ה-PR הבא.
 * כתיבה רק ב-POST עם Content-Type: application/json (או PUT לחלק) — טופס מאתר אחר לא יכול לשלוח כזה בלי preflight.
 * האפיון: docs/spec-1-skeleton.md, סעיף 6.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/drive.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function reply(array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** מגיש קובץ מהדיסק: ETag לפי sha256, 304, וטווח אחד (Range) — לווידאו ולהמשך הורדה. */
function sendFile(string $path, array $n): never {
    $etag = '"' . $n['sha256'] . '"';
    $size = (int) $n['size'];
    // רק סוגים שהדפדפן מציג בלי להריץ קוד — בתוך הדף; כל השאר (HTML, SVG…) — הורדה, בארגז חול.
    $inline = preg_match('#^(image/(png|jpeg|gif|webp)|application/pdf|text/plain|audio/|video/)#', (string) $n['mime']);
    header('Content-Type: ' . ($inline ? $n['mime'] : 'application/octet-stream'));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($n['name']));
    header('Cache-Control: private, no-cache');
    header("ETag: $etag");
    header('Accept-Ranges: bytes');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

    $from = 0; $to = $size - 1;
    if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $from = max(0, $size - (int) $m[2]); }
        else { $from = (int) $m[1]; if ($m[2] !== '') $to = min($to, (int) $m[2]); }
        if ($from > $to) { header("Content-Range: bytes */$size"); http_response_code(416); exit; }
        http_response_code(206);
        header("Content-Range: bytes $from-$to/$size");
    }
    header('Content-Length: ' . ($size === 0 ? 0 : $to - $from + 1));
    if ($size === 0 || ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $fh = fopen($path, 'rb');
    fseek($fh, $from);
    $left = $to - $from + 1;
    while ($left > 0 && !feof($fh)) {
        $buf = fread($fh, min(1 << 20, $left));
        if ($buf === false) break;
        echo $buf;
        $left -= strlen($buf);
        flush();
    }
    exit;
}

const WRITES = ['mkdir', 'write', 'upload_start', 'upload_finish', 'rename', 'move', 'trash', 'restore', 'purge', 'trash_empty',
                'admin_settings', 'admin_account'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$in = [];
if ($action !== 'upload_chunk') {
    $raw = file_get_contents('php://input');
    $in = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    if ($action === '' && is_string($in['action'] ?? null)) $action = $in['action'];
}

try {
    if (in_array($action, WRITES, true)
        && ($method !== 'POST' || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json'))) {
        throw new AppError('בקשה לא תקינה', 400, 'bad_request');
    }
    switch ($action) {
        case 'me': {
            $a = requireAccount();
            reply(['account' => publicAccount($a), 'quota' => quotaInfo($a), 'cloud_id' => setting('cloud_id')]);
        }
        case 'quota':          reply(quotaInfo(requireAccount()));
        case 'list':           reply(listFolder(requireAccount(), $_GET['folder'] ?? $in['folder'] ?? null));
        case 'stat': {
            $a = requireAccount();
            reply(publicNode(liveNode($a, $_GET['id'] ?? $in['id'] ?? null)));
        }
        case 'read': {
            [$path, $n] = readTarget(requireAccount(), $_GET['id'] ?? null);
            sendFile($path, $n);
        }
        case 'mkdir':          reply(makeFolder(requireAccount(), $in));
        case 'write':          reply(writeFile(requireAccount(), $in));
        case 'upload_start':   reply(uploadStart(requireAccount(), $in));
        case 'upload_chunk': {
            if ($method !== 'PUT') throw new AppError('בקשה לא תקינה', 400, 'bad_request');
            reply(uploadChunk(requireAccount(), $_GET['upload'] ?? null, (int) ($_GET['index'] ?? -1), 'php://input'));
        }
        case 'upload_finish':  reply(uploadFinish(requireAccount(), $in['upload'] ?? null));
        case 'rename':         reply(renameNode(requireAccount(), $in));
        case 'move':           reply(moveNode(requireAccount(), $in));
        case 'trash':          reply(trashNode(requireAccount(), $in));
        case 'restore':        reply(restoreNode(requireAccount(), $in));
        case 'trash_list':     reply(trashList(requireAccount()));
        case 'purge':          purgeNode(requireAccount(), $in); reply(['ok' => true]);
        case 'trash_empty':    reply(['removed' => emptyTrash(requireAccount())]);
        case 'admin':          requireAdmin(); reply(adminState());
        case 'admin_settings': requireAdmin(); reply(adminSettings($in));
        case 'admin_account':  reply(adminAccount(requireAdmin(), $in));
        default:
            throw new AppError('פעולה לא מוכרת', 404, 'unknown_action');
    }
} catch (AppError $e) {
    reply(['error' => $e->getMessage(), 'code' => $e->codeName] + $e->extra, $e->status);
} catch (Throwable $e) {
    error_log('suite-cloud: ' . $e);
    reply(['error' => 'תקלה בשרת', 'code' => 'server'], 500);
}
