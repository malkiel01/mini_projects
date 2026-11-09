<?php
// בדיקות בונה השיטות (spec-stage-a 17.2.1, 17.2.4, 17.2.5, 6.7; ✅19; PR 4ב/3): מנהל בלבד, admin_log, גרסאות,
// "🧪 נסה" בלי שמירה, נפח נדחה, מזהה של שיטת קוד נדחה, המיגרציה. php trading-strategies/tests/builder-api.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-builder-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
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

// המיגרציה
eq((int)$db->query('PRAGMA user_version')->fetchColumn(), 8, 'migration 8 ran');
$cols = array_column($db->query('PRAGMA table_info(methods)')->fetchAll(PDO::FETCH_ASSOC), 'name');
eq([in_array('kind', $cols, true), in_array('definition', $cols, true), in_array('definition_version', $cols, true), (bool)$db->query("SELECT name FROM sqlite_master WHERE name = 'method_versions'")->fetchColumn()],
    [true, true, true, true], 'methods: kind, definition, definition_version; table method_versions');

$def = [
    'id' => 'ma-cross-def', 'name' => 'חציית ממוצעים (מוגדרת)', 'source' => 'בדיקה', 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'],
    'params' => [
        'fast' => ['label' => 'ממוצע קצר', 'default' => 20, 'min' => 2, 'max' => 200, 'int' => true],
        'slow' => ['label' => 'ממוצע ארוך', 'default' => 50, 'min' => 3, 'max' => 400, 'int' => true],
        'atr' => ['label' => 'אורך ATR', 'default' => 14, 'min' => 2, 'max' => 100, 'int' => true],
        'stop_atr' => ['label' => 'סטופ (× ATR)', 'default' => 2, 'min' => 0.5, 'max' => 10, 'int' => false],
        'target_atr' => ['label' => 'יעד (× ATR)', 'default' => 3, 'min' => 0.5, 'max' => 20, 'int' => false],
        'horizon' => ['label' => 'אופק (נרות)', 'default' => 50, 'min' => 1, 'max' => 500, 'int' => true],
    ],
    'indicators' => [
        ['id' => 'ma_fast', 'type' => 'sma', 'source' => 'close', 'length' => '$fast'],
        ['id' => 'ma_slow', 'type' => 'sma', 'source' => 'close', 'length' => '$slow'],
        ['id' => 'atr14', 'type' => 'atr', 'length' => '$atr'],
    ],
    'rules' => ['long' => ['all' => ['ma_fast crosses_above ma_slow']], 'short' => ['all' => ['ma_fast crosses_below ma_slow']]],
    'levels' => ['stop' => 'entry - dir * $stop_atr * atr14', 'target' => 'entry + dir * $target_atr * atr14'],
    'horizon_bars' => '$horizon',
    'validate' => [['expr' => '$fast < $slow', 'message' => 'הממוצע הקצר חייב להיות קצר מהארוך']],
];

// ── מנהל בלבד (17.2.5: 403 למשתמש רגיל בכל admin_method_*) ──
$codes = [call('alice', 'admin_method_validate', ['definition' => $def])[0], call('alice', 'admin_method_dry_run', ['definition' => $def, 'asset' => 'binance:BTCUSDT', 'tf' => 'h1'])[0],
    call('alice', 'admin_method_save', ['definition' => $def])[0], call('alice', 'admin_method_list')[0], call('alice', 'admin_method_versions', null, ['id' => 'ma-cross-def'])[0]];
eq($codes, [403, 403, 403, 403, 403], 'every admin_method_* → 403 for a regular user (validate, dry_run, save, list, versions)');
eq((int)$db->query("SELECT COUNT(*) FROM methods WHERE id = 'ma-cross-def'")->fetchColumn(), 0, '… and nothing was saved');

// ── בדיקה (validate) ──
[$c, $r] = call('owner', 'admin_method_validate', ['definition' => $def]);
eq([$c, $r['method']['id'] ?? null, $r['method']['horizon_bars'] ?? null], [200, 'ma-cross-def', 50], 'validate: a good definition');
$bad = [
    'function call' => [['rules' => ['long' => ['all' => ['exec(close) > 1']]]] + $def, 'קריאה לפונקציה'],
    'code-method id' => [['id' => 'sample-ma-cross'] + $def, 'תפוס'],
    'volume' => [['indicators' => array_merge($def['indicators'], [['id' => 'vol', 'type' => 'volume']])] + $def, 'נפח'],
    'not an object' => [[1, 2, 3], 'אובייקט'],
];
foreach ($bad as $what => [$d, $msg]) {
    [$c, $r] = call('owner', 'admin_method_validate', ['definition' => $d]);
    eq([$c, str_contains($r['error'] ?? '', $msg)], [422, true], "validate rejects: $what" . (isset($r['error']) ? " — {$r['error']}" : ''));
}

// ── שמירה: חדשה = כבויה, גרסה 1, admin_log ──
[$c, $r] = call('owner', 'admin_method_save', ['definition' => $def + ['version' => 99]]);
eq([$c, $r['saved'] ?? null], [200, ['id' => 'ma-cross-def', 'version' => 1, 'created' => true, 'changed' => true]], 'save: new defined method, version 1 (the browser cannot set the version)');
$am = array_column(call('owner', 'admin_method_list')[1]['methods'], null, 'id');
$x = $am['ma-cross-def'] ?? [];
eq([$x['kind'] ?? null, $x['enabled'] ?? null, $x['version'] ?? null, $x['label'] ?? null, $x['definition']['id'] ?? null], ['defined', false, 1, 'מוגדרת ידנית', 'ma-cross-def'], 'listed: defined, OFF, version 1, "מוגדרת ידנית", with its definition');
eq([$am['sample-ma-cross']['kind'] ?? null, array_keys($am)[0]], ['code', 'sample-ma-cross'], 'code methods stay "code", and come first');
eq((int)$db->query("SELECT COUNT(*) FROM admin_log WHERE action = 'method_define' AND target = 'ma-cross-def v1'")->fetchColumn(), 1, 'the save is in admin_log');
eq(array_column(call('alice', 'methods')[1]['methods'], 'id'), [], 'OFF → a regular user does not see it');
eq(call('owner', 'admin_method_save', ['definition' => ['id' => 'sample-ma-cross'] + $def])[0], 422, 'save with a code-method id → 422');

