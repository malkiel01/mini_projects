<?php
/**
 * ה-API של ספורנו — הנתב היחיד (אפיון 01 §3, §5.3). הפעולות: lib/app.php; ההרשאות: lib/access.php.
 * כל תשובה JSON, עם X-Request-Id ו-Cache-Control: no-store. כל בקשה נרשמת ביומן הטכני (פעולה, מצב, משך, מי) —
 * **גוף הבקשה לא נרשם.**
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

devloop_log_begin(null);

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

function sf_out(array $body, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    // גוף JSON עד 1MB (פעולות התוכן — עד 'max' שלהן). קובץ שמצורף למטלה (…-attach) מגיע כגוף גולמי — המודול קורא אותו בעצמו, אחרי האימות.
    $in = [];
    if ($method === 'POST' && empty(SF_ACTIONS[$action]['body'])) {
        $max = (int)(SF_ACTIONS[$action]['max'] ?? 1_000_000);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) sf_fail('too_large');
        $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) sf_fail('too_large');
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    $out = sf_dispatch($method, $action, $_GET, $in, [
        'platform_user' => sf_platform_raw(),
        'cookie' => is_string($_COOKIE[SF_COOKIE] ?? null) ? $_COOKIE[SF_COOKIE] : '',
        'csrf' => (string)($_SERVER['HTTP_X_CSRF'] ?? ''),
        'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    ]);
    sf_out($out, 200);
} catch (Throwable $e) {
    sf_log_exception($e);
    [$status, $body] = sf_error_response($e);
    sf_out($body, $status);
}
