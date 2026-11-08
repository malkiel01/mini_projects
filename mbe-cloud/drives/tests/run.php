<?php
/**
 * בדיקות מודול הכוננים מול הענן עצמו, באותו תהליך: php mbe-cloud/drives/tests/run.php
 * "הרשת" בין האפליקציה לענן היא פונקציה שמריצה את קוד הענן ישירות — כל המסלול אמיתי, בלי שרת.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/drives-test-' . getmypid();
define('DATA_DIR', "$tmp/cloud");
define('CLOUD_ORIGIN', 'https://mbe-plus.com');
define('CLOUD_ISSUER', 'https://mbe-plus.com/mini_projects/mbe-cloud/');
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
                if (!$c || $c !== $ch || $f['redirect_uri'] !== 'https://mbe-plus.com/mini_projects/mbe-cloud/oauth/dropbox.php') return [400, '{"error":"invalid_grant"}'];
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
    check('connect — לכתובת האישור של הענן, עם files,libs,ai', str_starts_with($url, 'https://mbe-plus.com/mini_projects/mbe-cloud/connect.php?') && str_contains($url, 'scope=files%2Clibs%2Cai'));
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
    check('token — אסימון לדפדפן וכתובת ה-API', $t['connected'] && $t['api'] === 'https://mbe-plus.com/mini_projects/mbe-cloud/api.php' && $t['expires_in'] === 900);
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

    // ─── APK: החזרה בדפדפן החיצוני, בלי העוגייה של האפליקציה (api-guide.md §10 פ1, פ1א) ───
    $app->disconnect('u1');
    $pend = fn(string $u) => (fn() => $this->db()->query('SELECT * FROM pending WHERE user = ' . $this->db()->quote($u))->fetch())->call($app);
    $user = null;   // הדפדפן החיצוני — אין משתמש באפליקציה
    $url = $app->connectUrl('u1');   // נוצר בתוך האפליקציה, עם העוגייה של u1
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $r = $app->handle('drives_callback', $bq);
    $code = $r['claim'] ?? '';
    check('(א) חזרה בלי עוגייה — לא 401: ממתין, וקוד בן 8 תווים לדף', isset($r['done']) && (bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code));
    check('הקוד נשמר רק כ-hash', ($p = $pend('u1')) && $p['claim'] === hash('sha256', $code) && !str_contains(json_encode($p), $code));
    check('חזרה כפולה לאותו דף — לא מציגה את הקוד שוב (bad_state:used)', code(fn() => $app->handle('drives_callback', $bq)) === 'bad_state:used');
    check('בלי קוד — לא מתחבר אוטומטית; status מסמן pending', $app->status('u1')['suite']['connected'] === false
        && ($app->status('u1')['suite']['pending'] ?? false) === true && $app->token('u1') === ['connected' => false]);
    check('(ב) משתמש אחר לא רואה את הממתין, ולא יכול לאשר אותו גם עם הקוד הנכון', !isset($app->status('u2')['suite']['pending'])
        && code(fn() => $app->claim('u2', $code)) === 'no_pending' && $pend('u1') !== null);
    check('קוד שגוי — bad_claim, וניסיון נספר', code(fn() => $app->claim('u1', 'AAAAAAAA')) === 'bad_claim' && (int) $pend('u1')['tries'] === 1);
    $s = $app->claim('u1', strtolower(substr($code, 0, 4) . '-' . substr($code, 4)));   // כמו שמוקלד: עם מקף, באותיות קטנות
    check('(א) הקוד הנכון, עם העוגייה של u1 — מחובר', $s['suite']['connected'] === true && $s['suite']['name'] === 'בעל המערכת' && $pend('u1') === false);
    $t = $app->token('u1');
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $t['token'];
    check('(א) והאסימון פותח את הענן', actor('list', 'files', '')['app'] === 'vector-studio');
    unset($_SERVER['HTTP_X_CLOUD_TOKEN']);
    check('קוד שכבר נוצל — no_pending', code(fn() => $app->claim('u1', $code)) === 'no_pending');

    // 5 ניסיונות — ואז הממתין נמחק ומנותק בענן
    $url = $app->connectUrl('u6');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $r6 = $app->handle('drives_callback', $bq);
    $cid6 = $pend('u6')['connection'];
    $codes = [];
    for ($i = 0; $i < 4; $i++) $codes[] = code(fn() => $app->claim('u6', 'BBBBBBBB'));
    $codes[] = code(fn() => $app->claim('u6', 'BBBBBBBB'));
    check('ניסיון חמישי שגוי — claim_locked, והממתין נמחק', $codes === ['bad_claim', 'bad_claim', 'bad_claim', 'bad_claim', 'claim_locked'] && $pend('u6') === false);
    check('ניסיון שישי, גם עם הקוד הנכון — נדחה, והחיבור בענן נותק', code(fn() => $app->claim('u6', $r6['claim'])) === 'no_pending'
        && db()->query('SELECT revoked_at FROM connections WHERE id = ' . db()->quote($cid6))->fetchColumn() !== null);

    // (ג) state שפג — נדחה, גם בלי עוגייה
    $url = $app->connectUrl('u3');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    (fn() => $this->db()->prepare('UPDATE states SET expires = ? WHERE state = ?')->execute([time() - 1, $bq['state']]))->call($app);
    check('(ג) state שפג — bad_state:expired', code(fn() => $app->handle('drives_callback', $bq)) === 'bad_state:expired');
    check('(ג) state שלא קיים — bad_state:missing', code(fn() => $app->handle('drives_callback', ['state' => 'nope', 'code' => 'x'])) === 'bad_state:missing');
    check('(ג) ולא נוצר חיבור', $app->status('u3')['suite']['connected'] === false);

    // קוד שפג (10 דקות) — נדחה, הממתין נמחק ומנותק בענן
    $url = $app->connectUrl('u4');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $r4 = $app->handle('drives_callback', $bq);
    $cid4 = $pend('u4')['connection'];
    (fn() => $this->db()->exec("UPDATE pending SET expires = " . (time() - 1) . " WHERE user = 'u4'"))->call($app);
    check('קוד שפג — no_pending', code(fn() => $app->claim('u4', $r4['claim'])) === 'no_pending');
    check('ממתין שפג — נמחק, והחיבור בענן נותק', $pend('u4') === false
        && db()->query('SELECT revoked_at FROM connections WHERE id = ' . db()->quote($cid4))->fetchColumn() !== null);

    // בקשה חדשה מחליפה ממתין קודם של אותו משתמש
    $url = $app->connectUrl('u7');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $r7a = $app->handle('drives_callback', $bq);
    $url = $app->connectUrl('u7');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $r7b = $app->handle('drives_callback', $bq);
    check('בקשה חדשה מחליפה את הקודמת — הקוד הישן לא עובד, החדש כן', code(fn() => $app->claim('u7', $r7a['claim'])) === 'bad_claim'
        && $app->claim('u7', $r7b['claim'])['suite']['connected'] === true);

    // ביטול במסך האישור, בלי עוגייה — דף "בוטל", בלי קוד ובלי חיבור
    $url = $app->connectUrl('u5');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $q['error'] = 'access_denied';
    $r5 = $app->handle('drives_callback', $q);
    check('ביטול בדפדפן החיצוני — דף "בוטל", בלי קוד ובלי חיבור', str_contains($r5['done'], 'בוטל') && !isset($r5['claim']) && !empty($r5['denied'])
        && $app->status('u5')['suite']['connected'] === false);

    $user = null;
    check('drives_claim בלי משתמש באפליקציה — 401', code(fn() => $app->handle('drives_claim', ['code' => 'x'])) === 'unauthorized');

    // (ד) הזרימה הרגילה, עם העוגייה — לא השתנתה
    $user = 'u1';
    $url = $app->connectUrl('u1');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    check('(ד) עם עוגייה — הפניה רגילה ל-?cloud=connected', $app->handle('drives_callback', $bq) === ['redirect' => 'https://mbe-plus.com/mini_projects/vector-studio/?cloud=connected']);
    $url = $app->connectUrl('u1');
    parse_str((string) parse_url(approve($url), PHP_URL_QUERY), $bq);
    $user = 'u2';
    check('(ד) עם עוגייה של משתמש אחר — עדיין bad_state:user', code(fn() => $app->handle('drives_callback', $bq)) === 'bad_state:user');
    $user = null;
    check('בלי משתמש — שאר הפעולות עדיין 401', code(fn() => $app->handle('drives_status', [])) === 'unauthorized'
        && code(fn() => $app->handle('drives_token', [])) === 'unauthorized' && code(fn() => $app->handle('drives_connect', [])) === 'unauthorized');
    $user = 'u1';

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
