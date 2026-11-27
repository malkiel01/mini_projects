<?php
/**
 * POST api/report-ingest.php — קליטת דיווחי מורים ממקור מורשה (אסימון Bearer מקובץ מחוץ לאתר).
 * בלי עוגיות ובלי זהות לומד. דיווח שכבר נקלט לא נקלט שוב. אפיון: docs/spec/report-ingest.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/report-ingest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
try {
    [$status, $body] = academy_api_report_ingest($method, academy_load_config(), (string) ($_SERVER['CONTENT_TYPE'] ?? ''), (string) file_get_contents('php://input'));
} catch (Throwable $e) {
    error_log('cyber-academy ingest: ' . get_class($e));
    [$status, $body] = [500, ['ok' => false, 'code' => 'internal', 'error' => 'שגיאה פנימית']];
}
http_response_code($status);
if (strtoupper($method) !== 'HEAD') echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
