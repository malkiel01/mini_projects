<?php
/**
 * נקודת הכניסה לשרת.
 *
 * כל פעולה שמשנה דבר היא POST עם JSON. אצל משתמש מחובר היא דורשת גם את
 * כותרת X-CSRF. פעולות "מפתח" — רק לחשבון המפתח, ורק מטא-דאטה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/documents.php';

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
$PUBLIC = ['health', 'register', 'verify', 'login', 'forgot', 'reset', 'me',
           // ממלא חיצוני: הזהות היא האסימון שבכותרת X-Fill-Token, לא עוגייה.
           'fill_get', 'fill_asset', 'fill_otp_send', 'fill_otp_verify', 'fill_save', 'fill_complete',
           'fill_upload', 'fill_file'];
$POSTS  = ['register', 'verify', 'login', 'forgot', 'reset', 'logout', 'dev_user',
           'tpl_create', 'tpl_save', 'tpl_archive', 'tpl_delete',
           'doc_create', 'doc_save', 'doc_complete', 'doc_archive', 'doc_delete', 'doc_downloaded',
           'part_contact', 'part_link', 'fill_otp_send', 'fill_otp_verify', 'fill_save', 'fill_complete',
           'doc_upload', 'fill_upload', 'dev_quota', 'share_set', 'share_remove', 'share_leave',
           'contact_save', 'contact_archive', 'doc_contact', 'doc_tags', 'tpl_tags'];

try {
    if (in_array($action, $POSTS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $multipart = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/');
    $body = $method === 'POST' && !$multipart ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

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
            return ['ok' => true, 'stage' => 9, 'php' => PHP_VERSION, 'sqlite' => true,
                'crypto' => unseal(seal($probe)) === $probe,
                'mail' => function_exists('mail'), 'zip' => class_exists('ZipArchive'),
                'finfo' => class_exists('finfo'),
                'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size')];
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
        'tpl_list'    => tpl_list($session),
        'tpl_get'     => tpl_get($session, (int)($_GET['id'] ?? 0)),
        'tpl_asset'   => tpl_asset($session, (int)($_GET['id'] ?? 0)),
        'tpl_create'  => tpl_create($session, $_POST, $_FILES),
        'tpl_save'    => tpl_save($session, $body),
        'tpl_archive' => tpl_archive($session, $body),
        'tpl_delete'  => tpl_delete($session, $body),
        'doc_list'       => doc_list($session, $_GET),
        'doc_get'        => doc_get($session, (int)($_GET['id'] ?? 0)),
        'doc_asset'      => doc_asset($session, (int)($_GET['id'] ?? 0)),
        'doc_create'     => doc_create($session, $body),
        'doc_save'       => doc_save($session, $body),
        'doc_complete'   => doc_complete($session, $body),
        'doc_archive'    => doc_archive($session, $body),
        'doc_delete'     => doc_delete($session, $body),
        'doc_downloaded' => doc_downloaded($session, $body),
        'part_contact'    => part_contact($session, $body),
        'part_link'       => part_link($session, $body),
        'fill_get'        => fill_get(),
        'fill_asset'      => fill_asset(),
        'fill_otp_send'   => fill_otp_send(),
        'fill_otp_verify' => fill_otp_verify($body),
        'fill_save'       => fill_save($body),
        'fill_complete'   => fill_complete(),
        'doc_upload'      => doc_upload($session, $_POST, $_FILES),
        'doc_file'        => doc_file($session, (int)($_GET['id'] ?? 0), (int)($_GET['att'] ?? 0)),
        'fill_upload'     => fill_upload($_POST, $_FILES),
        'fill_file'       => fill_file((int)($_GET['att'] ?? 0)),
        'dev_quota'       => dev_quota($body),
        'share_list'      => share_list($session, $_GET),
        'share_set'       => share_set($session, $body),
        'share_remove'    => share_remove($session, $body),
        'share_leave'     => share_leave($session, $body),
        'contact_list'    => contact_list($session, $_GET),
        'contact_save'    => contact_save($session, $body),
        'contact_archive' => contact_archive($session, $body),
        'doc_contact'     => doc_contact($session, $body),
        'doc_tags'        => doc_tags($session, $body),
        'tpl_tags'        => tpl_tags($session, $body),
        'doc_docx'        => doc_docx($session, (int)($_GET['id'] ?? 0), !empty($_GET['preview'])),
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
