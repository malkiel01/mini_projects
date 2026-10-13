<?php
/**
 * נקודת הכניסה לשרת.
 *
 * כל פעולה שמשנה דבר היא POST עם JSON. אצל משתמש מחובר היא דורשת גם את
 * כותרת X-CSRF. פעולות "מפתח" — רק לחשבון המפתח, ורק מטא-דאטה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function dev_log(?int $userId, string $action, ?string $error, float $started): void
{
    try {
        db()->prepare('INSERT INTO dev_log (at, user_id, action, error_code, duration_ms) VALUES (?, ?, ?, ?, ?)')
            ->execute([now_iso(), $userId, $action, $error, (int)((microtime(true) - $started) * 1000)]);
    } catch (Throwable) {
        // יומן שנכשל לא מפיל את הבקשה.
    }
}

$started = microtime(true);
$action  = (string)($_GET['action'] ?? '');
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$session = null;

// פעולות שלא דורשות כניסה.
$PUBLIC = ['health', 'register', 'verify', 'login', 'forgot', 'reset', 'me'];
$POSTS  = ['register', 'verify', 'login', 'forgot', 'reset', 'logout', 'dev_user'];

try {
    if (in_array($action, $POSTS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $body = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

    $session = current_session();
    if (!in_array($action, $PUBLIC, true)) {
        if (!$session) throw new ApiError('נדרשת כניסה', 401);
        if ($method === 'POST' && !hash_equals($session['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
            throw new ApiError('הבקשה נדחתה (CSRF). רעננו את הדף', 403);
        }
    }
    if (str_starts_with($action, 'dev_') && !$session['is_developer']) throw new ApiError('למפתח בלבד', 403);

    $result = match ($action) {
        'health' => (function () {
            $probe = 'בדיקה';
            db();
            return ['ok' => true, 'stage' => 2, 'php' => PHP_VERSION, 'sqlite' => true,
                'crypto' => unseal(seal($probe)) === $probe,
                'mail' => function_exists('mail'), 'zip' => class_exists('ZipArchive')];
        })(),
        'me' => $session
            ? ['ok' => true, 'user' => public_user($session), 'csrf' => $session['csrf']]
            : ['ok' => true, 'user' => null],
        'register'  => auth_register($body),
        'verify'    => auth_verify($body),
        'login'     => (function () use ($body) { $r = auth_login($body); unset($r['token']); return $r; })(),
        'logout'    => auth_logout($session),
        'forgot'    => auth_forgot($body),
        'reset'     => auth_reset($body),
        'dev_users' => dev_users(),
        'dev_user'  => dev_user_action($body, $session),
        default     => throw new ApiError('פעולה לא מוכרת', 404),
    };
    if ($action !== 'health' && $action !== 'me') dev_log($session ? (int)$session['id'] : null, $action, null, $started);
    json_out($result);
} catch (ApiError $e) {
    dev_log($session ? (int)$session['id'] : null, $action, (string)$e->status, $started);
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    // הודעת השגיאה עלולה לכלול נתיבים — לא מוחזרת ללקוח.
    error_log('document-templates: ' . $e->getMessage());
    dev_log($session ? (int)$session['id'] : null, $action, '500', $started);
    json_out(['ok' => false, 'error' => 'שגיאת שרת'], 500);
}
