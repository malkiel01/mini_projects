<?php
// היסטוריה בפעימות (בקשת הבעלים: "תוסיף עד 5 שנים, אבל פעימות מידע קטנות, שלא נחסם") — market_history ומטמון ההיסטוריה.
// php trading-strategies/tests/history-api.php — php -S עם שער מדומה וספקים מדומים (fixtures/market).
//   - מנה = עד 999 נרות לפני before (אחד פחות מ-1,000 של Binance), פנייה אחת לספק; מנות רצופות, בלי חורים ובלי כפילויות.
//   - מה שנמשך נשמר (history): אותה מנה שוב — 0 פניות; גם chart_refresh/market_candles לא מושכים שוב את מה שמעבר ל-60 יום.
//   - לא חוצה 5 שנים; לפני שהנכס נסחר — done.
//   - 429 מהספק → retry + notice, בלי שגיאה; אחרי שעבר — אותה מנה מגיעה.
//   - כל מנה נספרת בתקרת 30 הבקשות לדקה למשתמש.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-history-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
// פורט פנוי מהמערכת ושם ייחודי — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת או קבצים של ריצה אחרת (#1333)
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$NOW = time();
$SINCE = $NOW - 200 * 86400;   // הנכס "נסחר" רק 200 יום — כדי לראות done לפני 5 שנים
$FLAG = "$tmp.429";
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_KLINES_SINCE' => (string)$SINCE, 'TS_KLINES_429_FILE' => $FLAG] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log"],
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
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens, $bodies;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    $bodies[] = $raw;
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$H = 3600;
$A = 'binance:BTCUSDT';
$klines = fn() => is_file("$tmp.http.log") ? substr_count((string)file_get_contents("$tmp.http.log"), '/klines') : 0;

// ── הגרף כמו היום: 3 חודשים בשעה. החלק שמעבר ל-60 יום — נשמר, ובפעם השנייה לא נמשך שוב ──
$from = $NOW - 90 * 86400;
[$c, $r] = call('alice', 'market_candles', null, ['asset' => $A, 'tf' => 'h1', 'from' => $from, 'to' => $NOW]);
eq([$c, count($r['candles']) >= 2159, $r['provider_calls'] >= 1], [200, true, true], 'the chart: 3 months of hourly candles, fetched from the provider');
$first = $r['candles'][0]['t'];
$k0 = $klines();
[$c, $r2] = call('alice', 'market_candles', null, ['asset' => $A, 'tf' => 'h1', 'from' => $from, 'to' => $NOW]);
eq([$c, $r2['provider_calls'], $klines() - $k0, count($r2['candles']) === count($r['candles'])], [200, 0, 0, true],
    'the same request again (the automatic refresh): the part older than 60 days comes from the history cache — 0 provider calls');

// ── מנה ראשונה: לפני הנר הראשון, עד 1,000 נרות, פנייה אחת ──
$k0 = $klines();
[$c, $p1] = call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => $first]);
$t1 = array_column($p1['candles'], 't');
eq([$c, count($t1), max($t1) < $first, $p1['done'], $p1['retry'], $klines() - $k0], [200, 999, true, false, false, 1],
    'one batch: 999 older candles, all before "before", one provider call');
eq(max($t1), $first - $H, 'the batch ends right before the first candle on the chart (no gap)');
eq($t1, range(min($t1), max($t1), $H), 'the batch is contiguous, oldest first, no duplicates');

// ── אותה מנה שוב — מהמטמון ──
$k0 = $klines();
[$c, $p1b] = call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => $first]);
eq([$c, array_column($p1b['candles'], 't') === $t1, $p1b['provider_calls'], $klines() - $k0], [200, true, 0, 0], 'the same batch again: from the history cache, 0 provider calls');
// משתמש אחר — אותו מאגר משותף (Binance), גם הוא בלי פנייה
$k0 = $klines();
[$c, $p1c] = call('dave', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => $first]);
eq([$c, count($p1c['candles']), $klines() - $k0], [200, 999, 0], 'another user: the shared pool (Binance) — also 0 provider calls');

// ── 429 מהספק: retry + notice, בלי שגיאה; אחרי שעבר — אותה מנה מגיעה ──
$before = min($t1);
touch($FLAG);
[$c, $p429] = call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => $before]);
eq([$c, $p429['candles'], $p429['retry'], $p429['done'], is_string($p429['notice']) && $p429['notice'] !== ''], [200, [], true, false, true],
    '429 from the provider → 200 with retry and a notice, no candles, not done');
unlink($FLAG);
[$c, $p2] = call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => $before]);
$t2 = array_column($p2['candles'], 't');
eq([$c, count($t2), $p2['retry'], max($t2)], [200, 999, false, $before - $H], 'after the 429 is gone, the same batch arrives and joins the previous one');

