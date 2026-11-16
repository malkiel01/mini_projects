<?php
/**
 * ה-API של "אסטרטגיות למסחר". כל תשובה JSON, חוץ מהייצוא (קובץ).
 *
 * GET  ?action=me                         המשתמש, ההגדרות, אסימון ה-CSRF והרשימות הסגורות (שווקים, מסגרות זמן…)
 * GET  ?action=strategies                 רשימה עם סיכום (אפיון 2.1)
 * GET  ?action=strategy&id=               אסטרטגיה וכל הגרסאות שלה (2.2)
 * GET  ?action=trades&from&to&strategy_id&version_id&market&symbol&result&plan    היומן (2.4)
 * GET  ?action=trade&id=                  עסקה (2.5)
 * GET  ?action=stats&strategy_id&version_id&method_id&market&from&to   הסטטיסטיקה (2.6), לפי תאריך סגירה; ועוד במטבע הבסיס (ECB, ✅12) ולפי שיטה
 * GET  ?action=trade_prefill&asset=      "פתח עסקה מזה": הנכס ביומן לפי סימול מנורמל (spec-stage-a 11)
 * GET  ?action=instruments                "נכסים שלי" (3.2)
 * GET  ?action=export&format=json|csv     ייצוא מלא / היומן (2.7)
 * POST action=settings | strategy_save | strategy_duplicate | strategy_status | strategy_delete
 *      | trade_save | trade_delete | instrument_save | instrument_delete
 *
 * שוק (spec-stage-a 2–3):
 * GET  ?action=market_providers          הספקים כפי שהמשתמש רואה אותם (מחובר, זמין, מה צריך)
 * GET  ?action=market_search&q=          חיפוש בכל הספקים שלו                     ← נספר בתקרת 30 לדקה
 * GET  ?action=pairs                     זוגות בשם (18.1): "מה" ו"מול מה" — רק מה שיש לו ספק למשתמש
 * GET  ?action=pair_parse&q=             "ביטקוין מול ין" → זוג; בלי ספק — "אין ספק לזוג הזה" (בלי פנייה לספק)
 * GET  ?action=pair_labels&assets=a,b    התווית של כל נכס: base/quote (קוד ושם) — "BTC ⇄ USD", לא הסימול המחובר (18.1.1)
 * GET  ?action=market_candles&asset=&tf=&from=&to=   נרות, מהמאגר ומהספק על החסר ← נספר בתקרת 30 לדקה
 * GET  ?action=chart_refresh&asset=&tf=&from=&to=&methods=a,b   הגרף (18.3, S3): נרות + תוצאת כל שיטה שנבחרה בבקשה אחת;
 *                                        שיטה בלי נר סגור חדש לא רצה שוב (lib/refresh.php); וכרטיס הניתוח (S4) עם
 *                                        הבדיקה לאחור השמורה בלבד ← נספר בתקרת 30 לדקה
 * GET  ?action=watchlist                 "המעקב שלי"
 * POST action=watchlist_add | watchlist_remove | watchlist_order | symbol_pref
 * POST action=cred_save | cred_test | cred_delete                  ספק משלי (א); המפתח לא חוזר לעולם
 *
 * ציור על הגרף (פרק 7):
 * GET  ?action=drawings&asset=           השרטוטים שלי על הנכס (כל המסגרות; הדפדפן מסנן לפי tf)
 * POST action=drawing_save | drawing_delete    יצירה/עדכון (עם id) ומחיקה — רק של המשתמש; עד 500 לנכס
 *
 * פרופיל, שיטות וניתוח (פרקים 5–6):
 * GET  ?action=profile                   הפרופיל שלי
 * POST action=profile_save
 * POST action=method_colors_save        {colors: {method_id: צבע}} — הצבע של כל שיטה בפרופיל (18.5); צבע לשיטה אחת בלבד
 * GET  ?action=methods                   השיטות שאני רואה (מופעלות; שיטת דוגמה — רק למנהל)
 * GET  ?action=analysis&asset=&tf=&methods=a,b   כרטיס לכל שיטה + שקלול. בדיקה לאחור לפי דרישה ← נספר בתקרת 30 לדקה
 * GET  ?action=method_journal&method=&version=&outcome=&kind=&before=   יומן השיטה (18.6): רשומות + סיכום לפי גרסה
 *
 * התראות (פרק 8) — הבדיקה עצמה רק ב-cron (bin/alerts-cron.php, CLI); כאן רק ניהול ותיבה:
 * GET  ?action=alerts                    ההתראות שלי והשיטות שמותרות בהן
 * GET  ?action=inbox | inbox_peek        התיבה; האחרונה שלא נקראה (ל-Service Worker כשמגיע פוש)
 * GET  ?action=push_key                  המפתח הציבורי של VAPID
 * POST action=alert_save | alert_status | alert_delete | inbox_read | push_subscribe | push_unsubscribe
 *
 * ענן MBE — נמחק (spec-stage-a 19.1): אין כאן drives_*. lib/drives ו-assets/js/drives נשארים כעותק מסונכרן בלבד
 *      (שער הפריסה של mbe-cloud/drives), ואף קוד לא טוען אותם.
 *
 * עורכים (פרק 24, ✅38) — ts_require_editor() בשרת לפני כל אחת; "(ראשי)" — גם ts_require_chief():
 * GET  ?action=admin_state | admin_methods
 * POST action=admin_provider_save | admin_shared_switch (ראשי) | admin_app_key {provider, cred_id|null, user_limit?} (ראשי, 21.3)
 *      | admin_cred_save | admin_cred_test | admin_cred_delete
 *      | admin_grant_save | admin_grant_delete | admin_role_add {user_id, role: editor|chief} (ראשי) | admin_role_remove (ראשי)
 *      | admin_cache_clear | admin_cache_cleanup | admin_method_save | admin_costs_save
 * למידה (23.10, L3): GET admin_learn&method=&version=&source= (פלחים, N, אחוז, תוחלת — רק N ≥ 30) · admin_learn_csv&method=[&version&source]
 *      (user_id — רק לעורך הראשי)
 * שכלול (18.7, S7): GET admin_tune_list[&method=] · POST admin_tune_propose {method, param, value, asset, tf, note?}
 *      (נספר בתקרת 30) | admin_tune_apply {id} (עורך ראשי בלבד)
 *
 * DevLoop (A1, PROTOCOL 7) — lib/devtasks.php, דרך המודול המשותף. עורך ראשי — הכול; עורך משני — צפייה, תגובה ופתיחת
 * פריט; משתמש — 🐞 בלבד (dev-report, וצילום לפריט שלו). הטבלה — TS_DEV_EDITOR / TS_DEV_USER (ר2):
 * GET  ?action=dev-items | dev-file&id= | dev-tokens | dev-item-log&code= | dev-log
 * POST action=dev-task-create | dev-task-set | dev-comment | dev-comment-delete | dev-verify | dev-report | dev-attach&code= (הגוף — הקובץ)
 *      | dev-token-create | dev-token-revoke | dev-log-links | dev-task-from-log
 * POST action=devloop-client-error       שגיאת JavaScript ליומן — כל משתמש מחובר
 * בלי משתמש, באסימון X-Dev-Token (tsd_…): devapi-feed | devapi-comment | devapi-file | devapi-attach ("public" ב-app.json)
 * כל בקשה נרשמת ביומן המובנה (data/_dev/log.sqlite, 30 יום) — פעולה, מצב, משך, מי; גוף הבקשה לא נרשם.
 *
 * הכניסה (A2 — spec-stage-a 20.1; lib/auth.php, lib/sso.php), לפני ה-session:
 * GET  ?action=login_config              {mode: connected|detached|unknown, platform, password}
 * GET  ?action=platform_key              המפתח הציבורי של האפליקציה (grant_status) — "public"
 * GET  ?action=sso_start                 "כניסה עם הכלים הדיגיטליים" (מנותקת) → הפניה למעטפת
 * GET  ?action=sso_callback&token&state  החזרה מהמעטפת: כל בדיקות sso.md §2 → session → הפניה לאפליקציה — "public"
 * POST action=login {email, password}    מנותקת + login.password בלבד; 5 שגיאות → רבע שעה; grant_status לפני session
 * POST action=logout                     יציאה מהאפליקציה (לא מהפלטפורמה)
 * POST action=password_set {password}    "סיסמה לאפליקציה" · admin_password_reset {user_id} (עורך ראשי)
 * בלי משתמש: 401 עם login (כמו login_config); המעטפת לא עונה: 503 עם login.mode = unknown.
 *
 * כל בקשה משנה: POST, גוף JSON, והכותרת X-CSRF עם האסימון מ-me (אפיון פרק 6). הזהות — רק מהשער
 * או מזהות חתומה שנבדקה (lib/auth.php, lib/sso.php); המזהה של המשתמש לא מגיע מהדפדפן אף פעם.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/market.php';
require_once __DIR__ . '/lib/tabs.php';
require_once __DIR__ . '/lib/drawings.php';
require_once __DIR__ . '/lib/analysis.php';
require_once __DIR__ . '/lib/forecast.php';
require_once __DIR__ . '/lib/refresh.php';
require_once __DIR__ . '/lib/alerts.php';
require_once __DIR__ . '/lib/prefill.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/fx.php';
require_once __DIR__ . '/lib/tune.php';
require_once __DIR__ . '/lib/devtasks.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

devloop_log_begin(null);   // היומן המובנה של DevLoop: מזהה בקשה, שגיאות PHP, ושורה לכל בקשה

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

function input(int $max = 1_000_000, int $tooBig = 413): array
{
    static $in = null;
    if ($in === null) {
        // גוף גדול מהתקרה — נדחה לפני הקריאה; ואם השרת עצמו זרק אותו (post_max_size) — php://input ריק
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $max) fail('הבקשה גדולה מדי', $tooBig);
        $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max || ($len > 0 && $raw === '')) fail('הבקשה גדולה מדי', $tooBig);
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    return $in;
}

/**
 * חריגה שלא טופלה → 500. ההודעה לפי סוג הבקשה: בקריאה (GET — analysis, chart_refresh…) לא נשמר כלום, ולכן "הפעולה לא
 * נשמרה" מטעה; רק בכתיבה (POST) היא נכונה. ביומן המובנה (DevLoop): המקום והעקבות (devloop_log_error), ובנוסף שורה עם
 * סוג החריגה, הפעולה והפרמטרים של הבקשה (ts_log_params — בלי סודות ובלי הגוף), כדי ש-500 בשרת יהיה אפשר לשחזר.
 */
