<?php
/**
 * API של וקטורי. בקשה אחת = action אחד (ב-query או בגוף ה-JSON).
 *
 * בשלד: רק `me`. הספרייה (רשימה, שמירה ופתיחה של ‎.mbe) — בשלב השמירה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function reply(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

try {
    switch ($action) {
        case 'me':
            reply(['success' => true, 'user' => publicUser(requireUser())]);
        default:
            reply(['success' => false, 'error' => 'פעולה לא מוכרת'], 404);
    }
} catch (AppError $e) {
    reply(['success' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('vector-studio: ' . $e);
    reply(['success' => false, 'error' => 'שגיאת שרת'], 500);
}
