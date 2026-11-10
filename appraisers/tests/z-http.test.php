<?php
// מקצה לקצה, ב-HTTP אמיתי מול שרת הפיתוח של PHP: api.php ו-cron.php עצמם — כותרות, עוגייה, פורמט התשובה,
// 01-E4 (cron.php) ו-01-E6 (גוף הבקשה לא נרשם ביומן הטכני).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$data = sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5));
mkdir($data, 0700, true);
$root = dirname(__DIR__);
copy("$root/data/.htaccess", "$data/.htaccess");
$port = 0;
for ($i = 0; $i < 20 && !$port; $i++) { $p = random_int(20000, 40000); $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); $port = $p; } }
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-t', $root],
    [['pipe', 'r'], ['file', "$data/server.log", 'w'], ['file', "$data/server.log", 'a']], $pipes, $root, ['APR_DATA_DIR' => $data, 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $data) {
    proc_terminate($proc);
    proc_close($proc);
    $rm = function (string $d) use (&$rm) { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); @rmdir($d); };
    $rm($data);
});
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

$fail = 0; $n = 0;
function check(string $name, bool $ok, mixed $got = null): void { global $fail, $n; $n++; if ($ok) return; $fail++; echo "  ✗ $name" . ($got !== null ? ' — ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
function eq(mixed $got, mixed $want, string $name): void { check($name, $got === $want, ['got' => $got, 'want' => $want]); }

/** בקשת HTTP. מחזיר [status, json|null, headers (lowercase), raw]. */
function http(string $method, string $path, ?array $body = null, array $headers = [], ?string $raw = null): array
{
    global $port;
    $h = [];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    if ($body !== null || $raw !== null) $h[] = 'Content-Type: application/json';
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $raw ?? ($body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : ''),
        'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]]);
    $res = (string)file_get_contents("http://127.0.0.1:$port/$path", false, $ctx);
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    $hs = [];
    foreach (array_slice($http_response_header ?? [], 1) as $line) { [$k, $v] = array_pad(explode(':', $line, 2), 2, ''); $hs[strtolower($k)][] = trim($v); }
    return [(int)($m[1] ?? 0), json_decode($res, true), $hs, $res];
}

// ── בלי זהות ──
[$st, $j, $h] = http('GET', 'api.php?action=me', null, ['X-Dev-As' => 'none']);
eq([$st, $j['ok'] ?? null, $j['error']['code'] ?? null], [401, false, 'unauthenticated'], 'בלי זהות: 401 בפורמט {ok:false, error:{code, message}}');
check('כל תשובה נושאת X-Request-Id, והוא חוזר גם בגוף השגיאה', preg_match('/^[a-f0-9]{10}$/', $h['x-request-id'][0] ?? '') === 1 && ($j['req'] ?? '') === $h['x-request-id'][0]);
eq([$h['cache-control'][0] ?? '', $h['x-content-type-options'][0] ?? ''], ['no-store', 'nosniff'], 'Cache-Control: no-store, nosniff');
check('בקשה בלי זהות לא מקבלת עוגייה', !isset($h['set-cookie']));
eq(http('GET', 'api.php?action=me', null, ['X-Dev-As' => 'detached'])[0], 401, '01-B7 מנותקת, בלי session → 401');
eq(http('GET', 'api.php?action=me', null, ['X-Dev-As' => 'unavailable'])[0], 503, '01-B7 המעטפת לא עונה → 503');
[$st, $j, $h] = http('GET', 'api.php?action=health', null, ['X-Dev-As' => 'none']);
eq([$st, array_keys($j), isset($h['set-cookie'])], [200, ['ok', 'version'], false], '01-E2 health ציבורי: רק ok ו-version, בלי עוגייה');

// ── כניסה: עוגייה משלה ──
[$st, $me, $h] = http('GET', 'api.php?action=me');
$cookie = $h['set-cookie'][0] ?? '';
check('העוגייה apr_session: HttpOnly, SameSite=Lax, נתיב התיקייה', $st === 200 && preg_match('/^apr_session=[a-f0-9]{64};/', $cookie) === 1 && stripos($cookie, 'HttpOnly') !== false
    && stripos($cookie, 'SameSite=Lax') !== false && preg_match('#path=/(;|$)#i', $cookie) === 1, $cookie);
preg_match('/apr_session=([a-f0-9]{64})/', $cookie, $m);
$C = ['Cookie' => 'apr_session=' . $m[1]];
[, $me2, $h2] = http('GET', 'api.php?action=me', null, $C);
eq([$me2['csrf'], isset($h2['set-cookie'])], [$me['csrf'], false], 'בקשה שנייה עם העוגייה — אותו session, בלי עוגייה חדשה');
eq([$me['ok'], $me['user']['is_developer']], [true, true], 'הבעלים: מפתח');
$X = $C + ['X-CSRF' => $me['csrf']];

// ── 01-E6: גוף הבקשה לא נרשם ביומן הטכני ──
$needle = 'UNIQ-' . bin2hex(random_bytes(8));
eq(http('POST', 'api.php?action=office-create', ['name' => "משרד $needle", 'role' => 'appraiser', 'extra' => $needle], $X)[0], 200, 'פעולה עם מחרוזת ייחודית בגוף');
eq(http('POST', 'api.php?action=invite-create', ['email' => "$needle@example.com", 'role' => 'staff'], $X)[0], 200, 'ועוד אחת');
eq(http('POST', 'api.php?action=office-rename', ['name' => str_repeat('א', 500) . $needle], $X)[0], 422, 'ופעולה שנכשלת');
eq(http('POST', 'api.php?action=invite-accept', ['token' => $needle], $X)[0], 404, 'ועוד אחת שנכשלת');
eq(http('POST', 'api.php?action=office-rename', null, $X, '{"name": "' . $needle)[0], 422, 'וגוף JSON שבור');
usleep(300000);
$logdb = new PDO("sqlite:$data/_dev/log.sqlite");
$rows = $logdb->query('SELECT * FROM log')->fetchAll(PDO::FETCH_ASSOC);
check('01-E6 היומן רשם את הבקשות', count(array_filter($rows, fn($r) => $r['action'] === 'office-create' && (int)$r['status'] === 200)) === 1 && count($rows) >= 8, count($rows));
check('01-E6 המחרוזת מהגוף לא מופיעה בשום שורה ביומן', !str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), $needle));
foreach (glob("$data/_dev/log.sqlite*") as $f) check('01-E6 וגם לא בקובצי היומן עצמם: ' . basename($f), !str_contains((string)file_get_contents($f), $needle));
$row = array_values(array_filter($rows, fn($r) => $r['action'] === 'office-rename'))[0];
eq([(int)$row['status'], $row['username'], $row['method']], [422, 'הבעלים', 'POST'], 'שורת בקשה: פעולה, מצב, מי');
check('לכל שורה מזהה בקשה ומשך', !array_filter($rows, fn($r) => $r['event'] === 'request' && ($r['req'] === '' || $r['ms'] === null)));

