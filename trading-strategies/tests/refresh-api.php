<?php
// רענון הגרף בשרת (spec-stage-a 18.3, S3, ✅23): בקשה אחת לכל השיטות, בלי חישוב חוזר כשלא נסגר נר, המונה במסך המפתח,
// ותקרת 30 הבקשות לדקה. php trading-strategies/tests/refresh-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
// הצד של הדפדפן (קצב, לשונית מוסתרת, 429) — tests/refresh-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-refresh-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

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
$NOW = 1794094200;   // "עכשיו" קבוע (X-Dev-Now) — אותם נרות מדומים בכל ריצה; חצי שעה אחרי שעה עגולה
/** [קוד, JSON מפוענח, הגוף כמו שהגיע] */
function call(string $as, string $action, ?array $body = null, array $query = [], ?int $now = null): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", 'X-Dev-Now: ' . ($now ?? $NOW)];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw, $raw];
}
/** החלק של השיטות בגוף, כמו שהגיע מהשרת (בייטים) */
function methodsRaw(string $raw): string
{
    $i = strpos($raw, '"methods":');
    $j = strpos($raw, ',"runs":', $i);
    return $i === false || $j === false ? '' : substr($raw, $i, $j - $i);
}
function runs(): array
{
    $out = [];
    foreach (call('owner', 'admin_state')[1]['method_runs'] ?? [] as $r) $out[$r['method_id']] = [$r['runs'], $r['reused']];
    return $out;
}
function httpCalls(): int
{
    global $tmp;
    return count(array_filter(explode("\n", (string)@file_get_contents("$tmp.http.log")), fn($l) => str_contains($l, 'klines')));
}
foreach (['alice', 'bob', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$set = fn($id, $p = []) => call('owner', 'admin_method_save', ['id' => $id, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4', 'd1'], 'params' => $p, 'note' => '']);
eq([$set('test-cross-a')[0], $set('test-cross-b')[0], $set('sample-ma-cross')[0]], [200, 200, 200], 'the admin turns on two test methods and the sample method');
$from = $NOW - 30 * 86400;
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $from, 'to' => $NOW, 'methods' => 'test-cross-a,test-cross-b'];

// ── קריטריון 1 (השרת): בקשה אחת — נרות ותוצאה לכל שיטה ──
[$c, $r, $raw1] = call('alice', 'chart_refresh', null, $q);
eq([$c, count($r['candles'] ?? []) > 500, array_keys($r['methods'] ?? [])], [200, true, ['test-cross-a', 'test-cross-b']], 'criterion 1: one request returns the candles and a result for every chosen method');
eq([$r['methods']['test-cross-a']['status'], $r['methods']['test-cross-b']['status'], $r['runs'], $r['reused']], ['ok', 'ok', 2, 0], 'first time: both methods run');
eq([$r['as_of'], $r['methods']['test-cross-a']['overlay']['as_of']], [$NOW - 5400, $NOW - 5400], 'as_of = the last closed candle (the open one is not counted)');
eq(end($r['candles'])['t'], $NOW - 1800, 'the open candle is in the candles (it moves on the chart)');
eq($r['refresh'], ['default' => 60, 'choices' => [60, 30]], 'the allowed rates come from the server: 60 by default, or 30 (18.3)');
eq(runs(), ['test-cross-a' => [1, 0], 'test-cross-b' => [1, 0]], 'the run counter in the developer screen: one run each');

// ── קריטריון 3: אותו נר סגור אחרון → השיטה לא רצה, והתשובה זהה בייט-לבייט ──
[$c, $r2, $raw2] = call('alice', 'chart_refresh', null, $q);
[$c3, $r3, $raw3] = call('alice', 'chart_refresh', null, $q);
eq([$r2['runs'], $r2['reused'], $r3['runs'], $r3['reused']], [0, 2, 0, 2], 'criterion 3: the same last closed candle → no method runs (from the stored result)');
eq(runs(), ['test-cross-a' => [1, 2], 'test-cross-b' => [1, 2]], 'criterion 3: the run counter does not go up — only "reused"');
eq([methodsRaw($raw1) !== '', methodsRaw($raw1) === methodsRaw($raw2)], [true, true], 'criterion 3: the method results are byte-identical to the run that computed them');
eq($raw2 === $raw3, true, 'criterion 3: two consecutive refreshes → the whole response is byte-identical');

// נר פתוח שזז (הקצה פג, הספק נשאל שוב) — הנרות מתעדכנים, השיטות לא רצות
$before = httpCalls();
[$c, $r4, $raw4] = call('alice', 'chart_refresh', null, ['to' => $NOW + 1500] + $q, $NOW + 1500);
eq([httpCalls() > $before, $r4['as_of'], $r4['runs'], methodsRaw($raw4) === methodsRaw($raw1)], [true, $NOW - 5400, 0, true], 'the open candle moves (provider asked again) → same closed candles → no run, same bytes');

// אותו נר, משתמש אחר על אותו מאגר משותף — גם הוא מקבל את התוצאה השמורה
[$c, $rb, $rawb] = call('bob', 'chart_refresh', null, $q);
eq([$rb['runs'], methodsRaw($rawb) === methodsRaw($raw1)], [0, true], 'another user on the same shared pool → the stored result, not a new run');

// ── קריטריון 4: נר חדש נסגר → בקשה אחת מחשבת מחדש ──
$N2 = $NOW + 3600;
[$c, $r5, $raw5] = call('alice', 'chart_refresh', null, ['from' => $from + 3600, 'to' => $N2] + $q, $N2);
eq([$c, $r5['runs'], $r5['reused'], $r5['as_of']], [200, 2, 0, $NOW - 1800], 'criterion 4: a new candle closed → one request recomputes every method');
eq([$r5['methods']['test-cross-a']['overlay']['as_of'], methodsRaw($raw5) !== methodsRaw($raw1)], [$NOW - 1800, true], 'criterion 4: the result is of the new candle');
eq(runs()['test-cross-a'][0], 2, 'criterion 4: the counter shows the second run');

// פרמטרים אחרים → מפתח אחר → ריצה
$set('test-cross-a', ['fast' => 10]);
[$c, $r6] = call('alice', 'chart_refresh', null, ['methods' => 'test-cross-a'] + $q);
eq([$c, $r6['runs']], [200, 1], 'changed parameters → a different key → the method runs');
$set('test-cross-a');

// ── שיטה שלא זמינה / מסגרת שלא מוגדרת — לא מפילות את האחרות ──
[$c, $r7] = call('alice', 'chart_refresh', null, ['methods' => 'test-cross-a,sample-ma-cross,zzz'] + $q);
eq([$c, $r7['methods']['test-cross-a']['status'], $r7['methods']['sample-ma-cross']['status'], $r7['methods']['zzz']['status']], [200, 'ok', 'unavailable', 'unavailable'],
    'a method the user does not see (sample for a regular user, unknown id) → "unavailable", the others still answer');
[$c, $r8] = call('alice', 'chart_refresh', null, ['tf' => 'm15', 'methods' => 'test-cross-a'] + $q);
eq([$c, $r8['methods']['test-cross-a']['status'], $r8['runs']], [200, 'unsupported', 0], 'a timeframe the method is not set for → unsupported, nothing runs');
[$c, $r9] = call('alice', 'chart_refresh', null, ['methods' => ''] + $q);
eq([$c, $r9['methods'], $r9['runs'], count($r9['candles']) > 500], [200, [], 0, true], 'no method → only the candles (the manual "↻" without analysis, criterion 6)');
eq(str_contains(call('alice', 'chart_refresh', null, ['methods' => ''] + $q)[2], '"methods":{}'), true, 'no method → "methods" is an empty object, not a list');
eq([call('alice', 'chart_refresh', null, ['tf' => 'x9'] + $q)[0], call('alice', 'chart_refresh', null, ['asset' => 'nope'] + $q)[0],
    call('alice', 'chart_refresh', null, ['methods' => implode(',', array_map(fn($i) => "m$i", range(1, 9)))] + $q)[0]], [422, 422, 422], 'bad timeframe / asset / more than 8 methods → 422');
eq(array_key_exists('method_runs', call('alice', 'admin_state')[1]), false, 'the counter is only in the admin state (a regular user gets 403)');

// ── חוב טכני: market_candles מקבל את "עכשיו" מ-ts_now() — X-Dev-Now חל גם עליו, כמו על chart_refresh ──
$mc = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1'];
[$c, $m1] = call('bob', 'market_candles', null, $mc);
[$c2, $m2] = call('bob', 'market_candles', null, $mc + ['to' => $NOW + 30 * 86400]);
eq([$c, end($m1['candles'])['t'] ?? null, $c2, end($m2['candles'])['t'] ?? null], [200, $NOW - 1800, 200, $NOW - 1800],
    'market_candles with X-Dev-Now: the last candle is the open one of the fixed "now" (not of the real clock), and "to" in the future is cut to it');
[$c, $m3] = call('bob', 'market_candles', null, $mc, $NOW - 86400);
eq([$c, end($m3['candles'])['t'] ?? null], [200, $NOW - 86400 - 1800], '… another X-Dev-Now → the candles of that "now"');

// ── התקרה (✅3): בקשת רענון אחת = ספירה אחת, לא משנה כמה שיטות ──
if ((int)date('s') > 40) sleep(61 - (int)date('s'));
$codes = [];
for ($i = 0; $i < 31; $i++) $codes[] = call('dave', 'chart_refresh', null, ['methods' => 'test-cross-a,test-cross-b'] + $q)[0];
eq(array_count_values($codes), [200 => 30, 429 => 1], 'criterion 1 / ✅3: 30 refreshes with 2 methods each fit in the limit — the 31st is 429');
[$c, $r429] = call('dave', 'chart_refresh', null, $q);
eq([$c, $r429['ok'] ?? null, is_string($r429['error'] ?? null)], [429, false, true], '429 carries a clear message');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
