<?php
declare(strict_types=1);

/**
 * מסך "חיבור ל-ChatGPT" (connect.html) — ניהול אסימונים אישיים מהדפדפן. מאחורי השער (לא ב-public):
 * הזהות, הארגון הפעיל וההרשאות — resolveAccess() של 01; ה-session וה-CSRF — אותם של api.php (fd_session / fd_csrf).
 * פעולות: me (רשימה, הנחיות, כתובת ה-OpenAPI, כתובת ה-MCP והחיבורים הפעילים), create, revoke, disconnect (09: ניתוק
 * חיבור OAuth — שלי, ולמנהל כל חיבור בארגון). האסימון מוחזר פעם אחת בלבד, ב-create.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/ext-lib.php';
require __DIR__ . '/mcp-lib.php';   // 09: החיבורים הפעילים (OAuth) וניתוק

$t0 = microtime(true);
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
register_shutdown_function(function () use ($t0, $action, $method) {
    logRequest($method, 'tokens:' . $action, http_response_code() ?: 200, (int)round((microtime(true) - $t0) * 1000),
        $GLOBALS['fd_ctx']['user']['id'] ?? null, (string)($GLOBALS['fd_err'] ?? ''));
});
$pu = platformUser();
if ($pu === null) reply(401, ['error' => 'אין הרשאה לאפליקציה. יש להיכנס דרך הפלטפורמה.']);
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_session');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (($_SESSION['fd_sub'] ?? null) !== $pu['sub']) {
    session_regenerate_id(true);
    $_SESSION = ['fd_sub' => $pu['sub'], 'fd_csrf' => bin2hex(random_bytes(24))];
}
if ($method !== 'GET' && !hash_equals((string)$_SESSION['fd_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    reply(403, ['error' => 'אימות בקשה נכשל']);
}

$pdo = db();
oauthEnsureSchema($pdo);   // כולל extEnsureSchema של 05
$ctx = resolveAccess($pu);   // 01: המשתמש, הארגון הפעיל (מה-session, נבדק מול members) והתפקיד
need('case.view');
$orgId = scope();
// האסימון עובד בהרשאות התפקיד בארגון — מפתח בלי חברות בארגון לא יכול ליצור אסימון (אין לו תפקיד לתת לו)
$m = extMember($pdo, (int)$ctx['user']['id'], $orgId);

if ($action === 'me') {
    $cfg = config();
    $ingest = null;
    if (can('token.manage')) {
        $last = $pdo->query("SELECT MAX(at) FROM req_log WHERE action LIKE 'ingest:%' AND status < 400")->fetchColumn()
            ?: $pdo->query("SELECT MAX(at) FROM events WHERE source='import' AND actor='api'")->fetchColumn();
        $ingest = ['exists' => ($cfg['api_token_sha256'] ?? '') !== '', 'last_used_at' => $last ?: null];
    }
    reply(200, ['ok' => true, 'csrf' => $_SESSION['fd_csrf'],
        'user' => $ctx['user'], 'role' => $m['role'] ?? null, 'role_name' => $m ? ROLES[$m['role']] : 'מפתח (בלי תפקיד בארגון)',
        'org' => ['id' => $orgId, 'name' => $ctx['org']['name']], 'can_create' => $m !== null,
        'can_manage' => extCan('manage_tokens'), 'tokens' => extListTokens($pdo, $orgId, (int)$ctx['user']['id']),
        'ttl_days' => EXT_TTL_DAYS, 'max_active' => EXT_MAX_ACTIVE_TOKENS,
        'prompt' => extPrompt(), 'project_prompt' => extProjectPrompt(), 'openapi_url' => EXT_PUBLIC_BASE . '/openapi.json', 'ingest' => $ingest,
        'mcp_url' => MCP_URL, 'connections' => oauthListGrants($pdo, $orgId, (int)$ctx['user']['id'], extCan('manage_tokens'))]);
}
if ($action === 'create') {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    if ($m === null) reply(403, ['error' => 'אסימון עובד בהרשאות התפקיד שלך בארגון, ואין לך תפקיד בארגון הזה.']);
    $p = body();
    $t = extCreateToken($pdo, $m, (string)($p['name'] ?? ''), (int)($p['days'] ?? 90));
    reply(200, ['ok' => true] + $t);
}
if ($action === 'revoke') {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    $p = body();
    extRevokeToken($pdo, $orgId, (int)$ctx['user']['id'], (int)($p['id'] ?? 0));
    reply(200, ['ok' => true]);
}
if ($action === 'disconnect') {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    $p = body();
    oauthDisconnect($pdo, $orgId, (int)$ctx['user']['id'], extCan('manage_tokens'), (int)($p['id'] ?? 0));
    reply(200, ['ok' => true]);
}
reply(404, ['error' => 'פעולה לא מוכרת']);
