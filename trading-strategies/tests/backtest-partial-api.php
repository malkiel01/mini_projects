<?php
// בדיקה לאחור מול ספק איטי. php trading-strategies/tests/backtest-partial-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
// הבעיה (נמצאה בתקלת ה-500, 16.11): h1 מושך שנתיים; בספק איטי המשיכה לבדה עוברת את תקרת הזמן → incomplete, שום דבר לא
// נשמר, וכל analysis מושך הכול מחדש. עכשיו: מה שנמשך נבדק, המצב נשמר (bt_partial), והקריאה הבאה ממשיכה ממנו.
// - שני שרתים: A — ספק איטי (TS_KLINES_DELAY_MS) ותקרה קצרה (TS_BT_MAX_SECONDS); B — ספק מהיר, התקרה הרגילה.
// - ב-A: הקריאה הראשונה לא מסיימת; אף פנייה לספק לא חוזרת על טווח שכבר נמשך; אחרי כמה קריאות — הושלמה.
// - התוצאה ב-A זהה לתוצאה ב-B (אותו "עכשיו") — המשך מהמצב לא משנה את הבדיקה לאחור כשהנתונים מלאים.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$base = sys_get_temp_dir() . '/ts-bt-partial-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
$NOW = 1794834030;   // 16.11 13:00:30 UTC
$servers = [];
register_shutdown_function(function () use (&$servers, $base) {
    foreach ($servers as $s) proc_terminate($s['proc']);
    foreach (glob("$base*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
function server(string $name, array $env): void
{
    global $servers, $base, $root;
    $tmp = "$base-$name";
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $env = $env + ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
    $proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'memory_limit=512M'],
        [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
    $servers[$name] = ['proc' => $proc, 'port' => $port, 'tmp' => $tmp, 'jar' => null, 'csrf' => ''];
}
/** [קוד, JSON מפוענח] */
function call(string $srv, string $action, ?array $body = null, array $query = []): array
{
    global $servers, $NOW;
    $s = &$servers[$srv];
    $h = ['X-Dev-As: owner', "X-Dev-Now: $NOW"];
    if ($s['jar']) $h[] = 'Cookie: ts_session=' . $s['jar'];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . $s['csrf']; }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:{$s['port']}/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $s['jar'] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
/** הנרות (שעה) שכל פנייה לספק ביקשה, לפי startTime–endTime: נר שנפתח בטווח. נר שמופיע פעמיים = נמשך פעמיים */
function klines(string $srv): array
{
    global $servers;
    $out = [];
    foreach (explode("\n", (string)@file_get_contents($servers[$srv]['tmp'] . '.http.log')) as $l) {
        $u = json_decode($l, true)['url'] ?? '';
        if (!str_contains($u, '/klines') || !str_contains($u, 'interval=1h') || !preg_match('/startTime=(\d+).*endTime=(\d+)/', $u, $m)) continue;
        for ($t = (int)ceil(intdiv((int)$m[1], 1000) / 3600) * 3600; $t <= intdiv((int)$m[2], 1000); $t += 3600) $out[] = $t;
    }
    return $out;
}
function setup(string $srv): void
{
    global $servers;
    $servers[$srv]['csrf'] = call($srv, 'me')[1]['csrf'];
    call($srv, 'admin_method_save', ['id' => 'sample-ma-cross', 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1'], 'params' => [], 'note' => '']);
}
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'sample-ma-cross'];
$bt = fn(array $r) => $r['cards'][0]['backtest'] ?? [];

// ── B: ספק מהיר — הבדיקה מסתיימת בקריאה אחת (כמו קודם) ──
server('fast', []);
setup('fast');
[$c, $r] = call('fast', 'analysis', null, $q);
$ref = $bt($r);
eq([$c, $ref['status'] ?? null, $ref['mode'] ?? null], [200, 'ok', 'full'], 'fast provider: one call, full backtest');
eq(count(klines('fast')) > 17000, true, 'h1 pulls about two years (more than 17,000 candles)');

// ── A: ספק איטי (150ms לפנייה) ותקרה של 1.2 שניות ──
server('slow', ['TS_KLINES_DELAY_MS' => '150', 'TS_BT_MAX_SECONDS' => '1.2']);
setup('slow');
[$c, $r] = call('slow', 'analysis', null, $q);
eq([$c, $bt($r)['status'] ?? null], [200, 'incomplete'], 'slow provider: the first call does not finish in time');
eq(str_contains($bt($r)['message'] ?? '', 'תמשיך מאותה נקודה'), true, 'the message says it will continue from the same point');
$first = klines('slow');
eq(count($first) > 0 && count($first) < count(klines('fast')), true, 'the first call pulled only part of the history');

$calls = 1; $last = $r;
while (($bt($last)['status'] ?? '') === 'incomplete' && $calls < 12) {
    [$c, $last] = call('slow', 'analysis', null, $q);
    $calls++;
}
$all = klines('slow');
eq($bt($last)['status'] ?? null, 'ok', "after $calls calls the backtest is complete");
eq($calls > 1, true, 'it took more than one call');
$cv = function (array $a) { $c = array_count_values($a); ksort($c); return $c; };
eq(count($all), count(klines('fast')), 'altogether — the same number of candles from the provider as one full run (before: every call started over)');
eq($cv($all) === $cv(klines('fast')), true, 'and exactly the same candles — nothing that was already pulled is pulled again');

// ── אותה תוצאה כמו בריצה אחת ──
$keys = ['n', 'wins', 'win_rate', 'expectancy_r', 'max_dd', 'from_t', 'to_t', 'bars', 'signals', 'skipped', 'certainty', 'win_pct'];
$pick = fn(array $b) => array_intersect_key($b, array_flip($keys));
eq($pick($bt($last)), $pick($ref), 'the result after continuing = the result of one full run');

// ── הפעם הבאה: מהשמור, בלי פנייה ──
[$c, $r] = call('slow', 'analysis', null, $q);
eq([$bt($r)['status'] ?? null, $bt($r)['mode'] ?? null, count(klines('slow'))], ['ok', 'stored', count($all)], 'the next call — stored, without the provider');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
