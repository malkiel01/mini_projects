<?php
// יומן השיטה (spec-stage-a 18.6, S6, ✅27): חמשת קריטריוני הקבלה בשרת — רשומה לנר, התוצאה ב-cron, גרסה, מפתח אישי, הסיכום.
// php trading-strategies/tests/method-journal-api.php — php -S עם שער מדומה וספקים מדומים (נתוני wave), וה-cron כ-CLI. בלי רשת.
// המסך — tests/method-journal-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-method-journal-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php', 'TS_KLINES_SHAPE' => 'wave'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;   // "עכשיו" קבוע (X-Dev-Now) — אותם נרות מדומים בכל ריצה; חצי שעה אחרי שעה עגולה
/** [קוד, JSON מפוענח, הגוף כמו שהגיע] */
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
    if ($err !== '') echo "  (cron stderr) $err\n";
    return [$code, json_decode(trim($out), true)];
}

// ── הלוגיקה של התוצאה (ts_journal_outcome) — בלי שרת ──
define('TS_DB_PATH', "$tmp.unit.sqlite");
require_once dirname(__DIR__) . '/lib/journal.php';
$k = fn($t, $h, $l) => ['t' => $t, 'h' => $h, 'l' => $l];
$long = ['zone_lo' => '110', 'zone_hi' => '120', 'invalidation' => '95'];   // אזור מעל, ביטול מתחת
eq(ts_journal_outcome($long, [$k(1, '105', '100'), $k(2, '111', '104'), $k(3, '106', '90')]), ['zone', 2, null], 'outcome: the zone is touched first → zone');
eq(ts_journal_outcome($long, [$k(1, '105', '96'), $k(2, '104', '94.5'), $k(3, '125', '110')]), ['invalidated', 2, null], 'outcome: the invalidation level is reached first → invalidated');
eq(ts_journal_outcome($long, [$k(1, '121', '94')]), ['invalidated', 1, 'same_candle'], 'outcome: both in the same candle → invalidated (conservative), noted');
eq(ts_journal_outcome($long, [$k(1, '105', '100'), $k(2, '109.99', '95.01')]), ['neither', null, null], 'outcome: neither until the end of the range → neither');
eq(ts_journal_outcome($long, []), ['neither', null, 'no_candles'], 'outcome: no candles at all → neither, noted');
$short = ['zone_lo' => '80', 'zone_hi' => '90', 'invalidation' => '105'];  // אזור מתחת, ביטול מעל
eq(ts_journal_outcome($short, [$k(1, '104', '95'), $k(2, '105', '96')]), ['invalidated', 2, null], 'outcome (short): the invalidation above is reached by the high');
eq(ts_journal_outcome($short, [$k(1, '100', '90')]), ['zone', 1, null], 'outcome (short): the low touches the top of the zone → zone');
eq(ts_journal_outcome(['zone_lo' => null, 'zone_hi' => null, 'invalidation' => '100'], [$k(1, '99', '98'), $k(2, '101', '99')]), ['invalidated', 2, null], 'outcome: only an invalidation level → reached when a candle crosses it');

