<?php
// תקלה בשרת (500) — ההודעה והיומן. php trading-strategies/tests/server-error-api.php — php -S עם שער מדומה וספקים מדומים.
// התקלה נגרמת באמת: הבדיקה מחזיקה נעילת כתיבה על data/cache.sqlite, והבקשה שכותבת למטמון נכשלת אחרי busy_timeout (5 ש').
// - בקריאה (GET — chart_refresh) לא נשמר כלום → "תקלה בשרת — נסו שוב בעוד רגע". "הפעולה לא נשמרה" — רק בכתיבה (POST).
// - ביומן המובנה (DevLoop): סוג החריגה, המקום, הפעולה והפרמטרים — בלי סודות ובלי גוף הבקשה.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-server-error-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log"],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794834030;   // 16.11 13:00:30 UTC — 15:00 בישראל, כמו בתקלה
/** [קוד, JSON מפוענח] */
function call(string $as, string $action, ?array $body = null, array $query = []): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", "X-Dev-Now: $NOW"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
/** שורות error ביומן המובנה, מהחדשה */
function errors(): array
{
    global $tmp;
    $l = new PDO("sqlite:$tmp.sqlite.dev/log.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $l->query("SELECT event, method, action, status, message, data_json FROM log WHERE level = 'error' ORDER BY id DESC")->fetchAll();
}

$tokens['owner'] = call('owner', 'me')[1]['csrf'];
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $NOW - 90 * 86400, 'to' => $NOW, 'methods' => '', 'token' => 'tsd_visible-secret-0zz'];
[$c] = call('owner', 'chart_refresh', null, $q);
eq($c, 200, 'without a lock — the chart loads (the setup works)');

// ── נעילת כתיבה על המטמון — כמו בקשה אחרת שתקועה באמצע כתיבה ──
$lock = new PDO("sqlite:$tmp.sqlite.cache", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lock->exec('BEGIN IMMEDIATE');

// קריאה (GET): נר חדש → כתיבה למטמון → 500. לא נשמר כלום, ולכן לא "הפעולה לא נשמרה"
[$c, $r] = call('owner', 'chart_refresh', null, ['tf' => 'h4'] + $q);   // מסגרת שעוד לא במטמון — חייבת לכתוב נרות
eq([$c, $r['error'] ?? null], [500, 'תקלה בשרת — נסו שוב בעוד רגע'], 'GET that fails → "try again in a moment", not "the action was not saved"');
$e = errors();
$ctx = null;
foreach ($e as $row) if ($row['event'] === 'exception.context') { $ctx = $row; break; }
$d = json_decode($ctx['data_json'] ?? '{}', true);
eq([$ctx['action'] ?? null, $ctx['method'] ?? null, $d['class'] ?? null], ['chart_refresh', 'GET', 'PDOException'], 'the log: the action, GET, and the class of the exception');
eq([$d['params']['asset'] ?? null, $d['params']['tf'] ?? null, isset($d['file'], $d['line'])], ['binance:BTCUSDT', 'h4', true], 'the log: the parameters and the place');
eq($d['params']['token'] ?? null, '***', 'the log: a parameter that looks like a secret is hidden');
eq(str_contains(json_encode($e), 'visible-secret'), false, 'the secret is nowhere in the log');

// כתיבה (POST): admin_cache_clear נכשל → "הפעולה לא נשמרה" — שם זה נכון
[$c, $r] = call('owner', 'admin_cache_clear', ['provider' => 'binance']);
eq([$c, $r['error'] ?? null], [500, 'תקלה בשרת — הפעולה לא נשמרה'], 'POST that fails → "the action was not saved"');
$ctx = null;
foreach (errors() as $row) if ($row['event'] === 'exception.context') { $ctx = $row; break; }
eq([$ctx['action'] ?? null, $ctx['method'] ?? null], ['admin_cache_clear', 'POST'], 'the log: the POST action');

$lock->exec('ROLLBACK');
[$c] = call('owner', 'chart_refresh', null, ['tf' => 'h4'] + $q);
eq($c, 200, 'the lock is gone — the same request works');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
