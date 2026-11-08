<?php
/**
 * הזמנת משתמשים מאפליקציה — שרת-לשרת, חתום (lib/provision.php; המדריך: docs/sso.md, סעיף 6).
 *
 *   POST provision.php   {email, name, grant: true|false, next?}
 *     X-Embed-Client / X-Embed-Time / X-Embed-Nonce / X-Embed-Signature (docs/embed.md, סעיף 2; action = "provision")
 *   → {status: "invited"|"granted"|"revoked"|"blocked"|"unknown", sub?}
 *
 *   POST provision.php?action=grant_status   {sub: "123"}   (action = "grant_status" בהודעה החתומה — PF-002)
 *   → {allowed: true|false} — האם למשתמש יש הרשאה לאפליקציה החותמת (lib/provision.php, provision_grant_status)
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/provision.php';

header('Cache-Control: no-store');
$action = 'provision';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('שיטה לא נתמכת', 405);
    // בלי ?action — provision, כמו קודם. ה-action נכנס להודעה החתומה: חתימה של פעולה אחת לא תקפה לשנייה.
    $action = $_GET['action'] ?? 'provision';
    if (!in_array($action, ['provision', 'grant_status'], true)) throw new ApiError('פעולה לא מוכרת', 400);
    $raw = (string)file_get_contents('php://input');
    $client = provision_verify($action, $raw, [
        'client' => $_SERVER['HTTP_X_EMBED_CLIENT'] ?? '', 'time' => $_SERVER['HTTP_X_EMBED_TIME'] ?? '',
        'nonce' => $_SERVER['HTTP_X_EMBED_NONCE'] ?? '', 'signature' => $_SERVER['HTTP_X_EMBED_SIGNATURE'] ?? '',
    ]);
    $body = json_decode($raw, true);
    if (!is_array($body)) throw new ApiError('בקשה לא תקינה', 400);
    json_out($action === 'grant_status' ? provision_grant_status($client, $body) : provision_user($client, $body));
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    // grant_status: בלי sub ובלי פרטים אישיים ביומן — רק סוג השגיאה (PF-002, סעיף 7)
    error_log("platform provision ($action): " . ($action === 'grant_status' ? get_class($e) . ': ' . $e->getMessage() : $e));
    json_out(['error' => 'שגיאה בשרת'], 500);
}
