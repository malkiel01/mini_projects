<?php
// בדיקות "🔮 תחזית שיטה" וחוזה ה-overlay (spec-stage-a 17.2.2, 17.2.3, 17.2.5; ✅15, ✅13; PR 4ב/2).
// php trading-strategies/tests/forecast-api.php — חלק טהור, ואחריו php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-forecast-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── חלק טהור: החוזה (17.2.3) וברירת המחדל של TsMethod::overlay ──
define('TS_DB_PATH', "$tmp.pure.sqlite");   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/overlay.php';
$H = 3600;
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$m = ts_method('sample-ma-cross');
$P = $m->params();
$withSc = 0; $empty = 0; $bad = 0; $aligned = true; $noCert = true;
foreach (range(60, 4999, 41) as $i) {
    $part = array_slice($F, 0, $i + 1);
    try { $o = ts_overlay_check($m->overlay($part, $P, $H), $H); } catch (TsOverlayInvalid) { $bad++; continue; }
    $o['items'] ? $withSc++ : $empty++;
    foreach ($o['projection']['scenarios'] as $s) {
        foreach ($s['points'] as $pt) if ($pt['t'] > $o['as_of'] && ($pt['t'] - $o['as_of']) % $H !== 0) $aligned = false;
        if ($s['score'] !== null) $noCert = false;
    }
    if ($o['as_of'] !== end($part)['t']) $aligned = false;
}
eq([$bad, $withSc > 10, $empty > 5], [0, true, true], "sample-ma-cross overlay passes the contract on every window ($withSc with a scenario, $empty without)");
eq([$aligned, $noCert], [true, true], 'future times = t + k·tf; as_of = the last candle; no score (a code method without counts)');
// בלי הצצה: ה-overlay על [0..i] לא תלוי בנרות שאחרי i
$o1 = $m->overlay(array_slice($F, 0, 1301), $P, $H);
eq(json_encode($o1), json_encode($m->overlay(array_slice($F, 0, 1301), $P, $H)), 'determinism: the same overlay twice');
$past = array_filter($o1['items'], fn($x) => isset($x['t']) && $x['t'] > $o1['as_of']);
eq($past, [], 'no item sits on a future candle except the projection (markers/labels only on closed candles)');
$kinds = array_column($o1['items'], 'kind');
eq($kinds, ['marker', 'hline', 'hline', 'zone', 'polyline'], 'an active signal → marker, stop and exit lines, zone, dashed projection');
eq(array_column(array_filter($o1['items'], fn($x) => $x['kind'] === 'hline'), 'role'), ['stop', 'target'], 'hline roles from the contract');
$sig = $m->analyze(array_slice($F, 0, 1301), $P);
eq([$o1['items'][1]['price'], $o1['items'][2]['price']], [(float)$sig['levels']['stop'], (float)$sig['levels']['target']], 'the lines are the levels of the active signal (same as the analysis card)');
eq($o1['projection']['bars'], 50, 'projection.bars = the method horizon (forecast_bars not set)');
eq(preg_match('/קנה|מכור|המלצ|כדאי|\bיעד\b|%/u', json_encode($o1, JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend/target" and no percentage in the overlay (6.6, ✅15)');
// החוזה דוחה פריטים לא תקינים
$base = ['as_of' => 1000 * $H, 'range' => ['from' => 0, 'to' => 1000 * $H], 'items' => [], 'projection' => ['bars' => 10, 'scenarios' => []]];
$rej = 0;
foreach ([
    ['kind' => 'script', 'text' => 'x'],
    ['kind' => 'label', 't' => 1000 * $H + 1800, 'price' => 1.0, 'text' => 'x'],            // לא מיושר
    ['kind' => 'label', 't' => 1011 * $H, 'price' => 1.0, 'text' => 'x'],                  // מעבר ל-bars
    ['kind' => 'polyline', 'points' => [['t' => 0, 'price' => 1.0]], 'style' => 'solid'],  // נקודה אחת
    ['kind' => 'polyline', 'points' => [['t' => 0, 'price' => 1.0], ['t' => $H, 'price' => 2.0]], 'style' => 'wavy'],
    ['kind' => 'hline', 'price' => 1.0, 'role' => 'buy', 'text' => 'x'],
    ['kind' => 'hline', 'price' => NAN, 'role' => 'stop', 'text' => 'x'],
    ['kind' => 'zone', 't1' => 2 * $H, 't2' => $H, 'p1' => 1.0, 'p2' => 2.0],
    ['kind' => 'marker', 't' => 0, 'side' => 'up', 'text' => 'x'],
    ['kind' => 'label', 't' => 0, 'price' => 1.0, 'text' => str_repeat('א', 201)],
] as $it) {
    try { ts_overlay_check(['items' => [$it]] + $base, $H); } catch (TsOverlayInvalid) { $rej++; }
}
eq($rej, 10, 'the contract rejects: unknown kind, unaligned or too-far future time, short polyline, unknown style/role/side, NaN, reversed zone, long text');
eq(ts_overlay_check(['items' => [['kind' => 'label', 't' => 1010 * $H, 'price' => 1.0, 'text' => 'x']]] + $base, $H)['items'][0]['t'], 1010 * $H, 'a future time exactly bars × tf ahead is allowed');

// ── API ──────────────────────────────────────────────────
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;   // "עכשיו" קבוע (X-Dev-Now) — אותם נרות מדומים בכל ריצה
function call(string $as, string $action, ?array $body = null, array $query = [], array $extra = []): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", "X-Dev-Now: $NOW", ...$extra];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$S = 'sample-ma-cross';
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'method' => $S, 'from' => $NOW - 30 * 86400, 'to' => $NOW];

eq(call('owner', 'method_overlay', null, $q)[0], 404, 'method off → 404 even for the admin');
$mset = ['id' => $S, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4', 'd1'], 'params' => [], 'note' => ''];
eq(call('owner', 'admin_method_save', $mset)[0], 200, 'the admin turns the sample method on');
eq(call('alice', 'method_overlay', null, $q)[0], 404, 'a regular user cannot run the sample method by id (shown to admins only)');
$btBefore = (int)$db->query('SELECT COUNT(*) FROM backtests')->fetchColumn();
[$c, $r] = call('owner', 'method_overlay', null, $q);
$ov = $r['overlay'] ?? [];
eq([$c, $r['status'] ?? null, $r['title'] ?? null, $r['basis'] ?? null], [200, 'ok', 'תרחיש לפי שיטת חציית ממוצעים 20/50 — לא ייעוץ', 'לפי כללי השיטה, לא נבדק לאחור'], 'the forecast: title "תרחיש לפי שיטת X — לא ייעוץ", marked "not backtested" (✅15)');
eq($r['disclaimer'], 'ניתוח אוטומטי לפי כללי שיטות מסחר וביצועי עבר. אינו ייעוץ השקעות ואינו מתחשב בנתונים האישיים שלך. ביצועי עבר אינם מבטיחים תוצאות בעתיד.', 'the fixed disclaimer (6.6)');
eq([array_key_exists('certainty', $r), array_key_exists('blend', $r), $r['backtested']], [false, false, false], 'no certainty, no blending — and "not backtested" when there is no stored backtest');
eq([$r['method']['label'], $r['method']['sample']], ['🧪 שיטת דוגמה — לא מהספר', true], 'the sample label travels with the forecast');
eq([array_keys($ov), $ov['as_of'] + 3600 <= $NOW, $ov['as_of'] + 7200 > $NOW, $ov['range']['from'] >= $q['from']], [['as_of', 'range', 'items', 'projection'], true, true, true], 'overlay contract keys; only closed candles (as_of = last closed hour); the range shown');
$fut = [];
foreach ($ov['items'] as $it) foreach (array_merge([$it['t'] ?? null, $it['t1'] ?? null, $it['t2'] ?? null], array_column($it['points'] ?? [], 't')) as $t) if ($t !== null && $t > $ov['as_of']) $fut[] = ($t - $ov['as_of']) % 3600;
eq([count($fut) > 0, array_unique($fut)], [true, [0]], 'future times aligned to the timeframe (t + k·tf, 17.2.2)');
eq((int)$db->query('SELECT COUNT(*) FROM backtests')->fetchColumn(), $btBefore, 'the forecast does not run or store a backtest');
// "נבדקה לאחור" — אחרי שיש תוצאה שמורה (מהניתוח)
call('owner', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $S]);
eq(call('owner', 'method_overlay', null, $q)[1]['backtested'], true, 'after the analysis stored a backtest → backtested: true (the "not backtested" mark goes away)');
eq(call('owner', 'method_overlay', null, ['tf' => 'h4'] + $q)[1]['backtested'], false, 'other timeframe → still not backtested');
// מסגרת או שוק שהשיטה לא מוגדרת להם
[$c, $r] = call('owner', 'method_overlay', null, ['tf' => 'm15'] + $q);
eq([$c, $r['status'], $r['overlay']], [200, 'unsupported', null], 'a timeframe the method is not set for → unsupported, no overlay');
// קלט לא תקין
eq([call('owner', 'method_overlay', null, ['asset' => 'nope'] + $q)[0], call('owner', 'method_overlay', null, ['tf' => 'x9'] + $q)[0], call('owner', 'method_overlay', null, ['method' => 'zzz'] + $q)[0]], [422, 422, 404], 'bad asset / timeframe → 422; unknown method → 404');
// שיטה שאינה דוגמה — למשתמש רגיל; ובידוד: אין בה נתונים של משתמש אחר
call('owner', 'admin_method_save', ['id' => 'test-cross-a'] + $mset);
[$c, $ra] = call('alice', 'method_overlay', null, ['method' => 'test-cross-a'] + $q);
[$c2, $rb] = call('bob', 'method_overlay', null, ['method' => 'test-cross-a'] + $q);
eq([$c, $ra['status'], $c2, json_encode($ra['overlay']) === json_encode($rb['overlay'])], [200, 'ok', 200, true], 'an enabled non-sample method → regular users get the same forecast for the same shared candles');
eq(preg_match('/קנה|מכור|המלצ|כדאי/u', json_encode($ra, JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend" wording (6.6)');

// ── תקרת 30 לדקה (✅3): התחזית נספרת ─────────────────────
if ((int)date('s') > 45) sleep(61 - (int)date('s'));
$codes = [];
for ($i = 0; $i < 31; $i++) $codes[] = call('dave', 'method_overlay', null, $q)[0];
eq(array_count_values($codes), [404 => 30, 429 => 1], 'method_overlay counts in the 30-per-minute data limit');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
