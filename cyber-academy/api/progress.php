<?php
/**
 * GET api/progress.php — התקדמות לומד. לומד רואה רק את עצמו; מנהל — כל לומד (?learner=).
 * נפרד מ-curriculum.php. בלי שום מקור GitHub. אפיון: docs/spec/student-progress.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/progress-api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
try {
    [$status, $body] = academy_api_progress_read($method, academy_load_config(), $_GET);
} catch (Throwable $e) {
    error_log('cyber-academy progress: ' . get_class($e));
    [$status, $body] = [500, ['ok' => false, 'code' => 'internal', 'error' => 'שגיאה פנימית']];
}
http_response_code($status);
if (strtoupper($method) !== 'HEAD') echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
