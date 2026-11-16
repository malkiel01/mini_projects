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
    ['match' => '~/platform/provision\.php\?action=grant_status$~', 'file' => 'grant.json'],
]));
/** מה grant_status עונה: true / false / null (המעטפת לא עונה — 500) */
function grant(?bool $allowed): void
{
    global $tmp;
    $idx = json_decode(file_get_contents("$tmp/fx/index.json"), true);
    $idx[2]['status'] = $allowed === null ? 500 : 200;
    file_put_contents("$tmp/fx/index.json", json_encode($idx));
    file_put_contents("$tmp/fx/grant.json", json_encode(['allowed' => (bool)$allowed]));
}
grant(true);

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
    $h = ["X-Dev-As: $as", "X-Dev-Platform: $plat", 'X-Dev-Password: ' . ($GLOBALS['PW'] ?? '0'), ...($GLOBALS['EXTRA'] ?? [])];
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

// ── 2. unknown (המעטפת לא עונה): אין session → אין כניסה ──
[$code, $j] = req($b, 'login_config', [], null, 'none', 'unknown');
eq([$j['mode'] ?? null, $j['platform'] ?? null, $j['password'] ?? null], ['unknown', false, false], 'unknown: no login options');
[$code, $j] = req($b, 'strategies', [], null, 'none', 'unknown');
eq($code, 401, 'unknown, no session → no data');

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
eq($code, 401, 'ק11: gate session does not continue when detached');
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
eq([$code, isset($z['ts_session'])], [200, false], 'logout');
[$code] = req($z, 'me');
eq($code, 401, 'after logout → login screen');
$z2 = [];
login($z2, ['sub' => '204', 'email' => 'z@test.local', 'name' => 'זד']);
[$code] = req($z2, 'logout', [], [], 'none', 'detached');
eq($code, 403, 'logout needs CSRF');

// ── 7. איחוד (20.1 ה'): אותו מייל עם sub אחר → אין כניסה, account_conflict; בלי מחיקה ובלי כפילות ──
$pdo = new PDO('sqlite:' . $db);
$k = [];
req($k, 'me', [], null, 'carol', 'connected');
$usersBefore = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$m = [];
$loc = login($m, ['sub' => '903', 'email' => 'Carol@Test.Local', 'name' => 'קרול']);
eq([$loc, isset($m['ts_session'])], ['/trading-strategies/#/login/conflict', false], 'same email, other sub → no login (SSO)');
eq((int)$pdo->query("SELECT COUNT(*) FROM admin_log WHERE action = 'account_conflict'")->fetchColumn(), 1, 'account_conflict logged');
eq([(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), $pdo->query("SELECT platform_sub FROM users WHERE lower(email) = 'carol@test.local'")->fetchColumn()],
    [$usersBefore, '103'], 'no new row, carol keeps her sub');
