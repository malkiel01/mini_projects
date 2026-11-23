<?php
/**
 * api/integrations.php — אינטגרציות מנהל.
 *   GET                                  → סטטוס אסימון (בלי התוכן)
 *   POST {"action":"save_token","token"} → שמירת אסימון קליטה בצד השרת (מנהל בלבד, JSON בלבד)
 *   POST {"action":"sync"}               → סנכרון (אין מקור מחובר = אין פעולה)
 * אפיון: docs/spec/integrations.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/integrations.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
try {
    $config = academy_load_config();
    $server = $_SERVER;
    if ($method === 'GET') {
        [$status, $body] = integration_token_status($config, $server, PHP_SAPI);
    } elseif ($method === 'POST') {
        $media = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($media !== 'application/json') {
            [$status, $body] = [415, ['ok' => false, 'code' => 'unsupported_media_type', 'error' => 'נדרש JSON']];
        } else {
            $raw = (string) file_get_contents('php://input');
            $b = json_decode($raw, true);
            $action = is_array($b) ? ($b['action'] ?? null) : null;
            if ($action === 'save_token') {
                [$status, $body] = integration_save_token($config, $b, $server, PHP_SAPI);
            } elseif ($action === 'sync') {
                [$status, $body] = integration_sync($config, $server, PHP_SAPI);
            } else {
                [$status, $body] = [400, ['ok' => false, 'code' => 'bad_request', 'error' => 'פעולה לא מוכרת']];
            }
        }
    } else {
        [$status, $body] = [405, ['ok' => false, 'code' => 'method_not_allowed', 'error' => 'GET או POST בלבד']];
    }
} catch (Throwable $e) {
    error_log('cyber-academy integrations: ' . get_class($e));
    [$status, $body] = [500, ['ok' => false, 'code' => 'internal', 'error' => 'שגיאה פנימית']];
}
http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
