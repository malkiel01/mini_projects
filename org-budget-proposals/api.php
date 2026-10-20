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

require_once __DIR__ . '/lib/export.php';   // ← budgets ← orgs ← dev ← platform ← auth ← db

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
    if (!empty($GLOBALS['ob_tracked']) || in_array($action, ['health', 'me', 'client_error', 'embed_key'], true)) return;
    $GLOBALS['ob_tracked'] = true;
    dev_track($session, $action, $started, $status, $e);
}

$started = microtime(true);
$action  = (string)($_GET['action'] ?? '');
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$session = null;

// פעולות שלא דורשות כניסה לאפליקציה.
// embed_key — המפתח הציבורי שעורך התמלילים מוריד כדי לאמת את החתימות שלנו (lib/plan_doc.php). אין בו סוד.
const PUBLIC_ACTIONS = ['health', 'me', 'register', 'verify', 'login', 'forgot', 'reset', 'embed_key'];
// המסך החד-פעמי של מי שנכנס דרך הפלטפורמה ואין לו עוד חשבון — זהות מהשער, בלי session (lib/platform.php).
// לא ציבוריות: השער מעביר אותן רק למי שיש לו הרשאה.
const PLATFORM_ACTIONS = ['platform_link', 'platform_new'];
// בסיסמה של האפליקציה — רק כשהיא מנותקת מהפלטפורמה.
const PASSWORD_ACTIONS = ['register', 'verify', 'login', 'logout', 'forgot', 'reset', 'me_password'];
const POST_ACTIONS   = ['register', 'verify', 'login', 'forgot', 'reset', 'logout', 'client_error',
                        'me_update', 'me_password', 'me_logout_others', 'dev_user', 'dev_org_force',
                        'org_create', 'org_update', 'org_style_set', 'org_delete', 'member_add', 'member_set', 'member_remove',
                        'fund_save', 'fund_archive', 'fund_order', 'contact_save', 'contact_archive', 'cat_save', 'cat_archive',
                        'budget_create', 'budget_update', 'budget_status', 'budget_duplicate', 'budget_delete', 'bfund_set', 'bfund_toggle',
                        'line_save', 'lines_batch', 'line_delete', 'line_order', 'transfer_create', 'srow_save', 'srow_delete', 'srow_order',
                        'comment_add', 'comment_set', 'editor_session', 'plan_sync', 'org_import', 'org_logo_upload', 'platform_link', 'platform_new'];

try {
    if (in_array($action, POST_ACTIONS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $body = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    $GLOBALS['ob_body'] = $body;

    [$session, $platform] = resolve_session();
    if (in_array($action, PASSWORD_ACTIONS, true)) require_password_login();
    if (!in_array($action, PUBLIC_ACTIONS, true) && !in_array($action, PLATFORM_ACTIONS, true)) {
        if (!$session) throw new ApiError('נדרשת כניסה', 401);
        if ($method === 'POST' && !hash_equals($session['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
            throw new ApiError('הבקשה נדחתה (CSRF). רעננו את הדף', 403);
        }
    }
    if (str_starts_with($action, 'dev_') && !$session['is_developer']) throw new ApiError('למפתח בלבד', 403);

    $result = match ($action) {
        'health' => (function () {
            db();
            return ['ok' => true, 'stage' => 5, 'php' => PHP_VERSION];
        })(),
        'me' => $session
            ? ['ok' => true, 'user' => public_user($session), 'csrf' => $session['csrf'], 'platform' => $platform]
            : ['ok' => true, 'user' => null, 'platform' => $platform],
        'platform_link' => platform_link($body),
        'platform_new'  => platform_new(),
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
        // ארגונים — שלב 2 (lib/orgs.php)
        'org_list'        => org_list($session),
        'org_get'         => org_get($session, (int)($_GET['id'] ?? 0)),
        'org_history'     => org_history($session, (int)($_GET['id'] ?? 0)),
        'org_create'      => org_create($session, $body),
        'org_update'      => org_update($session, $body),
        'org_style_set'   => org_style_set($session, $body),
        'org_delete'      => org_delete($session, $body),
        'member_add'      => member_add($session, $body),
        'member_set'      => member_set($session, $body),
        'member_remove'   => member_remove($session, $body),
        'fund_save'       => fund_save($session, $body),
        'fund_archive'    => fund_archive($session, $body),
        'fund_order'      => fund_order($session, $body),
        'contact_save'    => contact_save($session, $body),
        'contact_archive' => contact_archive($session, $body),
        'cat_save'        => cat_save($session, $body),
        'cat_archive'     => cat_archive($session, $body),
        // הצעות — שלב 3 (lib/budgets.php)
        'budget_list'      => budget_list($session, (int)($_GET['org_id'] ?? 0)),
        'budget_get'       => budget_get($session, (int)($_GET['id'] ?? 0)),
        'budget_create'    => budget_create($session, $body),
        'budget_update'    => budget_update($session, $body),
        'budget_status'    => budget_status($session, $body),
        'budget_duplicate' => budget_duplicate($session, $body),
        'budget_delete'    => budget_delete($session, $body),
        'bfund_set'        => bfund_set($session, $body),
        'bfund_toggle'     => bfund_toggle($session, $body),
        'line_save'        => line_save($session, $body),
        'lines_batch'      => lines_batch($session, $body),
        'line_delete'      => line_delete($session, $body),
        'line_order'       => line_order($session, $body),
        'transfer_create'  => transfer_create($session, $body),
        'srow_save'        => srow_save($session, $body),
        'srow_delete'      => srow_delete($session, $body),
        'srow_order'       => srow_order($session, $body),
        'comment_add'      => comment_add($session, $body),
        'comment_set'      => comment_set($session, $body),
        'org_import'       => org_import($session, $body),
        // תכנית העבודה בעורך התמלילים (lib/plan_doc.php)
        'embed_key'        => wp()->publicKeys(),
        'editor_session'   => editor_session($session, $body),
        'plan_sync'        => plan_sync($session, $body),
        // הפלט — שלב 5 (lib/export.php). docx / xlsx / לוגו יוצאים כקובץ (exit), לא כ-JSON
        'budget_doc'       => budget_doc($session, (int)($_GET['id'] ?? 0)),
        'budget_docx'      => export_docx($session, (int)($_GET['id'] ?? 0)),
        'budget_xlsx'      => export_xlsx($session, (int)($_GET['id'] ?? 0)),
        'org_logo'         => org_logo_serve($session, (int)($_GET['id'] ?? 0)),
        'org_logo_upload'  => org_logo_upload($session, $_POST, $_FILES),
        // אזור הפיתוח
        'dev_orgs'       => dev_orgs($_GET),
        'dev_org_force'  => dev_org_force($session, $body),
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
