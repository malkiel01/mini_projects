<?php
/**
 * api/progress.php — GET: ההתקדמות מהריפו (בלי הסוד). POST {"action":"save_token","token"}: שמירת הסוד בשרת.
 * גישה: רק משתמש שהשער אישר (granted). POST: JSON ומקור זהה בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/syllabus.php';
require_once __DIR__ . '/../lib/metrics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function lp_out(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$server = $_SERVER;
if (!lp_allowed($server)) lp_out(403, ['ok' => false, 'code' => 'forbidden', 'error' => 'אין גישה']);

$method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
try {
    if ($method === 'GET') {
        $token = lp_read_token();
        [$status, $items] = lp_fetch_progress($token);
        $curStatus = null;
        $summary = null;
        if ($status === 'ok') {
            [$curStatus, $cur] = lp_fetch_curriculum($token);
            if ($curStatus === 'ok') {
                $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem'));
                $summary = lp_summary($cur, $items, $today);
                $summary['metrics'] = lp_metrics($summary, $items, $today);
            }
        }
        lp_out(200, ['ok' => true, 'status' => $status, 'items' => $items, 'curriculum' => $curStatus, 'summary' => $summary]);
    }
    if ($method !== 'POST') lp_out(405, ['ok' => false, 'code' => 'method_not_allowed', 'error' => 'GET או POST בלבד']);

    // POST: מקור זהה בלבד (אין עוגיות בקליטה, אבל מונעים כתיבה מדף זר)
    $host = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
    $origin = strtolower((string) ($server['HTTP_ORIGIN'] ?? ''));
    if ($host === '' || $origin !== 'https://' . $host) lp_out(403, ['ok' => false, 'code' => 'forbidden', 'error' => 'בקשה ממקור אחר נדחתה']);
    $media = strtolower(trim(explode(';', (string) ($server['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($media !== 'application/json') lp_out(415, ['ok' => false, 'code' => 'unsupported_media_type', 'error' => 'נדרש JSON']);
    $b = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($b) || ($b['action'] ?? null) !== 'save_token') lp_out(400, ['ok' => false, 'code' => 'bad_request', 'error' => 'פעולה לא מוכרת']);
    $token = is_string($b['token'] ?? null) ? trim($b['token']) : '';
    if (strlen($token) < LP_TOKEN_MIN || strlen($token) > LP_TOKEN_MAX || preg_match('/\s/', $token)) {
        lp_out(422, ['ok' => false, 'code' => 'token_invalid', 'error' => 'האסימון אינו בפורמט תקין']);
    }
    if (!lp_save_token($token)) lp_out(503, ['ok' => false, 'code' => 'not_configured', 'error' => 'שמירת האסימון נכשלה. בדוק את תיקיית האסימונים בשרת']);
    lp_out(200, ['ok' => true, 'tokenStored' => true]);
} catch (Throwable $e) {
    error_log('linux-progress api: ' . get_class($e));
    lp_out(500, ['ok' => false, 'code' => 'internal', 'error' => 'שגיאה פנימית']);
}
