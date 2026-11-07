<?php
/**
 * בדיקות מודול הכוננים מול הענן עצמו, באותו תהליך: php suite-cloud/drives/tests/run.php
 * "הרשת" בין האפליקציה לענן היא פונקציה שמריצה את קוד הענן ישירות — כל המסלול אמיתי, בלי שרת.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/drives-test-' . getmypid();
define('DATA_DIR', "$tmp/cloud");
define('CLOUD_ORIGIN', 'https://mbe-plus.com');
define('CLOUD_ISSUER', 'https://mbe-plus.com/mini_projects/suite-cloud/');
require_once __DIR__ . '/../../lib/drive.php';
require_once __DIR__ . '/../../lib/clients.php';
require_once __DIR__ . '/../src/php/Drives.php';

use SuiteCloud\Drives;
use SuiteCloud\DrivesError;

$fails = 0; $n = 0;
function check(string $name, bool $ok): void {
    global $fails, $n;
    $n++;
    if (!$ok) { $fails++; echo "✗ $name\n"; } else echo "✓ $name\n";
}
function code(callable $fn): string {
    try { $fn(); return 'ok'; } catch (DrivesError $e) { return $e->codeName; } catch (AppError $e) { return $e->codeName; }
}

/** "השרת של הענן": מריץ את הפעולה כמו api.php, ומחזיר [status, body]. */
/** "Dropbox": החלפת קוד (עם בדיקת PKCE), רענון, פרטי חשבון, ביטול. */
$dbx = ['codes' => [], 'refresh' => [], 'revoked' => [], 'n' => 0];
$dropbox = function (string $url, array $headers, string $body) use (&$dbx): array {
    parse_str($body, $f);
    $auth = '';
    foreach ($headers as $h) if (str_starts_with($h, 'Authorization: Bearer ')) $auth = substr($h, 22);
    switch ($url) {
        case 'https://api.dropboxapi.com/oauth2/token':
            if (($f['client_id'] ?? '') !== 'dbxappkey123') return [400, '{"error":"invalid_client"}'];
            if ($f['grant_type'] === 'authorization_code') {
                $c = $dbx['codes'][$f['code']] ?? null;
                unset($dbx['codes'][$f['code']]);
                $ch = rtrim(strtr(base64_encode(hash('sha256', (string) ($f['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
                if (!$c || $c !== $ch || $f['redirect_uri'] !== 'https://mbe-plus.com/mini_projects/suite-cloud/oauth/dropbox.php') return [400, '{"error":"invalid_grant"}'];
                $dbx['refresh']['r1'] = true;
                return [200, json_encode(['access_token' => 'at' . (++$dbx['n']), 'expires_in' => 14400, 'refresh_token' => 'r1', 'token_type' => 'bearer'])];
            }
            if (empty($dbx['refresh'][$f['refresh_token'] ?? ''])) return [400, '{"error":"invalid_grant"}'];
            return [200, json_encode(['access_token' => 'at' . (++$dbx['n']), 'expires_in' => 14400])];
        case 'https://api.dropboxapi.com/2/users/get_current_account':
            return [200, json_encode(['name' => ['display_name' => 'מלכיאל'], 'email' => 'm@example.com'])];
        case 'https://api.dropboxapi.com/2/auth/token/revoke':
            $dbx['revoked'][] = $auth;
            unset($dbx['refresh']['r1']);
            return [200, 'null'];
    }
    return [404, '{}'];
};

$cloud = function (string $url, array $headers, string $json) use ($dropbox): array {
    if (!str_starts_with($url, CLOUD_ISSUER)) return $dropbox($url, $headers, $json);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    foreach (['CLIENT', 'TIME', 'NONCE', 'SIGNATURE'] as $k) unset($_SERVER["HTTP_X_CLOUD_$k"]);
    $h = [];
    foreach ($headers as $line) {
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        if (str_starts_with($k, 'X-Cloud-')) $h[strtolower(substr($k, 8))] = $v;
    }
    $in = json_decode($json, true) ?: [];
    try {
        $out = match ($q['action']) {
            'client_register' => registerClient($in),
            'connect_exchange' => exchangeCode(verifySigned('connect_exchange', $h, $json), $in),
            'access_token' => (function () use ($h, $json, $in) {
                $c = verifySigned('access_token', $h, $json);
                return issueToken($c, connectionRow((string) ($in['connection'] ?? ''), $c['id']));
            })(),
            'drives_config' => (function () use ($h, $json) {
                verifySigned('drives_config', $h, $json);
                $key = setting('dropbox_app_key');
                return $key === '' ? [] : ['dropbox' => ['app_key' => $key, 'redirect' => CLOUD_ISSUER . 'oauth/dropbox.php']];
            })(),
            'disconnect' => (function () use ($h, $json, $in) {
                $c = verifySigned('disconnect', $h, $json);
                revokeConnection((string) ($in['connection'] ?? ''));
                return ['ok' => true];
            })(),
        };
        return [200, json_encode($out)];
    } catch (AppError $e) {
        return [$e->status, json_encode(['error' => $e->getMessage(), 'code' => $e->codeName])];
    }
};

$app = null;
$GLOBALS['CLOUD_FETCH'] = function (string $url) use (&$app) { return json_encode($app->keyJson()); };
$user = 'u1';
$app = new Drives([
    'client' => 'vector-studio', 'name' => 'וקטורי', 'dataDir' => "$tmp/app",
    'endpoint' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php',
    'back' => 'https://mbe-plus.com/mini_projects/vector-studio/', 'user' => function () use (&$user) { return $user; },
    'http' => $cloud,
]);

/** בעל החשבון בענן מאשר את מסך החיבור — מחזיר את כתובת החזרה. */
function approve(string $connectUrl): string {
    parse_str((string) parse_url($connectUrl, PHP_URL_QUERY), $q);
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => '1', 'name' => 'בעל המערכת', 'email' => 'owner@example.com', 'platform_admin' => true]);
    $req = connectRequest($q);
    return approveConnect(requireAccount(), $req);
}

try {
    check('המפתח הציבורי של האפליקציה', $app->keyJson()['client'] === 'vector-studio' && strlen(base64_decode($app->keyJson()['keys'][0]['key'])) === 32);
    check('בהתחלה — לא מחובר', $app->status('u1')['suite']['connected'] === false && $app->token('u1') === ['connected' => false]);

    $url = $app->connectUrl('u1');
    check('connect — נרשמה בענן, פעילה (אותו origin)', activeClient('vector-studio')['status'] === 'active');
    check('connect — לכתובת האישור של הענן, עם files,libs,ai', str_starts_with($url, 'https://mbe-plus.com/mini_projects/suite-cloud/connect.php?') && str_contains($url, 'scope=files%2Clibs%2Cai'));
    $back = approve($url);
    parse_str((string) parse_url($back, PHP_URL_QUERY), $bq);
    check('משתמש אחר באפליקציה לא יכול להשתמש ב-state — והסיבה ידועה', code(fn() => $app->callback('u2', $bq)) === 'bad_state:user');
    check('state שלא קיים — missing', code(fn() => $app->callback('u1', ['state' => 'nope', 'code' => 'x'])) === 'bad_state:missing');

    $url = $app->connectUrl('u1');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $to = $app->callback('u1', $bq);
    check('callback — חיבור נשמר, חזרה לאפליקציה', $to === 'https://mbe-plus.com/mini_projects/vector-studio/?cloud=connected');
    check('חזרה כפולה עם אותו state — לא שגיאה, חוזרים מחוברים', $app->callback('u1', $bq) === 'https://mbe-plus.com/mini_projects/vector-studio/?cloud=connected');
    $st = $app->status('u1')['suite'];
    check('status — מחובר, שם בעל החשבון, cloud_id', $st['connected'] && $st['name'] === 'בעל המערכת' && $st['cloud_id'] === setting('cloud_id'));

    $t = $app->token('u1');
    check('token — אסימון לדפדפן וכתובת ה-API', $t['connected'] && $t['api'] === 'https://mbe-plus.com/mini_projects/suite-cloud/api.php' && $t['expires_in'] === 900);
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $t['token'];
    $x = actor('list', 'files', '');
    check('האסימון פותח את הקבצים בענן בשם בעל החשבון והאפליקציה', $x['app'] === 'vector-studio' && $x['account']['name'] === 'בעל המערכת');
    check('והרשאת ai', actor('ai_capabilities', 'ai', '')['app'] === 'vector-studio');
    check('והרשאת libs (גופנים)', actor('fonts_list', 'libs', '')['app'] === 'vector-studio');
    unset($_SERVER['HTTP_X_CLOUD_TOKEN']);

    $url = $app->connectUrl('u1');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $q['error'] = 'access_denied';
    check('ביטול במסך האישור — חזרה עם denied', $app->callback('u1', $q) === 'https://mbe-plus.com/mini_projects/vector-studio/?cloud=denied');

    // הענן איבד את רישום האפליקציה (מסד חדש) — נרשמים שוב לבד; החיבור הישן כבר לא קיים שם → מנותק כאן
    db()->exec("DELETE FROM connections; DELETE FROM clients;");
    check('ענן ששכח את האפליקציה — רישום מחדש, והחיבור נמחק', $app->token('u1') === ['connected' => false] && activeClient('vector-studio')['status'] === 'active');

    $url = $app->connectUrl('u1');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $app->callback('u1', $bq);
    check('חיבור מחדש', $app->status('u1')['suite']['connected']);
    $tok = $app->token('u1')['token'];
    $app->disconnect('u1');
    check('ניתוק מהאפליקציה — מנותק גם בענן, והאסימון נדחה מיד', $app->status('u1')['suite']['connected'] === false && code(fn() => verifyToken($tok)) === 'disconnected');
    check('ניתוק בענן (בדף "חיבורים") — token מחזיר connected:false', (function () use ($app) {
        $url = $app->connectUrl('u1');
        parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
        $app->callback('u1', $bq);
        db()->exec('UPDATE connections SET revoked_at = \'2026-01-01\'');
        return $app->token('u1') === ['connected' => false] && $app->status('u1')['suite']['connected'] === false;
    })());

    // ─── Dropbox ───
    check('בלי App key בענן — Dropbox לא מופיע', !isset($app->status('u1')['dropbox']) && code(fn() => $app->linkUrl('u1', 'dropbox')) === 'no-provider');
    setSetting('dropbox_app_key', 'dbxappkey123');
    check('במטמון שעה — עדיין לא', !isset($app->status('u1')['dropbox']));
    (new ReflectionMethod($app, 'setMeta'))->invoke($app, 'drives_config', '');
    check('App key בענן — Dropbox מופיע, לא מחובר', ($app->status('u1')['dropbox'] ?? null) === ['connected' => false]);
    $u = $app->linkUrl('u1', 'dropbox');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $lq);
    check('link — הרשאה ב-Dropbox עם PKCE, offline, והחזרה לדף ההעברה בענן',
        str_starts_with($u, 'https://www.dropbox.com/oauth2/authorize?') && $lq['client_id'] === 'dbxappkey123' && $lq['code_challenge_method'] === 'S256'
        && $lq['token_access_type'] === 'offline' && $lq['redirect_uri'] === CLOUD_ISSUER . 'oauth/dropbox.php' && str_starts_with($lq['state'], 'vector-studio.'));
    $dbx['codes']['c1'] = $lq['code_challenge'];
    check('callback ממשתמש אחר — bad_state:user', code(fn() => $app->oauthCallback('u2', ['state' => $lq['state'], 'code' => 'c1', 'provider' => 'dropbox'])) === 'bad_state:user');
    $u = $app->linkUrl('u1', 'dropbox');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $lq);
    $dbx['codes']['c2'] = 'not-the-challenge';
    check('verifier לא תואם — הספק דוחה', code(fn() => $app->oauthCallback('u1', ['state' => $lq['state'], 'code' => 'c2', 'provider' => 'dropbox'])) === 'provider');
    $u = $app->linkUrl('u1', 'dropbox');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $lq);
    $dbx['codes']['c3'] = $lq['code_challenge'];
    $to = $app->oauthCallback('u1', ['state' => $lq['state'], 'code' => 'c3', 'provider' => 'dropbox']);
    check('callback — מחובר, חזרה עם drive=connected', $to === 'https://mbe-plus.com/mini_projects/vector-studio/?drive=connected');
    check('חזרה כפולה — לא שגיאה', $app->oauthCallback('u1', ['state' => $lq['state'], 'code' => 'c3', 'provider' => 'dropbox']) === $to);
    check('status — Dropbox מחובר, עם שם החשבון', $app->status('u1')['dropbox'] === ['connected' => true, 'name' => 'מלכיאל']);
    check('ptoken — אסימון הגישה מהחלפת הקוד', $app->providerToken('u1', 'dropbox')['token'] === 'at1');
    check('משתמש אחר — לא מחובר', $app->providerToken('u2', 'dropbox') === ['connected' => false]);
    (new ReflectionMethod($app, 'db'))->invoke($app)->exec('UPDATE links SET access_until = 0');
    $t = $app->providerToken('u1', 'dropbox');
    check('אסימון שפג — מתחדש מאסימון הרענון', $t['token'] === 'at2' && $t['expires_in'] > 14000);
    $u = $app->linkUrl('u1', 'dropbox');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $lq);
    check('ביטול אצל Dropbox — חזרה עם drive=denied', $app->oauthCallback('u1', ['state' => $lq['state'], 'error' => 'access_denied', 'provider' => 'dropbox'])
        === 'https://mbe-plus.com/mini_projects/vector-studio/?drive=denied');
    $app->unlink('u1', 'dropbox');
    check('unlink — מבטל אצל Dropbox ומוחק כאן', $dbx['revoked'] === ['at2'] && $app->status('u1')['dropbox'] === ['connected' => false]);
    // הרשאה שבוטלה בצד של Dropbox: הרענון נכשל → לא מחובר
    $u = $app->linkUrl('u1', 'dropbox');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $lq);
    $dbx['codes']['c4'] = $lq['code_challenge'];
    $app->oauthCallback('u1', ['state' => $lq['state'], 'code' => 'c4', 'provider' => 'dropbox']);
    $dbx['refresh'] = [];
    (new ReflectionMethod($app, 'db'))->invoke($app)->exec('UPDATE links SET access_until = 0');
    check('הרשאה שבוטלה ב-Dropbox — connected:false, והקישור נמחק', $app->providerToken('u1', 'dropbox') === ['connected' => false] && $app->status('u1')['dropbox']['connected'] === false);
    check('ספק לא מוכר — no-provider', code(fn() => $app->linkUrl('u1', 'gdrive')) === 'no-provider');
    $local = new Drives(['client' => 'own', 'name' => 'עצמאית', 'dataDir' => "$tmp/own", 'endpoint' => 'https://own.example/api.php',
        'back' => 'https://own.example/', 'user' => fn() => 'u1', 'http' => $cloud, 'dropbox' => ['app_key' => 'ownkey12345']]);
    parse_str((string) parse_url($local->linkUrl('u1', 'dropbox'), PHP_URL_QUERY), $lq);
    check('אפליקציה בדומיין משלה — App key מקומי, החזרה ישר אליה', $lq['client_id'] === 'ownkey12345'
        && $lq['redirect_uri'] === 'https://own.example/api.php?action=drives_oauth_callback&provider=dropbox');

    $user = null;
    check('בלי משתמש באפליקציה — 401', code(fn() => $app->handle('drives_status', [])) === 'unauthorized');
    check('drives_key — בלי משתמש (הענן קורא אותו)', $app->handle('drives_key', [])['client'] === 'vector-studio');
} catch (Throwable $e) {
    $fails++;
    echo "✗ חריגה: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
