<?php
declare(strict_types=1);

/**
 * נקודת האסימונים — פרוסה 09 §3.2, §3.4. ציבורי. authorization_code (עם code_verifier, PKCE S256) ו-refresh_token.
 * לקוח ציבורי — בלי סוד. שגיאה — RFC 6749 §5.2, בלי שום אסימון או קוד בגוף.
 */
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
require __DIR__ . '/../mcp-lib.php';

$t0 = microtime(true);
$grant = '';
register_shutdown_function(static function () use ($t0, &$grant): void {
    logRequest('POST', 'oauth:token:' . substr($grant, 0, 20), (int)(http_response_code() ?: 200), (int)round((microtime(true) - $t0) * 1000), null, (string)($GLOBALS['fd_err'] ?? ''));
});
oauthCors('POST');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') oauthError(405, 'invalid_request', 'רק POST');
$p = oauthParams();
$grant = oauthStr($p, 'grant_type', 40);
$pdo = db();
oauthEnsureSchema($pdo);
if ($grant === 'authorization_code') oauthJson(200, oauthTokenFromCode($pdo, $p), ['Pragma: no-cache']);
if ($grant === 'refresh_token') oauthJson(200, oauthTokenFromRefresh($pdo, $p), ['Pragma: no-cache']);
oauthError(400, 'unsupported_grant_type', 'grant_type: authorization_code או refresh_token');
