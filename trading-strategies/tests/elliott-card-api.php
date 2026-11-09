<?php
// בדיקות 4א/3 — elliott-w3 בניהול, בכרטיס ובבדיקה לאחור (spec-stage-a 17.1.9 ג, ו, ו1, ז, ז0; 6.7; ✅17, ✅18).
// php trading-strategies/tests/elliott-card-api.php — חלק טהור, ואחריו php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-ew-card-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── חלק טהור ─────────────────────────────────────────────
define('TS_DB_PATH', "$tmp.pure.sqlite");   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$m = ts_method('elliott-w3');
$P = $m->params();
eq([ts_method_exists('elliott-w3'), get_class($m)], [true, 'TsMethodElliottW3'], 'elliott-w3 is registered (registry.php)');
// tick: רק לשיטה שמבקשת (meta.tick), ונכנס ל-hash
eq([ts_params_for_asset($m, $P, 2)['tick'], ts_params_for_asset($m, $P, 0)['tick'], array_key_exists('tick', ts_params_for_asset(ts_method('sample-ma-cross'), ts_method('sample-ma-cross')->params(), 2))],
    [0.01, 1, false], 'tick = 10^−decimals for elliott-w3 only; sample-ma-cross unchanged (same hash as before)');
eq(ts_bt_hash(ts_params_for_asset($m, $P, 2), ['fee' => 0, 'slip' => 0]) !== ts_bt_hash(ts_params_for_asset($m, $P, 4), ['fee' => 0, 'slip' => 0]), true, 'a different tick → a different backtest hash');
// ו1: הכרטיס ממשיך ממצב המנוע = אותו מצב כמו ריצה מלאה מנר 0
$cost = ['fee' => 0.001, 'slip' => 0.0005];
$same = 0; $checked = 0;
foreach ([1500, 3000, 4500] as $k) {
    $state = json_decode(json_encode(ts_bt_run($m, $P, array_slice($F, 0, $k), $cost)), true);
    foreach ([$k + 7, $k + 200] as $end) {
        $window = array_slice($F, max(0, $end - 700), $end - max(0, $end - 700));   // חלון אחרון, כמו בכרטיס
        [$a, $basis] = ts_analyze_for_card($m, $P, $window, $state);
        $full = $m->analyze(array_slice($F, 0, $end), $P);
        $checked++;
        if ($basis === 'state' && json_encode([$a['bias'], $a['conditions'], $a['levels'], $a['as_of']]) === json_encode([$full['bias'], $full['conditions'], $full['levels'], $full['as_of']])) $same++;
    }
}
eq($same, $checked, "ו1: the card continued from the backtest state = the analysis from bar 0 ($checked windows)");
[$a, $basis] = ts_analyze_for_card($m, $P, array_slice($F, 0, 900), null);
eq([$basis, $a['as_of']], ['warmup', $F[899]['t']], 'ו1: no stored state → from the window (basis: warmup)');
[, $basis] = ts_analyze_for_card(ts_method('sample-ma-cross'), ts_method('sample-ma-cross')->params(), array_slice($F, 0, 900), null);
eq($basis, null, 'a method without analyze_from_state — as before (no basis mark)');
// ז0
$notes = ts_param_notes($m);
eq([$notes['w3_mult']['note'], $notes['w2_min']['note'], count(array_filter($notes, fn($x) => $x['note'] === 'הגדרה שלנו')), ts_param_notes(ts_method('sample-ma-cross'))], ['לפי הספר', 'הגדרה שלנו', 7, null], 'ז0: "by the book" only for w3_mult; the other 7 "our definition"; nothing for the sample method');

// ── API ──────────────────────────────────────────────────
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
$jar = []; $tokens = [];
$NOW = 1794094200;
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", "X-Dev-Now: $NOW"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$E = 'elliott-w3';
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $E];

// 6.7: חדשה = כבויה
$am = array_column(call('owner', 'admin_methods')[1]['methods'], null, 'id');
$em = $am[$E] ?? [];
eq([$em['enabled'] ?? null, $em['sample'] ?? null, $em['alerts'] ?? null, $em['all_timeframes'] ?? null, $em['label'] ?? null],
    [false, false, false, ['h1', 'h4', 'd1', 'w1'], 'שיטה שלנו על בסיס כללי הספר (Frost & Prechter)'], 'admin: elliott-w3 listed OFF, not a sample, not in alerts, h1 and up (✅18), with "our method based on the book"');
