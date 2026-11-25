<?php
declare(strict_types=1);

/**
 * מטא-נתונים של OAuth — פרוסה 09 §3.2. ציבורי. ב-.htaccess:
 *   .well-known/oauth-protected-resource   → ?doc=resource  (RFC 9728)
 *   .well-known/oauth-authorization-server → ?doc=server    (RFC 8414)
 *   .well-known/openid-configuration       → ?doc=server    (אותו מסמך — לקוחות MCP מנסים גם אותו)
 * בלי מסד ובלי זהות: מסמך קבוע מהקוד.
 */
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
require __DIR__ . '/../mcp-lib.php';

oauthCors('GET');
$doc = (string)($_GET['doc'] ?? '');
if ($doc === 'resource') oauthJson(200, oauthProtectedResource(), ['Cache-Control: public, max-age=3600']);
if ($doc === 'server') oauthJson(200, oauthServerMetadata(), ['Cache-Control: public, max-age=3600']);
oauthJson(404, ['error' => 'not_found']);
