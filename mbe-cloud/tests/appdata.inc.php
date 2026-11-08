<?php
/**
 * שלב 4 — נתוני משתמש (appdata). נטען מתוך tests/run.php (אותו מסד זמני, אותם $owner, check() ו-code()).
 * הבדיקות לפי docs/spec-4-appdata.md סעיף 10 (ב1–ב12), ועוד: מכסת החשבון (ש1), "🧩 נתוני אפליקציות" ו-layer-off.
 */

declare(strict_types=1);

/** @var array $owner */

// ── הכנה: שתי אפליקציות (A, B) שהצהירו על 🗂️, ושני משתמשים (U, V) ──
$adPair = sodium_crypto_sign_keypair();
$adSk = sodium_crypto_sign_secretkey($adPair);
$GLOBALS['CLOUD_FETCH'] = function (string $url) use ($adPair) {
    preg_match('#/mini_projects/([a-z0-9-]+)/#', $url, $m);
    return json_encode(['client' => $m[1] ?? '', 'keys' => [['kid' => 'ad1', 'alg' => 'Ed25519', 'key' => base64_encode(sodium_crypto_sign_publickey($adPair))]]]);
};
$GLOBALS['CLOUD_APPS'] = ['ad-alpha' => ['layers' => ['files', 'appdata']], 'ad-beta' => ['layers' => ['files', 'appdata']],
                          'ad-plain' => ['layers' => ['files']]];
$adRet = fn(string $c) => "https://mbe-plus.com/mini_projects/$c/api.php?action=drives_callback";
foreach (['ad-alpha', 'ad-beta', 'ad-plain'] as $c) {
    registerClient(['client' => $c, 'name' => "אפליקציה $c", 'key_url' => "https://mbe-plus.com/mini_projects/$c/api.php?action=drives_key",
                    'origins' => ['https://mbe-plus.com'], 'return' => [$adRet($c)]]);
}
as_platform(['sub' => 'ad-u', 'name' => 'אורי', 'email' => 'uri@example.com']);
$U = requireAccount();
as_platform(['sub' => 'ad-v', 'name' => 'ורד', 'email' => 'vered@example.com']);
$V = requireAccount();
adminAccount($owner, ['account' => $U['id'], 'quota' => 100 * 1024 * 1024]);
adminAccount($owner, ['account' => $V['id'], 'quota' => 100 * 1024 * 1024]);
$U = getAccount($U['id']); $V = getAccount($V['id']);

/** אסימון של משתמש באפליקציה, דרך הזרימה האמיתית: connect → approve → exchange → access_token. */
$adToken = function (array $acc, string $client, string $scope = 'files,appdata') use ($adSk, $adRet): string {
    $state = 'ad' . bin2hex(random_bytes(6));
    $sub = (string) $acc['platform_sub'];
    $exp = (string) (time() + 600);
    $q = ['client' => $client, 'scope' => $scope, 'state' => $state, 'return' => $adRet($client), 'sub' => $sub, 'exp' => $exp,
          'sig' => 'ad1.' . b64u(sodium_crypto_sign_detached("connect\n$client\n$state\n$sub\n$exp\n" . $adRet($client), $adSk))];
    parse_str((string) parse_url(approveConnect($acc, connectRequest($q)), PHP_URL_QUERY), $bq);
    $ex = exchangeCode(activeClient($client), ['code' => $bq['code'], 'sub' => $sub]);
    return issueToken(activeClient($client), connectionRow($ex['connection'], $client))['token'];
};

/** הקשר מאסימון (הדפדפן). */
$asToken = function (string $tok, array $in = [], string $action = 'appdata_get'): array {
    foreach (['CLIENT', 'TIME', 'NONCE', 'SIGNATURE', 'CONNECTION'] as $k) unset($_SERVER["HTTP_X_CLOUD_$k"]);
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $tok;
    try { return appdataCtx($action, json_encode($in), $in); } finally { unset($_SERVER['HTTP_X_CLOUD_TOKEN']); }
};

