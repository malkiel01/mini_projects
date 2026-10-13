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
 *   ספק: POST s-quote / s-payreq (קובץ בגוף, פרטים ב-query) · s-receipt · s-bank (JSON)
 *
 * אין כאן עוגיות, ולכן אין CSRF: הבקשה מורשית רק עם האסימון עצמו.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/messages.php';
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
    if ($code >= 400 || in_array($action, ['decide', 'decide-change', 'pay-approve', 'proof', 's-quote', 's-payreq', 's-receipt', 's-bank', 's-invoice', 'expense'], true)) {
        $body = in_array($action, ['proof', 's-quote', 's-payreq', 's-invoice', 'expense'], true) ? null : json_decode((string)file_get_contents('php://input'), true);
        if (is_array($body)) unset($body['signature']); // החתימה עצמה נשמרת באישור, לא ביומן
        pm_log($code >= 500 ? 'error' : ($code >= 400 ? 'warn' : 'info'), 'portal', 'portal.' . ($action ?: '?'), [
            'status' => $code, 'ms' => (int)round((microtime(true) - $PM_T0) * 1000),
            'message' => $payload['error'] ?? '', 'data' => ['request' => is_array($body) ? $body : array_diff_key($_GET, ['action' => 1, 't' => 1])],
        ]);
    }
    // השגיאה ביומן — בעברית; למשתמש — בשפה שלו (X-Lang, lib/i18n.php)
    if (isset($payload['error'])) $payload['error'] = isset($payload['_t']) ? pm_t(...$payload['_t']) : pm_t((string)$payload['error']);
    unset($payload['_t']);
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function pm_portal_json(): array
{
    if (!str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new PmError('בקשה לא תקינה', 400);
    $in = json_decode((string)file_get_contents('php://input'), true);
    return is_array($in) ? $in : [];
}

try {
    $token = (string)($_SERVER['HTTP_X_PORTAL'] ?? ($_GET['t'] ?? ''));
    $a = pm_portal_access($token);
    // השפה: מה שהמבקר בחר בדף (X-Lang), ואם לא בחר — השפה של איש הקשר
    $h = (string)($_SERVER['HTTP_X_LANG'] ?? '');
    pm_lang(isset(PM_LANGS[$h]) ? $h : ($a['contact']['lang'] ?? 'he'));
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
        // ספק (שלב 9): הצעה ודרישת תשלום — קובץ בגוף הבקשה (לא חובה), הפרטים ב-query
        'POST s-quote'   => pm_portal_supplier_quote($a, $_GET),
        'POST s-payreq'  => pm_portal_supplier_payreq($a, $_GET),
        'POST s-receipt' => pm_portal_supplier_receipt($a, pm_portal_json()),
        'POST s-bank'    => pm_portal_supplier_bank($a, pm_portal_json()),
        // כ4: תוספת / חריגה — אישור או דחייה; חשבונית מהספק (הקובץ בגוף הבקשה)
        'POST decide-change' => pm_portal_change_decide($a, pm_portal_json()),
        'POST s-invoice' => pm_portal_supplier_invoice($a, $_GET),
        'POST expense'   => pm_portal_expense($a, $_GET),
        default => throw new PmError('פעולה לא מוכרת', 404),
    };
    pm_portal_reply(200, $result);
} catch (PmError $e) {
    pm_portal_reply($e->getCode() ?: 400, ['error' => $e->getMessage(), '_t' => [$e->tpl, $e->vars]]);
} catch (Throwable $e) {
    error_log('project-manager portal: ' . $e->getMessage());
    pm_log('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(),
        'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
                   'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 12)]]);
    pm_portal_reply(500, ['error' => pm_fill('תקלה בשרת — נרשמה ({id})', ['id' => pm_request_id()]), '_t' => ['תקלה בשרת — נרשמה ({id})', ['id' => pm_request_id()]]]); // i18n-ok
}
