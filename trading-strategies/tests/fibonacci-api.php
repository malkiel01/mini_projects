<?php
// בדיקות F-1 — פיבונאצ'י בניהול, בגרף ובכרטיס (spec-stage-a 22.1, 22.7; קריטריון 10, והצד של השרת בקריטריון 9).
// php trading-strategies/tests/fibonacci-api.php — php -S עם שער מדומה וספקים מדומים, שעון קבוע (X-Dev-Now). בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-fib-api-' . getmypid() . '-' . bin2hex(random_bytes(4));   // שם קובץ בלבד — לא משפיע על התוצאה
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
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log", 'TS_KLINES_SHAPE' => 'wave'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200 - 70 * 3600;   // בנתוני wave יש כאן אזור חפיפה ורמות בודדות בשעה (ב-1794094200 עצמו — אין)
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
$F = 'fibonacci';
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $F, 'from' => $NOW - 40 * 86400, 'to' => $NOW];

// ── קריטריון 10: מופיעה בניהול כבויה ──────────────────────
$am = array_column(call('owner', 'admin_methods')[1]['methods'], null, 'id');
$fm = $am[$F] ?? [];
eq([$fm['enabled'] ?? null, $fm['sample'] ?? null, $fm['alerts'] ?? null, $fm['name'] ?? null, $fm['all_timeframes'] ?? null, $fm['label'] ?? null],
    [false, false, true, "פיבונאצ'י", ['h1', 'h4', 'd1', 'w1'], 'שיטה שלנו על בסיס כללי הספר (Constance Brown)'],
    'criterion 10: listed in methods admin OFF (22.1, 6.7), not a sample, alerts allowed, h1 and up');
eq([($am['elliott-w3']['enabled'] ?? null)], [true], 'Elliott is still on by default (unchanged)');
eq((int)$db->query("SELECT COUNT(*) FROM methods WHERE id = 'fibonacci'")->fetchColumn(), 0, 'off without a row in methods — by default, not by a write');
eq(isset(array_column(call('alice', 'methods')[1]['methods'], null, 'id')[$F]), false, 'off → a regular user is not offered it (no 🔮 entry)');
eq(call('alice', 'chart_refresh', null, $q)[1]['methods'][$F]['status'] ?? null, 'unavailable', 'off → the chart gets "unavailable"');
eq(call('alice', 'analysis', null, $q)[0], 404, 'off → a regular user cannot run its card');

// אחרי הפעלה
eq(call('owner', 'admin_method_save', ['id' => $F, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'], 'params' => []])[0], 200, 'the admin turns it on');
$vis = array_column(call('alice', 'methods')[1]['methods'], null, 'id');
eq([isset($vis[$F]), $vis[$F]['name'] ?? null, $vis[$F]['alerts'] ?? null], [true, "פיבונאצ'י", true], 'criterion 10: on → in the 🔮 list of a regular user (the menu reads api methods)');
eq(call('owner', 'admin_method_save', ['id' => $F, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4'], 'params' => ['zone_atr' => 1, 'zone_max_atr' => 0.5]])[0], 422,
    'validate: zone_max_atr < zone_atr → rejected');

[$c, $r] = call('alice', 'chart_refresh', null, $q);
$res = $r['methods'][$F] ?? [];
eq([$c, $res['status'] ?? null, $res['title'] ?? null], [200, 'ok', "תרחיש לפי שיטת פיבונאצ'י — לא ייעוץ"], 'chart_refresh: ok, with the 6.6 title');
$items = $res['overlay']['items'] ?? [];
$zones = array_values(array_filter($items, fn($x) => $x['kind'] === 'zone'));
$singles = array_values(array_filter($items, fn($x) => !empty($x['single'])));
eq([count($zones) > 0, count($singles) > 0, array_unique(array_column($zones, 'extend')), $res['forecast']['items'] ?? null, $res['forecast']['scenarios'] ?? null],
    [true, true, [true], [], []], 'criterion 9 (server): zones (extend right) and single levels in the finding; no scenario layer in F-1 (' . count($zones) . ' zones, ' . count($singles) . ' singles)');
eq(array_filter($zones, fn($z) => $z['t2'] !== $res['overlay']['as_of']), [], 'every zone reaches the last closed candle (and the chart draws it on to the edge — R3)');

// הכרטיס: "הסתברות: לא נבדק לאחור" — בלי בדיקה לאחור, וגם עם פחות מ-30 אותות
$card = array_values(array_filter($r['analysis']['cards'] ?? [], fn($x) => $x['method']['id'] === $F))[0] ?? [];
eq([$card['status'] ?? null, $card['backtest']['status'] ?? null, $card['backtest']['certainty'] ?? null], ['ok', 'missing', null], 'criterion 10: the card with the chart — no backtest run → no certainty');
$ids = [];
foreach ($card['conditions'] ?? [] as $x) $ids[$x['id']] = $x['met'];
eq(array_keys($ids), ['l-zone', 'l-touch', 'l-rr', 's-zone', 's-touch', 's-rr', 'manual-trunc', 'manual-pattern', 'manual-tf'], '22.7: the card conditions');
eq([$ids['manual-trunc'], $ids['manual-tf'], is_bool($ids['l-zone'])], [null, null, true], '22.7: "check yourself" — met null; computed — true/false');
[$c, $r2] = call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $F]);
$bt = $r2['cards'][0]['backtest'] ?? [];
eq([$c, in_array($bt['status'] ?? null, ['ok', 'insufficient'], true), ($bt['status'] ?? null) === 'ok' ? ($bt['n'] ?? 0) >= 30 : ($bt['certainty'] ?? null) === null],
    [200, true, true], 'criterion 10: after a backtest — certainty only from N ≥ 30, otherwise none (status ' . ($bt['status'] ?? '?') . ', N ' . ($bt['n'] ?? '?') . ')');
$row = $db->query("SELECT method_version, state FROM backtests WHERE method_id = 'fibonacci' AND tf = 'h1'")->fetch(PDO::FETCH_ASSOC);
eq([$row !== false, (int)($row['method_version'] ?? 0)], [true, 1], 'the backtest is stored under fibonacci v1');
eq(call('alice', 'chart_refresh', null, ['tf' => 'm15'] + $q)[1]['methods'][$F]['status'] ?? null, 'unsupported', 'm15 → unsupported (h1 and up)');
eq(preg_match('/קנה|מכור|המלצ|כדאי|\bיעד\b/u', json_encode([$res, $card], JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend/target" wording (6.6)');

// היומן (S6): ריצה של נר סגור חדש נרשמת גם לפיבונאצ'י
eq((int)$db->query("SELECT COUNT(*) FROM method_journal WHERE method_id = 'fibonacci' AND kind = 'analysis'")->fetchColumn() >= 1, true, 'journal (S6): the chart run is recorded for fibonacci');

$err = array_filter(explode("\n", (string)@file_get_contents("$tmp.error.log")), fn($l) => preg_match('/PHP (Fatal error|Parse error|Warning|Notice|Deprecated)/', $l) && !str_contains($l, 'in Unknown on line 0'));
eq(array_values($err), [], 'no PHP errors in the server log');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
