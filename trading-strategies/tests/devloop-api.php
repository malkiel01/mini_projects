<?php
// DevLoop (A1, PROTOCOL 7) מקצה לקצה: עורך ראשי הוא המפתח; עורך משני — עוקב, משתמש — 🐞 בלבד (ר2); קודים TS-; 🐞 עם הקשר;
// אימות; אסימון של Claude בלי עוגייה (devapi-…); אסימון שמת כשמי שיצר אותו כבר לא המנהל הראשי; צילום; היומן המחובר;
// והנתונים — רק בתיקייה של DevLoop (בשרת: data/_dev, שמוחרגת מ-rsync).
// php trading-strategies/tests/devloop-api.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

$tmp = sys_get_temp_dir() . '/ts-devloop-' . getmypid() . '-' . bin2hex(random_bytes(4));
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, ['TS_DB_PATH' => "$tmp.sqlite"] + getenv());
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) {
        if (is_dir($f)) { array_map('unlink', glob("$f/*") ?: []); @rmdir($f); } else @unlink($f);
    }
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);

$jar = []; $tokens = [];
/** [קוד, JSON, כותרות]. $raw — גוף גולמי (צילום); $as = 'none' — בלי זהות מהשער */
function call(string $as, string $action, ?array $body = null, array $query = [], array $headers = [], ?string $raw = null, string $type = 'application/json'): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as", ...$headers];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    $post = $body !== null || $raw !== null;
    if ($post) { $h[] = "Content-Type: $type"; if (!preg_grep('/^X-(CSRF|Dev-Token):/i', $headers)) $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $post ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $raw ?? ($body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : ''), 'ignore_errors' => true]]);
    $out = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($out, true) ?? $out, $http_response_header];
}

