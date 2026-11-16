<?php
// L2 דרך ה-API (spec-stage-a 23.4, קריטריונים L2 2–4): עסקה מכרטיס שנסגרת → שורת trade; הכיבוי של המשתמש (ל3);
// ו-user_id לא מופיע בשום תשובה למשתמש אחר, ולא בייצוא של עורך שאינו ראשי (24.2).
// php trading-strategies/tests/learn-live-api.php — php -S עם שער מדומה (dev-prepend). owner — עורך ראשי, carol — עורך משני.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-learn-live-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log"],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? null : @unlink($f);
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
/** [קוד, JSON מפוענח, הגוף כמו שהגיע] */
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw, $raw];
}
foreach (['alice', 'bob', 'carol', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$idOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
[$ALICE, $BOB, $CAROL] = [$idOf('101'), $idOf('102'), $idOf('103')];
eq(call('owner', 'admin_role_add', ['user_id' => $CAROL])[0], 200, 'carol — secondary editor');

$M = 'elliott-w3';
$snap = ['asset' => 'binance:BTCUSDT', 'tf' => 'h4', 'method_id' => $M, 'method_version' => 3, 'as_of' => 1767225600];
$trade = fn(array $o) => $o + ['symbol' => 'BTCUSDT', 'market' => 'crypto', 'currency' => 'USDT', 'multiplier' => '1', 'direction' => 'long',
    'opened_at' => '2026-01-01T10:00', 'entry_price' => '100', 'quantity' => '2', 'planned_stop' => '98', 'method_id' => $M, 'analysis_snapshot' => $snap,
    'exits' => [['exited_at' => '2026-01-03T10:00', 'price' => '104', 'quantity' => '2']]];
$rows = fn(int $uid) => (int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE source = 'trade' AND user_id = $uid")->fetchColumn();

// ── קריטריון 2 ──
eq(call('alice', 'trade_save', $trade([]))[0], 200, 'alice closes a trade opened from a card');
eq([$rows($ALICE), (float)$db->query("SELECT r FROM method_outcomes WHERE source = 'trade'")->fetchColumn()], [1, 2.0], 'criterion 2: one trade row, R = +2 (risk 2×2, net 4×2)');
call('alice', 'trade_save', $trade(['planned_stop' => null]));
eq($rows($ALICE), 1, 'criterion 2: without planned_stop → no row');

// ── קריטריון 3: הכיבוי — של המשתמש עצמו, מההגדרות ──
eq(call('bob', 'me')[1]['user']['learn_off'], false, 'the switch is on by default');
[$c, $r] = call('bob', 'learn_off_set', ['off' => true]);
eq([$c, $r['learn_off'], call('bob', 'me')[1]['user']['learn_off']], [200, true, true], 'bob turns it off — and me shows it');
eq(call('bob', 'learn_off_set', ['off' => 'yes'])[0], 422, 'learn_off_set: only true / false');
eq(call('bob', 'learn_off_set', ['off' => true], [])[0] === 200 && call('alice', 'me')[1]['user']['learn_off'] === false, true, 'one user\'s switch does not touch another');
call('bob', 'trade_save', $trade([]));
eq($rows($BOB), 0, 'criterion 3: bob turned it off → no trade rows of his');

// ── קריטריון 4: user_id לא מופיע לאחרים ──
$id = (string)$ALICE;
[$c, $j, $raw] = call('carol', 'admin_learn', null, ['method' => $M, 'source' => 'trade']);
eq([$c, str_contains($raw, 'user_id'), $j['rows'] ?? null], [200, false, 1], 'criterion 4: admin_learn for a secondary editor — no user_id');
[$c, , $csv] = call('carol', 'admin_learn_csv', null, ['method' => $M, 'source' => 'trade']);
$head = str_getcsv(explode("\n", ltrim($csv, "\u{FEFF}"))[0]);
eq([$c, in_array('user_id', $head, true)], [200, false], 'criterion 4: CSV of a secondary editor — no user_id column');
[$c, , $csv] = call('owner', 'admin_learn_csv', null, ['method' => $M, 'source' => 'trade']);
$lines = array_values(array_filter(explode("\n", ltrim($csv, "\u{FEFF}"))));
$head = str_getcsv($lines[0]);
$col = array_search('user_id', $head, true);
eq([$c, $col !== false, $col !== false ? str_getcsv($lines[1])[$col] : null], [200, true, $id], 'criterion 4: the chief editor\'s CSV has user_id');
eq([call('alice', 'admin_learn', null, ['method' => $M])[0], call('alice', 'admin_learn_csv', null, ['method' => $M])[0]], [403, 403], 'a user — no learning screen, no export');
// התשובות שמשתמש אחר מקבל (בוב): אין בהן שורות של אליס ולא user_id שלה
foreach (['me', 'trades', 'stats', 'method_journal'] as $a) {
    [, , $raw] = call('bob', $a, null, $a === 'method_journal' ? ['method' => $M] : []);
    eq(str_contains($raw, '"user_id"'), false, "criterion 4: bob's $a — no user_id");
}

echo $fail ? "$fail/$n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
