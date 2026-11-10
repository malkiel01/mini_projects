<?php
/**
 * ה-API של "שמאות מקרקעין" — המעטפת בלבד. הפעולות וההרשאות: lib/app.php.
 * כל תשובה JSON (חוץ מקובץ שצורף למטלה). כל בקשה נרשמת ביומן עם מזהה (X-Request-Id), וכל שגיאה — עם ההקשר שלה.
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
    $c = $GLOBALS['apr_cookie_out'] ?? null;
    if ($c) apr_set_cookie((string)($c['token'] ?? ''), !empty($c['clear']));
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    // גוף JSON, עד 1MB. צילום שמצורף למטלה (…-attach) מגיע כגוף גולמי — המודול קורא אותו בעצמו.
    $in = [];
    if ($method === 'POST' && !str_ends_with($action, '-attach')) {
        $max = 1_000_000;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) throw new AprError('הבקשה גדולה מדי', 413);
        $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) throw new AprError('הבקשה גדולה מדי', 413);
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    $out = apr_dispatch($method, $action, $_GET, $in, [
        'platform_user' => is_string($_SERVER['PLATFORM_USER'] ?? null) ? $_SERVER['PLATFORM_USER'] : null,
        'cookie' => is_string($_COOKIE[APR_COOKIE] ?? null) ? $_COOKIE[APR_COOKIE] : '',
        'csrf' => (string)($_SERVER['HTTP_X_CSRF'] ?? ''),
    ]);
    apr_out($out ?? ['ok' => true]);
} catch (Throwable $e) {
    devloop_log_error($e);
    $known = $e instanceof AprError;
    // המודול רושם חריגה רק כשאינה RuntimeException (או 5xx). תקלת מסד (PDOException) היא RuntimeException — נרשמת כאן
    if (!$known && $e instanceof RuntimeException && !($e->getCode() >= 500)) {
        devloop_log_write('error', 'php', 'exception', ['message' => $e->getMessage(), 'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
            'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 8)]]);
    }
    $status = $known && $e->status >= 400 && $e->status < 600 ? $e->status : 500;
    // מזהה הבקשה חוזר למשתמש — כך אפשר למצוא ביומן בדיוק את הבקשה שנכשלה
    apr_out(['error' => $known ? $e->getMessage() : 'תקלה בשרת', 'req' => devloop_req_id()], $status);
}
