<?php
/**
 * 11 — המצב המנותק (lib/sso.php): קריטריונים 🤖 2 (כניסה עם הכלים הדיגיטליים; חתימה שגויה נדחית) ו-3 (הרשאה שבוטלה ←
 * נחסם). בלי רשת: "הפלטפורמה" כאן — זוג מפתחות שהבדיקה מחזיקה, ו-grant_status מדומה ב-$GLOBALS['sf_http_post'].
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

const SFT_PLATFORM = 'https://platform.example.test/root/';
const SFT_RETURN = 'https://sforno.example.test/';

$pkp = sodium_crypto_sign_keypair();          // המפתח של "הפלטפורמה"
$other = sodium_crypto_sign_keypair();        // מפתח של מישהו אחר
$b64u = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$launchFile = SF_DATA_DIR . '/launch.json';
$setLaunch = function (?array $c) use ($launchFile) {
    if ($c === null) { @unlink($launchFile); return; }
    file_put_contents($launchFile, json_encode($c, JSON_UNESCAPED_SLASHES));
};
$detached = ['detached' => true, 'platform' => SFT_PLATFORM, 'sso_key' => $b64u(sodium_crypto_sign_publickey($pkp)), 'return' => SFT_RETURN];

/** זהות חתומה כמו ש-platform/sso.php מחזיר. */
$token = function (array $over = [], ?string $key = null) use ($pkp, $b64u): string {
    $p = $b64u((string)json_encode($over + ['iss' => SFT_PLATFORM, 'aud' => 'sforno', 'sub' => '501', 'name' => 'רחל מנהלת',
        'email' => 'rachel@example.test', 'email_verified' => true, 'state' => '', 'return' => SFT_RETURN,
        'iat' => sf_time(), 'exp' => sf_time() + 120, 'jti' => bin2hex(random_bytes(8))], JSON_UNESCAPED_UNICODE));
    return $p . '.' . $b64u(sodium_crypto_sign_detached($p, $key ?? sodium_crypto_sign_secretkey($pkp)));
};

// grant_status המדומה: מי מורשה, ומה נשלח
$GLOBALS['t_grant'] = ['501' => true, '502' => true, '503' => false];
$GLOBALS['t_grant_down'] = false;
$GLOBALS['t_posts'] = [];
$GLOBALS['sf_http_post'] = function (string $url, string $body, array $headers) {
    $GLOBALS['t_posts'][] = [$url, $body, $headers];
    if ($GLOBALS['t_grant_down']) return null;
    $sub = (string)(json_decode($body, true)['sub'] ?? '');
    return [200, json_encode(['allowed' => $GLOBALS['t_grant'][$sub] ?? false])];
};

