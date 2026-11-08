<?php
// ענן MBE (spec-stage-a 3.7, 9; mbe-cloud/docs/api-guide.md 3, 9): השער, בידוד, "קובצי שיטות" למנהל בלבד. ענן מדומה, בלי רשת.
// php trading-strategies/tests/drives-api.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-drives-api-' . getmypid();
$port = 24000 + getmypid() % 1000;
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
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$uidOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();

// ── השער: רק drives_key פתוח, בלי עוגייה ────────────────
$app = json_decode(file_get_contents(dirname(__DIR__) . '/app.json'), true);
eq([$app['gate'], $app['public']], [true, ['api.php?action=drives_key']], 'app.json: gate, and only drives_key in public');
[$c, $r] = call('none', 'drives_key');
eq([$c, $r['client'] ?? null, $r['keys'][0]['alg'] ?? null, strlen(base64_decode($r['keys'][0]['key'] ?? ''))], [200, 'trading-strategies', 'Ed25519', 32],
    'drives_key without identity and without a cookie → the public key (Ed25519)');
eq(isset($r['keys'][0]['sk']) || str_contains(json_encode($r), 'private'), false, 'only the public key goes out');
foreach (['drives_status', 'drives_token', 'drives_connect', 'drives_disconnect'] as $a) eq(call('none', $a)[0], 401, "$a without identity → 401");
eq(call('none', 'me')[0], 401, 'and the app itself without identity → 401');

// ── חיבור: ה-sub מהשער בלבד ──────────────────────────────
eq(call('alice', 'drives_status')[1]['suite'], ['connected' => false, 'layers' => ['files' => false], 'cloud' => 'https://mbe-plus.com/mini_projects/mbe-cloud/'],
    'not connected — only the files layer (layers.json)');
[$c, $r] = call('alice', 'drives_connect', ['layers' => ['files'], 'sub' => '999']);
parse_str((string)parse_url($r['url'] ?? '', PHP_URL_QUERY), $q);
eq([$c, parse_url($r['url'], PHP_URL_PATH), $q['client'] ?? null, $q['scope'] ?? null, $q['sub'] ?? null],
    [200, '/mini_projects/mbe-cloud/connect.php', 'trading-strategies', 'files', '101'], 'connect URL: our client, files only, and the sub from the gate — a sub in the body is ignored');
eq(call('alice', 'drives_connect', ['layers' => ['libs']])[0], 400, 'a layer we did not declare → refused');
parse_str((string)parse_url(call('bob', 'drives_connect', ['layers' => ['files']])[1]['url'], PHP_URL_QUERY), $qb);
eq($qb['sub'] ?? null, '102', 'bob\'s request carries bob\'s sub');

// ── בידוד: אסימון רק לחיבור של המשתמש עצמו ───────────────
$ddb = new PDO('sqlite:' . "$tmp.sqlite.drives/drives.sqlite");
$ins = $ddb->prepare("INSERT INTO conns (user, connection, name, cloud_id, scope, created_at, sub) VALUES (?, ?, ?, 'cloud-1', '[\"files\"]', '2026-11-08', ?)");
$ins->execute([(string)$uidOf('101'), 'c_101', 'אליס', '101']);
$t = call('alice', 'drives_token')[1];
eq([$t['connected'], $t['token'], $t['api']], [true, 'tok-for-c_101', 'https://mbe-plus.com/mini_projects/mbe-cloud/api.php'], 'alice gets a short token for her own connection');
eq(call('alice', 'drives_status')[1]['suite']['layers'], ['files' => true], 'connected → 📁 files on');
eq(call('bob', 'drives_token', ['connection' => 'c_101'])[1], ['connected' => false], 'bob gets no token — not even when he names alice\'s connection');
// בוב שמחזיק (איכשהו) את החיבור של אליס — הענן דוחה, כי ה-sub מהשער הוא של בוב
$ins->execute([(string)$uidOf('102'), 'c_101', 'בוב', '102']);
eq(call('bob', 'drives_token')[0], 403, 'a connection of another account → the cloud refuses (account_mismatch); bob cannot write into alice\'s drive');
eq(str_contains(json_encode(call('bob', 'drives_token')[1]), 'tok-for'), false, 'and no token leaks to bob');
eq(file_exists("$tmp.sqlite.drives/drives.sqlite") && !file_exists(dirname(__DIR__) . '/tests/drives.sqlite'), true, 'drives.sqlite is in the data dir');

// ── 📚 קובצי שיטות: מנהל בלבד, בשרת ─────────────────────
eq(call('alice', 'admin_method_files')[0], 403, 'method files → 403 for a regular user (server-side, not just hidden)');
eq(call('alice', 'admin_method_files_folder', ['id' => 'n_abc', 'path' => 'x'])[0], 403, 'choosing the folder → 403 for a regular user');
eq(call('owner', 'admin_method_files')[1]['folder'], null, 'admin: no folder yet');
eq(call('owner', 'admin_method_files_folder', ['id' => '../../etc', 'path' => 'x'])[0], 422, 'folder id is validated');
eq(call('owner', 'admin_method_files_folder', ['id' => 'n_5f2a', 'path' => 'אסטרטגיות למסחר/שיטות'])[0], 200, 'admin chooses the folder');
$f = call('owner', 'admin_method_files')[1]['folder'];
eq([$f['id'], $f['path']], ['n_5f2a', 'אסטרטגיות למסחר/שיטות'], 'only the id and the path are stored — no file content here');
$carol = $db->query("SELECT id FROM users WHERE platform_sub = '103'")->fetchColumn();
call('carol', 'me');
$carol = $uidOf('103');
call('owner', 'admin_role_add', ['user_id' => $carol]);
$tokens['carol'] = call('carol', 'me')[1]['csrf'];
eq(call('carol', 'admin_method_files')[0], 200, 'an additional admin → 200');
call('owner', 'admin_role_remove', ['user_id' => $carol]);
eq(call('carol', 'admin_method_files')[0], 403, 'revoked admin → 403 immediately');
eq(count(array_filter(call('owner', 'admin_state')[1]['log'], fn($l) => $l['action'] === 'method_files_folder')), 1, 'logged in admin_log');
$src = (string)file_get_contents(dirname(__DIR__) . '/api.php');
preg_match_all("/case '([a-z_]*_files?[a-z_]*)'/", $src, $mm);
eq($mm[1], ['admin_method_files', 'admin_method_files_folder'], 'the only "file" actions are the folder choice — no endpoint of ours serves file content');

// ── הפריסה לא דורסת: data/* מוחרג ───────────────────────
$deploy = (string)file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/deploy.yml');
eq(str_contains($deploy, "--exclude='/trading-strategies/data/*'"), true, 'deploy.yml excludes trading-strategies/data/* (drives.sqlite with the private key)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
