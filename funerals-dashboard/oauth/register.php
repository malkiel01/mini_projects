<?php
declare(strict_types=1);

/** רישום לקוח דינמי (DCR, RFC 7591) — פרוסה 09 §3.2. ציבורי. לקוח ציבורי בלבד, עד 20 רישומים לשעה לכתובת. */
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
require __DIR__ . '/../mcp-lib.php';

$t0 = microtime(true);
register_shutdown_function(static function () use ($t0): void {
    logRequest('POST', 'oauth:register', (int)(http_response_code() ?: 200), (int)round((microtime(true) - $t0) * 1000), null, (string)($GLOBALS['fd_err'] ?? ''));
});
oauthCors('POST');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') oauthError(405, 'invalid_request', 'רק POST');
$p = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($p)) oauthError(400, 'invalid_client_metadata', 'נדרש JSON');
$pdo = db();
oauthEnsureSchema($pdo);
oauthJson(201, oauthRegister($pdo, $p));