/** הקשר משרת האפליקציה, חתום, בלי חיבור (מרחב app). */
$asServer = function (string $client, array $in, string $action = 'appdata_put') use ($adSk): array {
    unset($_SERVER['HTTP_X_CLOUD_TOKEN'], $_SERVER['HTTP_X_CLOUD_CONNECTION']);
    $body = json_encode($in);
    $t = (string) time(); $n = bin2hex(random_bytes(16));
    $_SERVER['HTTP_X_CLOUD_CLIENT'] = $client; $_SERVER['HTTP_X_CLOUD_TIME'] = $t; $_SERVER['HTTP_X_CLOUD_NONCE'] = $n;
    $_SERVER['HTTP_X_CLOUD_SIGNATURE'] = 'ad1.' . base64_encode(sodium_crypto_sign_detached("$action\n$t\n$n\n" . hash('sha256', $body), $adSk));
    try { return appdataCtx($action, $body, $in); }
    finally { foreach (['CLIENT', 'TIME', 'NONCE', 'SIGNATURE'] as $k) unset($_SERVER["HTTP_X_CLOUD_$k"]); }
};

$tUA = $adToken($U, 'ad-alpha');
$tUB = $adToken($U, 'ad-beta');
$tVA = $adToken($V, 'ad-alpha');
$cUA = $asToken($tUA);
check('appdata: הקשר — client מהאסימון, מרחב user, החשבון מהחיבור', $cUA['client'] === 'ad-alpha' && $cUA['space'] === 'user' && $cUA['account'] === $U['id']);

// ב1
$r1 = appdataPut($cUA, ['collection' => 'settings', 'key' => 'chart', 'value' => ['zoom' => 2, 'ת' => 'א']]);
$g = appdataGet($cUA, ['collection' => 'settings', 'key' => 'chart']);
$r2 = appdataPut($cUA, ['collection' => 'settings', 'key' => 'chart', 'value' => ['zoom' => 3, 'ת' => 'א']]);
check('ב1: put ← get מחזיר את הערך ו-rev 1; put נוסף — rev 2', $r1['rev'] === 1 && $g['value'] === ['zoom' => 2, 'ת' => 'א'] && $g['rev'] === 1 && $r2['rev'] === 2);

// ב2
$e = null; try { appdataPut($cUA, ['collection' => 'settings', 'key' => 'chart', 'value' => 1, 'if_rev' => 1]); } catch (AppError $x) { $e = $x; }
check('ב2: if_rev שגוי — 409 conflict עם rev ו-value הנוכחיים', $e && $e->status === 409 && $e->codeName === 'conflict'
    && $e->extra['rev'] === 2 && $e->extra['value'] === ['zoom' => 3, 'ת' => 'א']);
check('ב2: if_rev 0 על מסמך קיים — 409', code(fn() => appdataPut($cUA, ['collection' => 'settings', 'key' => 'chart', 'value' => 1, 'if_rev' => 0])) === 'conflict');
check('ב2: if_rev 0 על מסמך חדש — יצירה', appdataPut($cUA, ['collection' => 'settings', 'key' => 'new', 'value' => true, 'if_rev' => 0])['rev'] === 1);
check('ב2: if_rev נכון — עובר', appdataPut($cUA, ['collection' => 'settings', 'key' => 'chart', 'value' => ['zoom' => 4], 'if_rev' => 2])['rev'] === 3);

// ב3
$cUB = $asToken($tUB);
$cVA = $asToken($tVA);
check('ב3: אפליקציה אחרת, אותו משתמש — 404, ו-list ריק', code(fn() => appdataGet($cUB, ['collection' => 'settings', 'key' => 'chart'])) === 'not_found'
    && appdataList($cUB, [])['items'] === []);
check('ב3: משתמש אחר, אותה אפליקציה — 404, ו-list ריק', code(fn() => appdataGet($cVA, ['collection' => 'settings', 'key' => 'chart'])) === 'not_found'
    && appdataList($cVA, [])['items'] === []);

