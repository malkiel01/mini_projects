<?php
// הגבול "ישן מ-60 יום" זז עם השעון (בקרה 11, ריצה 1800): בקשה חוזרת שנייה / 4 דקות / שעה אחרי — לא פונה לספק על רסיס של החלק
// הישן (הוא כבר במאגר), והנרות ברצף — בלי חור ובלי כפילות בגבול. דטרמיניסטי: X-Dev-Now. לפני התיקון — פנייה בכל רענון.
// php trading-strategies/tests/history-edge-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-history-edge-' . getmypid() . '-' . bin2hex(random_bytes(4));
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
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
function call(int $now, array $query): array
{
    global $port;
    $ctx = stream_context_create(['http' => ['header' => "X-Dev-As: alice\r\nX-Dev-Now: $now", 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => 'market_candles'] + $query), false, $ctx);
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true)];
}
/** הפניות ל-klines מאז $k0: [startTime, endTime] בשניות */
function klines(int $k0): array
{
    global $tmp;
    $out = [];
    $lines = is_file("$tmp.http.log") ? file("$tmp.http.log", FILE_IGNORE_NEW_LINES) : [];
    foreach (array_slice($lines, $k0) as $l) {
        $u = json_decode($l, true)['url'] ?? '';
        if (!str_contains($u, '/klines')) continue;
        parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
        $out[] = [intdiv((int)($q['startTime'] ?? 0), 1000), intdiv((int)($q['endTime'] ?? 0), 1000)];
    }
    return $out;
}
$logLen = fn() => is_file("$tmp.http.log") ? count(file("$tmp.http.log")) : 0;
$H = 3600;
$A = 'binance:BTCUSDT';
$N = 1794094200 + 1234;   // לא על גבול של נר
$q = fn(int $now) => ['asset' => $A, 'tf' => 'h1', 'from' => $now - 90 * 86400, 'to' => $now];
$hours = fn(array $c) => array_column($c, 't');
$contig = fn(array $t) => $t === range($t[0], end($t), 3600);

// ── הפתיחה: 3 חודשים בשעה — החלק הישן נמשך ל-history, השאר למאגר ──
[$c, $r0] = call($N, $q($N));
eq([$c, count($r0['candles']) > 2000, $r0['provider_calls'] >= 2, $contig($hours($r0['candles']))], [200, true, true, true], 'the chart opens: 3 months of hours, old part + pool, contiguous');

// ── שנייה אחרי — אותה בקשה (הרענון האוטומטי): 0 פניות, אותם נרות ──
$k = $logLen();
[$c, $r1] = call($N + 1, $q($N + 1));
eq([$c, $r1['provider_calls'], klines($k)], [200, 0, []], 'a second later (the boundary moved by one second) → 0 provider calls — before: one call for a 1-second sliver of the old part');
eq($hours($r1['candles']), $hours($r0['candles']), '… the same candles');

// ── 4 דקות אחרי (בתוך התוקף של הקצה): 0 פניות ──
$k = $logLen();
[$c, $r2] = call($N + 240, $q($N + 240));
eq([$c, $r2['provider_calls'], klines($k)], [200, 0, []], '4 minutes later → 0 provider calls');
eq($contig($hours($r2['candles'])) && count(array_unique($hours($r2['candles']))) === count($r2['candles']), true, '… contiguous, no duplicates');

// ── שעה אחרי: רק הקצה החדש נמשך — אף פנייה לחלק הישן מ-60 יום; הנרות ברצף, בלי חור ובלי כפילות בגבול ──
$N3 = $N + $H;
$k = $logLen();
[$c, $r3] = call($N3, $q($N3));
$old = array_filter(klines($k), fn($x) => $x[1] < $N3 - 60 * 86400);
eq([$c, $old], [200, []], 'an hour later → no provider call for the part older than 60 days (it is already in the pool)');
eq(array_filter(klines($k), fn($x) => $x[0] < $N3 - 2 * $H), [], '… only the new tail is fetched');
$t3 = $hours($r3['candles']);
eq([$contig($t3), count(array_unique($t3)) === count($t3), $t3[0] >= $N3 - 90 * 86400, end($t3) > end($r0['candles'])['t']], [true, true, true, true],
    '… contiguous across the 60-day boundary, no duplicates, the new candle in');

// ── יום אחרי: הגבול זז ביום — שוב בלי פנייה לחלק הישן ──
$N4 = $N + 86400;
$k = $logLen();
[$c, $r4] = call($N4, $q($N4));
eq([$c, array_filter(klines($k), fn($x) => $x[1] < $N4 - 60 * 86400), $contig($hours($r4['candles']))], [200, [], true], 'a day later → still no call for the old part, still contiguous');

$err = array_values(array_filter(explode("\n", is_file("$tmp.error.log") ? (string)file_get_contents("$tmp.error.log") : ''),
    fn($l) => trim($l) !== '' && !str_contains($l, ' in Unknown on line 0')));
eq($err, [], 'no PHP errors on the server');
echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
