<?php
/**
 * spec-5 — שני סוגי משתמשים: עם כונן ובלי כונן. נטען מתוך tests/run.php (אותו מסד זמני, check(), code()).
 * הבדיקות לפי docs/spec-5-account-tiers.md סעיף 7 ו-8 (ה1–ה10), ומ7–מ8 של spec-6.
 */

declare(strict_types=1);

/** @var array $owner */

// ── הכנה: tr-app מצהירה 📁 🗂️ 🤖; tr-files — 📁 בלבד. D — עם כונן, N — בלי כונן ──
$trPair = sodium_crypto_sign_keypair();
$trSk = sodium_crypto_sign_secretkey($trPair);
$GLOBALS['CLOUD_FETCH'] = function (string $url) use ($trPair) {
    preg_match('#/mini_projects/([a-z0-9-]+)/#', $url, $m);
    return json_encode(['client' => $m[1] ?? '', 'keys' => [['kid' => 'tr1', 'alg' => 'Ed25519', 'key' => base64_encode(sodium_crypto_sign_publickey($trPair))]]]);
};
$GLOBALS['CLOUD_APPS'] = ['tr-app' => ['layers' => ['files', 'appdata', 'ai']], 'tr-files' => ['layers' => ['files']]];
$trRet = fn(string $c) => "https://mbe-plus.com/mini_projects/$c/api.php?action=drives_callback";
foreach (['tr-app', 'tr-files'] as $c) {
    registerClient(['client' => $c, 'name' => "אפליקציה $c", 'key_url' => "https://mbe-plus.com/mini_projects/$c/api.php?action=drives_key",
                    'origins' => ['https://mbe-plus.com'], 'return' => [$trRet($c)]]);
    policyAll($c);
}
$trId = fn(string $sub, bool $granted) => ['sub' => $sub, 'name' => "משתמש $sub", 'email' => "$sub@example.com", 'granted' => $granted];
as_platform($trId('tr-d', true));
$D = requireAccount();
adminAccount($owner, ['account' => $D['id'], 'quota' => 10 * 1024 * 1024]);
$D = getAccount($D['id']);
$GLOBALS['NO_DRIVE']['tr-n'] = true;   // grant_status → false
as_platform($trId('tr-n', false));
$N = requireAccount();
check('בלי כונן — החשבון נוצר (connect.php / me.php ב-signed_in), ומסומן drive=0 בניהול', (int) $N['drive'] === 0 && (int) $D['drive'] === 1);

/** חיבור כמו connect.php (השער כותב granted) → קוד → החלפה → אסימון. מחזיר [exchange, token]. */
$trConnect = function (array $acc, bool $granted, string $client) use ($trSk, $trRet, $trId): array {
    as_platform($trId((string) $acc['platform_sub'], $granted));
    $state = 'tr' . bin2hex(random_bytes(6));
    $sub = (string) $acc['platform_sub']; $exp = (string) (time() + 600);
    $q = ['client' => $client, 'state' => $state, 'return' => $trRet($client), 'sub' => $sub, 'exp' => $exp,
          'sig' => 'tr1.' . b64u(sodium_crypto_sign_detached("connect\n$client\n$state\n$sub\n$exp\n" . $trRet($client), $trSk))];
    $a = requireAccount();
    $req = connectRequest($q);
    parse_str((string) parse_url(approveConnect($a, $req), PHP_URL_QUERY), $bq);
    as_platform(null);   // מכאן — שרת האפליקציה, בלי עוגייה: הסוג מ-grant_status
    $ex = exchangeCode(activeClient($client), ['code' => $bq['code'], 'sub' => $sub]);
    return [$ex, issueToken(activeClient($client), connectionRow($ex['connection'], $client))];
};
/** פעולה עם אסימון, כמו ב-api.php. */
$trDo = function (array $tok, string $action, string $scope): array {
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $tok['token'];
    try { return actor($action, $scope, ''); } finally { unset($_SERVER['HTTP_X_CLOUD_TOKEN']); }
};
$trErr = function (callable $fn): ?AppError { try { $fn(); return null; } catch (AppError $e) { return $e; } };

