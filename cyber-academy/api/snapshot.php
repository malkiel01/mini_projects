<?php
/**
 * GET api/snapshot.php — תמונת המצב של האקדמיה, JSON לדפדפן. קריאה בלבד.
 * מחזיר source ו-isSample בכל תשובה, ו-code יציב בכל כשל. האסימון (אם יוגדר) לעולם לא בתשובה.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
try {
    [$status, $body] = academy_api_handle($method, academy_load_config(), $_GET);
} catch (Throwable $e) {
    error_log('cyber-academy api: ' . academy_redact($e->getMessage(), (string) (academy_load_config()['github']['token'] ?? '')));
    [$status, $body] = [500, ['ok' => false, 'code' => 'internal', 'error' => 'שגיאה פנימית']];
}
if ($status === 405) header('Allow: GET, HEAD');
http_response_code($status);
if (strtoupper($method) !== 'HEAD') echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
