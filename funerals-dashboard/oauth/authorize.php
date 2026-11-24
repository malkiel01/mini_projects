<?php
declare(strict_types=1);

/**
 * מסך ההסכמה של OAuth — פרוסה 09 §3.3. **מאחורי השער** (לא ב-public): מי שלא מחובר לפלטפורמה עובר בכניסה
 * הרגילה וחוזר לכאן עם כל הפרמטרים. הזהות — PLATFORM_USER → המשתמש באפליקציה (resolveAccess של 01): המשתמשים
 * של האפליקציה, לא של הפלטפורמה. בלי חברות פעילה בארגון — אין קוד.
 *
 * כל הבדיקות של הבקשה (לקוח, redirect_uri מדויק, S256, state, resource) — לפני המסך ושוב באישור. כשל → דף שגיאה,
 * בלי הפניה לכתובת שלא אומתה. אישור → קוד חד-פעמי (60 שניות) ואירוע oauth_grant. ביטול → error=access_denied.
 */
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
require __DIR__ . '/../mcp-lib.php';

$t0 = microtime(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
register_shutdown_function(static function () use ($t0, $method): void {
    logRequest($method, 'oauth:authorize', (int)(http_response_code() ?: 200), (int)round((microtime(true) - $t0) * 1000),
        $GLOBALS['fd_ctx']['user']['id'] ?? null, (string)($GLOBALS['fd_err'] ?? ''));
});
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'; form-action 'self' https: http://localhost:* http://127.0.0.1:*");
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function page(int $status, string $title, string $inner): never {
    http_response_code($status);
    if ($status >= 400) $GLOBALS['fd_err'] = $title;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="theme-color" content="#2d4a8a"><meta name="referrer" content="no-referrer">'
        . '<title>' . h($title) . ' — ניהול לוויות חברה קדישא</title>'
        . '<link rel="stylesheet" href="../assets/css/app.css"><link rel="stylesheet" href="../assets/css/oauth.css"></head><body>'
        . '<header class="top"><div class="titles"><h1>🔐 ' . h($title) . '</h1><div class="sub">ניהול לוויות חברה קדישא</div></div></header>'
        . '<main id="main" class="oauth">' . $inner . '</main></body></html>';
    exit;
}
function fail(string $why): never {
    page(400, 'לא ניתן להתחבר', '<p class="error" role="alert">' . h($why) . '</p>'
        . '<p class="hint">חוזרים לצ\'אט ומתחילים את החיבור מחדש. הבקשה לא הועברה הלאה.</p>');
}
function back(string $uri, array $q): never {
    http_response_code(302);
    header('Location: ' . $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
    exit;
}

$pu = platformUser();
if ($pu === null) page(401, 'נדרשת כניסה', '<p class="error" role="alert">יש להיכנס דרך הפלטפורמה ואז לחזור לצ\'אט.</p>');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_oauth');   // עוגייה נפרדת מ-fd_session — כדי לא לדרוס את ה-session של האפליקציה בכניסה מאתר אחר
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (($_SESSION['fd_sub'] ?? null) !== $pu['sub']) {
    session_regenerate_id(true);
    $_SESSION = ['fd_sub' => $pu['sub'], 'fd_csrf' => bin2hex(random_bytes(24))];
}

$pdo = db();
oauthEnsureSchema($pdo);
$in = $method === 'POST' ? $_POST : $_GET;
$s = static fn(string $k, int $max = 500): string => oauthStr($in, $k, $max);

// 1. הלקוח וכתובת החזרה — עד שאלה אומתו, אין שום הפניה
$clientId = $s('client_id');
$redirect = $s('redirect_uri');
if ($clientId === '' || $redirect === '') fail('חסרים client_id או redirect_uri.');
$client = oauthClient($pdo, $clientId);
if ($client === null) fail('הלקוח לא מוכר (או שמסמך הלקוח לא נמצא / לא תקין).');
if (!in_array($redirect, $client['redirect_uris'], true)) fail('כתובת החזרה אינה רשומה ללקוח הזה.');
// 2. שאר הבקשה
if ($s('response_type', 20) !== 'code') fail('response_type חייב להיות code.');
$challenge = $s('code_challenge', 200);
if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) || $s('code_challenge_method', 10) !== 'S256') fail('נדרש PKCE עם S256 (code_challenge + code_challenge_method=S256).');
$state = $s('state', 500);
if ($state === '') fail('חסר state.');
if (!oauthResourceOk($s('resource'))) fail('resource אינו כתובת שרת ה-MCP של האפליקציה.');

