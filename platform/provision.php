<?php
/**
 * הזמנת משתמשים מאפליקציה — שרת-לשרת, חתום (lib/provision.php; המדריך: docs/sso.md, סעיף 6).
 *
 *   POST provision.php   {email, name, grant: true|false, next?}
 *     X-Embed-Client / X-Embed-Time / X-Embed-Nonce / X-Embed-Signature (docs/embed.md, סעיף 2; action = "provision")
 *   → {status: "invited"|"granted"|"revoked"|"blocked"|"unknown", sub?}
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/provision.php';

header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('שיטה לא נתמכת', 405);
    $raw = (string)file_get_contents('php://input');
    $client = provision_verify('provision', $raw, [
        'client' => $_SERVER['HTTP_X_EMBED_CLIENT'] ?? '', 'time' => $_SERVER['HTTP_X_EMBED_TIME'] ?? '',
        'nonce' => $_SERVER['HTTP_X_EMBED_NONCE'] ?? '', 'signature' => $_SERVER['HTTP_X_EMBED_SIGNATURE'] ?? '',
    ]);
    $body = json_decode($raw, true);
    if (!is_array($body)) throw new ApiError('בקשה לא תקינה', 400);
    json_out(provision_user($client, $body));
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('platform provision: ' . $e);
    json_out(['error' => 'שגיאה בשרת'], 500);
}
