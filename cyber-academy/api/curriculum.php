<?php
/**
 * GET api/curriculum.php — תוכנית האקדמיה המאושרת בלבד, למשתמש מחובר ומורשה. קריאה בלבד, בלי נתוני לומד.
 * כבוי כל עוד מקור GitHub כבוי (source_disabled). האסימון לעולם לא בתשובה.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
try {
    [$status, $body] = academy_api_curriculum($method, academy_load_config());
} catch (Throwable $e) {
    error_log('cyber-academy curriculum: ' . academy_redact($e->getMessage(), (string) (academy_load_config()['github']['token'] ?? '')));
    [$status, $body] = [500, ['ok' => false, 'code' => 'internal', 'error' => 'שגיאה פנימית']];
}
if ($status === 405) header('Allow: GET, HEAD');
http_response_code($status);
if (strtoupper($method) !== 'HEAD') echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
