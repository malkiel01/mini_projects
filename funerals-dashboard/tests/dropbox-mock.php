<?php
// Dropbox מדומה לבדיקות בלבד: php -S 127.0.0.1:PORT tests/dropbox-mock.php, עם DBX_MOCK_STATE=<קובץ JSON>.
// מחקה את נקודות הקצה שהאפליקציה משתמשת בהן (OAuth, files/*, users, auth/token/revoke), בלי רשת.
// /_mock/* — בקרה מהבדיקות: אישור OAuth, הוספה/מחיקה/העברה של קבצים, דגלים, ויומן הקריאות.
// הקובץ לא נפרס (tests/ ב-exclude של deploy.yml) ורץ רק תחת שרת הפיתוח של PHP.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$stateFile = (string)getenv('DBX_MOCK_STATE');
$fh = fopen($stateFile, 'c+');
flock($fh, LOCK_EX);
$S = json_decode((string)stream_get_contents($fh), true) ?: [];
$S += ['app_key' => 'mockkey123', 'app_secret' => 'mocksecret456', 'codes' => [], 'refresh' => [], 'access' => [],
    'fail_refresh' => false, 'entries' => [], 'log' => [], 'seq' => 0, 'sessions' => [], 'calls' => [], 'reset_next' => false,
    'page_size' => 1000, 'revoked' => 0, 'account' => ['id' => 'dbid:AAA111', 'name' => 'חשבון בדיקה']];