foreach (['alice', 'bob', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$uidOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
$set = fn($id, $mk = ['crypto']) => call('owner', 'admin_method_save', ['id' => $id, 'enabled' => true, 'markets' => $mk, 'timeframes' => ['h1', 'h4', 'd1'], 'params' => [], 'note' => '']);
eq([$set('elliott-w3')[0], $set('test-cross-a', ['crypto', 'stocks'])[0]], [200, 200], 'the admin turns on Elliott and a test method');
eq((int)$db->query('PRAGMA user_version')->fetchColumn() >= 11, true, 'migration 11 ran');
$cols = array_column($db->query('PRAGMA table_info(method_journal)')->fetchAll(), 'name');
eq(in_array('user_id', $cols, true), false, '✅27: the journal is system-wide — no column says who asked');

$from = $NOW - 30 * 86400;
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $from, 'to' => $NOW, 'methods' => 'elliott-w3'];
$count = fn(string $where = "kind = 'analysis'") => (int)$db->query("SELECT COUNT(*) FROM method_journal WHERE $where")->fetchColumn();

// ── קריטריון 1: ניתוח אחד → רשומה אחת. עשרה רענונים בלי נר חדש → עדיין אחת ──
[$c, $r] = call('alice', 'chart_refresh', null, $q);
eq([$c, $r['runs'], $r['methods']['elliott-w3']['status']], [200, 1, 'ok'], 'the first refresh runs Elliott');
eq($count(), 1, 'criterion 1: one analysis → one record');
for ($i = 0; $i < 10; $i++) call('alice', 'chart_refresh', null, $q);
eq($count(), 1, 'criterion 1: ten refreshes without a new candle → still one record');
[, $rb] = call('bob', 'chart_refresh', null, ['from' => $from + 86400] + $q);
eq([$rb['runs'], $count()], [1, 1], 'another user with another window on the same last closed candle: the method runs (another key), and still one record');
$row = $db->query("SELECT * FROM method_journal WHERE method_id = 'elliott-w3' ORDER BY id LIMIT 1")->fetch();
eq([(int)$row['as_of'], (int)$row['method_version'], $row['asset'], $row['tf'], $row['cred_scope'], $row['kind'], $row['outcome']],
    [$NOW - 5400, 1, 'binance:BTCUSDT', 'h1', '', 'analysis', null], 'the record: method, version, asset, frame, scope, as_of = the last closed candle; outcome unknown');
$said = json_decode($row['said'], true);
eq([is_string($said['finding']), $said['scenario']['zone']['lo'] === $row['zone_lo'], $said['scenario']['invalidation'] === $row['invalidation'],
    (int)$said['scenario']['until'] === (int)$row['until_t'], (int)$row['until_t'] > $NOW - 5400, dec_cmp($row['zone_lo'], $row['zone_hi']) <= 0],
    [true, true, true, true, true, true], 'what was said: the finding, and the scenario — zone, time range, invalidation level');
call('alice', 'chart_refresh', null, ['to' => $NOW + 3600] + $q, $NOW + 3600);
eq($count("method_id = 'elliott-w3'"), 2, 'a new closed candle → a second record');

// ── קריטריון 2: התוצאה ב-cron מנרות סגורים; לפני הזמן — "עוד לא ידוע" ──
$J = fn(string $as, array $qq = []) => call($as, 'method_journal', null, $qq)[1];
$mine = array_values(array_filter($J('owner', ['method' => 'elliott-w3'])['rows'], fn($x) => $x['id'] === (int)$row['id']))[0];
eq([$mine['outcome'], $mine['outcome_text']], [null, 'עוד לא ידוע'], 'criterion 2: a record before its time — "not known yet"');
$until = (int)$row['until_t'];
[$code, $s] = cron($until);
eq([$code, $db->query("SELECT outcome FROM method_journal WHERE id = $row[id]")->fetchColumn()], [0, null], 'criterion 2: the cron at the last candle of the range (still open) → still unknown');
[$code, $s] = cron($until + 3600 + 60);
$res = $db->query("SELECT * FROM method_journal WHERE id = $row[id]")->fetch();
eq([$code, in_array($res['outcome'], ['zone', 'invalidated', 'neither'], true), $s['journal']['resolved'] >= 1], [0, true, true], 'criterion 2: after the range the cron fills the outcome (' . $res['outcome'] . ')');
// בדיקה עצמאית: אותם נרות מהנוסחה של הנתונים המדומים, ואותו כלל — ראשון שנגע
$px = fn(int $t) => 82000 + 5200 * sin($t / (86400 * 9.0)) + 1900 * sin($t / (86400 * 2.3) + 1) + 600 * sin($t / 31000.0 + 2);
$want = ['neither', null];
for ($t = (int)$row['as_of'] + 3600; $t <= $until; $t += 3600) {
    $o = $px($t); $cl = $px($t + 3600);
    $h = (float)sprintf('%.2f', max($o, $cl) + 90); $l = (float)sprintf('%.2f', min($o, $cl) - 90);
    $iv = (float)$row['invalidation']; $lo = (float)$row['zone_lo']; $hi = (float)$row['zone_hi'];
    $zone = $l <= $hi && $h >= $lo;
    $inv = $iv < $lo ? $l <= $iv : ($iv > $hi ? $h >= $iv : ($l <= $iv && $h >= $iv));
    if ($inv) { $want = ['invalidated', $t]; break; }
    if ($zone) { $want = ['zone', $t]; break; }
}
eq([$res['outcome'], $res['outcome_t'] !== null ? (int)$res['outcome_t'] : null], $want, 'criterion 2: the outcome matches the closed candles, computed independently');
eq((int)$res['resolved_at'], $until + 3660, 'resolved by the cron run');
cron($until + 7 * 86400);
eq($db->query("SELECT * FROM method_journal WHERE id = $row[id]")->fetch(), $res, 'a later cron run does not touch a decided record');
$second = $db->query("SELECT outcome FROM method_journal WHERE method_id = 'elliott-w3' AND id != $row[id]")->fetchColumn();
eq(in_array($second, ['zone', 'invalidated', 'neither'], true), true, 'and the second record was decided too, once its range passed');

// ── קריטריון 3: הגרסה נשמרת; גרסה חדשה לא משכתבת ──
// (בונה השיטות נמחק ב-21.1 — גרסה חדשה באה משינוי פרמטר של המנהל הראשי, methods.tune, 18.7)
$S = 'sample-ma-cross';
$sset = fn(array $p) => call('owner', 'admin_method_save', ['id' => $S, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4', 'd1'], 'params' => $p, 'note' => '']);
eq($sset([])[0], 200, 'the admin turns on the sample method');
$qd = ['methods' => $S] + $q;
[, $r1] = call('owner', 'chart_refresh', null, $qd);
eq($r1['methods'][$S]['method']['version'] ?? null, 1, 'it runs as version 1');
$v1 = $db->query("SELECT * FROM method_journal WHERE method_id = '$S'")->fetchAll();
eq([count($v1), (int)$v1[0]['method_version']], [1, 1], 'criterion 3: the record is saved with the method version');
eq($sset(['fast' => 21])[0], 200, 'the primary admin changes a parameter → a new version');
[, $r2] = call('owner', 'chart_refresh', null, $qd);
eq($r2['methods'][$S]['method']['version'] ?? null, 2, 'the method is now version 2');
$both = $db->query("SELECT * FROM method_journal WHERE method_id = '$S' ORDER BY id")->fetchAll();
eq([count($both), (int)$both[1]['method_version'], (int)$both[1]['as_of']], [2, 2, (int)$v1[0]['as_of']], 'criterion 3: version 2 on the same candle → a record of its own');
eq($both[0], $v1[0], 'criterion 3: the version-1 record is unchanged, column by column');

// ── בדיקה לאחור — רשומה מסוג backtest, פעם אחת לחישוב ──
eq(call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'test-cross-a'])[0], 200, 'alice runs a backtest');
call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => 'test-cross-a']);
eq($count("kind = 'backtest' AND method_id = 'test-cross-a'"), 1, 'a backtest writes one record of kind backtest; the stored result again → no new one');
$bt = json_decode($db->query("SELECT said FROM method_journal WHERE kind = 'backtest'")->fetchColumn(), true)['backtest'] ?? [];
eq([isset($bt['n'], $bt['wins']), (int)$bt['to_t'] > 0], [true, true], 'what the backtest said: N, wins, until when');

