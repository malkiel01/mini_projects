<?php
declare(strict_types=1);

/**
 * שרת MCP מרוחק — פרוסה 09 (docs/spec/09-mcp.md §3.1). Streamable HTTP בלי SSE ובלי session:
 * POST עם JSON-RPC 2.0 (בקשה אחת או מערך) → application/json. GET → 405. notifications → 202 בלי גוף.
 * הכתובת: <base>/mcp (rewrite ב-.htaccess) או <base>/mcp.php. רשום ב-"public" של app.json — ChatGPT ניגש בלי השער.
 *
 * initialize / ping / tools/list — בלי אסימון. tools/call — Bearer fdo_ (OAuth) או fdx_ (05); בלי → 401 עם
 * WWW-Authenticate שמפנה למטא-נתונים (RFC 9728). הכלים — extActions() / extDo() של 05, בלי שכבה שנייה.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/ext-lib.php';
require __DIR__ . '/mcp-lib.php';

$t0 = microtime(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$GLOBALS['mcp_action'] = 'mcp:' . strtolower($method);
// יומן הבקשות של 01: mcp:<method>:<tool>, סטטוס, זמן, משתמש — אף פעם לא האסימון. 401 לא נרשם (כמו 05).
register_shutdown_function(static function () use ($method, $t0): void {
    if (!empty($GLOBALS['mcp_nolog'])) return;
    logRequest($method, (string)$GLOBALS['mcp_action'], (int)(http_response_code() ?: 200),
        (int)round((microtime(true) - $t0) * 1000), $GLOBALS['fd_ctx']['user']['id'] ?? null, (string)($GLOBALS['fd_err'] ?? ''));
});

function mcpOut(int $status, mixed $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $h) header($h);
    if ($body === null) exit;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function mcpErr(mixed $id, int $code, string $msg): array { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]]; }

// Origin (הגנה מ-DNS rebinding): אם נשלחה — רק הלקוחות המוכרים והדומיין שלנו
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && !in_array(rtrim($origin, '/'), MCP_ORIGINS, true)) {
    $GLOBALS['fd_err'] = 'origin';
    mcpOut(403, mcpErr(null, -32600, 'Origin לא מורשה'));
}
if ($method !== 'POST') mcpOut(405, mcpErr(null, -32600, 'רק POST (Streamable HTTP, בלי SSE)'), ['Allow: POST']);

$raw = file_get_contents('php://input', false, null, 0, 1200001);
if (strlen((string)$raw) > 1200000) mcpOut(413, mcpErr(null, -32600, 'הבקשה גדולה מדי'));
$msg = json_decode((string)$raw, true);
if (!is_array($msg)) mcpOut(400, mcpErr(null, -32700, 'Parse error'));
$batch = array_is_list($msg) && $msg !== [];
$list = $batch ? $msg : [$msg];
if ($msg === []) mcpOut(400, mcpErr(null, -32600, 'Invalid Request'));
if (count($list) > MCP_BATCH_MAX) mcpOut(400, mcpErr(null, -32600, 'עד ' . MCP_BATCH_MAX . ' בקשות ב-batch'));

$pdo = db();   // המיגרציות של 01
oauthEnsureSchema($pdo);

// tools/call בבקשה — חייב אסימון תקין, אחרת 401 לכל הבקשה (ChatGPT מתחיל מזה את ההתחברות)
$needsAuth = false;
foreach ($list as $r) if (is_array($r) && ($r['method'] ?? null) === 'tools/call' && array_key_exists('id', $r)) $needsAuth = true;
$auth = null;
if ($needsAuth) {
    $minute = intdiv(time(), 60);
    $ip = oauthIpKey('fdx-ip');   // אותה ספירת כשלים כמו 05
    $f = oauthRow($pdo, 'SELECT n FROM ext_fail WHERE ip=? AND minute=?', [$ip, $minute]);
    if ((int)($f['n'] ?? 0) >= EXT_FAILS_PER_MINUTE) {
        $GLOBALS['mcp_nolog'] = true;
        mcpOut(429, mcpErr(null, -32000, 'יותר מדי ניסיונות עם אסימון שגוי. לחכות דקה.'), ['Retry-After: ' . (60 - time() % 60)]);
    }
    $auth = mcpAuthenticate($pdo);
    if ($auth === null) {
        $sent = mcpBearer() !== '' || isset($_SERVER['HTTP_AUTHORIZATION']);
        if ($sent) {
            $pdo->prepare('INSERT INTO ext_fail(ip, minute, n) VALUES(?,?,1) ON CONFLICT(ip) DO UPDATE SET n = CASE WHEN minute=excluded.minute THEN n+1 ELSE 1 END, minute=excluded.minute')
                ->execute([$ip, $minute]);
        }
        $GLOBALS['mcp_nolog'] = true;
        $hdr = 'WWW-Authenticate: Bearer resource_metadata="' . MCP_PRM_URL . '", scope="' . MCP_SCOPE . '"' . ($sent ? ', error="invalid_token"' : '');
        mcpOut(401, mcpErr(null, -32001, 'נדרשת התחברות (OAuth). אסימון שבוטל או שפג — להתחבר מחדש.'), [$hdr]);
    }
}

$out = [];
foreach ($list as $r) {
    if (!is_array($r) || ($r['jsonrpc'] ?? null) !== '2.0' || !is_string($r['method'] ?? null)) { $out[] = mcpErr(null, -32600, 'Invalid Request'); continue; }
    $isNote = !array_key_exists('id', $r);
    $id = $r['id'] ?? null;
    $m = $r['method'];
    $params = is_array($r['params'] ?? null) ? $r['params'] : [];
    $GLOBALS['mcp_action'] = 'mcp:' . substr($m, 0, 20);
    if ($isNote) continue;   // notifications/* (ותשובות של לקוח) — אין מה להחזיר
    switch ($m) {
        case 'initialize':
            $want = (string)($params['protocolVersion'] ?? '');
            $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => in_array($want, MCP_PROTOCOLS, true) ? $want : MCP_PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'funerals-dashboard', 'title' => 'ניהול לוויות חברה קדישא', 'version' => VERSION],
                'instructions' => MCP_INSTRUCTIONS]];
            break;
        case 'ping':
            $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
            break;
        case 'tools/list':
            $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => mcpTools()]];
            break;
        case 'tools/call':
            $name = $params['name'] ?? null;
            $args = $params['arguments'] ?? [];
            $known = array_column(mcpTools(), 'name');
            if (!is_string($name) || !in_array($name, $known, true)) { $out[] = mcpErr($id, -32602, 'כלי לא מוכר. הכלים: ' . implode(', ', $known)); break; }
            if (!is_array($args) || ($args !== [] && array_is_list($args))) { $out[] = mcpErr($id, -32602, 'arguments — אובייקט'); break; }
            $GLOBALS['mcp_action'] = 'mcp:tools/call:' . $name;
            if (!mcpRate($pdo, $auth['rl'])) {
                $res = mcpToolResult(['error' => 'יותר מדי בקשות, נסה בעוד דקה.', 'status' => 429], true);
            } else {
                $res = mcpCall($pdo, $name, $args);
            }
            if ($res['isError']) $GLOBALS['fd_err'] = (string)($res['structuredContent']['error'] ?? '');
            $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $res];
            break;
        default:
            $out[] = mcpErr($id, -32601, 'Method not found');
    }
}
if ($batch && count($list) > 1) $GLOBALS['mcp_action'] = 'mcp:batch';
if (!$out) mcpOut(202, null);
mcpOut(200, $batch ? $out : $out[0]);
