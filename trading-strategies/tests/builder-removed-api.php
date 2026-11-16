<?php
// בונה השיטות נמחק (spec-stage-a 21.1, ✅29). קוד בלבד — הנתונים נשארים: שורה של methods.kind = 'defined' (וגם
// method_versions, היומן והבדיקות לאחור שלה) נשארת במסד, והאפליקציה עולה בלי לטעון אותה. מה שמפנה אליה:
// התראה → "מושהית — השיטה הוסרה" (בלי שגיאה ב-cron), עסקה ביומן → המזהה עם "(שיטה שהוסרה)".
// php trading-strategies/tests/builder-removed-api.php — php -S עם שער מדומה. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-builder-removed-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── קריטריון 1: הקבצים לא קיימים, ואין להם זכר בקוד ──
$app = dirname(__DIR__);
foreach (['lib/builder.php', 'lib/expr.php', 'lib/methods/defined.php', 'assets/js/builder.js'] as $f) eq(is_file("$app/$f"), false, "criterion 1: $f is gone");
$src = implode("\n", array_map('file_get_contents', [...glob("$app/*.php"), ...glob("$app/lib/*.php"), ...glob("$app/lib/methods/*.php"), ...glob("$app/assets/js/*.js")]));
foreach (['TsDefinedMethod', 'ts_builder_', 'builder.js', 'openBuilder', 'admin_method_dry_run', 'admin_method_validate', 'admin_method_versions', 'admin_method_list'] as $w) {
    eq(str_contains($src, $w), false, "criterion 1: no trace of $w in the code");
}

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log"],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;
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
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw, $raw];
}
function cron(int $now): array
{
    global $root, $env;
    $p = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', "$root/trading-strategies/bin/alerts-cron.php", "--now=$now"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    return [$code, json_decode(trim($out), true), $err];
}

