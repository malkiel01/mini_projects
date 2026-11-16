<?php
// L6 דרך ה-API (spec-stage-a 23.7, קריטריונים L6 1–3): הכרטיס — "ראשונית — מבדיקה לאחור (N = …)", ושורה שנייה "בפועל מאז" רק
// מ-30 שורות live; המספר הראשי לא מתחלף לבד; "החלף לבפועל" — רק עורך ראשי, בלי להעלות גרסה. גם בגרף (chart_refresh).
// php trading-strategies/tests/learn-card-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת. owner — עורך ראשי, carol — עורך משני.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-learn-card-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
// פורט פנוי מהמערכת ושם ייחודי — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת או קבצים של ריצה אחרת (#1333)
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
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
$M = 'sample-ma-cross';
$NOW = 1794094200;
eq(call('owner', 'admin_role_add', ['user_id' => $uidOf('103')])[0], 200, 'carol — secondary editor');
eq(call('owner', 'admin_method_save', ['id' => $M, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'], 'params' => [], 'note' => ''])[0], 200, 'the sample method on');
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $M];
$card = fn(string $as = 'owner') => call($as, 'analysis', null, $q)[1]['cards'][0];
$chart = fn() => call('owner', 'chart_refresh', null, $q + ['from' => $NOW - 10 * 86400, 'to' => $NOW])[1]['analysis']['cards'][0];

// ── קריטריון 1: רק backtest → "ראשונית" ──
$c = $card();
$bt = $c['backtest'];
eq([$bt['status'], $c['certainty'] === $bt['certainty'], $c['certainty_basis'], $c['live']], ['ok', true, 'backtest', null],
    'criterion 1: only backtest rows → the main number is the backtest one ("ראשונית — מבדיקה לאחור"), no second line');
$bRows = (int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE source = 'backtest' AND method_id = '$M'")->fetchColumn();
eq($bRows, (int)$bt['n'], 'the backtest rows of the card are in method_outcomes (L1) — and none of them is live');

// ── קריטריון 2: 29 live → אין שורה שנייה; 30 → "בפועל מאז" ──
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, cred_scope, t_signal, t_exit, side, result, win, r,
    market, month, quarter, weekday, hour, holidays, created_at) VALUES ('live', ?, 1, 'x', 'binance', 'binance:BTCUSDT', 'h1', '', ?, ?, 'long', ?, ?, ?, 'crypto', 1, 1, 1, 0, '[]', 0)");
$liveAdd = function (int $count, int $wins) use ($ins, $M) {
    static $t = 1_900_000_000;
    for ($i = 0; $i < $count; $i++) { $t += 3600; $w = $i < $wins; $ins->execute([$M, $t, $t + 1800, $w ? 'win' : 'loss', $w ? 1 : 0, $w ? 1.0 : -1.0]); }
};
$liveAdd(29, 0);
eq([$card()['live'], $chart()['live']], [null, null], 'criterion 2: 29 live rows → no second line (the analysis and the chart)');
$liveAdd(1, 0);
$c = $card();
eq([$c['live']['n'] ?? null, $c['live']['certainty'] ?? null, $c['live']['scope'] ?? null, $c['live']['switched'] ?? null], [30, 0, 'segment', false],
    'criterion 2: 30 live rows → "בפועל מאז: 0% (N = 30)" for crypto × h1');
eq($chart()['live']['n'] ?? null, 30, '… and the same line in the chart refresh (chart_refresh)');
eq([$c['certainty'], $c['certainty_basis']], [$bt['certainty'], 'backtest'], 'criterion 3: the main number does not change by itself');
$c4 = call('owner', 'analysis', null, ['tf' => 'h4'] + $q)[1]['cards'][0];
eq([$c4['live']['n'] ?? null, $c4['live']['scope'] ?? null], [30, 'general'], 'ל2: h4 has no live rows → the method and its version in all markets, "כללי — לא לשוק הזה"');

// ── קריטריון 3: רווחי סמך שלא חופפים → הצעה; "החל" — רק עורך ראשי ──
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.sqlite.cache");
require __DIR__ . '/../lib/tune.php';
$S = ts_learn_switch_sync($NOW);
eq($S['proposed'] >= 1, true, 'the cron step: backtest vs live 0/30 — apart → a proposal');
[$code, $L] = call('carol', 'admin_tune_list');
$P = array_values(array_filter($L['proposals'] ?? [], fn($x) => $x['param'] === '@certainty' && $x['segment'] === 'crypto|h1'))[0] ?? null;
eq([$code, $P['status'] ?? null, $P['origin'] ?? null, $L['can_apply'] ?? null], [200, 'open', 'learning', false], 'a secondary editor sees the proposal, and cannot apply');
eq(call('carol', 'admin_tune_apply', ['id' => $P['id']])[0], 403, 'criterion 3: "החלף לבפועל" by a secondary editor → 403');
eq(call('alice', 'admin_tune_apply', ['id' => $P['id']])[0], 403, '… by a user → 403');
eq([$card()['certainty_basis'], call('owner', 'admin_tune_list')[1]['proposals'][0]['status'] !== 'applied'], ['backtest', true], 'still not switched');
[$code, $r] = call('owner', 'admin_tune_apply', ['id' => $P['id']]);
eq([$code, $r['proposal']['status'] ?? null, array_key_exists('new_version', $r['proposal'] ?? []) ? $r['proposal']['new_version'] : 'x'], [200, 'applied', null], 'the chief editor applies — no new version');
$ver = array_values(array_filter($r['methods'] ?? [], fn($x) => $x['id'] === $M))[0]['version'] ?? null;
eq($ver, 1, 'the method version stays 1 (the live rows of this version still count)');
$c = $card();
eq([$c['certainty_basis'], $c['certainty'], $c['live']['switched'], $c['backtest']['certainty']], ['live', 0, true, $bt['certainty']],
    'after the switch: the main number is "בפועל מאז"; the backtest number is still in the card');
eq($chart()['certainty_basis'], 'live', '… also in the chart');
eq(call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'elliott-w3'])[1]['cards'][0]['certainty_basis'] ?? null, 'backtest',
    'another method is untouched (23.1)');
$log = array_values(array_filter(call('owner', 'admin_state')[1]['log'], fn($l) => $l['action'] === 'method_cert_live'));
eq([count($log), str_contains($log[0]['target'] ?? '', 'crypto|h1')], [1, true], 'logged in admin_log (method_cert_live)');
eq(call('owner', 'admin_tune_apply', ['id' => $P['id']])[0], 422, 'applying twice → 422');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
