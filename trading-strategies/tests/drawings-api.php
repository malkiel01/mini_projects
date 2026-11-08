<?php
// בדיקות API לציור על הגרף (spec-stage-a פרק 7, ופרק 15: בידוד, תקרה, CSRF).
// php trading-strategies/tests/drawings-api.php — php -S עם שער מדומה (dev-prepend). בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-drawings-' . getmypid() . '-' . bin2hex(random_bytes(4));
// פורט פנוי מהמערכת ושם ייחודי — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת או קבצים של ריצה אחרת (#1333)
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
    ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market'] + getenv());
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
function call(string $as, string $action, ?array $body = null, array $query = [], ?string $csrf = 'auto'): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        if ($csrf !== null) $h[] = 'X-CSRF: ' . ($csrf === 'auto' ? ($tokens[$as] ?? '') : $csrf);
    }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];

$A = 'binance:BTCUSDT';
$trend = ['asset' => $A, 'tf' => null, 'kind' => 'trend', 'points' => [['t' => 1772460000, 'p' => 75000.5], ['t' => 1772546400, 'p' => 76250]],
    'style' => ['color' => '#2D4A8A', 'width' => 2]];

// ── יצירה, קריאה, עדכון ──────────────────────────────────
[$c, $r] = call('alice', 'drawing_save', $trend);
eq($c, 200, 'alice creates a trend line');
$id = $r['drawing']['id'];
eq([$r['drawing']['kind'], $r['drawing']['tf'], $r['drawing']['style']['color'], $r['drawing']['points'][1]['p']], ['trend', null, '#2d4a8a', 76250], 'stored as sent (color normalized)');
[$c, $r] = call('alice', 'drawing_save', ['id' => $id, 'tf' => 'h1'] + $trend);
eq([$c, $r['drawing']['tf']], [200, 'h1'], 'update: "only this timeframe"');
call('alice', 'drawing_save', ['asset' => $A, 'kind' => 'text', 'points' => [['t' => 1772460000, 'p' => 75100]], 'style' => ['color' => '#b3261e', 'width' => 3, 'text' => '<b>תמיכה</b>']]);
call('alice', 'drawing_save', ['asset' => 'ecb:USDILS', 'kind' => 'hline', 'points' => [['t' => 1772460000, 'p' => 3.65]]]);
$list = call('alice', 'drawings', null, ['asset' => $A])[1]['drawings'];
eq([count($list), $list[1]['style']['text']], [2, '<b>תמיכה</b>'], 'list per asset; text kept as text (the browser draws it on canvas, never as HTML)');

// ── בידוד: B לא קורא, לא משנה ולא מוחק של A ─────────────
eq(call('bob', 'drawings', null, ['asset' => $A])[1]['drawings'], [], 'bob does not see alice\'s drawings on the same asset');
eq(call('bob', 'drawing_save', ['id' => $id] + $trend)[0], 404, 'bob cannot update alice\'s drawing by id');
eq(call('bob', 'drawing_delete', ['id' => $id])[0], 404, 'bob cannot delete alice\'s drawing by id');
eq(call('alice', 'drawings', null, ['asset' => $A])[1]['drawings'][0]['tf'], 'h1', 'alice\'s drawing untouched');
$bobExport = call('bob', 'export', null, ['format' => 'json'])[1];
eq($bobExport['drawings'], [], 'bob\'s export has none of alice\'s drawings');
eq(count(call('alice', 'export', null, ['format' => 'json'])[1]['drawings']), 3, 'alice\'s export includes her drawings');

// ── CSRF ─────────────────────────────────────────────────
eq(call('alice', 'drawing_save', $trend, [], null)[0], 403, 'save without X-CSRF → 403');
eq(call('alice', 'drawing_delete', ['id' => $id], [], 'wrong')[0], 403, 'delete with wrong X-CSRF → 403');
eq(call('alice', 'drawing_save')[0], 405, 'save by GET → 405');

// ── ולידציה ──────────────────────────────────────────────
$bad = fn(array $over) => call('alice', 'drawing_save', $over + $trend)[0];
eq($bad(['kind' => 'circle']), 422, 'unknown kind');
eq($bad(['points' => [['t' => 1772460000, 'p' => 1]]]), 422, 'trend needs 2 points');
eq($bad(['kind' => 'hline']), 422, 'hline needs 1 point');
eq($bad(['points' => [['t' => '1772460000', 'p' => 1], ['t' => 1772460000, 'p' => 2]]]), 422, 'time must be an integer');
eq($bad(['points' => [['t' => 1772460000, 'p' => 'x'], ['t' => 1772460000, 'p' => 2]]]), 422, 'price must be a number');
eq($bad(['style' => ['color' => 'red', 'width' => 2]]), 422, 'color must be #rrggbb');
eq($bad(['style' => ['color' => '#000000', 'width' => 9]]), 422, 'width 1–4');
eq($bad(['tf' => 'h2']), 422, 'unknown timeframe');
eq($bad(['asset' => 'nope:X']), 422, 'unknown provider in asset');
eq(call('alice', 'drawing_save', ['asset' => $A, 'kind' => 'text', 'points' => [['t' => 1, 'p' => 1]], 'style' => ['color' => '#000000', 'width' => 2, 'text' => ' ']])[0], 422, 'text needs text');

// ── תקרה: 500 לנכס ───────────────────────────────────────
$db = new PDO('sqlite:' . "$tmp.sqlite");
$uidA = (int)$db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
$ins = $db->prepare("INSERT INTO drawings (user_id, asset, tf, kind, points, style, updated_at) VALUES (?, ?, NULL, 'hline', '[{\"t\":1,\"p\":1}]', '{}', 0)");
$db->beginTransaction();
for ($i = 0; $i < 498; $i++) $ins->execute([$uidA, $A]);
$db->commit();
eq(count(call('alice', 'drawings', null, ['asset' => $A])[1]['drawings']), 500, 'alice has 500 on the asset');
[$c, $r] = call('alice', 'drawing_save', $trend);
eq([$c, str_contains((string)($r['error'] ?? ''), '500')], [422, true], 'the 501st on the same asset → refused with a clear message');
eq(call('alice', 'drawing_save', ['id' => $id] + $trend)[0], 200, 'updating an existing one at the cap is fine');
eq(call('alice', 'drawing_save', ['asset' => 'binance:ETHUSDT'] + $trend)[0], 200, 'the cap is per asset');
$eth = call('alice', 'drawings', null, ['asset' => 'binance:ETHUSDT'])[1]['drawings'][0]['id'];
eq(call('alice', 'drawing_save', ['id' => $eth, 'asset' => $A] + $trend)[0], 422, 'update cannot move a drawing to another (full) asset');
eq(count(call('alice', 'drawings', null, ['asset' => $A])[1]['drawings']), 500, 'still 500 on the full asset');
eq(call('bob', 'drawing_save', $trend)[0], 200, 'and per user — bob is not limited by alice\'s 500');
eq(call('alice', 'drawing_delete', ['id' => $id])[0], 200, 'delete');
eq(call('alice', 'drawing_save', $trend)[0], 200, 'after deleting one — room for a new one');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
