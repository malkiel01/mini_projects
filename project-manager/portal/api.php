<?php
/**
 * ה-API של פורטל הלקוח והעו"ד (lib/portal.php). ציבורי בשער — כל ההגנה כאן:
 * בלי אסימון תקף (כותרת X-Portal, או ?t= לקבצים) — 404.
 *
 *   GET  ?action=view                 הפרויקט, השלבים, ההצעות שנשלחו, הקבצים הגלויים
 *   POST ?action=decide               {quote_id, version, decision, note, signature?}
 *   GET  ?action=file&id=…&t=…        קובץ גלוי / של הצעה / אסמכתא (thumb=1, download=1)
 *   POST ?action=pay-approve          {payment_id, note} — העו"ד מאשר לתשלום
 *   POST ?action=proof&payment_id=…&amount=…&paid_on=…&method=…&note=…&name=…
 *                                     "שילמתי": גוף הבקשה = האסמכתא (תמונה / PDF)
 *
 * אין כאן עוגיות, ולכן אין CSRF: הבקשה מורשית רק עם האסימון עצמו.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/portal.php';
require_once __DIR__ . '/../lib/auth.php';

pm_log_install_handlers();
$PM_T0 = microtime(true);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function pm_portal_reply(int $code, array $payload): never
{
    global $PM_T0;
    $action = (string)($_GET['action'] ?? '');
    if ($code >= 400 || in_array($action, ['decide', 'pay-approve', 'proof'], true)) {
        $body = $action === 'proof' ? null : json_decode((string)file_get_contents('php://input'), true);
        if (is_array($body)) unset($body['signature']); // החתימה עצמה נשמרת באישור, לא ביומן
        pm_log($code >= 500 ? 'error' : ($code >= 400 ? 'warn' : 'info'), 'portal', 'portal.' . ($action ?: '?'), [
            'status' => $code, 'ms' => (int)round((microtime(true) - $PM_T0) * 1000),
            'message' => $payload['error'] ?? '', 'data' => ['request' => is_array($body) ? $body : array_diff_key($_GET, ['action' => 1, 't' => 1])],
        ]);
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $token = (string)($_SERVER['HTTP_X_PORTAL'] ?? ($_GET['t'] ?? ''));
    $a = pm_portal_access($token);
    pm_log_user(['id' => null, 'username' => 'portal:' . $a['link']['id'] . ' ' . $a['contact']['name']], true);

    $result = match ("$method $action") {
        'GET view' => (function () use ($a) {
            pm_portal_seen($a);
            return pm_portal_view($a);
        })(),
        'POST decide' => (function () use ($a) {
            if (!str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new PmError('בקשה לא תקינה', 400);
            $in = json_decode((string)file_get_contents('php://input'), true);
            return pm_portal_decide($a, is_array($in) ? $in : []);
        })(),
        'GET file' => pm_portal_file($a, (int)($_GET['id'] ?? 0), !empty($_GET['thumb']), !empty($_GET['download'])),
        'POST pay-approve' => (function () use ($a) {
            if (!str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new PmError('בקשה לא תקינה', 400);
            $in = json_decode((string)file_get_contents('php://input'), true);
            return pm_portal_pay_approve($a, is_array($in) ? $in : []);
        })(),
        // גוף הבקשה — הקובץ; הפרטים ב-query
        'POST proof' => pm_portal_proof($a, $_GET),
        default => throw new PmError('פעולה לא מוכרת', 404),
    };
    pm_portal_reply(200, $result);
} catch (PmError $e) {
    pm_portal_reply($e->getCode() ?: 400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('project-manager portal: ' . $e->getMessage());
    pm_log('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(),
        'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
                   'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 12)]]);
    pm_portal_reply(500, ['error' => 'תקלה בשרת — נרשמה (' . pm_request_id() . ')']);
}