// email_verified: false + אותו מייל עם sub אחר — גם התנגשות (2.23, ק6)
$m2 = [];
eq(login($m2, ['sub' => '904', 'email' => 'carol@test.local', 'email_verified' => false]), '/trading-strategies/#/login/conflict', 'unverified email of another account → conflict');
// מייל לא מאומת, בלי חשבון אחר → חשבון חדש
$nv = [];
login($nv, ['sub' => '906', 'email' => 'new@test.local', 'email_verified' => false, 'name' => 'חדש']);
[$code, $me] = req($nv, 'me');
eq([$code, $me['user']['display_name'] ?? null], [200, 'חדש'], 'unverified, no match → new account');
// בלי מייל → חשבון חדש
$nm = [];
login($nm, ['sub' => '905', 'email' => null, 'name' => 'בלי מייל']);
[$code, $me] = req($nm, 'me');
eq([$code, $me['user']['display_name'] ?? null], [200, 'בלי מייל'], 'no email → new account');
eq((int)$pdo->query('SELECT COUNT(*) FROM (SELECT platform_sub FROM users GROUP BY platform_sub HAVING COUNT(*) > 1)')->fetchColumn(), 0, 'platform_sub unique');
// מחוברת: התנגשות מהשער → 403, ולא נפתח חשבון
$gc = [];
$pdo->exec("UPDATE users SET email = 'dave@test.local' WHERE platform_sub = '905'");
[$code, $j] = req($gc, 'me', [], null, 'dave', 'connected');
eq([$code, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE platform_sub = '104'")->fetchColumn()], [403, 0], 'connected: conflict → 403, no account');

// ── 8. המנהל הראשי במצב מנותק (20.1 ו'): השמור מהשער, ובתנאי ש-grant_status מאשר ──
$o = [];
req($o, 'me', [], null, 'owner', 'connected');            // השער: platform_admin = true → נשמר
$os = [];
login($os, ['sub' => '100', 'email' => 'owner@test.local', 'name' => 'בעל המערכת']);
[$code, $me] = req($os, 'me');
eq([$code, $me['is_primary_admin'] ?? null, $me['is_admin'] ?? null], [200, true, true], 'detached: owner is primary admin (stored flag + grant)');
[$code] = req($os, 'dev-items');
eq($code, 200, 'detached: owner reaches DevLoop');
$as = [];
login($as, ['sub' => '101', 'email' => 'alice@test.local', 'name' => 'אליס']);
[$code, $me] = req($as, 'me');
[$code2] = req($as, 'dev-items');
[$code3] = req($as, 'admin_role_add', [], ['user_id' => 1]);
eq([$me['is_primary_admin'] ?? null, $code2, $code3], [false, 403, 403], 'detached: others are not, and cannot become');
eq((int)$pdo->query("SELECT platform_admin FROM users WHERE platform_sub = '100'")->fetchColumn(), 1, 'SSO login does not reset the stored flag');
// ההרשאה בוטלה: בבדיקה הבאה (אחרי 5 דקות) ה-session נמחק — גם של המנהל
grant(false);
[$code] = req($os, 'me');
eq($code, 200, 'grant answer cached for 5 minutes');
$pdo->exec("UPDATE sessions SET grant_checked_at = grant_checked_at - 301 WHERE via <> 'gate'");
$keepOs = $os;
[$code, $j] = req($os, 'me');
eq([$code, $j['error'] ?? null, isset($os['ts_session'])], [401, 'אין לך הרשאת גישה לאפליקציה הזו — פנו לעורך ראשי', false], 'grant false → session dropped');
[$code] = req($keepOs, 'me');
eq($code, 401, 'dropped session is gone from the db');
// המעטפת לא עונה: session נחסם (503), לא נמחק; חוזרת — ממשיך
grant(true);
$bl = [];
login($bl, ['sub' => '101', 'email' => 'alice@test.local', 'name' => 'אליס']);
grant(null);
$pdo->exec("UPDATE sessions SET grant_checked_at = grant_checked_at - 301 WHERE via <> 'gate'");
[$code] = req($bl, 'me');
eq($code, 503, 'grant_status no answer → session blocked (fail closed)');
grant(true);
[$code] = req($bl, 'me');
eq($code, 200, 'platform answers again → session works');
// SSO כשההרשאה בוטלה — המעטפת לא נותנת זהות; ו-unknown עם session טרי ממשיך עד הבדיקה הבאה
[$code] = req($bl, 'me', [], null, 'none', 'unknown');
eq($code, 200, 'unknown: existing session continues until the next grant check');

// ── 8א. grant_status עצמו: בקשה חתומה במפתח שב-platform_key ──
[$code, $pk] = req($b, 'platform_key', [], null, 'none', 'connected');
eq([$code, $pk['client'] ?? null, $pk['keys'][0]['alg'] ?? null, count($pk['keys'] ?? [])], [200, 'trading-strategies', 'Ed25519', 1], 'platform_key: public key only');
eq(preg_match('/secret|private/i', json_encode($pk)), 0, 'platform_key has no private part');
$calls = array_values(array_filter(array_map(fn($x) => json_decode($x, true), explode("\n", (string)file_get_contents("$tmp/http.log"))),
    fn($x) => is_array($x) && str_contains($x['url'], 'grant_status')));
$h = [];
foreach (end($calls)['headers'] as $line) { [$hk, $hv] = array_map('trim', explode(':', $line, 2)); $h[$hk] = $hv; }
[$kid, $sigB] = explode('.', $h['X-Embed-Signature'], 2);
$msg = "grant_status\n{$h['X-Embed-Time']}\n{$h['X-Embed-Nonce']}\n" . hash('sha256', '{"sub":"101"}');
eq([end($calls)['method'], end($calls)['url'], $h['X-Embed-Client'], $kid],
    ['POST', "$base/platform/provision.php?action=grant_status", 'trading-strategies', $pk['keys'][0]['kid']], 'grant_status request');
eq(sodium_crypto_sign_verify_detached(base64_decode($sigB), $msg, base64_decode($pk['keys'][0]['key'])), true, 'grant_status signature verifies with platform_key');

// ── 8ב. סיסמה (20.1 ד') ──
$GLOBALS['PW'] = '1';
$ps = [];
login($ps, ['sub' => '102', 'email' => 'bob@test.local', 'name' => 'בוב']);
req($ps, 'me');
[$code, $j] = req($ps, 'password_set', [], ['password' => 'short']);
eq($code, 422, 'password too short');
[$code] = req($ps, 'password_set', [], ['password' => 'correct horse 1']);
eq($code, 200, 'set own password');
eq(str_starts_with((string)$pdo->query("SELECT password_hash FROM users WHERE platform_sub = '102'")->fetchColumn(), '$'), true, 'stored as password_hash');
$pl = [];
[$code, $j] = req($pl, 'login_config');
eq([$j['platform'] ?? null, $j['password'] ?? null], [true, true], 'detached + login.password → both options');
[$code] = req($pl, 'login', [], ['email' => 'BOB@test.local', 'password' => 'correct horse 1']);
[$code2, $me] = req($pl, 'me');
eq([$code, $code2, $me['login_via'] ?? null], [200, 200, 'password'], 'email + password → logged in');
[, $j1] = req($pl, 'login', [], ['email' => 'nobody@test.local', 'password' => 'whatever123']);
[, $j2] = req($pl, 'login', [], ['email' => 'bob@test.local', 'password' => 'wrong password']);
eq([$j1['error'] ?? null, $j1['error'] ?? 1], [$j2['error'] ?? null, 'מייל או סיסמה שגויים'], 'same message: no user / wrong password');
// grant false → סיסמה נכונה, אין כניסה
grant(false);
$pg = [];
[$code, $j] = req($pg, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1']);
eq([$code, isset($pg['ts_session'])], [403, false], 'password ok but grant false → no login');
grant(null);
[$code] = req($pg, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1']);
eq([$code, isset($pg['ts_session'])], [503, false], 'grant no answer → no new login');
grant(true);
// נעילה: 5 שגיאות, והשישית נכונה — עדיין נעול
$pdo->exec('DELETE FROM login_locks');
$lk = [];
for ($i = 0; $i < 5; $i++) req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'bad' . $i . 'xxxxxxx']);
[$code, $j] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1']);
eq([$code, isset($lk['ts_session'])], [429, false], '5 failures → locked, even with the right password');
$pdo->exec("UPDATE login_locks SET locked_until = " . (time() - 1));
[$code] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1']);
eq($code, 200, 'after 15 minutes → login works');
// לפי IP: מיילים שונים מאותו IP
$pdo->exec('DELETE FROM login_locks');
for ($i = 0; $i < 5; $i++) req($lk, 'login', [], ['email' => "x$i@test.local", 'password' => 'whatever123']);
[$code] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1']);
eq($code, 429, 'lock by IP too');
$pdo->exec('DELETE FROM login_locks');
// login.password כבוי / unknown / מחוברת
$GLOBALS['PW'] = '0';
[$code] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1']);
eq($code, 403, 'login.password off → 403');
$GLOBALS['PW'] = '1';
[$code] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1'], 'none', 'unknown');
eq($code, 503, 'unknown → password login blocked');
[$code, $j] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1'], 'none', 'connected');
eq([$code, $j['error'] ?? null], [403, 'הכניסה היא דרך הכלים הדיגיטליים'], 'connected → 403');
[$code, $j] = req($lk, 'login', [], ['email' => 'bob@test.local', 'password' => 'correct horse 1'], 'bob', 'connected');
eq($code, 403, 'connected with gate identity → 403');
// חזרה למחוברת: הסיסמה נשארת במסד, והשער הוא מקור הזהות
[$code, $me] = req($pl, 'me', [], null, 'none', 'connected');
eq([$code, isset($pl['ts_session'])], [401, false], 'back to connected: password session gone');
eq($pdo->query("SELECT password_hash IS NOT NULL FROM users WHERE platform_sub = '102'")->fetchColumn(), 1, 'password still stored');
// איפוס בידי המנהל הראשי
$bobId = (int)$pdo->query("SELECT id FROM users WHERE platform_sub = '102'")->fetchColumn();
$ad = [];
req($ad, 'me', [], null, 'alice', 'connected');
[$code] = req($ad, 'admin_password_reset', [], ['user_id' => $bobId], 'alice', 'connected');
eq($code, 403, 'reset: not by a regular user');
// ק10: מנהל נוסף (app_roles), לא ראשי → 403
$ow0 = [];
req($ow0, 'me', [], null, 'owner', 'connected');
$aliceId = (int)$pdo->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
req($ow0, 'admin_role_add', [], ['user_id' => $aliceId], 'owner', 'connected');
[, $mm] = req($ad, 'me', [], null, 'alice', 'connected');
[$code] = req($ad, 'admin_password_reset', [], ['user_id' => $bobId], 'alice', 'connected');
eq([$mm['is_admin'] ?? null, $code, $pdo->query("SELECT password_hash IS NOT NULL FROM users WHERE id = $bobId")->fetchColumn()], [true, 403, 1],
    'reset: another admin (not primary) → 403, password kept');
$ow = [];
req($ow, 'me', [], null, 'owner', 'connected');
[$code, $st] = req($ow, 'admin_state', [], null, 'owner', 'connected');
$row = array_values(array_filter($st['users'] ?? [], fn($u) => (int)$u['id'] === $bobId))[0] ?? [];
eq([(int)($row['has_password'] ?? -1), array_key_exists('password_hash', $row)], [1, false], 'admin list: has_password, never the hash');
[$code] = req($ow, 'admin_password_reset', [], ['user_id' => $bobId], 'owner', 'connected');
eq([$code, $pdo->query("SELECT password_hash FROM users WHERE id = $bobId")->fetchColumn()], [200, null], 'primary admin resets the password');
$GLOBALS['PW'] = '0';

// ── 8ג. devapi-* לא תלויות במצב (קריטריון 9) ──
foreach (['connected', 'detached', 'unknown'] as $pm) {
    $dv = [];
    [$code] = req($dv, 'devapi-feed', [], null, 'none', $pm);
    eq($code, 401, "devapi-feed without token → 401 (not a login screen) — $pm");
}

// ── 8ד. ביקורת סבב 1 ──
// 🟡1 — הנעילה אטומית: 4 תהליכים במקביל, שגיאה אחת כל אחד → fails = 4 בדיוק (בלי עדכון שאבד); 5 במקביל → נעול
$lockRun = function (string $key, int $nproc) use ($db) {
    $code = 'define("TS_DB_PATH", getenv("TS_DB_PATH")); require "' . dirname(__DIR__) . '/lib/auth.php"; ts_lock_fail(getenv("K"));';
    $ps = [];
    for ($i = 0; $i < $nproc; $i++) $ps[] = proc_open(['php', '-r', $code], [], $pp, null, ['TS_DB_PATH' => $db, 'K' => $key] + getenv());
    foreach ($ps as $p) proc_close($p);
};
$lost = 0;
for ($trial = 0; $trial < 5; $trial++) {
    $lockRun("race:$trial", 4);
    if ((int)$pdo->query("SELECT fails FROM login_locks WHERE lock_key = 'race:$trial'")->fetchColumn() !== 4) $lost++;
}
eq($lost, 0, '🟡1: concurrent failures are all counted');
$lockRun('race:five', 5);
eq((int)$pdo->query("SELECT locked_until > strftime('%s','now') FROM login_locks WHERE lock_key = 'race:five'")->fetchColumn(), 1, '🟡1: 5 concurrent failures → locked');
$pdo->exec("UPDATE login_locks SET locked_until = 1 WHERE lock_key = 'race:five'");
$lockRun('race:five', 1);
eq($pdo->query("SELECT fails || '/' || locked_until FROM login_locks WHERE lock_key = 'race:five'")->fetchColumn(), '1/0', '🟡1: expired lock starts over');

// 🟡2 — devapi-* במנותקת: המפתח רק כש-grant_status מאשר
grant(true);
$dv = [];
login($dv, ['sub' => '100', 'email' => 'owner@test.local', 'name' => 'בעל המערכת']);
req($dv, 'me');
[$code, $tk] = req($dv, 'dev-token-create', [], ['label' => 'Claude']);
$GLOBALS['EXTRA'] = ['X-Dev-Token: ' . ($tk['token'] ?? '')];
$nb = [];
[$code] = req($nb, 'devapi-feed');
eq($code, 200, '🟡2: detached, grant true → devapi works');
grant(false);
[$code] = req($nb, 'devapi-feed');
eq($code, 200, '🟡2: grant answer cached 5 minutes');
$pdo->exec("DELETE FROM settings WHERE k LIKE 'dev_grant:%'");
[$code] = req($nb, 'devapi-feed');
eq($code >= 400 && $code < 500, true, '🟡2: detached, grant false → token refused');
grant(null);
[$code] = req($nb, 'devapi-feed');
eq($code >= 400 && $code < 500, true, '🟡2: detached, no answer → token refused (fail closed)');
[$code] = req($nb, 'devapi-feed', [], null, 'none', 'connected');
eq($code, 200, '🟡2: connected → no grant_status needed');
$GLOBALS['EXTRA'] = [];
grant(true);

// 🟡3 — מייל כפול: מייל שתפוס בשורה אחרת לא מועתק; שתי שורות עם סיסמה לאותו מייל → 409, נרשם
$e1 = [];
login($e1, ['sub' => '906', 'email' => 'carol@test.local', 'name' => 'חדש']);   // 906 קיים (new@test.local)
eq([$pdo->query("SELECT email FROM users WHERE platform_sub = '906'")->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) = 'carol@test.local'")->fetchColumn()],
    ['new@test.local', 1], '🟡3: an email used by another row is not copied');
eq((int)$pdo->query("SELECT COUNT(*) FROM admin_log WHERE action = 'account_conflict' AND target LIKE 'email in use%'")->fetchColumn(), 1, '🟡3: logged');
$h1 = password_hash('dup password 1', PASSWORD_DEFAULT);
$pdo->exec("UPDATE users SET email = 'dup@test.local', password_hash = '$h1' WHERE platform_sub IN ('905', '906')");   // מצב ישן, לפני התיקון
$GLOBALS['PW'] = '1';
$d3 = [];
[$code, $j] = req($d3, 'login', [], ['email' => 'dup@test.local', 'password' => 'dup password 1']);
eq([$code, $j['error'] ?? null, isset($d3['ts_session'])], [409, 'יש כמה חשבונות באותו מייל — פנו לעורך ראשי', false], '🟡3: two accounts with a password → no login');
[$code, $j] = req($d3, 'login', [], ['email' => 'dup@test.local', 'password' => 'wrong password']);
eq([$code, $j['error'] ?? null], [401, 'מייל או סיסמה שגויים'], '🟡3: wrong password → the usual message');
$pdo->exec('DELETE FROM login_locks');
// ⚪ 72 בתים; החלפת סיסמה סוגרת כניסות אחרות בסיסמה
$q1 = []; $q2 = [];
login($q1, ['sub' => '102', 'email' => 'bob@test.local', 'name' => 'בוב']);
req($q1, 'me');
[$code] = req($q1, 'password_set', [], ['password' => str_repeat('a', 73)]);
eq($code, 422, '⚪ password over 72 bytes → 422');
req($q1, 'password_set', [], ['password' => 'first password 1']);
req($q2, 'login', [], ['email' => 'bob@test.local', 'password' => 'first password 1']);
[$code] = req($q2, 'me');
eq($code, 200, '⚪ second session by password');
req($q1, 'password_set', [], ['password' => 'second password 2']);
[$code] = req($q2, 'me');
[$code1] = req($q1, 'me');
eq([$code, $code1], [401, 200], '⚪ changing the password closes other password sessions');
$GLOBALS['PW'] = '0';
// ⚪ sso_states שפגו נמחקים בכל יצירה
$pdo->exec("INSERT INTO sso_states (state_hash, created_at, expires_at) VALUES ('old', 1, 2)");
$cs = [];
start($cs);
eq((int)$pdo->query("SELECT COUNT(*) FROM sso_states WHERE state_hash = 'old'")->fetchColumn(), 0, '⚪ expired sso_states cleaned');

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
eq(count(array_filter($log, fn($u) => !str_starts_with($u, "$base/platform/sso.php?") && $u !== "$base/platform/provision.php?action=grant_status")), 0,
    'only the platform is asked — no URL from the request');

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
