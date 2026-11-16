<?php
// A2 — מחוברת או מנותקת (app-rules 2; platform/docs/sso.md, auth.md). מקצה לקצה מול php -S:
// כל בדיקות הזהות החתומה (sso.md §2), state חד-פעמי וקשור לדפדפן, jti, מעבר בין המצבים, איחוד לפי מייל,
// והמנהל הראשי במצב מנותק. "המעטפת" — קבצים (TS_HTTP_FIXTURES); המפתח — זוג שנוצר כאן.
// php trading-strategies/tests/sso-api.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-sso-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp . '/fx', 0700, true);
$db = $tmp . '/app.sqlite';
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$base = "http://127.0.0.1:$port";
$app = "$base/trading-strategies/";
$iss = "$base/";
$ret = $app . 'api.php?action=sso_callback';

$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
$other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$b64u = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
file_put_contents("$tmp/fx/key.json", json_encode(['alg' => 'Ed25519', 'key' => $b64u(sodium_crypto_sign_publickey($pair)), 'issuer' => $iss]));
file_put_contents("$tmp/fx/config.json", json_encode(['app' => 'trading-strategies', 'connected' => false, 'login' => ['platform' => true, 'password' => false, 'google' => false]]));
file_put_contents("$tmp/fx/index.json", json_encode([
    ['match' => '~/platform/sso\.php\?action=key$~', 'file' => 'key.json'],
    ['match' => '~/platform/sso\.php\?action=config&client=trading-strategies$~', 'file' => 'config.json'],
]));

$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
    ['TS_DB_PATH' => $db, 'TS_APP_URL' => $app, 'TS_HTTP_FIXTURES' => "$tmp/fx", 'TS_HTTP_LOG' => "$tmp/http.log"] + getenv());
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (array_merge(glob("$tmp/fx/*"), glob("$tmp/*")) as $f) if (is_file($f)) @unlink($f);
    @rmdir("$tmp/fx");
    @rmdir($tmp);
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

/**
 * בקשה בלי מעקב אחרי הפניות. $c — "דפדפן": מערך עוגיות שמתעדכן מ-Set-Cookie.
 * מחזיר [קוד, JSON או גוף, Location].
 */
function req(array &$c, string $action, array $query = [], ?array $body = null, string $as = 'none', string $plat = 'detached'): array
{
    global $port;
    $h = ["X-Dev-As: $as", "X-Dev-Platform: $plat"];
    if ($c) $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($c), $c));
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($c['@csrf'] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'follow_location' => 0]]);
    $raw = file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    $code = (int)explode(' ', $http_response_header[0])[1];
    $loc = '';
    foreach ($http_response_header as $line) {
        if (preg_match('/^Location: (.*)$/i', $line, $m)) $loc = trim($m[1]);
        if (preg_match('/^Set-Cookie: (ts_session|ts_sso)=([^;]*)/i', $line, $m)) {
            if ($m[2] === '' || $m[2] === 'deleted') unset($c[$m[1]]); else $c[$m[1]] = $m[2];
        }
    }
    $json = json_decode((string)$raw, true);
    if (is_array($json) && isset($json['csrf'])) $c['@csrf'] = $json['csrf'];
    return [$code, $json ?? $raw, $loc];
}

function cookies(array $c): array
{
    return array_filter($c, fn($k) => $k[0] !== '@', ARRAY_FILTER_USE_KEY);
}

