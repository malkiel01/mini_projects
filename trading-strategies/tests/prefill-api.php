<?php
// מילוי איטי של המאגר ברקע (spec-stage-a 21.2, ✅30): שלב ב-cron אחרי ההתראות. הסדר (מועדפים, נפתחו לאחרונה, השאר),
// חצי מהתקרה לדקה של הספק בכל ריצה ו-25% מהיומית לכל מפתח, Twelve Data רק במפתח של המשתמש ולו בלבד (ש10), כיבוי
// בהגדרות, "לפני N ימים" בשורה, ו-429 → לסוף התור בלי ניסיון חוזר. מיגרציה 15 — עמודה וטבלה בלבד.
// php trading-strategies/tests/prefill-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-prefill-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
// fixtures/prefill — הספקים המדומים הרגילים, ו-MSFT ב-Twelve Data עונה 429 (קריטריון 7)
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/prefill', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log"],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;   // 23:30 UTC
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
/** ריצה של ה-cron: [קוד יציאה, הסיכום, stderr, שניות] */
function cron(int $now, array $budget = []): array
{
    global $root, $env;
    $args = array_map(fn($p, $v) => "--budget=$p:$v", array_keys($budget), $budget);
    $t = microtime(true);
    $p = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', "$root/trading-strategies/bin/alerts-cron.php", "--now=$now", ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    return [$code, json_decode(trim($out), true), $err, microtime(true) - $t];
}
/** הבקשות לספק מהיומן של הספקים המדומים, מאז שורה $from: [url, key] */
function http(int $from = 0): array
{
    global $tmp;
    $out = [];
    foreach (array_slice(array_filter(explode("\n", (string)@file_get_contents("$tmp.http.log"))), $from) as $l) {
        $j = json_decode($l, true);
        $key = '';
        foreach ($j['headers'] as $hh) if (stripos($hh, 'Authorization: apikey ') === 0) $key = substr($hh, 22);
        $out[] = [$j['url'], $key];
    }
    return $out;
}
$httpN = fn() => count(array_filter(explode("\n", (string)@file_get_contents("$tmp.http.log"))));
$rowsOf = function (string $as, string $tab, ?int $now = null) {
    [$c, $r] = call($as, 'market_tab', null, ['id' => $tab], $now);
    return $c === 200 ? array_column($r['rows'], null, 'asset') : [];
};
$only = fn(array $done, string $p) => array_values(array_filter($done, fn($a) => str_starts_with($a, "$p:")));

