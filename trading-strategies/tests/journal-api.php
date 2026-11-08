<?php
// בדיקות החיבור ליומן (spec-stage-a 11, 12 #8, ✅12; פרק 15: המרת מטבע, כסף, בידוד), ושני תיקונים מהבקרה:
// מנוי פוש לא עובר בעלות בשקט (#1275), והלוג של ה-cron מתקצר לבד. בלי רשת.
// php trading-strategies/tests/journal-api.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-journal-api-' . getmypid();
$port = 23000 + getmypid() % 1000;
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
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
$jar = []; $tokens = [];
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$ecbCalls = fn() => count(array_filter(is_file("$tmp.http.log") ? file("$tmp.http.log") : [], fn($l) => str_contains(json_decode($l, true)['url'] ?? '', 'frankfurter.dev/v1/2')));
$trade = fn(array $o) => $o + ['market' => 'stocks', 'currency' => 'USD', 'multiplier' => '1', 'direction' => 'long', 'opened_at' => '2026-03-02T10:00',
    'entry_price' => '100', 'quantity' => '10', 'exits' => [['exited_at' => '2026-03-04T12:00', 'price' => '110', 'quantity' => '10']]];

// ── "פתח עסקה מזה": הנכס ביומן לפי סימול מנורמל ───────────
$p = call('alice', 'trade_prefill', null, ['asset' => 'binance:BTCUSDT'])[1]['prefill'];
eq([$p['existing'], $p['symbol'], $p['market'], $p['currency'], $p['multiplier'], $p['asset']], [false, 'BTCUSDT', 'crypto', 'USDT', '1', 'binance:BTCUSDT'],
    'new asset: symbol without the provider, market and currency from the provider, default multiplier — for the user to confirm');
eq(call('alice', 'trade_prefill', null, ['asset' => 'ecb:EURUSD'])[1]['prefill']['market'], 'forex', 'ECB pair → forex');
eq(call('alice', 'trade_prefill', null, ['asset' => 'nope:X'])[0], 422, 'invalid asset');
call('alice', 'trade_save', $trade(['symbol' => 'BTC/USDT', 'market' => 'crypto', 'currency' => 'USDT', 'multiplier' => '2']));
$p = call('alice', 'trade_prefill', null, ['asset' => 'binance:BTCUSDT'])[1]['prefill'];
eq([$p['existing'], $p['symbol'], $p['multiplier']], [true, 'BTC/USDT', '2'], 'existing in "my assets" (BTC/USDT = BTCUSDT normalized) → used, with the user\'s own multiplier');
eq(call('bob', 'trade_prefill', null, ['asset' => 'binance:BTCUSDT'])[1]['prefill']['existing'], false, 'isolation: bob does not get alice\'s asset');

