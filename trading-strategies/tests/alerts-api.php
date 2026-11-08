<?php
// בדיקות ההתראות (spec-stage-a פרק 8; פרק 15: cron, בידוד, תקרות). php -S עם שער מדומה, ספקים ושירות פוש מדומים,
// וה-cron כ-CLI עם זמן מדומה (--now). בלי רשת.  php trading-strategies/tests/alerts-api.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-alerts-api-' . getmypid();
$port = 22000 + getmypid() % 1000;
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log"],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
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
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
/** ה-cron, כמו ב-cPanel: CLI. בבדיקות — עם השער המדומה (רק בשביל TS_DEV והנתיבים) וזמן מדומה */
function cron(int $now, array $args = []): array
{
    global $root, $env;
    $p = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', "$root/trading-strategies/bin/alerts-cron.php", "--now=$now", ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    if ($err !== '') echo "  (cron stderr) $err\n";
    return [$code, json_decode(trim($out), true)];
}
foreach (['alice', 'bob', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$db->exec('PRAGMA busy_timeout = 5000');
$uidOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
$A = 'binance:BTCUSDT';
$H0 = intdiv(time(), 3600 * 50) * 50 * 3600 + 50 * 3600;   // תחילת "שן" של המסור במחיר המדומה (100 + שעה % 50)
$at = fn(int $hour) => $H0 + $hour * 3600 + 1800;           // באמצע שעה $hour — הסגירה של הנר הפתוח = 100 + hour + 0.5
$httpPosts = fn() => array_values(array_filter(array_map(fn($l) => json_decode($l, true), file("$tmp.http.log") ?: []), fn($r) => ($r['method'] ?? '') === 'POST'));

// ── הפעלת שיטות (מנהל) ───────────────────────────────────
$all = ['crypto', 'stocks'];
foreach (['sample-ma-cross', 'test-cross-a', 'test-cross-b'] as $m) {
    call('owner', 'admin_method_save', ['id' => $m, 'enabled' => true, 'markets' => $all, 'timeframes' => ['h1', 'h4']]);
}
$ms = call('alice', 'alerts')[1]['methods'];
eq(array_column($ms, 'id'), ['test-cross-a', 'test-cross-b'], 'methods allowed in alerts: enabled, not the sample method');
eq(call('owner', 'alert_save', ['asset' => $A, 'tf' => 'h1', 'side' => 'long', 'kind' => 'method', 'method_id' => 'sample-ma-cross'])[0], 422,
    'the sample method cannot be used in an alert — not even by an admin');

// ── יצירה, ולידציה ───────────────────────────────────────
$price = ['asset' => $A, 'tf' => 'h1', 'side' => 'long', 'kind' => 'price', 'level' => '115'];
[$c, $r] = call('alice', 'alert_save', $price);
eq([$c, $r['alert']['status'], $r['alert']['level'], $r['alert']['last_state']], [200, 'active', '115', null], 'alice creates a price alert');
$pid = $r['alert']['id'];
eq(abs($r['alert']['expires_at'] - time() - 30 * 86400) < 60, true, 'default validity — 30 days');
foreach ([['side' => 'up'], ['level' => 'abc'], ['level' => '-5'], ['level' => 1.5], ['tf' => 'h2'], ['asset' => 'nope:X'], ['asset' => 'twelvedata:AAPL'],
    ['kind' => 'blend', 'methods' => ['test-cross-a']], ['kind' => 'method', 'method_id' => 'nope'], ['days' => 91]] as $bad) {
    eq(call('alice', 'alert_save', $bad + $price)[0], 422, 'alert rejects ' . json_encode($bad));
}

// ── בידוד ────────────────────────────────────────────────
eq(call('bob', 'alerts')[1]['alerts'], [], 'bob does not see alice\'s alerts');
eq(call('bob', 'alert_status', ['id' => $pid, 'status' => 'paused'])[0], 404, 'bob cannot pause alice\'s alert by id');
eq(call('bob', 'alert_delete', ['id' => $pid])[0], 404, 'bob cannot delete alice\'s alert by id');
eq(call('alice', 'alerts')[1]['alerts'][0]['status'], 'active', 'alice\'s alert untouched');

// ── cron: בלי כפילות ─────────────────────────────────────
[$code, $s] = cron($at(10));   // 110.5 — מתחת ל-115
eq([$code, $s['locked'], $s['checked'], $s['fired']], [0, false, 1, 0], 'first run: the alert is checked, only sets its starting point');
eq($db->query("SELECT last_state FROM alerts WHERE id = $pid")->fetchColumn(), 'below', 'starting point: below the level');
[, $s] = cron($at(20));        // 120.5 — חצה מעלה
eq($s['fired'], 1, 'price crossed up → fired');
[, $s] = cron($at(20) + 900);
eq($s['fired'], 0, 'two runs in a row → no duplicate');
[, $s] = cron($at(30));
eq($s['fired'], 0, 'still above → nothing');
cron($at(51));                 // 101.5 — חזר מתחת (התנאי התבטל)
[, $s] = cron($at(66));        // 116.5 — חצה שוב
eq($s['fired'], 1, 'condition reset and met again → fired again');
$inbox = call('alice', 'inbox')[1];
eq([count($inbox['events']), $inbox['unread']], [2, 2], 'two messages in alice\'s inbox');
$ev = $inbox['events'][0]['payload'];
eq([str_contains($ev['title'], 'BTCUSDT'), str_contains($ev['body'], 'אינו ייעוץ השקעות'), $ev['link']], [true, true, '#/analysis/binance%3ABTCUSDT'],
    'the message: asset, the short disclaimer, a link to the analysis card');
eq(preg_match('/קנה|מכור|המלצ|כדאי/u', json_encode($inbox, JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend" wording in alerts');
eq(call('bob', 'inbox')[1]['events'], [], 'bob\'s inbox is empty');
eq(call('alice', 'me')[1]['unread'], 2, 'unread count for the tab');
call('alice', 'inbox_read', ['ids' => 'all']);
eq(call('alice', 'inbox')[1]['unread'], 0, 'mark all as read');

// ── פוש: VAPID, בלי תוכן, מנוי שנעלם נמחק, שעות שקטות ────
eq(call('alice', 'push_subscribe', ['endpoint' => 'https://evil.example/x', 'keys' => []])[0], 422, 'push endpoint must be a known push service (no SSRF)');
eq(call('alice', 'push_subscribe', ['endpoint' => 'http://push.test/x'])[0], 422, 'push endpoint must be HTTPS');
call('alice', 'push_subscribe', ['endpoint' => 'https://push.test/alice-1', 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
call('alice', 'push_subscribe', ['endpoint' => 'https://push.test/gone/alice-2', 'keys' => []]);
$prof = call('alice', 'profile')[1]['profile'];
$prof['alert_channels'] = ['inbox', 'push'];
eq(call('alice', 'profile_save', $prof)[0], 200, 'alice turns push on');
cron($at(91));                  // 141.5 — מעל (בלי שינוי)
cron($at(101));                 // 101.5 — מתחת
$before = count($httpPosts());
[, $s] = cron($at(116));        // 116.5 — מעל
eq($s['fired'], 1, 'fired with push on');
$posts = array_slice($httpPosts(), $before);
eq(count($posts), 2, 'one push per device');
$auth = '';
foreach ($posts[0]['headers'] as $hd) if (str_starts_with($hd, 'Authorization: vapid ')) $auth = $hd;
preg_match('/t=([^,]+), k=(\S+)/', $auth, $mm);
[$jh, $jc, $js] = explode('.', $mm[1] ?? '..');
$b64 = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$claims = json_decode($b64($jc), true);
eq([$claims['aud'] ?? null, ($claims['exp'] ?? 0) > $at(116)], ['https://push.test', true], 'VAPID JWT: audience is the push service origin, not expired');
$pub = $b64($mm[2] ?? '');
$der = fn($raw) => (function ($r, $s) { $i = fn($x) => (ord(ltrim($x, "\0")[0] ?? "\0") & 0x80 ? "\0" : '') . ltrim($x, "\0");
    $r = $i($r); $s = $i($s); $body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s; return "\x30" . chr(strlen($body)) . $body; })(substr($raw, 0, 32), substr($raw, 32));
$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pub), 64) . "-----END PUBLIC KEY-----\n";
eq(openssl_verify("$jh.$jc", $der($b64($js)), $pem, OPENSSL_ALGO_SHA256), 1, 'VAPID JWT signature verifies with the public key the browser gets (push_key)');
eq($mm[2] ?? null, call('alice', 'push_key')[1]['key'], 'the key in the header = push_key');
eq(in_array('Content-Length: 0', $posts[0]['headers'], true), true, 'push carries no content (the SW pulls the message from the inbox)');
eq((int)$db->query("SELECT COUNT(*) FROM push_subs WHERE endpoint LIKE '%gone%'")->fetchColumn(), 0, 'a subscription the push service says is gone (410) is removed');
$peek = call('alice', 'inbox_peek')[1];
eq(str_contains($peek['event']['payload']['title'] ?? '', 'BTCUSDT'), true, 'inbox_peek gives the SW the latest unread message');

$prof['quiet_hours'] = ['from' => '00:00', 'to' => '23:59'];
call('alice', 'profile_save', $prof);
cron($at(151));                 // 101.5 — מתחת
$before = count($httpPosts());
[, $s] = cron($at(166));        // 116.5 — מעל
eq([$s['fired'], count($httpPosts()) - $before], [1, 0], 'quiet hours: delivered to the inbox, no push');
eq(call('alice', 'inbox')[1]['events'][0]['delivered']['push'] ?? null, 'quiet', 'marked as quiet');

// ── מקסימום ליום ─────────────────────────────────────────
$prof['quiet_hours'] = null; $prof['max_alerts_per_day'] = 1;
call('alice', 'profile_save', $prof);
$db->exec('DELETE FROM alert_events');
$second = call('alice', 'alert_save', ['level' => '112'] + $price)[1]['alert']['id'];   // שתיים שמתקיימות באותה ריצה
cron($at(201)); [, $s1] = cron($at(216));
eq([$s1['fired'], $s1['capped']], [1, 1], 'max per day (profile = 1): two met in the same day → one sent, the other not');
eq((int)$db->query('SELECT COUNT(*) FROM alert_events')->fetchColumn(), 1, 'only one message that day');
call('alice', 'alert_delete', ['id' => $second]);

// ── השהיה ותוקף ──────────────────────────────────────────
call('alice', 'alert_status', ['id' => $pid, 'status' => 'paused']);
[, $s] = cron($at(301));
eq($s['checked'], 0, 'paused → not checked');
eq(call('alice', 'alert_status', ['id' => $pid, 'status' => 'active'])[1]['alert']['last_state'], null, 'resume → a fresh starting point (nothing from the pause is sent)');
$db->exec("UPDATE alerts SET expires_at = 1 WHERE id = $pid");
[, $s] = cron($at(310));
eq([$s['expired'], $s['checked'], call('alice', 'alerts')[1]['alerts'][0]['status']], [1, 0, 'expired'], 'past its validity → expired, not checked');
eq(call('alice', 'alert_status', ['id' => $pid, 'status' => 'active'])[0], 422, 'an expired alert cannot be resumed');

// ── שיטה ושקלול ──────────────────────────────────────────
call('alice', 'alert_delete', ['id' => $pid]);
[$c, $r] = call('alice', 'alert_save', ['asset' => $A, 'tf' => 'h4', 'side' => 'both', 'kind' => 'method', 'method_id' => 'test-cross-b']);
eq($c, 200, 'method alert');
$mid = $r['alert']['id'];
[$c, $r] = call('alice', 'alert_save', ['asset' => $A, 'tf' => 'h4', 'side' => 'both', 'kind' => 'blend', 'methods' => ['test-cross-a', 'test-cross-b']]);
eq($c, 200, 'blend alert (2 methods)');
$bid = $r['alert']['id'];
$db->exec('DELETE FROM alert_events');
$prof['max_alerts_per_day'] = 100;
call('alice', 'profile_save', $prof);
$states = []; $fired = 0;
for ($k = 0; $k < 14; $k++) {
    [, $s] = cron($at(400) + $k * 4 * 3600);
    $fired += $s['fired'];
    $states[] = $db->query("SELECT last_state FROM alerts WHERE id = $mid")->fetchColumn();
    if ($s['errors']) { echo "  errors in run $k: " . file_get_contents("$tmp.error.log") . "\n"; break; }
}
$trans = 0;
for ($k = 1; $k < count($states); $k++) if ($states[$k] !== $states[$k - 1] && $states[$k] !== '0') $trans++;
$evM = (int)$db->query("SELECT COUNT(*) FROM alert_events WHERE alert_id = $mid")->fetchColumn();
eq([$evM, $evM > 0], [$trans, true], 'method alert fires exactly on each change into a direction with certainty ≥ the threshold (' . implode(',', $states) . ')');
eq((int)$db->query("SELECT COUNT(*) FROM alert_events WHERE alert_id = $bid")->fetchColumn() > 0, true, 'blend alert fired too');
$mp = json_decode($db->query("SELECT payload FROM alert_events WHERE alert_id = $mid ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
eq([$mp['certainty'] >= 50, str_contains($mp['body'], 'לפי בדיקה ב'), str_contains($mp['body'], 'לא של העסקה הבאה')], [true, true, true], 'method message: "לפי השיטה", certainty with its meaning');

// ── תקציב לספק בריצה ─────────────────────────────────────
call('alice', 'alert_save', ['asset' => 'binance:ETHUSDT', 'tf' => 'h1', 'side' => 'both', 'kind' => 'price', 'level' => '120']);
call('bob', 'alert_save', ['asset' => 'binance:SOLUSDT', 'tf' => 'h1', 'side' => 'both', 'kind' => 'price', 'level' => '120']);
[, $s] = cron($at(500), ['--budget=binance:1']);
eq([$s['groups'], $s['deferred'] > 0], [1, true], 'budget per provider per run: 1 call → one group, the rest deferred to the next run');
[, $s2] = cron($at(500) + 60, ['--budget=binance:1']);
eq($s2['groups'], 1, 'the next run picks up the deferred group first');
eq((int)$db->query("SELECT COUNT(*) FROM alerts WHERE asset IN ('binance:ETHUSDT','binance:SOLUSDT') AND last_checked_at IS NOT NULL")->fetchColumn(), 2, 'both groups checked across the two runs');
[, $s3] = cron($at(501) + 120);
eq($s3['deferred'], 0, 'with the provider\'s own budget — nothing deferred');

// ── נעילה ────────────────────────────────────────────────
$lock = fopen("$tmp.sqlite.alerts.lock", 'c');
flock($lock, LOCK_EX);
[$code, $s] = cron($at(502));
eq([$code, $s['locked']], [2, true], 'lock: a run does not start while another is running');
flock($lock, LOCK_UN); fclose($lock);
eq(cron($at(503))[1]['locked'], false, 'after the lock is released — runs');

// ── דרך הרשת: אין כתובת שמפעילה את ה-cron ─────────────────
$before = $db->query("SELECT v FROM settings WHERE k = 'alerts_cron'")->fetchColumn();
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'X-Dev-As: owner']]);
$body = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/bin/alerts-cron.php?now=1", false, $ctx);
eq([(int)explode(' ', $http_response_header[0])[1], $body], [403, ''], 'running the cron over the network → 403, nothing runs');
eq($db->query("SELECT v FROM settings WHERE k = 'alerts_cron'")->fetchColumn(), $before, 'and no run was recorded');
eq(trim((string)file_get_contents(dirname(__DIR__) . '/bin/.htaccess')) !== '' && str_contains(file_get_contents(dirname(__DIR__) . '/bin/.htaccess'), 'Require all denied'), true, 'bin/ is denied in .htaccess');

// ── ניקוי המאגר — מה-cron, פעם ביום ──────────────────────
eq((int)$db->query("SELECT v FROM settings WHERE k = 'cache_cleanup_at'")->fetchColumn() >= $at(500) - 86400, true, 'the daily cache cleanup runs from the cron');

// ── תקרות: 20 למשתמש, 500 במערכת ─────────────────────────
$uA = $uidOf('101'); $uD = $uidOf('104');
$have = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE user_id = $uA AND status != 'expired'")->fetchColumn();
$ins = $db->prepare("INSERT INTO alerts (user_id, asset, tf, side, kind, level, status, expires_at, created_at) VALUES (?, 'binance:BTCUSDT', 'h1', 'long', 'price', '1', 'paused', ?, ?)");
for ($i = $have; $i < 20; $i++) $ins->execute([$uA, time() + 86400, time()]);
[$c, $r] = call('alice', 'alert_save', $price);
eq([$c, str_contains($r['error'] ?? '', '20')], [422, true], '21st alert of a user → refused, with the cap in the message');
eq(call('dave', 'alert_save', $price)[0], 200, 'the cap is per user');
$total = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status != 'expired'")->fetchColumn();
$db->beginTransaction();
for ($i = $total; $i < 500; $i++) $ins->execute([$uidOf('100'), time() + 86400, time()]);
$db->commit();
[$c, $r] = call('dave', 'alert_save', $price);
eq([$c, str_contains($r['error'] ?? '', '500')], [422, true], 'system-wide 500 → refused');
eq(call('owner', 'admin_state')[1]['alerts']['total'], 500, 'admin sees the system count');
eq(call('alice', 'admin_state')[0], 403, 'admin state still 403 for a regular user');

// ── ייצוא ────────────────────────────────────────────────
$ex = call('alice', 'export', null, ['format' => 'json'])[1];
eq([count($ex['alerts']) >= 20, is_array($ex['inbox'])], [true, true], 'alice\'s export includes her alerts and inbox');
eq(call('bob', 'export', null, ['format' => 'json'])[1]['inbox'], [], 'bob\'s export has none of alice\'s messages');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