function save(): void { global $fh, $S; ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($S, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); fflush($fh); }
function out(int $st, mixed $body, array $headers = []): never {
    save();
    http_response_code($st);
    foreach ($headers as $h) header($h);
    if (is_string($body)) echo $body;
    else { header('Content-Type: application/json'); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    exit;
}
function err409(string $summary): never { out(409, ['error_summary' => $summary, 'error' => ['.tag' => explode('/', $summary)[0]]]); }
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function low(string $p): string { return mb_strtolower($p); }
function byPath(string $p): ?string { global $S; foreach ($S['entries'] as $id => $e) if ($e['path_lower'] === low($p)) return $id; return null; }
function resolve(string $p): ?string {
    global $S;
    if (str_starts_with($p, 'id:')) return isset($S['entries'][$p]) ? $p : null;
    return byPath($p);
}
function meta(string $id): array {
    global $S;
    $e = $S['entries'][$id];
    $m = ['.tag' => $e['tag'], 'name' => basename($e['path_display']), 'path_lower' => $e['path_lower'], 'path_display' => $e['path_display'], 'id' => $id];
    if ($e['tag'] === 'file') $m += ['size' => $e['size'], 'rev' => $e['rev'], 'server_modified' => $e['server_modified'], 'client_modified' => $e['server_modified']];
    return $m;
}
function logEntry(array $m): void { global $S; $S['seq']++; $S['log'][] = ['s' => $S['seq'], 'e' => $m]; }
function mkdirs(string $path): void {
    global $S;
    $parts = array_values(array_filter(explode('/', $path), 'strlen'));
    $cur = '';
    foreach ($parts as $p) {
        $cur .= '/' . $p;
        if (byPath($cur) === null) {
            $id = 'id:' . bin2hex(random_bytes(6));
            $S['entries'][$id] = ['tag' => 'folder', 'path_display' => $cur, 'path_lower' => low($cur)];
            logEntry(meta($id));
        }
    }
}
function addFile(string $path, int $size, bool $autorename): string {
    global $S;
    if (byPath($path) !== null) {
        if (!$autorename) err409('path/conflict/file/');
        $info = pathinfo($path);
        for ($n = 1; byPath($path) !== null; $n++) $path = $info['dirname'] . '/' . $info['filename'] . " ($n)" . (isset($info['extension']) ? '.' . $info['extension'] : '');
    }
    mkdirs(dirname($path));
    $id = 'id:' . bin2hex(random_bytes(6));
    $S['entries'][$id] = ['tag' => 'file', 'path_display' => $path, 'path_lower' => low($path), 'size' => $size,
        'rev' => bin2hex(random_bytes(5)), 'server_modified' => gmdate('Y-m-d\TH:i:s\Z')];
    logEntry(meta($id));
    return $id;
}
function under(string $lower, string $base, bool $recursive): bool {
    if ($base === '') return $recursive ? true : substr_count($lower, '/') === 1;
    if (!str_starts_with($lower, $base . '/')) return false;
    return $recursive || !str_contains(substr($lower, strlen($base) + 1), '/');
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string)file_get_contents('php://input');

/* ---------- בקרה מהבדיקות ---------- */
if ($uri === '/_mock/authorize') {         // "המשתמש אישר" — מחזיר code לקריאה ל-callback
    $code = 'code_' . bin2hex(random_bytes(6));
    $S['codes'][$code] = ['challenge' => (string)($_GET['code_challenge'] ?? ''), 'redirect_uri' => (string)($_GET['redirect_uri'] ?? '')];
    out(200, ['code' => $code]);
}
if ($uri === '/_mock/state') out(200, $S);
if ($uri === '/_mock/do') {
    $op = json_decode($raw, true);
    switch ($op['op']) {
        case 'add_file': out(200, ['id' => addFile($op['path'], (int)($op['size'] ?? 100), false)]);
        case 'add_folder': mkdirs($op['path']); out(200, ['id' => byPath($op['path'])]);
        case 'delete':
            $base = low($op['path']);
            foreach ($S['entries'] as $id => $e) {
                if ($e['path_lower'] === $base || str_starts_with($e['path_lower'], $base . '/')) unset($S['entries'][$id]);
            }
            logEntry(['.tag' => 'deleted', 'name' => basename($op['path']), 'path_lower' => $base, 'path_display' => $op['path']]);
            out(200, ['ok' => true]);
        case 'move':
            $from = low($op['from']);
            logEntry(['.tag' => 'deleted', 'name' => basename($op['from']), 'path_lower' => $from, 'path_display' => $op['from']]);
            foreach ($S['entries'] as $id => $e) {
                if ($e['path_lower'] === $from || str_starts_with($e['path_lower'], $from . '/')) {
                    $S['entries'][$id]['path_display'] = $op['to'] . substr($e['path_display'], strlen($op['from']));
                    $S['entries'][$id]['path_lower'] = low($S['entries'][$id]['path_display']);
                    logEntry(meta($id));
                }
            }
            out(200, ['ok' => true]);
        case 'set': foreach ($op['values'] as $k => $v) $S[$k] = $v; out(200, ['ok' => true]);
        case 'clear_calls': $S['calls'] = []; out(200, ['ok' => true]);
        case 'expire_access': $S['access'] = []; out(200, ['ok' => true]);
    }
    out(400, ['error' => 'op']);
}

/* ---------- OAuth ---------- */
if ($uri === '/oauth2/token') {
    $S['calls'][] = 'oauth2/token';
    parse_str($raw, $f);
    if (($f['client_id'] ?? '') !== $S['app_key'] || ($f['client_secret'] ?? '') !== $S['app_secret']) out(400, ['error' => 'invalid_client']);
    if (($f['grant_type'] ?? '') === 'authorization_code') {
        $c = $S['codes'][$f['code'] ?? ''] ?? null;
        unset($S['codes'][$f['code'] ?? '']);
        if (!$c || b64u(hash('sha256', (string)($f['code_verifier'] ?? ''), true)) !== $c['challenge'] || $c['redirect_uri'] !== ($f['redirect_uri'] ?? '')) {
            out(400, ['error' => 'invalid_grant']);
        }
        $rt = 'rt_' . bin2hex(random_bytes(12)); $at = 'at_' . bin2hex(random_bytes(12));
        $S['refresh'][$rt] = $S['account']['id']; $S['access'][$at] = $S['account']['id'];
        out(200, ['access_token' => $at, 'refresh_token' => $rt, 'token_type' => 'bearer', 'expires_in' => 14400, 'account_id' => $S['account']['id'], 'uid' => '1']);
    }
    if (($f['grant_type'] ?? '') === 'refresh_token') {
        if ($S['fail_refresh'] || !isset($S['refresh'][$f['refresh_token'] ?? ''])) out(400, ['error' => 'invalid_grant', 'error_description' => 'refresh token is invalid or revoked']);
        $at = 'at_' . bin2hex(random_bytes(12));
        $S['access'][$at] = $S['refresh'][$f['refresh_token']];
        out(200, ['access_token' => $at, 'token_type' => 'bearer', 'expires_in' => 14400]);
    }
    out(400, ['error' => 'unsupported_grant_type']);
}

/* ---------- API ---------- */
if (!str_starts_with($uri, '/2/')) out(404, 'not found');
$ep = substr($uri, 3);
$S['calls'][] = $ep;
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!str_starts_with($auth, 'Bearer ') || !isset($S['access'][substr($auth, 7)])) out(401, ['error_summary' => 'expired_access_token/', 'error' => ['.tag' => 'expired_access_token']]);
$arg = isset($_SERVER['HTTP_DROPBOX_API_ARG']) ? json_decode($_SERVER['HTTP_DROPBOX_API_ARG'], true) : json_decode($raw, true);