// ב4
$cB4 = $asToken($tUB, ['client' => 'ad-alpha', 'account' => $U['id']]);
check('ב4: client ו-account בגוף — מתעלמים מהם', $cB4['client'] === 'ad-beta'
    && code(fn() => appdataGet($cB4, ['client' => 'ad-alpha', 'collection' => 'settings', 'key' => 'chart'])) === 'not_found');

// ב5
$tNoScope = $adToken($U, 'ad-alpha', 'files');   // אותו חיבור — ההיקף מורחב בלבד, לכן חיבור של אפליקציה בלי appdata:
$tPlain = $adToken($U, 'ad-plain', 'files');
$e = null; try { $asToken($tPlain); } catch (AppError $x) { $e = $x; }
check('ב5: בלי ההיקף appdata — 403 עם scope appdata (layer-off במודול)', $e && $e->status === 403 && ($e->extra['scope'] ?? '') === 'appdata'
    && ($e->extra['layer'] ?? '') === 'appdata');
check('ב5: אפליקציה שלא הצהירה על 🗂️ — לא יכולה לבקש אותו (bad_request)', code(fn() => $adToken($U, 'ad-plain', 'files,appdata')) === 'bad_request');
// כיבוי 🗂️ בחיבור קיים — מיד
$conn = connectionRow((string) json_decode((string) unb64u(explode('.', $tUA)[0]), true)['conn'], 'ad-alpha');
narrowScope($conn, ['files']);
check('ב5: כיבוי 🗂️ — האסימון שכבר הונפק נחסם מיד', code(fn() => $asToken($tUA)) === 'forbidden');
narrowScope(connectionRow($conn['id'], 'ad-alpha'), []);   // ומחזירים: היקף מורחב רק דרך connect — חיבור מחדש
$tUA = $adToken($U, 'ad-alpha');
$cUA = $asToken($tUA);

// ב6 — מרחב app
$sA = $asServer('ad-alpha', ['space' => 'app', 'collection' => 'catalog', 'key' => 'v1', 'value' => ['n' => 1]]);
check('ב6: שרת חתום בלי חיבור — כותב במרחב app', $sA['space'] === 'app' && $sA['write'] === true
    && appdataPut($sA, ['space' => 'app', 'collection' => 'catalog', 'key' => 'v1', 'value' => ['n' => 1]])['rev'] === 1);
$bA = $asToken($tUA, ['space' => 'app']);
check('ב6: הדפדפן קורא את מרחב app', appdataGet($bA, ['collection' => 'catalog', 'key' => 'v1'])['value'] === ['n' => 1]);
check('ב6: הדפדפן לא כותב במרחב app — 403', code(fn() => appdataPut($bA, ['collection' => 'catalog', 'key' => 'v1', 'value' => 2])) === 'forbidden');
check('ב6: שרת חתום בלי חיבור — מרחב user אסור', code(fn() => $asServer('ad-alpha', ['collection' => 'x', 'key' => 'y', 'value' => 1])) === 'bad_request');
check('ב6: מרחב app של אפליקציה אחרת — לא נראה', code(fn() => appdataGet($asToken($tUB, ['space' => 'app']), ['collection' => 'catalog', 'key' => 'v1'])) === 'not_found');
check('ב6: מרחב app — updated_by server, account ריק', db()->query("SELECT updated_by || '|' || account FROM appdata WHERE client = 'ad-alpha' AND space = 'app'")->fetchColumn() === 'server|');
db()->exec("UPDATE clients SET status = 'disabled' WHERE id = 'ad-beta'");
check('ב6: לקוח כבוי — לא קורא ולא כותב', code(fn() => $asServer('ad-beta', ['space' => 'app', 'collection' => 'c', 'key' => 'k', 'value' => 1])) === 'unknown_client'
    && in_array(code(fn() => $asToken($tUB)), ['unknown_client', 'disconnected'], true));
db()->exec("UPDATE clients SET status = 'active' WHERE id = 'ad-beta'");

