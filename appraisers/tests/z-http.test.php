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
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'post_max_size=32M', '-t', $root],
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
    if (($body !== null || $raw !== null) && !isset($headers['Content-Type'])) $h[] = 'Content-Type: application/json';
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
// ── פרוסה 02, ב-HTTP אמיתי: הפעולות הציבוריות עונות בלי זהות, והכניסה המאוחדת היא הפניה של הדפדפן ──
[$st, $j, $h] = http('GET', 'api.php?action=platform_key', null, ['X-Dev-As' => 'none']);
eq([$st, $j['client'] ?? null, $j['keys'][0]['alg'] ?? null, isset($h['set-cookie'])], [200, 'appraisers', 'Ed25519', false], '02-A1 platform_key בלי זהות: JSON, לא 401, בלי עוגייה');
eq(http('GET', 'api.php?action=login-config', null, ['X-Dev-As' => 'detached'])[1]['mode'] ?? null, 'detached', 'login-config ציבורי');
[$st, , $h] = http('GET', 'api.php?action=sso-start', null, ['X-Dev-As' => 'detached']);
check('§5.2 sso-start: 302 למעטפת, עם state ועוגיית apr_sso (HttpOnly)', $st === 302 && preg_match('~/platform/sso\.php\?client=appraisers&state=[a-f0-9]{32}&return=~', $h['location'][0] ?? '') === 1
    && preg_match('/^apr_sso=[a-f0-9]{32};.*HttpOnly/i', $h['set-cookie'][0] ?? '') === 1, [$st, $h['location'] ?? null]);
[$st, , $h] = http('GET', 'api.php?action=sso-callback&state=' . str_repeat('a', 32) . '&token=x.y', null, ['X-Dev-As' => 'detached']);
check('02-B1 sso-callback עם state זר: חזרה למסך הכניסה, בלי עוגיית session', $st === 302 && str_ends_with($h['location'][0] ?? '', '#/login/invalid') && !preg_match('/apr_session=[a-f0-9]/', implode(' ', $h['set-cookie'] ?? [])), [$st, $h]);
eq(http('POST', 'api.php?action=login', ['email' => 'a@example.com', 'password' => 'x'], ['X-Dev-As' => 'none'])[0], 403, '02-B3 מחוברת: login → 403');
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

// ── פרוסה 06, ב-HTTP אמיתי: העלאה בגוף גולמי, והגשה עם הכותרות (06-C3) ──
http('POST', 'api.php?action=office-create', ['name' => 'משרד קבצים', 'role' => 'appraiser'], $X);
$pp = http('POST', 'api.php?action=profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $X)[1]['profile'] ?? '';
$dr = http('POST', 'api.php?action=profile-draft-open', ['profile' => $pp], $X)[1];
$sv = http('POST', 'api.php?action=profile-draft-save', ['profile' => $pp, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => []], 'tasks' => ['items' => []], 'rules' => []], 'revision' => $dr['draft']['revision'] ?? ''], $X)[1];
http('POST', 'api.php?action=profile-publish', ['profile' => $pp, 'note' => '', 'revision' => $sv['revision'] ?? ''], $X);
$cc = http('POST', 'api.php?action=case-create', ['profile' => $pp], $X)[1]['case'] ?? '';
$pdfBody = "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n<script>alert(1)</script>\n%%EOF\n";
$upUrl = 'api.php?action=file-upload&case=' . $cc . '&name=' . rawurlencode('../נסח <b>.html');
[$st, $j] = http('POST', $upUrl, null, $X, $pdfBody);
$fp = $j['file'] ?? '';
eq([$st, preg_match('/^[a-z0-9]{16}$/', $fp), http('POST', $upUrl, null, $C, $pdfBody)[0], http('POST', $upUrl, null, $X, '<html><script>alert(1)</script></html>')[0], http('POST', $upUrl, null, $X, '')[0]],
    [200, 1, 403, 415, 415], '06 העלאה ב-HTTP: גוף גולמי נשמר; בלי X-CSRF ← 403; HTML או גוף ריק ← 415');
[$st, , $h, $raw] = http('GET', 'api.php?action=file&file=' . $fp, null, $C);
eq([$st, $raw === $pdfBody, $h['content-type'][0] ?? '', $h['x-content-type-options'][0] ?? '', str_starts_with($h['content-disposition'][0] ?? '', 'attachment; filename='), str_contains($h['content-disposition'][0] ?? '', '.pdf'),
    str_contains($h['content-disposition'][0] ?? '', 'html'), str_starts_with($h['content-security-policy'][0] ?? '', 'sandbox'), str_contains($h['cache-control'][0] ?? '', 'no-store')],
    [200, true, 'application/pdf', 'nosniff', true, true, false, true, true], '06-C3 הורדה ב-HTTP: התוכן כמו שהוא, application/pdf, nosniff, attachment עם סיומת מהסוג שזוהה, sandbox');
