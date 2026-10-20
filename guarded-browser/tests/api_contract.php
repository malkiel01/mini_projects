<?php
/**
 * בדיקת חוזה ל-api/index.php — דרך HTTP אמיתי (php -S), לא דרך קריאה לפונקציות.
 *
 *   php guarded-browser/tests/api_contract.php
 *
 * החוזה (סקירה, 2026-10-20 — שלב 7): תשובה עם ok=false נושאת allowed=false ו-reason, חוץ משגיאה
 * זמנית (retry=true). תשובה עם ok=true של פעולת הכרעה (check, heartbeat, policy.state) נושאת allowed
 * בוליאני תמיד. אסור שהיעדר allowed יתפרש כהיתר.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-contract-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");

require_once __DIR__ . '/../lib/children.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$acc] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
q("UPDATE accounts SET status = 'active' WHERE id = ?", [$acc]);
[$kid] = childCreate($acc, 'נועה');
q("UPDATE users SET status = 'active' WHERE id = ?", [$kid]);
$token = registerDevice($kid, 'Galaxy', 'g1', 3);
childRuleAdd($kid, 'https://example.org/', 'domain', 'דוגמה', 'allow');

// השרת המובנה של PHP, על פורט פנוי, עם אותה תיקיית נתונים.
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$root = realpath(__DIR__ . '/..');
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                 $pipes, $root, ['GB_DATA_DIR' => $tmp] + getenv());
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv); proc_close($srv);
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);

/** [קוד HTTP, JSON]. */
function api(string $do, array $body = [], string $tok = ''): array {
    global $port;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Content-Type: application/json\r\n" . ($tok !== '' ? "Authorization: Bearer $tok\r\n" : ''),
        'content' => json_encode($body),
    ]]);
    $text = file_get_contents("http://127.0.0.1:$port/api/index.php?do=$do", false, $ctx);
    $code = (int) (preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    return [$code, json_decode((string) $text, true)];
}

/** הצורה שהחוזה דורש מכל תשובה. */
function shape(array $j): string {
    if (($j['ok'] ?? null) === true) return 'ok';
    if (($j['retry'] ?? false) === true) return array_key_exists('allowed', $j) ? 'retry+allowed?!' : 'retry';
    return (($j['allowed'] ?? null) === false && is_string($j['reason'] ?? null) && $j['reason'] !== '') ? 'refused' : 'בלי allowed';
}

echo "\n— חשבון פעיל —\n";
[$c, $j] = api('ping');
check('השרת עלה', [$c, $j['app'] ?? null], [200, 'migdalor']);
[$c, $j] = api('check', ['url' => 'https://example.org/a'], $token);
check('check מותר — allowed=true', [$c, $j['ok'], $j['allowed']], [200, true, true]);
[$c, $j] = api('check', ['url' => 'https://evil.example.net/'], $token);
check('check חסום — ok=true, allowed=false, עם סיבה', [$j['ok'], $j['allowed'], $j['reason'] !== ''], [true, false, true]);
[$c, $j] = api('heartbeat', ['seconds' => 0], $token);
check('heartbeat — allowed בוליאני', is_bool($j['allowed'] ?? null), true);
[$c, $j] = api('policy', [], $token);
check('policy — state.allowed בוליאני', is_bool($j['state']['allowed'] ?? null), true);

echo "\n— סירובים: ok=false תמיד עם allowed=false —\n";
[$c, $j] = api('heartbeat', [], 'not-a-token');
check('אסימון לא מוכר — 401, unauthorized, refused', [$c, $j['code'], shape($j)], [401, 'unauthorized', 'refused']);
[$c, $j] = api('nosuch', [], $token);
check('פעולה לא מוכרת — refused', [$c, shape($j)], [404, 'refused']);

q("UPDATE accounts SET status = 'suspended' WHERE id = ?", [$acc]);
foreach (['heartbeat', 'check', 'policy'] as $do) {
    [$c, $j] = api($do, ['url' => 'https://example.org/a'], $token);
    // הבאג: בלי allowed, ‏optBoolean("allowed", true) בפעימה המשיך לגלוש בחשבון מושעה.
    check("חשבון מושעה — $do: 403, account_suspended, refused", [$c, $j['code'] ?? null, shape($j)],
          [403, 'account_suspended', 'refused']);
}
q("UPDATE accounts SET status = 'active' WHERE id = ?", [$acc]);
[$c, $j] = api('check', ['url' => 'https://example.org/a'], $token);
check('אחרי חידוש — שוב מותר', $j['allowed'] ?? null, true);

echo "\n— שגיאה זמנית: retry, בלי allowed —\n";
// הגבלת ניסיונות (429) — עשרות כניסות שגויות מאותה כתובת.
$last = [];
for ($i = 0; $i < 25; $i++) { $last = api('login', ['username' => 'nobody', 'password' => 'wrong-pass-1']); if ($last[0] === 429) break; }
check('429 — retry, בלי allowed', [$last[0], shape($last[1])], [429, 'retry']);

echo "\n— כל ה-bad() בקוד עוברים דרך אותו חוזה —\n";
$src = file_get_contents(__DIR__ . '/../api/index.php');
check('אין out() עם ok=false שעוקף את bad()', preg_match("/out\\(\\s*\\[\\s*'ok'\\s*=>\\s*false/", $src), 0);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
