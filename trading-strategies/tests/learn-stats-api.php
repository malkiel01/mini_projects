<?php
// למידה, L3 (spec-stage-a 23.5–23.6, 23.10): הסטטיסטיקה לפי פלח, המינימום N = 30, וייצוא CSV. php -S עם שער מדומה.
// php trading-strategies/tests/learn-stats-api.php. השורות נכתבות ישירות למסד (סט ידוע); איך הן נוצרות — tests/learn-api.php.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-learn-stats-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
/** [קוד, JSON מפוענח או הגוף כמו שהוא, הכותרות] */
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw, $http_response_header];
}
foreach (['owner', 'alice', 'bob'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$alice = (int)$db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $alice])[0], 200, 'alice becomes a secondary editor');

// ── הסט: sample-ma-cross גרסה 1, backtest. 30 עסקאות ביום שישי (15 הצלחות), 29 ביום רביעי (10), 3 פערים; ועוד live ו-trade ──
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, result, win, r,
    market, month, quarter, weekday, hour, holidays, user_id, trade_id, created_at) VALUES (?, ?, 1, 'h', 'binance', 'binance:BTCUSDT', 'h1', ?, ?, 'long', ?, ?, ?,
    'crypto', 3, 1, ?, 10, ?, ?, ?, 0)");
$k = 0;
$add = function (string $source, string $method, int $weekday, bool $win, ?float $r = null, string $hol = '[]', ?int $user = null) use ($ins, &$k) {
    $k++;
    $ins->execute([$source, $method, 1_700_000_000 + $k * 3600, 1_700_000_000 + $k * 3600 + 7200, $r === null ? 'skipped_gap' : ($win ? 'win' : 'loss'),
        $win ? 1 : 0, $r, $weekday, $hol, $user, $user ? $k : 0]);
};
for ($i = 0; $i < 30; $i++) $add('backtest', 'sample-ma-cross', 5, $i < 15, $i < 15 ? 2.0 : -1.0, $i < 3 ? '["US"]' : '[]');
for ($i = 0; $i < 29; $i++) $add('backtest', 'sample-ma-cross', 3, $i < 10, $i < 10 ? 2.0 : -1.0);
for ($i = 0; $i < 3; $i++) $add('backtest', 'sample-ma-cross', 1, false);   // פער — בלי R
for ($i = 0; $i < 40; $i++) $add('live', 'sample-ma-cross', 2, true, 1.0);
$add('trade', 'sample-ma-cross', 2, true, 1.0, '[]', $alice);
for ($i = 0; $i < 24; $i++) $add('backtest', 'fibonacci', 5, $i === 0, $i === 0 ? 3.0 : -1.0);   // 22.5ח: 24 עסקאות, רווח אחד

$seg = fn(array $L, string $dim, string $v) => array_values(array_filter($L['segments'], fn($s) => $s['dim'] === $dim && $s['value'] === $v))[0] ?? null;

// ── L3 קריטריון 1: 29 → "אין מספיק נתונים", בלי אחוז; 30 → אחוז ותוחלת ──
[$c, $L] = call('owner', 'admin_learn', null, ['method' => 'sample-ma-cross', 'version' => 1]);
eq([$c, $L['source'] ?? null, $L['min_n'] ?? null], [200, 'backtest', 30], 'the learning tab: backtest by default, minimum N = 30');
$fri = $seg($L, 'weekday', '5'); $wed = $seg($L, 'weekday', '3');
eq([$fri['n'], $fri['enough'], $fri['win_pct'], $fri['expectancy_r'], is_int($fri['certainty'])], [30, true, 50, 0.5, true], 'criterion 1: a segment of 30 → win rate, expectancy and certainty');
eq([$wed['n'], $wed['enough'], $wed['win_pct'], $wed['expectancy_r'], $wed['certainty'], $wed['wins']], [29, false, null, null, null, null], 'criterion 1: a segment of 29 → only N, no number derived from it');
eq([$L['total']['n'], $L['total']['enough'], $L['gaps']], [59, true, 3], 'the total: 59 trades with R; 3 gaps outside the statistics');
eq([$seg($L, 'market_tf', 'crypto|h1')['n'] ?? null, $seg($L, 'holidays', 'US')['n'] ?? null, $seg($L, 'holidays', 'none')['n'] ?? null], [59, 3, 56],
    'segments: market × tf; a holiday region; "no holiday"');
