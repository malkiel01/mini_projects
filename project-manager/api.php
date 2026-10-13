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
 * הפורטל של הלקוח והעו"ד — API נפרד ב-portal/api.php (lib/portal.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/messages.php';
require_once __DIR__ . '/lib/auth.php';

pm_log_install_handlers();
pm_survey_migrate();
// מייל התזכורות היומי — "קרון" שרץ בבקשה הראשונה אחרי 07:00. תקלה בו לא מפילה את הבקשה.
try { pm_digest_tick(); } catch (Throwable $e) { pm_log('error', 'mail', 'digest.failed', ['message' => $e->getMessage()]); }
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
    $quiet = $code < 400 && ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $action !== 'me') || $action === 'upload-chunk');
    if (!$quiet) {
        $level = $code >= 500 ? 'error' : ($code >= 400 ? 'warn' : 'info');
        $req = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? pm_body() : array_diff_key($_GET, ['action' => 1]);
        pm_log($level, 'api', 'api.' . ($action ?: '?'), [
            'status' => $code, 'ms' => (int)round((microtime(true) - $PM_T0) * 1000),
            'message' => $payload['error'] ?? '', 'data' => ['request' => $req],
        ]);
    }
    // מיילים שנשלחו בבקשה הזו — הדפדפן מראה "✉️ נשלח מייל ל…"
    if ($code < 400 && function_exists('pm_notified') && pm_notified()) $payload['notified'] = pm_notified();
    // השגיאה ביומן — בעברית; למשתמש — בשפה שלו (X-Lang, lib/i18n.php)
    if (isset($payload['error'])) $payload['error'] = isset($payload['_t']) ? pm_t(...$payload['_t']) : pm_t((string)$payload['error']);
    unset($payload['_t']);
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
        // יומן הטלפון מושך את זה בלי עוגייה — האסימון בכתובת (lib/calendar.php)
        'GET calendar-feed'    => fn() => pm_calendar_feed((string)($_GET['t'] ?? '')),
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
        pm_reply(401, ['error' => 'נדרשת כניסה', 'reason' => $s ? $s['status'] : 'no_session']); // i18n-ok — pm_reply מתרגם
    }
    pm_log_user($user, true);

    if ($method === 'POST' && !hash_equals($user['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        pm_reply(403, ['error' => 'פג תוקף הדף — רענן ונסה שוב']); // i18n-ok — pm_reply מתרגם
    }
    $isDev = $user['role'] === 'developer';
    $in = $method === 'POST' ? pm_body() : $_GET;

    $result = match ("$method $action") {
        'GET me' => (function () use ($user) {
            $d = pm_db()->prepare('SELECT digest, lang FROM users WHERE id = ?');
            $d->execute([$user['id']]);
            $u = $d->fetch();
            return ['user' => $user + ['digest' => (bool)$u['digest'], 'lang' => $u['lang']], 'trades' => pm_trades(), 'templates' => pm_templates(),
                    'file_types' => pm_t_map(PM_FILE_TYPES), 'max_upload' => pm_max_upload(),
                    // מצב עצמאי: בלי חזרה לדף האפליקציות. כבוי כל עוד האפליקציה בתוך האינדקס.
                    'standalone' => pm_setting('standalone', '0') === '1',
                    'vat_rate' => pm_vat_rate(),
                    'labels' => array_map('pm_t_map', ['stage' => PM_STAGE_STATUSES, 'project' => PM_PROJECT_STATUSES, 'kind' => PM_PROJECT_KINDS,
                                 'quote' => PM_QUOTE_STATUSES, 'payment' => PM_PAYMENT_STATUSES, 'method' => PM_PAY_METHODS]),
                    // נוסחי ההודעות — אותו נוסח לוואטסאפ / SMS בלחיצה ולמייל האוטומטי
                    'msg_templates' => pm_msg_templates($user), 'msg_vars' => pm_msg_vars()];
        })(),

        'GET contacts'    => ['contacts' => pm_contacts_list($user, (string)($in['kind'] ?? ''),
                                 trim((string)($in['q'] ?? '')), !empty($in['archived']))],
        'GET contact'     => ['contact' => pm_contact_get($user, (int)($in['id'] ?? 0))],
        'POST contact'    => ['contact' => pm_contact_save($user, $in)],
        'POST contacts-import' => pm_contacts_import($user, $in),
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

        // קבצים ותיקיות (שלב 3)
        'GET folders'     => ['folders' => pm_folders($user, (int)($in['project_id'] ?? 0))],
        'POST folder'     => pm_folder_save($user, $in),
        'POST folder-delete' => pm_folder_delete($user, (int)($in['id'] ?? 0)),
        'GET files'       => ['files' => pm_files_list($user, (int)($in['project_id'] ?? 0), $in)],
        'GET file'        => !empty($in['meta']) ? ['file' => pm_file_get($user, (int)($in['id'] ?? 0))]
                             : pm_file_send($user, (int)($in['id'] ?? 0), !empty($in['thumb']), !empty($in['download'])),
        'POST upload-start'  => pm_upload_start($user, $in),
        'POST upload-chunk'  => pm_upload_chunk($user, (string)($_GET['id'] ?? ''), (int)($_GET['offset'] ?? -1)),
        'POST upload-finish' => pm_upload_finish($user, $in),
        'POST file-update' => pm_file_update($user, $in),
        'POST file-move'   => pm_file_move($user, (int)($in['id'] ?? 0), (int)($in['folder_id'] ?? 0)),
        'POST file-link'   => pm_file_link($user, (int)($in['id'] ?? 0), (int)($in['folder_id'] ?? 0), !empty($in['add'])),
        'POST file-delete' => pm_file_delete($user, (int)($in['id'] ?? 0)),

        // הצעות מחיר (שלב 5)
        'GET quotes'        => pm_quotes($user, (int)($in['project_id'] ?? 0)),
        'POST quote'        => pm_quote_save($user, $in),
        'POST quote-recommend' => pm_quote_recommend($user, (int)($in['id'] ?? 0), !empty($in['on'])),
        'POST quotes-send'  => pm_quotes_send($user, (int)($in['stage_id'] ?? 0)),
        'POST quote-decide' => pm_quote_decide($user, (int)($in['id'] ?? 0), (string)($in['role'] ?? ''),
                                               (string)($in['decision'] ?? ''), (string)($in['note'] ?? '')),
        'POST quote-cancel' => pm_quote_cancel($user, (int)($in['id'] ?? 0), (string)($in['note'] ?? '')),
        // הודעות (שלב 11)
        'GET msg-templates'  => ['templates' => pm_msg_templates($user)],
        'POST msg-template'  => pm_msg_template_save($user, $in),
        'POST message-log'   => pm_message_log($user, $in),
        'GET messages'       => pm_messages($user, (int)($in['project_id'] ?? 0)),
        'POST mail-test'     => pm_mail_test($user),

        // יומן ותזכורות (שלב 10)
        'GET calendar'      => pm_calendar($user, (string)($in['from'] ?? ''), (string)($in['to'] ?? '')),
        'GET reminders'     => ['reminders' => pm_reminders($user)],
        'POST appointment'  => pm_appointment_save($user, $in),
        'POST appointment-cancel' => pm_appointment_cancel($user, (int)($in['id'] ?? 0)),
        'GET cal-link'      => pm_cal_link($user, false),
        'POST cal-link'     => pm_cal_link($user, true),
        // שפת הממשק (lib/i18n.php) — נשמרת למשתמש, וגם המיילים אליו יוצאים בה
        'POST lang'         => (function () use ($user, $in) {
            $lang = (string)($in['lang'] ?? '');
            if (!isset(PM_LANGS[$lang])) throw new PmError('שפה לא מוכרת', 422);
            pm_db()->prepare('UPDATE users SET lang = ? WHERE id = ?')->execute([$lang, $user['id']]);
            return ['lang' => $lang];
        })(),
        'POST digest'       => (function () use ($user, $in) {
            pm_db()->prepare('UPDATE users SET digest = ? WHERE id = ?')->execute([!empty($in['on']) ? 1 : 0, $user['id']]);
            return ['digest' => !empty($in['on'])];
        })(),

        // דיווח מהשטח (שלב 8)
        'GET reports'       => pm_reports($user, (int)($in['project_id'] ?? 0)),
        'GET report-folder' => ['folder_id' => pm_report_folder($user, (int)($in['project_id'] ?? 0), (int)($in['stage_id'] ?? 0) ?: null)],
        'POST report'       => pm_report_save($user, $in),
        'POST report-update' => pm_report_update($user, $in),
        'POST report-delete' => pm_report_delete($user, (int)($in['id'] ?? 0)),

        // אבני דרך לתשלום (שלב 7)
        // אגף הכספים (lib/finance.php)
        'GET finance'         => pm_finance($user, (int)($in['project_id'] ?? 0)),
        'POST finance-settings' => pm_finance_settings_save($user, $in),
        'POST payment-split'  => pm_payment_split($user, $in),
        'GET payments'        => pm_payments($user, (int)($in['project_id'] ?? 0)),
        'POST payment'        => pm_payment_save($user, $in),
        'POST payment-request' => pm_payment_request($user, (int)($in['id'] ?? 0)),
        'POST payment-lawyer' => pm_payment_lawyer_approve($user, (int)($in['id'] ?? 0), (string)($in['note'] ?? '')),
        'POST payment-proof'  => pm_payment_proof_add($user, (int)($in['id'] ?? 0), $in, 'manager', $user['display_name'], (int)($in['file_id'] ?? 0) ?: null),
        'POST proof-review'   => pm_payment_proof_review($user, (int)($in['id'] ?? 0), !empty($in['ok']), $in['received'] ?? null, (string)($in['note'] ?? '')),
        'POST payment-cancel' => pm_payment_cancel($user, (int)($in['id'] ?? 0), (string)($in['note'] ?? '')),

        // קישורים אישיים לפורטל (שלב 6)
        'GET portal-links'  => ['links' => pm_portal_links($user, (int)($in['project_id'] ?? 0))],
        'POST portal-link'  => pm_portal_link_save($user, (int)($in['project_id'] ?? 0), (int)($in['contact_id'] ?? 0), (string)($in['do'] ?? 'get')),
        'POST quote-invite' => pm_quote_invite($user, (int)($in['stage_id'] ?? 0), is_array($in['supplier_ids'] ?? null) ? $in['supplier_ids'] : []),
        'GET approval'      => ['approval' => pm_approval_get($user, (int)($in['id'] ?? 0))],
        'POST max-upload'  => $isDev ? (function () use ($user, $in) {
            $mb = max(1, min(2000, (int)($in['mb'] ?? 100)));
            pm_setting_set('max_upload_mb', (string)$mb);
            pm_log('info', 'admin', 'setting.max_upload', ['data' => ['mb' => $mb]]);
            return ['max_upload' => pm_max_upload()];
        })() : throw new PmError('רק המפתח', 403),

        'GET trades'      => ['trades' => pm_trades()],
        'POST template'   => $isDev ? ['templates' => pm_template_save($user, $in)] : throw new PmError('רק המפתח עורך תבניות', 403),
        'POST template-delete' => $isDev ? ['templates' => pm_template_delete($user, (int)($in['id'] ?? 0))] : throw new PmError('רק המפתח עורך תבניות', 403),
        // שיעור המע"מ — לסכומים חדשים (הצעות ואבני דרך שומרות את השיעור ביום שנקבעו)
        'POST vat-rate'   => $isDev ? (function () use ($user, $in) {
            $r = $in['rate'] ?? '';
            if (!is_numeric($r) || $r < 0 || $r > 50) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'מע"מ']);
            pm_setting_set('vat_rate', (string)(float)$r);
            pm_audit("user:{$user['id']}", 'setting.vat_rate', 'setting', null, ['rate' => (float)$r]);
            return ['vat_rate' => (float)$r];
        })() : throw new PmError('רק המפתח', 403),
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
    pm_reply($e->getCode() ?: 400, ['error' => $e->getMessage(), '_t' => [$e->tpl, $e->vars]]);
} catch (Throwable $e) {
    error_log('project-manager api: ' . $e->getMessage());
    // החריגה המלאה ליומן — סוג, הודעה, קובץ, שורה ומחסנית — כדי שאפשר יהיה להבין מה קרה
    pm_log('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(),
        'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
                   'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 12)]]);
    $tpl = 'תקלה בשרת — נרשמה ביומן ({id})'; // i18n-ok — מתורגם ב-pm_reply (_t)
    pm_reply(500, ['error' => pm_fill($tpl, ['id' => pm_request_id()]), '_t' => [$tpl, ['id' => pm_request_id()]]]);
}