[$st, , $h] = http('GET', 'api.php?action=file&inline=1&file=' . $fp, null, $C);
eq([$st, str_starts_with($h['content-disposition'][0] ?? '', 'inline; '), str_starts_with($h['content-security-policy'][0] ?? '', 'sandbox'), $h['x-content-type-options'][0] ?? ''], [200, true, true, 'nosniff'], '06-C3 תצוגה מקדימה ב-HTTP: inline עם sandbox');
eq([http('GET', 'api.php?action=file&file=' . $fp, null, ['X-Dev-As' => '77:זר:stranger@example.com'])[0], http('GET', 'api.php?action=file&file=' . $fp, null, ['X-Dev-As' => 'none'])[0]], [403, 401], '06-C1 ב-HTTP: בלי משרד / בלי זהות ← אין קובץ');
$stored = glob("$data/files/*/*") ?: [];
check('06-B7 הקובץ בדיסק: תחת data/files, שם אקראי בלי סיומת; הקובץ הזמני נמחק', count($stored) === 1 && preg_match('~/files/[a-f0-9]{2}/[a-f0-9]{32}$~', $stored[0]) === 1 && !(glob("$data/tmp/*") ?: []), $stored);

// סבב 2: הגוף אינו נכתב לפני אימות (🔴2), וגוף מעל המגבלה נדחה (🟡6) — גם בלי Content-Length (chunked)
$spy = function () use ($data): array { clearstatcache(); return [is_dir("$data/tmp") ? count(glob("$data/tmp/*") ?: []) : 0, count(glob("$data/files/*/*") ?: [])]; };
$was = $spy();
$tmpMtime = is_dir("$data/tmp") ? filemtime("$data/tmp") : 0;
sleep(1);
$anon = [http('POST', $upUrl, null, ['X-Dev-As' => 'none'], $pdfBody)[0], http('POST', $upUrl, null, ['X-Dev-As' => '77:זר:stranger@example.com', 'X-CSRF' => 'x'], $pdfBody)[0], http('POST', $upUrl, null, $C, $pdfBody)[0],
    http('POST', 'api.php?action=file-upload&case=aaaaaaaaaaaaaaaa&name=x.pdf', null, $X, $pdfBody)[0]];
