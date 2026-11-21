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
    if (isset($GLOBALS['apr_portal_cookie_out'])) apr_set_portal_cookie($GLOBALS['apr_portal_cookie_out']);
    // כניסה מאוחדת (sso-start, sso-callback): הפניה של הדפדפן, לא JSON
    if (is_array($data) && isset($data['_redirect'])) {
        http_response_code(302);
        header('Location: ' . $data['_redirect']);
        exit;
    }
    // קובץ (file, file-thumb): הכותרות נקבעות ב-lib/files.php — תמיד nosniff, Content-Disposition ו-sandbox
    if (is_array($data) && isset($data['_file'])) {
        http_response_code(200);
        foreach ($data['_file']['headers'] as $k => $v) header("$k: $v");
        while (ob_get_level() > 0) ob_end_clean();
        readfile($data['_file']['path']);
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

/** קורא את גוף הבקשה לקובץ זמני. מחזיר ['upload' => נתיב] או ['upload_error' => קוד] — בלי לקרוא מעבר לגודל המותר. */
function apr_upload_receive(): array
{
    $max = apr_file_max();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) return ['upload_error' => 'file_too_large'];
    apr_upload_tmp_sweep();
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    $src = fopen('php://input', 'rb');
    $dst = $tmp !== false ? fopen($tmp, 'wb') : false;
    if (!$src || !$dst) return ['upload_error' => 'storage_full'];
    $err = '';
    for ($total = 0; !feof($src);) {
        $chunk = fread($src, 1 << 20);
        if ($chunk === false || $chunk === '') break;
        $total += strlen($chunk);
        if ($total > $max) { $err = 'file_too_large'; break; }
        if (fwrite($dst, $chunk) !== strlen($chunk)) { $err = 'storage_full'; break; }
    }
    fclose($src);
    // הוצהר על גוף, ולא הגיע דבר: השרת זרק אותו (post_max_size) — "גדול מדי", לא "לא התקבל קובץ"
    if ($err === '' && $total === 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $err = 'file_too_large';
    if (!fclose($dst) && $err === '') $err = 'storage_full';
    if ($err !== '') { @unlink($tmp); return ['upload_error' => $err]; }
    // בקשה שנדחית לפני הקליטה (CSRF, הרשאה) — הקובץ הזמני לא נשאר
    register_shutdown_function(fn() => is_file($tmp) && @unlink($tmp));
    return ['upload' => $tmp];
}

try {
    // גוף JSON, עד 1MB. צילום שמצורף למטלה (…-attach) מגיע כגוף גולמי — המודול קורא אותו בעצמו.
    $in = [];
    $upload = [];
    if ($method === 'POST' && ($action === 'file-upload' || $action === 'source-file-upload' || $action === 'visit-photo-upload' || $action === 'portal-upload')) {
        // קובץ: הגוף הגולמי נשמר לקובץ זמני תחת data/tmp, עד הגודל המותר; הפרמטרים — בכתובת.
        // **הגוף נקרא רק אחרי אימות**: הנתב בודק זהות ו-CSRF, apr_file_upload בודק הרשאה בתיק ומכסה — ורק אז קורא לקורא הזה.
        // בפורטל (portal-upload) האימות הוא האסימון של הקישור, שמגיע בכותרת — אותו עיקרון: קודם אסימון, אחר כך גוף.
        $in = array_filter($_GET, 'is_string');
        $upload = ['upload_reader' => 'apr_upload_receive'];
    } elseif ($method === 'POST' && str_starts_with($action, 'inbound-')) {
        // webhook נכנס: הגוף הגולמי נקרא בידי lib/inbound.php, עד התקרה ולא מעבר לה — והחתימה נבדקת עליו לפני כל פענוח
        $upload = ['signature' => (string)($_SERVER['HTTP_X_RELAY_SIGNATURE'] ?? ''), 'raw_reader' => function (int $max): ?string {
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) return null;
            $raw = file_get_contents('php://input', false, null, 0, $max + 1);
            return is_string($raw) && strlen($raw) <= $max ? $raw : null;
        }];
    } elseif ($method === 'POST' && !str_ends_with($action, '-attach')) {
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
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
        'origin' => (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
        'fetch_site' => (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''),
        'sso_state' => is_string($_COOKIE['apr_sso'] ?? null) ? $_COOKIE['apr_sso'] : '',
        'portal_token' => (string)($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? ''),
        'portal_name' => (string)($_SERVER['HTTP_X_PORTAL_NAME'] ?? ''),
        'portal_cookie' => is_string($_COOKIE[APR_PORTAL_COOKIE] ?? null) ? $_COOKIE[APR_PORTAL_COOKIE] : '',
    ] + $upload);
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