// ה1 + מ7 — בלי כונן: החיבור נוצר עם מה שזמין לו בלבד
[$exN, $tokN] = $trConnect($N, false, 'tr-app');
check('ה1 / מ7: בלי כונן — החיבור עם 🗂️ בלבד (📁 ו-🤖 הודלקו, אבל לא זמינים לו)', $exN['scope'] === ['appdata'] && $tokN['scope'] === ['appdata']);
check('ה1: access_token — available: 📁 ו-🤖 false, 🗂️ ו-📚 true', $tokN['available'] === ['files' => false, 'libs' => true, 'appdata' => true, 'ai' => false]);
$_SERVER['HTTP_X_CLOUD_TOKEN'] = $tokN['token'];
$put = appdataPut(appdataCtx('appdata_put', '', []), ['collection' => 's', 'key' => 'k', 'value' => 1]);
unset($_SERVER['HTTP_X_CLOUD_TOKEN']);
check('ה1: appdata_put עובד — 🗂️ תמיד, בלי תלות בהרשאה', $put['rev'] === 1);
$e = $trErr(fn() => $trDo($tokN, 'write', 'files'));
check('ה1: write — 403 no_drive עם layer files (לא forbidden)', $e && $e->status === 403 && $e->codeName === 'no_drive' && ($e->extra['layer'] ?? '') === 'files');

// ה2 + מ8 — אפליקציה שכל מה שהודלק בה דורש כונן
check('ה2 / מ8: בלי כונן, ואפליקציה עם 📁 בלבד — אין היקף (connect.php: "נדרש כונן", חזרה ?error=no_drive)', accountScope(getClient('tr-files'), $N) === []
    && accountScope(getClient('tr-files'), $D) === ['files']);
check('ה2: connect.php — המסך והחזרה עם error=no_drive, בלי approveConnect', (function () {
    $src = (string) file_get_contents(__DIR__ . '/../connect.php');
    $i = strpos($src, 'if (!accountScope($req[\'client\'], $a))');
    return $i !== false && $i < strpos($src, 'approveConnect($a, $req)') && str_contains(substr($src, $i, 900), "'error' => 'no_drive'");
})());

// ה3 — הרשאה שהוסרה: 📁 נחסם בקריאה הבאה (אחרי המטמון), הקבצים נשארים, וההרשאה חוזרת בלי אישור מחדש
[, $tokD] = $trConnect($D, true, 'tr-files');
$fD = writeFile($D, ['name' => 'שלי.txt', 'content_b64' => base64_encode('d')]);
check('ה3: עם כונן — read עובד', $trDo($tokD, 'read', 'files')['files_mode'] === 'full');
$GLOBALS['NO_DRIVE']['tr-d'] = true;
check('ה3: ההרשאה הוסרה — בתוך 60 שניות עדיין מהמטמון', $trDo($tokD, 'read', 'files')['app'] === 'tr-files');
db()->exec('UPDATE grant_cache SET at = at - 61');
$e = $trErr(fn() => $trDo($tokD, 'read', 'files'));
check('ה3: אחרי המטמון — 403 no_drive, והקובץ לא נמחק', $e && $e->codeName === 'no_drive' && nodeRow($D, $fD['id'])['id'] === $fD['id']);
$connD = connectionRow((string) json_decode((string) unb64u(explode('.', $tokD['token'])[0]), true)['conn'], 'tr-files');
$t = issueToken(activeClient('tr-files'), $connD);
check('ה3: issueToken — 📁 יוצא מההיקף, ו-available.files false', $t['scope'] === [] && $t['available']['files'] === false);
unset($GLOBALS['NO_DRIVE']['tr-d']);
db()->exec('DELETE FROM grant_cache');
check('ה3: ההרשאה חזרה — read עובד, בלי אישור מחדש', $trDo($tokD, 'read', 'files')['app'] === 'tr-files');

