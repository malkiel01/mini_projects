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
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/fonts.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/**
 * הקלט של ai_run: multipart מהדפדפן (image / mask / images[] כקבצים), או JSON (image_b64 / mask_b64 / images_b64) —
 * JSON הוא הדרך היחידה בקריאה חתומה, כי ב-multipart ה-PHP לא נותן את הגוף הגולמי לבדיקת החתימה.
 */
function aiInput(array $json): array {
    $f = $_POST ?: $json;
    $in = [];
    foreach (['task', 'provider', 'prompt', 'system', 'size'] as $k) if (isset($f[$k]) && is_scalar($f[$k])) $in[$k] = (string) $f[$k];
    if (isset($f['transparent'])) $in['transparent'] = filter_var($f['transparent'], FILTER_VALIDATE_BOOLEAN);
    if (isset($f['schema'])) {
        $s = is_string($f['schema']) ? json_decode($f['schema'], true) : $f['schema'];
        if (!is_array($s)) throw new AppError('schema אינו JSON', 400, 'bad_request');
        $in['schema'] = $s;
    }
    $file = function (string $name) use ($f): ?string {
        if (isset($_FILES[$name]) && is_uploaded_file($_FILES[$name]['tmp_name'] ?? '')) return (string) file_get_contents($_FILES[$name]['tmp_name']);
        if (isset($f[$name . '_b64']) && is_string($f[$name . '_b64'])) {
            $b = base64_decode($f[$name . '_b64'], true);
            if ($b === false) throw new AppError("$name אינו base64", 400, 'bad_request');
            return $b;
        }
        return null;
    };
    foreach (['image', 'mask'] as $k) if (($b = $file($k)) !== null) $in[$k] = $b;
    $imgs = [];
    foreach ((array) ($_FILES['images']['tmp_name'] ?? []) as $t) if (is_uploaded_file($t)) $imgs[] = (string) file_get_contents($t);
    foreach ((array) ($f['images_b64'] ?? []) as $b) if (is_string($b) && ($d = base64_decode($b, true)) !== false) $imgs[] = $d;
    if ($imgs) $in['images'] = $imgs;
    return $in;
}

/** מצב הניהול המלא: חשבונות (עם הרשאות ה-AI), אפליקציות רשומות. כל פעולת ניהול מחזירה אותו — כך שום רשימה לא מתרוקנת. */
function adminFull(array $s): array {
    foreach ($s['accounts'] as &$a) $a['ai'] = aiUser($a['id']) + ['spent' => aiSpent($a['id'])];
    return $s + ['clients' => adminClients()];
}

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
                'disconnect', 'connection_revoke', 'ai_provider', 'ai_test', 'ai_default', 'ai_app', 'ai_user', 'font_update', 'font_delete'];

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
        // ── בינה מלאכותית (docs/spec-3-ai.md) ──
        case 'ai_capabilities': { $x = actor($action, 'ai', $raw); reply(aiCapabilities($x['account'], $x['app'])); }
        case 'ai_run': {
            if ($method !== 'POST') throw new AppError('בקשה לא תקינה', 400, 'bad_request');
            $bearer = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_CLOUD_TOKEN'])
                || !empty($_SERVER['HTTP_X_CLOUD_SIGNATURE']);
            // מהדף של הענן (עוגייה): כותרת שאתר זר לא יכול לשלוח בלי preflight — כדי שלא יוציאו כסף בשמך
            if (!$bearer && ($_SERVER['HTTP_X_CLOUD_REQUEST'] ?? '') !== '1') throw new AppError('בקשה לא תקינה', 400, 'bad_request');
            $x = actor($action, 'ai', $raw);
            $res = aiRun($x['account'], $x['app'], aiInput($in));
            if (isset($res['png'])) {
                header('Content-Type: image/png');
                header('X-AI-Size: ' . implode('x', $res['size']));
                header('X-AI-Transparent: ' . ($res['transparent'] ? '1' : '0'));
                header('X-AI-Provider: ' . $res['provider']);
                echo $res['png'];
                exit;
            }
            if (isset($res['masks'])) $res['masks'] = array_map(fn($m) => ['png' => base64_encode($m['png'])] + $m, $res['masks']);
            reply($res);
        }
        // ── ספריית הגופנים (docs/spec-2-fonts.md) ──
        case 'fonts_list':     reply(fontsList(actor($action, 'libs', $raw)['account']));
        case 'font_add': {
            if ($method !== 'POST') throw new AppError('בקשה לא תקינה', 400, 'bad_request');
            $bearer = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_CLOUD_TOKEN'])
                || !empty($_SERVER['HTTP_X_CLOUD_SIGNATURE']);
            if (!$bearer && ($_SERVER['HTTP_X_CLOUD_REQUEST'] ?? '') !== '1') throw new AppError('בקשה לא תקינה', 400, 'bad_request');
            $x = actor($action, 'libs', $raw);
            $f = $_POST ?: $in;
            if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
                $bytes = (string) file_get_contents($_FILES['file']['tmp_name']);
                $f['filename'] ??= $_FILES['file']['name'];
            } else {
                $bytes = base64_decode((string) ($f['content_b64'] ?? ''), true);
                if ($bytes === false || $bytes === '') throw new AppError('חסר קובץ', 400, 'bad_request');
            }
            $f['license_ack'] = filter_var($f['license_ack'] ?? false, FILTER_VALIDATE_BOOLEAN);
            reply(fontAdd($x['account'], $bytes, $f, $x['app']));
        }
        case 'font_file': {
            [$path, $r] = fontFile(actor($action, 'libs', $raw)['account'], $_GET['id'] ?? null);
            header('Content-Type: ' . FONT_FORMATS[$r['format']]);
            header('Content-Length: ' . filesize($path));
            // לפי rev בכתובת — התוכן לא משתנה לעולם; גופן שהוחלף מקבל rev חדש
            header('Cache-Control: ' . (($_GET['rev'] ?? '') === $r['sha256'] ? 'private, max-age=31536000, immutable' : 'private, no-cache'));
            header('ETag: "' . $r['sha256'] . '"');
            readfile($path);
            exit;
        }
        case 'font_update':    reply(fontUpdate(actor($action, 'libs', $raw)['account'], $in));
        case 'font_delete':    fontDelete(actor($action, 'libs', $raw)['account'], $in['id'] ?? null); reply(['ok' => true]);

        case 'ai_admin':       requireAdmin(); reply(aiAdminState());
        case 'ai_provider':    requireAdmin(); reply(aiAdminProvider($in));
        case 'ai_test':        requireAdmin(); reply(aiAdminTest($in['provider'] ?? null));
        case 'ai_default':     requireAdmin(); reply(aiAdminDefault($in));
        case 'ai_app':         requireAdmin(); reply(aiAdminApp($in));
        case 'ai_user':        requireAdmin(); reply(aiAdminUser($in));

        case 'connections':    reply(['items' => accountConnections(requireAccount()), 'scopes' => SCOPE_NAMES]);
        case 'connection_revoke': revokeOwnConnection(requireAccount(), $in['id'] ?? null); reply(['ok' => true]);

        case 'admin':          requireAdmin(); reply(adminFull(adminState()));
        case 'admin_settings': requireAdmin(); reply(adminFull(adminSettings($in)));
        case 'admin_account':  reply(adminFull(adminAccount(requireAdmin(), $in)));
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
