<?php
// בדיקות יחידה לשלב א' — ספקים, זמנים, מפתחות, מאגר (spec-stage-a פרק 15: זמנים, מפתחות, מאגר ורישוי).
// php trading-strategies/tests/market.php — בלי רשת: הספקים עונים מ-tests/fixtures/market.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-market-unit-' . getmypid();
define('TS_DEV', true);
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
define('TS_SECRET_KEY_PATH', "$tmp.key");
putenv('TS_HTTP_FIXTURES=' . __DIR__ . '/fixtures/market');
putenv("TS_HTTP_LOG=$tmp.http.log");
register_shutdown_function(fn() => array_map('unlink', glob("$tmp*") ?: []));

require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/market.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function calls(): int { global $tmp; return is_file("$tmp.http.log") ? count(file("$tmp.http.log")) : 0; }

// ── זמנים: t = זמן פתיחה בשניות UTC (פרק 15, "זמנים") ──────────
$h0 = gmmktime(10, 0, 0, 3, 2, 2026);
$c = ts_provider('binance')->candles('BTCUSDT', 'h1', $h0, $h0 + 3 * 3600, null);
eq(array_column($c, 't'), [$h0, $h0 + 3600, $h0 + 7200, $h0 + 10800], 'binance: ms → seconds, aligned to the hour');
eq([is_string($c[0]['o']), (bool)preg_match('/^\d+\.10000000$/', $c[0]['o']), (bool)preg_match('/^\d+\.50000000$/', $c[0]['c'])], [true, true, true], 'binance: prices as strings, exactly as given');
$d = ts_provider('binance')->candles('BTCUSDT', 'd1', gmmktime(0, 0, 0, 3, 1, 2026), gmmktime(23, 0, 0, 3, 3, 2026), null);
eq(array_map(fn($r) => gmdate('Y-m-d H:i', $r['t']), $d), ['2026-03-01 00:00', '2026-03-02 00:00', '2026-03-03 00:00'], 'crypto daily boundary 00:00 UTC');

$td = ts_provider('twelvedata')->candles('AAPL', 'd1', gmmktime(0, 0, 0, 3, 2, 2026), gmmktime(23, 0, 0, 3, 2, 2026), 'good-key-0001');
eq(gmdate('Y-m-d H:i', $td[0]['t']), '2026-03-02 05:00', 'twelvedata daily = midnight New York (winter) in UTC');
$td = ts_provider('twelvedata')->candles('AAPL', 'd1', gmmktime(0, 0, 0, 7, 1, 2026), gmmktime(23, 0, 0, 7, 1, 2026), 'good-key-0001');
eq(gmdate('Y-m-d H:i', $td[0]['t']), '2026-07-01 04:00', 'twelvedata daily = midnight New York (summer) in UTC');
$th = ts_provider('twelvedata')->candles('AAPL', 'h1', $h0, $h0 + 3600, 'good-key-0001');
eq(array_column($th, 't'), [$h0, $h0 + 3600], 'twelvedata intraday = UTC datetime');

$fh = ts_provider('finnhub')->candles('OANDA:EUR_USD', 'd1', 1772400000, 1772600000, 'good-key-0002');
eq(array_column($fh, 't'), [1772460000, 1772546400], 'finnhub t passthrough');
eq([$fh[0]['o'], $fh[1]['c']], ['1.08345', '1.08499'], 'finnhub float → exact shortest string');

$ecb = ts_provider('ecb')->candles('USDILS', 'd1', gmmktime(0, 0, 0, 3, 2, 2026), gmmktime(0, 0, 0, 3, 4, 2026), null);
eq(array_map(fn($r) => [gmdate('Y-m-d H:i', $r['t']), $r['c'], $r['o'] === $r['c']], $ecb),
    [['2026-03-02 00:00', '3.6512', true], ['2026-03-03 00:00', '3.6408', true], ['2026-03-04 00:00', '3.65', true]], 'ECB: date → 00:00 UTC, one rate');

eq(array_map([TsProvider::class, 'floatStr'], [3.1259, 1.5e-5, 123456.12345678, 2.0, -0.07, 1.0E+21]),
    ['3.1259', '0.000015', '123456.12345678', '2', '-0.07', '1000000000000000000000'], 'float → decimal string');

// ── מפתחות (פרק 15, "מפתחות") ────────────────────────────
$key = 'secretKEY-1234567890-abcd';
$box = ts_encrypt($key);
eq(str_contains($box, 'secretKEY'), false, 'encrypted at rest');
eq(ts_decrypt($box), $key, 'decrypt round-trip');
eq(ts_last4($key), 'abcd', 'last4');
$dirty = "error for $key; partial KEY-12345 and 7890-abcd and 0-abcd and abcd";
$clean = ts_scrub($dirty, [$key]);
foreach ([$key, 'KEY-12345', '7890-abcd', '0-abcd'] as $part) eq(str_contains($clean, $part), false, "scrub removes «{$part}»");
eq(str_ends_with($clean, ' abcd'), true, 'last 4 alone may remain');
try {
    ts_provider('twelvedata')->candles('AAPL', 'd1', $h0, $h0, 'bad-key-ZZZZYYYYXXXX');
    eq(true, false, 'bad key should throw');
} catch (TsProviderError $e) {
    eq(str_contains($e->getMessage(), 'ZZZZYYYY'), false, 'provider error that echoes the key is scrubbed');
    eq(str_contains($e->getMessage(), 'המפתח נדחה'), true, 'clear message');
}
$log = (string)file_get_contents("$tmp.http.log");
eq(str_contains(implode("\n", array_map(fn($l) => json_decode($l, true)['url'], file("$tmp.http.log"))), 'good-key'), false, 'key never in a URL (header only)');

