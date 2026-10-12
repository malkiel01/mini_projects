<?php
/**
 * ה-API של ממשק המנהלים.
 *
 * GET לקריאה, POST עם JSON לשינוי. ה-action ב-query (?action=…).
 * משתמשים וכניסה — של האפליקציה עצמה (lib/auth.php). api.php ציבורי בשער של
 * הפלטפורמה, ולכן כל ההגנה כאן: בלי משתמש פעיל — 401; כל POST דורש X-CSRF.
 * חריגים, לפני כניסה: auth (מצב), login, register, setup, logout,
 * forgot, reset-info, reset-password, client-error.
 *
 * הפורטל (לקוח, עו"ד, ספק) יקבל API נפרד ב-portal/api.php — שלב 4.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/auth.php';

pm_log_install_handlers();
$PM_T0 = microtime(true);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/** תשובה — וגם שורה ביומן לכל בקשה: פעולה, קוד, משך, ושגיאה אם הייתה. */
function pm_reply(int $code, array $payload): never
{
    global $PM_T0;
    $action = (string)($_GET['action'] ?? '');
    // קריאות שקטות (מצב, רשימות) — רק כשנכשלו, כדי שהיומן יישאר קריא
    $quiet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $code < 400 && $action !== 'me';
    if (!$quiet) {
        $level = $code >= 500 ? 'error' : ($code >= 400 ? 'warn' : 'info');
        $req = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? pm_body() : array_diff_key($_GET, ['action' => 1]);
        pm_log($level, 'api', 'api.' . ($action ?: '?'), [
            'status' => $code, 'ms' => (int)round((microtime(true) - $PM_T0) * 1000),
            'message' => $payload['error'] ?? '', 'data' => ['request' => $req],
        ]);
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function pm_body(): array
{
    static $data = null;
    $data ??= json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // לפני כניסה
    $pre = match ("$method $action") {
        'GET auth'      => fn() => pm_auth_state(),
        'POST login'    => fn() => pm_login(pm_body()),
        'POST register' => fn() => pm_register(pm_body()),
        'POST setup'    => fn() => pm_setup(pm_body()),
        'POST logout'   => fn() => pm_logout(pm_session_user()),
        'POST forgot'          => fn() => pm_forgot(pm_body()),
        'POST reset-info'      => fn() => pm_reset_info((string)(pm_body()['token'] ?? '')),
        'POST reset-password'  => fn() => pm_reset_password(pm_body()),
        // שגיאת JavaScript מהדפדפן — גם לפני כניסה (מסך הכניסה). X-PM מונע הצפה מאתר זר.
        'POST client-error' => function () {
            pm_require_app_header();
            $b = pm_body();
            pm_log_user(pm_session_user(), true);
            pm_log('error', 'client', 'client.error', ['message' => (string)($b['message'] ?? ''),
                'data' => array_intersect_key($b, array_flip(['source', 'line', 'col', 'stack', 'page', 'kind']))]);
            return ['ok' => true];
        },
        default         => null,
    };
    if ($pre) pm_reply(200, $pre());

    $user = pm_current_user();
    if (!$user) {
        $s = pm_session_user();
        pm_log_user($s, true);
        pm_reply(401, ['error' => 'נדרשת כניסה', 'reason' => $s ? $s['status'] : 'no_session']);
    }
    pm_log_user($user, true);

    if ($method === 'POST' && !hash_equals($user['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        pm_reply(403, ['error' => 'פג תוקף הדף — רענן ונסה שוב']);
    }
    $isDev = $user['role'] === 'developer';
    $in = $method === 'POST' ? pm_body() : $_GET;

    $result = match ("$method $action") {
        'GET me' => (function () use ($user) {
            return ['user' => $user, 'trades' => pm_trades(), 'templates' => pm_templates(),
                    // מצב עצמאי: בלי חזרה לדף האפליקציות. כבוי כל עוד האפליקציה בתוך האינדקס.
                    'standalone' => pm_setting('standalone', '0') === '1',
                    'labels' => ['stage' => PM_STAGE_STATUSES, 'project' => PM_PROJECT_STATUSES, 'kind' => PM_PROJECT_KINDS]];
        })(),

        'GET contacts'    => ['contacts' => pm_contacts_list($user, (string)($in['kind'] ?? ''),
                                 trim((string)($in['q'] ?? '')), !empty($in['archived']))],
        'GET contact'     => ['contact' => pm_contact_get($user, (int)($in['id'] ?? 0))],
        'POST contact'    => ['contact' => pm_contact_save($user, $in)],
        'POST archive'    => ['contact' => pm_contact_archive($user, (int)($in['id'] ?? 0), !empty($in['archived']))],

        'GET projects'    => ['projects' => pm_projects_list($user, trim((string)($in['q'] ?? '')), (string)($in['filter'] ?? 'open'))],
        'GET project'     => pm_project_get($user, (int)($in['id'] ?? 0)),
        'POST project'    => pm_project_save($user, $in),
        'POST project-status' => pm_project_status($user, (int)($in['id'] ?? 0), (string)($in['status'] ?? '')),
        'POST stage'      => pm_stage_save($user, $in),
        'POST stage-delete' => pm_stage_delete($user, (int)($in['id'] ?? 0)),
        'POST stages-order' => pm_stages_order($user, (int)($in['project_id'] ?? 0), is_array($in['ids'] ?? null) ? $in['ids'] : []),
        'POST note'       => pm_note($user, (int)($in['project_id'] ?? 0), (string)($in['text'] ?? '')),
        'POST member'     => pm_member($user, (int)($in['project_id'] ?? 0), (int)($in['contact_id'] ?? 0),
                                       !empty($in['add']), (string)($in['role'] ?? '')),

        'GET trades'      => ['trades' => pm_trades()],
        'POST template'   => $isDev ? ['templates' => pm_template_save($user, $in)] : throw new PmError('רק המפתח עורך תבניות', 403),
        'POST template-delete' => $isDev ? ['templates' => pm_template_delete($user, (int)($in['id'] ?? 0))] : throw new PmError('רק המפתח עורך תבניות', 403),
        'POST standalone' => $isDev ? (function () use ($user, $in) {
            pm_setting_set('standalone', !empty($in['on']) ? '1' : '0');
            pm_audit("user:{$user['id']}", 'setting.standalone', 'setting', null, ['on' => !empty($in['on'])]);
            return ['standalone' => !empty($in['on'])];
        })() : throw new PmError('רק המפתח', 403),
        'POST password'   => pm_change_password($user, $in),
        'POST profile'    => pm_update_profile($user, $in),
        'GET users'       => $isDev ? ['users' => pm_users_list(), 'orphans' => pm_orphans(), 'mail' => pm_mail_available()] : throw new PmError('רק המפתח', 403),
        'POST user'       => $isDev ? pm_user_action($user, (int)($in['id'] ?? 0), (string)($in['do'] ?? ''), $in) : throw new PmError('רק המפתח', 403),
        'POST user-save'  => $isDev ? pm_user_save($user, $in) : throw new PmError('רק המפתח', 403),
        'POST claim-orphans' => $isDev ? pm_claim_orphans($user, (int)($in['to'] ?? 0)) : throw new PmError('רק המפתח', 403),
        'GET log'         => $isDev ? ['entries' => pm_log_query($in), 'stats' => pm_log_stats()] : throw new PmError('רק המפתח', 403),
        'GET log-token'   => $isDev ? pm_log_token() : throw new PmError('רק המפתח', 403),
        'POST log-token'  => $isDev ? pm_log_token(true) : throw new PmError('רק המפתח', 403),
        'POST trade'      => $isDev ? ['trades' => pm_trade_save($user, $in)] : throw new PmError('רק המפתח עורך תחומים', 403),
        'POST trade-delete' => $isDev ? ['trades' => pm_trade_delete($user, (int)($in['id'] ?? 0))] : throw new PmError('רק המפתח עורך תחומים', 403),

        default => throw new PmError('פעולה לא מוכרת', 404),
    };
    pm_reply(200, $result);
} catch (PmError $e) {
    pm_reply($e->getCode() ?: 400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('project-manager api: ' . $e->getMessage());
    // החריגה המלאה ליומן — סוג, הודעה, קובץ, שורה ומחסנית — כדי שאפשר יהיה להבין מה קרה
    pm_log('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(),
        'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
                   'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 12)]]);
    pm_reply(500, ['error' => 'תקלה בשרת — נרשמה ביומן (' . pm_request_id() . ')']);
}
