<?php
/**
 * API של ענן החבילה. בקשה אחת = action אחד (ב-query או בגוף ה-JSON).
 *
 * me, quota — מי נכנס וכמה מקום.
 * קבצים: list, stat, read (GET), mkdir, write, upload_start, upload_chunk (PUT, גוף בינארי), upload_finish,
 *         rename, move, trash, restore, trash_list, purge, trash_empty.
 * ניהול (רק admin): admin, admin_settings, admin_account.
 *
 * חיבור אפליקציה: cloud_key, client_register, connect_exchange / access_token / disconnect (חתומים), connections.
 * מי קורא לפעולת קבצים — actor() ב-lib/clients.php: הדף של הענן (השער), אסימון (Bearer) או חתימה (X-Cloud-*).
 * כתיבה רק ב-POST עם Content-Type: application/json (או PUT לחלק) — טופס מאתר אחר לא יכול לשלוח כזה בלי preflight.
 * האפיון: docs/spec-1-skeleton.md, סעיף 6.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/clients.php';

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
                'admin_settings', 'admin_account', 'admin_client', 'client_register', 'connect_exchange', 'access_token',
                'disconnect', 'connection_revoke'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$raw = (string) file_get_contents('php://input');   // גם לחתימה: הגיבוב הוא על הגוף בדיוק כפי שנשלח
$in = [];
if ($action !== 'upload_chunk') {
    $in = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    if ($action === '' && is_string($in['action'] ?? null)) $action = $in['action'];
}

try {
    corsHeaders();
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    if (in_array($action, WRITES, true)
        && ($method !== 'POST' || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json'))) {
        throw new AppError('בקשה לא תקינה', 400, 'bad_request');
    }
    // פעולות קבצים: הדף של הענן (השער), אפליקציה עם אסימון, או שרת של אפליקציה בחתימה — actor() מחליט.
    $files = fn() => actor($action, 'files', $raw);
    switch ($action) {
        case 'me': {
            $a = requireAccount();
            reply(['account' => publicAccount($a), 'quota' => quotaInfo($a), 'cloud_id' => setting('cloud_id')]);
        }
        case 'quota':          reply(quotaInfo($files()['account']));
        case 'list':           reply(listFolder($files()['account'], $_GET['folder'] ?? $in['folder'] ?? null));
        case 'stat':           reply(publicNode(liveNode($files()['account'], $_GET['id'] ?? $in['id'] ?? null)));
        case 'read': {
            [$path, $n] = readTarget($files()['account'], $_GET['id'] ?? null);
            sendFile($path, $n);
        }
        case 'mkdir':          { $x = $files(); reply(makeFolder($x['account'], $in, $x['app'])); }
        case 'write':          { $x = $files(); reply(writeFile($x['account'], $in, $x['app'])); }
        case 'upload_start':   reply(uploadStart($files()['account'], $in));
        case 'upload_chunk': {
            if ($method !== 'PUT') throw new AppError('בקשה לא תקינה', 400, 'bad_request');
            reply(uploadChunk($files()['account'], $_GET['upload'] ?? null, (int) ($_GET['index'] ?? -1), 'php://input'));
        }
        case 'upload_finish':  { $x = $files(); reply(uploadFinish($x['account'], $in['upload'] ?? null, $x['app'])); }
        case 'rename':         { $x = $files(); reply(renameNode($x['account'], $in, $x['app'])); }
        case 'move':           { $x = $files(); reply(moveNode($x['account'], $in, $x['app'])); }
        case 'trash':          reply(trashNode($files()['account'], $in));
        case 'restore':        reply(restoreNode($files()['account'], $in));
        case 'trash_list':     reply(trashList($files()['account']));
        case 'purge':          purgeNode($files()['account'], $in); reply(['ok' => true]);
        case 'trash_empty':    reply(['removed' => emptyTrash($files()['account'])]);

        // ── חיבור אפליקציה (docs/spec-1-skeleton.md, סעיף 7) ──
        case 'cloud_key':      reply(cloudKeyPublic());
        case 'client_register': reply(registerClient($in));
        case 'connect_exchange': reply(exchangeCode(verifySigned($action, signedHeaders(), $raw), $in));
        case 'access_token': {
            $client = verifySigned($action, signedHeaders(), $raw);
            reply(issueToken($client, connectionRow((string) ($in['connection'] ?? ''), $client['id'])));
        }
        case 'disconnect': {
            $client = verifySigned($action, signedHeaders(), $raw);
            $st = db()->prepare('SELECT id FROM connections WHERE id = ? AND client = ?');
            $st->execute([(string) ($in['connection'] ?? ''), $client['id']]);
            if ($id = $st->fetchColumn()) revokeConnection((string) $id);
            reply(['ok' => true]);   // גם אם כבר נותק — התוצאה זהה
        }
        case 'connections':    reply(['items' => accountConnections(requireAccount()), 'scopes' => SCOPE_NAMES]);
        case 'connection_revoke': revokeOwnConnection(requireAccount(), $in['id'] ?? null); reply(['ok' => true]);

        case 'admin':          requireAdmin(); reply(adminState() + ['clients' => adminClients()]);
        case 'admin_settings': requireAdmin(); reply(adminSettings($in));
        case 'admin_account':  reply(adminAccount(requireAdmin(), $in));
        case 'admin_client':   requireAdmin(); reply(['clients' => adminClient($in)]);
        default:
            throw new AppError('פעולה לא מוכרת', 404, 'unknown_action');
    }
} catch (AppError $e) {
    reply(['error' => $e->getMessage(), 'code' => $e->codeName] + $e->extra, $e->status);
} catch (Throwable $e) {
    error_log('suite-cloud: ' . $e);
    reply(['error' => 'תקלה בשרת', 'code' => 'server'], 500);
}
