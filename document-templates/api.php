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
header('X-Request-Id: ' . dev_req_id());   // אותו מזהה בלוג המעקב — 🐞 דיווח ומטלות מפנים אליו

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** לוג המעקב (lib/devzone.php) — פעם אחת לבקשה. */
function track(?array $session, string $action, float $started, int $status, ?Throwable $e = null, array $extra = []): void
{
    if (!empty($GLOBALS['dt_tracked']) || $action === 'health' || $action === 'me' || $action === 'client_error') return;
    $GLOBALS['dt_tracked'] = true;
    dev_track($session, $action, $started, $status, $e, $extra);
}

$started = microtime(true);
$action  = (string)($_GET['action'] ?? '');
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$session = null;
// בקשה שהסתיימה בהורדת קובץ (exit), או שנפלה בשגיאה קטלנית — נרשמת כאן
register_shutdown_function(function () use (&$session, &$action, $started) {
    $err = error_get_last();
    $fatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    track($session, $action, $started, $fatal ? 500 : (http_response_code() ?: 200), null,
        $fatal ? ['message' => mb_substr($err['message'], 0, 500), 'trace' => basename($err['file']) . ':' . $err['line'] . ' fatal'] : []);
});

// פעולות שלא דורשות כניסה.
$PUBLIC = ['health', 'register', 'verify', 'login', 'forgot', 'reset', 'me',
           // ממלא חיצוני: הזהות היא האסימון שבכותרת X-Fill-Token, לא עוגייה.
           'fill_get', 'fill_asset', 'fill_otp_send', 'fill_otp_verify', 'fill_save', 'fill_complete',
           'fill_downloaded', 'fill_docx', 'fill_upload', 'fill_file',
           // מערכת חיצונית: הזהות היא מפתח ה-API שבכותרת Authorization, לא עוגייה.
           'ext_templates', 'ext_template', 'ext_issue', 'ext_document', 'ext_update', 'ext_file', 'fill_font',
           // Claude קורא מטלות ומשיב (DevLoop): הזהות היא האסימון שבכותרת X-Dev-Token.
           'devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'];
$POSTS  = ['register', 'verify', 'login', 'forgot', 'reset', 'logout', 'dev_user',
           'tpl_create', 'tpl_create_authored', 'tpl_save', 'tpl_set_source', 'tpl_archive', 'tpl_delete',
           'doc_create', 'doc_duplicate', 'doc_return', 'doc_save', 'doc_complete', 'doc_archive', 'doc_delete', 'doc_downloaded',
           'part_contact', 'part_link', 'fill_otp_send', 'fill_otp_verify', 'fill_save', 'fill_complete', 'fill_downloaded',
           'doc_upload', 'fill_upload', 'dev_quota', 'share_set', 'share_remove', 'share_leave',
           'contact_save', 'contact_archive', 'doc_contact', 'doc_tags', 'tpl_tags', 'bulk_preview', 'bulk_issue',
           'src_save', 'src_delete', 'src_sync', 'key_create', 'key_update', 'key_revoke', 'key_secret', 'key_test',
           'ext_issue', 'ext_update',
           'client_error', 'font_upload', 'font_delete', 'dev_force', 'me_update', 'me_password', 'me_logout_others',
           'contact_import', 'fmt_code_save', 'fmt_code_delete', 'dev-task-create', 'dev-task-set', 'dev-comment', 'dev-comment-delete', 'dev-token-create', 'dev-token-revoke',
           'dev-verify', 'dev-report', 'dev-attach', 'dev-log-links', 'dev-task-from-log', 'devapi-comment', 'devapi-attach'];