// ב7
$big = str_repeat('א', 131072);   // 262144 בתים + מרכאות
$e = null; try { appdataPut($cUA, ['collection' => 'big', 'key' => 'k', 'value' => $big]); } catch (AppError $x) { $e = $x; }
check('ב7: מסמך מעל 256KB — 413 too_large', $e && $e->status === 413 && $e->codeName === 'too_large');
check('ב7: מסמך של בדיוק 256KB — עובר', appdataPut($cUA, ['collection' => 'big', 'key' => 'k', 'value' => str_repeat('a', 262142)])['rev'] === 1);
db()->exec("UPDATE clients SET appdata_user_quota = 300000 WHERE id = 'ad-alpha'");
$e = null; try { appdataPut($cUA, ['collection' => 'big', 'key' => 'k2', 'value' => str_repeat('b', 100000)]); } catch (AppError $x) { $e = $x; }
check('ב7: חריגה מתקרת הזוג — 413 quota_exceeded עם scope user', $e && $e->codeName === 'quota_exceeded' && $e->extra['scope'] === 'user' && $e->extra['quota'] === 300000);
check('ב7: החלפת מסמך באותו גודל — לא נחסמת (הישן לא נספר פעמיים)', appdataPut($cUA, ['collection' => 'big', 'key' => 'k', 'value' => str_repeat('c', 262142)])['rev'] === 2);
db()->exec("UPDATE clients SET appdata_user_quota = NULL WHERE id = 'ad-alpha'");
db()->exec("UPDATE clients SET appdata_app_quota = 10 WHERE id = 'ad-alpha'");
$e = null; try { appdataPut($sA, ['space' => 'app', 'collection' => 'catalog', 'key' => 'v2', 'value' => str_repeat('x', 20)]); } catch (AppError $x) { $e = $x; }
check('ב7: חריגה במרחב app — scope app', $e && $e->codeName === 'quota_exceeded' && $e->extra['scope'] === 'app');
db()->exec("UPDATE clients SET appdata_app_quota = NULL WHERE id = 'ad-alpha'");
check('usage: נפח ותקרה של המרחב', appdataUsage($cUA) === ['used' => appdataUsed($cUA), 'quota' => 20971520] && appdataUsage($sA)['quota'] === 52428800);

// ש1 — הוכרע: מקום נפרד. ברירת המחדל — לא במכסת הכונן, ותקרה של 100MB למשתמש בכל האפליקציות
check('ש1 (הכרעה): ברירת המחדל — appdata לא במכסת הכונן', setting('appdata_counts_in_quota') === '0'
    && usedBytes($U['id']) === (int) db()->query("SELECT COALESCE(SUM(size),0) FROM nodes WHERE account = " . db()->quote($U['id']) . " AND kind = 'file'")->fetchColumn()
        + (int) db()->query("SELECT COALESCE(SUM(size),0) FROM lib_items WHERE account = " . db()->quote($U['id']))->fetchColumn());
adminAccount($owner, ['account' => $V['id'], 'quota' => 0]);
$cVA = $asToken($tVA);
$vUsed = usedBytes($V['id']);
check('ש1 (הכרעה): משתמש עם מכסת כונן 0 שומר מסמך, ו-usedBytes של הכונן לא משתנה',
    appdataPut($cVA, ['collection' => 's0', 'key' => 'k', 'value' => ['a' => 1]])['rev'] === 1 && usedBytes($V['id']) === $vUsed);
appdataDelete($cVA, ['collection' => 's0', 'key' => 'k']);
setSetting('appdata_account_quota', '300000');
$e = null; try { appdataPut($asToken($tUB), ['collection' => 'big', 'key' => 'k', 'value' => str_repeat('d', 100000)]); } catch (AppError $x) { $e = $x; }
check('ב7: חריגה מתקרת המשתמש בכל האפליקציות — 413 quota_exceeded עם scope account', $e && $e->codeName === 'quota_exceeded'
    && $e->extra['scope'] === 'account' && $e->extra['quota'] === 300000);
