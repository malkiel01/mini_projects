<?php
// שכלול שיטה (spec-stage-a 18.7, S7): הצעת שינוי פרמטר — ביצועים בתוך הדגימה ומחוצה לה, בלי חלק מחוץ לדגימה אין
// הצעה, רק המנהל הראשי מחיל, ההחלה ב-admin_log ומעלה את הגרסה, ורשומות היומן שאחריה בגרסה החדשה. מיגרציה 12 — לא מוחקת.
// php trading-strategies/tests/tune-api.php — php -S עם שער מדומה. בלי רשת. הדפדפן — tests/tune-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-tune-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── טהור: החלוקה 70/30, בלי חפיפה ──
define('TS_DB_PATH', "$tmp.pure.sqlite");   // לא נפתח
require_once dirname(__DIR__) . '/lib/tune.php';
$cs = array_map(fn($i) => ['t' => $i * 60, 'o' => 1.0, 'h' => 1.0, 'l' => 1.0, 'c' => 1.0], range(0, 9));
[$in, $out, $splitT] = ts_tune_split($cs);
eq([count($in), count($out), $splitT, end($in)['t'] < $out[0]['t']], [7, 3, 420, true], 'split: the first 70% in the sample, the last 30% out, no overlap');
eq(ts_tune_split([])[2], null, 'split: no candles → no out-of-sample part');
eq(is_file("$tmp.pure.sqlite"), false, 'the pure part does not open a database');

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
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
function httpCalls(): int
{
    global $tmp;
    return count(array_filter(explode("\n", (string)@file_get_contents("$tmp.http.log"))));
}
foreach (['alice', 'carol', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$carol = (int)$db->query("SELECT id FROM users WHERE platform_sub = '103'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $carol])[0], 200, 'carol becomes an admin (not the primary one)');

// ── מיגרציה 12: עמודה וטבלה חדשות בלבד ──
eq((int)$db->query('PRAGMA user_version')->fetchColumn() >= 12, true, 'migration 12 ran');
eq(in_array('tune', array_column($db->query('PRAGMA table_info(methods)')->fetchAll(), 'name'), true), true, 'methods.tune exists');
eq((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'method_proposals'")->fetchColumn(), 1, 'method_proposals exists');

$S = 'sample-ma-cross';
$mset = ['id' => $S, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4', 'd1'], 'params' => [], 'note' => ''];
eq(call('owner', 'admin_method_save', $mset)[0], 200, 'the admin turns the sample method on');
$ver = fn() => (int)array_column(call('owner', 'admin_methods')[1]['methods'], 'version', 'id')[$S];
eq($ver(), 1, 'turning a method on does not change its version (no parameter changed)');
$logOf = fn(string $action) => array_values(array_filter(call('owner', 'admin_state')[1]['log'], fn($l) => $l['action'] === $action));

// ── הרשאות: משתמש רגיל — 403 ──
$prop = ['method' => $S, 'param' => 'fast', 'value' => 10, 'asset' => 'binance:BTCUSDT', 'tf' => 'h4', 'note' => 'ממוצע קצר מהיר יותר'];
eq([call('alice', 'admin_tune_list')[0], call('alice', 'admin_tune_propose', $prop)[0], call('alice', 'admin_tune_apply', ['id' => 1])[0]], [403, 403, 403],
    'a regular user: no list, no proposal, no apply');

// ── יומן לפני ההחלה — בגרסה 1 ──
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h4', 'from' => $NOW - 60 * 86400, 'to' => $NOW, 'methods' => $S];
eq(call('owner', 'chart_refresh', null, $q)[0], 200, 'a chart refresh with the sample method');
$jv = fn() => array_map('intval', $db->query("SELECT DISTINCT method_version FROM method_journal WHERE method_id = '$S' ORDER BY method_version")->fetchAll(PDO::FETCH_COLUMN));
eq($jv(), [1], 'the journal rows before any apply carry version 1');

// ── קריטריון 3: הצעה = ערך מועמד + ביצועים בתוך הדגימה ומחוצה לה, בנפרד ──
[$c, $r] = call('carol', 'admin_tune_propose', $prop);
eq($c, 200, 'an admin (not primary) proposes a candidate value');
$p1 = $r['proposal'] ?? [];
eq([$p1['method'] ?? null, $p1['param'] ?? null, $p1['current'] ?? null, $p1['candidate'] ?? null, $p1['status'] ?? null, $p1['method_version'] ?? null],
    [$S, 'fast', 20, 10, 'open', 1], 'the proposal: the current value, the candidate, open, at version 1');
$res = $p1['results'] ?? [];
eq(array_keys($res), ['split_t', 'current', 'candidate'], 'results: the split, the current value and the candidate');
eq([array_keys($res['current']), array_keys($res['candidate'])], [['in', 'out'], ['in', 'out']], 'criterion 3: each — in the sample and out of it, separately');
eq($res['current']['in']['to_t'] < $res['split_t'] && $res['current']['out']['from_t'] === $res['split_t'], true, 'no overlap: the sample ends before the out-of-sample part starts');
$bars = $res['current']['in']['bars'] + $res['current']['out']['bars'];
eq(abs($res['current']['out']['bars'] - 0.3 * $bars) <= 1, true, 'out of the sample — the last 30% of the range');
eq($res['current']['out']['n'] > 0 && $res['candidate']['out']['n'] > 0, true, 'there are trades out of the sample');
eq($res['current'] !== $res['candidate'], true, 'the candidate value gives a different result');
eq(count($logOf('method_propose')), 1, 'the proposal is in admin_log');
eq($ver(), 1, 'criterion 4: a proposal changes nothing — the version is still 1');
eq(array_column(call('owner', 'admin_methods')[1]['methods'], 'params', 'id')[$S], [], '… and the parameter is still the default');

// ── בלי עסקאות מחוץ לדגימה — אין הצעה (קריטריון 3). אליוט על הנרות המדומים (שן מסור) לא נותנת אות ──
[$c, $r] = call('carol', 'admin_tune_propose', ['method' => 'elliott-w3', 'param' => 'w3_mult', 'value' => 2, 'asset' => 'binance:BTCUSDT', 'tf' => 'h4']);
eq([$c, str_contains($r['error'] ?? '', 'מחוץ לדגימה')], [422, true], 'criterion 3: no trades out of the sample → no proposal');
eq(count(call('owner', 'admin_tune_list')[1]['proposals']), 1, '… and nothing was saved');

// ── קלט לא תקין ──
foreach ([
    'the same value' => ['value' => 20],
    'out of range' => ['value' => 1],
    'not an integer' => ['value' => 10.5],
    'not a number' => ['value' => 'abc'],
    'unknown parameter' => ['param' => 'nope'],
    'bad timeframe' => ['tf' => 'x9'],
    'a timeframe the method does not support' => ['method' => 'elliott-w3', 'param' => 'w3_mult', 'value' => 2, 'tf' => 'm5'],
] as $what => $bad) {
    $code = call('carol', 'admin_tune_propose', $bad + $prop)[0];
    eq($code === 422 || $code === 404, true, "refused: $what ($code)");
}

// ── קריטריון 5: מנהל שאינו הראשי — רואה, "החל" חסום; הראשי — פעיל ──
[$c, $r] = call('carol', 'admin_tune_list', null, ['method' => $S]);
eq([$c, $r['can_apply'], count($r['proposals']), isset($r['proposals'][0]['results']['candidate']['out'])], [200, false, 1, true], 'criterion 5: carol sees the proposal and its results, can_apply false');
eq(call('owner', 'admin_tune_list')[1]['can_apply'], true, 'criterion 5: the primary admin — can_apply true');
[$c, $r] = call('carol', 'admin_tune_apply', ['id' => $p1['id']]);
eq($c, 403, 'criterion 5: carol cannot apply (the server refuses too)');
eq($ver(), 1, '… and nothing changed');
eq(call('carol', 'admin_method_save', ['params' => ['fast' => 10]] + $mset)[0], 403, '✅24: carol cannot change a parameter directly either');
eq(call('carol', 'admin_method_save', ['note' => 'הערה של קרול'] + $mset)[0], 200, '… but can still save the note (no parameter changed)');
eq($ver(), 1, '… and that does not change the version');

// ── קריטריון 6 + 4: "החל" — הראשי. הגרסה עולה, הפרמטר משתנה, ב-admin_log עם שתי התוצאות ──
[$c, $r] = call('owner', 'admin_tune_apply', ['id' => $p1['id']]);
eq([$c, $r['proposal']['status'] ?? null, $r['proposal']['new_version'] ?? null], [200, 'applied', 2], 'the primary admin applies → applied, version 2');
$m = array_column(call('owner', 'admin_methods')[1]['methods'], null, 'id')[$S];
eq([$m['version'], $m['params'], $m['enabled'], $m['note']], [2, ['fast' => 10], true, 'הערה של קרול'], 'criterion 6: version 2, fast = 10; the other settings kept');
$tl = $logOf('method_tune');
eq(count($tl), 1, 'criterion 4: the apply is in admin_log');
eq(str_contains($tl[0]['target'], 'v1 → v2') && str_contains($tl[0]['target'], 'נוכחי: בתוך') && str_contains($tl[0]['target'], 'מועמד: בתוך') && mb_strlen($tl[0]['target']) < 300, true, '… with the versions and both results');
eq($tl[0]['display_name'], 'בעל המערכת', '… by the primary admin');
eq(call('owner', 'admin_tune_apply', ['id' => $p1['id']])[0], 422, 'applying twice → refused');
call('owner', 'chart_refresh', null, $q);
eq($jv(), [1, 2], 'criterion 6: journal rows after the apply carry the new version (2); the old ones stay at 1');
eq((int)$db->query("SELECT COUNT(*) FROM method_journal WHERE method_id = '$S' AND method_version = 1")->fetchColumn() > 0, true, 'the old journal rows were not deleted');

// ── הצעה ישנה: הערך או הגרסה זזו מאז — לא מוחלת ──
[, $r] = call('carol', 'admin_tune_propose', ['param' => 'slow', 'value' => 60] + $prop);
$p2 = $r['proposal'];
eq([$p2['method_version'], $p2['current'], $p2['status']], [2, 50, 'open'], 'a new proposal at version 2');
eq(call('owner', 'admin_method_save', ['params' => ['fast' => 10, 'slow' => 55]] + $mset)[0], 200, 'the primary admin changes a parameter directly');
eq($ver(), 3, '… which raises the version too (a parameter changed)');
eq(str_contains($logOf('method_save')[0]['target'], 'v2 → v3'), true, '… and is logged with the versions');
eq(call('owner', 'admin_tune_list')[1]['proposals'][0]['status'], 'stale', 'the proposal is now stale');
[$c, $r] = call('owner', 'admin_tune_apply', ['id' => $p2['id']]);
eq([$c, str_contains($r['error'] ?? '', 'ישנה')], [422, true], 'a stale proposal is not applied');
eq(array_column(call('owner', 'admin_methods')[1]['methods'], 'params', 'id')[$S], ['fast' => 10, 'slow' => 55], '… and the parameter stays');

// ── קריטריון 4: כל שינוי פרמטר — מפעולה של מנהל ב-admin_log ──
$changes = array_merge($logOf('method_tune'), array_filter($logOf('method_save'), fn($l) => str_contains($l['target'], ' → v')));
eq(count($changes), $ver() - 1, 'criterion 4: every version step has an admin action in admin_log');
eq((int)$db->query('SELECT COUNT(*) FROM method_proposals')->fetchColumn(), 2, 'proposals are never deleted (applied + stale)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
