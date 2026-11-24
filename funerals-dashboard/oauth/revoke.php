<?php
declare(strict_types=1);

/** ביטול אסימון (RFC 7009) — פרוסה 09 §3.2. ציבורי. תמיד 200. אסימון רענון → כל החיבור (grant) מבוטל. */
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
require __DIR__ . '/../mcp-lib.php';

$t0 = microtime(true);
register_shutdown_function(static function () use ($t0): void {
    logRequest('POST', 'oauth:revoke', (int)(http_response_code() ?: 200), (int)round((microtime(true) - $t0) * 1000), null, (string)($GLOBALS['fd_err'] ?? ''));
});
oauthCors('POST');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') oauthError(405, 'invalid_request', 'רק POST');
$pdo = db();
oauthEnsureSchema($pdo);
oauthRevoke($pdo, oauthParams());
oauthJson(200, ['ok' => true]);
