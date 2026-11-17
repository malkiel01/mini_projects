<?php
// "זה שוב פעם ריק" (הבעלים, 2026-11-17, XAG ⇄ USD ב-Twelve Data, שעה, 3 חודשים) — הגרף לא נופל ולא נשאר ריק בלי הסבר:
//   - Twelve Data מחזיר 400 "No data is available on the specified dates" על טווח בלי נרות (סוף שבוע, פרוסה קצרה) — זה "אין נרות",
//     לא תקלה. לפני התיקון: 502 לכל chart_refresh, והגרף ריק.
//   - שגיאה אחרת מהספק (סימבול לא בתוכנית) — ההודעה האמיתית, לא "המפתח נדחה"; כשיש נרות במאגר — הם מוצגים, וההודעה איתם.
//   - טווח שכולו סגור — 200, 0 נרות (הדפדפן מציג "אין נתונים לטווח").
// php trading-strategies/tests/empty-chart-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-empty-chart-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log", 'TS_TD_FAIL_FILE' => "$tmp.fail"] + getenv();
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
function call(string $as, string $action, ?array $body = null, array $query = [], ?int $now = null): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", 'X-Dev-Now: ' . ($now ?? $NOW)];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
$NOW = gmmktime(12, 20, 0, 11, 14, 2026);   // שבת 14.11.2026, 12:20 UTC — השוק סגור משישי 21:00
foreach (['alice', 'bob'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
eq(call('alice', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Alice4455667788'])[0], 200, 'alice connects Twelve Data');
$A = 'twelvedata:XAG/USD';
$q = fn(int $now, int $days = 90) => ['asset' => $A, 'tf' => 'h1', 'from' => $now - $days * 86400, 'to' => $now];

// ── 1. פתיחה בשבת: שעה, 3 חודשים — נרות עד שישי 20:00, בלי שגיאה ──
[$c, $r] = call('alice', 'chart_refresh', null, $q($NOW) + ['methods' => '']);
$last = $r['candles'] ? end($r['candles'])['t'] : null;
eq([$c, count($r['candles'] ?? []) > 1000, $last === gmmktime(20, 0, 0, 11, 13, 2026)], [200, true, true],
    'Saturday, XAG/USD 1h, 3 months: the chart has candles, the last on Friday 20:00');
// ── 2. שעתיים אחר כך (עדיין שבת): הקצה החסר כולו סגור → Twelve Data 400 "No data" — לפני התיקון 502 והגרף ריק ──
$N2 = $NOW + 2 * 3600;
[$c, $r2] = call('alice', 'chart_refresh', null, $q($N2) + ['methods' => ''], $N2);
eq([$c, count($r2['candles'] ?? []) > 1000, $r2['notice'] ?? null], [200, true, null],
    'two hours later (still Saturday): the missing tail is all closed — Twelve Data answers 400 "No data", the chart still has its candles, no error');
[$c, $r3] = call('alice', 'market_candles', null, $q($N2 + 600), $N2 + 600);
eq([$c, count($r3['candles'] ?? []) > 1000], [200, true], '… and market_candles too (the old part\'s 10-minute sliver has no candle either)');
// ── 3. טווח שכולו סגור (יום אחד בשבת): 200, 0 נרות, בלי שגיאה ──
[$c, $r4] = call('alice', 'market_candles', null, ['asset' => $A, 'tf' => 'h1', 'from' => $NOW - 10 * 3600, 'to' => $NOW]);
eq([$c, $r4['candles'] ?? null, $r4['notice'] ?? null], [200, [], null], 'a range that is all closed (Saturday morning) → 200, 0 candles, no error — the chart says "אין נתונים לטווח"');
// ── 4. סימבול שלא בתוכנית של המפתח: ההודעה האמיתית (לא "המפתח נדחה") ──
[$c, $r5] = call('alice', 'chart_refresh', null, ['asset' => 'twelvedata:PLANX/USD', 'tf' => 'h1', 'from' => $NOW - 5 * 86400, 'to' => $NOW, 'methods' => '']);
eq([$c, str_contains((string)($r5['error'] ?? ''), 'לא זמין בתוכנית'), str_contains((string)($r5['error'] ?? ''), 'המפתח נדחה')], [502, true, false],
    'a symbol outside the key\'s plan → the real reason ("הסימבול לא זמין בתוכנית של המפתח"), not "המפתח נדחה"');
eq(str_contains((string)($r5['error'] ?? ''), 'good-Alice'), false, '… and the key is not in the message');

// ── 4ב. תקלה רק בחלק הישן (מעבר ל-60 יום) — 60 הימים האחרונים עדיין נמשכים. לפני התיקון: הקצה החדש לא נמשך בכלל ──
$N4 = gmmktime(7, 40, 0, 11, 16, 2026);   // שני בבוקר — מה שנפתח מאז שישי חסר
file_put_contents("$tmp.fail", (string)($N4 - 60 * 86400));
[$c, $r9] = call('alice', 'chart_refresh', null, ['asset' => $A, 'tf' => 'h1', 'from' => $N4 - 120 * 86400, 'to' => $N4, 'methods' => ''], $N4);
eq([$c, str_contains((string)($r9['notice'] ?? ''), 'Internal error'), end($r9['candles'])['t'] ?? null], [200, true, gmmktime(7, 0, 0, 11, 16, 2026)],
    'an error only on the part older than 60 days → a notice, and the recent tail still comes in (up to Monday 07:00) — before: it stopped at Friday');
unlink("$tmp.fail");

// ── 5. תקלה מהספק (לא תקרה) כשיש נרות במאגר — הם מוצגים, וההודעה איתם; לפני התיקון — 502 לכל הגרף ──
$N3 = gmmktime(9, 30, 0, 11, 16, 2026);   // שני — השוק פתוח, יש קצה חסר למשוך
touch("$tmp.fail");
[$c, $r6] = call('alice', 'chart_refresh', null, $q($N3) + ['methods' => ''], $N3);
eq([$c, count($r6['candles'] ?? []) > 1000, str_contains((string)($r6['notice'] ?? ''), 'Twelve Data')], [200, true, true],
    'a provider error (not a cap) on the missing tail → 200, the candles in the pool, and the provider\'s message as a notice');
[$c, $r7] = call('alice', 'chart_refresh', null, ['asset' => 'twelvedata:EUR/USD', 'tf' => 'h1', 'from' => $N3 - 5 * 86400, 'to' => $N3, 'methods' => ''], $N3);
eq([$c, str_contains((string)($r7['error'] ?? ''), 'Internal error')], [502, true], '… nothing in the pool at all → the provider\'s real message (502)');
unlink("$tmp.fail");
[$c, $r8] = call('alice', 'chart_refresh', null, $q($N3) + ['methods' => ''], $N3 + 400);
eq([$c, $r8['notice'] ?? null, end($r8['candles'])['t'] ?? null], [200, null, gmmktime(9, 0, 0, 11, 16, 2026)], 'the provider is back → the tail fills in (up to the open 09:00 candle), no notice');

$err = array_values(array_filter(explode("\n", is_file("$tmp.error.log") ? (string)file_get_contents("$tmp.error.log") : ''),
    fn($l) => trim($l) !== '' && !str_contains($l, ' in Unknown on line 0')));
eq($err, [], 'no PHP errors on the server');
echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
