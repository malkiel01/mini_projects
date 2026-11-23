<?php
/**
 * GET api/snapshot.php — תמונת המצב של האקדמיה, JSON לדפדפן. קריאה בלבד.
 * מחזיר source ו-isSample בכל תשובה. האסימון (אם יוגדר) לעולם לא בתשובה.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'קריאה בלבד'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    [$status, $body] = academy_api_snapshot(academy_load_config(), $_GET);
} catch (Throwable $e) {
    error_log('cyber-academy api: ' . academy_redact($e->getMessage(), (string) (academy_load_config()['github']['token'] ?? '')));
    [$status, $body] = [500, ['ok' => false, 'error' => 'שגיאה פנימית']];
}
http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
