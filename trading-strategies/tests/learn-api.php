<?php
// למידה עצמית, L1 — בדיקה לאחור אמיתית דרך ה-API כותבת ל-method_outcomes (spec-stage-a 23.3–23.4, קריטריונים 1, 2, 7).
// php trading-strategies/tests/learn-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת. המנוע והתגיות — tests/learn.php.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-learn-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
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
function call(string $as, string $action, ?array $body = null, array $query = [], ?int $now = null): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", 'X-Dev-Now: ' . ($now ?? $NOW)];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
/** השורות במסד (ישירות מהקובץ) — [count, wins, avg r] של מקור backtest, בלי skipped_gap */
function rows(string $method, ?int $version = null): array
{
    global $tmp;
    $db = new PDO("sqlite:$tmp.sqlite");
    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(win), 0), AVG(r) FROM method_outcomes WHERE source = 'backtest' AND method_id = ? AND result != 'skipped_gap'"
        . ($version !== null ? ' AND method_version = ?' : ''));
    $st->execute($version !== null ? [$method, $version] : [$method]);
    [$c, $w, $r] = $st->fetch(PDO::FETCH_NUM);
    return [(int)$c, (int)$w, $r === null ? null : (float)$r];
}

$tokens['owner'] = call('owner', 'me')[1]['csrf'];
$save = fn(array $params) => call('owner', 'admin_method_save', ['id' => 'sample-ma-cross', 'enabled' => true, 'markets' => ['crypto'],
    'timeframes' => ['h1'], 'params' => $params, 'note' => ''])[0];
eq($save([]), 200, 'the admin turns on sample-ma-cross');
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'sample-ma-cross'];
$bt = fn(array $r) => $r['cards'][0]['backtest'] ?? [];

// ── קריטריון 1: מספר השורות = N של backtests; אחוז ההצלחה ותוחלת R מהשורות = הסיכום ──
[$c, $r] = call('owner', 'analysis', null, $q);
$b = $bt($r);
eq([$c, $b['mode'] ?? null, ($b['n'] ?? 0) > 30], [200, 'full', true], 'a full backtest with more than 30 trades');
[$cnt, $wins, $avg] = rows('sample-ma-cross');
eq([$cnt, $wins], [$b['n'] ?? null, $b['wins'] ?? null], 'criterion 1: rows in method_outcomes = N of the backtest, and the wins agree');
eq(abs(($avg ?? 0) - ($b['expectancy_r'] ?? 1)) < 1e-9, true, 'criterion 1: the expectancy R from the rows = the summary (up to rounding)');
eq(round($wins / max(1, $cnt), 9), round($b['win_rate'] ?? -1, 9), 'criterion 1: the win rate from the rows = the summary');

// ── קריטריון 2: חישוב חוזר — 0 שורות חדשות ──
[$c, $r] = call('owner', 'analysis', null, $q);
eq([$bt($r)['mode'] ?? null, rows('sample-ma-cross')[0]], ['stored', $cnt], 'criterion 2: the same backtest again → stored, 0 new rows');

// ── נרות חדשים (אחרי יום — מצטבר): רק העסקאות שנסגרו מאז ──
$NOW2 = $NOW + 3 * 86400;
[$c, $r] = call('owner', 'analysis', null, $q, $NOW2);
$b2 = $bt($r);
[$cnt2] = rows('sample-ma-cross');
eq([$b2['mode'] ?? null, $cnt2, $cnt2 > $cnt], ['incremental', $b2['n'] ?? null, true], 'new candles that close trades → only the new trades are added (rows = the new N)');
$db = new PDO("sqlite:$tmp.sqlite");
eq((int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE t_exit > " . ($NOW - 3600))->fetchColumn(), $cnt2 - $cnt, '… and they are exactly the ones that closed after the first run');
eq((int)$db->query("SELECT COUNT(*) FROM (SELECT t_signal FROM method_outcomes GROUP BY source, method_id, method_version, params_hash, asset, tf, t_signal HAVING COUNT(*) > 1)")->fetchColumn(),
    0, 'no row is duplicated');

// ── קריטריון 7: גרסה חדשה (שינוי פרמטר — "החל") → שורות חדשות, והישנות נשארות ──
eq($save(['fast' => 19]), 200, 'the primary admin changes a parameter → version 2');
[$c, $r] = call('owner', 'analysis', null, $q, $NOW2);
$b3 = $bt($r);
eq([$b3['mode'] ?? null, rows('sample-ma-cross', 1)[0], rows('sample-ma-cross', 2)[0]], ['full', $cnt2, $b3['n'] ?? null], 'criterion 7: version 2 — new rows; the rows of version 1 remain as they were');

// ── תגיות ושדות ──
$one = $db->query("SELECT * FROM method_outcomes WHERE method_version = 1 ORDER BY t_signal LIMIT 1")->fetch(PDO::FETCH_ASSOC);
eq([$one['market'], $one['tf'], $one['cred_scope'], $one['user_id'], (int)$one['trade_id'], $one['weekday'] >= 1 && $one['weekday'] <= 7, $one['hour'] !== null],
    ['crypto', 'h1', '', null, 0, true, true], 'a row: market, tf, scope, no user, weekday, hour');
eq([is_numeric($one['entry']), is_numeric($one['atr']), $one['mae_r'] !== null, $one['mfe_r'] !== null], [true, true, true, true], 'a row: entry, ATR, MAE and MFE');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
