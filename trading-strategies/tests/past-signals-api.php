<?php
// H1 דרך ה-API (spec-stage-a 18.1.8): chart_refresh מחזיר לכל שיטה `history` — אותות העבר על נרות הגרף (אותו מנוע). קריטריון 1 (אליוט
// ב-BTC/USDT, שעה: "∅" בהווה, ואותות עבר), 7 (המתג בפרופיל — נשמר), 10 (רק תצוגה: אותו שקלול, 0 שורות ב-method_outcomes).
// php trading-strategies/tests/past-signals-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-past-signals-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
// פורט פנוי מהמערכת ושם ייחודי — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת או קבצים של ריצה אחרת (#1333)
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_KLINES_SHAPE' => 'wave', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
$jar = []; $tokens = []; $bodies = [];
function call(string $as, string $action, ?array $body = null, array $query = [], array $extra = []): array
{
    global $port, $jar, $tokens, $bodies;
    global $NOW;
    $h = ["X-Dev-As: $as", ...$extra];
    if (!array_filter($extra, fn($x) => str_starts_with($x, 'X-Dev-Now:'))) $h[] = "X-Dev-Now: $NOW";
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    $bodies[] = $raw;
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob', 'carol', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$cache = new PDO('sqlite:' . "$tmp.sqlite.cache");
$uidOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
// "עכשיו" שבו לאליוט אין ספירה נוכחית בסט הגלים (TS_KLINES_SHAPE=wave — הסט של הגרף, F1 קריטריון 8): ∅ בהווה
$NOW = 1794342600;
eq(call('owner', 'admin_method_save', ['id' => 'sample-ma-cross', 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'], 'params' => [], 'note' => ''])[0], 200, 'the sample method on');
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $NOW - 90 * 86400, 'to' => $NOW];
$refresh = fn(string $as, string $methods) => call($as, 'chart_refresh', null, $q + ['methods' => $methods])[1];

// ── קריטריון 1: אליוט, BTC/USDT, שעה, 3 חודשים ──
$r = $refresh('alice', 'elliott-w3');
$E = $r['methods']['elliott-w3'];
$now = !($E['overlay']['items'] ?? []) && !($E['forecast']['items'] ?? []) && !($E['forecast']['scenarios'] ?? []);
eq([$E['status'], is_array($E['history'] ?? null)], ['ok', true], 'chart_refresh: every method carries `history`');
eq([$now, $E['history']['n'] >= 1], [true, true], "criterion 1: Elliott on BTC/USDT 1h — nothing in the present (∅), and past signals in the chart ({$E['history']['n']})");
$it = $E['history']['items'][0] ?? [];
eq([in_array($it['side'] ?? null, ['long', 'short'], true), in_array($it['result'] ?? null, ['win', 'loss', 'horizon', 'open'], true), is_array($it['rule'] ?? null)], [true, true, true],
    'a past signal: side, result, the rule at its candle');
eq([$E['history']['from'], $E['history']['to']], [$r['candles'][0]['t'], $r['as_of']], 'computed on the closed candles of the chart (from the first to as_of)');
// H1b: ספירות עבר שהושלמו (קריטריון 5) — עד 5, בלי נקודה משותפת; ופיבונאצ'י — אזור לכל אות (קריטריון 6)
$cn = $E['history']['counts'] ?? null;
$pts = is_array($cn) ? array_merge([], ...array_map(fn($x) => array_column($x['points'], 't'), $cn)) : [];
eq([is_array($cn), count($cn ?? []) >= 1 && count($cn) <= 5, count($pts) === count(array_unique($pts))], [true, true, true], 'Elliott: history.counts — 1 to 5 completed counts in the chart set, no shared pivot');
eq(array_diff_key($cn[0] ?? [], array_flip(['dir', 'score', 'words', 't', 'ct', 'points'])), [], '… a past count carries no target zone, projection or invalidation');
eq(call('owner', 'admin_method_save', ['id' => 'fibonacci', 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1'], 'params' => [], 'note' => ''])[0], 200, 'fibonacci on');
$Fh = $refresh('alice', 'fibonacci')['methods']['fibonacci']['history'] ?? [];
$Fz = array_filter($Fh['items'] ?? [], fn($x) => $x['entry'] !== null);
eq(count(array_filter($Fz, fn($x) => is_array($x['zone']))), count($Fz), 'criterion 6: every fibonacci past signal carries its zone (' . count($Fz) . ')');
eq(count($Fz) > 0, true, '… and there are fibonacci past signals in the chart set');
// אותו מפתח (אותו נר סגור) — מהמטמון, אותם בייטים
$r2 = $refresh('alice', 'elliott-w3');
eq([$r2['runs'], $r2['methods']['elliott-w3']['history']], [0, $E['history']], 'the same closed candle → from the cache (the method did not run), the same history');

// ── קריטריון 7: המתג — בפרופיל, דולק כברירת מחדל, נשמר ──
eq(call('alice', 'profile')[1]['profile']['history'], true, 'the "History" switch — on by default');
eq([call('alice', 'history_set', ['on' => false])[0], call('alice', 'profile')[1]['profile']['history'], call('alice', 'me')[1]['profile']['history'] ?? null], [200, false, false],
    'criterion 7: off → saved in the profile (and in me)');
eq(call('bob', 'profile')[1]['profile']['history'], true, '… only for alice');
eq(call('alice', 'history_set', ['on' => 'no'])[0], 422, 'history_set: only true / false');
$tokens['x'] = $tokens['alice']; $tokens['alice'] = 'bad';
eq(call('alice', 'history_set', ['on' => true])[0], 403, 'history_set without X-CSRF → 403');
$tokens['alice'] = $tokens['x'];

// ── קריטריון 10: רק תצוגה — אותו שקלול עם המתג ובלעדיו, ו-0 שורות ב-method_outcomes ──
call('owner', 'history_set', ['on' => false]);
$blendOff = $refresh('owner', 'sample-ma-cross,elliott-w3')['analysis']['blend'];
call('owner', 'history_set', ['on' => true]);
$blendOn = $refresh('owner', 'sample-ma-cross,elliott-w3')['analysis']['blend'];
eq($blendOn, $blendOff, 'criterion 10: the same blend (6.5) with the switch and without');
eq((int)$db->query('SELECT COUNT(*) FROM method_outcomes')->fetchColumn(), 0, 'criterion 10: no rows in method_outcomes from the chart (the learning gets trades only from the full backtest and live)');
$S = $refresh('owner', 'sample-ma-cross')['methods']['sample-ma-cross']['history'];
eq([$S['n'] >= count($S['items']), count($S['items']) <= 50], [true, true], "the sample method: {$S['n']} past signals, up to 50 shown");

// ── H1c, קריטריון 12: מנה ישנה (market_history) → method_history: רק לפני תחילת הטווח הקודם, מהמטמון, בלי לשנות את מה שעל המסך ──
$klines = fn() => is_file("$tmp.http.log") ? substr_count((string)file_get_contents("$tmp.http.log"), '/klines') : 0;
$ids = 'elliott-w3,fibonacci';
$r0 = $refresh('carol', $ids);
$first = $r0['candles'][0]['t'];
[$c, $b1] = call('carol', 'market_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'before' => $first]);
eq([$c, count($b1['candles']) > 900], [200, true], 'one older batch (market_history)');
$k0 = $klines();
[$c, $mh] = call('carol', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $b1['candles'][0]['t'], 'before' => $first, 'methods' => $ids]);
eq([$c, $mh['provider_calls'], $klines() - $k0], [200, 0, 0], 'method_history: the candles and the warm-up from cache.sqlite (history + the pool) — 0 provider calls');
$items = array_merge(...array_map(fn($x) => [...($x['history']['items'] ?? []), ...($x['history']['counts'] ?? [])], array_values($mh['methods'])));
eq([array_column(array_values($mh['methods']), 'status'), count($items) > 0, array_unique(array_map(fn($x) => $x['t'] < $first, $items))], [['ok', 'ok'], true, [true]],
    'criterion 12: every method — past items only before the start of the previous range (' . count($items) . ')');
eq(array_unique(array_column(array_values($mh['methods']), 'ran')), [true], '… computed once');
[, $mh2] = call('carol', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $b1['candles'][0]['t'], 'before' => $first, 'methods' => $ids]);
eq([array_unique(array_column(array_values($mh2['methods']), 'ran')), array_column(array_values($mh2['methods']), 'history')], [[false], array_column(array_values($mh['methods']), 'history')],
    'the same batch again → from the cache (the methods did not run), the same items');
$r1 = $refresh('carol', $ids);
eq(array_map(fn($x) => $x['history'], $r1['methods']), array_map(fn($x) => $x['history'], $r0['methods']), 'criterion 12: what was on the chart (chart_refresh) — unchanged');
eq(call('carol', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $first, 'before' => $first, 'methods' => $ids])[0], 422, 'from ≥ before → 422');
// תקרת חישוב (CPU) — 12 מנות שחושבו בדקה למשתמש; מנה מהמטמון לא נספרת, ומשתמש אחר — תקרה משלו
$cache->exec('DELETE FROM history_runs');
$runs = [];
for ($i = 1; $i <= 13; $i++) {   // 13 מנות שונות (before אחר → מפתח אחר): 12 רצות, ה-13 → 429
    $bf = $first - $i * 50 * 3600;
    $runs[] = call('carol', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $bf - 200 * 3600, 'before' => $bf, 'methods' => 'fibonacci'])[0];
}
eq($runs, [...array_fill(0, 12, 200), 429], 'a compute cap: 12 batches computed per minute per user, the 13th → 429 (Retry-After)');
[$c, $again] = call('carol', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $b1['candles'][0]['t'], 'before' => $first, 'methods' => $ids]);
eq([$c, array_unique(array_column(array_values($again['methods'] ?? []), 'ran'))], [200, [false]], '… a batch already computed comes from the cache, even over the cap');
eq(call('dave', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $first - 300 * 3600, 'before' => $first - 77 * 3600, 'methods' => 'fibonacci'])[0], 200, '… another user — a cap of their own');
eq(call('carol', 'method_history', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $first - 3600, 'before' => $first, 'methods' => ''])[0], 422, 'no methods → 422');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
