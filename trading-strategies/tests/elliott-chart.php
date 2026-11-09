<?php
// בדיקות 4א/4 — אליוט בגרף: elliott-w3 ב"🔮 תחזית שיטה" (spec-stage-a 17.1.7, 17.2.2–17.2.3, ✅15, ✅16).
// php trading-strategies/tests/elliott-chart.php — חלק טהור, ואחריו php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-ew-chart-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/lib/overlay.php';
$H = 3600;
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$m = ts_method('elliott-w3');
$P = ['pivot_atr_mult' => 2.0] + $m->params();
$bad = 0; $with = 0; $words = []; $pct = 0; $keys = true;
foreach (range(300, 4999, 53) as $i) {
    try { $o = ts_overlay_check($m->overlay(array_slice($F, 0, $i + 1), $P, $H), $H); } catch (TsOverlayInvalid $e) { $bad++; continue; }
    if ($o['items']) $with++;
    if (array_key_exists('counts', $o) || !array_key_exists('check', $o)) $keys = false;
    foreach ($o['projection']['scenarios'] as $s) { $words[$s['words']] = true; if (preg_match('/\d\s*%/', $s['label'])) $pct++; }
}
eq([$bad, $with > 5], [0, true], "elliott-w3 overlay passes the contract (17.2.3) on every window ($with with counts)");
eq([array_diff(array_keys($words), ['התאמה גבוהה', 'התאמה בינונית']), $pct, $keys], [[], 0, true], 'score only in words (✅16), no percentage; no internal "counts" sent, "check" is');
// פערים בנתונים (סופי שבוע במניות): הזמנים העתידיים עדיין t + k·tf מהנר האחרון
$gappy = array_values(array_filter($F, fn($c) => intdiv($c['t'], 86400) % 7 < 5));
$gb = 0; $gw = 0;
foreach (range(300, count($gappy) - 1, 61) as $i) {
    try { $o = ts_overlay_check($m->overlay(array_slice($gappy, 0, $i + 1), $P, $H), $H); if ($o['items']) $gw++; } catch (TsOverlayInvalid) { $gb++; }
}
eq([$gb, $gw > 0], [0, true], "with gaps in the data the future times stay aligned to as_of + k·tf ($gw windows with counts)");
// המנהל משנה את הרגישות → ספירות אחרות
$a = $m->overlay(array_slice($F, 0, 3000), ['pivot_atr_mult' => 2.0] + $m->params(), $H);
$b = $m->overlay(array_slice($F, 0, 3000), ['pivot_atr_mult' => 5.0] + $m->params(), $H);
eq(json_encode($a['items']) !== json_encode($b['items']), true, 'the method parameters (pivot sensitivity) drive the chart counts');
$e = $m->overlay([], $m->params(), $H);
eq([$e['items'], is_string($e['note'] ?? null)], [[], true], 'no candles → empty overlay with a note');
eq(ts_method('sample-ma-cross')->overlay(array_slice($F, 0, 1301), ts_method('sample-ma-cross')->params(), $H)['items'][0]['kind'] ?? null, 'marker', 'other methods keep the default overlay (4ב/2)');

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
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'method' => 'elliott-w3', 'from' => $NOW - 90 * 86400, 'to' => $NOW];
eq(call('alice', 'method_overlay', null, $q)[0], 404, 'off → the forecast refuses elliott-w3');
call('owner', 'admin_method_save', ['id' => 'elliott-w3', 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'], 'params' => ['pivot_atr_mult' => 2]]);
[$c, $r] = call('alice', 'method_overlay', null, $q);
$ov = $r['overlay'] ?? [];
eq([$c, $r['status'] ?? null, $r['title'] ?? null, $r['basis'] ?? null], [200, 'ok', 'תרחיש לפי שיטת גלי אליוט — כניסה לגל 3 — לא ייעוץ', 'לפי כללי השיטה, לא נבדק לאחור'], 'on → "תרחיש לפי שיטת גלי אליוט — כניסה לגל 3 — לא ייעוץ", not backtested (✅15)');
eq([array_key_exists('counts', $ov), count($ov['check'] ?? []) >= 4, $r['method']['label'] ?? null], [false, true, 'שיטה שלנו על בסיס כללי הספר (Frost & Prechter)'], 'the chart gets the contract and the "check yourself" guidelines, with the method label');
$sc = $ov['projection']['scenarios'] ?? [];
eq(count($sc) > 0 || is_string($ov['note'] ?? null), true, 'either counts (scenarios) or a note why there are none (' . count($sc) . ' scenarios)');
foreach ($sc as $s) eq(preg_match('/^התאמה (גבוהה|בינונית) · דחף (עולה|יורד), \d גלים — הבא: /u', $s['label']), 1, 'scenario label: words, direction, waves and the next one — ' . $s['label']);
eq(count(array_filter($ov['items'] ?? [], fn($x) => $x['kind'] === 'hline' && $x['role'] === 'invalidation')) === count($sc), true, 'one red invalidation line per count');
eq(array_sum(array_map(fn($s) => $s['primary'] ? 1 : 0, $sc)), $sc ? 1 : 0, 'exactly one leading count; the rest are alternates');
eq(call('alice', 'method_overlay', null, ['tf' => 'm15'] + $q)[1]['status'] ?? null, 'unsupported', 'm15 → unsupported (✅18)');
eq(preg_match('/קנה|מכור|המלצ|כדאי|\bיעד\b/u', json_encode($r, JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend/target" wording (6.6, ✅15)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
