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
                $conn = connectionRow((string) ($in['connection'] ?? ''), $c['id']);
                bindConnection($conn, $in['sub'] ?? null);   // כמו ב-api.php
                return issueToken($c, $conn);
            })(),
            'drives_config' => (function () use ($h, $json) {
                verifySigned('drives_config', $h, $json);
                $key = setting('dropbox_app_key');
                return $key === '' ? [] : ['dropbox' => ['app_key' => $key, 'redirect' => CLOUD_ISSUER . 'oauth/dropbox.php']];
            })(),
            'connection_scope' => connectionScope(verifySigned('connection_scope', $h, $json), $in),
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

/** מי מחובר לפלטפורמה בבקשה הזו (השער כותב PLATFORM_USER). sub 1 — בעל המערכת; sub 2 — אדם אחר. null — אין. */
function platformAs(?string $sub): void {
    if ($sub === null) { unset($_SERVER['PLATFORM_USER']); return; }
    $_SERVER['PLATFORM_USER'] = json_encode($sub === '1'
        ? ['sub' => '1', 'name' => 'בעל המערכת', 'email' => 'owner@example.com', 'platform_admin' => true]
        : ['sub' => $sub, 'name' => "אדם $sub", 'email' => "p$sub@example.com", 'platform_admin' => false]);
}

/**
 * מסך החיבור בענן, בדיוק כמו connect.php: מי שנכנס לענן ($sub) — ואם הוא לא אותו חשבון כמו המשתמש באפליקציה, החיבור נדחה
 * בלי כפתור (חוזר עם error=account_mismatch). $force — מדלג על המסך (כמו תוקף שמנסה לעקוף), כדי לבדוק את ההחלפה עצמה.
 */