// ה4 — הפלטפורמה לא עונה: סגור ל-📁 ו-🤖, פתוח ל-🗂️
[, $tokDA] = $trConnect($D, true, 'tr-app');
$GLOBALS['NO_DRIVE']['tr-d'] = null;
db()->exec('DELETE FROM grant_cache');
$e = $trErr(fn() => $trDo($tokDA, 'read', 'files'));
check('ה4: grant_status לא עונה — 📁 503 platform_unavailable', $e && $e->status === 503 && $e->codeName === 'platform_unavailable');
check('ה4: — ו-🗂️ עובד', $trDo($tokDA, 'appdata_get', 'appdata')['app'] === 'tr-app');
check('ה4: לא ענתה — לא נשמר במטמון (הבקשה הבאה שואלת שוב)', (int) db()->query('SELECT COUNT(*) FROM grant_cache')->fetchColumn() === 0);
unset($GLOBALS['NO_DRIVE']['tr-d']);

// ה5 — 📚 בלי כונן: קריאה כן, font_add לא (הוא שומר קובץ)
[, $tokNL] = (function () use ($trConnect, $N) {
    $GLOBALS['CLOUD_APPS']['tr-lib'] = ['layers' => ['libs']];
    registerClient(['client' => 'tr-lib', 'name' => 'ספריות', 'key_url' => 'https://mbe-plus.com/mini_projects/tr-lib/api.php?action=drives_key',
                    'origins' => ['https://mbe-plus.com'], 'return' => ['https://mbe-plus.com/mini_projects/tr-lib/api.php?action=drives_callback']]);
    policyAll('tr-lib');
    return $trConnect($N, false, 'tr-lib');
})();
$xL = $trDo($tokNL, 'fonts_list', 'libs');
check('ה5: בלי כונן — fonts_list עם builtin עובד', is_array(fontsList($xL['account'], true)['fonts'] ?? null));
check('ה5: font_add — 403 no_drive (api.php בודק requireDrive אחרי actor)', code(fn() => requireDrive($xL['account'], 'files')) === 'no_drive'
    && str_contains((string) file_get_contents(__DIR__ . '/../api.php'), "requireDrive(\$x['account'], 'files');   // spec-5 3.3"));

// ה6 — "רק אותו חשבון" גם בלי כונן
check('ה6: בלי כונן, sub אחר ב-connect.php — "החיבור נדחה"', !sameSub($N, 'tr-d') && sameSub($N, 'tr-n'));

// ה7 — granted לא ניתן לזיוף מכותרת
$_SERVER['HTTP_PLATFORM_USER'] = json_encode($trId('tr-n', true));
as_platform($trId('tr-n', false));
check('ה7: HTTP_PLATFORM_USER לא נקרא — granted מהשער בלבד', identityGranted($N) === false && accountGranted($N) === false);
unset($_SERVER['HTTP_PLATFORM_USER']);
check('ה7: עוגייה בדף הענן (api.php) עם granted:false — 📁 no_drive גם שם', code(fn() => actor('list', 'files', '')) === 'no_drive');
as_platform(null);

// ה9 — 🤖 בלי כונן: ai_capabilities / ai_run — 403 no_drive
$GLOBALS['NO_DRIVE']['tr-n'] = true;
policyAll('tr-app');
$cN = connectionRow($exN['connection'], 'tr-app');
$e = $trErr(fn() => actorFrom($cN, activeClient('tr-app'), 'ai'));
check('ה9: ai — 403 no_drive (גם כשהמנהל הדליק 🤖)', $e && $e->codeName === 'no_drive' && ($e->extra['layer'] ?? '') === 'ai');

// platform_key — המפתח שהפלטפורמה מאמתת מולו (provision.key_url), ב-public
$pk = platformKeyJson();
$app = json_decode((string) file_get_contents(__DIR__ . '/../app.json'), true);
check('platform_key — {client: mbe-cloud, keys:[Ed25519]}, ב-public, ו-provision.key_url מצביע עליו', $pk['client'] === 'mbe-cloud'
    && strlen(base64_decode($pk['keys'][0]['key'])) === 32 && str_contains($app['public'][0], '|platform_key|')
    && str_ends_with($app['provision']['key_url'], 'api.php?action=platform_key'));
check('grant_status — חתימה לפי docs/sso.md §6 (X-Embed-*, "grant_status\\n<time>\\n<nonce>\\n<sha256>")', (function () {
    $src = (string) file_get_contents(__DIR__ . '/../lib/tier.php');
    return str_contains($src, '"grant_status\\n$time\\n$nonce\\n" . hash(\'sha256\', $body)') && str_contains($src, "'X-Embed-Client: mbe-cloud'")
        && platformBase() === 'https://mbe-plus.com/mini_projects/platform/';
})());

