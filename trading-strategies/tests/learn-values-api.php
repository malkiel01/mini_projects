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
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market'] + getenv();
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
    $code = 'define("TS_DB_PATH", $argv[1]); define("TS_CACHE_PATH", $argv[1] . ".cache"); require $argv[2] . "/lib/analysis.php";'
        . '$m = ts_method("sample-ma-cross"); $set = ts_method_settings("sample-ma-cross");'
        . 'echo json_encode(ts_params_for_segment($m, $set, $m->params($set["params"]), $argv[3], $argv[4]));';
    return json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$tmp.sqlite") . ' ' . escapeshellarg(dirname(__DIR__))
        . ' ' . escapeshellarg($asset) . ' ' . escapeshellarg($tf)), true) ?? [];
}

foreach (['owner', 'alice'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$alice = (int)$db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $alice])[0], 200, 'alice — a secondary editor');
eq(call('owner', 'admin_method_save', ['id' => 'sample-ma-cross', 'enabled' => true, 'markets' => ['crypto', 'stocks'], 'timeframes' => ['h1', 'd1'], 'params' => [], 'note' => ''])[0],
    200, 'the sample method is on');

// הסט: 60 עסקאות בקריפטו × שעה — המנצחות עם MAE סביב 1 ATR (הסטופ הנוכחי — 2 ATR)
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, entry, stop, result, win, r,
    mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, created_at) VALUES ('backtest', 'sample-ma-cross', 1, 'h', 'binance', 'binance:BTCUSDT', 'h1', ?, ?, 'long',
    '100', '98', ?, ?, ?, ?, ?, '1', 'crypto', 3, 1, 2, 10, '[]', 0)");
for ($i = 0, $t = 1_700_000_000; $i < 60; $i++, $t += 3600) {
    $w = $i % 3 !== 2;
    $ins->execute([$t, $t + 1800, $w ? 'win' : 'loss', $w ? 1 : 0, $w ? 1.5 : -1.0, (0.9 + ($i % 5) * 0.05) / 2, (3.0 + ($i % 3) * 0.1) / 2]);
}

// ── המיקומים בלשונית ──
[$c, $L] = call('alice', 'admin_learn', null, ['method' => 'sample-ma-cross']);
$v = array_values(array_filter($L['values'] ?? [], fn($x) => $x['param'] === 'stop_atr' && $x['segment'] === 'crypto|h1'))[0] ?? null;
eq([$c, $v['decision'] ?? null, $v['current'] ?? null, ($v['candidate']['n'] ?? 0) > 0], [200, 'propose', 2, true], 'the learning tab: stop_atr for crypto × 1h — a proposal (current 2)');

// ── "🔍 חשב הצעות" — עורך (גם משני) ──
[$c, $r] = call('alice', 'admin_learn_propose', ['method' => 'sample-ma-cross', 'asset' => 'binance:BTCUSDT', 'tf' => 'h1']);
eq([$c, ($r['proposals'] ?? 0) >= 1], [200, true], 'a secondary editor computes proposals');
[, $T] = call('alice', 'admin_tune_list', null, ['method' => 'sample-ma-cross']);
$seg = array_values(array_filter($T['proposals'] ?? [], fn($x) => $x['param'] === 'stop_atr' && $x['segment'] === 'crypto|h1'))[0] ?? null;
eq([$seg['origin'] ?? null, $seg['status'] ?? null, $seg['current'] ?? null, $seg['candidate'] < 2], ['learning', 'open', 2, true], 'criterion 6: a proposal for the crypto × 1h segment, from learning');
eq(call('bob', 'admin_learn_propose', ['method' => 'sample-ma-cross', 'asset' => 'binance:BTCUSDT', 'tf' => 'h1'])[0], 403, 'a user — no');

// ── קריטריון 7: "החל" — רק העורך הראשי, ומעלה גרסה ──
eq(call('alice', 'admin_tune_apply', ['id' => $seg['id']])[0], 403, 'criterion 7: a secondary editor cannot apply');
[$c, $A] = call('owner', 'admin_tune_apply', ['id' => $seg['id']]);
eq([$c, $A['proposal']['status'] ?? $A['status'] ?? null, $A['proposal']['new_version'] ?? $A['new_version'] ?? null], [200, 'applied', 2], 'criterion 7: the chief editor applies → version 2');
$row = $db->query("SELECT params, seg_params FROM methods WHERE id = 'sample-ma-cross'")->fetch(PDO::FETCH_ASSOC);
eq([json_decode($row['params'], true)['stop_atr'] ?? null, json_decode($row['seg_params'], true)['crypto|h1']['stop_atr'] ?? null], [null, $seg['candidate']],
    'the value is saved for the segment; the general parameters are untouched');

// ── קריטריון 6: אחרי "החל" — הערך של הפלח בקריפטו × שעה, והכללי בשאר ──
eq(array_map('floatval', [effective('binance:BTCUSDT', 'h1')['stop_atr'] ?? -1, effective('binance:BTCUSDT', 'd1')['stop_atr'] ?? -1, effective('finnhub:AAPL', 'h1')['stop_atr'] ?? -1]),
    [(float)$seg['candidate'], 2.0, 2.0], 'criterion 6: crypto × 1h — the segment value; crypto × 1d and stocks × 1h — the general one');
[, $T2] = call('owner', 'admin_tune_list', null, ['method' => 'sample-ma-cross']);
$again = array_values(array_filter($T2['proposals'], fn($x) => $x['id'] === $seg['id']))[0];
eq([$again['status'], $again['segment']], ['applied', 'crypto|h1'], 'the proposal — applied, for the segment');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