function ts_server_error(Throwable $e, string $action, string $method): never
{
    devloop_log_error($e);
    devloop_log_write('error', 'api', 'exception.context', ['message' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500),
        'data' => ['class' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine(), 'params' => ts_log_params($_GET)]]);
    error_log('trading-strategies ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    fail($method === 'POST' ? 'תקלה בשרת — הפעולה לא נשמרה' : 'תקלה בשרת — נסו שוב בעוד רגע', 500);
}

/** פרמטרי ה-URL ליומן: בלי מפתחות שנראים כמו סוד, כל ערך מקוצר. גוף הבקשה לא נרשם אף פעם */
function ts_log_params(array $q): array
{
    $out = [];
    foreach (array_slice($q, 0, 20, true) as $k => $v) {
        $k = (string)$k;
        $out[$k] = preg_match('/token|secret|key|pass|csrf|cred|auth|sig|state/i', $k) ? '***'
            : (is_scalar($v) ? mb_substr((string)$v, 0, 200) : '[' . gettype($v) . ']');
    }
    return $out;
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// חריגה מחוץ לכל try (למשל במונה התקרה, ts_rate_hit) — אותה תשובת JSON ואותו רישום, ולא דף שגיאה של PHP
set_exception_handler(fn(Throwable $e) => ts_server_error($e, $action, $method));

// Claude באסימון (DevLoop): בלי עוגייה ובלי משתמש — לפני ה-session. האסימון נבדק במודול (devloop_api_auth)
if (in_array($action, TS_DEVAPI_ACTIONS, true)) {
    try {
        if ($action !== 'devapi-feed' && $action !== 'devapi-file' && $method !== 'POST') fail('נדרש POST', 405);
        out(['ok' => true] + ts_devapi_dispatch($action, $_GET, $action === 'devapi-comment' ? input() : []));
    } catch (TsDevError $e) {
        devloop_log_error($e);
        fail($e->getMessage(), $e->getCode() ?: 400);
    } catch (Throwable $e) {
        devloop_log_error($e);
        error_log('trading-strategies ' . $action . ': ' . $e->getMessage());
        fail('תקלה בשרת', 500);
    }
}

// הכניסה (A2 — spec-stage-a 20.1, lib/auth.php, lib/sso.php) — לפני ה-session: מי שמגיע לכאן עוד לא מחובר.
// platform_key ו-sso_callback ב-"public" (במחוברת השער חל); השאר נקראות רק כשמנותקת, ואז השער לא חל על האפליקציה.
// sso_start / sso_callback — ניווט של הדפדפן (הפניה), לא JSON.
if (in_array($action, ['login_config', 'platform_key', 'sso_start', 'sso_callback', 'login'], true)) {
    try {
        if ($action === 'login_config') out(['ok' => true] + ts_login_config());
        if ($action === 'platform_key') {
            header('Cache-Control: public, max-age=3600');
            out(ts_app_key_public());
        }
        if ($action === 'login') {
            if ($method !== 'POST') fail('נדרש POST', 405);
            // JSON בלבד: טופס מאתר אחר לא יכול לשלוח אותו בלי preflight
            if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) fail('נדרש JSON', 415);
            $in = input(10_000);
            try {
                [, $csrf] = ts_password_login($in['email'] ?? null, $in['password'] ?? null, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            } catch (TsLoginError $e) {
                fail($e->getMessage(), $e->getCode() ?: 400);
            }
            out(['ok' => true]);
        }
        $to = $action === 'sso_start' ? ts_sso_start() : ts_sso_callback($_GET, (string)($_COOKIE[TS_SSO_COOKIE] ?? ''));
        http_response_code(302);
        header('Location: ' . $to);
        exit;
    } catch (Throwable $e) {
        devloop_log_error($e);
        error_log('trading-strategies ' . $action . ': ' . $e->getMessage());
        fail('תקלה זמנית — נסו שוב בעוד רגע', 503);
    }
}

try {
    $session = ts_session();
} catch (TsUnavailable) {
    out(['ok' => false, 'error' => 'אין חיבור לכלים הדיגיטליים כרגע — נסו שוב בעוד כמה דקות', 'login' => ['mode' => 'unknown', 'platform' => false, 'password' => false]], 503);
} catch (TsNoAccess $e) {
    out(['ok' => false, 'error' => $e->getMessage(), 'login' => ts_login_config() + ['denied' => true]], 401);
} catch (TsConflict $e) {
    fail($e->getMessage(), 403);
} catch (Throwable $e) {
    error_log('trading-strategies session: ' . $e->getMessage());
    fail('תקלה זמנית במסד — נסו שוב בעוד רגע', 503);
}
if (!$session) {
    // מנותקת — מסך כניסה; מחוברת — אין הרשאה (השער לא נתן זהות)
    $login = ts_login_config();
    out(['ok' => false, 'error' => $login['mode'] === 'connected' ? TS_NO_ACCESS_MSG : 'צריך להיכנס', 'login' => $login], 401);
}
[$user, $csrf] = $session;
$uid = (int)$user['id'];
$tz = $user['timezone'];
devloop_log_user(['id' => $uid, 'username' => $user['display_name']]);

// DevLoop (PROTOCOL 7, 24.2, ר2) — עורך ראשי: הכול. עורך משני: צפייה, תגובה ופתיחת פריט. משתמש: 🐞 בלבד.
// הדפדפן רק מסתיר; כאן האכיפה (ts_dev_allow, lib/devtasks.php)
if (in_array($action, TS_DEV_ACTIONS, true)) {
    try {
        $write = in_array($action, TS_DEV_WRITE, true);
        if ($write || $action === 'dev-attach') {
            if ($method !== 'POST') fail('נדרש POST', 405);
            if ($write && !str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) fail('נדרש JSON', 415);
            if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('פג תוקף הדף — רעננו ונסו שוב', 403);
        }
        ts_dev_allow($action, $uid, $_GET);
        out(['ok' => true] + ts_dev_dispatch($action, $user, $_GET, $write ? input() : []));
    } catch (TsForbidden $e) {
        fail($e->getMessage(), 403);
    } catch (TsDevError $e) {
        devloop_log_error($e);
        fail($e->getMessage(), $e->getCode() ?: 400);
    } catch (Throwable $e) {
        ts_server_error($e, $action, $method);
    }
}

const TS_WRITE = ['logout', 'password_set', 'admin_password_reset', 'settings', 'strategy_save', 'strategy_duplicate', 'strategy_status', 'strategy_delete',
    'trade_save', 'trade_delete', 'instrument_save', 'instrument_delete',
    'drawing_save', 'drawing_delete', 'profile_save', 'admin_method_save', 'admin_costs_save',
    'alert_save', 'alert_status', 'alert_delete', 'inbox_read', 'push_subscribe', 'push_unsubscribe',
    'watchlist_add', 'watchlist_remove', 'watchlist_order', 'market_tabs_save', 'method_colors_save', 'symbol_pref', 'cred_save', 'cred_test', 'cred_delete',
    'admin_provider_save', 'admin_shared_switch', 'admin_app_key', 'admin_cred_save', 'admin_cred_test', 'admin_cred_delete',
    'admin_grant_save', 'admin_grant_delete', 'admin_role_add', 'admin_role_remove', 'admin_cache_clear', 'admin_cache_cleanup',
    'admin_tune_propose', 'admin_tune_apply'];
// בקשות שפונות (או עלולות לפנות) לספק — בתקרה של 30 לדקה למשתמש (3.5, ✅3)
const TS_DATA = ['market_search', 'market_candles', 'chart_refresh', 'analysis', 'admin_tune_propose', 'cred_save', 'cred_test', 'admin_cred_save', 'admin_cred_test'];

if (in_array($action, TS_WRITE, true)) {
    if ($method !== 'POST') fail('נדרש POST', 405);
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) fail('נדרש JSON', 415);
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('פג תוקף הדף — רעננו ונסו שוב', 403);
}