// ה10 + ה11 — me.php ו-me-api.php: נפתחים למשתמש בלי כונן; רשימה סגורה; חיבורים (🔒), נתוני אפליקציות, ייצוא ומחיקה
require_once __DIR__ . '/../me-api.php';
as_platform($trId('tr-n', false));
$J = 'application/json';
$me = meApi('me', 'GET', '', false, []);
$links = meApi('connections', 'GET', '', false, [])['items'];
$mine = array_values(array_filter($links, fn($c) => $c['client'] === 'tr-app'))[0] ?? null;
check('ה10: me-api me — granted false, ושימוש ב-🗂️ מול התקרה (בלי מכסת כונן)', $me['granted'] === false && $me['account']['id'] === $N['id']
    && $me['appdata']['quota'] === (int) setting('appdata_account_quota') && !isset($me['quota']));
check('ה10 / ה11: connections עונה — 📁 ו-🤖 "🔒 לא זמין בחשבון שלך", 🗂️ פעיל', $mine && $mine['layers'] === ['files' => 'locked', 'appdata' => 'on', 'ai' => 'locked']);
check('ה10: appdata_apps — נתוני האפליקציות שלו', in_array('tr-app', array_column(meApi('appdata_apps', 'GET', '', false, [])['items'], 'client'), true));
check('ה11: רשימה סגורה — list, write, admin, connection_revoke → 404', code(fn() => meApi('list', 'GET', '', false, [])) === 'not_found'
    && code(fn() => meApi('write', 'POST', $J, false, [])) === 'not_found' && code(fn() => meApi('admin', 'GET', '', false, [])) === 'not_found'
    && code(fn() => meApi('connection_revoke', 'POST', $J, false, [])) === 'not_found');
check('ה11: POST שאינו JSON — 400; GET לפעולת כתיבה — 400', code(fn() => meApi('appdata_wipe', 'POST', 'application/x-www-form-urlencoded', false, ['client' => 'tr-app'])) === 'bad_request'
    && code(fn() => meApi('appdata_wipe', 'GET', $J, false, ['client' => 'tr-app'])) === 'bad_request');
check('ה11: Authorization / אסימון / חתימה — 400 (רק השער)', code(fn() => meApi('me', 'GET', '', true, [])) === 'bad_request');
check('ה11: me-api.php לא עושה require ל-api.php', !preg_match('#require[^;]*api\.php#', (string) file_get_contents(__DIR__ . '/../me-api.php')));
check('ה10: ייצוא', meApi('account_export', 'POST', $J, false, [])['user'] === $N['id']);
meApi('appdata_wipe', 'POST', $J, false, ['client' => 'tr-app']);
check('ה10: 🗑️ מחק נתונים של אפליקציה', !in_array('tr-app', array_column(meApi('appdata_apps', 'GET', '', false, [])['items'], 'client'), true));
check('ה10: מחיקת חשבון — רק עם אישור', code(fn() => meApi('account_delete', 'POST', $J, false, ['confirm' => 'כן'])) === 'bad_request');
meApi('account_delete', 'POST', $J, false, ['confirm' => 'DELETE']);
check('ה10: מחיקת החשבון — החשבון והחיבורים נמחקו', code(fn() => getAccount($N['id'])) === 'not_found'
    && (int) db()->query('SELECT COUNT(*) FROM connections WHERE account = ' . db()->quote($N['id']))->fetchColumn() === 0);
check('ה11: signed_in — connect.php, me.php, me-api.php בלבד; restricted נשאר', $app['signed_in'] === ['connect.php', 'me.php', 'me-api.php']
    && $app['restricted'] === true && !array_intersect($app['signed_in'], ['index.html', 'picker.php', 'api.php']));
check('ה11: me.php מדבר רק עם me-api.php', str_contains((string) file_get_contents(__DIR__ . '/../assets/js/me.js'), 'me-api.php?action=')
    && !str_contains((string) file_get_contents(__DIR__ . '/../assets/js/me.js'), "'api.php"));
as_platform(null);

unset($GLOBALS['CLOUD_APPS'], $GLOBALS['NO_DRIVE']['tr-n']);