setSetting('appdata_account_quota', '104857600');
// והמתג למנהל עדיין עובד בשני הכיוונים
setSetting('appdata_counts_in_quota', '1');
$usedBefore = usedBytes($U['id']);
check('ש1 (דלוק): נתוני האפליקציות נספרים במכסת החשבון', $usedBefore >= appdataAccountBytes($U['id']) && appdataAccountBytes($U['id']) > 262142);
$cVA = $asToken($tVA);
check('ש1 (דלוק): משתמש עם מכסה 0 — לא שומר אפילו הגדרות', code(fn() => appdataPut($cVA, ['collection' => 's', 'key' => 'k', 'value' => 1])) === 'quota_exceeded');
setSetting('appdata_counts_in_quota', '0');
check('ש1 (כבוי): מכסה 0 — שומר, ולא נספר במכסת החשבון', appdataPut($cVA, ['collection' => 's', 'key' => 'k', 'value' => 1])['rev'] === 1
    && usedBytes($U['id']) === $usedBefore - appdataAccountBytes($U['id']));
setSetting('appdata_counts_in_quota', '0');
adminAccount($owner, ['account' => $V['id'], 'quota' => 100 * 1024 * 1024]);

// ב8
$since = appdataChanges($cUA, ['since' => 0])['cursor'];
appdataPut($cUA, ['collection' => 'w', 'key' => 'a', 'value' => 1]);
appdataPut($cUA, ['collection' => 'w', 'key' => 'b', 'value' => 2]);
appdataDelete($cUA, ['collection' => 'w', 'key' => 'a']);
$ch = appdataChanges($cUA, ['since' => $since]);
check('ב8: changes — שתי כתיבות ומחיקה, לפי seq, המחיקה deleted', array_map(fn($c) => $c['key'] . ($c['deleted'] ? '-' : '+'), $ch['changes']) === ['b+', 'a-']
    && $ch['changes'][0]['seq'] < $ch['changes'][1]['seq'] && $ch['more'] === false);
$all = appdataChanges($cUA, ['since' => 0, 'limit' => 500])['changes'];
check('ב8: changes מ-0 — כל מסמך פעם אחת, בגרסה האחרונה שלו', count($all) === 5
    && count(array_unique(array_map(fn($c) => $c['collection'] . '/' . $c['key'], $all))) === 5);
$p = appdataChanges($cUA, ['since' => 0, 'limit' => 2]);
check('ב8: limit ו-more, cursor ממשיך', count($p['changes']) === 2 && $p['more'] === true
    && appdataChanges($cUA, ['since' => $p['cursor'], 'limit' => 500])['changes'][0]['seq'] > $p['cursor']);
check('ב8: since מעבר למונה — 410 gone', code(fn() => appdataChanges($cUA, ['since' => 10 ** 9])) === 'gone');
db()->prepare("UPDATE appdata SET deleted_at = ? WHERE client = 'ad-alpha' AND deleted_at IS NOT NULL")->execute([nowIso(time() - 31 * 86400)]);
appdataPurgeMaybe(true);
check('ב8: מצבה שנמחקה אחרי 30 יום — since מתחתיה 410', code(fn() => appdataChanges($cUA, ['since' => $since])) === 'gone');
check('ב8: get של מחוק — 404; delete שוב — 404', code(fn() => appdataGet($cUA, ['collection' => 'w', 'key' => 'a'])) === 'not_found'
    && code(fn() => appdataDelete($cUA, ['collection' => 'w', 'key' => 'a'])) === 'not_found');
$re = appdataPut($cUA, ['collection' => 'w', 'key' => 'b', 'value' => 3]);
appdataDelete($cUA, ['collection' => 'w', 'key' => 'b']);
check('ב8: מחיקה ויצירה מחדש — rev ממשיך', appdataPut($cUA, ['collection' => 'w', 'key' => 'b', 'value' => 4])['rev'] === $re['rev'] + 1);