clearstatcache();
eq([$anon, $spy(), is_dir("$data/tmp") ? filemtime("$data/tmp") : 0], [[401, 403, 403, 404], $was, $tmpMtime], '🔴2 ב-HTTP: בלי זהות, בלי משרד, בלי CSRF, תיק שלא קיים ← נדחה, ושום קובץ לא נוצר ב-data/tmp (גם לא לרגע)');
$bigBody = "%PDF-1.4\n" . str_repeat('% ' . str_repeat('x', 1021) . "\n", 26 * 1024) . "%%EOF\n";
[$st, $j] = http('POST', $upUrl . '&dup=1', null, $X, $bigBody);
eq([$st, $j['error']['code'] ?? null, $spy()], [413, 'file_too_large', $was], '🟡6 ב-HTTP: גוף של 26MB (Content-Length) ← 413, לא נשמר');
$sock = fsockopen('127.0.0.1', $port, $en, $es, 10);
fwrite($sock, "POST /$upUrl&dup=1 HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: {$C['Cookie']}\r\nX-CSRF: {$me['csrf']}\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
foreach (str_split($bigBody, 1 << 20) as $part) @fwrite($sock, dechex(strlen($part)) . "\r\n" . $part . "\r\n");
@fwrite($sock, "0\r\n\r\n");
stream_set_timeout($sock, 20);
$resp = (string)stream_get_contents($sock);
fclose($sock);
preg_match('#^HTTP/\S+ (\d+)#', $resp, $m);
check('🟡6 ב-HTTP: אותו גוף ב-chunked (בלי Content-Length) ← לא נשמר', (int)($m[1] ?? 0) >= 400 && $spy() === $was, [substr($resp, 0, 60), $spy(), $was]);

// ── פרוסה 07, ב-HTTP אמיתי: הפורטל הוא כתובת ציבורית — בלי זהות, בלי עוגייה, והאסימון אינו נרשם ──
$N = ['X-Dev-As' => 'none'];                                   // דפדפן של נמען: אין זהות מהשער, אין session
http('POST', 'api.php?action=case-update', ['case' => $cc, 'title' => 'דירה ברחוב הסוד 9', 'purpose' => 'purchase'], $X);
http('POST', 'api.php?action=case-identifier-add', ['case' => $cc, 'kind' => 'parcel', 'gush' => '7777', 'helka' => '3'], $X);
$pty = http('POST', 'api.php?action=case-party-add', ['case' => $cc, 'name' => 'נועה נמענת', 'kind' => 'client', 'is_orderer' => true], $X)[1]['party'] ?? '';
http('POST', 'api.php?action=case-start', ['case' => $cc], $X);
$rqp = http('POST', 'api.php?action=requirement-add', ['case' => $cc, 'title' => 'נסח טאבו', 'doc_type' => 'rights', 'explain_internal' => 'פנימי-בלבד-7'], $X)[1]['requirement'] ?? '';
$content = ['case' => $cc, 'party' => $pty, 'requirements' => [$rqp], 'channel' => 'link_copy'];
$pv = http('POST', 'api.php?action=request-preview', $content, $X)[1];
eq(http('POST', 'api.php?action=request-send', $content + ['op' => 'op-http-0001'], $X)[1]['error']['code'] ?? null, 'preview_required', '07-A1 ב-HTTP: שליחה בלי תצוגה מקדימה נדחית');
$snd = http('POST', 'api.php?action=request-send', $content + ['preview' => $pv['preview'] ?? '', 'op' => 'op-http-0001'], $X);
preg_match('~^http://127\.0\.0\.1:\d+/portal/#([A-Za-z0-9_-]{43})$~', (string)($snd[1]['link'] ?? ''), $tm);
$TK = $tm[1] ?? '';
check('הכנה: בקשה ב"קישור להעתקה" — הקישור נבנה מכתובת האפליקציה, והאסימון אחרי ה-#', $snd[0] === 200 && $TK !== '', $snd[1]);

[$st, , $h, $page] = http('GET', 'portal/', null, $N);
$csp = $h['content-security-policy'][0] ?? '';
eq([$st, $h['referrer-policy'][0] ?? '', $h['cache-control'][0] ?? '', str_contains($h['x-robots-tag'][0] ?? '', 'noindex'), isset($h['set-cookie']),
    str_contains($csp, "default-src 'none'"), (bool)preg_match("~script-src 'nonce-[A-Za-z0-9_-]{20,}';~", $csp), str_contains($csp, 'unsafe'), str_contains($csp, "frame-ancestors 'none'")],
    [200, 'no-referrer', 'no-store', true, false, true, true, false, true], '07-D1 דף הפורטל בלי זהות: 200, no-referrer, no-store, noindex, CSP בלי סקריפט חיצוני ובלי inline — ובלי עוגייה');
preg_match_all('~(?:src|href)="([^"]+)"~', (string)preg_replace('~<!--.*?-->~s', '', $page), $refs);
eq([array_values(array_filter($refs[1], fn($u) => !preg_match('~^portal\.(js|css)\?v=[0-9.]+$~', $u))), (bool)preg_match('~<script(?![^>]*\ssrc=)|\son[a-z]+=|style=~i', $page), str_contains($page, 'index.html')],
    [[], false, false], '07-D2 בדף הפורטל: רק portal.js ו-portal.css, בלי סקריפט בתוך הדף, ובלי קישור למערכת');
$pjs = http('GET', 'portal/portal.js', null, $N);
$pcss = http('GET', 'portal/portal.css', null, $N);
eq([$pjs[0], $pcss[0], (bool)preg_match('~https?://|innerHTML|document\.write|localStorage|sessionStorage|eval\(~', $pjs[3]), (bool)preg_match('~url\(|@import|https?://~', $pcss[3]),
    (bool)preg_match('~assets/|index\.html|#/cases~', $pjs[3])], [200, 200, false, false, false],
    '07-D2 portal.js ו-portal.css: בלי משאב חיצוני, בלי innerHTML, בלי storage, ובלי הפניה לקוד או למסכים של המערכת');

[$st, $po, $h] = http('POST', 'api.php?action=portal-open', ['token' => $TK], $N);
$poj = json_encode($po, JSON_UNESCAPED_UNICODE);
eq([$st, $po['state'] ?? null, array_column($po['items'] ?? [], 'title'), $po['office'] ?? null, isset($h['set-cookie']), $h['referrer-policy'][0] ?? '', $h['cache-control'][0] ?? '',
    array_values(array_filter(['הסוד', '7777', 'פנימי-בלבד', $cc, 'owner@'], fn($x) => str_contains($poj, $x)))],
    [200, 'open', ['נסח טאבו'], 'משרד קבצים', false, 'no-referrer', 'no-store', []], '07-B1 ב-HTTP: portal-open בלי זהות ← הדרישות של הבקשה בלבד; בלי עוגייה; בלי פרטי התיק');
$inv = fn(array $r) => [$r[0], $r[1]['error']['code'] ?? null, $r[1]['error']['message'] ?? null, isset($r[2]['set-cookie'])];
$bad = str_repeat('Z', 43);
$want = [404, 'portal_invalid', 'הקישור אינו בתוקף. לקבלת קישור חדש, פנו למשרד ששלח אותו.', false];
eq([$inv(http('POST', 'api.php?action=portal-open', ['token' => $bad], $N)), $inv(http('POST', 'api.php?action=portal-open', ['token' => $bad], $X)), $inv(http('POST', 'api.php?action=portal-open&token=' . $TK, [], $N)),
    $inv(http('POST', 'api.php?action=portal-respond', ['token' => $bad, 'requirement' => $rqp, 'kind' => 'comment', 'text' => 'x'], $X)),
    $inv(http('POST', 'api.php?action=portal-file', ['token' => $bad, 'file' => $fp], $X))], [$want, $want, $want, $want, $want],
    '07-B9 / 07-B6 ב-HTTP: אסימון שגוי ← 404 אחיד — גם עם עוגיית session ו-X-CSRF של שמאי, וגם כשהאסימון הנכון בכתובת במקום בגוף');
eq([http('GET', 'api.php?action=portal-open&token=' . $TK, null, $N)[0], http('POST', 'api.php?action=portal-open', ['token' => $TK], $N + ['Origin' => 'https://evil.example'])[1]['error']['code'] ?? null,
    http('POST', 'api.php?action=portal-open', null, $N + ['Content-Type' => 'text/plain'], json_encode(['token' => $TK]))[1]['error']['code'] ?? null,
    http('POST', 'api.php?action=portal-open', ['token' => $TK], $N + ['Sec-Fetch-Site' => 'cross-site'])[1]['error']['code'] ?? null], [405, 'csrf', 'csrf', 'csrf'],
    'CSRF ב-HTTP: GET, מקור זר, או גוף שאינו JSON ← נדחה');

$filesWas = $spy();
$upP = 'api.php?action=portal-upload&requirement=' . $rqp . '&name=' . rawurlencode('<b>נסח.pdf') . '&key=';
$goodPdf = "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%portal\n%%EOF\n";
$oct = ['Content-Type' => 'application/octet-stream'];
$noHdr = http('POST', $upP . 'key-http-0001', null, $N + $oct, $goodPdf);
$asOwner = http('POST', $upP . 'key-http-0002', null, $X + $oct + ['X-Portal-Token' => $bad], $goodPdf);
$inUrl = http('POST', $upP . 'key-http-0003&token=' . $TK . '&portal_token=' . $TK, null, $N + $oct, $goodPdf);
eq([$noHdr[1]['error']['code'] ?? null, $asOwner[1]['error']['code'] ?? null, $inUrl[1]['error']['code'] ?? null, $spy()], ['portal_invalid', 'portal_invalid', 'portal_invalid', $filesWas],
    '07-B9 ב-HTTP: העלאה לפורטל בלי הכותרת, עם זהות של שמאי ואסימון שגוי, או עם האסימון בכתובת ← נדחית, ודבר לא נשמר');
$upOk = http('POST', $upP . 'key-http-0004', null, $N + $oct + ['X-Portal-Token' => $TK], $goodPdf);
$upJs = http('POST', $upP . 'key-http-0005', null, $N + $oct + ['X-Portal-Token' => $TK], "%PDF-1.4\n1 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\n%%EOF\n");
$docs = http('GET', 'api.php?action=case-docs&case=' . $cc, null, $C)[1];
$mine = array_values(array_filter($docs['requirements'] ?? [], fn($r) => $r['pid'] === $rqp))[0] ?? [];
eq([$upOk[0], $upOk[1]['received'] ?? null, isset($upOk[2]['set-cookie']), $upJs[1]['error']['code'] ?? null, $mine['status'] ?? null, $mine['files'][0]['review_state'] ?? null,
    $mine['files'][0]['file']['uploader_kind'] ?? null, $mine['files'][0]['file']['channel'] ?? null, $mine['files'][0]['file']['uploader'] ?? null, $mine['files'][0]['file']['name'] ?? null, count($mine['files'] ?? [])],
    [200, true, false, 'file_active', 'received', 'pending', 'recipient', 'portal', 'נועה נמענת', '<b>נסח.pdf', 1],
    '07-C1 / 07-C3 ב-HTTP: העלאה מהפורטל ← received ו-pending, והמשרד רואה מי העלה ובאיזה ערוץ; קובץ עם תוכן פעיל נדחה');
$pf = http('POST', 'api.php?action=portal-file', ['token' => $TK, 'file' => $upOk[1]['file'] ?? '', 'inline' => true], $N);
$pfC = http('POST', 'api.php?action=portal-file', ['token' => $TK, 'file' => $upOk[1]['file'] ?? '', 'inline' => true], $N + ['Cookie' => 'apr_portal=' . str_repeat('c', 43)]);
eq([$pf[0], $pf[1]['error']['code'] ?? null, $pfC[1]['error']['code'] ?? null, http('POST', 'api.php?action=portal-otp-send', ['token' => $TK], $N)[1]['error']['code'] ?? null,
    http('POST', 'api.php?action=portal-file', ['token' => $TK, 'file' => $fp, 'inline' => true], $N)[1]['error']['code'] ?? null],
    [403, 'portal_otp_required', 'portal_otp_required', 'portal_otp_unavailable', 'portal_otp_required'], '07-B4 ב-HTTP: portal-file בלי מכשיר מאומת (גם עם עוגייה מומצאת) ← 403; לנמען בלי מייל אין קוד');
$rs = http('POST', 'api.php?action=portal-respond', ['token' => $TK, 'requirement' => $rqp, 'kind' => 'no_document', 'text' => '<img src=x onerror=alert(1)>'], $N);
$rqs = http('GET', 'api.php?action=case-requests&case=' . $cc, null, $C)[1]['requests'][0] ?? [];
eq([$rs[0], $rqs['responses'][0]['text'] ?? null, $rqs['use_count'] ?? 0, $rqs['messages'][0]['status'] ?? null, http('GET', 'api.php?action=case-requests&case=' . $cc, null, $U)[0]],
    [200, '<img src=x onerror=alert(1)>', 1, 'unknown', 403], '07-C2 ב-HTTP: התשובה של הנמען נשמרת כפי שנכתבה (המסך מציג אותה דרך esc); משתמש בלי משרד ← 403');
http('POST', 'api.php?action=portal-client-error', ['message' => "boom at /portal/#$TK", 'source' => "http://x/portal/portal.js?t=$TK", 'line' => 3, 'stack' => $TK, 'page' => "#$TK"], $N);
usleep(300000);
$leaks = [];
// (יומן שרת הפיתוח רושם כתובות, והבדיקה למעלה שמה את האסימון בכתובת בכוונה — לכן הוא אינו נסרק כאן; הדף עצמו לעולם אינו שולח אותו בכתובת)
foreach (array_merge(glob("$data/_dev/log.sqlite*") ?: [], glob("$data/*.sqlite*") ?: []) as $f) if (str_contains((string)file_get_contents($f), $TK)) $leaks[] = basename($f);
$logdb = new PDO("sqlite:$data/_dev/log.sqlite");
$prow = $logdb->query("SELECT COUNT(*) FROM log WHERE action LIKE 'portal-%'")->fetchColumn();
$pusr = $logdb->query("SELECT COUNT(*) FROM log WHERE action LIKE 'portal-%' AND action <> 'portal-client-error' AND username <> ''")->fetchColumn();
eq([$leaks, (int)$prow >= 10, (int)$pusr, (int)$logdb->query("SELECT COUNT(*) FROM log WHERE event = 'portal.client_error'")->fetchColumn()], [[], true, 0, 1],
    '§9 האסימון אינו מופיע ביומן הבקשות, ביומן השגיאות או במסד — גם אחרי שגיאת דפדפן שכללה אותו; ובקשות הפורטל נרשמות בלי משתמש');
// קוד חד-פעמי ועוגיית המכשיר, ב-HTTP: נמען עם מייל (X-Dev-Mail — "שליחה" לקובץ בתיקיית הנתונים של הבדיקה)
$M = ['X-Dev-Mail' => '1'];
$mails = fn() => array_map(fn($l) => json_decode($l, true), file("$data/dev-mail.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$pty2 = http('POST', 'api.php?action=case-party-add', ['case' => $cc, 'name' => 'עו"ד עם מייל', 'email' => 'law@firm.example', 'kind' => 'lawyer'], $X)[1]['party'] ?? '';
$rq2 = http('POST', 'api.php?action=requirement-add', ['case' => $cc, 'title' => 'הסכם מכר', 'doc_type' => 'rights'], $X)[1]['requirement'] ?? '';
$c2 = ['case' => $cc, 'party' => $pty2, 'requirements' => [$rq2], 'channel' => 'email', 'contact_ok' => true];
$pv2 = http('POST', 'api.php?action=request-preview', $c2, $X)[1];
$s2 = http('POST', 'api.php?action=request-send', $c2 + ['preview' => $pv2['preview'] ?? '', 'op' => 'op-http-0002'], $X + $M);
$m2 = $mails()[0] ?? [];
preg_match('~/portal/#([A-Za-z0-9_-]{43})\s~', (string)($m2['text'] ?? ''), $tm2);
$TK2 = $tm2[1] ?? '';
eq([$s2[1]['message'] ?? null, array_key_exists('link', $s2[1]) ? $s2[1]['link'] : 'x', $m2['to'] ?? null, $m2['from_name'] ?? null, $m2['reply_to'] ?? null, $TK2 !== '', str_contains(json_encode($s2[1]), $TK2 ?: '-')],
    ['sent', null, 'law@firm.example', 'משרד קבצים', 'owner@example.com', true, false], '07-A2 / 07-D4 ב-HTTP: בקשה במייל — שם המשרד כשולח, "השב" לשמאי, והאסימון במייל בלבד (לא בתשובה למשתמש)');
$up2 = http('POST', 'api.php?action=portal-upload&requirement=' . $rq2 . '&name=contract.pdf&key=key-http-0010', null, $N + $oct + ['X-Portal-Token' => $TK2], "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%two\n%%EOF\n");
http('POST', 'api.php?action=portal-otp-send', ['token' => $TK2], $N + $M);
preg_match('/הקוד שלכם: (\d{6})/', (string)($mails()[1]['text'] ?? ''), $cm);
$wrong = http('POST', 'api.php?action=portal-otp-verify', ['token' => $TK2, 'code' => $cm[1] === '111111' ? '222222' : '111111'], $N);
[$vs, , $vh] = http('POST', 'api.php?action=portal-otp-verify', ['token' => $TK2, 'code' => $cm[1] ?? ''], $N);
$dc = $vh['set-cookie'][0] ?? '';
preg_match('/^apr_portal=([A-Za-z0-9_-]{43});/', $dc, $dm);
check('§5.1 ב-HTTP: קוד נכון ← עוגיית apr_portal: HttpOnly, SameSite=Strict; קוד שגוי ← בלי עוגייה', $vs === 200 && isset($dm[1]) && stripos($dc, 'HttpOnly') !== false && stripos($dc, 'SameSite=Strict') !== false
    && ($wrong[1]['error']['code'] ?? null) === 'portal_otp_wrong' && !isset($wrong[2]['set-cookie']), [$vs, $dc, $wrong[1]]);
$DC = ['Cookie' => 'apr_portal=' . ($dm[1] ?? '')];
[$fs, , $fh, $fraw] = http('POST', 'api.php?action=portal-file', ['token' => $TK2, 'file' => $up2[1]['file'] ?? '', 'inline' => true], $N + $DC);
eq([$fs, $fh['content-type'][0] ?? '', $fh['x-content-type-options'][0] ?? '', str_starts_with($fh['content-security-policy'][0] ?? '', 'sandbox'), str_starts_with($fh['content-disposition'][0] ?? '', 'inline'), str_starts_with($fraw, '%PDF-1.4'),
    http('POST', 'api.php?action=portal-file', ['token' => $TK2, 'file' => $up2[1]['file'] ?? ''], $N + $DC)[1]['error']['code'] ?? null,
    http('POST', 'api.php?action=portal-file', ['token' => $TK2, 'file' => $upOk[1]['file'] ?? '', 'inline' => true], $N + $DC)[1]['error']['code'] ?? null,
    http('POST', 'api.php?action=portal-file', ['token' => $TK2, 'file' => $fp, 'inline' => true], $N + $DC)[1]['error']['code'] ?? null,
    http('POST', 'api.php?action=portal-file', ['token' => $TK, 'file' => $upOk[1]['file'] ?? '', 'inline' => true], $N + $DC)[1]['error']['code'] ?? null,
    http('GET', 'api.php?action=me', null, $N + $DC)[0]],
    [200, 'application/pdf', 'nosniff', true, true, true, 'portal_forbidden', 'not_found', 'not_found', 'portal_otp_required', 401],
    '07-B4 / 07-B2 ב-HTTP: ממכשיר מאומת — הקובץ שלו, עם nosniff ו-sandbox; הורדה בלי download_own, קובץ של נמען אחר, קובץ פנימי, או אותה עוגייה בקישור אחר ← נדחה; העוגייה אינה זהות במערכת');
$leak2 = [];
foreach (array_merge(glob("$data/_dev/log.sqlite*") ?: [], glob("$data/*.sqlite*") ?: []) as $f) { $raw = (string)file_get_contents($f); if (str_contains($raw, $TK2) || str_contains($raw, $dm[1] ?? '-') || preg_match('/"?code_hash"?\W+' . ($cm[1] ?? 'x') . '\b/', $raw)) $leak2[] = basename($f); }
eq($leak2, [], '07-C4 ב-HTTP: האסימון, עוגיית המכשיר והקוד אינם בקובצי המסד או היומן');
$rvk = http('POST', 'api.php?action=request-revoke', ['request' => $snd[1]['request'] ?? ''], $X);
eq([$rvk[0], $inv(http('POST', 'api.php?action=portal-open', ['token' => $TK], $N)), http('POST', $upP . 'key-http-0006', null, $N + $oct + ['X-Portal-Token' => $TK], $goodPdf)[1]['error']['code'] ?? null],
    [200, $want, 'portal_invalid'], '07-B7 ב-HTTP: אחרי ביטול — אותה תשובה כמו לאסימון שלא היה קיים');

// ── ביקורת 07, 🔴1: דף הפורטל דרך השער האמיתי של הפלטפורמה — שום סקריפט זר ──
// השער (platform/gate-prepend.php) מוסיף לכל דף PHP של אפליקציה מחוברת את diag.js, ששולח את הכתובת כולל ה-# (= האסימון).
// שרת הפיתוח הרגיל רץ בלי שער, ולכן כאן שרת שני: עותק זמני של platform/ (קריאה בלבד מהמקור) ושל portal/, עם gate-prepend אמיתי.
$repo = dirname($root);
if (is_file("$repo/platform/gate-prepend.php")) {
    $gt = "$data/gate";
    $cp = function (string $from, string $to) use (&$cp) { @mkdir($to, 0700, true); foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $f) is_dir("$from/$f") ? $cp("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f"); };
    $cp("$repo/platform", "$gt/platform");
    $cp("$root/portal", "$gt/appraisers/portal");
    copy("$root/app.json", "$gt/appraisers/app.json");
    // דף זהה בלי ההערה שמונעת את ההזרקה — מוכיח שהבדיקה באמת מפעילה את ההזרקה, ושה-CSP חוסם גם אז
    file_put_contents("$gt/appraisers/portal/bare.php", preg_replace('~<!--.*?-->\s*~s', '', (string)file_get_contents("$root/portal/index.php")));
    $gport = 0;
    for ($i = 0; $i < 20 && !$gport; $i++) { $p = random_int(40001, 50000); $sk = @stream_socket_server("tcp://127.0.0.1:$p"); if ($sk) { fclose($sk); $gport = $p; } }
    $gproc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$gport", '-d', "auto_prepend_file=$gt/platform/gate-prepend.php", '-t', $gt], [['pipe', 'r'], ['file', "$data/gate.log", 'w'], ['file', "$data/gate.log", 'a']], $gp, $gt, ['PATH' => getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $gport)) break; usleep(100000); }
    $nav = function (string $path) use ($gport): array {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Sec-Fetch-Mode: navigate\r\nSec-Fetch-Dest: document\r\nAccept: text/html", 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]]);
        $body = (string)file_get_contents("http://127.0.0.1:$gport/$path", false, $ctx);
        preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        $csp = '';
        foreach ($http_response_header ?? [] as $h) if (stripos($h, 'content-security-policy:') === 0) $csp = trim(substr($h, 24));
        preg_match_all('~<script\b[^>]*>~i', $body, $tags);
        return [(int)($m[1] ?? 0), $tags[0], $csp, $body, implode("\n", $http_response_header ?? [])];
    };
    [$gs, $gtags, $gcsp, $gbody, $ghead] = $nav('appraisers/portal/');
    preg_match("~script-src 'nonce-([A-Za-z0-9_-]{20,})'~", $gcsp, $nm);
    $nonce = $nm[1] ?? '-';
    [$bs, $btags, $bcsp] = $nav('appraisers/portal/bare.php');
    preg_match("~script-src 'nonce-([A-Za-z0-9_-]{20,})'~", $bcsp, $bm);
    proc_terminate($gproc);
    proc_close($gproc);
    eq([$gs, count($gtags), str_contains($gtags[0] ?? '', 'nonce="' . $nonce . '"') && str_contains($gtags[0] ?? '', 'src="portal.js?v='), stripos($ghead, 'set-cookie') !== false,
        (bool)preg_match("~script-src[^;]*('self'|'unsafe|https?:|\*)~", $gcsp), str_contains($gbody, 'pwa-beacon.js"') || str_contains($gbody, 'diag.js"'), (bool)preg_match('~rel="?manifest~', $gbody)],
        [200, 1, true, false, false, false, false], '🔴1 דף הפורטל דרך gate-prepend.php האמיתי, בלי התחברות: סקריפט אחד בלבד — portal.js עם ה-nonce של הבקשה; בלי diag.js, בלי beacon, בלי manifest ובלי עוגייה');
    eq([$bs, count($btags) > 1 && (bool)preg_grep('~platform/assets/diag\.js~', $btags), count(preg_grep('~nonce=~', $btags)), ($bm[1] ?? '') !== '' && ($bm[1] ?? '') !== $nonce],
        [200, true, 1, true], '🔴1 בלי ההערה שבדף השער אכן מזריק את diag.js (הבדיקה אמיתית) — וגם אז ה-CSP מתיר רק את הסקריפט שנושא nonce, וה-nonce מתחלף בכל בקשה');
    check('🔴1 ב-portal/index.php: ההערה עם שם הסקריפט של הפלטפורמה נמצאת לפני </head>', (bool)preg_match('~<!--[^>]*platform/assets/diag\.js[^>]*-->.*</head>~s', (string)file_get_contents("$root/portal/index.php")));
} else {
    check('🔴1 platform/gate-prepend.php נמצא בריפו (נדרש לבדיקת ההזרקה)', false);
}
// שם הקובץ בכותרת (לא בכתובת — כתובות נרשמות ביומן הגישה)
$rq3 = http('POST', 'api.php?action=requirement-add', ['case' => $cc, 'title' => 'תשריט', 'doc_type' => 'rights'], $X)[1]['requirement'] ?? '';
$c3 = ['case' => $cc, 'party' => $pty, 'requirements' => [$rq3], 'channel' => 'link_copy'];
$sndH = http('POST', 'api.php?action=request-send', $c3 + ['preview' => http('POST', 'api.php?action=request-preview', $c3, $X)[1]['preview'] ?? '', 'op' => 'op-http-0003'], $X);
preg_match('~/portal/#([A-Za-z0-9_-]{43})$~', (string)($sndH[1]['link'] ?? ''), $hm);
$upH = http('POST', 'api.php?action=portal-upload&requirement=' . $rq3 . '&key=key-http-0020', null, $N + $oct + ['X-Portal-Token' => $hm[1] ?? '', 'X-Portal-Name' => rawurlencode('צילום "חדש" <1>.pdf')], "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%hdr\n%%EOF\n");
$docsH = http('GET', 'api.php?action=case-docs&case=' . $cc, null, $C)[1];
$namesH = [];
foreach ($docsH['requirements'] ?? [] as $r) foreach ($r['files'] as $l) $namesH[] = $l['file']['name'];
eq([$upH[0], in_array('צילום "חדש" <1>.pdf', $namesH, true), str_contains((string)file_get_contents("$data/server.log"), rawurlencode('צילום'))], [200, true, false], 'שם הקובץ בהעלאה מהפורטל מגיע בכותרת X-Portal-Name, ואינו ביומן הגישה של השרת');

// ── קבצים סטטיים ונתיבים חסומים ──
eq(http('GET', 'manifest.webmanifest')[0], 200, 'manifest.webmanifest מוגש');
$idx = http('GET', 'index.html')[3];
check('index.html נטען, בעברית מימין לשמאל', str_contains($idx, 'lang="he"') && str_contains($idx, 'dir="rtl"'));
check('אין שגיאות PHP ביומן השרת', !preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', (string)file_get_contents("$data/server.log")), substr((string)file_get_contents("$data/server.log"), -600));

echo $fail ? "  $fail מתוך $n נכשלו\n" : "  ✓ $n בדיקות\n";
exit($fail ? 1 : 0);