// ── הפעלה, ניתוח, ו"מוגדרת ידנית" עד N ≥ 30 ──
eq(call('owner', 'admin_method_save', ['id' => 'ma-cross-def', 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'], 'params' => []])[0], 200, 'the admin turns it on (the usual method settings)');
$vis = array_column(call('alice', 'methods')[1]['methods'], null, 'id');
eq([isset($vis['ma-cross-def']), $vis['ma-cross-def']['label'] ?? null, $vis['ma-cross-def']['alerts'] ?? null], [true, 'מוגדרת ידנית', false], 'ON → a regular user sees it, "מוגדרת ידנית", not in alerts');
[$c, $r] = call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'ma-cross-def']);
$card = $r['cards'][0] ?? [];
eq([$c, $card['status'] ?? null, ($card['backtest']['n'] ?? 0) >= 30], [200, 'ok', true], 'analysis with the defined method: card + backtest (N ≥ 30 on the fixed set)');
$mm = call('alice', 'methods')[1]['methods'][0] ?? [];
eq(array_key_exists('label', $mm) ? $mm['label'] : 'missing', null, 'after a backtest with N ≥ 30 — no longer "מוגדרת ידנית" (17.2.1)');
$row1 = $db->query("SELECT method_version, n FROM backtests WHERE method_id = 'ma-cross-def' AND tf = 'h1'")->fetch(PDO::FETCH_ASSOC);
eq((int)$row1['method_version'], 1, 'the stored backtest is of version 1');

// ── גרסאות: אותה הגדרה — בלי גרסה; שינוי — גרסה 2, והבדיקה לאחור מחושבת מחדש ──
eq(call('owner', 'admin_method_save', ['definition' => $def])[1]['saved'] ?? null, ['id' => 'ma-cross-def', 'version' => 1, 'created' => false, 'changed' => false], 'saving the same definition → no new version');
$def2 = $def;
$def2['params']['fast']['default'] = 10;
eq(call('owner', 'admin_method_save', ['definition' => $def2])[1]['saved']['version'] ?? null, 2, 'a changed definition → version 2');
$enabled = (int)$db->query("SELECT enabled FROM methods WHERE id = 'ma-cross-def'")->fetchColumn();
eq($enabled, 1, 'a new version keeps the method on (the admin already approved it)');
$vs = call('owner', 'admin_method_versions', null, ['id' => 'ma-cross-def'])[1]['versions'] ?? [];
eq([array_column($vs, 'version'), $vs[0]['definition']['params']['fast']['default'] ?? null, is_string($vs[0]['saved_by'] ?? null)], [[2, 1], 10, true], 'versions: newest first, each with its definition and who saved it');
eq(call('owner', 'admin_method_versions', null, ['id' => 'sample-ma-cross'])[0], 404, 'versions of a code method → 404');
[$c, $r] = call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'ma-cross-def']);
eq([$r['cards'][0]['method']['version'] ?? null, $r['cards'][0]['backtest']['mode'] ?? null], [2, 'full'], 'after a new version the backtest is recomputed in full (6.7)');
eq((int)$db->query("SELECT COUNT(*) FROM admin_log WHERE action = 'method_redefine'")->fetchColumn(), 1, 'the new version is in admin_log');

// ── "🧪 נסה" — לא נשמר דבר ──
$before = [$db->query('SELECT COUNT(*) FROM backtests')->fetchColumn(), $db->query("SELECT definition FROM methods WHERE id = 'ma-cross-def'")->fetchColumn(),
    $db->query('SELECT COUNT(*) FROM method_versions')->fetchColumn()];
$try = $def2;
$try['params']['fast']['default'] = 5;
[$c, $r] = call('owner', 'admin_method_dry_run', ['definition' => $try, 'asset' => 'binance:BTCUSDT', 'tf' => 'h1']);
eq([$c, $r['candles'] > 500, $r['signals_total'] > 0, count($r['signals']) <= 20, count($r['conditions'] ?? []), $r['note'] ?? null], [200, true, true, true, 2, 'ניסיון בלבד — לא נשמר, ובלי בדיקה לאחור'], 'dry run: recent candles, signals, conditions');
eq(preg_match('/^\d+(\.\d+)?$/', $r['signals'][0]['entry'] ?? ''), 1, 'dry run levels are decimal strings rounded to the tick');
eq([$db->query('SELECT COUNT(*) FROM backtests')->fetchColumn(), $db->query("SELECT definition FROM methods WHERE id = 'ma-cross-def'")->fetchColumn(), $db->query('SELECT COUNT(*) FROM method_versions')->fetchColumn()], $before, 'dry run stores nothing — no definition, no version, no backtest');
eq(call('owner', 'admin_method_dry_run', ['definition' => ['rules' => ['long' => ['all' => ['system(1)']]]] + $def, 'asset' => 'binance:BTCUSDT', 'tf' => 'h1'])[0], 422, 'dry run of a bad definition → 422');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