foreach (['alice', 'carol', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$alice = (int)$db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
$carol = (int)$db->query("SELECT id FROM users WHERE platform_sub = '103'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $carol])[0], 200, 'carol becomes an admin (not the primary one)');

// ── מסד עם שיטה מוגדרת מלפני המחיקה (בונה השיטות שמר אותה כך) ──
$D = 'ma-cross-def';
$def = json_encode(['id' => $D, 'name' => 'חציית ממוצעים (מוגדרת)', 'source' => 'בדיקה', 'markets' => ['crypto'], 'timeframes' => ['h1']]);
$db->prepare("INSERT INTO methods (id, enabled, markets, timeframes, params, note, kind, definition, definition_version) VALUES (?, 1, '[\"crypto\"]', '[\"h1\"]', '{}', '', 'defined', ?, 2)")
    ->execute([$D, $def]);
$db->prepare('INSERT INTO method_versions (method_id, version, definition, saved_by, saved_at) VALUES (?, 1, ?, NULL, ?), (?, 2, ?, NULL, ?)')
    ->execute([$D, $def, $NOW - 7200, $D, $def, $NOW - 3600]);
$db->prepare("INSERT INTO method_journal (method_id, method_version, asset, tf, cred_scope, as_of, kind, said, zone_lo, zone_hi, invalidation, until_t, created_at)
              VALUES (?, 2, 'binance:BTCUSDT', 'h1', '', ?, 'analysis', '{}', '1', '2', '0.5', ?, ?)")
    ->execute([$D, $NOW - 30 * 86400, $NOW - 29 * 86400, $NOW - 30 * 86400]);
$counts = fn() => array_map(fn($q) => (int)$db->query($q)->fetchColumn(), [
    "SELECT COUNT(*) FROM methods WHERE kind = 'defined'", 'SELECT COUNT(*) FROM method_versions', "SELECT COUNT(*) FROM method_journal WHERE method_id = '$D'"]);
$before = $counts();
eq($before, [1, 2, 1], 'the database has a defined method, its versions and a journal record');

// התראות של alice: על השיטה המוגדרת, בשקלול שכולל אותה, ועל מחיר (לא מושפעת)
$ins = $db->prepare("INSERT INTO alerts (user_id, asset, tf, side, kind, method_id, methods, level, status, expires_at, created_at) VALUES (?, 'binance:BTCUSDT', 'h1', 'both', ?, ?, ?, ?, 'active', ?, ?)");
$ins->execute([$alice, 'method', $D, '[]', null, $NOW + 30 * 86400, $NOW - 86400]);
$aMethod = (int)$db->lastInsertId();
$ins->execute([$alice, 'blend', null, json_encode(['elliott-w3', $D]), null, $NOW + 30 * 86400, $NOW - 86400]);
$aBlend = (int)$db->lastInsertId();
$ins->execute([$alice, 'price', null, '[]', '50000', $NOW + 30 * 86400, $NOW - 86400]);
$aPrice = (int)$db->lastInsertId();

// ── קריטריון 3: האפליקציה עולה, והשיטה לא מופיעה בשום מקום ──
[$c, $me] = call('owner', 'me');
eq($c, 200, 'criterion 3: the app comes up (me) with a defined row in the database');
[$c, $am] = call('owner', 'admin_methods');
eq([$c, in_array($D, array_column($am['methods'], 'id'), true)], [200, false], 'criterion 3: not in the methods admin screen');
eq(array_keys(array_filter($am['methods'][0], fn($k) => $k === 'definition', ARRAY_FILTER_USE_KEY)), [], 'the methods admin no longer sends a definition');
foreach (['owner', 'alice'] as $u) {
    [$c, $ms] = call($u, 'methods');
    eq([$c, in_array($D, array_column($ms['methods'], 'id'), true)], [200, false], "criterion 3: not in the 🔮 menu ($u)");
}
[$c] = call('owner', 'chart_refresh', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $NOW - 5 * 86400, 'to' => $NOW, 'methods' => $D]);
[$c2, $an] = call('owner', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $D]);
eq([$c === 200 || $c === 404 || $c === 422, $c2 === 404 || $c2 === 422], [true, true], "criterion 3: the chart and the analysis do not run it (refresh $c, analysis $c2)");
[$c, $mj] = call('owner', 'method_journal');
eq([$c, in_array($D, array_column(array_column($mj['rows'], 'method'), 'id'), true), in_array($D, array_column($mj['summary'], 'method_id'), true)
    || in_array($D, array_map(fn($x) => $x['method']['id'] ?? null, $mj['summary']), true)], [200, false, false], 'criterion 3: its journal records are kept but not shown (even to the admin)');

// ── קריטריון 2: admin_method_save עם definition → 422; בלי — כמו היום ──
[$c] = call('owner', 'admin_method_save', ['definition' => json_decode($def, true)]);
eq($c, 422, 'criterion 2: admin_method_save with a definition → 422');
foreach (['admin_method_validate', 'admin_method_dry_run'] as $a) eq(call('owner', $a, ['definition' => []])[0], 404, "criterion 2: $a → 404");
foreach (['admin_method_list', 'admin_method_versions'] as $a) eq(call('owner', $a, null, ['id' => $D])[0], 404, "criterion 2: $a → 404");
eq(call('owner', 'admin_method_save', ['id' => $D, 'enabled' => true])[0], 404, 'criterion 2: saving the settings of the removed method → 404 (unknown method)');
$S = 'sample-ma-cross';
$mset = ['id' => $S, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4', 'd1'], 'params' => [], 'note' => ''];
eq(call('carol', 'admin_method_save', $mset)[0], 200, 'criterion 2: without a definition — any admin turns a method on, as today');
eq(call('carol', 'admin_method_save', ['params' => ['fast' => 10]] + $mset)[0], 403, 'criterion 2: a parameter change — only the primary admin (18.7)');
[$c, $r] = call('owner', 'admin_method_save', ['params' => ['fast' => 10]] + $mset);
eq([$c, array_column($r['methods'], 'version', 'id')[$S] ?? null], [200, 2], 'criterion 2: the primary admin changes a parameter → version 2');

// ── קריטריון 4: התראה על שיטה כזו → "מושהית — השיטה הוסרה", בלי שגיאה ב-cron ──
[$code, $sum, $err] = cron($NOW);
eq([$code, $sum['errors'] ?? null, $sum['paused_removed'] ?? null, trim($err)], [0, 0, 2, ''], 'criterion 4: the cron runs with no error, and pauses the two alerts');
$st = fn($id) => $db->query("SELECT status FROM alerts WHERE id = $id")->fetchColumn();
eq([$st($aMethod), $st($aBlend), $st($aPrice)], ['paused', 'paused', 'active'], 'criterion 4: the method and blend alerts are paused; the price alert is not');
eq($sum['checked'], 1, 'criterion 4: the cron did not run the removed method (only the price alert was checked)');
[, $al] = call('alice', 'alerts');
$byId = array_column($al['alerts'], null, 'id');
eq([$byId[$aMethod]['status'], $byId[$aMethod]['method_removed'], $byId[$aBlend]['method_removed'], $byId[$aPrice]['method_removed']], ['paused', true, true, false],
    'criterion 4: the list says it is paused because the method was removed');
eq(call('alice', 'alert_status', ['id' => $aMethod, 'status' => 'active'])[0], 422, 'criterion 4: it cannot be turned back on');
[$code, $sum] = cron($NOW + 900);
eq([$code, $sum['errors'], $sum['paused_removed'], $st($aMethod)], [0, 0, 0, 'paused'], 'a second cron run — still paused, nothing to do');

// היומן: רשומה של השיטה שהוסרה — נשארת כמו שהיא (ה-cron לא מכריע אותה)
eq($db->query("SELECT outcome, resolved_at FROM method_journal WHERE method_id = '$D'")->fetch(), ['outcome' => null, 'resolved_at' => null],
    'the journal record of the removed method is kept as it is');

// ── עסקה ביומן עם method_id של השיטה שהוסרה ──
[$c, $r] = call('alice', 'strategy_save', ['name' => 'בדיקה', 'markets' => ['crypto'], 'setup' => ['א'], 'entry' => ['ב'], 'exit' => ['ג'], 'risk' => ['ד']]);
$v1 = $r['strategy']['current_version_id'] ?? null;
[$c, $r] = call('alice', 'trade_save', ['symbol' => 'btcusdt', 'market' => 'crypto', 'currency' => 'usd', 'direction' => 'long', 'strategy_version_id' => $v1,
    'opened_at' => '2026-03-02T16:30', 'entry_price' => '100', 'quantity' => '1', 'entry_fees' => '0']);
eq($c, 200, 'alice saves a trade');
$db->prepare('UPDATE trades SET method_id = ? WHERE id = ?')->execute([$D, $r['id']]);
[$c, $tr] = call('alice', 'trade', null, ['id' => $r['id']]);
eq([$c, $tr['trade']['method_id'], $tr['trade']['method_name']], [200, $D, "$D (שיטה שהוסרה)"], 'a trade with the removed method shows its id, "(שיטה שהוסרה)"');
[$c, $sx] = call('alice', 'stats');
eq([$c, $sx['method_names'][$D] ?? null], [200, "$D (שיטה שהוסרה)"], 'and the stats filter names it the same way');

// ── קריטריון 3 (סוף): השורות נשארות במסד — ספירה לפני ואחרי ──
eq($counts(), $before, 'criterion 3: no data deleted — the defined row, its versions and its journal record are all still there');
eq((int)$db->query("SELECT COUNT(*) FROM alerts")->fetchColumn(), 3, 'the alerts are paused, not deleted');
$cols = array_column($db->query('PRAGMA table_info(methods)')->fetchAll(), 'name');
eq(array_values(array_intersect(['kind', 'definition', 'definition_version'], $cols)), ['kind', 'definition', 'definition_version'], 'the columns stay');
eq(trim((string)@file_get_contents("$tmp.error.log")), '', 'no PHP error in the server log');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