function approve(string $connectUrl, string $sub = '1', bool $force = false): string {
    parse_str((string) parse_url($connectUrl, PHP_URL_QUERY), $q);
    $appPlatform = $_SERVER['PLATFORM_USER'] ?? null;
    platformAs($sub);
    try {
        $req = connectRequest($q);
        $a = requireAccount();
        if (!$force && !sameSub($a, $req['sub'])) return withQuery($req['return'], ['error' => 'account_mismatch', 'state' => $req['state']]);
        return approveConnect($a, $req);
    } finally {
        if ($appPlatform === null) unset($_SERVER['PLATFORM_USER']); else $_SERVER['PLATFORM_USER'] = $appPlatform;
    }
}
platformAs('1');   // האפליקציה: המשתמשים u1… שייכים לבעל המערכת, אלא אם נקבע אחרת

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

    // ─── רק אותו חשבון (spec-0-vision.md 6.3) ───
    $app->disconnect('u1');
    $conns = fn(string $u) => (fn() => $this->db()->query('SELECT * FROM conns WHERE user = ' . $this->db()->quote($u))->fetch())->call($app);
    $cloudConn = fn(string $id) => db()->query('SELECT * FROM connections WHERE id = ' . db()->quote($id))->fetch();
    $q2 = fn(string $url) => (function () use ($url) { parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return $q; })();

    $url = $app->connectUrl('u1');
    $q = $q2($url);
    check('הבקשה ל-connect.php נושאת sub, exp וחתימה', $q['sub'] === '1' && ctype_digit($q['exp']) && str_contains($q['sig'], '.'));
    check('connect.php מזהה את המשתמש באפליקציה מהחתימה', connectRequest($q)['sub'] === '1');
    check('sub מזויף בכתובת (החתימה על sub אחר) — bad_identity', code(fn() => connectRequest(['sub' => '2'] + $q)) === 'bad_identity');
    check('בלי חתימה — bad_identity', code(fn() => connectRequest(array_diff_key($q, ['sig' => 1]))) === 'bad_identity');
    check('בלי sub — bad_identity', code(fn() => connectRequest(array_diff_key($q, ['sub' => 1]))) === 'bad_identity');
    check('exp שפג — bad_identity', code(fn() => connectRequest(['exp' => (string) (time() - 1)] + $q)) === 'bad_identity');
    check('חתימה של return אחר — bad_identity', code(fn() => connectRequest(['sig' => $q2($app->connectUrl('u1'))['sig']] + $q)) === 'bad_identity');

    platformAs(null);
    check('בלי חשבון פלטפורמה באפליקציה — אין הפניה לענן (no_platform_account)', code(fn() => $app->connectUrl('u1')) === 'no_platform_account');
    platformAs('1');

    // אדם אחר (sub 2) יוצר בקשה באפליקציה, ושולח את הקישור לבעל המערכת (sub 1), שמאשר בענן שלו
    platformAs('2');
    $url = $app->connectUrl('u2');
    platformAs('1');
    $q = $q2($url);
    platformAs('1');
    check('connect.php: החשבון בענן (1) אינו המשתמש באפליקציה (2) — נדחה במסך', !sameSub(requireAccount(), connectRequest($q)['sub']));
    $user = null;   // הדפדפן החיצוני
    $back = approve($url);
    check('המסך מחזיר error=account_mismatch, בלי קוד', str_contains($back, 'error=account_mismatch') && !str_contains($back, 'code='));
    check('והחזרה מציגה "החשבון בענן שונה", בלי חיבור', code(fn() => $app->handle('drives_callback', $q2($back))) === 'account_mismatch'
        && $conns('u2') === false);
    // ואם מדלגים על המסך — ההחלפה עצמה דוחה
    $url = (function () use ($app) { platformAs('2'); $u = $app->connectUrl('u2'); platformAs('1'); return $u; })();
    $bq = $q2(approve($url, '1', true));
    check('גם בעקיפת המסך: connect_exchange דוחה (account_mismatch), ואין חיבור', code(fn() => $app->handle('drives_callback', $bq)) === 'account_mismatch'
        && $conns('u2') === false);
    check('והקוד נשרף', !db()->query('SELECT 1 FROM codes WHERE code = ' . db()->quote($bq['code']))->fetchColumn());
    // sub נכון בחתימה, אבל ב-connect_exchange נשלח sub אחר
    $url = $app->connectUrl('u1');
    $bq = $q2(approve($url));
    check('connect_exchange עם sub שונה מזה שנחתם — account_mismatch', code(fn() => exchangeCode(activeClient('vector-studio'), ['code' => $bq['code'], 'sub' => '2'])) === 'account_mismatch');
    $bq = $q2(approve($app->connectUrl('u1')));
    check('connect_exchange בלי sub — account_mismatch', code(fn() => exchangeCode(activeClient('vector-studio'), ['code' => $bq['code']])) === 'account_mismatch');

    // ─── APK: החזרה בדפדפן החיצוני, בלי העוגייה של האפליקציה (api-guide.md §10 פ1) ───
    $user = 'u1';
    $url = $app->connectUrl('u1');   // נוצר בתוך האפליקציה, עם העוגייה של u1 (sub 1)
    $user = null;
    $bq = $q2(approve($url));
    $r = $app->handle('drives_callback', $bq);
    check('(א) אותו אדם, בלי עוגייה — החיבור הושלם ישר', ($r['done'] ?? '') !== '' && empty($r['denied'])
        && $app->status('u1')['suite']['connected'] === true && $conns('u1')['sub'] === '1');
    check('(א) והחיבור בענן רשום עם ה-sub', $cloudConn($conns('u1')['connection'])['sub'] === '1');
    check('חזרה כפולה לאותו דף — "כבר הושלם", לא שגיאה', str_contains($app->handle('drives_callback', $bq)['done'] ?? '', 'כבר הושלם'));
    $t = $app->token('u1');
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $t['token'];
    check('(א) והאסימון פותח את הענן', actor('list', 'files', '')['app'] === 'vector-studio');
    unset($_SERVER['HTTP_X_CLOUD_TOKEN']);

    // ביטול במסך האישור, בלי עוגייה — דף "בוטל", בלי חיבור
    $user = 'u5';
    $q = $q2($app->connectUrl('u5'));
    $user = null;
    $r5 = $app->handle('drives_callback', ['error' => 'access_denied'] + $q);
    check('ביטול בדפדפן החיצוני — דף "בוטל", בלי חיבור', str_contains($r5['done'], 'בוטל') && !empty($r5['denied'])
        && $app->status('u5')['suite']['connected'] === false);

    // (ג) state שפג — נדחה, גם בלי עוגייה
    $url = $app->connectUrl('u3');
    $bq = $q2(approve($url));
    (fn() => $this->db()->prepare('UPDATE states SET expires = ? WHERE state = ?')->execute([time() - 1, $bq['state']]))->call($app);
    check('(ג) state שפג — bad_state:expired', code(fn() => $app->handle('drives_callback', $bq)) === 'bad_state:expired');
    check('(ג) state שלא קיים — bad_state:missing', code(fn() => $app->handle('drives_callback', ['state' => 'nope', 'code' => 'x'])) === 'bad_state:missing');
    check('(ג) ולא נוצר חיבור', $app->status('u3')['suite']['connected'] === false);

    // ─── access_token — בכל פעם. חיבורים מלפני הכלל (בלי sub) ───
    $user = 'u1';
    $cid = $conns('u1')['connection'];
    db()->prepare('UPDATE connections SET sub = NULL WHERE id = ?')->execute([$cid]);
    (fn() => $this->db()->exec("UPDATE conns SET sub = NULL WHERE user = 'u1'"))->call($app);
    check('חיבור ישן, אותו אדם — אסימון, וה-sub נרשם בשני הצדדים', $app->token('u1')['connected'] === true
        && $cloudConn($cid)['sub'] === '1' && $conns('u1')['sub'] === '1' && $cloudConn($cid)['mismatch_at'] === null);
    db()->prepare('UPDATE connections SET sub = NULL WHERE id = ?')->execute([$cid]);
    (fn() => $this->db()->exec("UPDATE conns SET sub = NULL WHERE user = 'u1'"))->call($app);
    platformAs('2');   // באפליקציה מחובר עכשיו אדם אחר, והחיבור הישן מוביל לענן של בעל המערכת
    check('חיבור ישן של אדם אחר — אין אסימון (account_mismatch)', code(fn() => $app->token('u1')) === 'account_mismatch');
    check('— מסומן mismatch_at, לא מנותק, ומופיע בניהול', $cloudConn($cid)['mismatch_at'] !== null && $cloudConn($cid)['revoked_at'] === null
        && array_values(array_filter(adminClients(), fn($c) => $c['id'] === 'vector-studio'))[0]['mismatch'] === 1);
    platformAs('1');
    db()->prepare("UPDATE connections SET sub = '2' WHERE id = ?")->execute([$cid]);
    check('חיבור שנרשם ל-sub אחר — אין אסימון גם כשה-sub של הבקשה תואם', code(fn() => $app->token('u1')) === 'account_mismatch');
    db()->prepare("UPDATE connections SET sub = '1', mismatch_at = NULL WHERE id = ?")->execute([$cid]);
    check('ושוב תקין — אסימון', $app->token('u1')['connected'] === true);

    // cfg['sub'] — האפליקציה יכולה לקבוע את ה-sub בעצמה (לא חובה)
    $own = new Drives(['client' => 'vector-studio', 'name' => 'וקטורי', 'dataDir' => "$tmp/app",
        'endpoint' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php', 'back' => 'https://mbe-plus.com/mini_projects/vector-studio/',
        'user' => fn() => 'u9', 'http' => $cloud, 'sub' => fn(string $u) => $u === 'u9' ? '2' : null]);
    check("cfg['sub'] — גובר על PLATFORM_USER", $q2($own->connectUrl('u9'))['sub'] === '2' && code(fn() => $own->connectUrl('u8')) === 'no_platform_account');

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
    check('drives_claim (מהגרסה עם הקוד) כבר לא קיים', code(fn() => $app->handle('drives_claim', ['code' => 'x'])) === 'unauthorized');
    check('בלי משתמש — שאר הפעולות עדיין 401', code(fn() => $app->handle('drives_status', [])) === 'unauthorized'
        && code(fn() => $app->handle('drives_token', [])) === 'unauthorized' && code(fn() => $app->handle('drives_connect', [])) === 'unauthorized');
    $user = 'u1';

    // ─── שכבות — מתג לכל שכבה (spec-0-vision.md 7.3) ───
    $user = 'u1';
    $app->disconnect('u1');
    $st = $app->status('u1')['suite'];
    check('שכבות: לא מחובר — כל המתגים כבויים, רק השכבות שהוצהרו', $st['connected'] === false && $st['layers'] === ['files' => false, 'libs' => false, 'ai' => false]);
    $q = $q2($app->connectUrl('u1', (fn() => $this->scopeOf(['layers' => ['files']]))->call($app)));
    check('connect({layers:[files]}) — מבקש רק files', $q['scope'] === 'files');
    $app->callback('u1', $q2(approve($app->connectUrl('u1', 'files'))));
    $st = $app->status('u1')['suite'];
    check('שכבות: אחרי חיבור לקבצים — רק 📁 דלוק', $st['connected'] && $st['layers'] === ['files' => true, 'libs' => false, 'ai' => false]);
    $cid = $conns('u1')['connection'];
    $q = $q2($app->connectUrl('u1', (fn() => $this->scopeOf(['layers' => ['libs']]))->call($app)));
    check('הדלקת 📚 — מבקש רק libs', $q['scope'] === 'libs');
    platformAs('1');
    check('מסך האישור בחיבור קיים — מציג רק את השכבה החדשה', ($live = liveConnection(requireAccount()['id'], 'vector-studio'))
        && array_values(array_diff(connectRequest($q)['scope'], json_decode($live['scope'], true))) === ['libs']);
    $app->callback('u1', $q2(approve($app->connectUrl('u1', 'libs'))));
    $st = $app->status('u1')['suite'];
    check('הדלקת שכבה — אותו חיבור, 📁 ו-📚 דלוקים', $conns('u1')['connection'] === $cid && $st['layers'] === ['files' => true, 'libs' => true, 'ai' => false]);
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $app->token('u1')['token'];
    check('שכבה דלוקה — הפעולה שלה עובדת', actor('fonts_list', 'libs', '')['app'] === 'vector-studio');
    $e = null; try { actor('ai_capabilities', 'ai', ''); } catch (AppError $x) { $e = $x; }
    check('שכבה כבויה — 403 עם layer (המודול: layer-off)', $e && $e->status === 403 && ($e->extra['layer'] ?? '') === 'ai');
    $r = $app->handle('drives_disconnect', ['layers' => ['libs']]);
    check('כיבוי 📚 — מיד, החיבור נשאר', $r['suite']['connected'] && $r['suite']['layers'] === ['files' => true, 'libs' => false, 'ai' => false]
        && $conns('u1')['connection'] === $cid);
    $e = null; try { actor('fonts_list', 'libs', ''); } catch (AppError $x) { $e = $x; }
    check('— גם האסימון שכבר הונפק לא פותח את 📚', $e && ($e->extra['layer'] ?? '') === 'libs');
    unset($_SERVER['HTTP_X_CLOUD_TOKEN']);
    check('כיבוי שכבה שלא הוצהרה — bad_request', code(fn() => $app->handle('drives_disconnect', ['layers' => ['appdata']])) === 'bad_request');
    // כיבוי בדף הענן ("🔗 חיבורים") — המתג באפליקציה מתעדכן ב-status הבא
    $app->callback('u1', $q2(approve($app->connectUrl('u1', 'libs,ai'))));
    platformAs('1');
    ownConnectionScope(requireAccount(), $cid, ['files', 'ai']);
    check('כיבוי בדף הענן — המתג באפליקציה כבוי ב-status הבא', $app->status('u1')['suite']['layers'] === ['files' => true, 'libs' => false, 'ai' => true]);
    $r = $app->handle('drives_disconnect', ['layers' => ['files', 'ai']]);
    check('כיבוי השכבה האחרונה — ניתוק, גם בענן', $r['suite']['connected'] === false && $conns('u1') === false
        && code(fn() => connectionRow($cid, 'vector-studio')) === 'disconnected');
    // ניתוק בדף הענן — status מתעדכן
    $app->callback('u1', $q2(approve($app->connectUrl('u1'))));
    platformAs('1');
    ownConnectionScope(requireAccount(), $conns('u1')['connection'], []);
    check('ניתוק בדף הענן — status הבא: לא מחובר', $app->status('u1')['suite']['connected'] === false && $conns('u1') === false);
    // אפליקציה שהצהירה רק על קבצים
    $one = new Drives(['client' => 'vector-studio', 'name' => 'וקטורי', 'dataDir' => "$tmp/app",
        'endpoint' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php', 'back' => 'https://mbe-plus.com/mini_projects/vector-studio/',
        'user' => fn() => 'u1', 'http' => $cloud, 'layers' => ['files']]);
    check('אפליקציה עם שכבה אחת — מתג אחד, וחיבור רק אליה', $one->status('u1')['suite']['layers'] === ['files' => false]
        && $q2($one->connectUrl('u1', (fn() => $this->scopeOf([]))->call($one)))['scope'] === 'files'
        && code(fn() => $one->handle('drives_connect', ['layers' => ['libs']])) === 'bad_request');

    check('🗂️ appdata — לא בברירת המחדל (רק למי שהצהיר)', !array_key_exists('appdata', $app->status('u1')['suite']['layers']));
    $ad = new Drives(['client' => 'vector-studio', 'name' => 'וקטורי', 'dataDir' => "$tmp/app",
        'endpoint' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php', 'back' => 'https://mbe-plus.com/mini_projects/vector-studio/',
        'user' => fn() => 'u1', 'http' => $cloud, 'layers' => ['files', 'appdata']]);
    check('🗂️ appdata — אפליקציה שהצהירה: מתג, ובקשה ל-files,appdata', array_keys($ad->status('u1')['suite']['layers']) === ['files', 'appdata']
        && (fn() => $this->scopeOf([]))->call($ad) === 'files,appdata' && (fn() => $this->scopeOf(['layers' => ['appdata']]))->call($ad) === 'appdata');

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
