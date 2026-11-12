<?php
/**
 * נקודת הדיווח של ה-CSP (פרוסה C1): הדפדפן שולח לכאן הפרות (report-uri) של הסטודיו, של העורך ושל התצוגה החיה.
 * נצברות בטבלה csp_reports ונכתבות ליומן המפתח, מקובצות עם מונה (lib/csp.php).
 *
 * מאחורי השער (אינו ב-public של app.json): דוח נקלט רק עם זהות מהשער — דף זר אינו יכול להציף את היומן. בלי CSRF:
 * הדפדפן שולח את הדוח בעצמו, בלי כותרות שלנו; הפעולה רק מוסיפה מונה, והגוף מוגבל בגודל ובמספר הדוחות.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csp.php';

while (ob_get_level() > 0) @ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
csp_api_headers();
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }
try {
    $raw = (string)file_get_contents('php://input', false, null, 0, CSP_MAX_BODY + 1);
    csp_report_accept(identity(), (string)($_GET['p'] ?? ''), $raw);
} catch (Throwable $e) {
    error_log('kol-maroko csp report: ' . $e->getMessage());
}
http_response_code(204);
