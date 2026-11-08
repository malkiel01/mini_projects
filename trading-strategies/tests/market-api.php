<?php
// בדיקות API מקצה לקצה לשלב א' (spec-stage-a פרק 15): בידוד, מנהל, מפתחות, מאגר ורישוי, תקרות.
// php trading-strategies/tests/market-api.php — php -S עם שער מדומה (dev-prepend) וספקים מדומים (fixtures/market).
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-market-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
// פורט פנוי מהמערכת ושם ייחודי — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת או קבצים של ריצה אחרת (#1333)
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
foreach (['alice', 'bob', 'carol', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];

// תווים אקראיים — בלי מילים שמופיעות בתשובות ממילא (שם, פעולה), אחרת החיפוש של 5 תווים מוצא "דליפה" שאינה
$KEYS = ['alice' => 'good-Qz7f3a9c21e5', 'bob' => 'good-Wx0b44d1e9aa', 'admin' => 'good-Rk55c0f9e42',
    'bad' => 'bad-Jv8TqLmZ3pXy'];
$now = time();

// ── מפתחות ובידוד ───────────────────────────────────────
[$c, $r] = call('alice', 'cred_save', ['provider' => 'twelvedata', 'secret' => $KEYS['alice'], 'label' => 'שלי']);
eq($c, 200, 'alice saves a Twelve Data key (tested first)');
$td = array_values(array_filter($r['providers'], fn($p) => $p['id'] === 'twelvedata'))[0];
eq([$td['available'], $td['source'], $td['own']['last4'], $td['own']['last_test_ok']], [true, 'own', '21e5', true], 'shown as connected, last 4 only');
[$c, $r] = call('alice', 'cred_save', ['provider' => 'finnhub', 'secret' => $KEYS['bad']]);
eq($c, 422, 'bad key refused, not saved');
eq(is_string($r['error'] ?? null) && $r['error'] !== '', true, 'with a message');

$bp = array_column(call('bob', 'market_providers')[1]['providers'], null, 'id');
eq([$bp['twelvedata']['available'], $bp['twelvedata']['own']], [false, null], 'bob does not see alice\'s key');
eq(call('bob', 'market_candles', null, ['asset' => 'twelvedata:AAPL', 'tf' => 'h1'])[0], 422, 'bob cannot fetch via alice\'s key');
eq(call('bob', 'cred_delete', ['provider' => 'twelvedata'])[0], 404, 'bob cannot delete alice\'s key');
eq(call('bob', 'cred_test', ['provider' => 'twelvedata'])[0], 404, 'bob cannot test alice\'s key');
eq(call('alice', 'market_providers')[1]['providers'][1]['own']['last4'] ?? null, '21e5', 'alice\'s key untouched');

call('alice', 'watchlist_add', ['asset' => 'binance:BTCUSDT']);
eq(call('bob', 'watchlist')[1]['watchlist'], [], 'bob does not see alice\'s watchlist');
eq(call('bob', 'watchlist_remove', ['asset' => 'binance:BTCUSDT'])[0], 404, 'bob cannot remove from alice\'s watchlist');
eq(count(call('alice', 'watchlist')[1]['watchlist']), 1, 'alice\'s watchlist untouched');
eq(call('alice', 'watchlist_add', ['asset' => 'nope:X'])[0], 422, 'invalid asset refused');

// ── מאגר ורישוי ─────────────────────────────────────────
$q = ['tf' => 'h1', 'from' => $now - 24 * 3600, 'to' => $now];
eq(call('alice', 'market_candles', null, $q + ['asset' => 'twelvedata:AAPL'])[1]['provider_calls'], 1, 'alice fetches with her key');
call('bob', 'cred_save', ['provider' => 'twelvedata', 'secret' => $KEYS['bob']]);
[$c, $r] = call('bob', 'market_candles', null, $q + ['asset' => 'twelvedata:AAPL']);
eq([$c, $r['provider_calls'], $r['source']], [200, 1, 'own'], 'bob\'s request goes to the provider with his key — alice\'s personal data is not served to him');
eq(call('alice', 'market_candles', null, $q + ['asset' => 'binance:BTCUSDT'])[1]['provider_calls'], 1, 'keyless asset: first fetch');
eq(call('bob', 'market_candles', null, $q + ['asset' => 'binance:BTCUSDT'])[1]['provider_calls'], 0, 'keyless asset: shared pool for bob');

// ── מנהל ────────────────────────────────────────────────
$ADMIN_POST = ['admin_provider_save' => ['provider' => 'binance', 'enabled' => false], 'admin_shared_switch' => ['on' => true],
    'admin_cred_save' => ['provider' => 'finnhub', 'secret' => 'good-x-123456789'], 'admin_cred_test' => ['id' => 1], 'admin_cred_delete' => ['id' => 1],
    'admin_grant_save' => ['cred_id' => 1, 'user_id' => 1, 'daily_limit' => 5], 'admin_grant_delete' => ['cred_id' => 1, 'user_id' => 1],
    'admin_role_add' => ['user_id' => 1], 'admin_role_remove' => ['user_id' => 1], 'admin_cache_clear' => [], 'admin_cache_cleanup' => []];
eq(call('alice', 'admin_state')[0], 403, 'admin_state → 403 for a regular user');
foreach ($ADMIN_POST as $a => $b) eq(call('alice', $a, $b)[0], 403, "$a → 403 for a regular user");
eq(call('alice', 'market_providers')[1]['providers'][0]['enabled'], true, 'nothing changed by the 403 attempts');
eq(call('owner', 'admin_state')[0], 200, 'primary admin (platform_admin) → 200');

$carolId = array_values(array_filter(call('owner', 'admin_state')[1]['users'], fn($u) => $u['display_name'] === 'קרול'))[0]['id'];
eq(call('owner', 'admin_role_add', ['user_id' => $carolId])[0], 200, 'primary appoints carol');
eq(call('carol', 'admin_state')[0], 200, 'carol is admin');
eq(call('carol', 'admin_role_add', ['user_id' => 2])[0], 403, 'non-primary admin cannot appoint');
eq(call('carol', 'admin_shared_switch', ['on' => true])[0], 403, 'non-primary admin cannot flip the master switch');
eq(call('owner', 'admin_role_remove', ['user_id' => $carolId])[0], 200, 'primary revokes carol');
eq(call('carol', 'admin_state')[0], 403, 'revoked admin → 403 immediately');
eq(call('carol', 'admin_cache_clear', [])[0], 403, 'revoked admin → 403 on writes too');

// ── ספק (ב): המתג הראשי ────────────────────────────────
[$c, $r] = call('owner', 'admin_cred_save', ['provider' => 'finnhub', 'secret' => $KEYS['admin'], 'label' => 'לחלוקה']);
$cid = $r['creds'][0]['id'];
call('owner', 'admin_grant_save', ['cred_id' => $cid, 'user_id' => $carolId, 'daily_limit' => 2]);
$fin = fn() => array_column(call('carol', 'market_providers')[1]['providers'], null, 'id')['finnhub'];
eq([$fin()['available'], call('owner', 'admin_state')[1]['shared_enabled']], [false, false], 'master switch off by default → grant gives nothing');
eq(call('carol', 'market_candles', null, ['asset' => 'finnhub:AAPL', 'tf' => 'd1'])[0], 422, 'carol cannot fetch while off');
call('owner', 'admin_shared_switch', ['on' => true]);
eq([$fin()['available'], $fin()['source']], [true, 'shared'], 'primary turns it on → carol gets the shared provider');
eq(call('carol', 'market_candles', null, ['asset' => 'finnhub:AAPL', 'tf' => 'd1', 'from' => 1772400000, 'to' => 1772600000])[0], 200, 'carol fetches via grant');
call('carol', 'market_candles', null, ['asset' => 'finnhub:MSFT', 'tf' => 'd1', 'from' => 1772400000, 'to' => 1772600000]);
eq(call('carol', 'market_candles', null, ['asset' => 'finnhub:NVDA', 'tf' => 'd1', 'from' => 1772400000, 'to' => 1772600000])[0], 502, 'grant daily limit (2) enforced');
call('owner', 'admin_shared_switch', ['on' => false]);
eq($fin()['available'], false, 'switch off → gone');
eq(count(array_filter(call('owner', 'admin_state')[1]['log'], fn($l) => $l['action'] === 'shared_switch')), 2, 'admin actions logged');

// ── מפתחות לא דולפים: שום תשובה, ייצוא, לוג או שגיאה ──
call('alice', 'cred_save', ['provider' => 'twelvedata', 'secret' => $KEYS['bad']]);   // הספק המדומה מחזיר את המפתח בשגיאה
call('owner', 'admin_cred_save', ['provider' => 'twelvedata', 'secret' => $KEYS['bad']]);
$bodies[] = json_encode(call('alice', 'export', null, ['format' => 'json'])[1]);
$bodies[] = (string)call('alice', 'export', null, ['format' => 'csv'])[1];
$texts = ['responses' => implode("\n", $bodies), 'server log' => (string)@file_get_contents("$tmp.server.log"),
    'error log' => (string)@file_get_contents("$tmp.error.log")];
foreach ($KEYS as $who => $k) {
    foreach ($texts as $where => $txt) {
        $leak = null;
        for ($len = strlen($k); $len >= 5 && !$leak; $len--) {
            for ($i = 0; $i + $len <= strlen($k); $i++) if (str_contains($txt, substr($k, $i, $len))) { $leak = substr($k, $i, $len); break; }
        }
        eq($leak, null, "key «{$who}» (or 5+ chars of it) not in $where");
    }
}
$urls = implode("\n", array_map(fn($l) => json_decode($l, true)['url'], file("$tmp.http.log")));
foreach ($KEYS as $who => $k) eq(str_contains($urls, substr($k, 0, 12)), false, "key «{$who}» never in a provider URL");

// ── תקרה: 30 בקשות נתונים לדקה ─────────────────────────
if ((int)date('s') > 45) sleep(61 - (int)date('s'));      // לא לחצות דקה באמצע הבדיקה
$codes = [];
for ($i = 0; $i < 31; $i++) $codes[] = call('dave', 'market_candles', null, $q + ['asset' => 'binance:BTCUSDT'])[0];
eq(array_count_values($codes), [200 => 30, 429 => 1], '31st data request in a minute → 429');
eq(call('dave', 'watchlist')[0], 200, 'non-data requests are not limited');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
