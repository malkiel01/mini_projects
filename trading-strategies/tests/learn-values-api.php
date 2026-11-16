<?php
// למידה, L5b — דרך ה-API (spec-stage-a 23.9, 23.10, קריטריונים 6–9): הלשונית מציגה את השכנים, N בכל חלק וההיסטוגרמות (לדיון
// בלבד); ההשוואה רצה **רק ב-cron** (ts_learn_cron — כאן בתהליך נפרד על אותו מסד, עם הסט הקבוע "narrow"), ואין לה פעולה בבקשה של
// משתמש. ההצעה לפלח מט"ח × 1h; "החל" — רק העורך הראשי, ומעלה גרסה; אחרי ההחלה — הערך של הפלח במט"ח × 1h, והכללי בשאר; "↺ בטל".
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
function effective(string $asset, string $tf, string $method = 'test-neighbors'): array
{
    global $tmp;
    $code = 'define("TS_DB_PATH", $argv[1]); define("TS_CACHE_PATH", $argv[1] . ".cache"); define("TS_DEV", true);'
        . 'putenv("TS_TEST_METHODS=" . $argv[2] . "/tests/fixtures/methods/learning.php"); require $argv[2] . "/lib/analysis.php";'
        . '$m = ts_method($argv[5]); $set = ts_method_settings($argv[5]);'
        . 'echo json_encode(ts_params_for_segment($m, $set, $m->params($set["params"]), $argv[3], $argv[4]));';
    return json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$tmp.sqlite") . ' ' . escapeshellarg(dirname(__DIR__))
        . ' ' . escapeshellarg($asset) . ' ' . escapeshellarg($tf) . ' ' . escapeshellarg($method)), true) ?? [];
}
/** ריצת ה-cron של הלמידה (ts_learn_cron), בתהליך נפרד על אותו מסד — הנרות מהסט הקבוע $set (tests/fixtures/learn) */
function learn_cron(string $set): array
{
    global $tmp;
    $code = 'define("TS_DB_PATH", $argv[1]); define("TS_CACHE_PATH", $argv[1] . ".cache"); define("TS_DEV", true);'
        . 'putenv("TS_TEST_METHODS=" . $argv[2] . "/tests/fixtures/methods/learning.php"); require $argv[2] . "/lib/tune.php"; require $argv[2] . "/lib/devtasks.php";'
        . 'require $argv[2] . "/tests/fixtures/learn/sets.php"; $u = [];'
        . 'echo json_encode(ts_learn_cron(1800000000, $u, null, microtime(true), fn($a, $tf) => ts_learn_fx_set($argv[3], 1699992000, 50)));';
    return json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$tmp.sqlite") . ' ' . escapeshellarg(dirname(__DIR__))
        . ' ' . escapeshellarg($set)), true) ?? [];
}

foreach (['owner', 'alice'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$alice = (int)$db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $alice])[0], 200, 'alice — a secondary editor');
eq(call('owner', 'admin_method_save', ['id' => 'test-neighbors', 'enabled' => true, 'markets' => ['crypto', 'forex'], 'timeframes' => ['h1'], 'params' => [], 'note' => ''])[0],
    200, 'the neighbors test method is on');

// הסט: 100 עסקאות במט"ח × שעה, t_signal כל 10 שעות לאורך הסט הקבוע (50 מחזורים של 20 נרות)
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, entry, stop, result, win, r,
    mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, created_at) VALUES ('backtest', 'test-neighbors', 1, 'h', 'twelvedata', 'twelvedata:EUR/USD', 'h1', ?, ?, 'long',
    '100', '95', 'win', 1, 1.5, ?, 2.2, '5', 'forex', 3, 1, 2, 10, '[]', 0)");
for ($i = 0; $i < 100; $i++) $ins->execute([1_699_992_000 + $i * 36000, 1_699_992_000 + $i * 36000 + 7200, 0.1 * ($i % 6)]);

// ── הלשונית (23.10): השכנים, N בכל חלק, וההיסטוגרמות — קריאה בלבד ──
[$c, $L] = call('alice', 'admin_learn', null, ['method' => 'test-neighbors']);
$v = array_values(array_filter($L['values'] ?? [], fn($x) => $x['segment'] === 'forex|h1'))[0] ?? null;
eq([$c, $v['param'] ?? null, $v['status'] ?? null, $v['current'] ?? null, $v['neighbors'] ?? null, $v['n_in'] ?? null, $v['n_out'] ?? null, array_key_exists('last', $v ?? []) ? $v['last'] : 'x'],
    [200, 'stop_atr', 'due', 1, [0.75, 1, 1.25], 70, 30, null], 'the learning tab: forex × 1h — the current, the neighbors, 70 / 30, waiting for the cron');
eq([$L['hist']['n'] ?? null, array_sum($L['hist']['mae'] ?? []), $L['hist']['mae'][2] ?? null], [100, 100, 16], 'criterion 7: the MAE / MFE histograms (for discussion)');
eq(call('alice', 'admin_tune_list', null, ['method' => 'test-neighbors'])[1]['proposals'] ?? null, [], 'criterion 7: … they create no proposal; opening the tab runs nothing');
eq(call('alice', 'admin_learn_propose', ['method' => 'test-neighbors', 'asset' => 'twelvedata:EUR/USD', 'tf' => 'h1'])[0] !== 200, true,
    'no comparison inside a user request: the old "🔍 compute" action is gone');

