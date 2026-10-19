<?php
/**
 * נקודת הכניסה לשרת.
 *
 * כל פעולה שמשנה דבר היא POST עם JSON. אצל משתמש מחובר היא דורשת גם את
 * כותרת X-CSRF. פעולות dev_ — רק לחשבון המפתח.
 *
 * ⚠️ כל פעולה ב-$PUBLIC חייבת להופיע גם ב-"public" של app.json — אחרת שער
 * הפלטפורמה חוסם אותה (tests/gate_public.php בודק שהשתיים זהות).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/dev.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Request-Id: ' . dev_req_id());

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** לוג המעקב — פעם אחת לבקשה. health / me / client_error לא נרשמות (הן נקראות כל הזמן). */
function track(?array $session, string $action, float $started, int $status, ?Throwable $e = null): void
{
    if (!empty($GLOBALS['ob_tracked']) || in_array($action, ['health', 'me', 'client_error'], true)) return;
    $GLOBALS['ob_tracked'] = true;
    dev_track($session, $action, $started, $status, $e);
}

$started = microtime(true);
$action  = (string)($_GET['action'] ?? '');
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$session = null;

// פעולות שלא דורשות כניסה לאפליקציה.
const PUBLIC_ACTIONS = ['health', 'me', 'register', 'verify', 'login', 'forgot', 'reset'];
const POST_ACTIONS   = ['register', 'verify', 'login', 'forgot', 'reset', 'logout', 'client_error',
                        'me_update', 'me_password', 'me_logout_others', 'dev_user'];

try {
    if (in_array($action, POST_ACTIONS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $body = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    $GLOBALS['ob_body'] = $body;

    $session = current_session();
    if (!in_array($action, PUBLIC_ACTIONS, true)) {
        if (!$session) throw new ApiError('נדרשת כניסה', 401);
        if ($method === 'POST' && !hash_equals($session['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
            throw new ApiError('הבקשה נדחתה (CSRF). רעננו את הדף', 403);
        }
    }
    if (str_starts_with($action, 'dev_') && !$session['is_developer']) throw new ApiError('למפתח בלבד', 403);

    $result = match ($action) {
        'health' => (function () {
            db();
            return ['ok' => true, 'stage' => 1, 'php' => PHP_VERSION];
        })(),
        'me' => $session
            ? ['ok' => true, 'user' => public_user($session), 'csrf' => $session['csrf']]
            : ['ok' => true, 'user' => null],
        'register' => auth_register($body),
        'verify'   => auth_verify($body),
        'login'    => (function () use ($body) { $r = auth_login($body); unset($r['token']); return $r; })(),
        'logout'   => auth_logout($session),
        'forgot'   => auth_forgot($body),
        'reset'    => auth_reset($body),
        'client_error'     => client_error($session, $body),
        'me_info'          => me_info($session),
        'me_update'        => me_update($session, $body),
        'me_password'      => me_password($session, $body),
        'me_logout_others' => me_logout_others($session),
        // אזור הפיתוח
        'dev_users'      => dev_users(),
        'dev_user'       => dev_user_action($body, $session),
        'dev_log_list'   => dev_log_list($_GET),
        'dev_audit_list' => dev_audit_list(),
        'dev_health'     => dev_health(),
        default => throw new ApiError('פעולה לא מוכרת', 404),
    };
    track($session, $action, $started, 200);
    json_out($result);
} catch (ApiError $e) {
    track($session, $action, $started, $e->status, $e);
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    // הודעת השגיאה עלולה לכלול נתיבים — לא מוחזרת ללקוח, רק ללוג.
    error_log('org-budget-proposals: ' . $e->getMessage());
    track($session, $action, $started, 500, $e);
    json_out(['ok' => false, 'error' => 'שגיאת שרת'], 500);
}