/** "דפדפן": עוגיות sf_staff ו-sf_sso משלו. */
$jars = [];
$req = function (string $method, string $action, array $in = [], string $b = 'A', array $env = []) use (&$jars): array {
    unset($GLOBALS['sf_cookie_out'], $GLOBALS['sf_sso_cookie_out']);
    try {
        $body = sf_dispatch($method, $action, ['action' => $action], $in, $env + ['platform_user' => null,
            'cookie' => $jars[$b]['staff'] ?? '', 'sso_cookie' => $jars[$b]['sso'] ?? '', 'csrf' => $jars[$b]['csrf'] ?? '',
            'ua' => 'test', 'content_type' => 'application/json', 'ip' => '203.0.113.9', 'sec_fetch_site' => 'same-origin', 'origin' => '']);
        $st = 200;
    } catch (Throwable $e) {
        [$st, $body] = sf_error_response($e);
        if ($st === 500) echo '  500: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
    if (isset($GLOBALS['sf_cookie_out'])) $jars[$b]['staff'] = $GLOBALS['sf_cookie_out'];
    if (isset($GLOBALS['sf_sso_cookie_out'])) $jars[$b]['sso'] = $GLOBALS['sf_sso_cookie_out'];
    if ($action === 'me' && $st === 200) $jars[$b]['csrf'] = $body['csrf'];
    return [$st, $body];
};
/** כניסה מלאה: start → state מהכתובת → טוקן → finish. */
$login = function (string $b, array $over = []) use ($req, $token): array {
    [$st, $r] = $req('POST', 'sso-start', [], $b);
    if ($st !== 200) return [$st, $r];
    parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
    return $req('POST', 'sso-finish', ['token' => $token($over + ['state' => $q['state']]), 'state' => $q['state']], $b);
};

// ── בלי data/launch.json — שום דבר לא משתנה ──────────────────
call('GET', 'health');
$staffBefore = (int)sf_val('SELECT COUNT(*) FROM staff');
foreach ([['POST', 'sso-start'], ['POST', 'sso-finish'], ['GET', 'platform-key']] as [$m, $a]) {
    $r = $req($m, $a);
    eq([$r[0], code($r)], [404, 'unknown_action'], "בלי launch.json: $a לא קיימת");
}
$r = $req('GET', 'me');
eq([$r[0], code($r)], [401, 'login_required'], 'בלי launch.json: בלי זהות מהשער — 401 כמו ב-01');
eq($req('GET', 'me', [], 'Z', ['platform_user' => ident('900', 'מחובר')])[0], 200, 'בלי launch.json: הזהות מהשער עובדת כמו קודם');
eq(sf_meta('sso_sign_key'), null, 'בלי launch.json: לא נוצר מפתח');
eq($GLOBALS['t_posts'], [], 'בלי launch.json: אין בקשה ל-grant_status');

// ── launch.json בלי "detached": רק המפתח, כדי לרשום אותו בפלטפורמה לפני המעבר ──
$setLaunch(['detached' => false] + $detached);
[$st, $k] = $req('GET', 'platform-key');
eq($st, 200, 'platform-key — עם launch.json');
eq([$k['client'] ?? null, $k['keys'][0]['alg'] ?? null], ['sforno', 'Ed25519'], 'platform-key: client ו-alg');
$pub = base64_decode((string)($k['keys'][0]['key'] ?? ''), true);
eq(strlen((string)$pub), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'platform-key: מפתח ציבורי של 32 בתים');
check('platform-key: בלי המפתח הפרטי', !str_contains(json_encode($k), base64_encode(sodium_crypto_sign_secretkey(sf_unseal(sf_meta('sso_sign_key'))))));
check('המפתח הפרטי במסד — מוצפן', str_starts_with((string)sf_meta('sso_sign_key'), 's1:'));
eq($req('GET', 'platform-key')[1]['keys'][0]['key'], $k['keys'][0]['key'], 'platform-key: אותו מפתח בבקשה שנייה');
eq(code($req('POST', 'sso-start')), 'unknown_action', 'לא מנותקת — sso-start לא קיימת');
eq(code($req('GET', 'me')), 'login_required', 'לא מנותקת — me כמו ב-01');

// ── K2: מנותקת — כניסה עם הכלים הדיגיטליים ───────────────────
$setLaunch($detached);
$r = $req('GET', 'me', [], 'A');
eq([$r[0], code($r)], [401, 'sso_required'], 'K2 מנותקת, בלי session — 401 sso_required (הכפתור)');
eq($req('POST', 'sso-start', [], 'A', ['content_type' => 'text/plain'])[0], 415, 'K2 sso-start: רק application/json');
eq($req('POST', 'sso-start', [], 'A', ['sec_fetch_site' => 'cross-site'])[0], 403, 'K2 sso-start: מאתר אחר — 403');
[$st, $r] = $req('POST', 'sso-start', [], 'A');
eq($st, 200, 'K2 sso-start');
$u = parse_url((string)$r['url']);
parse_str((string)($u['query'] ?? ''), $q);
eq($u['scheme'] . '://' . $u['host'] . $u['path'], SFT_PLATFORM . 'platform/sso.php', 'K2 sso-start: לכתובת המעטפת מ-launch.json');
eq([$q['client'] ?? null, $q['return'] ?? null], ['sforno', SFT_RETURN], 'K2 sso-start: client ו-return');
check('K2 state אקראי — 48 hex', preg_match('/^[a-f0-9]{48}$/', (string)($q['state'] ?? '')) === 1);
eq($jars['A']['sso'] ?? null, $q['state'], 'K2 ה-state בעוגיית sf_sso');
check('K2 ה-state לא נשמר כמו שהוא במסד', !sf_val('SELECT 1 FROM meta WHERE v = ? OR k LIKE ?', [$q['state'], '%' . $q['state'] . '%']));
$state = $q['state'];
$n = (int)sf_val('SELECT COUNT(*) FROM staff');
$r = $req('POST', 'sso-finish', ['token' => $token(['state' => $state], sodium_crypto_sign_secretkey($other)), 'state' => $state], 'A');
eq([$r[0], code($r)], [401, 'sso'], 'K2 חתימה של מפתח אחר — נדחית');
eq((int)sf_val('SELECT COUNT(*) FROM staff'), $n, 'K2 חתימה שגויה — לא נפתח מנהל');
eq($jars['A']['staff'] ?? '', '', 'K2 חתימה שגויה — אין עוגיית מנהל');
eq(sf_val("SELECT meta FROM audit_log WHERE action = 'staff.sso_fail' ORDER BY id DESC LIMIT 1"), '{"why":"signature"}', 'K2 ביומן: signature, בלי הטוקן');
eq($req('POST', 'sso-finish', ['token' => $token(['state' => $state]), 'state' => $state], 'A')[0], 401, 'K2 ה-state נוצל בניסיון הקודם — חד-פעמי');

// כל אחת מבדיקות sso.md §2 בנפרד — כל ניסיון בכניסה חדשה (state חדש)
$bad = [
    'aud של אפליקציה אחרת' => ['aud' => 'migdalor'],
    'iss אחר' => ['iss' => 'https://evil.example.test/'],
    'return אחר' => ['return' => 'https://evil.example.test/'],
    'פג תוקף' => ['exp' => sf_time() - 1],
    'sub לא ספרות' => ['sub' => 'abc'],
];
foreach ($bad as $name => $over) {
    $r = $login('A', $over);
    eq([$r[0], code($r)], [401, 'sso'], "K2 $name — נדחית");
}
// payload ששונה אחרי החתימה
[, $r] = $req('POST', 'sso-start', [], 'A');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
[$p, $sig] = explode('.', $token(['state' => $q['state']]));
$p2 = $b64u(str_replace('"sub":"501"', '"sub":"777"', (string)base64_decode(strtr($p, '-_', '+/'))));
eq(code($req('POST', 'sso-finish', ['token' => "$p2.$sig", 'state' => $q['state']], 'A')), 'sso', 'K2 payload ששונה — נדחה');
// state מהעוגייה ≠ state בבקשה / בטוקן
[, $r] = $req('POST', 'sso-start', [], 'A');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
eq(code($req('POST', 'sso-finish', ['token' => $token(['state' => str_repeat('a', 48)]), 'state' => str_repeat('a', 48)], 'A')), 'sso', 'K2 state שלא מהעוגייה — נדחה');
[, $r] = $req('POST', 'sso-start', [], 'A');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
eq(code($req('POST', 'sso-finish', ['token' => $token(['state' => $q['state']]), 'state' => $q['state']], 'B')), 'sso', 'K2 דפדפן אחר (בלי העוגייה) — נדחה');
[, $r] = $req('POST', 'sso-start', [], 'A');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
eq(code($req('POST', 'sso-finish', ['error' => 'access_denied', 'state' => $q['state']], 'A')), 'sso', 'K2 המשתמש ביטל — 401');
[, $r] = $req('POST', 'sso-start', [], 'A');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
$GLOBALS['sf_time'] = sf_time() + 601;
eq(code($req('POST', 'sso-finish', ['token' => $token(['state' => $q['state']]), 'state' => $q['state']], 'A')), 'sso', 'K2 state אחרי 10 דקות — נדחה');
unset($GLOBALS['sf_time']);
eq((int)sf_val('SELECT COUNT(*) FROM staff'), $n, 'K2 אף ניסיון שנדחה לא פתח מנהל');
eq($GLOBALS['t_posts'], [], 'K2 זהות שנדחתה — אין בקשה ל-grant_status');

// ביקורת ⚪3: אותו state בשתי בקשות (שני חלונות עם אותה עוגייה) — רק אחת עוברת, והשורה נמחקת אטומית
[, $r] = $req('POST', 'sso-start', [], 'R1');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
$jars['R2'] = $jars['R1'];
$tk = $token(['sub' => '502', 'email' => 'race@example.test', 'state' => $q['state']]);
$r1 = $req('POST', 'sso-finish', ['token' => $tk, 'state' => $q['state']], 'R1');
$r2 = $req('POST', 'sso-finish', ['token' => $tk, 'state' => $q['state']], 'R2');
eq([$r1[0], $r2[0], code($r2)], [200, 401, 'sso'], 'K2 אותו state פעמיים — רק הראשונה');
eq((int)sf_val("SELECT COUNT(*) FROM meta WHERE k LIKE 'sso_state:%' AND k = ?", ['sso_state:' . hash('sha256', $q['state'])]), 0, 'K2 ה-state נמחק');
sf_exec("DELETE FROM staff_sessions WHERE staff_id = (SELECT id FROM staff WHERE platform_sub = '502')");
sf_exec("DELETE FROM staff WHERE platform_sub = '502'");
$GLOBALS['t_posts'] = [];
// ⚪3: שורה שפג תוקפה — לא נמחקת בבקשה (DELETE … v >= now), ונדחית
[, $r] = $req('POST', 'sso-start', [], 'A');
parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
sf_meta_set('sso_state:' . hash('sha256', $q['state']), (string)(sf_time() - 1));
eq(code($req('POST', 'sso-finish', ['token' => $token(['state' => $q['state']]), 'state' => $q['state']], 'A')), 'sso', 'K2 state שפג (בשורה) — נדחה');

// הכניסה התקינה
$r = $login('A');
eq($r[0], 200, 'K2 כניסה תקינה');
$s = staff_by_sub('501');
eq([$s['status'] ?? null, $s['email'] ?? null, $s['name'] ?? null], ['active', 'rachel@example.test', 'רחל מנהלת'], 'K2 נפתח מנהל פעיל לפי ה-sub');
eq((int)$s['is_developer'], 0, 'K2 מנהל חדש מ-sso — לא מפתח');
check('K2 עוגיית מנהל', preg_match('/^[a-f0-9]{64}$/', (string)($jars['A']['staff'] ?? '')) === 1);
eq($jars['A']['sso'] ?? null, '', 'K2 עוגיית ה-state נמחקה');
eq(audit_count('staff.sso_login'), 2, 'K2 ביומן: staff.sso_login (גם של בדיקת ה-state הכפול)');
[$st, $me] = $req('GET', 'me', [], 'A');
eq([$st, $me['staff']['pid'] ?? null], [200, $s['pid']], 'K2 me עם העוגייה');
eq($req('POST', 'logout', [], 'A')[0], 200, 'K2 POST עם CSRF של ה-session');
eq(code($req('GET', 'me', [], 'A')), 'sso_required', 'K2 אחרי יציאה — שוב הכפתור');

// הבקשה ל-grant_status — חתומה במפתח שב-platform-key, כמו embed.md §2
[$url, $body, $h] = $GLOBALS['t_posts'][0];
eq($url, SFT_PLATFORM . 'platform/provision.php?action=grant_status', 'K2 grant_status — לכתובת המעטפת');
eq($body, '{"sub":"501"}', 'K2 grant_status — רק sub');
[$kid, $sigB64] = explode('.', (string)$h['X-Embed-Signature'], 2);
eq([$h['X-Embed-Client'], $kid], ['sforno', $k['keys'][0]['kid']], 'K2 grant_status — client ו-kid');
check('K2 grant_status — החתימה מאומתת במפתח הציבורי', sodium_crypto_sign_verify_detached((string)base64_decode($sigB64),
    "grant_status\n{$h['X-Embed-Time']}\n{$h['X-Embed-Nonce']}\n" . hash('sha256', $body), (string)$pub));
check('K2 grant_status — nonce של 16 בתים', preg_match('/^[a-f0-9]{32}$/', (string)$h['X-Embed-Nonce']) === 1);

// איחוד לפי מייל, ומפתח שנשאר מפתח
sf_insert('staff', ['pid' => sf_pid(), 'platform_sub' => null, 'email' => 'old@example.test', 'email_verified' => 0, 'name' => 'ותיק',
    'is_developer' => 1, 'status' => 'active', 'created_at' => sf_now(), 'updated_at' => sf_now()]);
eq($login('C', ['sub' => '502', 'email' => 'old@example.test', 'name' => 'ותיק'])[0], 200, 'K2 מנהל קיים באותו מייל');
eq([(int)(staff_by_sub('502')['is_developer'] ?? 0), staff_by_sub('502')['name'] ?? null], [1, 'ותיק'], 'K2 מתקשר לחשבון הקיים, ונשאר מפתח');
eq(audit_count('staff.developer_off'), 0, 'K2 sso לא מוריד "מפתח"');

// בזהות מהשער — גם כשמנותקת, כמו קודם
eq($req('GET', 'me', [], 'Z', ['platform_user' => ident('900', 'מחובר')])[0], 200, 'K2 זהות מהשער — עדיין עובדת');

// הגדרה חסרה — נכשל סגור
$setLaunch(['detached' => true, 'platform' => SFT_PLATFORM]);
eq(code($req('POST', 'sso-start', [], 'A')), 'login_unavailable', 'K2 launch.json בלי מפתח — 503, לא כניסה');
eq(code($req('GET', 'me', [], 'C')), 'login_unavailable', 'K2 launch.json בלי מפתח — גם session קיים לא עובר');
$setLaunch(['detached' => true, 'platform' => 'http://platform.example.test/', 'sso_key' => $detached['sso_key'], 'return' => SFT_RETURN]);
eq(code($req('POST', 'sso-start', [], 'A')), 'login_unavailable', 'K2 מעטפת בלי https — 503');
$setLaunch($detached);

// ── K3: ההרשאה בוטלה בפלטפורמה ← נחסם ────────────────────────
$n = (int)sf_val('SELECT COUNT(*) FROM staff');
$r = $login('D', ['sub' => '503', 'email' => 'no@example.test']);
eq([$r[0], code($r)], [403, 'no_grant'], 'K3 בלי הרשאה בפלטפורמה — 403 בכניסה');
eq((int)sf_val('SELECT COUNT(*) FROM staff'), $n, 'K3 בלי הרשאה — לא נפתח מנהל');
eq($jars['D']['staff'] ?? '', '', 'K3 בלי הרשאה — אין עוגייה');

eq($login('A')[0], 200, 'K3 נכנס שוב');
$posts = count($GLOBALS['t_posts']);
eq($req('GET', 'me', [], 'A')[0], 200, 'K3 בקשה אחרי הכניסה');
eq(count($GLOBALS['t_posts']), $posts, 'K3 התשובה נשמרת — אין grant_status בכל בקשה');
$GLOBALS['t_grant']['501'] = false;                         // המנהל בפלטפורמה הסיר את ההרשאה
eq($req('GET', 'me', [], 'A')[0], 200, 'K3 בתוך 5 דקות — עוד מהמטמון');
$GLOBALS['sf_time'] = time() + 301;
$r = $req('GET', 'me', [], 'A');
eq([$r[0], code($r)], [403, 'no_grant'], 'K3 אחרי 5 דקות — נחסם');
eq((int)sf_val('SELECT COUNT(*) FROM staff_sessions WHERE staff_id = ?', [$s['id']]), 0, 'K3 ה-session נמחק');
eq(code($req('GET', 'me', [], 'A')), 'sso_required', 'K3 ובבקשה הבאה — שוב כניסה');
$r = $login('A');
eq([$r[0], code($r)], [403, 'no_grant'], 'K3 כניסה הבאה — נחסם');

// הכניסה תמיד שואלת מחדש, גם כשבמטמון "מותר"
$GLOBALS['t_grant']['502'] = true;
eq($req('GET', 'me', [], 'C')[0], 200, 'K3 מנהל אחר — מורשה (במטמון)');
$GLOBALS['t_grant']['502'] = false;
eq(code($login('C', ['sub' => '502', 'email' => 'old@example.test'])), 'no_grant', 'K3 בכניסה — שאלה טרייה, לא המטמון');

// הפלטפורמה לא עונה — נכשל סגור, וה-session לא נמחק
$GLOBALS['t_grant']['501'] = true;
eq($login('A')[0], 200, 'K3 ההרשאה הוחזרה — נכנס');
$GLOBALS['sf_time'] = time() + 700;
$GLOBALS['t_grant_down'] = true;
eq(code($req('GET', 'me', [], 'A')), 'login_unavailable', 'K3 הפלטפורמה לא עונה — 503, לא כניסה');
eq(code($login('E', ['sub' => '501'])), 'login_unavailable', 'K3 הפלטפורמה לא עונה — כניסה חדשה נכשלת');
$GLOBALS['t_grant_down'] = false;
eq($req('GET', 'me', [], 'A')[0], 200, 'K3 חזרה — ה-session עדיין שלו');

// מנהל שהושעה בספורנו — נחסם גם כשמורשה בפלטפורמה
sf_exec("UPDATE staff SET status = 'suspended' WHERE platform_sub = '501'");
eq(code($req('GET', 'me', [], 'A')), 'suspended', 'K3 מושעה בספורנו — 403');
eq(code($login('A')), 'suspended', 'K3 מושעה — לא נכנס');
unset($GLOBALS['sf_time']);

$setLaunch(null);
eq(code($req('GET', 'me', [], 'C')), 'login_required', 'בלי launch.json שוב — חזרה להתנהגות של 01');

done();