// ── CSRF ב-HTTP ──
eq(http('POST', 'api.php?action=office-rename', ['name' => 'x'], $C)[1]['error']['code'] ?? null, 'csrf', '01-B5 בלי X-CSRF → 403');

// ── מפתח: תקינות ושירותי רקע ──
[$st, $hl] = http('GET', 'api.php?action=dev-health', null, $C);
check('בדיקות התקינות ב-HTTP — בלי כשל', $st === 200 && $hl['state'] !== 'bad', array_values(array_filter($hl['checks'] ?? [], fn($c) => $c['state'] === 'bad')));
[, $jobs] = http('GET', 'api.php?action=dev-jobs', null, $C);
$url = $jobs['cron']['url'] ?? '';
check('הכתובת של המתזמן להעתקה', preg_match('#/cron\.php\?token=[a-f0-9]{40}$#', $url) === 1, $url);
check('סבב הגיבוי רץ אחרי בקשה רגילה (המתזמן עוד לא הוגדר)', ($jobs['cron']['last_ok_at'] ?? null) !== null && $jobs['cron']['last_source'] === 'lazy', $jobs['cron'] ?? null);

// ── 01-E4: cron.php ──
eq(http('GET', 'cron.php', null, ['X-Dev-As' => 'none'])[0], 404, '01-E4 cron.php בלי אסימון → 404');
eq(http('GET', 'cron.php?token=' . str_repeat('a', 40), null, ['X-Dev-As' => 'none'])[0], 404, '01-E4 אסימון שגוי → 404');
$token = substr($url, strpos($url, 'token=') + 6);
[$st, $cr] = http('GET', "cron.php?token=$token", null, ['X-Dev-As' => 'none']);
check('01-E4 האסימון הנכון, בלי זהות → מריץ ומחזיר סיכום', $st === 200 && $cr['ok'] === true && array_key_exists('ran', $cr), $cr);
[, $jobs] = http('GET', 'api.php?action=dev-jobs', null, $C);
eq([$jobs['cron']['last_source'], in_array('maintenance.cleanup', array_column($jobs['jobs'], 'kind'), true)], ['cron', true], '01-E4 cron_last_ok_at עודכן, ו-maintenance.cleanup רץ');

// ── DevLoop ב-HTTP: חוזה המודול, וצירוף צילום ──
[$st, $j] = http('POST', 'api.php?action=dev-task-create', ['title' => ''], $X);
check('שגיאת DevLoop: {error: "…"}', $st === 422 && is_string($j['error'] ?? null), $j);
[, $t] = http('POST', 'api.php?action=dev-task-create', ['title' => 'בדיקת צירוף'], $X);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
global $port;
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Cookie: {$C['Cookie']}\r\nX-CSRF: {$me['csrf']}\r\nContent-Type: image/png", 'content' => $png, 'ignore_errors' => true]]);
$att = json_decode((string)file_get_contents("http://127.0.0.1:$port/api.php?action=dev-attach&code={$t['code']}&name=shot.png", false, $ctx), true);
check('צירוף צילום למטלה (גוף גולמי)', !empty($att['ok']) || !empty($att['id']), $att);
check('הצילום נשמר תחת data/_dev', count(glob("$data/_dev/*/*") ?: glob("$data/_dev/*.png") ?: []) > 0 || count(array_diff(scandir("$data/_dev"), ['.', '..', 'log.sqlite', 'log.sqlite-wal', 'log.sqlite-shm'])) > 0);

// ── משתמש רגיל ב-HTTP ──
$U = ['X-Dev-As' => '17:דנה לוי:dana@example.com'];
eq(http('GET', 'api.php?action=dev-health', null, $U)[0], 404, '01-E1 משתמש רגיל: מסך המפתח → 404');
eq(http('GET', 'api.php?action=dev-items', null, $U)[0], 404, '01-E1 ו-DevLoop → 404');

// ── קבצים סטטיים ונתיבים חסומים ──
eq(http('GET', 'manifest.webmanifest')[0], 200, 'manifest.webmanifest מוגש');
$idx = http('GET', 'index.html')[3];
check('index.html נטען, בעברית מימין לשמאל', str_contains($idx, 'lang="he"') && str_contains($idx, 'dir="rtl"'));
check('אין שגיאות PHP ביומן השרת', !preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', (string)file_get_contents("$data/server.log")), substr((string)file_get_contents("$data/server.log"), -600));

echo $fail ? "  $fail מתוך $n נכשלו\n" : "  ✓ $n בדיקות\n";
exit($fail ? 1 : 0);