// 3. מי המשתמש — באפליקציה, ובאילו ארגונים הוא חבר פעיל
$c = resolveAccess($pu);
$orgs = [];
foreach ($c['orgs'] as $o) {
    $m = extMember($pdo, (int)$c['user']['id'], (int)$o['id']);
    if ($m !== null) $orgs[(int)$o['id']] = $m;
}
if (!$orgs) page(403, 'אין לך ארגון באפליקציה', '<p class="error" role="alert">החשבון שלך באפליקציה אינו חבר פעיל באף ארגון, ולכן אי אפשר לחבר אותו לצ\'אט.</p>'
    . '<p class="hint">מבקשים ממנהל הארגון להזמין אותך, ואז מתחברים מחדש.</p>');

$host = (string)parse_url($redirect, PHP_URL_HOST);
$iss = MCP_BASE;

if ($method === 'POST') {
    if (!hash_equals((string)$_SESSION['fd_csrf'], $s('csrf', 100))) fail('אימות הבקשה נכשל. לטעון את הדף מחדש.');
    if ($s('decision', 10) !== 'approve') back($redirect, ['error' => 'access_denied', 'state' => $state, 'iss' => $iss]);
    $orgId = (int)$s('org', 12);
    if (!isset($orgs[$orgId])) fail('ארגון לא תקין.');
    $m = $orgs[$orgId];
    $code = oauthIssueCode($pdo, $client, $redirect, (int)$m['user_id'], $orgId, $challenge);
    recordEvent($orgId, null, $c['actor'], 'ui', 'oauth_grant', ['client' => [null, $client['client_name']], 'host' => [null, $host]]);
    back($redirect, ['code' => $code, 'state' => $state, 'iss' => $iss]);
}

// המסך
$hidden = '';
foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope'] as $k) {
    $hidden .= '<input type="hidden" name="' . $k . '" value="' . h($s($k)) . '">';
}
$hidden .= '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['fd_csrf']) . '">';
$first = array_key_first($orgs);
$orgHtml = '';
if (count($orgs) > 1) {
    $orgHtml = '<fieldset class="orgs"><legend>לאיזה ארגון?</legend>';
    foreach ($orgs as $id => $m) {
        $orgHtml .= '<label class="org"><input type="radio" name="org" value="' . $id . '"' . ($id === $first ? ' checked' : '') . '><span>'
            . h((string)$m['org_name']) . ' <small>(' . h(ROLES[$m['role']]) . ')</small></span></label>';
    }
    $orgHtml .= '</fieldset>';
    $orgName = 'הארגון שתבחר';
} else {
    $orgHtml = '<input type="hidden" name="org" value="' . $first . '">';
    $orgName = (string)$orgs[$first]['org_name'];
}
page(200, 'אישור חיבור', '<section class="consent">'
    . '<p class="ask"><b>' . h($client['client_name']) . '</b> (<span dir="ltr">' . h($host) . '</span>) מבקש גישה לתיקים של <b>' . h($orgName) . '</b> בשמך:</p>'
    . '<ul class="can"><li>לקרוא תיקים וקבצים</li><li>לעדכן — עדכון נשמר "לאימות"</li><li>אין מחיקה</li></ul>'
    . '<p class="hint">ההרשאות הן התפקיד שלך בארגון, ברגע כל בקשה. מנתקים בכל רגע במסך 🤖 חיבור לצ\'אט.</p>'
    . '<form method="post" action="authorize.php" class="actions">' . $hidden . $orgHtml
    . '<button class="btn primary wide" name="decision" value="approve">אישור</button>'
    . '<button class="btn wide" name="decision" value="deny">ביטול</button></form></section>');