// ב9
for ($i = 1; $i <= 7; $i++) appdataPut($cUA, ['collection' => 'h', 'key' => 'doc', 'value' => ['v' => $i]]);
$h = appdataHistory($cUA, ['collection' => 'h', 'key' => 'doc']);
check('ב9: history — עד 5 גרסאות, מהחדשה', array_column($h['items'], 'rev') === [6, 5, 4, 3, 2]);
$rs = appdataRestore($cUA, ['collection' => 'h', 'key' => 'doc', 'rev' => 3]);
check('ב9: restore — הערך הישן כ-rev חדש', $rs['rev'] === 8 && appdataGet($cUA, ['collection' => 'h', 'key' => 'doc'])['value'] === ['v' => 3]);
check('ב9: restore של גרסה שלא קיימת — 404', code(fn() => appdataRestore($cUA, ['collection' => 'h', 'key' => 'doc', 'rev' => 1])) === 'not_found');
check('ב9: restore עם if_rev שגוי — 409', code(fn() => appdataRestore($cUA, ['collection' => 'h', 'key' => 'doc', 'rev' => 4, 'if_rev' => 1])) === 'conflict');

// list ו-get_many
for ($i = 0; $i < 5; $i++) appdataPut($cUA, ['collection' => 'watch', 'key' => "2026/1$i", 'value' => $i]);
appdataPut($cUA, ['collection' => 'watch', 'key' => '2025/x', 'value' => 9]);
$l = appdataList($cUA, ['collection' => 'watch', 'prefix' => '2026/', 'limit' => 3]);
$l2 = appdataList($cUA, ['collection' => 'watch', 'prefix' => '2026/', 'limit' => 3, 'cursor' => $l['cursor']]);
check('list: prefix, limit ו-cursor, בלי ערכים', count($l['items']) === 3 && !isset($l['items'][0]['value']) && $l['cursor'] !== null
    && array_column(array_merge($l['items'], $l2['items']), 'key') === ['2026/10', '2026/11', '2026/12', '2026/13', '2026/14'] && $l2['cursor'] === null);
check('list: בלי collection — כל האוספים', count(array_unique(array_column(appdataList($cUA, ['limit' => 500])['items'], 'collection'))) >= 4);
$gm = appdataGetMany($cUA, ['collection' => 'watch', 'keys' => ['2026/10', 'אין', '2025/x']]);
check('get_many: קיימים בלבד, עם ערכים', array_column($gm['items'], 'value', 'key') === ['2025/x' => 9, '2026/10' => 0]);
check('get_many: יותר מ-100 מפתחות — 400', code(fn() => appdataGetMany($cUA, ['collection' => 'watch', 'keys' => range(1, 101)])) === 'bad_request');

// ב12 ו-ולידציה
check('ב12: value null — 400', code(fn() => appdataPut($cUA, ['collection' => 's', 'key' => 'k', 'value' => null])) === 'bad_request');
check('ב12: בלי value (גוף לא תקין מגיע כ-[]) — 400', code(fn() => appdataPut($cUA, ['collection' => 's', 'key' => 'k'])) === 'bad_request');
check('ולידציה: אוסף לא חוקי, מפתח ארוך או עם תו בקרה, מרחב לא מוכר', code(fn() => appdataPut($cUA, ['collection' => 'Bad', 'key' => 'k', 'value' => 1])) === 'bad_request'
    && code(fn() => appdataPut($cUA, ['collection' => 's', 'key' => str_repeat('k', 201), 'value' => 1])) === 'bad_request'
    && code(fn() => appdataPut($cUA, ['collection' => 's', 'key' => "a\nb", 'value' => 1])) === 'bad_request'
    && code(fn() => $asToken($tUA, ['space' => 'other'])) === 'bad_request');
check('ולידציה: מפתח עם / ועברית — תקין', appdataPut($cUA, ['collection' => 's', 'key' => '2026/11/ביטקוין', 'value' => 1])['rev'] === 1);

// "🧩 נתוני אפליקציות" (דף הענן)
$apps = appdataApps($U);
check('🧩: שורה לכל אפליקציה עם נתונים — נפח ומסמכים, בלי תוכן', ($row = array_values(array_filter($apps['items'], fn($a) => $a['client'] === 'ad-alpha'))[0] ?? null)
    && $row['name'] === 'אפליקציה ad-alpha' && $row['used'] > 0 && $row['docs'] > 5 && !isset($row['value']));
