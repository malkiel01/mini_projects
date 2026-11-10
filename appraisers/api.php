<?php
/**
 * ה-API של "שמאות מקרקעין" — המעטפת בלבד. הפעולות: lib/app.php; ההרשאות: lib/access.php.
 * כל תשובה JSON (חוץ מקובץ שצורף למטלה) ונושאת X-Request-Id. כל בקשה נרשמת ביומן הטכני — פעולה, מצב, משך, מי;
 * **גוף הבקשה לא נרשם לעולם.**
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

devloop_log_begin(null);

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

function apr_out(mixed $data, int $status = 200): never
{
    if (isset($GLOBALS['apr_cookie_out'])) apr_set_cookie($GLOBALS['apr_cookie_out']);
    if (isset($GLOBALS['apr_sso_cookie_out'])) apr_set_sso_cookie($GLOBALS['apr_sso_cookie_out']);
    // כניסה מאוחדת (sso-start, sso-callback): הפניה של הדפדפן, לא JSON
    if (is_array($data) && isset($data['_redirect'])) {
        http_response_code(302);
        header('Location: ' . $data['_redirect']);
        exit;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    // גיבוי בלי מתזמן: אם cron.php לא רץ 10 דקות — סבב קצר אחרי שהתשובה נשלחה
    try {
        if ($status < 500 && isset($GLOBALS['apr_pdo']) && apr_cron_stale()) {
            if (function_exists('litespeed_finish_request')) litespeed_finish_request();
            elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            apr_jobs_run(5.0, 'lazy');
        }
    } catch (Throwable) {
        // סבב הגיבוי לא מפיל בקשה שכבר נענתה
    }
    exit;
}

try {
    // גוף JSON, עד 1MB. צילום שמצורף למטלה (…-attach) מגיע כגוף גולמי — המודול קורא אותו בעצמו.
    $in = [];
    if ($method === 'POST' && !str_ends_with($action, '-attach')) {
        $max = 1_000_000;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) apr_fail('too_large');
        $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) apr_fail('too_large');
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    $out = apr_dispatch($method, $action, $_GET, $in, [
        'platform_user' => apr_platform_raw(),
        'cookie' => is_string($_COOKIE[APR_COOKIE] ?? null) ? $_COOKIE[APR_COOKIE] : '',
        'csrf' => (string)($_SERVER['HTTP_X_CSRF'] ?? ''),
        'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'cron_url' => apr_self_url('cron.php'),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'sso_state' => is_string($_COOKIE['apr_sso'] ?? null) ? $_COOKIE['apr_sso'] : '',
    ]);
    apr_out(apr_is_devloop($action) ? ($out ?? ['ok' => true]) : ['ok' => true] + (array)$out);
} catch (Throwable $e) {
    devloop_log_error($e);
    // המודול רושם חריגה רק כשאינה RuntimeException (או 5xx). תקלת מסד (PDOException) היא RuntimeException — נרשמת כאן
    if (!($e instanceof AprError) && $e instanceof RuntimeException && !($e->getCode() >= 500)) {
        devloop_log_write('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(), 'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
            'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 8)]]);
    }
    [$status, $body] = apr_error_response($e, $action);
    apr_out($body, $status);
}
