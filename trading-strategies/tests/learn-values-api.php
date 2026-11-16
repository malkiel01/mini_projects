<?php
// למידה, L5 — דרך ה-API (spec-stage-a 23.9, קריטריונים 6–7): "🔍 חשב הצעות" (admin_learn_propose) יוצר הצעה מהלמידה גם לפלח
// קריפטו × שעה; "החל" — רק העורך הראשי, ומעלה גרסה; אחרי ההחלה השיטה משתמשת בערך של הפלח בקריפטו × שעה, ובכללי בשאר.
// php trading-strategies/tests/learn-values-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-learn-values-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/learning.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794834030;
/** [קוד, JSON מפוענח] */
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
/** הפרמטרים שהשיטה משתמשת בהם על נכס ומסגרת — ts_params_for_segment, בתהליך נפרד על אותו מסד */
function effective(string $asset, string $tf): array
{
    global $tmp;
    $code = 'define("TS_DB_PATH", $argv[1]); define("TS_CACHE_PATH", $argv[1] . ".cache"); define("TS_DEV", true);'
        . 'putenv("TS_TEST_METHODS=" . $argv[2] . "/tests/fixtures/methods/learning.php"); require $argv[2] . "/lib/analysis.php";'
        . '$m = ts_method("test-learn"); $set = ts_method_settings("test-learn");'
        . 'echo json_encode(ts_params_for_segment($m, $set, $m->params($set["params"]), $argv[3], $argv[4]));';
    return json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$tmp.sqlite") . ' ' . escapeshellarg(dirname(__DIR__))
        . ' ' . escapeshellarg($asset) . ' ' . escapeshellarg($tf)), true) ?? [];
}

foreach (['owner', 'alice'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$alice = (int)$db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $alice])[0], 200, 'alice — a secondary editor');
eq(call('owner', 'admin_method_save', ['id' => 'test-learn', 'enabled' => true, 'markets' => ['crypto', 'stocks'], 'timeframes' => ['h1', 'd1'], 'params' => [], 'note' => ''])[0],
    200, 'the learning test method is on');

// הסט: 120 עסקאות בקריפטו × שעה, בתוך טווח הנרות של הבדיקה — רוחב האזור במנצחות סביב 1.0 (הנוכחי — 0.5)
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, entry, stop, result, win, r,
    mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, features, created_at) VALUES ('backtest', 'test-learn', 1, 'h', 'binance', 'binance:BTCUSDT', 'h1', ?, ?, 'long',
    '100', '98', ?, ?, ?, 0.4, 1.5, '1', 'crypto', 3, 1, 2, 10, '[]', ?, 0)");
for ($i = 0; $i < 120; $i++) {
    $t = $NOW - 400 * 86400 + $i * 3 * 86400;
    $w = $i % 3 !== 2;
    $ins->execute([$t, $t + 1800, $w ? 'win' : 'loss', $w ? 1 : 0, $w ? 1.5 : -1.0, json_encode(['zone_atr' => 0.95 + ($i % 5) * 0.025])]);
}

// ── המיקומים בלשונית ──
[$c, $L] = call('alice', 'admin_learn', null, ['method' => 'test-learn']);
$v = array_values(array_filter($L['values'] ?? [], fn($x) => $x['param'] === 'zone_atr' && $x['segment'] === 'crypto|h1'))[0] ?? null;
$sv = array_values(array_filter($L['values'] ?? [], fn($x) => $x['param'] === 'stop_atr' && $x['segment'] === 'crypto|h1'))[0] ?? null;
eq([$c, $v['decision'] ?? null, $v['current'] ?? null, ($v['candidate']['n'] ?? 0) >= 30, $sv['decision'] ?? null], [200, 'paused', 0.5, true, 'paused'],
    'the learning tab: zone_atr and the stop for crypto × 1h — shown, paused (2.37)');

// ── "🔍 חשב הצעות" — עורך (גם משני) ──
eq(call('alice', 'admin_learn_propose', ['method' => 'test-learn', 'asset' => 'binance:BTCUSDT', 'tf' => 'h4'])[0], 422, '🟡6a: a timeframe the method is not set for → 422');
eq(call('alice', 'admin_learn_propose', ['method' => 'test-learn', 'asset' => 'twelvedata:EUR/USD', 'tf' => 'h1'])[0], 422, '🟡6a: a market the method is not set for → 422');
[$c, $r] = call('alice', 'admin_learn_propose', ['method' => 'test-learn', 'asset' => 'binance:BTCUSDT', 'tf' => 'h1']);
eq([$c, $r['proposals'] ?? null, $r['findings'] ?? null], [200, 0, 0], 'a secondary editor computes — 2.37: no proposal and no finding from the paused statistics');
// הצעה מהלמידה לפלח — כמו ש-L5b ייצור (השוואת שכנים); כאן ישירות, כדי לבדוק את התשתית: החלה וביטול
$res = ['split_t' => $NOW - 100 * 86400, 'current' => ['in' => ['n' => 40, 'win_rate' => 0.5, 'expectancy_r' => 0.2], 'out' => ['n' => 31, 'win_rate' => 0.5, 'expectancy_r' => 0.1]],
    'candidate' => ['in' => ['n' => 40, 'win_rate' => 0.55, 'expectancy_r' => 0.3], 'out' => ['n' => 31, 'win_rate' => 0.55, 'expectancy_r' => 0.2]]];