// ── קריטריון 4: נכס במפתח אישי של A — לא ביומן של B ──
eq(call('alice', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Alice1234567'])[0], 200, 'alice connects her own Twelve Data key');
$qa = ['asset' => 'twelvedata:AAPL', 'tf' => 'h1', 'from' => $NOW - 10 * 86400, 'to' => $NOW, 'methods' => 'test-cross-a'];
[$c, $ra] = call('alice', 'chart_refresh', null, $qa);
eq([$c, $ra['methods']['test-cross-a']['status'] ?? null], [200, 'ok'], 'alice analyses AAPL with her key');
$aliceScope = 'u:' . $uidOf('101');
$assets = fn(array $j) => array_values(array_unique(array_column($j['rows'], 'asset')));
// 21.3 (✅31): Twelve Data — מאגר משותף. רשומה חדשה נשמרת ב-'' וכולם רואים אותה
eq($db->query("SELECT cred_scope FROM method_journal WHERE asset = 'twelvedata:AAPL'")->fetchAll(PDO::FETCH_COLUMN), [''], '21.3: a new record of a Twelve Data asset is shared (cred_scope \'\')');
eq(in_array('twelvedata:AAPL', $assets($J('bob')), true), true, '21.3: bob sees it');
// רשומה ישנה ב-u:N (מלפני 21.3) — נשארת רק של בעליה (21.3 (2)): קריטריון 4 של 18.6 נבדק עליה
$db->exec("UPDATE method_journal SET cred_scope = '$aliceScope' WHERE asset = 'twelvedata:AAPL'");
$ja = $J('alice'); $jb = $J('bob'); $jo = $J('owner');
eq(in_array('twelvedata:AAPL', $assets($ja), true), true, 'criterion 4: alice sees it');
eq([in_array('twelvedata:AAPL', $assets($jb), true), in_array('binance:BTCUSDT', $assets($jb), true)], [false, true], 'criterion 4: bob does not — but he sees the shared ones');
eq(array_values(array_filter($jb['summary'], fn($x) => $x['method']['id'] === 'test-cross-a')), [], 'and the summary bob gets does not count it');
eq($J('bob', ['method' => 'test-cross-a', 'kind' => 'analysis'])['rows'], [], 'nor through a filter');
$oa = array_values(array_filter($jo['rows'], fn($x) => $x['asset'] === 'twelvedata:AAPL'));
eq([count($oa), $oa[0]['scope_kind'] ?? null, array_key_exists('scope_kind', $ja['rows'][0])], [1, 'personal', false], 'the admin sees the whole journal (marked personal); a user is not told scopes');
eq(array_key_exists('cred_scope', $oa[0]) || str_contains(json_encode($jo), $aliceScope), false, 'no cred_scope (user id) leaves the server');
eq([$oa[0]['label'] ?? null, isset($oa[0]['symbol'])], ['AAPL ⇄ USD', false] , 'a pair is shown by its label (18.1.1)');

// ── קריטריון 5: הסיכום לפי גרסה ──
$sum = array_column(array_map(fn($x) => $x + ['k' => $x['method']['id'] . '@' . $x['method']['version']], $jo['summary']), null, 'k');
$ew = $sum['elliott-w3@1'];
eq([$ew['total'], $ew['zone'] + $ew['invalidated'] + $ew['neither'] + $ew['pending'] + $ew['none'], $ew['decided'], $ew['pending']], [2, 2, 2, 0],
    'criterion 5: Elliott v1 — 2 scenarios, both decided');
eq($ew[$want[0]] >= 1, true, 'criterion 5: the decided outcome is counted under its kind');
eq([isset($sum['sample-ma-cross@1']), isset($sum['sample-ma-cross@2'])], [true, true], 'criterion 5: a line for each version');
eq(array_key_exists('elliott-w3@1', array_column(array_map(fn($x) => $x + ['k' => $x['method']['id'] . '@' . $x['method']['version']], $J('owner', ['outcome' => 'pending'])['summary']), null, 'k')), true, 'the summary covers all records, not only the filtered page');
$pend = $J('owner', ['outcome' => 'pending'])['rows'];
eq(array_unique(array_map(fn($x) => $x['outcome'], $pend)) === [null] || $pend === [], true, 'filter: only "not known yet"');
eq(call('owner', 'method_journal', null, ['outcome' => "x' OR 1=1 --", 'method' => "'; DROP TABLE method_journal; --"])[0], 200, 'odd filters are just filters');
eq($count('1 = 1') > 0, true, 'and the table is still there');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
