<?php
// בדיקה לאחור על העותק האישי בענן (spec-stage-a 3.7, ✅14; פרק 15: "עותק — נרות מזויפים", "עותק — מגבלות (422)",
// "עותק — צירוף", "עותק — קצב ושקלול"). php trading-strategies/tests/copy-api.php — php -S עם שער מדומה וספקים מדומים.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-copy-api-' . getmypid();
$port = 25000 + getmypid() % 1000;
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1',
    '-d', "error_log=$tmp.error.log"],
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
$jar = []; $tokens = [];
$NOW = 1794094200;   // "עכשיו" קבוע (X-Dev-Now) — הנרות המדומים תלויים בזמן
function call(string $as, string $action, ?array $body = null, array $query = [], ?string $raw = null): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", "X-Dev-Now: $NOW"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null || $raw !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null || $raw !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $raw ?? ($body !== null ? json_encode($body) : ''), 'ignore_errors' => true, 'timeout' => 120]]);
    $res = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($res, true) ?? $res];
}
foreach (['alice', 'bob', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$cache = new PDO('sqlite:' . "$tmp.sqlite.cache");
$uidOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();

const H = 3600;
$A = 'binance:BTCUSDT';
$poolStart = $NOW - 60 * 86400;
$lastClosed = intdiv($NOW - H, H) * H;
/** CSV של 3.7. $price(t) → [o,h,l,c,v] */
$csv = function (int $from, int $to, callable $price, string $head = 'provider=binance; symbol=BTCUSDT; tf=h1') {
    $out = "# $head; saved=2026-11-08T10:00:00Z\nt,o,h,l,c,v\n";
    for ($t = $from; $t <= $to; $t += H) $out .= $t . ',' . implode(',', $price($t)) . "\n";
    return $out;
};
// כמו הספק המדומה (fixtures/market/binance-klines.php) — שיניים של מסור, ולכן יש אותות
$real = fn(int $t) => [($p = 100 + ($t / H % 50)) . '.10000000', ($p + 1) . '.00000000', ($p - 1) . '.00000000', "$p.50000000", '12.5'];
$fake = fn(int $t) => ['77777.10000000', '77778.00000000', '77776.00000000', '77777.50000000', '1'];
$gz = fn(string $s) => base64_encode(gzencode($s));
$run = fn(string $as, string $csvText, array $over = []) => call($as, 'analysis_copy', $over + ['asset' => $A, 'tf' => 'h1', 'methods' => 'test-cross-a,test-cross-b', 'csv_gz' => $gz($csvText)]);
$candlesSig = fn() => [(int)$cache->query('SELECT COUNT(*) FROM candles')->fetchColumn(),
    sha1(implode('|', array_map(fn($r) => implode(',', $r), $cache->query('SELECT provider, symbol, tf, t, o, h, l, c, v FROM candles ORDER BY provider, symbol, tf, t')->fetchAll(PDO::FETCH_NUM))))];
$shared = fn() => $db->query("SELECT method_id, n, wins, computed_at FROM backtests WHERE cred_scope = '' AND source = 'provider' ORDER BY method_id")->fetchAll(PDO::FETCH_ASSOC);
$copyRows = fn() => $db->query("SELECT method_id, cred_scope, source, state, n, computed_at, n_copy, n_provider FROM backtests WHERE source = 'copy' ORDER BY cred_scope, method_id")->fetchAll(PDO::FETCH_ASSOC);

// ── הכנה: שתי שיטות בדיקה מופעלות (גם למשתמש רגיל) ───────
foreach (['test-cross-a', 'test-cross-b'] as $m) call('owner', 'admin_method_save', ['id' => $m, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4']]);
$q = ['asset' => $A, 'tf' => 'h1', 'methods' => 'test-cross-a,test-cross-b'];
$bob0 = call('bob', 'analysis', null, $q)[1]['cards'];
$ali0 = call('alice', 'analysis', null, $q)[1];
eq([$ali0['cards'][0]['backtest_copy'], $ali0['cards'][0]['copy_used']], [null, false], 'before a copy run: no copy result, not used');
$sig0 = $candlesSig();
$shared0 = $shared();
eq(count($shared0), 2, 'the shared results exist (keyless provider → shared scope)');

// ── עותק — נרות מזויפים: לא נכנסים למאגר, לא משנים תוצאות של אחרים ─────────
$from = $NOW - 400 * 86400 - ($NOW % H);   // מיושר לשעה
[$c, $r] = $run('alice', $csv($from, $lastClosed - 30 * 86400, $fake));
$card = $r['cards'][0] ?? [];
$bc = $card['backtest_copy'] ?? [];
$expCopy = intdiv(($poolStart - 1) - $from, H) + 1;   // נרות העותק שלפני המאגר (בחפיפה — המאגר)
eq([$c, $bc['source'] ?? null, $bc['n_copy'] ?? null], [200, 'copy', $expCopy], 'alice runs on her (fake) copy → a copy result; overlap with the pool → the pool\'s candles');
eq($candlesSig(), $sig0, 'fake candles: candles (count and hash) unchanged');
eq($shared(), $shared0, 'fake candles: the shared result is unchanged');
$cr = $copyRows();
eq([count($cr), $cr[0]['cred_scope'], $cr[0]['source'], $cr[0]['state']], [2, 'u:' . $uidOf('101'), 'copy', null], 'stored in alice\'s scope only (u:<id>, source=copy, no state — full recompute)');
$bob1 = call('bob', 'analysis', null, $q)[1]['cards'];
eq([array_column($bob1, 'backtest_copy'), array_map(fn($c) => [$c['backtest']['n'], $c['backtest']['wins'], $c['certainty']], $bob1)],
    [[null, null], array_map(fn($c) => [$c['backtest']['n'], $c['backtest']['wins'], $c['certainty']], $bob0)], 'bob: no copy result, and his results did not change');
eq(call('bob', 'analysis_copy_use', ['asset' => $A, 'tf' => 'h1', 'method_id' => 'test-cross-a', 'use' => true])[0], 200, 'bob may choose "copy" for himself …');
eq(call('bob', 'analysis', null, $q)[1]['cards'][0]['copy_used'], false, '… but has no copy result — nothing changes (and he never sees alice\'s)');
eq(str_contains(json_encode(call('bob', 'analysis', null, $q)[1]), '77777'), false, 'nothing of alice\'s copy reaches bob');

// ── עותק — צירוף: פער בין סוף העותק לתחילת המאגר — מהספק ───────
$end = $lastClosed - 100 * 86400;
[$c, $r] = $run('alice', $csv($from, $end, $real));
$bc = $r['cards'][0]['backtest_copy'] ?? [];
$allBars = intdiv($lastClosed - $from, H) + 1;
eq([$c, $bc['n_copy'] ?? null, ($bc['n_copy'] ?? 0) + ($bc['n_provider'] ?? 0)], [200, intdiv($end - $from, H) + 1, $allBars],
    'gap → filled from the provider: every candle of the copy + provider candles to the last closed one, no holes');
eq(($r['copy_run']['provider_calls'] ?? 0) > 0, true, 'the gap was fetched from the provider (counted in the budget)');
eq($candlesSig()[0] >= $sig0[0] && $cache->query('SELECT COUNT(*) FROM candles WHERE t < ' . ($poolStart - H))->fetchColumn() == 0, true,
    'the gap (older than 60 days) is not kept in candles either');
eq([$bc['from_t'] ?? null, ($bc['to_t'] ?? 0) <= $lastClosed], [$from, true], 'the range starts at the copy\'s first candle');
$sig1 = $candlesSig();
$copy1 = $copyRows();

// ── עותק — מגבלות (422): כל אחד נדחה ולא כותב דבר ─────────
$ok = fn() => $csv($from, $end, $real);
$bad = [
    '100,001 candles' => $csv($lastClosed - 100000 * H, $lastClosed, $real),
    'header: another provider' => $csv($from, $end, $real, 'provider=ecb; symbol=BTCUSDT; tf=h1'),
    'header: another symbol' => $csv($from, $end, $real, 'provider=binance; symbol=ETHUSDT; tf=h1'),
    'header: another timeframe' => $csv($from, $end, $real, 'provider=binance; symbol=BTCUSDT; tf=h4'),
    'no comment line' => substr($ok(), strpos($ok(), "\n") + 1),
    'unsorted' => str_replace("\n" . ($from + H) . ',', "\n" . ($from - H) . ',', $ok()),
    'duplicate t' => str_replace("\n" . ($from + H) . ',', "\n" . $from . ',', $ok()),
    'not aligned to the hour' => str_replace("\n" . ($from + H) . ',', "\n" . ($from + H + 60) . ',', $ok()),
    'price not a decimal string' => str_replace("\n" . ($from + H) . ',' . $real($from + H)[0], "\n" . ($from + H) . ',123.456789x', $ok()),
    'price in exponent form' => str_replace("\n" . ($from + H) . ',' . $real($from + H)[0], "\n" . ($from + H) . ',1e5', $ok()),
    'negative price' => $csv($from, $end, fn($t) => ['-1', '2', '-2', '1', '1']),
    'h < max(o,c)' => $csv($from, $end, fn($t) => ['123.456789', '100', '90', '95', '1']),
    'future candle' => $csv($lastClosed - 10 * H, $lastClosed + 5 * H, $real),
    'the open candle' => $csv($lastClosed - 10 * H, $lastClosed + H, $real),
];
foreach ($bad as $what => $text) {
    [$c, $r] = $run('alice', $text);
    eq([$c, str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), '123.456789')], [422, false], "422: $what (the message does not echo the content)");
}
eq($run('alice', $ok(), ['tf' => 'h4'])[0], 422, '422: the declared timeframe differs from the file');
eq($run('alice', $ok(), ['asset' => 'binance:ETHUSDT'])[0], 422, '422: the declared asset differs from the file');
eq(call('alice', 'analysis_copy', $q + ['csv_gz' => 'not base64!'])[0], 422, '422: not gzip/base64');
$bomb = base64_encode(gzencode(str_repeat('0', 13_000_000)));
eq(call('alice', 'analysis_copy', $q + ['csv_gz' => $bomb])[0], 422, '422: unpacks beyond the ceiling (gzip bomb)');
[$c, $r] = call('alice', 'analysis_copy', null, [], json_encode($q + ['csv_gz' => str_repeat('A', 6_100_000)]));
eq($c, 422, '422: request body over the ceiling');
eq([$r['cards'][0]['row'] ?? null, (int)$db->query('SELECT COUNT(*) FROM backtests')->fetchColumn()], [null, 4], 'nothing was written (only the 2 shared + 2 of alice)');
[$c, $r] = $run('alice', $bad['not aligned to the hour']);
eq(preg_match('/^שורה \d+: /u', $r['error'] ?? ''), 1, 'the 422 says what failed, and in which line');
eq([$candlesSig(), $copyRows()], [$sig1, $copy1], 'after all the 422s: candles and results unchanged');

// ── עותק — קצב ושקלול ─────────────────────────────────────
$u = fn(string $as, string $id, bool $use) => call($as, 'analysis_copy_use', ['asset' => $A, 'tf' => 'h1', 'method_id' => $id, 'use' => $use]);
$an = call('alice', 'analysis', null, $q)[1];
$ca = $an['cards'][0];
eq([$ca['copy_used'], $ca['backtest_copy']['status'], is_int($ca['backtest_copy']['certainty'])], [false, 'ok', true], 'default: the regular result is in the blend (the copy result is shown beside it)');
$w0 = array_column($an['blend']['included'], 'weight', 'id');
eq($u('alice', 'test-cross-a', true)[0], 200, 'alice chooses the copy result for the blend');
$an = call('alice', 'analysis', null, $q)[1];
$w1 = array_column($an['blend']['included'], 'weight', 'id');
eq([$an['cards'][0]['copy_used'], $an['cards'][1]['copy_used']], [true, false], '"🧪 שקלול לפי העותק שלך" — only for the method she chose');
eq(abs($w1['test-cross-a'] - $an['cards'][0]['backtest_copy']['certainty'] / 100 * 0.5) < 1e-9 && $w1['test-cross-b'] === $w0['test-cross-b'], true,
    'the blend weight uses the copy certainty for that method only');
eq(call('bob', 'analysis', null, $q)[1]['blend']['included'], call('bob', 'analysis', null, $q)[1]['blend']['included'], 'bob\'s blend is his own');
eq(array_column(call('bob', 'analysis', null, $q)[1]['blend']['included'], 'weight', 'id'), $w0, 'alice\'s choice does not change bob\'s blend');
// ה-cron של ההתראות: ts_analysis בלי $withCopy — לא קורא את התוצאה מהעותק ולא את הבחירה
$script = "$tmp.cron-view.php";
file_put_contents($script, '<?php require ' . var_export("$root/trading-strategies/lib/alerts.php", true) . '; $a = ts_analysis(' . $uidOf('101') . ", 'binance:BTCUSDT', 'h1', 'test-cross-a,test-cross-b', $NOW);"
    . ' echo json_encode([array_key_exists("backtest_copy", $a["cards"][0]), array_column($a["blend"]["included"], "weight", "id")]);');
$p = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root, $env);
$cronOut = stream_get_contents($pp[1]); $cronErr = stream_get_contents($pp[2]); proc_close($p);
$cronView = json_decode($cronOut, true);
if ($cronView === null) echo "cron-view: $cronOut $cronErr\n";
eq($cronView, [false, $w0], 'the alerts path (no browser) sees no copy result, and blends with the regular results');
eq(preg_match('/ts_analysis\(\$uid, \$a\[\'asset\'\], \$a\[\'tf\'\], \$ids, \$now\);/', (string)file_get_contents("$root/trading-strategies/lib/alerts.php")), 1,
    'lib/alerts.php calls ts_analysis without $withCopy');
$u('alice', 'test-cross-a', false);
eq(call('alice', 'analysis', null, $q)[1]['cards'][0]['copy_used'], false, 'back to the regular result');
eq($u('alice', 'nope', true)[0], 404, 'choosing for an unknown method → refused');

if ((int)date('s') > 45) sleep(61 - (int)date('s'));
$codes = [];
for ($i = 0; $i < 31; $i++) $codes[] = $run('dave', $bad['header: another provider'])[0];
eq(array_count_values($codes), [422 => 30, 429 => 1], 'a run on the copy counts in the 30-per-minute data limit — the 31st → 429');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
