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
 * GET  ?action=market_candles&asset=&tf=&from=&to=   נרות, מהמאגר ומהספק על החסר ← נספר בתקרת 30 לדקה
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
 * GET  ?action=methods                   השיטות שאני רואה (מופעלות; שיטת דוגמה — רק למנהל)
 * GET  ?action=analysis&asset=&tf=&methods=a,b   כרטיס לכל שיטה + שקלול. בדיקה לאחור לפי דרישה ← נספר בתקרת 30 לדקה
 *
 * התראות (פרק 8) — הבדיקה עצמה רק ב-cron (bin/alerts-cron.php, CLI); כאן רק ניהול ותיבה:
 * GET  ?action=alerts                    ההתראות שלי והשיטות שמותרות בהן
 * GET  ?action=inbox | inbox_peek        התיבה; האחרונה שלא נקראה (ל-Service Worker כשמגיע פוש)
 * GET  ?action=push_key                  המפתח הציבורי של VAPID
 * POST action=alert_save | alert_status | alert_delete | inbox_read | push_subscribe | push_unsubscribe
 *
 * מנהל (1.1) — ts_require_admin() בשרת לפני כל אחת:
 * GET  ?action=admin_state | admin_methods
 * POST action=admin_provider_save | admin_shared_switch (ראשי) | admin_cred_save | admin_cred_test | admin_cred_delete
 *      | admin_grant_save | admin_grant_delete | admin_role_add (ראשי) | admin_role_remove (ראשי)
 *      | admin_cache_clear | admin_cache_cleanup | admin_method_save | admin_costs_save
 *
 * כל בקשה משנה: POST, גוף JSON, והכותרת X-CSRF עם האסימון מ-me (אפיון פרק 6). הזהות — רק מהשער
 * (lib/auth.php); המזהה של המשתמש לא מגיע מהדפדפן אף פעם.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/market.php';
require_once __DIR__ . '/lib/drawings.php';
require_once __DIR__ . '/lib/analysis.php';
require_once __DIR__ . '/lib/alerts.php';
require_once __DIR__ . '/lib/fx.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

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

function input(): array
{
    static $in = null;
    if ($in === null) {
        $raw = (string)file_get_contents('php://input', false, null, 0, 1_000_001);
        if (strlen($raw) > 1_000_000) fail('הבקשה גדולה מדי', 413);
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    return $in;
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $session = ts_session();
} catch (Throwable $e) {
    error_log('trading-strategies session: ' . $e->getMessage());
    fail('תקלה זמנית במסד — נסו שוב בעוד רגע', 503);
}
if (!$session) fail('אין לך הרשאת גישה לאפליקציה הזו — פנו למנהל', 401);
[$user, $csrf] = $session;
$uid = (int)$user['id'];
$tz = $user['timezone'];

const TS_WRITE = ['settings', 'strategy_save', 'strategy_duplicate', 'strategy_status', 'strategy_delete',
    'trade_save', 'trade_delete', 'instrument_save', 'instrument_delete',
    'drawing_save', 'drawing_delete', 'profile_save', 'admin_method_save', 'admin_costs_save',
    'alert_save', 'alert_status', 'alert_delete', 'inbox_read', 'push_subscribe', 'push_unsubscribe',
    'watchlist_add', 'watchlist_remove', 'watchlist_order', 'symbol_pref', 'cred_save', 'cred_test', 'cred_delete',
    'admin_provider_save', 'admin_shared_switch', 'admin_cred_save', 'admin_cred_test', 'admin_cred_delete',
    'admin_grant_save', 'admin_grant_delete', 'admin_role_add', 'admin_role_remove', 'admin_cache_clear', 'admin_cache_cleanup'];
// בקשות שפונות (או עלולות לפנות) לספק — בתקרה של 30 לדקה למשתמש (3.5, ✅3)
const TS_DATA = ['market_search', 'market_candles', 'analysis', 'cred_save', 'cred_test', 'admin_cred_save', 'admin_cred_test'];

if (in_array($action, TS_WRITE, true)) {
    if ($method !== 'POST') fail('נדרש POST', 405);
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) fail('נדרש JSON', 415);
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('פג תוקף הדף — רעננו ונסו שוב', 403);
}