$db->prepare("INSERT INTO method_proposals (method_id, method_version, param, current_value, candidate_value, asset, tf, split_t, results, note, created_by, created_at, segment, origin, stats)
    VALUES ('test-learn', 1, 'zone_atr', '0.5', '1', 'binance:BTCUSDT', 'h1', ?, ?, 'מהלמידה', 0, ?, 'crypto|h1', 'learning', '{}')")->execute([$res['split_t'], json_encode($res), $NOW]);
[, $T] = call('alice', 'admin_tune_list', null, ['method' => 'test-learn']);
$seg = array_values(array_filter($T['proposals'] ?? [], fn($x) => $x['param'] === 'zone_atr' && $x['segment'] === 'crypto|h1'))[0] ?? null;
eq([$seg['origin'] ?? null, $seg['status'] ?? null, $seg['current'] ?? null, $seg['candidate'] ?? null], ['learning', 'open', 0.5, 1], 'criterion 6: a proposal for the crypto × 1h segment, from learning');
eq(call('bob', 'admin_learn_propose', ['method' => 'test-learn', 'asset' => 'binance:BTCUSDT', 'tf' => 'h1'])[0], 403, 'a user — no');

// ── קריטריון 7: "החל" — רק העורך הראשי, ומעלה גרסה ──
eq(call('alice', 'admin_tune_apply', ['id' => $seg['id']])[0], 403, 'criterion 7: a secondary editor cannot apply');
[$c, $A] = call('owner', 'admin_tune_apply', ['id' => $seg['id']]);
eq([$c, $A['proposal']['status'] ?? $A['status'] ?? null, $A['proposal']['new_version'] ?? $A['new_version'] ?? null], [200, 'applied', 2], 'criterion 7: the chief editor applies → version 2');
$row = $db->query("SELECT params, seg_params FROM methods WHERE id = 'test-learn'")->fetch(PDO::FETCH_ASSOC);
eq([json_decode($row['params'], true)['zone_atr'] ?? null, json_decode($row['seg_params'], true)['crypto|h1']['zone_atr'] ?? null], [null, $seg['candidate']],
    'the value is saved for the segment; the general parameters are untouched');

// ── קריטריון 6: אחרי "החל" — הערך של הפלח בקריפטו × שעה, והכללי בשאר ──
eq(array_map('floatval', [effective('binance:BTCUSDT', 'h1')['zone_atr'] ?? -1, effective('binance:BTCUSDT', 'd1')['zone_atr'] ?? -1, effective('finnhub:AAPL', 'h1')['zone_atr'] ?? -1]),
    [(float)$seg['candidate'], 0.5, 0.5], 'criterion 6: crypto × 1h — the segment value; crypto × 1d and stocks × 1h — the general one');
[, $T2] = call('owner', 'admin_tune_list', null, ['method' => 'test-learn']);
$again = array_values(array_filter($T2['proposals'], fn($x) => $x['id'] === $seg['id']))[0];
eq([$again['status'], $again['segment']], ['applied', 'crypto|h1'], 'the proposal — applied, for the segment');

// ── 🟡4: הערך לפלח נראה, ו"↺ בטל" — רק הראשי, מעלה גרסה, והפלח חוזר לכללי ──
[, $M] = call('alice', 'admin_methods');
$tm = array_values(array_filter($M['methods'], fn($x) => $x['id'] === 'test-learn'))[0];
eq([$tm['seg_params']['crypto|h1']['zone_atr'] ?? null, $tm['version']], [$seg['candidate'], 2], '🟡4: the segment value is visible to the editors (admin_methods)');
eq(call('alice', 'admin_seg_param_clear', ['method' => 'test-learn', 'segment' => 'crypto|h1', 'param' => 'zone_atr'])[0], 403, '🟡4: a secondary editor cannot cancel');
eq(call('owner', 'admin_seg_param_clear', ['method' => 'test-learn', 'segment' => 'crypto|h1', 'param' => 'nope'])[0], 404, 'an unknown value → 404');
[$c, $X] = call('owner', 'admin_seg_param_clear', ['method' => 'test-learn', 'segment' => 'crypto|h1', 'param' => 'zone_atr']);
[, $M2] = call('owner', 'admin_methods');
$tm2 = array_values(array_filter($M2['methods'], fn($x) => $x['id'] === 'test-learn'))[0];
eq([$c, $X['seg_params'] ?? null, $tm2['version'], (float)(effective('binance:BTCUSDT', 'h1')['zone_atr'] ?? -1)], [200, [], 3, 0.5],
    '🟡4: the chief editor cancels → version 3, and crypto × 1h is back to the general value');
eq((bool)$db->query("SELECT 1 FROM admin_log WHERE action = 'method_seg_clear'")->fetchColumn(), true, '… written in admin_log');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