/** זהות חתומה, כמו platform/lib/sso.php. $o דורס שדות; $key — מפתח אחר (חתימה לא תקינה). */
function token(string $state, array $o = [], ?string $key = null): string
{
    global $sk, $iss, $ret, $b64u;
    $now = time();
    $p = $b64u(json_encode($o + ['iss' => $iss, 'aud' => 'trading-strategies', 'sub' => '201', 'name' => 'דנה', 'email' => 'dana@test.local',
        'email_verified' => true, 'state' => $state, 'return' => $ret, 'iat' => $now, 'exp' => $now + 120, 'jti' => bin2hex(random_bytes(8))],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $p . '.' . $b64u(sodium_crypto_sign_detached($p, $key ?? $sk));
}

/** sso_start בדפדפן $c: מחזיר את ה-state מההפניה. */
function start(array &$c): string
{
    [, , $loc] = req($c, 'sso_start');
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    return (string)($q['state'] ?? '');
}

/** כניסה מלאה: start + callback עם זהות ($o). מחזיר את ה-Location של ה-callback. */
function login(array &$c, array $o = []): string
{
    $s = start($c);
    return req($c, 'sso_callback', ['state' => $s, 'token' => token($s, $o)])[2];
}

// ── 1. מחוברת: אין זהות → 401, בלי מסך כניסה ──
$b = [];
[$code, $j] = req($b, 'me', [], null, 'none', 'connected');
eq([$code, $j['login']['mode'] ?? null, $j['login']['platform'] ?? null], [401, 'connected', false], 'connected, no identity → 401, no login screen');
[$code, $j] = req($b, 'login_config', [], null, 'alice', 'connected');
eq($j['mode'] ?? null, 'connected', 'login_config with gate identity → connected');
[$code, , $loc] = req($b, 'sso_start', [], null, 'none', 'connected');
eq([$code, $loc, isset($b['ts_sso'])], [302, '/trading-strategies/', false], 'connected: sso_start does not start (back to app path)');

// ── 2. לא עונה → 503, נכשלים סגור ──
[$code, $j] = req($b, 'me', [], null, 'none', 'unavailable');
eq([$code, $j['login']['mode'] ?? null], [503, 'unavailable'], 'platform unavailable → 503');
[$code, $j] = req($b, 'strategies', [], null, 'none', 'unavailable');
eq($code, 503, 'unavailable → no data');

// ── 3. מנותקת: מסך כניסה, והזרימה ──
[$code, $j] = req($b, 'me');
eq([$code, $j['login']['mode'] ?? null, $j['login']['platform'] ?? null], [401, 'detached', true], 'detached, no session → 401 + login screen');
[$code, , $loc] = req($b, 'sso_start');
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
eq([$code, strtok($loc, '?'), $q['client'] ?? null, $q['return'] ?? null, (bool)preg_match('/^[a-f0-9]{32}$/', $q['state'] ?? ''), ($b['ts_sso'] ?? '') === ($q['state'] ?? '-')],
    [302, "{$base}/platform/sso.php", 'trading-strategies', $ret, true, true], 'sso_start → platform with client/state/return; state in cookie');
$state = $q['state'];
$tok = token($state);
[$code, , $loc] = req($b, 'sso_callback', ['state' => $state, 'token' => $tok]);
eq([$code, $loc, isset($b['ts_session']), isset($b['ts_sso'])], [302, '/trading-strategies/', true, false], 'valid identity → session, back to app');
[$code, $me] = req($b, 'me');
eq([$code, $me['user']['display_name'] ?? null, $me['login_via'] ?? null, $me['is_primary_admin'] ?? null], [200, 'דנה', 'sso', false], 'me after SSO');
[$code, $st] = req($b, 'strategies');
eq($code, 200, 'detached session reaches data');
// ה-session לא תלוי ב-X-Dev-As / בשום דבר מהדפדפן מלבד העוגייה
[$code, $me2] = req($b, 'me', [], null, 'nobody-xyz');
eq([$code, $me2['user']['display_name'] ?? null], [200, 'דנה'], 'identity comes only from the session');

// אותה זהות פעמיים (אותו state) → נדחית: ה-state כבר נוצל
$b2 = $b;
$b2['ts_sso'] = $state;
unset($b2['ts_session']);
[, , $loc] = req($b2, 'sso_callback', ['state' => $state, 'token' => $tok]);
eq([$loc, isset($b2['ts_session'])], ['/trading-strategies/#/login/invalid', false], 'state is single-use');

// ── 4. כל בדיקה של sso.md §2 ──
$cases = [
    'bad signature'   => fn($s) => token($s, [], $other),
    'aud of other app' => fn($s) => token($s, ['aud' => 'appraisers']),
    'bad iss'         => fn($s) => token($s, ['iss' => 'https://evil.example/']),
    'state in token ≠ state' => fn($s) => token($s, ['state' => str_repeat('a', 32)]),
    'bad return'      => fn($s) => token($s, ['return' => 'https://evil.example/cb']),
    'expired'         => fn($s) => token($s, ['exp' => time() - 300]),
    'exp not int'     => fn($s) => token($s, ['exp' => (string)(time() + 100)]),
    'no jti'          => fn($s) => token($s, ['jti' => '']),
    'sub not digits'  => fn($s) => token($s, ['sub' => 'abc']),
    'garbage token'   => fn($s) => 'xyz.abc',
    'no token'        => fn($s) => '',
];
foreach ($cases as $what => $mk) {
    $c = [];
    $s = start($c);
    [$code, , $loc] = req($c, 'sso_callback', ['state' => $s, 'token' => $mk($s)]);
    eq([$code, $loc, isset($c['ts_session'])], [302, '/trading-strategies/#/login/invalid', false], "rejected: $what");
}
// jti שכבר נוצל — גם עם state חדש ותקין
$c = [];
$s = start($c);
$jtiTok = token($s, ['jti' => 'same-jti-1']);
req($c, 'sso_callback', ['state' => $s, 'token' => $jtiTok]);
eq(isset($c['ts_session']), true, 'first use of jti ok');
$c = [];
$s = start($c);
[, , $loc] = req($c, 'sso_callback', ['state' => $s, 'token' => token($s, ['jti' => 'same-jti-1'])]);
eq([$loc, isset($c['ts_session'])], ['/trading-strategies/#/login/invalid', false], 'replayed jti rejected');
// state שלא מהדפדפן הזה: התוקף התחיל כניסה, והקורבן מקבל את הקישור
$att = [];
$s = start($att);
$victim = [];
[, , $loc] = req($victim, 'sso_callback', ['state' => $s, 'token' => token($s)]);
eq([$loc, isset($victim['ts_session'])], ['/trading-strategies/#/login/invalid', false], 'state not bound to this browser → rejected');
// state מומצא, עם עוגייה תואמת — לא נוצר אצלנו
$c = ['ts_sso' => str_repeat('b', 32)];
[, , $loc] = req($c, 'sso_callback', ['state' => str_repeat('b', 32), 'token' => token(str_repeat('b', 32))]);
eq([$loc, isset($c['ts_session'])], ['/trading-strategies/#/login/invalid', false], 'state never issued → rejected');
// המשתמש ביטל במעטפת
$c = [];
$s = start($c);
[, , $loc] = req($c, 'sso_callback', ['state' => $s, 'error' => 'access_denied']);
eq([$loc, isset($c['ts_session'])], ['/trading-strategies/#/login/cancelled', false], 'cancelled at platform');
// במצב מחובר ה-callback לא פותח session גם עם זהות תקינה
$c = [];
$s = start($c);
[, , $loc] = req($c, 'sso_callback', ['state' => $s, 'token' => token($s)], null, 'none', 'connected');
eq([$loc, isset($c['ts_session'])], ['/trading-strategies/', false], 'connected: callback opens nothing');

// ── 5. מעבר בין המצבים ──
// session מהשער לא עובר למצב מנותק
$g = [];
[$code] = req($g, 'me', [], null, 'alice', 'connected');
eq([$code, isset($g['ts_session'])], [200, true], 'gate session');
[$code] = req($g, 'me', [], null, 'none', 'detached');
eq($code, 401, 'gate session is not a detached login');
// מחוברת, ואין זהות → ה-session של הכניסה המאוחדת נמחק (יציאה מהפלטפורמה = יציאה מכאן)
$x = [];
login($x, ['sub' => '202', 'email' => 'x@test.local', 'name' => 'איקס']);
$keep = $x;
[$code] = req($x, 'me', [], null, 'none', 'connected');
eq([$code, isset($x['ts_session'])], [401, false], 'connected without identity drops the session');
[$code] = req($keep, 'me');
eq($code, 401, 'the dropped session is gone from the db');
// מחוברת עם זהות של מישהו אחר → ה-session של האפליקציה מתחלף אליו
$y = [];
login($y, ['sub' => '203', 'email' => 'y@test.local', 'name' => 'וויי']);
[$code, $me] = req($y, 'me', [], null, 'bob', 'connected');
eq([$code, $me['user']['display_name'] ?? null, $me['login_via'] ?? null], [200, 'בוב', 'gate'], 'gate identity wins over an SSO session');

// ── 6. יציאה ──
$z = [];
login($z, ['sub' => '204', 'email' => 'z@test.local', 'name' => 'זד']);
req($z, 'me');
[$code, $j] = req($z, 'logout', [], []);
eq([$code, $j['mode'] ?? null, isset($z['ts_session'])], [200, 'detached', false], 'logout');
[$code] = req($z, 'me');
eq($code, 401, 'after logout → login screen');
$z2 = [];
login($z2, ['sub' => '204', 'email' => 'z@test.local', 'name' => 'זד']);
[$code] = req($z2, 'logout', [], [], 'none', 'detached');
eq($code, 403, 'logout needs CSRF');

// ── 7. איחוד לפי מייל: משתמש קיים, אותו מייל, sub אחר → אותו חשבון ──
$k = [];
[, $carol] = req($k, 'me', [], null, 'carol', 'connected');
$k2 = $k;
req($k2, 'strategy_save', [], ['name' => 'השיטה של קרול'], 'carol', 'connected');
$m = [];
login($m, ['sub' => '903', 'email' => 'Carol@Test.Local', 'name' => 'קרול']);
[$code, $me] = req($m, 'me');
[, $list] = req($m, 'strategies');
eq([$code, $me['user']['display_name'] ?? null, array_column($list['strategies'] ?? [], 'name')], [200, 'קרול', ['השיטה של קרול']],
    'same email → same account and data');
$pdo = new PDO('sqlite:' . $db);
eq((int)$pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) = 'carol@test.local'")->fetchColumn(), 1, 'no duplicate account');
eq($pdo->query("SELECT platform_sub FROM users WHERE lower(email) = 'carol@test.local'")->fetchColumn(), '903', 'account linked to the new sub');
eq((int)$pdo->query("SELECT COUNT(*) FROM admin_log WHERE action = 'user_merge_email'")->fetchColumn(), 1, 'merge is logged');
// בלי מייל → חשבון חדש, לא איחוד
$nm = [];
login($nm, ['sub' => '905', 'email' => null, 'name' => 'בלי מייל']);
[$code, $me] = req($nm, 'me');
eq([$code, $me['user']['display_name'] ?? null], [200, 'בלי מייל'], 'no email → new account');

// ── 8. המנהל הראשי במצב מנותק: מי שהשער אמר עליו כך ──
$o = [];
req($o, 'me', [], null, 'owner', 'connected');            // השער: platform_admin = true → נשמר
$os = [];
login($os, ['sub' => '100', 'email' => 'owner@test.local', 'name' => 'בעל המערכת']);
[$code, $me] = req($os, 'me');
eq([$code, $me['is_primary_admin'] ?? null, $me['is_admin'] ?? null], [200, true, true], 'detached: owner is primary admin (stored flag)');
[$code] = req($os, 'dev-items');
eq($code, 200, 'detached: owner reaches DevLoop');
$as = [];
login($as, ['sub' => '101', 'email' => 'alice@test.local', 'name' => 'אליס']);
[$code, $me] = req($as, 'me');
[$code2] = req($as, 'dev-items');
eq([$me['is_primary_admin'] ?? null, $code2], [false, 403], 'detached: others are not');
eq((int)$pdo->query("SELECT platform_admin FROM users WHERE platform_sub = '100'")->fetchColumn(), 1, 'SSO login does not reset the stored flag');

// ── 9. המצב מהמעטפת עצמה (live): config + מטמון ──
$l = [];
[$code, $j] = req($l, 'login_config', [], null, 'none', 'live');
eq([$code, $j['mode'] ?? null], [200, 'detached'], 'live: config says detached');
file_put_contents("$tmp/fx/config.json", json_encode(['app' => 'trading-strategies', 'connected' => true, 'login' => []]));
[, $j] = req($l, 'login_config', [], null, 'none', 'live');
eq($j['mode'] ?? null, 'detached', 'live: config cached (60s)');
$log = array_map(fn($x) => json_decode($x, true)['url'], array_filter(explode("\n", (string)@file_get_contents("$tmp/http.log"))));
eq(count(array_filter($log, fn($u) => str_contains($u, 'action=config'))), 1, 'config asked once');
eq(count(array_filter($log, fn($u) => str_contains($u, 'action=key'))) >= 1, true, 'public key fetched from the platform');
eq(count(array_filter($log, fn($u) => !str_starts_with($u, "$base/platform/sso.php?"))), 0, 'only the platform is asked — no URL from the request');

// ── 10. granted:false מהשער אינו זהות (כתובת signed_in) ──
putenv('TS_DB_PATH');
define('TS_DB_PATH', $tmp . '/unit.sqlite');
require dirname(__DIR__) . '/lib/auth.php';
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '7', 'name' => 'a', 'granted' => false]);
eq(ts_identity(), null, 'granted:false → no identity');
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '7', 'name' => 'a', 'granted' => true]);
eq(ts_identity()['sub'] ?? null, '7', 'granted:true → identity');
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '7x', 'name' => 'a']);
eq(ts_identity(), null, 'sub must be digits');

echo $fail ? "\n$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