$in = in_array($action, TS_WRITE, true) ? input() : [];
$id = (int)($in['id'] ?? $_GET['id'] ?? 0);
if (str_starts_with($action, 'admin_')) {
    try {
        ts_require_admin($uid);
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
                'is_admin' => ts_is_admin($uid), 'is_primary_admin' => ts_is_primary_admin(), 'lists' => [
                'markets' => TS_MARKETS, 'timeframes' => TS_TIMEFRAMES, 'styles' => TS_STYLES, 'statuses' => TS_STATUSES,
                'moods' => TS_MOODS, 'plan' => TS_PLAN,
            ], 'profile' => ts_profile($uid), 'horizon_tfs' => TS_HORIZON_TFS, 'risk_defaults' => TS_RISK_DEFAULTS,
                'disclaimer' => TS_ANALYSIS_DISCLAIMER, 'unread' => ts_inbox_unread($uid)]);

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
            $methodNames = [];
            foreach (array_keys(ts_methods_all()) as $mid) $methodNames[$mid] = ts_method($mid)->meta()['name'];
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
        case 'market_providers':
            out(['ok' => true, 'providers' => ts_providers_for($uid), 'shared_enabled' => ts_shared_enabled()]);

        case 'market_search':
            out(['ok' => true] + ts_market_search($uid, $_GET['q'] ?? ''));

        case 'market_candles':
            out(['ok' => true] + ts_market_candles($uid, $_GET['asset'] ?? '', $_GET['tf'] ?? '', $_GET['from'] ?? null, $_GET['to'] ?? null));

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

        // ── מנהל (ts_require_admin כבר נבדק למעלה, לכל admin_*) ──
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
            out(['ok' => true, 'is_primary_admin' => ts_is_primary_admin(), 'providers' => $providers,
                'shared_enabled' => ts_shared_enabled(), 'creds' => ts_admin_creds(), 'admins' => ts_admins_list(),
                'users' => ts_db()->query('SELECT id, display_name, email FROM users ORDER BY display_name')->fetchAll(),
                'cache' => ts_cache_stats(), 'log' => $log, 'alerts' => ts_alerts_admin_stats()]);

        case 'admin_methods':
            out(['ok' => true, 'methods' => ts_admin_methods(), 'costs' => ts_costs(), 'cost_defaults' => TS_COST_DEFAULTS]);

        case 'admin_method_save':
            ts_admin_method_save($uid, $in);
            out(['ok' => true, 'methods' => ts_admin_methods()]);

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
            ts_require_primary_admin();
            $on = ($in['on'] ?? false) === true;
            ts_setting_set('shared_providers', $on ? '1' : '0');
            ts_admin_log($uid, 'shared_switch', $on ? 'on' : 'off');
            out(['ok' => true, 'shared_enabled' => $on]);

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

        case 'admin_role_add':
            ts_admin_grant($uid, (int)($in['user_id'] ?? 0));
            out(['ok' => true, 'admins' => ts_admins_list()]);

        case 'admin_role_remove':
            ts_admin_revoke($uid, (int)($in['user_id'] ?? 0));
            out(['ok' => true, 'admins' => ts_admins_list()]);

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
    fail($e->getMessage(), 403);
} catch (TsProviderError $e) {
    fail($e->getMessage(), 502);   // ההודעה כבר בלי המפתח (lib/market.php)
} catch (TsInvalid $e) {
    fail($e->getMessage(), 422);
} catch (TsNotFound $e) {
    fail($e->getMessage(), 404);
} catch (Throwable $e) {
    error_log('trading-strategies ' . $action . ': ' . $e->getMessage());
    fail('תקלה בשרת — הפעולה לא נשמרה', 500);
}
