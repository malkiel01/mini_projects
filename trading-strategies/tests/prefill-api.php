<?php
// מילוי איטי של המאגר ברקע (spec-stage-a 21.2, ✅30): שלב ב-cron אחרי ההתראות. הסדר (מועדפים, נפתחו לאחרונה, השאר),
// חצי מהתקרה לדקה של הספק בכל ריצה ו-25% מהיומית לכל מפתח, "לפני N ימים" בשורה, ו-429 → לסוף התור בלי ניסיון חוזר.
// 21.3 (✅31): Twelve Data — רק במפתח האפליקציה, למאגר המשותף; בלי מפתח אפליקציה — לא ממולא; אין מתג (prefill_set → 404).
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

// ── Twelve Data — מאגר משותף, רק במפתח האפליקציה (21.3, ✅31) ──
// התקרה היומית של הספק — 8 (דריסה של המנהל), כדי לבדוק את 25% (2 ליום) בלי מאות בקשות
$db->exec("INSERT INTO providers (id, enabled, overrides) VALUES ('twelvedata', 1, '{\"per_day\":8}')
           ON CONFLICT(id) DO UPDATE SET overrides = excluded.overrides");
eq(call('alice', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Alice1234567'])[0], 200, 'alice connects her own Twelve Data key');
eq(call('bob', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Bob123456789'])[0], 200, 'bob connects his own Twelve Data key');
eq(call('alice', 'watchlist_add', ['asset' => 'twelvedata:NVDA'])[0], 200, 'alice: ⭐ NVDA');

// ── קריטריון 8 (21.3): אין מתג מילוי; prefill_set → 404; העמודה נשארת ──
[, $pv] = call('alice', 'market_providers');
eq(array_key_exists('prefill', array_column($pv['providers'], null, 'id')['twelvedata']['own']), false, '21.3 criterion 8: no prefill switch in the settings');
eq(call('bob', 'prefill_set', ['on' => false])[0], 404, '21.3 criterion 8: prefill_set → 404');
eq(in_array('prefill_off', array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name'), true), true, '21.3 criterion 8: users.prefill_off is still there');

// ── קריטריון 7 (21.3): בלי מפתח אפליקציה — 0 פניות ל-Twelve Data ברקע, גם כשלמשתמשים יש מפתח ──
$h0 = $httpN();
[$code, $s] = cron($NOW + 2 * 86400, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
eq([$code, $only($s['prefill']['done'], 'twelvedata'), array_filter(http($h0), fn($x) => str_contains($x[0], 'twelvedata'))], [0, [], []],
    '21.3 criterion 7: no app key → no Twelve Data request in the background (alice and bob have keys — not used)');

// ── מפתח האפליקציה: המנהל הראשי מסמן ──
[, $r] = call('owner', 'admin_cred_save', ['provider' => 'twelvedata', 'secret' => 'good-AppKey123456', 'label' => 'אפליקציה']);
$appId = end($r['creds'])['id'];
eq(call('owner', 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => $appId])[0], 200, 'the primary admin marks the app key');

// ── קריטריון 3 (21.2) + 7 (21.3): 25% ליום ממפתח האפליקציה — נאכף; התור נעצר וממשיך למחרת ──
$h0 = $httpN();
[$code, $s] = cron($NOW + 2 * 86400 + 600, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
$td = $only($s['prefill']['done'], 'twelvedata');
eq([$code, count($td), $td[0] ?? null], [0, 2, 'twelvedata:NVDA'], 'criterion 3: 25% of 8 → 2 a day with the app key — a favorite first');
$first = $td[1] ?? '';
eq(in_array('twelvedata', $s['prefill']['day_capped'], true), true, 'criterion 3: the queue stops at the daily share');
eq($s['prefill']['calls']['twelvedata'] ?? null, 2, '… and only 2 calls were made');
$keysUsed = array_values(array_unique(array_column(array_filter(http($h0), fn($x) => str_contains($x[0], 'twelvedata')), 1)));
eq($keysUsed, ['good-AppKey123456'], '21.3 criterion 7: the background uses the app key only — never a user\'s key');
eq($db->query("SELECT DISTINCT cred_scope FROM prefill_state WHERE provider = 'twelvedata'")->fetchAll(PDO::FETCH_COLUMN), [''], '21.3 criterion 7: saved to the shared pool (\'\')');
[, $s] = cron($NOW + 2 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
eq($only($s['prefill']['done'], 'twelvedata'), [], 'criterion 3: later the same day — nothing more');

// ── קריטריון 7 (21.2): 429 → הנכס לסוף התור, בלי ניסיון חוזר באותה ריצה, וה-cron מסתיים בזמן ──
// MSFT ראשון בתור: מועדף של bob
eq(call('bob', 'watchlist_add', ['asset' => 'twelvedata:MSFT'])[0], 200, 'bob: ⭐ MSFT');
$h1 = $httpN();
[$code, $s, $err, $sec] = cron($NOW + 3 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
$msft = array_filter(http($h1), fn($x) => str_contains($x[0], 'time_series?symbol=MSFT&'));
eq([$code, $only($s['prefill']['done'], 'twelvedata'), $s['prefill']['errors'], count($msft)], [0, [], 1, 1],
    'criterion 7: MSFT answers 429 — one request, no retry, and no more requests with that key in this run');
eq([$s['prefill']['calls']['twelvedata'], $sec < 240, $s['stopped']], [1, true, false], 'criterion 7: the cron ends in time');
eq((int)$db->query("SELECT failed_at > filled_at FROM prefill_state WHERE symbol = 'MSFT'")->fetchColumn(), 1, 'MSFT is marked as failed');
[, $s] = cron($NOW + 3 * 86400 + 1200, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
$next = $only($s['prefill']['done'], 'twelvedata');
eq([$s['prefill']['errors'], count($next)], [0, 1], 'the same day, 5 minutes later (23:50): the next asset — the 429 took 1 of the 2 for today');
[, $s] = cron($NOW + 4 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
eq(count($only($s['prefill']['done'], 'twelvedata')), 2, 'criterion 7 + 3: the next day it goes on');
eq(in_array('twelvedata:MSFT', $only($s['prefill']['done'], 'twelvedata'), true), false, '… MSFT moved to the end of the queue');
eq(count(array_filter(http($h1), fn($x) => str_contains($x[0], 'time_series?symbol=MSFT&'))), 1, '⚪ a failed asset is not asked again within the hour (still one MSFT request)');
eq(array_filter(http($h0), fn($x) => in_array($x[1], ['good-Alice1234567', 'good-Bob123456789'], true)), [], '21.3 criterion 7: no background request with a user\'s key');

// ── 21.3: מה שמולא — לכולם (המאגר המשותף) ──
$nb = $rowsOf('bob', 'nasdaq100');
$nc = $rowsOf('owner', 'nasdaq100');
eq([$nb[$first]['price'] !== null, $nc[$first]['price'] !== null, $nc[$first]['locked']], [true, true, false], '21.3: what the app key filled — bob and the owner both see the price');
eq($rowsOf('owner', 'nasdaq100')['twelvedata:NVDA']['locked'] ?? null, false, 'with an app key nothing is locked');
eq(call('owner', 'market_tab', null, ['id' => 'nasdaq100'])[1]['needs'], null, 'with an app key — no ש7 line');
// בלי מפתח אפליקציה ובלי מפתח — מה שבמאגר מוצג; מה שלא — אפור, ושורת ש7 (21.3 קריטריון 5)
eq(call('owner', 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => null])[0], 200, 'the primary admin unmarks the app key');
$nc = call('owner', 'market_tab', null, ['id' => 'nasdaq100'])[1];
$ncr = array_column($nc['rows'], null, 'asset');
$grey = array_values(array_filter($nc['rows'], fn($x) => $x['price'] === null));
eq([$ncr[$first]['price'] !== null, $ncr[$first]['locked'], $grey[0]['locked'] ?? null, $nc['needs']['provider'] ?? null], [true, false, true, 'twelvedata'],
    '21.3 criterion 5: no key at all — an asset with data in the pool is shown; one without — locked, and the ש7 line');
eq(call('owner', 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => $appId])[0], 200, '… and marks it again');

// ── ביקורת 21-2 🟡1: חצי לדקה, בניכוי מה שההתראות כבר לקחו מאותו מפתח (עכשיו — מפתח האפליקציה) ──
$db->exec("UPDATE providers SET overrides = '{\"per_day\":800}' WHERE id = 'twelvedata'");   // בלי תקרת 25% שמסתירה
$ins = $db->prepare("INSERT INTO alerts (user_id, asset, tf, side, kind, method_id, methods, level, status, expires_at, created_at) VALUES (?, ?, 'h1', 'both', 'price', NULL, '[]', '100', 'active', ?, ?)");
foreach (['ADBE', 'AMD', 'CSCO', 'PEP', 'INTU', 'QCOM'] as $sym) $ins->execute([$carol, "twelvedata:$sym", $NOW + 30 * 86400, $NOW]);
[$code, $s] = cron($NOW + 5 * 86400 + 900, ['binance' => 0, 'ecb' => 0, 'twelvedata' => 8]);
eq([$code, $s['calls']['twelvedata'] ?? null, $s['prefill']['calls']['twelvedata'] ?? 0], [0, 8, 2],
    '🟡1: 6 alert groups through the app key → the prefill takes only 2 (8 − 6), not 4 — the key stays within 8 a minute');
$db->exec('DELETE FROM alerts');

// ── ביקורת 21-2 🟡2: המילוי לא נרשם ביומן בקשות הנרות ──
$fl = new PDO("sqlite:$tmp.sqlite.cache", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
eq((int)$fl->query('SELECT COUNT(*) FROM fetch_log WHERE user_id = 0')->fetchColumn(), 0, '🟡2: no fetch_log rows from the prefill (it would flood the 2,000 rows)');
eq((int)$fl->query("SELECT COUNT(*) FROM fetch_log WHERE user_id = $carol AND symbol = 'DOGEUSDT'")->fetchColumn(), 1, '🟡2: carol\'s chart opening is still there (recently opened)');

// ── ביקורת 21-2 🔴1: מפתח פגום לא עוצר את המשך ה-cron (עכשיו — מפתח אפליקציה שלא מתפענח) ──
$db->prepare("INSERT INTO provider_creds (owner, user_id, provider_id, secret, last4, created_at) VALUES ('admin', NULL, 'twelvedata', 'AAAAnot-a-box', 'xbox', ?)")->execute([$NOW]);
$db->exec("UPDATE settings SET v = '" . $db->lastInsertId() . "' WHERE k = 'app_key:twelvedata'");
[$code, $s, $err] = cron($NOW + 6 * 86400 + 900, ['binance' => 2, 'ecb' => 0, 'twelvedata' => 8]);
eq([$code, $s['prefill']['key_errors'] ?? null, count($only($s['prefill']['done'], 'twelvedata')), count($only($s['prefill']['done'], 'binance'))], [0, 0, 0, 1],
    '🔴1: the app key cannot be decrypted — it is skipped (as if there were none), and Binance is still filled');
$last = json_decode((string)$db->query("SELECT v FROM settings WHERE k = 'alerts_cron'")->fetchColumn(), true);
eq([(int)$last['at'], $s['cleanup']], [$NOW + 6 * 86400 + 900, true], '🔴1: the run still saved its state and ran the daily cleanup (✅4)');

// אזהרות הפעלה של PHP עצמו (למשל "JIT … disabled. in Unknown on line 0" ב-runner של הפריסה, #1629) — לא של הקוד
$phpErr = array_values(array_filter(explode("\n", (string)@file_get_contents("$tmp.error.log")),
    fn($l) => preg_match('/PHP (Fatal error|Parse error|Warning|Notice|Deprecated)/', $l) && !str_contains($l, ' in Unknown on line 0')));
eq($phpErr, [], 'no PHP error in the server log');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
