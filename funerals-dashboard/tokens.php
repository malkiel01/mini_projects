<?php
declare(strict_types=1);

/**
 * מסך "חיבור ל-ChatGPT" (connect.html) — ניהול אסימונים אישיים מהדפדפן. מאחורי השער (לא ב-public):
 * הזהות מ-PLATFORM_USER, ה-session וה-CSRF — אותם של api.php (fd_session / fd_csrf).
 * פעולות: me (רשימה, הנחיות, כתובת ה-OpenAPI), create, revoke. האסימון מוחזר פעם אחת בלבד, ב-create.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/ext-lib.php';

$user = platformUser();
if ($user === null) reply(401, ['error' => 'אין הרשאה לאפליקציה. יש להיכנס דרך הפלטפורמה.']);
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_session');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (($_SESSION['fd_sub'] ?? null) !== $user['sub']) {
    session_regenerate_id(true);
    $_SESSION = ['fd_sub' => $user['sub'], 'fd_csrf' => bin2hex(random_bytes(24))];
}
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && !hash_equals((string)$_SESSION['fd_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    reply(403, ['error' => 'אימות בקשה נכשל']);
}

$pdo = db();
extRequireInfra($pdo);
$u = extUserBySub($pdo, $user['sub']);
if ($u === null) reply(403, ['error' => 'עדיין לא שויכת לחברה קדישא. בקש ממנהל הארגון להזמין אותך.']);
$orgs = extMemberships($pdo, (int)$u['id']);
if (!$orgs) reply(403, ['error' => 'עדיין לא שויכת לחברה קדישא. בקש ממנהל הארגון להזמין אותך.']);
// ADAPTER (01): הארגון הפעיל. עד 01 — מהבקשה (נבדק מול members), אחרת הראשון.
$want = (int)($_GET['org'] ?? ($_SESSION['fd_org'] ?? $orgs[0]['org_id']));
$m = extMember($pdo, (int)$u['id'], $want) ?? extMember($pdo, (int)$u['id'], (int)$orgs[0]['org_id']);
if ($m === null) reply(403, ['error' => 'אין חברות פעילה בארגון']);
$_SESSION['fd_org'] = (int)$m['org_id'];

if ($action === 'me') {
    $cfg = config();
    $ingest = null;
    if (extCan($m, 'manage_tokens')) {
        $last = $pdo->query("SELECT MAX(at) FROM audit WHERE actor='api'")->fetchColumn();
        $ingest = ['exists' => ($cfg['api_token_sha256'] ?? '') !== '', 'last_used_at' => $last ?: null];
    }
    reply(200, ['ok' => true, 'csrf' => $_SESSION['fd_csrf'],
        'user' => ['name' => $m['name'], 'email' => $m['email']], 'role' => $m['role'], 'role_name' => EXT_ROLE_NAMES[$m['role']],
        'org' => ['id' => (int)$m['org_id'], 'name' => $m['org_name']],
        'orgs' => array_map(static fn($o) => ['id' => (int)$o['org_id'], 'name' => $o['org_name']], $orgs),
        'can_manage' => extCan($m, 'manage_tokens'), 'tokens' => extListTokens($pdo, $m),
        'ttl_days' => EXT_TTL_DAYS, 'max_active' => EXT_MAX_ACTIVE_TOKENS,
        'prompt' => extPrompt(), 'openapi_url' => EXT_PUBLIC_BASE . '/openapi.json', 'ingest' => $ingest]);
}
if ($action === 'create') {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    $p = body();
    $t = extCreateToken($pdo, $m, (string)($p['name'] ?? ''), (int)($p['days'] ?? 90));
    reply(200, ['ok' => true] + $t);
}
if ($action === 'revoke') {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    $p = body();
    extRevokeToken($pdo, $m, (int)($p['id'] ?? 0));
    reply(200, ['ok' => true]);
}
reply(404, ['error' => 'פעולה לא מוכרת']);