// ── המשך עד שהנכס "התחיל להיסחר" (200 יום): done, בלי חורים ──
$all = array_merge($t2, $t1);
$guard = 0;
do {
    [$c, $pp] = call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => min($all)]);
    $tt = array_column($pp['candles'], 't');
    if ($tt) $all = array_merge($tt, $all);
} while ($c === 200 && !$pp['done'] && ++$guard < 10);
eq([$c, $pp['done'], $pp['retry'], min($all) >= $SINCE, min($all) - $SINCE < $H], [200, true, false, true, true], 'paging stops (done) where the asset starts trading');
eq($all, range(min($all), max($all), $H), 'all the batches together: contiguous, no gaps, no duplicates');
$k0 = $klines();
[$c, $pe] = call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => min($all)]);
eq([$c, $pe['candles'], $pe['done'], $klines() - $k0], [200, [], true, 0], 'asking again before the start: done, and the empty range is remembered — 0 provider calls');

// ── שגיאה אחרת מהספק (451, 500) בחלק הישן — לא מפילה את הבקשה: notice, ומה שבמאגר (תוספת של הבקרה) ──
foreach ([451, 500] as $code) {
    $sym = $code === 451 ? 'binance:SOLUSDT' : 'binance:XRPUSDT';
    [$c, $rp] = call('alice', 'market_candles', null, ['asset' => $sym, 'tf' => 'h1', 'from' => $NOW - 59 * 86400, 'to' => $NOW]);
    eq([$c, count($rp['candles']) > 1000], [200, true], "($code) the last 60 days are in the pool first");
    file_put_contents($FLAG, (string)$code);
    [$c, $ro] = call('alice', 'market_candles', null, ['asset' => $sym, 'tf' => 'h1', 'from' => $NOW - 90 * 86400, 'to' => $NOW]);
    unlink($FLAG);
    $oldest = $ro['candles'][0]['t'] ?? 0;
    eq([$c, is_string($ro['notice'] ?? null) && $ro['notice'] !== '', $oldest >= $NOW - 61 * 86400, count($ro['candles'] ?? []) >= count($rp['candles'])], [200, true, true, true],
        "$code from the provider on the part older than 60 days → 200, a notice, and what is in the pool (not an error)");
    [$c, $rh] = call('alice', 'market_history', null, ['asset' => $sym, 'tf' => 'h1', 'before' => $NOW - 100 * 86400]);
    eq($c, 200, "($code) market_history right after: works (the error was not remembered as coverage)");
    eq(count($rh['candles'] ?? []), 999, "($code) … and returns a full batch");
}
file_put_contents($FLAG, '451');
[$c, $rh] = call('alice', 'market_history', null, ['asset' => 'binance:ADAUSDT', 'tf' => 'h1', 'before' => $NOW - 100 * 86400]);
unlink($FLAG);
eq([$c, $rh['candles'], $rh['retry'], $rh['done']], [200, [], true, false], '451 on a history batch → retry (not an error, not done)');

// ── 5 שנים: לא חוצים ──
$limit5 = $NOW - 1826 * 86400;
[$c, $p5] = call('alice', 'market_history', null, ['asset' => 'binance:ETHUSDT', 'tf' => 'd1', 'before' => $limit5 + 3 * 86400]);
eq([$c, $p5['done'], $p5['limit'] >= $limit5 - 5 && $p5['limit'] <= $limit5 + 5], [200, true, true], 'near the 5-year limit: done');
eq(array_filter(array_column($p5['candles'], 't'), fn($t) => $t < $p5['limit']), [], 'and nothing older than 5 years');
$k0 = $klines();
[$c, $p6] = call('alice', 'market_history', null, ['asset' => 'binance:ETHUSDT', 'tf' => 'd1', 'before' => $limit5 - 86400]);
eq([$c, $p6['candles'], $p6['done'], $klines() - $k0], [200, [], true, 0], 'before the 5-year limit: nothing, done, no provider call');

// ── קלט לא תקין ──
eq(call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'h1'])[0], 422, 'no "before" → 422');
eq(call('alice', 'market_history', null, ['asset' => $A, 'tf' => 'x9', 'before' => $NOW])[0], 422, 'a bad timeframe → 422');

// ── תקרת 30 לדקה למשתמש: כל מנה נספרת ──
$codes = [];
for ($i = 0; $i < 32; $i++) $codes[] = call('dave', 'market_history', null, ['asset' => $A, 'tf' => 'h1', 'before' => $first])[0];
eq(in_array(429, $codes, true), true, 'market_history counts in the 30-per-minute cap per user (429 before 32 calls in a minute)');

// אזהרות הפעלה של PHP עצמו (למשל "JIT … disabled. in Unknown on line 0" ב-runner של הפריסה, ריצה 1789) — לא של הקוד;
// כל השאר נספר (כמו ב-builder-removed-api.php ו-prefill-api.php)
$err = array_values(array_filter(explode("\n", is_file("$tmp.error.log") ? (string)file_get_contents("$tmp.error.log") : ''),
    fn($l) => trim($l) !== '' && !str_contains($l, ' in Unknown on line 0')));
eq($err, [], 'no PHP errors on the server');
echo $fail ? "✗ $fail/$n נכשלו\n" : "✓ $n בדיקות עברו\n";
exit($fail ? 1 : 0);