// ── ה-cron: השוואת השכנים → הצעה לכללי ולפלח מט"ח × 1h ──
$s = learn_cron('narrow');
eq([$s['evaluated'] ?? null, $s['proposals'] ?? null, $s['runs_in'] ?? null, $s['runs_out'] ?? null], [2, 2, 6, 4], 'the cron: 2 comparisons (general, forex × 1h), 3 + 2 runs each');
[, $T] = call('alice', 'admin_tune_list', null, ['method' => 'test-neighbors']);
$seg = array_values(array_filter($T['proposals'] ?? [], fn($x) => $x['segment'] === 'forex|h1'))[0] ?? null;
eq([$seg['origin'] ?? null, $seg['status'] ?? null, $seg['current'] ?? null, $seg['candidate'] ?? null, $seg['stats']['kind'] ?? null], ['learning', 'open', 1, 0.75, 'neighbors'],
    'criterion 8: a proposal for the forex × 1h segment, from learning (narrow: 1 → 0.75)');
[, $L] = call('alice', 'admin_learn', null, ['method' => 'test-neighbors']);
$v = array_values(array_filter($L['values'], fn($x) => $x['segment'] === 'forex|h1'))[0];
eq([$v['status'], $v['last']['result'], array_column($v['last']['detail']['candidates'], 'value'), $v['last']['detail']['chosen']], ['waiting', 'propose', [0.75, 1, 1.25], 0],
    'the tab: the last comparison — the expectancy of each of the three neighbors');

// ── קריטריון 9: "החל" — רק העורך הראשי, ומעלה גרסה ──
eq(call('alice', 'admin_tune_apply', ['id' => $seg['id']])[0], 403, 'criterion 9: a secondary editor cannot apply');
[$c, $A] = call('owner', 'admin_tune_apply', ['id' => $seg['id']]);
eq([$c, $A['proposal']['status'] ?? $A['status'] ?? null, $A['proposal']['new_version'] ?? $A['new_version'] ?? null], [200, 'applied', 2], 'criterion 9: the chief editor applies → version 2');
$row = $db->query("SELECT params, seg_params FROM methods WHERE id = 'test-neighbors'")->fetch(PDO::FETCH_ASSOC);
eq([json_decode($row['params'], true)['stop_atr'] ?? null, json_decode($row['seg_params'], true)['forex|h1']['stop_atr'] ?? null], [null, $seg['candidate']],
    'the value is saved for the segment; the general parameters are untouched');

// ── קריטריון 8: אחרי "החל" — הערך של הפלח במט"ח × 1h, והכללי בשאר ──
eq(array_map('floatval', [effective('twelvedata:EUR/USD', 'h1')['stop_atr'] ?? -1, effective('binance:BTCUSDT', 'h1')['stop_atr'] ?? -1]),
    [0.75, 1.0], 'criterion 8: forex × 1h — the segment value; crypto × 1h — the general one');
[, $T2] = call('owner', 'admin_tune_list', null, ['method' => 'test-neighbors']);
$again = array_values(array_filter($T2['proposals'], fn($x) => $x['id'] === $seg['id']))[0];
eq([$again['status'], $again['segment']], ['applied', 'forex|h1'], 'the proposal — applied, for the segment');

// ── 🟡4: הערך לפלח נראה, ו"↺ בטל" — רק הראשי, מעלה גרסה, והפלח חוזר לכללי ──
[, $M] = call('alice', 'admin_methods');
$tm = array_values(array_filter($M['methods'], fn($x) => $x['id'] === 'test-neighbors'))[0];
eq([$tm['seg_params']['forex|h1']['stop_atr'] ?? null, $tm['version']], [$seg['candidate'], 2], '🟡4: the segment value is visible to the editors (admin_methods)');
eq(call('alice', 'admin_seg_param_clear', ['method' => 'test-neighbors', 'segment' => 'forex|h1', 'param' => 'stop_atr'])[0], 403, '🟡4: a secondary editor cannot cancel');
eq(call('owner', 'admin_seg_param_clear', ['method' => 'test-neighbors', 'segment' => 'forex|h1', 'param' => 'nope'])[0], 404, 'an unknown value → 404');
[$c, $X] = call('owner', 'admin_seg_param_clear', ['method' => 'test-neighbors', 'segment' => 'forex|h1', 'param' => 'stop_atr']);
[, $M2] = call('owner', 'admin_methods');
$tm2 = array_values(array_filter($M2['methods'], fn($x) => $x['id'] === 'test-neighbors'))[0];
eq([$c, $X['seg_params'] ?? null, $tm2['version'], (float)(effective('twelvedata:EUR/USD', 'h1')['stop_atr'] ?? -1)], [200, [], 3, 1.0],
    '🟡4: the chief editor cancels → version 3, and forex × 1h is back to the general value');
eq((bool)$db->query("SELECT 1 FROM admin_log WHERE action = 'method_seg_clear'")->fetchColumn(), true, '… written in admin_log');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
