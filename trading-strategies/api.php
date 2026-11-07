<?php
/**
 * ה-API של "אסטרטגיות למסחר". כל תשובה JSON, חוץ מהייצוא (קובץ).
 *
 * GET  ?action=me                         המשתמש, ההגדרות, אסימון ה-CSRF והרשימות הסגורות (שווקים, מסגרות זמן…)
 * GET  ?action=strategies                 רשימה עם סיכום (אפיון 2.1)
 * GET  ?action=strategy&id=               אסטרטגיה וכל הגרסאות שלה (2.2)
 * GET  ?action=trades&from&to&strategy_id&version_id&market&symbol&result&plan    היומן (2.4)
 * GET  ?action=trade&id=                  עסקה (2.5)
 * GET  ?action=stats&strategy_id&version_id&market&from&to   הסטטיסטיקה (2.6), לפי תאריך סגירה
 * GET  ?action=instruments                "נכסים שלי" (3.2)
 * GET  ?action=export&format=json|csv     ייצוא מלא / היומן (2.7)
 * POST action=settings | strategy_save | strategy_duplicate | strategy_status | strategy_delete
 *      | trade_save | trade_delete | instrument_save | instrument_delete
 *
 * כל בקשה משנה: POST, גוף JSON, והכותרת X-CSRF עם האסימון מ-me (אפיון פרק 6). הזהות — רק מהשער
 * (lib/auth.php); המזהה של המשתמש לא מגיע מהדפדפן אף פעם.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';

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
    'trade_save', 'trade_delete', 'instrument_save', 'instrument_delete'];

if (in_array($action, TS_WRITE, true)) {
    if ($method !== 'POST') fail('נדרש POST', 405);
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) fail('נדרש JSON', 415);
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('פג תוקף הדף — רעננו ונסו שוב', 403);
}

$in = in_array($action, TS_WRITE, true) ? input() : [];
$id = (int)($in['id'] ?? $_GET['id'] ?? 0);

/** מסננים מה-query string, רק המוכרים */
function filters(): array
{
    $f = [];
    foreach (['from', 'to', 'strategy_id', 'version_id', 'market', 'symbol', 'result', 'plan', 'currency'] as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') $f[$k] = mb_substr($_GET[$k], 0, 40);
    }
    return $f;
}

try {
    switch ($action) {
        case 'me':
            out(['ok' => true, 'csrf' => $csrf, 'user' => ts_settings_out($user), 'lists' => [
                'markets' => TS_MARKETS, 'timeframes' => TS_TIMEFRAMES, 'styles' => TS_STYLES, 'statuses' => TS_STATUSES,
                'moods' => TS_MOODS, 'plan' => TS_PLAN,
            ]]);

        case 'settings':
            ts_settings_save($uid, $in);
            out(['ok' => true]);

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
            out(['ok' => true, 'by_currency' => ts_stats(array_values($closed), $tz), 'no_stop' => $noStop]);

        case 'instruments':
            out(['ok' => true, 'instruments' => ts_instruments_list($uid)]);

        case 'instrument_save':
            ts_instrument_save($uid, $in, $tz);
            out(['ok' => true]);

        case 'instrument_delete':
            ts_instrument_delete($uid, $id);
            out(['ok' => true]);

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
} catch (TsInvalid $e) {
    fail($e->getMessage(), 422);
} catch (TsNotFound $e) {
    fail($e->getMessage(), 404);
} catch (Throwable $e) {
    error_log('trading-strategies ' . $action . ': ' . $e->getMessage());
    fail('תקלה בשרת — הפעולה לא נשמרה', 500);
}