check('🧩: מרחב app לא מופיע אצל המשתמש', count($apps['items']) === 1);
check('ניהול: נפח לכל אפליקציה ושני המרחבים — בלי תוכן', ($ad = array_values(array_filter(adminClients(), fn($c) => $c['id'] === 'ad-alpha'))[0])
    && $ad['appdata']['used']['user'] > 0 && $ad['appdata']['used']['app'] > 0);
adminClient(['client' => 'ad-alpha', 'appdata_user_quota' => 5 * 1024 * 1024]);
check('ניהול: תקרה לאפליקציה, ו-null חוזר לברירת המחדל', appdataUsage($cUA)['quota'] === 5242880
    && adminClient(['client' => 'ad-alpha', 'appdata_user_quota' => null]) && appdataUsage($cUA)['quota'] === 20971520);

// ב11 — ייצוא (לפני ה-clear)
$exp = accountExport($U);
check('ב11: account_export — מרחב user לפי אפליקציה, בלי app ובלי גרסאות', ($exp['appdata']['ad-alpha']['h']['doc'] ?? null) === ['v' => 3]
    && !isset($exp['appdata']['ad-alpha']['catalog']) && !isset($exp['appdata']['ad-beta']));

// ב10
appdataPut($cUB, ['collection' => 'keep', 'key' => 'k', 'value' => 1]);
$floorU = fn() => (int) db()->query("SELECT seq FROM appdata_floor WHERE client = 'ad-alpha' AND account = " . db()->quote($U['id']))->fetchColumn();
$sinceB = appdataChanges($cUA, ['since' => $floorU(), 'limit' => 500]);
check('ב10: clear בלי confirm — 400; במרחב app — 400', code(fn() => appdataClear($cUA, [])) === 'bad_request'
    && code(fn() => appdataClear($asToken($tUA, ['space' => 'app']), ['confirm' => 'clear'])) === 'bad_request');
$cl = appdataClear($cUA, ['confirm' => 'clear']);
check('ב10: clear — כל המסמכים והגרסאות של הזוג נמחקים', $cl['deleted'] > 5 && appdataList($cUA, [])['items'] === []
    && (int) db()->query("SELECT COUNT(*) FROM appdata_history WHERE client = 'ad-alpha' AND space = 'user' AND account = " . db()->quote($U['id']))->fetchColumn() === 0);
check('ב10: אחרים לא נפגעו — אפליקציה אחרת, משתמש אחר, מרחב app', appdataGet($cUB, ['collection' => 'keep', 'key' => 'k'])['value'] === 1
    && appdataGet($cVA, ['collection' => 's', 'key' => 'k'])['value'] === 1 && appdataGet($bA, ['collection' => 'catalog', 'key' => 'v1'])['rev'] === 1);
check('ב10: changes מ-since ישן — 410', code(fn() => appdataChanges($cUA, ['since' => 0])) === 'gone' && code(fn() => appdataChanges($cUA, ['since' => $sinceB['cursor']])) === 'gone');
appdataPut($cUA, ['collection' => 's', 'key' => 'after', 'value' => 1]);
check('ב10: אחרי clear — כותבים שוב, ו-changes מהרצפה עובד', appdataChanges($cUA, ['since' => $floorU()])['changes'][0]['key'] === 'after');
check('🗑️ מדף הענן — מוחק את הנתונים של אפליקציה אחת אצלי', appdataWipeOwn($U, 'ad-beta')['deleted'] === 1
    && code(fn() => appdataGet($cUB, ['collection' => 'keep', 'key' => 'k'])) === 'not_found');

// ב11 — מחיקת חשבון
accountDelete(getAccount($V['id']));
check('ב11: account_delete — כל ה-appdata של המשתמש נמחק, ומרחב app נשאר',
    (int) db()->query('SELECT COUNT(*) FROM appdata WHERE account = ' . db()->quote($V['id']))->fetchColumn() === 0
    && (int) db()->query("SELECT COUNT(*) FROM appdata WHERE client = 'ad-alpha' AND space = 'app'")->fetchColumn() === 1);

unset($GLOBALS['CLOUD_APPS']);