$in = !in_array($action, TS_WRITE, true) ? [] : input();
$id = (int)($in['id'] ?? $_GET['id'] ?? 0);
if (str_starts_with($action, 'admin_')) {
    try {
        ts_require_editor($uid);
    } catch (TsForbidden $e) {
        fail($e->getMessage(), 403);
    }
}
if (in_array($action, TS_DATA, true)) {
    try {
        ts_rate_hit($uid);
    } catch (TsRateLimited $e) {
        header('Retry-After: 60');
        fail($e->getMessage(), 429);
    }
}

/** מסננים מה-query string, רק המוכרים */
function filters(): array
{
    $f = [];
    foreach (['from', 'to', 'strategy_id', 'version_id', 'method_id', 'market', 'symbol', 'result', 'plan', 'currency'] as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') $f[$k] = mb_substr($_GET[$k], 0, 40);
    }
    return $f;
}

try {
    switch ($action) {
        case 'me':
            out(['ok' => true, 'csrf' => $csrf, 'user' => ts_settings_out($user),
                // role: chief / editor / user (פרק 24). is_admin / is_primary_admin — אותו דבר בשמות הישנים, לקוד שעוד קורא אותם
                'role' => ts_role($uid), 'is_editor' => ts_is_editor($uid), 'is_chief' => ts_is_chief(),
                'is_admin' => ts_is_editor($uid), 'is_primary_admin' => ts_is_chief(), 'lists' => [
                'markets' => TS_MARKETS, 'timeframes' => TS_TIMEFRAMES, 'styles' => TS_STYLES, 'statuses' => TS_STATUSES,
                'moods' => TS_MOODS, 'plan' => TS_PLAN,
            ], 'profile' => ts_profile($uid), 'horizon_tfs' => TS_HORIZON_TFS, 'risk_defaults' => TS_RISK_DEFAULTS,
                'disclaimer' => TS_ANALYSIS_DISCLAIMER, 'login_via' => ts_current()['via'] ?? 'gate',
                'has_password' => !empty($user['password_hash']), 'unread' => ts_inbox_unread($uid), 'version' => ts_app_version()]);

        case 'logout':
            // יציאה מהאפליקציה בלבד — לא נוגעת בפלטפורמה (auth.md §5). מחוברת: השער יפתח session חדש בבקשה הבאה
            ts_session_drop();
            out(['ok' => true]);

        case 'password_set':
            ts_password_set($uid, $in['password'] ?? null);
            out(['ok' => true]);

        case 'settings':
            ts_settings_save($uid, $in);
            ts_profile_sync_risk($uid);
            out(['ok' => true]);

        // ── פרופיל, שיטות, ניתוח ──
        case 'profile':
            out(['ok' => true, 'profile' => ts_profile($uid)]);

        case 'profile_save':
            out(['ok' => true, 'profile' => ts_profile_save($uid, $in)]);

        case 'methods':
            out(['ok' => true, 'methods' => ts_methods_visible($uid)]);

        case 'method_journal':
            // יומן השיטה (18.6, S6): מנהל — הכול; משתמש — רק נכסים שהוא רואה (✅27, ✅2). קריאה בלבד, בלי פנייה לספק
            out(['ok' => true] + ts_journal_list($uid, array_intersect_key($_GET, array_flip(['method', 'version', 'outcome', 'kind', 'before']))));

        // ── התראות ──
        case 'alerts':
            out(['ok' => true, 'alerts' => ts_alerts_list($uid), 'methods' => ts_alert_methods($uid),
                'per_user' => TS_ALERTS_PER_USER, 'push_devices' => ts_push_count($uid)]);

        case 'alert_save':
            out(['ok' => true, 'alert' => ts_alert_save($uid, $in)]);

        case 'alert_status':
            out(['ok' => true, 'alert' => ts_alert_status($uid, $in['id'] ?? null, $in['status'] ?? null)]);

        case 'alert_delete':
            ts_alert_delete($uid, $in['id'] ?? null);
            out(['ok' => true]);

        case 'inbox':
            out(['ok' => true, 'events' => ts_inbox($uid), 'unread' => ts_inbox_unread($uid)]);

        case 'inbox_peek':
            $ev = array_values(array_filter(ts_inbox($uid, 5), fn($e) => !$e['read']));
            out(['ok' => true, 'event' => $ev[0] ?? null, 'unread' => ts_inbox_unread($uid)]);

        case 'inbox_read':
            ts_inbox_read($uid, $in['ids'] ?? null);
            out(['ok' => true, 'unread' => ts_inbox_unread($uid)]);

        case 'push_key':
            out(['ok' => true, 'key' => ts_vapid()['public']]);

        case 'push_subscribe':
            ts_push_subscribe($uid, $in);
            out(['ok' => true, 'push_devices' => ts_push_count($uid)]);

        case 'push_unsubscribe':
            ts_push_unsubscribe($uid, $in['endpoint'] ?? null);
            out(['ok' => true, 'push_devices' => ts_push_count($uid)]);

        case 'analysis':
            out(['ok' => true] + ts_analysis($uid, $_GET['asset'] ?? '', $_GET['tf'] ?? '', $_GET['methods'] ?? ''));

        case 'strategies':
            out(['ok' => true, 'strategies' => ts_strategies_list($uid, $tz)]);

        case 'strategy':
            out(['ok' => true, 'strategy' => ts_strategy_get($uid, $id, $tz)]);

        case 'strategy_save':
            [$sid, $newVersion] = ts_strategy_save($uid, $in);
            out(['ok' => true, 'id' => $sid, 'new_version' => $newVersion, 'strategy' => ts_strategy_get($uid, $sid, $tz)]);

        case 'strategy_duplicate':
            out(['ok' => true, 'id' => ts_strategy_duplicate($uid, $id)]);

        case 'strategy_status':
            ts_strategy_set_status($uid, $id, $in['status'] ?? null);
            out(['ok' => true]);

        case 'strategy_delete':
            ts_strategy_delete($uid, $id);
            out(['ok' => true]);

        case 'trades':
            $rows = ts_trades_load($uid, $tz, filters());
            out(['ok' => true, 'trades' => array_map(fn($t) => ts_trade_out($t, $tz), $rows)]);

        case 'trade':
            out(['ok' => true, 'trade' => ts_trade_get($uid, $id, $tz)]);

        case 'trade_save':
            $tid = ts_trade_save($uid, $in, $tz);
            out(['ok' => true, 'id' => $tid, 'trade' => ts_trade_get($uid, $tid, $tz)]);

        case 'trade_delete':
            ts_trade_delete($uid, $id);
            out(['ok' => true]);

        case 'stats':
            $f = filters() + ['by' => 'closed'];
            unset($f['result']);
            $closed = array_filter(ts_trades_load($uid, $tz, $f), fn($t) => $t['calc']['status'] === 'closed');
            $noStop = count(array_filter($closed, fn($t) => $t['calc']['r'] === null));
            // שמות רק לשיטות שהמשתמש רואה, ולשיטות שכבר בעסקאות שלו (ממצא בקרה #1282)
            $methodNames = array_column(ts_methods_visible($uid), 'name', 'id');
            $mine = ts_db()->prepare('SELECT DISTINCT method_id FROM trades WHERE user_id = ? AND method_id IS NOT NULL');
            $mine->execute([$uid]);
            foreach ($mine->fetchAll(PDO::FETCH_COLUMN) as $mid) $methodNames[$mid] ??= ts_trade_method_name((string)$mid);
            out(['ok' => true, 'by_currency' => ts_stats(array_values($closed), $tz), 'no_stop' => $noStop,
                'in_base' => $closed ? ts_stats_in_base($uid, array_values($closed), $user['base_currency'], $tz) : null,
                'method_names' => $methodNames]);

        case 'trade_prefill':
            out(['ok' => true, 'prefill' => ts_trade_prefill($uid, $_GET['asset'] ?? '')]);

        case 'instruments':
            out(['ok' => true, 'instruments' => ts_instruments_list($uid)]);

        case 'instrument_save':
            ts_instrument_save($uid, $in, $tz);
            out(['ok' => true]);

        case 'instrument_delete':
            ts_instrument_delete($uid, $id);
            out(['ok' => true]);

        // ── שוק ──
        case 'pairs':
            // בחירת זוג בשם (18.1): "מה" ו"מול מה" — רק זוגות שיש להם ספק למשתמש. בלי פנייה לספק
            out(['ok' => true] + ts_pairs_for($uid));

        case 'pair_parse':
            // "ביטקוין מול ין" → הזוג. זוג בלי ספק — הודעה, לא שגיאה, ולא נשלחת בקשה לספק (18.1 קריטריון 3)
            $pp = ts_pair_parse((string)($_GET['q'] ?? ''));
            $pa = $pp && $pp['known'] ? ts_pair_asset($uid, $pp['pair']) : null;
            out(['ok' => true, 'pair' => $pp ? $pp['pair'] : null, 'label' => $pp['label'] ?? null, 'base' => $pp['base'] ?? null, 'quote' => $pp['quote'] ?? null, 'asset' => $pa,
                'message' => $pp && !$pa ? 'אין ספק לזוג הזה' : null]);

        case 'pair_labels':
            $as = array_slice(array_filter(explode(',', (string)($_GET['assets'] ?? ''))), 0, 200);
            out(['ok' => true, 'labels' => ts_pair_labels($as, $uid)]);

        case 'market_providers':
            out(['ok' => true, 'providers' => ts_providers_for($uid), 'shared_enabled' => ts_shared_enabled()]);

        case 'market_search':
            out(['ok' => true] + ts_market_search($uid, $_GET['q'] ?? ''));

        case 'chart_refresh':
            out(['ok' => true] + ts_chart_refresh($uid, $_GET['asset'] ?? '', $_GET['tf'] ?? '', $_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['methods'] ?? ''));

        case 'market_candles':
            out(['ok' => true] + ts_market_candles($uid, $_GET['asset'] ?? '', $_GET['tf'] ?? '', $_GET['from'] ?? null, $_GET['to'] ?? null, ts_now()));

        // לשוניות במסך השוק (18.1.4). market_tab — מהמאגר בלבד, בלי ספק, ולכן לא בתקרה של TS_DATA
        case 'market_tabs':
            out(['ok' => true] + ts_market_tabs_out($uid));

        case 'market_tabs_save':
            out(['ok' => true] + ts_market_tabs_save($uid, $in['tabs'] ?? null));

        // צבע לכל שיטה (18.5, S5) — בפרופיל, לא במכשיר: חוזר אחרי יציאה וכניסה ובכל זוג
        case 'method_colors_save':
            out(['ok' => true, 'method_colors' => ts_method_colors_save($uid, $in['colors'] ?? null)]);

        case 'market_tab':
            out(['ok' => true] + ts_market_tab($uid, $_GET['id'] ?? '', ts_now()));

        case 'watchlist':
            out(['ok' => true, 'watchlist' => ts_watchlist($uid)]);

        case 'watchlist_add':
            ts_watchlist_add($uid, $in['asset'] ?? null);
            out(['ok' => true, 'watchlist' => ts_watchlist($uid)]);

        case 'watchlist_remove':
            ts_watchlist_remove($uid, $in['asset'] ?? null);
            out(['ok' => true, 'watchlist' => ts_watchlist($uid)]);

        case 'watchlist_order':
            ts_watchlist_order($uid, $in['assets'] ?? null);
            out(['ok' => true]);

        case 'symbol_pref':
            ts_symbol_pref_set($uid, $in['asset'] ?? null);
            out(['ok' => true]);

        case 'cred_save':
            ts_cred_save($uid, $in['provider'] ?? null, $in['secret'] ?? null, $in['label'] ?? '');
            out(['ok' => true, 'providers' => ts_providers_for($uid)]);

        case 'cred_test':
            ts_cred_test_mine($uid, $in['provider'] ?? '');
            out(['ok' => true, 'providers' => ts_providers_for($uid)]);

        case 'cred_delete':
            ts_cred_delete_mine($uid, $in['provider'] ?? '');
            out(['ok' => true, 'providers' => ts_providers_for($uid)]);

        // ── ציור ──
        case 'drawings':
            out(['ok' => true, 'drawings' => ts_drawings_list($uid, $_GET['asset'] ?? '')]);

        case 'drawing_save':
            out(['ok' => true, 'drawing' => ts_drawing_save($uid, $in)]);

        case 'drawing_delete':
            ts_drawing_delete($uid, $in['id'] ?? null);
            out(['ok' => true]);

        // ── עורכים (ts_require_editor כבר נבדק למעלה, לכל admin_*) ──
        case 'admin_state':
            $providers = [];
            foreach (array_keys(TS_PROVIDERS) as $pid) {
                $m = ts_provider($pid)->meta();
                $providers[] = ['id' => $pid, 'name' => $m['name'], 'needs_key' => $m['needs_key'], 'markets' => $m['markets'],
                    'delay' => $m['delay'], 'share' => $m['share'], 'terms' => $m['terms'], 'default_limits' => $m['limits'],
                    'limits' => ts_provider_limits($pid)] + ts_provider_settings($pid);
            }
            $log = ts_db()->query('SELECT l.action, l.target, l.at, u.display_name FROM admin_log l LEFT JOIN users u ON u.id = l.user_id
                                   ORDER BY l.id DESC LIMIT 100')->fetchAll();
            out(['ok' => true, 'is_chief' => ts_is_chief(), 'is_primary_admin' => ts_is_chief(), 'me_id' => $uid, 'providers' => $providers,
                'shared_enabled' => ts_shared_enabled(), 'app_keys' => ts_app_keys_state(), 'creds' => ts_admin_creds(), 'editors' => ts_editors_list(),
                'users' => ts_db()->query('SELECT id, display_name, email, password_hash IS NOT NULL AS has_password FROM users ORDER BY display_name')->fetchAll(),
                'cache' => ts_cache_stats(), 'fetch_log' => ts_fetch_log_recent(), 'method_runs' => ts_method_runs(), 'pairs' => ts_pairs_admin(), 'log' => $log, 'alerts' => ts_alerts_admin_stats()]);

        case 'admin_methods':
            out(['ok' => true, 'methods' => ts_admin_methods(), 'costs' => ts_costs(), 'cost_defaults' => TS_COST_DEFAULTS]);

        case 'admin_learn':
        case 'admin_learn_csv':
            // למידה (23.10, L3): לכל העורכים (24.2 — ts_require_admin למעלה, לכל admin_*). user_id בייצוא — רק לעורך הראשי (ל3)
            $lm = (string)($_GET['method'] ?? '');
            if (!ts_method_exists($lm)) throw new TsNotFound('שיטה לא מוכרת');
            $lv = isset($_GET['version']) && $_GET['version'] !== '' ? (int)$_GET['version'] : null;
            if ($action === 'admin_learn') {
                out(['ok' => true] + ts_learn_stats($lm, $lv ?? (int)ts_method($lm)->meta()['version'], (string)($_GET['source'] ?? 'backtest')));
            }
            $src = isset($_GET['source']) && $_GET['source'] !== '' ? (string)$_GET['source'] : null;
            $csv = ts_learn_csv($lm, $lv, $src, ts_is_primary_admin());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="learning-' . preg_replace('/[^a-z0-9-]/', '', $lm) . '-' . gmdate('Y-m-d') . '.csv"');
            echo $csv;
            exit;

        case 'admin_method_save':
            // הגדרות של שיטה קיימת (6.7). בונה השיטות נמחק (21.1, ✅29) — שמירת הגדרה (definition) לא קיימת יותר
            if (array_key_exists('definition', $in)) throw new TsInvalid('בונה השיטות הוסר — שיטה חדשה נכתבת כקוד');
            ts_admin_method_save($uid, $in);
            out(['ok' => true, 'methods' => ts_admin_methods()]);

        // שכלול (18.7, S7): הצעה — כל עורך; "החל" — רק עורך ראשי (נבדק ב-ts_tune_apply)
        case 'admin_tune_list':
            out(['ok' => true, 'proposals' => ts_tune_list($_GET['method'] ?? null), 'can_apply' => ts_is_chief()]);

        case 'admin_tune_propose':
            out(['ok' => true, 'proposal' => ts_tune_propose($uid, $in)]);

        case 'admin_tune_apply':
            out(['ok' => true, 'proposal' => ts_tune_apply($uid, $in['id'] ?? null), 'methods' => ts_admin_methods()]);

        case 'admin_costs_save':
            out(['ok' => true, 'costs' => ts_costs_save($uid, $in['costs'] ?? null)]);

        case 'admin_provider_save':
            $pid = (string)($in['provider'] ?? '');
            if (!isset(TS_PROVIDERS[$pid])) fail('ספק לא מוכר', 404);
            $ov = [];
            foreach (['per_min', 'per_day'] as $k) {
                $v = $in[$k] ?? null;
                if ($v === null || $v === '') continue;
                if (!(is_int($v) || (is_string($v) && ctype_digit($v))) || (int)$v < 1 || (int)$v > 10_000_000) fail('תקרה — מספר שלם חיובי', 422);
                $ov[$k] = (int)$v;
            }
            $enabled = ($in['enabled'] ?? true) === true;
            ts_db()->prepare('INSERT INTO providers (id, enabled, overrides) VALUES (?, ?, ?)
                              ON CONFLICT(id) DO UPDATE SET enabled = excluded.enabled, overrides = excluded.overrides')
                ->execute([$pid, $enabled ? 1 : 0, json_encode($ov)]);
            ts_admin_log($uid, 'provider_save', "$pid enabled=" . ($enabled ? 1 : 0) . ' ' . json_encode($ov));
            out(['ok' => true]);

        case 'admin_shared_switch':
            ts_require_chief();
            $on = ($in['on'] ?? false) === true;
            ts_setting_set('shared_providers', $on ? '1' : '0');
            ts_admin_log($uid, 'shared_switch', $on ? 'on' : 'off');
            out(['ok' => true, 'shared_enabled' => $on]);

        // מפתח האפליקציה (21.3, ✅31): מפתח של עורך שהאפליקציה פונה בו בשביל כולם — רק עורך ראשי מסמן
        case 'admin_app_key':
            ts_require_chief();
            ts_app_key_set($in['provider'] ?? null, $in['cred_id'] ?? null, $in['user_limit'] ?? null);
            ts_admin_log($uid, 'app_key', (string)($in['provider'] ?? '') . ' cred:' . (string)($in['cred_id'] ?? '-')
                . (isset($in['user_limit']) ? ' limit:' . (string)$in['user_limit'] : ''));
            out(['ok' => true, 'app_keys' => ts_app_keys_state(), 'creds' => ts_admin_creds()]);

        case 'admin_cred_save':
            $cid = ts_cred_save($uid, $in['provider'] ?? null, $in['secret'] ?? null, $in['label'] ?? '', 'admin');
            ts_admin_log($uid, 'cred_save', "cred:$cid " . (string)($in['provider'] ?? ''));
            out(['ok' => true, 'creds' => ts_admin_creds()]);

        case 'admin_cred_test':
            ts_cred_test_row(ts_admin_cred_row($id));
            out(['ok' => true, 'creds' => ts_admin_creds()]);

        case 'admin_cred_delete':
            ts_admin_cred_delete($id);
            ts_admin_log($uid, 'cred_delete', "cred:$id");
            out(['ok' => true, 'creds' => ts_admin_creds()]);

        case 'admin_grant_save':
            ts_grant_set((int)($in['cred_id'] ?? 0), $in['user_id'] ?? null, $in['daily_limit'] ?? null);
            ts_admin_log($uid, 'grant_save', 'cred:' . (int)($in['cred_id'] ?? 0) . ' user:' . (int)($in['user_id'] ?? 0));
            out(['ok' => true, 'creds' => ts_admin_creds()]);

        case 'admin_grant_delete':
            ts_grant_delete((int)($in['cred_id'] ?? 0), (int)($in['user_id'] ?? 0));
            ts_admin_log($uid, 'grant_delete', 'cred:' . (int)($in['cred_id'] ?? 0) . ' user:' . (int)($in['user_id'] ?? 0));
            out(['ok' => true, 'creds' => ts_admin_creds()]);

        // עורכים (24.3, ר1): מינוי ושינוי תפקיד — role: editor (ברירת המחדל) / chief. רק עורך ראשי (נבדק ב-ts_role_grant)
        case 'admin_role_add':
            ts_role_grant($uid, (int)($in['user_id'] ?? 0), $in['role'] ?? 'editor');
            out(['ok' => true, 'editors' => ts_editors_list()]);

        case 'admin_password_reset':
            ts_password_reset($uid, (int)($in['user_id'] ?? 0));
            out(['ok' => true]);

        case 'admin_role_remove':
            ts_role_revoke($uid, (int)($in['user_id'] ?? 0));
            out(['ok' => true, 'editors' => ts_editors_list()]);

        case 'admin_cache_clear':
            $pv = isset($in['provider']) && is_string($in['provider']) && isset(TS_PROVIDERS[$in['provider']]) ? $in['provider'] : null;
            $n = ts_cache_clear($pv);
            ts_admin_log($uid, 'cache_clear', ($pv ?? 'all') . " candles=$n");
            out(['ok' => true, 'deleted' => $n, 'cache' => ts_cache_stats()]);

        case 'admin_cache_cleanup':
            $n = ts_cache_cleanup();
            ts_admin_log($uid, 'cache_cleanup', "candles=$n");
            out(['ok' => true, 'deleted' => $n, 'cache' => ts_cache_stats()]);

        case 'export':
            $date = gmdate('Y-m-d');
            if (($_GET['format'] ?? '') === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"trading-journal-$date.csv\"");
                echo ts_export_csv($uid, $user);
            } else {
                header('Content-Type: application/json; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"trading-strategies-$date.json\"");
                echo json_encode(ts_export_json($uid, $user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            exit;

        default:
            fail('פעולה לא מוכרת', 404);
    }
} catch (TsForbidden $e) {
    devloop_log_error($e);
    fail($e->getMessage(), 403);
} catch (TsProviderError $e) {
    devloop_log_error($e);
    fail($e->getMessage(), 502);   // ההודעה כבר בלי המפתח (lib/market.php)
} catch (TsInvalid $e) {
    devloop_log_error($e);
    fail($e->getMessage(), 422);
} catch (TsNotFound $e) {
    devloop_log_error($e);
    fail($e->getMessage(), 404);
} catch (Throwable $e) {
    ts_server_error($e, $action, $method);
}