try {
    if (in_array($action, $POSTS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $multipart = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/');
    $body = $method === 'POST' && !$multipart ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    $GLOBALS['dt_body'] = $body;   // ללוג: מזהים בלבד (מסמך, תבנית)

    $session = current_session();
    if (!in_array($action, $PUBLIC, true)) {
        if (!$session) throw new ApiError('נדרשת כניסה', 401);
        if ($method === 'POST' && !hash_equals($session['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
            throw new ApiError('הבקשה נדחתה (CSRF). רעננו את הדף', 403);
        }
    }
    if ((str_starts_with($action, 'dev_') || str_starts_with($action, 'dev-')) && !$session['is_developer']) throw new ApiError('למפתח בלבד', 403);

    $result = match ($action) {
        'health' => (function () {
            $probe = 'בדיקה';
            db();
            return ['ok' => true, 'stage' => 12, 'integrations' => true, 'curl' => function_exists('curl_init'), 'php' => PHP_VERSION, 'sqlite' => true,
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
        'tpl_set_source' => tpl_set_source($session, $body),
        'tpl_create_authored' => tpl_create_authored($session, $body),
        // שלב 12: הנפקה מאקסל וייצוא
        'bulk_preview' => bulk_preview($session, $_POST, $_FILES),
        'bulk_issue'   => bulk_issue($session, $_POST, $_FILES),
        'bulk_sample'  => bulk_sample($session, (int)($_GET['template_id'] ?? 0)),
        'doc_export'   => doc_export($session, $_GET),
        'tpl_archive' => tpl_archive($session, $body),
        'tpl_delete'  => tpl_delete($session, $body),
        'doc_list'       => doc_list($session, $_GET),
        'doc_get'        => doc_get($session, (int)($_GET['id'] ?? 0)),
        'doc_asset'      => doc_asset($session, (int)($_GET['id'] ?? 0)),
        'doc_create'     => doc_create($session, $body),
        'doc_duplicate'  => doc_duplicate($session, $body),
        'doc_return'     => doc_return($session, $body),
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
        'fill_downloaded' => fill_downloaded($body),
        'fill_docx'       => fill_docx(),
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
        // חיבורים: מקורות נתונים ומפתחות API
        'src_list'   => src_list($session),
        'src_save'   => src_save($session, $body),
        'src_delete' => src_delete($session, $body),
        'src_sync'   => src_sync($session, $body),
        'key_list'   => key_list($session),
        'key_create' => key_create($session, $body),
        'key_update' => key_update($session, $body),
        'key_revoke' => key_revoke($session, $body),
        'key_secret' => key_secret($session, $body),
        'key_test'   => key_test($session, $body),
        // ה-API למערכות חיצוניות
        'ext_templates', 'ext_template', 'ext_issue', 'ext_document', 'ext_update', 'ext_file' => (function () use ($action, $body) {
            [$u, $k] = api_auth();
            return match ($action) {
                'ext_templates' => ext_templates($u),
                'ext_template'  => ext_template($u, $_GET),
                'ext_issue'     => ext_issue($u, $k, $body),
                'ext_document'  => ext_document($u, $_GET),
                'ext_update'    => ext_update($u, $body),
                'ext_file'      => ext_file($u, $_GET),
            };
        })(),
        // אזור הפיתוח (dev_ — למפתח בלבד) והגדרות המשתמש
        'dev_log_list'   => dev_log_list($_GET),
        'contact_fills'  => contact_fills($session, $_GET),
        'fmt_codes'       => fmt_codes($session, $_GET),
        'fmt_code_save'   => fmt_code_save($session, $body),
        'fmt_code_delete' => fmt_code_delete($session, $body),
        'contact_import' => contact_import($session, $body),
        // מטלות ושיח — המודול המשותף DevLoop (lib/devtasks.php), באותם שמות כמו במנהל הפרויקטים
        'dev-items'          => ['ok' => true] + devloop_task_list(),
        'dev-task-create'    => devloop_task_create($session, $body),
        'dev-task-set'       => devloop_task_set($session, $body),
        'dev-comment'        => devloop_comment_add((string)($body['code'] ?? ''), 'user', (int)$session['id'], (string)($body['body'] ?? '')),
        'dev-comment-delete' => devloop_comment_delete($session, $body),
        'dev-verify'         => devloop_verify($session, $body),
        'dev-report'         => devloop_report($session, $body),
        'dev-attach'         => devloop_attach('user:' . $session['id'], $_GET),
        'dev-file'           => devloop_file_out((int)($_GET['id'] ?? 0)),
        'dev-item-log'       => ['ok' => true] + devloop_item_log((string)($_GET['code'] ?? '')),
        'dev-log-links'      => ['ok' => true] + devloop_log_links((array)($body['ids'] ?? []), (array)($body['reqs'] ?? [])),
        'dev-task-from-log'  => devloop_task_from_log($session, $body),
        'dev-tokens'         => ['ok' => true] + devloop_token_list(),
        'dev-token-create'   => devloop_token_create($session, $body),
        'dev-token-revoke'   => devloop_token_revoke($session, $body),
        // באסימון, בלי עוגייה
        'devapi-feed'    => devloop_api_feed(devloop_api_auth(), $_GET),
        'devapi-comment' => devloop_api_comment(devloop_api_auth(), $body),
        'devapi-file'    => (devloop_api_auth() and devloop_file_out((int)($_GET['id'] ?? 0))),
        'devapi-attach'  => devloop_attach('claude', $_GET + ['_auth' => devloop_api_auth()]),
        'dev_health'     => dev_health(),
        'dev_docs'       => dev_docs($_GET),
        'dev_tpls'       => dev_tpls($_GET),
        'dev_force'      => dev_force($session, $body),
        'dev_audit_list' => dev_audit_list(),
        'client_error'   => client_error($session, $body),
        'me_info'        => me_sessions($session),
        'me_update'      => me_update($session, $body),
        'me_password'    => me_password($session, $body),
        'me_logout_others' => me_logout_others($session),
        // גופנים
        'font_list'   => font_list($session),
        'font_upload' => font_upload($session, $_POST, $_FILES),
        'font_delete' => font_delete($session, $body),
        'font_file'   => font_file($session, $_GET),
        'fill_font'   => fill_font($_GET),
        'dev_users' => dev_users(),
        'dev_user'  => dev_user_action($body, $session),
        default     => throw new ApiError('פעולה לא מוכרת', 404),
    };
    track($session, $action, $started, 200);
    json_out($result);
} catch (ApiError $e) {
    track($session, $action, $started, $e->status, $e);
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    // הודעת השגיאה עלולה לכלול נתיבים — לא מוחזרת ללקוח.
    error_log('document-templates: ' . $e->getMessage());
    track($session, $action, $started, 500, $e);
    json_out(['ok' => false, 'error' => 'שגיאת שרת'], 500);
}