// ── השיטה ותמונת הניתוח בעסקה ────────────────────────────
call('owner', 'admin_method_save', ['id' => 'test-cross-a', 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1']]);
call('owner', 'admin_method_save', ['id' => 'sample-ma-cross', 'enabled' => true]);
$snap = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'method_id' => 'test-cross-a', 'bias' => 'long', 'levels' => ['entry' => '67000.5', 'stop' => '66000', 'target' => '68500'], 'certainty' => 55];
[$c, $r] = call('alice', 'trade_save', $trade(['symbol' => 'BTCUSDT', 'market' => 'crypto', 'currency' => 'USDT', 'method_id' => 'test-cross-a', 'analysis_snapshot' => $snap]));
eq($c, 200, 'trade opened from an analysis card');
$t = $r['trade'];
eq([$t['method_id'], $t['method_name'], $t['analysis_snapshot']['asset'], $t['analysis_snapshot']['levels']['entry']], ['test-cross-a', 'בדיקה א', 'binance:BTCUSDT', '67000.5'],
    'the trade keeps the method and the snapshot with the original provider:symbol');
$tid = $t['id'];
[$c, $r] = call('alice', 'trade_save', ['id' => $tid] + $trade(['symbol' => 'BTCUSDT', 'market' => 'crypto', 'currency' => 'USDT', 'quantity' => '10']));
eq([$c, $r['trade']['method_id'], $r['trade']['analysis_snapshot']['tf'] ?? null], [200, 'test-cross-a', 'h1'], 'editing the trade (without those fields) does not drop them');
eq(call('alice', 'trade_save', $trade(['symbol' => 'X1', 'method_id' => 'sample-ma-cross']))[0], 422, 'a method the user cannot see (sample, not admin) → refused');
eq(call('alice', 'trade_save', $trade(['symbol' => 'X1', 'method_id' => 'nope']))[0], 422, 'unknown method → refused');
eq(call('alice', 'trade_save', $trade(['symbol' => 'X1', 'method_id' => 'test-cross-a', 'analysis_snapshot' => ['asset' => 'nope:X']]))[0], 422, 'snapshot must carry a valid provider:symbol');
eq(call('alice', 'trade_save', $trade(['symbol' => 'X1', 'method_id' => 'test-cross-a', 'analysis_snapshot' => ['asset' => 'binance:BTCUSDT', 'x' => str_repeat('a', 9000)]]))[0], 422, 'snapshot over 8KB → refused');
eq(call('owner', 'trade_save', $trade(['symbol' => 'X1', 'method_id' => 'sample-ma-cross']))[0], 200, 'an admin may record a trade from the sample method (it is visible to them)');
eq(call('bob', 'trade', null, ['id' => $tid])[0], 404, 'isolation: bob cannot read alice\'s trade (and its snapshot) by id');

// ── סטטיסטיקה לפי שיטה ───────────────────────────────────
$s = call('alice', 'stats')[1];
$usdt = array_values(array_filter($s['by_currency'], fn($x) => $x['currency'] === 'USDT'))[0];
$bm = array_column($usdt['breakdown']['method'], 'count', 'key');
ksort($bm);
eq($bm, ['none' => 1, 'test-cross-a' => 1], 'breakdown by method (and "no method")');
eq($s['method_names']['test-cross-a'] ?? null, 'בדיקה א', 'method names for the breakdown');
eq(count(call('alice', 'stats', null, ['method_id' => 'test-cross-a'])[1]['by_currency']), 1, 'filter by method');
eq(call('alice', 'trades', null, ['method_id' => 'none'])[1]['trades'][0]['symbol'], 'BTC/USDT', 'journal filter: trades without a method');
eq(call('bob', 'stats')[1]['by_currency'], [], 'bob\'s stats have none of alice\'s trades');

// ── המרת מטבע לפי ECB (✅12) ─────────────────────────────
// בוב: מטבע בסיס USD; עסקאות ב-ILS, USDT, USD ו-BTC
foreach ([
    ['symbol' => 'TEVA', 'currency' => 'ILS'],                                                                  // נטו 100 ILS, נסגר ד' 4.3
    ['symbol' => 'ELAL', 'currency' => 'ILS', 'exits' => [['exited_at' => '2026-03-08T12:00', 'price' => '110', 'quantity' => '10']]],   // נסגר א' 8.3 — אין שער
    ['symbol' => 'ETHUSDT', 'market' => 'crypto', 'currency' => 'USDT', 'entry_price' => '100', 'exits' => [['exited_at' => '2026-03-04T12:00', 'price' => '105', 'quantity' => '10']]],   // 50 USDT
    ['symbol' => 'AAPL', 'currency' => 'USD', 'exits' => [['exited_at' => '2026-03-04T12:00', 'price' => '98', 'quantity' => '10']]],      // −20 USD
    ['symbol' => 'SOLBTC', 'market' => 'crypto', 'currency' => 'BTC', 'exits' => [['exited_at' => '2026-03-04T12:00', 'price' => '101', 'quantity' => '1']], 'quantity' => '1'],   // 1 BTC
] as $o) eq(call('bob', 'trade_save', $trade($o))[0], 200, 'bob records ' . $o['symbol']);
$before = $ecbCalls();
$b = call('bob', 'stats')[1]['in_base'];
eq([$b['currency'], $b['converted'], $b['approx'], $b['pegged'], $b['skipped']], ['USD', 4, 1, 1, ['BTC' => 1]],
    'in the base currency: 4 converted, 1 by an earlier day\'s rate, 1 USDT as USD, BTC not converted (no ECB rate)');
$rates = array_column(array_filter($b['rates'], fn($x) => $x['currency'] === 'ILS'), null, 'closed_day');
eq([$rates['2026-03-04']['rate'] ?? null, $rates['2026-03-08']['rate_day'] ?? null, $rates['2026-03-08']['exact'] ?? null], ['0.2704', '2026-03-06', false],
    'the ECB rate of the closing day; a day with no rate (Sunday) → the last one before (Friday), marked');
eq($b['net'], '84.1', 'net in USD, in decimal: 100×0.2704 + 100×0.2706 + 50 − 20 = 84.10');
eq(count(array_filter(call('bob', 'stats')[1]['by_currency'], fn($x) => $x['currency'] === 'ILS')), 1, 'the per-currency stats stay as they were (stage 1 unchanged)');
eq($ecbCalls() - $before, 2, 'one ECB request per pair (ILS→USD, and BTC→USD which has no rate)');
call('bob', 'stats');
eq($ecbCalls() - $before, 2, 'second time — from the stored rates (and the remembered "no rate"), no request');
eq((int)$db->query("SELECT COUNT(*) FROM fx_rates WHERE base = 'ILS' AND quote = 'USD'")->fetchColumn() > 0, true, 'rates stored');
call('bob', 'settings', ['base_currency' => 'ILS', 'timezone' => 'Asia/Jerusalem', 'account_size' => '', 'default_risk_pct' => '1']);
$bi = call('bob', 'stats')[1]['in_base'];
eq([$bi['currency'], $bi['skipped']], ['ILS', ['BTC' => 1]], 'base currency from the settings (ILS): USD/USDT converted to ILS');
call('owner', 'admin_provider_save', ['provider' => 'ecb', 'enabled' => false]);
$db->exec('DELETE FROM fx_rates; DELETE FROM fx_cover;');
$be = call('bob', 'stats')[1];
eq([count($be['by_currency']) > 0, count($be['in_base']['errors'] ?? []) > 0], [true, true], 'ECB off → stats per currency still work, conversion says why it is missing');
call('owner', 'admin_provider_save', ['provider' => 'ecb', 'enabled' => true]);

// ── פוש: מנוי לא עובר בעלות בשקט (ממצא בקרה #1275) ────────
$E = 'https://push.test/device-1';
call('alice', 'push_subscribe', ['endpoint' => $E, 'keys' => ['p256dh' => 'a1', 'auth' => 'a2']]);
$id1 = (int)$db->query("SELECT id FROM push_subs WHERE endpoint = '$E'")->fetchColumn();
call('alice', 'push_subscribe', ['endpoint' => $E, 'keys' => ['p256dh' => 'a3', 'auth' => 'a4']]);
eq([(int)$db->query("SELECT id FROM push_subs WHERE endpoint = '$E'")->fetchColumn(), json_decode($db->query("SELECT keys FROM push_subs WHERE endpoint = '$E'")->fetchColumn(), true)['p256dh']],
    [$id1, 'a3'], 'same user re-subscribing → same row, keys updated');
call('bob', 'push_subscribe', ['endpoint' => $E, 'keys' => []]);
$rows = $db->query("SELECT id, user_id, keys FROM push_subs WHERE endpoint = '$E'")->fetchAll(PDO::FETCH_ASSOC);
$bobId = (int)$db->query("SELECT id FROM users WHERE platform_sub = '102'")->fetchColumn();
eq([count($rows), (int)$rows[0]['user_id'], json_decode($rows[0]['keys'], true)], [1, $bobId, []], 'another user with the same endpoint → the old subscription is deleted and a new one created (no silent ownership change)');

// ── הלוג של ה-cron מתקצר לבד ─────────────────────────────
$log = "$tmp.sqlite.alerts-cron.log";
$fh = fopen($log, 'w');
for ($i = 1; $i <= 6000; $i++) fwrite($fh, sprintf("{\"line\":%d,\"pad\":\"%s\"}\n", $i, str_repeat('x', 180)));
fclose($fh);
eq(filesize($log) > 1_000_000, true, 'a log over 1MB');
$pp = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', "$root/trading-strategies/bin/alerts-cron.php", '--now=' . time()],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
stream_get_contents($pipes[1]); proc_close($pp);
$lines = file($log, FILE_IGNORE_NEW_LINES);
eq([count($lines), json_decode($lines[0], true)['line'] ?? null, json_decode(end($lines), true)['line'] ?? null], [1000, 5001, 6000], 'cron trims its log to the last 1,000 lines');
clearstatcache();
$size = filesize($log);
$pp = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', "$root/trading-strategies/bin/alerts-cron.php", '--now=' . time()],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
stream_get_contents($pipes[1]); proc_close($pp);
clearstatcache();
eq(filesize($log), $size, 'under 1MB — left untouched');
eq(str_contains((string)file_get_contents(dirname(__DIR__) . '/bin/alerts-cron.php'), '>> ~/public_html/mini_projects/trading-strategies/data/alerts-cron.log'), true, 'the docblock: public_html path, output to the log in data/');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