switch ($ep) {
    case 'users/get_current_account':
        out(200, ['account_id' => $S['account']['id'], 'name' => ['display_name' => $S['account']['name']], 'email' => 'mock@example.com']);
    case 'auth/token/revoke':
        $S['revoked']++;
        unset($S['access'][substr($auth, 7)]);
        $S['refresh'] = [];
        out(200, 'null', ['Content-Type: application/json']);
    case 'files/get_metadata':
        $id = resolve((string)$arg['path']);
        if ($id === null) err409('path/not_found/');
        out(200, meta($id));
    case 'files/list_folder':
        $p = (string)$arg['path'];
        $base = '';
        if ($p !== '') { $id = resolve($p); if ($id === null) err409('path/not_found/'); $base = $S['entries'][$id]['path_lower']; }
        $all = [];
        foreach ($S['entries'] as $id => $e) if (under($e['path_lower'], $base, (bool)$arg['recursive'])) $all[] = meta($id);
        usort($all, static fn($a, $b) => strcmp($a['path_lower'], $b['path_lower']));
        $page = array_slice($all, 0, (int)$S['page_size']);
        $more = count($all) > count($page);
        $cur = b64u(json_encode(['p' => $base, 'r' => (bool)$arg['recursive'], 's' => $S['seq'], 'off' => $more ? count($page) : null]));
        out(200, ['entries' => $page, 'cursor' => $cur, 'has_more' => $more]);
    case 'files/list_folder/continue':
        if ($S['reset_next']) { $S['reset_next'] = false; err409('reset/'); }
        $c = json_decode((string)base64_decode(strtr((string)$arg['cursor'], '-_', '+/')), true);
        if (!is_array($c)) err409('reset/');
        if ($c['off'] !== null) {        // המשך הרשימה הראשונית
            $all = [];
            foreach ($S['entries'] as $id => $e) if (under($e['path_lower'], $c['p'], $c['r'])) $all[] = meta($id);
            usort($all, static fn($a, $b) => strcmp($a['path_lower'], $b['path_lower']));
            $page = array_slice($all, $c['off'], (int)$S['page_size']);
            $next = $c['off'] + count($page);
            $more = $next < count($all);
            out(200, ['entries' => $page, 'cursor' => b64u(json_encode(['p' => $c['p'], 'r' => $c['r'], 's' => $c['s'], 'off' => $more ? $next : null])), 'has_more' => $more]);
        }
        $entries = [];
        foreach ($S['log'] as $l) if ($l['s'] > $c['s'] && under($l['e']['path_lower'], $c['p'], $c['r'])) $entries[] = $l['e'];
        out(200, ['entries' => $entries, 'cursor' => b64u(json_encode(['p' => $c['p'], 'r' => $c['r'], 's' => $S['seq'], 'off' => null])), 'has_more' => false]);
    case 'files/create_folder_v2':
        if (byPath((string)$arg['path']) !== null) err409('path/conflict/folder/');
        mkdirs((string)$arg['path']);
        out(200, ['metadata' => meta(byPath((string)$arg['path']))]);
    case 'files/upload':
        if (strlen($raw) > 150 * 1024 * 1024) err409('payload_too_large/');
        out(200, meta(addFile((string)$arg['path'], strlen($raw), (bool)($arg['autorename'] ?? false))));
    case 'files/upload_session/start':
        $sid = 'sess_' . bin2hex(random_bytes(6));
        $S['sessions'][$sid] = ['offset' => strlen($raw), 'chunks' => [strlen($raw)]];
        out(200, ['session_id' => $sid]);
    case 'files/upload_session/append_v2':
        $s = &$S['sessions'][$arg['cursor']['session_id']];
        if (!$s || $s['offset'] !== $arg['cursor']['offset']) err409('incorrect_offset/');
        $s['offset'] += strlen($raw); $s['chunks'][] = strlen($raw);
        out(200, 'null', ['Content-Type: application/json']);
    case 'files/upload_session/finish':
        $s = &$S['sessions'][$arg['cursor']['session_id']];
        if (!$s || $s['offset'] !== $arg['cursor']['offset']) err409('lookup_failed/incorrect_offset/');
        $s['offset'] += strlen($raw); $s['chunks'][] = strlen($raw);
        $s['done'] = true;
        out(200, meta(addFile((string)$arg['commit']['path'], $s['offset'], (bool)($arg['commit']['autorename'] ?? false))));
    case 'files/get_thumbnail_v2':
        $id = resolve((string)$arg['resource']['path']);
        if ($id === null) err409('path/not_found/');
        out(200, "\xFF\xD8\xFF\xE0MOCKJPEG" . $arg['size'], ['Content-Type: application/octet-stream', 'Dropbox-API-Result: ' . json_encode(['file_metadata' => meta($id)])]);
    case 'files/download':
    case 'files/get_preview':
        $id = resolve((string)$arg['path']);
        if ($id === null) err409('path/not_found/');
        out(200, "%PDF-1.4\n% mock " . $ep . "\n", ['Content-Type: application/octet-stream', 'Dropbox-API-Result: ' . json_encode(meta($id))]);
}
out(400, ['error_summary' => 'unknown_endpoint/']);