eq(array_values(array_unique(array_column($L['segments'], 'dim'))), ['market', 'tf', 'market_tf', 'month', 'quarter', 'weekday', 'hour', 'holidays'], 'every tag alone, plus market × tf (23.5)');
eq([$L['counts'] ?? null, $L['rows'] ?? null], [[['source' => 'backtest', 'version' => 1, 'n' => 62], ['source' => 'live', 'version' => 1, 'n' => 40],
    ['source' => 'trade', 'version' => 1, 'n' => 1]], 103], 'N by source and version, and the size of the table');

// לא מערבבים מקורות (23.7)
[, $Lv] = call('owner', 'admin_learn', null, ['method' => 'sample-ma-cross', 'version' => 1, 'source' => 'live']);
eq([$Lv['total']['n'], $Lv['total']['win_pct']], [40, 100], 'live — separately, not mixed with the backtest');
eq(str_contains(json_encode($L) . json_encode($Lv), 'user_id'), false, 'no user_id in the learning tab');

// ── L3 קריטריון 2: פיבונאצ'י עם 24 עסקאות בסך הכול → כל הפלחים "אין מספיק נתונים" ──
[, $F] = call('owner', 'admin_learn', null, ['method' => 'fibonacci', 'version' => 1]);
eq([$F['total']['n'], $F['total']['enough'], count(array_filter($F['segments'], fn($s) => $s['enough'] || $s['win_pct'] !== null))], [24, false, 0],
    'criterion 2: fibonacci with 24 trades — every segment "not enough data", no number anywhere');

// ── כל שיטה לחוד: הגרסה הנוכחית כברירת מחדל; שיטה לא מוכרת → 404 ──
[, $Ld] = call('owner', 'admin_learn', null, ['method' => 'sample-ma-cross']);
eq([$Ld['version'], $Ld['total']['n']], [1, 59], 'without a version — the current version of the method');
eq(call('owner', 'admin_learn', null, ['method' => 'nope'])[0], 404, 'an unknown method → 404');
eq(call('owner', 'admin_learn', null, ['method' => 'sample-ma-cross', 'source' => 'x'])[0], 422, 'an unknown source → 422');

// ── L3 קריטריון 3: CSV — אותן שורות כמו במסד; user_id רק לעורך הראשי ──
[$c, $csv, $hd] = call('owner', 'admin_learn_csv', null, ['method' => 'sample-ma-cross']);
$lines = array_values(array_filter(explode("\n", (string)$csv)));
$head = str_getcsv(ltrim($lines[0] ?? '', "\xEF\xBB\xBF"), ',', '"', '');
$dbN = (int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE method_id = 'sample-ma-cross'")->fetchColumn();
eq([$c, count($lines) - 1, $dbN, in_array('user_id', $head, true)], [200, 103, 103, true], 'criterion 3: the CSV — the same rows as in the database; the primary editor gets user_id');
eq((bool)preg_grep('/^Content-Type: text\/csv/i', $hd), true, 'a CSV file');
$ids = array_map(fn($l) => (int)str_getcsv($l, ',', '"', '')[0], array_slice($lines, 1));
eq($ids, array_map('intval', $db->query("SELECT id FROM method_outcomes WHERE method_id = 'sample-ma-cross' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), '… exactly those rows, in order');
[$c, $csvA] = call('alice', 'admin_learn_csv', null, ['method' => 'sample-ma-cross']);
$headA = str_getcsv(ltrim(strtok((string)$csvA, "\n"), "\xEF\xBB\xBF"), ',', '"', '');
eq([$c, in_array('user_id', $headA, true), count(array_filter(explode("\n", (string)$csvA))) - 1], [200, false, 103], 'criterion 3: a secondary editor — the same rows, without the user_id column');
[$c] = call('owner', 'admin_learn_csv', null, ['method' => 'sample-ma-cross', 'source' => 'live', 'version' => 1]);
eq(count(array_filter(explode("\n", (string)call('owner', 'admin_learn_csv', null, ['method' => 'sample-ma-cross', 'source' => 'live'])[1]))) - 1, 40, 'CSV by source');

// ── מי רואה (24.2): עורכים — כן; משתמש — לא ──
eq([call('alice', 'admin_learn', null, ['method' => 'sample-ma-cross'])[0], call('bob', 'admin_learn', null, ['method' => 'sample-ma-cross'])[0],
    call('bob', 'admin_learn_csv', null, ['method' => 'sample-ma-cross'])[0]], [200, 403, 403], 'editors see the tab; a user does not (also not the CSV)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