// ── טווחים ──────────────────────────────────────────────
eq(ts_range_subtract(0, 100, []), [[0, 100]], 'subtract: nothing covered');
eq(ts_range_subtract(0, 100, [[10, 20], [50, 200]]), [[0, 9], [21, 49]], 'subtract: holes');
eq(ts_range_subtract(0, 100, [[-5, 100]]), [], 'subtract: fully covered');

// ── מאגר: פנייה רק על החסר, זנב בתוקף, ניקוי 60 יום, הפרדה לפי scope ──
$db = ts_db();
$db->prepare("INSERT INTO users (platform_sub, display_name, created_at) VALUES ('1', 'A', 0), ('2', 'B', 0)")->execute();
$now = time();
$before = calls();
$r = ts_market_candles(1, 'binance:BTCUSDT', 'h1', $now - 48 * 3600, $now, $now);
eq([$r['provider_calls'], calls() - $before > 0], [1, true], 'first request goes to the provider');
$r2 = ts_market_candles(2, 'binance:BTCUSDT', 'h1', $now - 48 * 3600, $now + 30, $now + 30);
eq($r2['provider_calls'], 0, 'second user, same keyless asset, within TTL → shared pool, no provider call');
eq(count($r2['candles']), count($r['candles']), 'same candles');
$r3 = ts_market_candles(2, 'binance:BTCUSDT', 'h1', $now - 48 * 3600, $now + 400, $now + 400);
eq($r3['provider_calls'], 1, 'after tail TTL → one call, only for the tail');
$r4 = ts_market_candles(1, 'binance:BTCUSDT', 'h1', $now - 72 * 3600, $now + 400, $now + 400);
eq($r4['provider_calls'], 1, 'older range not covered → one call for the missing part only');

// נתונים ישנים מ-60 יום — נמשכים לבקשה, לא נשמרים
$old = ts_market_candles(1, 'binance:BTCUSDT', 'd1', $now - 90 * 86400, $now, $now);
$st = ts_cache()->prepare("SELECT MIN(t) FROM candles WHERE provider = 'binance' AND tf = 'd1'");
$st->execute();
eq((int)$st->fetchColumn() >= $now - 60 * 86400 - 86400, true, 'history older than 60 days not stored');
eq(count($old['candles']) >= 89, true, 'but returned to the request');

// ניקוי
ts_cache()->prepare("INSERT INTO candles (provider, symbol, tf, t, o, h, l, c, v, fetched_at, cred_scope) VALUES ('binance', 'OLD', 'h1', ?, '1', '1', '1', '1', '1', 0, '')")
    ->execute([$now - 61 * 86400]);
$deleted = ts_cache_cleanup($now);
$st = ts_cache()->prepare("SELECT COUNT(*) FROM candles WHERE symbol = 'OLD'");
$st->execute();
eq([(int)$st->fetchColumn(), $deleted >= 1], [0, true], 'cleanup deletes candles older than 60 days');
$st = ts_cache()->prepare("SELECT COUNT(*) FROM candles WHERE symbol = 'BTCUSDT' AND tf = 'h1'");
$st->execute();
eq((int)$st->fetchColumn() > 0, true, 'cleanup keeps recent candles');

// מפתח אישי → scope של המשתמש; המשתמש השני לא מקבל אותם
ts_cred_save(1, 'twelvedata', 'good-alice-0001', 'א');
$a = ts_market_candles(1, 'twelvedata:AAPL', 'h1', $now - 10 * 3600, $now, $now);
eq([$a['provider_calls'], $a['source']], [1, 'own'], 'A fetches with her key');
try {
    ts_market_candles(2, 'twelvedata:AAPL', 'h1', $now - 10 * 3600, $now, $now);
    eq(true, false, 'B without key must not get A\'s candles');
} catch (TsInvalid $e) {
    eq(str_contains($e->getMessage(), 'אין לכם חיבור'), true, 'B without key → no access');
}
ts_cred_save(2, 'twelvedata', 'good-bob-0002', 'ב');
$b = ts_market_candles(2, 'twelvedata:AAPL', 'h1', $now - 10 * 3600, $now, $now);
eq($b['provider_calls'], 1, 'B with own key → own fetch, not A\'s pool');
$st = ts_cache()->query("SELECT DISTINCT cred_scope FROM candles WHERE provider = 'twelvedata' ORDER BY cred_scope");
eq($st->fetchAll(PDO::FETCH_COLUMN), ['u:1', 'u:2'], 'personal pools are separate (cred_scope)');
ts_cred_delete_mine(1, 'twelvedata');
$st = ts_cache()->query("SELECT COUNT(*) FROM candles WHERE cred_scope = 'u:1'");
eq((int)$st->fetchColumn(), 0, 'deleting a key deletes what was fetched with it');

// ספק (ב): המתג הראשי כבוי → השיוך לא נותן גישה
$cid = ts_cred_save(0, 'finnhub', 'good-admin-0009', 'משותף', 'admin');
ts_grant_set($cid, 2, 10);
eq(ts_access(2, 'finnhub'), null, 'master switch off → granted provider not available');
ts_setting_set('shared_providers', '1');
eq(ts_access(2, 'finnhub')['source'] ?? null, 'shared', 'master switch on → available');
ts_setting_set('shared_providers', '0');
eq(ts_access(2, 'finnhub'), null, 'switch off again → gone immediately');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