foreach (['alice', 'bob', 'carol', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$uid = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
[$alice, $bob, $carol] = [$uid('101'), $uid('102'), $uid('103')];

// ── מיגרציה 15 ──
eq((int)$db->query('PRAGMA user_version')->fetchColumn() >= 15, true, 'migration 15 ran');
eq(in_array('prefill_off', array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name'), true), true, 'users.prefill_off exists');
eq((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'prefill_state'")->fetchColumn(), 1, 'prefill_state exists');

// ── קריטריון 6: נכס שמולא לפני 4 ימים → "לפני 4 ימים"; שלא מולא → "—" ──
// ריצה לפני 4 ימים, עם מקום לבקשה אחת ב-Binance (חצי מ-2) ובלי ECB: עוד אין מועדפים ופתיחות — הראשון בקטלוג
[$code, $s] = cron($NOW - 4 * 86400, ['binance' => 2, 'ecb' => 0]);
eq([$code, $s['prefill']['done'] ?? null], [0, ['binance:BTCUSDT']], 'criterion 6: a run 4 days ago fills one asset — the first in the catalog');
$cr = $rowsOf('alice', 'crypto');
eq([$cr['binance:BTCUSDT']['price'] !== null, $cr['binance:BTCUSDT']['age_days']], [true, 4], 'criterion 6: filled 4 days ago → the row says 4 days ("לפני 4 ימים")');
eq([$cr['binance:ETHUSDT']['price'], $cr['binance:ETHUSDT']['age_days']], [null, null], 'criterion 6: not filled → "—"');
eq($rowsOf('alice', 'crypto', $NOW - 4 * 86400 + 600)['binance:BTCUSDT']['age_days'], null, 'a candle of today → no "N days ago"');

// ── קריטריון 1: הסדר — מועדפים (לפי מספר המשתמשים), אחר כך נפתחו לאחרונה, אחר כך השאר ──
foreach ([['alice', 'binance:SOLUSDT'], ['alice', 'binance:ETHUSDT'], ['bob', 'binance:ETHUSDT']] as [$u, $a]) eq(call($u, 'watchlist_add', ['asset' => $a])[0], 200, "$u: ⭐ $a");
[$c] = call('carol', 'market_candles', null, ['asset' => 'binance:DOGEUSDT', 'tf' => 'h1', 'from' => $NOW - 86400, 'to' => $NOW]);
eq($c, 200, 'carol opens a DOGE chart (recently opened)');
[$code, $s] = cron($NOW, ['binance' => 6, 'ecb' => 0]);
eq([$code, $s['prefill']['done']], [0, ['binance:ETHUSDT', 'binance:SOLUSDT', 'binance:DOGEUSDT']],
    'criterion 1: favorites by number of users (ETH 2, SOL 1), then recently opened (DOGE)');
[$code, $s] = cron($NOW + 900, ['binance' => 40, 'ecb' => 0]);
eq(array_slice($s['prefill']['done'], 0, 6), ['binance:ETHUSDT', 'binance:SOLUSDT', 'binance:DOGEUSDT', 'binance:BTCUSDT', 'binance:BNBUSDT', 'binance:XRPUSDT'],
    'criterion 1: … then the rest, in catalog order (Binance — every run)');

// ── קריטריון 2: התקרה של משתמש לא נפגעת — המילוי עד חצי מהתקרה לדקה של הספק ──
eq([$s['prefill']['calls']['binance'], $s['prefill']['filled'], $s['prefill']['deferred'] > 0], [20, 20, true], 'criterion 2: budget 40 → the prefill took 20 (half), and left the rest for the next run');
[$code, $s] = cron($NOW + 1800);
eq($s['prefill']['calls']['binance'] <= 300, true, 'criterion 2: the real Binance limit (600/min) → at most 300 in a run');
[$c, $r] = call('alice', 'market_candles', null, ['asset' => 'binance:ETHUSDT', 'tf' => 'h1', 'from' => $NOW - 86400, 'to' => $NOW + 1800], $NOW + 1800);
eq([$c, count($r['candles']) > 0], [200, true], 'criterion 2: right after the prefill a user opens a chart → it answers');
$ecb = $only($s['prefill']['done'], 'ecb');
eq([count($ecb) > 0, $s['prefill']['calls']['ecb'] <= 30], [true, true], 'ECB is filled too, shared, within half of its limit (60/min)');
[, $s2] = cron($NOW + 2700);
eq($only($s2['prefill']['done'], 'ecb'), [], 'ECB — once a day: the next run does not refill it');
[, $s3] = cron($NOW + 86400 + 1800, ['binance' => 0]);
eq(count($only($s3['prefill']['done'], 'ecb')), count($ecb), '… and the next day it does');
$users = (int)$db->query("SELECT COUNT(*) FROM prefill_state WHERE cred_scope != ''")->fetchColumn();
eq($users, 0, 'Binance and ECB are filled once for everyone (cred_scope "")');

// ── Twelve Data: רק במפתח של המשתמש (ש10) ──
// התקרה היומית של הספק — 8 (דריסה של המנהל), כדי לבדוק את 25% (2 ליום) בלי מאות בקשות
$db->exec("INSERT INTO providers (id, enabled, overrides) VALUES ('twelvedata', 1, '{\"per_day\":8}')
           ON CONFLICT(id) DO UPDATE SET overrides = excluded.overrides");
eq(call('alice', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Alice1234567'])[0], 200, 'alice connects her own Twelve Data key');
eq(call('bob', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Bob123456789'])[0], 200, 'bob connects his own Twelve Data key');
eq(call('alice', 'watchlist_add', ['asset' => 'twelvedata:NVDA'])[0], 200, 'alice: ⭐ NVDA');
// הלשוניות שלה: מועדפים ונאסד"ק 100 בלבד — כך שהסדר של "השאר" ידוע (עם מפתח, גם מט"ח נפתר אצלה ל-Twelve Data)
eq(call('alice', 'market_tabs_save', ['tabs' => [['id' => 'favorites', 'kind' => 'favorites'], ['id' => 'nasdaq100', 'kind' => 'builtin']]])[0], 200, 'alice keeps only ⭐ and Nasdaq 100');
[, $pv] = call('alice', 'market_providers');
eq(array_column($pv['providers'], null, 'id')['twelvedata']['own']['prefill'], true, 'settings: "מילוי ברקע במפתח שלי — פעיל" by default');
eq(array_column($pv['providers'], null, 'id')['binance']['own'], null, 'no switch for a provider without a key');

// ── קריטריון 5: כיבוי בהגדרות → לא משתמש במפתח שלו ──
[$c, $r] = call('bob', 'prefill_set', ['on' => false]);
eq([$c, array_column($r['providers'], null, 'id')['twelvedata']['own']['prefill']], [200, false], 'criterion 5: bob turns the prefill off');
eq(call('bob', 'prefill_set', ['on' => 'no'])[0], 422, 'prefill_set: a non-boolean → 422');

// ── קריטריון 3: 25% ליום לכל מפתח — נאכף; התור נעצר וממשיך למחרת ──
$h0 = $httpN();
[$code, $s] = cron($NOW + 2 * 86400, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
$td = $only($s['prefill']['done'], 'twelvedata');
eq([$code, $td], [0, ['twelvedata:NVDA', 'twelvedata:AAPL']], 'criterion 3: 25% of 8 → 2 a day for alice — her favorite first, then her tabs');
eq(in_array('twelvedata|personal', $s['prefill']['day_capped'], true), true, 'criterion 3: the queue stops at the daily share');
eq($s['prefill']['calls']['twelvedata|personal'], 2, '… and only 2 calls were made with her key');
[, $s] = cron($NOW + 2 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
eq($only($s['prefill']['done'], 'twelvedata'), [], 'criterion 3: later the same day — nothing more');
// ── קריטריון 7: 429 → הנכס לסוף התור, בלי ניסיון חוזר באותה ריצה, וה-cron מסתיים בזמן ──
$h1 = $httpN();
[$code, $s, $err, $sec] = cron($NOW + 3 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
$msft = array_filter(http($h1), fn($x) => str_contains($x[0], 'time_series?symbol=MSFT&'));
eq([$code, $only($s['prefill']['done'], 'twelvedata'), $s['prefill']['errors'], count($msft)], [0, [], 1, 1],
    'criterion 7: the next day MSFT answers 429 — one request, no retry, and no more requests with that key in this run');
eq([$s['prefill']['calls']['twelvedata|personal'], $sec < 240, $s['stopped']], [1, true, false], 'criterion 7: the cron ends in time');
eq((int)$db->query("SELECT failed_at > filled_at FROM prefill_state WHERE symbol = 'MSFT'")->fetchColumn(), 1, 'MSFT is marked as failed');
[, $s] = cron($NOW + 4 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
eq($only($s['prefill']['done'], 'twelvedata'), ['twelvedata:AMZN', 'twelvedata:META'], 'criterion 7 + 3: the next run goes on — MSFT moved to the end of the queue');
eq(array_filter(http($h0), fn($x) => $x[1] === 'good-Bob123456789'), [], 'criterion 5: no request with bob\'s key (he turned it off)');
eq((int)$db->query("SELECT COUNT(*) FROM prefill_state WHERE cred_scope = 'u:$bob'")->fetchColumn(), 0, 'criterion 5: nothing filled in bob\'s scope');

// ── קריטריון 4: רישוי — מה שמולא במפתח של alice, bob לא רואה ──
$na = $rowsOf('alice', 'nasdaq100');
eq($na['twelvedata:AAPL']['price'] !== null, true, 'alice sees the AAPL price her key filled');
$nb = $rowsOf('bob', 'nasdaq100');
eq([$nb['twelvedata:AAPL']['price'], $nb['twelvedata:AAPL']['locked']], [null, false], 'criterion 4: bob (his own key, prefill off) does not see it — "—"');
$nc = $rowsOf('owner', 'nasdaq100');
eq([$nc['twelvedata:AAPL']['price'], $nc['twelvedata:AAPL']['locked']], [null, true], 'criterion 4: a user without a key — locked, no price');
eq((int)$db->query("SELECT COUNT(*) FROM prefill_state WHERE provider = 'twelvedata' AND cred_scope != 'u:$alice'")->fetchColumn(), 0, 'Twelve Data is saved only with cred_scope = u:<alice>');
eq(call('bob', 'prefill_set', ['on' => true])[0], 200, 'bob turns it back on');

eq(trim((string)@file_get_contents("$tmp.error.log")) === '' || !str_contains((string)file_get_contents("$tmp.error.log"), 'PHP '), true, 'no PHP error in the server log');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