eq([$em['param_defs']['w3_mult']['ours'] ?? null, $em['param_defs']['w2_min']['ours'] ?? null], [false, true], 'admin: each parameter marked from the book / our definition (ז0)');
eq(call('alice', 'analysis', null, $q)[0], 404, 'off → a regular user cannot run it');
eq(call('owner', 'admin_method_save', ['id' => $E, 'enabled' => true, 'markets' => ['crypto', 'stocks'], 'timeframes' => ['h1', 'h4'], 'params' => ['w2_min' => 0.7, 'w2_max' => 0.6]])[0], 422, 'admin: w2_max ≤ w2_min → rejected (validate)');
eq(call('owner', 'admin_method_save', ['id' => $E, 'enabled' => true, 'markets' => ['crypto', 'stocks'], 'timeframes' => ['h1', 'h4'], 'params' => []])[0], 200, 'the admin turns it on');
$vis = array_column(call('alice', 'methods')[1]['methods'], null, 'id');
eq([isset($vis[$E]), $vis[$E]['alerts'] ?? null], [true, false], 'on → a regular user sees it (not a sample), not for alerts');

// הכרטיס
[$c, $r] = call('alice', 'analysis', null, $q);
$card = $r['cards'][0] ?? [];
eq([$c, $card['status'] ?? null, $card['method']['label'] ?? null, $card['method']['sample'] ?? null], [200, 'ok', 'שיטה שלנו על בסיס כללי הספר (Frost & Prechter)', false], 'card: "our method based on the book" (ז0)');
$ids = [];   // לא array_column — הוא משמיט ערכי null
foreach ($card['conditions'] ?? [] as $x) $ids[$x['id']] = $x['met'];
eq([array_key_exists('l-w1', $ids), array_key_exists('s-confirm', $ids), array_key_exists('manual-structure', $ids) ? $ids['manual-structure'] : 'missing', array_key_exists('manual-degree', $ids) ? $ids['manual-degree'] : 'missing'], [true, true, null, null], 'card: long/short conditions, and "check yourself" conditions with met: null (not false)');
eq([is_bool($ids['l-w1']), is_bool($ids['s-r1'])], [true, true], 'the computed conditions stay true/false');
eq([$card['basis'] ?? null, ($card['backtest']['status'] ?? null) !== null], ['state', true], 'ו1: with a stored backtest the card continues from the engine state (basis: state)');
eq([$card['param_notes']['w3_mult']['note'] ?? null, $card['param_notes']['horizon_bars']['note'] ?? null], ['לפי הספר', 'הגדרה שלנו'], 'card: parameter notes (ז0)');
$row = $db->query("SELECT params_hash, state FROM backtests WHERE method_id = '$E' AND tf = 'h1'")->fetch(PDO::FETCH_ASSOC);
$dec = $r['decimals'];
$hashWithTick = ts_bt_hash(ts_params_for_asset($m, $m->params(), $dec), ts_cost_for('crypto'));
eq([$row !== false, $row['params_hash'] ?? null], [true, $hashWithTick], "the backtest is stored with the asset's tick in its parameters (decimals $dec)");
$st = json_decode($row['state'], true);
eq([array_key_exists('skipped_gap', ts_bt_result($st)), isset($card['backtest']['skipped_gap'])], [true, true], 'the result carries skipped_gap (6.2(ח)) to the card');
if ($card['levels'] ?? null) {
    eq(preg_match('/^\d+(\.\d{1,' . max(1, $dec) . '})?$/', $card['levels']['target']) === 1, true, 'levels → decimal strings rounded to the tick');
}
// מסגרת שהשיטה לא תומכת בה
eq(call('alice', 'analysis', null, ['tf' => 'm15'] + $q)[1]['cards'][0]['status'] ?? null, 'unsupported', 'm15 → unsupported (✅18: h1 and up)');
eq(preg_match('/קנה|מכור|המלצ|כדאי/u', json_encode($r, JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend" wording (6.6)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