foreach (['alice', 'carol', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$carol = (int)$db->query("SELECT id FROM users WHERE platform_sub = '103'")->fetchColumn();
eq(call('owner', 'admin_role_add', ['user_id' => $carol])[0], 200, 'carol becomes an admin (not the primary one)');

// ── מיגרציה 13: עמודה חדשה בלבד; מי המפתח — לפי השער ──
eq((int)$db->query('PRAGMA user_version')->fetchColumn() >= 13, true, 'migration 13 ran');
eq(array_column($db->query('SELECT platform_sub, platform_admin FROM users ORDER BY platform_sub')->fetchAll(), 'platform_admin', 'platform_sub'),
    ['100' => 1, '101' => 0, '103' => 0], 'users.platform_admin follows the gate');

// ── רק המפתח (המנהל הראשי) ──
[$c, $me] = call('owner', 'me');
eq([$c, $me['is_primary_admin'], is_string($me['version'] ?? null) && $me['version'] !== ''], [200, true, true], 'owner me: primary admin + version');
[$c, $r] = call('owner', 'dev-items');
eq($c, 200, 'owner dev-items');
eq(count(array_filter($r['items'] ?? [], fn($x) => str_starts_with($x['code'], 'TS-'))) > 0, true, 'items from dev/open-items.json (TS-)');
// ר2 (24.2): עורך משני (carol) — צפייה, תגובה ופתיחה, בלי אסימונים; משתמש (alice) — 🐞 בלבד. הטבלה המלאה — tests/roles-api.php
foreach (['alice', 'carol'] as $u) eq(call($u, 'dev-tokens')[0], 403, "$u dev-tokens → 403");
eq([call('alice', 'dev-items')[0], call('alice', 'dev-log')[0]], [403, 403], 'alice (user) dev-items / dev-log → 403');
eq([call('carol', 'dev-items')[0], call('carol', 'dev-log')[0]], [200, 200], 'carol (editor) sees items and the log');
eq(call('none', 'dev-items')[0], 401, 'no identity → 401');
eq(call('owner', 'dev-report', ['title' => 'x'], [], ['X-CSRF: wrong'])[0], 403, 'dev-report without CSRF → 403');
eq(call('owner', 'dev-report', null, [], [], 'title=x', 'application/x-www-form-urlencoded')[0], 415, 'dev-report not JSON → 415');

// ── 🐞 דיווח: מטלה חדשה בקוד TS- הבא, עם ההקשר ──
$file = json_decode((string)file_get_contents(dirname(__DIR__) . '/dev/open-items.json'), true);
$maxFile = max(array_map(fn($x) => (int)substr($x['code'], 3), $file['items']));
[$c, $rep] = call('owner', 'dev-report', ['title' => 'הגרף לא נטען', 'detail' => 'לחצתי על BTC', 'context' => ['url' => '#/chart/x', 'screen' => '390×860', 'version' => '1.7',
    'requests' => [['action' => 'chart_refresh', 'status' => 500, 'req' => 'abc']], 'secret' => 'לא נשמר']]);
eq($c, 200, 'dev-report');
eq($rep['code'] ?? null, sprintf('TS-%03d', $maxFile + 1), 'report gets the next TS- code');
$item = current(array_filter(call('owner', 'dev-items')[1]['items'], fn($x) => $x['code'] === $rep['code']));
eq([$item['kind'], $item['status'], $item['context']['url'] ?? null, isset($item['context']['secret'])], ['bug', 'open', '#/chart/x', false], 'report: bug, open, context kept (only known keys)');

// ── שיח ואימות ──
eq(call('owner', 'dev-comment', ['code' => $rep['code'], 'body' => 'עוד פרט'])[0], 200, 'dev-comment');
eq(call('owner', 'dev-task-set', ['code' => $rep['code'], 'status' => 'verify'])[0], 200, 'dev-task-set verify');
eq(call('owner', 'dev-verify', ['code' => $rep['code'], 'ok' => true])[0], 200, 'dev-verify ok');
$item = current(array_filter(call('owner', 'dev-items')[1]['items'], fn($x) => $x['code'] === $rep['code']));
eq($item['status'], 'closed', 'verified → closed');

// ── אסימון של Claude: בלי עוגייה ובלי זהות ──
[$c, $tk] = call('owner', 'dev-token-create', ['label' => 'Claude']);
eq([$c, (bool)preg_match('/^tsd_[0-9a-f]{48}$/', $tk['token'] ?? '')], [200, true], 'token tsd_…');
$T = 'X-Dev-Token: ' . $tk['token'];
[$c, $feed] = call('none', 'devapi-feed', null, ['all' => 1], [$T]);
eq([$c, $feed['next_code'] ?? null], [200, sprintf('TS-%03d', $maxFile + 2)], 'devapi-feed with token, no identity');
eq(call('none', 'devapi-feed', null, ['all' => 1])[0], 401, 'devapi-feed without token → 401');
eq(call('none', 'devapi-feed', null, ['all' => 1], ['X-Dev-Token: tsd_' . str_repeat('0', 48)])[0], 401, 'unknown token → 401');
[$c] = call('none', 'devapi-comment', ['code' => $rep['code'], 'body' => 'בדקתי', 'evidence' => [['kind' => 'pr', 'ref' => '#1', 'url' => '']]], [], [$T]);
eq($c, 200, 'devapi-comment');
$item = current(array_filter(call('owner', 'dev-items')[1]['items'], fn($x) => $x['code'] === $rep['code']));
eq([end($item['comments'])['author'], in_array('#1', array_column($item['evidence'], 'ref'), true)], ['claude', true], 'Claude comment + evidence');

// ── צילום: המפתח בעוגייה, Claude באסימון; הקובץ בתיקייה של DevLoop ──
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
[$c, $att] = call('owner', 'dev-attach', null, ['code' => $rep['code'], 'name' => 'a.png'], [], $png, 'image/png');
eq($c, 200, 'dev-attach (raw body, CSRF)');
eq(call('owner', 'dev-attach', null, ['code' => $rep['code']], ['X-CSRF: wrong'], $png, 'image/png')[0], 403, 'dev-attach without CSRF → 403');
eq(call('alice', 'dev-attach', null, ['code' => $rep['code']], [], $png, 'image/png')[0], 403, 'alice dev-attach → 403');
[$c, , $hd] = call('none', 'devapi-file', null, ['id' => $att['id']], [$T]);
eq([$c, (bool)preg_grep('#^Content-Type: image/png#i', $hd)], [200, true], 'devapi-file');
eq(count(glob("$tmp.sqlite.dev/*.png") ?: []), 1, 'the file is in the DevLoop dir (server: data/_dev)');

// ── היומן המחובר: כל בקשה נרשמת, עם מזהה בקשה ──
[, , $hd] = call('owner', 'me');
eq((bool)preg_grep('/^X-Request-Id: /i', $hd), true, 'X-Request-Id on every response');
eq(call('alice', 'devloop-client-error', ['kind' => 'error', 'message' => 'boom', 'page' => '#/market'])[0], 200, 'client error from any user → log');
[$c, $log] = call('owner', 'dev-log', null, ['q' => 'boom']);
eq([$c, $log['rows'][0]['event'] ?? null], [200, 'client.error'], 'dev-log shows the JS error');
[, $log] = call('owner', 'dev-log', null, ['q' => 'dev-items']);
eq(count($log['rows']) > 0, true, 'requests are logged');
eq(is_file("$tmp.sqlite.dev/log.sqlite"), true, 'log.sqlite in the DevLoop dir');

// ── אסימון מת כשמי שיצר אותו כבר לא המנהל הראשי (השער אומר אחרת בכניסה הבאה) ──
$db->exec("UPDATE users SET platform_admin = 0 WHERE platform_sub = '100'");
eq(call('none', 'devapi-feed', null, [], [$T])[0], 401, 'token dies with the developer');
call('owner', 'me');   // הכניסה הבאה — השער אומר platform_admin: true
eq(call('none', 'devapi-feed', null, [], [$T])[0], 200, 'back after the gate says so');
eq(call('owner', 'dev-token-revoke', ['id' => 1])[0], 200, 'revoke');
eq(call('none', 'devapi-feed', null, [], [$T])[0], 401, 'revoked → 401');

// ── שום נתון של DevLoop מחוץ לתיקייה שלו ולמסד ──
$extra = array_values(array_filter(glob("$tmp*") ?: [], fn($f) => !preg_match('/\.sqlite(-wal|-shm|\.cache.*|\.key|\.dev)?$/', $f)));
eq($extra, [], 'no stray files');

echo $fail ? "\n$fail/$n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
