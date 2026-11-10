<?php
// פרוסה 02 — מצב מנותק ואישור חוזר (docs/spec/02-invites-detached.md §5–6): 02-B1 … 02-B8, 02-C1, מקרי הקצה 6–10.
// הכתובות לא נגזרות מהבקשה: גם עם Host זר — הכול לפי הכתובת הקבועה (tests/lib.php: example.test)
$_SERVER['SERVER_NAME'] = 'evil.example';
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['SCRIPT_NAME'] = '/x/api.php';
require __DIR__ . '/lib.php';

// ── המעטפת המדומה: config, והמפתח שבו היא חותמת על זהויות ──
$GLOBALS['t_pair'] = sodium_crypto_sign_keypair();
$GLOBALS['t_cfg'] = ['connected' => false, 'login' => ['platform' => true, 'password' => true, 'google' => false]];
$GLOBALS['t_key_fetches'] = 0;
$b64u = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$GLOBALS['apr_http'] = function (string $url) use ($b64u) {
    if (str_contains($url, 'action=key')) {
        $GLOBALS['t_key_fetches']++;
        return [200, json_encode(['alg' => 'Ed25519', 'key' => $b64u(sodium_crypto_sign_publickey($GLOBALS['t_pair'])), 'issuer' => 'https://example.test/mini_projects/'])];
    }
    if (str_contains($url, 'action=config')) return $GLOBALS['t_cfg'] === null ? null : [200, json_encode($GLOBALS['t_cfg'])];
    return [403, ''];
};
/** משנה את מה שהמעטפת עונה ב-config, ומנקה את המטמון של 60 השניות. */
function platform_cfg(?array $cfg): void
{
    $GLOBALS['t_cfg'] = $cfg;
    apr_exec("DELETE FROM meta WHERE key = 'platform_config_cache'");
}
function detached(bool $password = true): void
{
    putenv('APR_PLATFORM=detached');
    platform_cfg(['connected' => false, 'login' => ['platform' => true, 'password' => $password]]);
}
function connected(): void
{
    putenv('APR_PLATFORM');
    platform_cfg(['connected' => true, 'login' => ['platform' => true, 'password' => true]]);
}
const RETURN_URL = 'https://example.test/mini_projects/appraisers/api.php?action=sso-callback';
/** "כניסה עם הכלים הדיגיטליים": מחזיר את ה-state שנשלח למעטפת (והעוגייה apr_sso נשמרת, כמו בדפדפן). */
function sso_start(): string
{
    [, $r] = call('GET', 'sso-start');
    parse_str((string)parse_url($r['_redirect'], PHP_URL_QUERY), $q);
    $GLOBALS['t_start'] = ['url' => $r['_redirect'], 'q' => $q];
    return $q['state'];
}
/** זהות חתומה כמו שהמעטפת מחזירה (sso.md §2). $over — שדות לשינוי; $pair — מפתח אחר. */
function sso_token(string $state, array $over = [], ?string $pair = null): string
{
    $b64u = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $payload = $b64u(json_encode($over + ['iss' => 'https://example.test/mini_projects/', 'aud' => 'appraisers', 'sub' => '17', 'name' => 'דנה לוי',
        'email' => 'dana@example.com', 'email_verified' => true, 'state' => $state, 'return' => RETURN_URL, 'iat' => apr_time(), 'exp' => apr_time() + 120,
        'jti' => bin2hex(random_bytes(8))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $payload . '.' . $b64u(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair ?? $GLOBALS['t_pair'])));
}
/** החזרה מהמעטפת, בדפדפן $jar. מחזיר [לאן הופנה, הסיבה הטכנית לדחייה או null, האם נכתבה עוגיית session]. */
function sso_callback(array $get, string $jar = 'browser'): array
{
    unset($GLOBALS['apr_sso_reason']);
    $before = jar()[$jar] ?? null;
    [, $r] = call('GET', 'sso-callback', [], null, $get, ['jar' => $jar]);
    return [$r['_redirect'] ?? null, $GLOBALS['apr_sso_reason'] ?? null, (jar()[$jar] ?? null) !== $before];
}
$me = fn(string $jar) => call('GET', 'me', [], null, [], ['jar' => $jar]);
$HOME = '/mini_projects/appraisers/';

// חשבונות שנפתחו במצב מחוברת
connected();
$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$dana = ident('17', 'דנה לוי', 'dana@example.com');
$A = office_for($owner, 'משרד א');
$danaPid = join_office($owner, $dana, 'dana@example.com');

// ── login-config ──
eq(call('GET', 'login-config')[1], ['ok' => true, 'mode' => 'connected', 'platform' => false, 'password' => false], 'login-config מחוברת: אין מסך כניסה');
detached(false);
eq(call('GET', 'login-config')[1], ['ok' => true, 'mode' => 'detached', 'platform' => true, 'password' => false], '§5.1 מנותקת: "כניסה עם הכלים הדיגיטליים" תמיד; סיסמה — רק כשנפתחה');
detached(true);
eq(call('GET', 'login-config')[1]['password'], true, '§5.1 login.password: true → טופס סיסמה');
platform_cfg(null);
eq(call('GET', 'login-config')[1]['mode'], 'unavailable', '§5.1 config לא עונה → "הכניסה לא זמינה כרגע", בלי טופס');
eq([code(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'x'])), call('POST', 'login', [])[0]], ['login_unavailable', 503], 'config לא עונה: login → 503');
detached();

// ── 02-B2: זהות חתומה תקינה → session, והמשתמש הוא החשבון המקושר ל-sub ──
$state = sso_start();
eq([strlen($state), $GLOBALS['t_start']['q']['client'], $GLOBALS['t_start']['q']['return'], strtok($GLOBALS['t_start']['url'], '?')],
    [32, 'appraisers', RETURN_URL, 'https://example.test/mini_projects/platform/sso.php'], '§5.2 sso-start: state של 32 תווים, client וכתובת החזרה שלנו');
eq(apr_val('SELECT COUNT(*) FROM sso_states WHERE state_hash = ?', [$state]), 0, 'ה-state נשמר כ-hash בלבד');
[$to, $why, $cookie] = sso_callback(['state' => $state, 'token' => sso_token($state)]);
eq([$to, $why, $cookie], [$HOME, null, true], '02-B2 זהות תקינה → נוצר session, וחוזרים לאפליקציה');
[$st, $m] = $me('browser');
eq([$st, $m['user']['pid'] ?? null, $m['mode'] ?? null, $m['office'] ?? null], [200, $danaPid, 'detached', $A], '02-B2 המשתמש הוא החשבון המקושר ל-sub — עם המשרד שלו');
eq((int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'user.login' AND json_extract(meta, '$.via') = 'sso'"), 1, 'הכניסה נרשמת ביומן הפעולות');
// sub חדש → חשבון חדש, פעיל (אותו טיפול כמו זהות מהשער)
$s2 = sso_start();
[$to, , $cookie] = sso_callback(['state' => $s2, 'token' => sso_token($s2, ['sub' => '55', 'name' => 'חדש', 'email' => 'new@example.com'])], 'b2');
eq([$to, $cookie, $me('b2')[1]['user']['email'] ?? null, apr_val("SELECT platform_sub FROM users WHERE email = 'new@example.com'")], [$HOME, true, 'new@example.com', '55'], '02-B2 sub חדש → חשבון חדש, מקושר');
// הבעלים נכנס בזהות חתומה (בלי platform_admin) — נשאר מפתח
$s3 = sso_start();
sso_callback(['state' => $s3, 'token' => sso_token($s3, ['sub' => '1', 'name' => 'הבעלים', 'email' => 'owner@example.com'])], 'owner-d');
eq([$me('owner-d')[1]['user']['is_developer'], $me('owner-d')[1]['user']['is_sysadmin']], [true, true], 'זהות חתומה אינה נושאת platform_admin — הדגלים לא נוגעים');
// חשבון מושעה
apr_exec("UPDATE users SET status = 'suspended' WHERE platform_sub = '55'");
$s4 = sso_start();
eq(sso_callback(['state' => $s4, 'token' => sso_token($s4, ['sub' => '55'])], 'b3'), [$HOME . '#/login/suspended', 'suspended', false], 'חשבון מושעה: בלי session');
eq(code($me('b2')), 'account_suspended', 'וה-session הקיים שלו נחסם');

// ── 02-B1: sso-callback דוחה — שבע בדיקות נפרדות (ועוד) ──
$reject = function (string $name, string $want, callable $make) use ($HOME) {
    $state = sso_start();
    $get = $make($state);
    $n = (int)apr_val('SELECT COUNT(*) FROM sessions');
    eq([...sso_callback($get, 'attacker'), (int)apr_val('SELECT COUNT(*) FROM sessions') - $n], [$HOME . '#/login/invalid', $want, false, 0], "02-B1 $name → נדחה ($want), בלי session");
};
$reject('חתימה שגויה', 'bad_signature', fn($s) => ['state' => $s, 'token' => sso_token($s, [], sodium_crypto_sign_keypair())]);
$reject('aud אחר', 'bad_aud', fn($s) => ['state' => $s, 'token' => sso_token($s, ['aud' => 'migdalor'])]);
$reject('state שלא נוצר כאן', 'state_used_or_expired', function ($s) {
    $fake = bin2hex(random_bytes(16));
    $GLOBALS['t_sso_cookie'] = $fake;                   // גם אם התוקף שתל את העוגייה — ה-state אינו במסד
    return ['state' => $fake, 'token' => sso_token($fake)];
});
$used = sso_start();
sso_callback(['state' => $used, 'token' => sso_token($used)], 'x1');
$GLOBALS['t_sso_cookie'] = $used;
eq(array_slice(sso_callback(['state' => $used, 'token' => sso_token($used)], 'attacker'), 0, 3), [$HOME . '#/login/invalid', 'state_used_or_expired', false], '02-B1 state שכבר נוצל → נדחה');
$reject('exp שעבר', 'expired', fn($s) => ['state' => $s, 'token' => sso_token($s, ['exp' => apr_time() - 61])]);
$reject('return אחר', 'bad_return', fn($s) => ['state' => $s, 'token' => sso_token($s, ['return' => 'https://evil.example/cb'])]);
$jti = 'jti-' . bin2hex(random_bytes(6));
$j1 = sso_start();
eq(sso_callback(['state' => $j1, 'token' => sso_token($j1, ['jti' => $jti])], 'x2')[1], null, 'jti חדש — מתקבל');
$reject('jti שכבר נראה', 'replay', fn($s) => ['state' => $s, 'token' => sso_token($s, ['jti' => $jti])]);
// ועוד: iss, state של דפדפן אחר, state שפג, state בתוך הזהות, ביטול
$reject('iss אחר', 'bad_iss', fn($s) => ['state' => $s, 'token' => sso_token($s, ['iss' => 'https://evil.example/'])]);
$reject('state של דפדפן אחר (העוגייה לא תואמת)', 'state_unknown', function ($s) { $GLOBALS['t_sso_cookie'] = ''; return ['state' => $s, 'token' => sso_token($s)]; });
$reject('זהות שנחתמה ל-state אחר', 'bad_state', fn($s) => ['state' => $s, 'token' => sso_token(bin2hex(random_bytes(16)))]);
$reject('בלי token', 'bad_token', fn($s) => ['state' => $s]);
$reject('sub שאינו מספר', 'bad_sub', fn($s) => ['state' => $s, 'token' => sso_token($s, ['sub' => 'abc'])]);
$old = sso_start();
$GLOBALS['apr_time'] = time() + 601;
eq(sso_callback(['state' => $old, 'token' => sso_token($old)], 'attacker')[1], 'state_used_or_expired', '02-B1 state שפג (10 דקות) → נדחה');
unset($GLOBALS['apr_time']);
$c = sso_start();
eq(array_slice(sso_callback(['state' => $c, 'error' => 'access_denied'], 'attacker'), 0, 2), [$HOME . '#/login/cancelled', 'cancelled'], '§5.2 error בכתובת → "הכניסה בוטלה"');
$GLOBALS['t_sso_cookie'] = $c;
eq(sso_callback(['state' => $c, 'token' => sso_token($c)], 'attacker')[1], 'state_used_or_expired', 'וה-state שלו כבר נוצל');
eq($me('attacker')[0], 401, 'אחרי כל הדחיות — לדפדפן הזה אין session');
// מקרה 8: סובלנות של 60 שניות על exp, ואזהרה בבדיקות התקינות אחרי דחייה
$t = sso_start();
eq(sso_callback(['state' => $t, 'token' => sso_token($t, ['exp' => apr_time() - 30])], 'x3')[1], null, 'מקרה 8: exp שעבר לפני 30 שניות — בתוך הסובלנות');
$health = array_column(call('GET', 'dev-health', [], null, [], ['jar' => 'owner-d'])[1]['checks'] ?? [], null, 'id');
eq($health['sso_clock']['state'] ?? null, 'warn', 'מקרה 8: זהות שנדחתה על תוקף → אזהרה בבדיקות התקינות');
// מקרה 6: המפתח הציבורי של המעטפת התחלף → רענון אחד ובדיקה חוזרת
$GLOBALS['t_pair'] = sodium_crypto_sign_keypair();
$f0 = $GLOBALS['t_key_fetches'];
$r = sso_start();
eq([sso_callback(['state' => $r, 'token' => sso_token($r)], 'x4')[1], $GLOBALS['t_key_fetches'] - $f0], [null, 1], 'מקרה 6: המפתח התחלף — רענון אחד, והזהות מתקבלת');
$r = sso_start();
sso_callback(['state' => $r, 'token' => sso_token($r)], 'x5');
eq($GLOBALS['t_key_fetches'] - $f0, 1, 'המפתח במטמון (24 שעות) — בלי בקשה נוספת');
// במצב מחוברת אין זרימת כניסה
connected();
eq(call('GET', 'sso-start')[1]['_redirect'], $HOME, 'מחוברת: sso-start רק מחזיר לאפליקציה');
detached();

// ── §5.3 קביעת סיסמה, 02-B8: שינוי סיסמה מבטל את שאר ה-sessions ──
eq($me('browser')[1]['has_password'], false, 'עוד לא נקבעה סיסמה');
eq(code(call('POST', 'password-set', ['password' => '123456789'], null, [], ['jar' => 'browser'])), 'password_short', '§5.3 פחות מ-10 תווים — נדחה');
eq(code(call('POST', 'password-set', ['password' => 'correct horse 1'], null, [], ['jar' => 'nobody'])), 'login_required', 'קביעת סיסמה — רק כשמחוברים');
$n = fn() => (int)apr_val('SELECT COUNT(*) FROM sessions WHERE user_id = (SELECT id FROM users WHERE pid = ?)', [$danaPid]);
check('לדנה כמה sessions לפני השינוי', $n() >= 3, $n());
eq(call('POST', 'password-set', ['password' => 'correct horse 1'], null, [], ['jar' => 'browser'])[0], 200, '§5.3 קביעת סיסמה');
eq([$n(), $me('browser')[0], $me('x1')[0], $me('x2')[0]], [1, 200, 401, 401], '02-B8 שינוי סיסמה מבטל את שאר ה-sessions של אותו משתמש — רק הנוכחי נשאר');
eq($me('owner-d')[0], 200, '02-B8 ולא של משתמש אחר');
check('הסיסמה נשמרת כ-hash', password_verify('correct horse 1', (string)apr_val('SELECT password_hash FROM users WHERE pid = ?', [$danaPid])) && $me('browser')[1]['has_password'] === true);
check('me לא מחזיר את ה-hash', !str_contains(json_encode($me('browser')[1]), '$2y$'));

// ── 02-B3: במצב מחוברת login בסיסמה → 403, גם עם סיסמה נכונה ──
connected();
$GLOBALS['t_ip'] = '198.51.100.3';
$n0 = (int)apr_val('SELECT COUNT(*) FROM sessions');
$r = call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'correct horse 1'], null, [], ['jar' => 'b3-login']);
eq([$r[0], code($r), (int)apr_val('SELECT COUNT(*) FROM sessions') - $n0], [403, 'password_login_off', 0], '02-B3 מחוברת: login → 403 גם עם הסיסמה הנכונה, ובלי session');
eq(code(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'correct horse 1'], $dana)), 'password_login_off', '02-B3 גם כשיש זהות מהשער');
eq([code(call('POST', 'forgot', ['email' => 'dana@example.com'])), code(call('POST', 'reset', ['token' => str_repeat('a', 48), 'password' => 'correct horse 2']))], ['password_login_off', 'password_login_off'], 'מחוברת: גם forgot ו-reset חסומות');
eq(code(call('POST', 'logout', [], $dana)), 'forbidden', '§5.4 מחוברת: אין יציאה');

// ── 02-B4: מנותקת — לפי login.password ──
detached(false);
eq([call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'correct horse 1'], null, [], ['jar' => 'b4'])[0], $me('b4')[0]], [403, 401], '02-B4 login.password: false → 403 (מקרה 10)');
detached(true);
$r = call('POST', 'login', ['email' => 'Dana@Example.com ', 'password' => 'correct horse 1'], null, [], ['jar' => 'b4']);
eq([$r[0], $me('b4')[0], $me('b4')[1]['user']['pid'] ?? null], [200, 200, $danaPid], '02-B4 true וסיסמה נכונה → session');
check('02-B4 תשובת login בלי פרטים', $r[1] === ['ok' => true]);

// ── 02-B6: אותה תשובה בדיוק ──
$GLOBALS['t_ip'] = '198.51.100.6';
$strip = function (array $r) { unset($r[1]['req']); return $r; };
$wrong = $strip(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'wrong password'], null, [], ['jar' => 'b6']));
$unknown = $strip(call('POST', 'login', ['email' => 'nobody@example.com', 'password' => 'wrong password'], null, [], ['jar' => 'b6']));
$nopass = $strip(call('POST', 'login', ['email' => 'owner@example.com', 'password' => 'wrong password'], null, [], ['jar' => 'b6']));
eq([$wrong[0], code($wrong)], [401, 'login_failed'], '02-B6 סיסמה שגויה → 401');
eq([$unknown, $nopass], [$wrong, $wrong], '02-B6 מייל לא קיים, סיסמה שגויה וחשבון בלי סיסמה — אותה תשובה בדיוק');
$m0 = count($GLOBALS['t_mail']);
$known = $strip(call('POST', 'forgot', ['email' => 'dana@example.com']));
$m1 = count($GLOBALS['t_mail']);
$none = $strip(call('POST', 'forgot', ['email' => 'nobody@example.com']));
eq([$known, $none, $m1 - $m0, count($GLOBALS['t_mail']) - $m1], [[200, ['ok' => true]], [200, ['ok' => true]], 1, 0], '02-B6 forgot: אותה תשובה לכתובת רשומה ולא רשומה; מייל רק לרשומה');

// ── 02-B7: קישור איפוס עובד פעם אחת בלבד, ולא אחרי שעה ──
preg_match('~^https://example\.test/mini_projects/appraisers/#/reset/([a-f0-9]{48})$~m', end($GLOBALS['t_mail'])['text'], $mm);
check('המייל: קישור #/reset/<אסימון>', isset($mm[1]) && end($GLOBALS['t_mail'])['to'] === 'dana@example.com');
eq(apr_val('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$mm[1]]), 0, 'במסד רק ה-hash של האסימון');
eq(code(call('POST', 'reset', ['token' => $mm[1], 'password' => 'short'])), 'password_short', 'סיסמה קצרה — הקישור לא נשרף');
eq(call('POST', 'reset', ['token' => $mm[1], 'password' => 'brand new pass 2'])[0], 200, '02-B7 הקישור עובד');
eq(code(call('POST', 'reset', ['token' => $mm[1], 'password' => 'brand new pass 3'])), 'reset_invalid', '02-B7 ופעם אחת בלבד');
eq([$n(), $me('b4')[0]], [0, 401], '02-B8 איפוס מנתק את כל ה-sessions של המשתמש');
eq([call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b7'])[0],
    code(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'correct horse 1'], null, [], ['jar' => 'b7x']))], [200, 'login_failed'], 'הסיסמה החדשה עובדת, הישנה לא');
call('POST', 'forgot', ['email' => 'dana@example.com']);
preg_match('~#/reset/([a-f0-9]{48})~', end($GLOBALS['t_mail'])['text'], $late);
$GLOBALS['apr_time'] = time() + 3601;
eq(code(call('POST', 'reset', ['token' => $late[1], 'password' => 'brand new pass 4'])), 'reset_invalid', '02-B7 ולא אחרי שעה');
unset($GLOBALS['apr_time']);
eq(code(call('POST', 'reset', ['token' => 'zz', 'password' => 'brand new pass 4'])), 'reset_invalid', 'אסימון לא תקין — אותה תשובה');
check('02-C2 גם מיילי האיפוס: בלי כתובת ב-mail_log', !str_contains(json_encode(apr_rows('SELECT * FROM mail_log')), '@') && (int)apr_val("SELECT COUNT(*) FROM mail_log WHERE kind = 'reset'") === 2);

// ── 02-B5: 5 כשלים → הניסיון השישי נדחה גם עם הסיסמה הנכונה, 15 דקות ──
$GLOBALS['t_ip'] = '198.51.100.5';
$t0 = time();
$GLOBALS['apr_time'] = $t0;
call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b5-pre']);   // כניסה מוצלחת מאפסת את המונה של המייל
for ($i = 1; $i <= 5; $i++) $last = call('POST', 'login', ['email' => 'dana@example.com', 'password' => "bad $i"], null, [], ['jar' => 'b5']);
eq(code($last), 'login_failed', '02-B5 הכישלון החמישי עדיין "שגויים"');
$r = call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b5']);
eq([$r[0], code($r), $me('b5')[0]], [429, 'login_locked', 401], '02-B5 הניסיון השישי נדחה גם עם הסיסמה הנכונה');
$GLOBALS['t_ip'] = '198.51.100.55';
eq(code(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b5'])), 'login_locked', '02-B5 הנעילה לפי מייל — גם מכתובת IP אחרת');
$GLOBALS['t_ip'] = '198.51.100.5';
eq(code(call('POST', 'login', ['email' => 'someone.else@example.com', 'password' => 'x'], null, [], ['jar' => 'b5'])), 'login_locked', '02-B5 והנעילה לפי IP — גם למייל אחר');
$GLOBALS['apr_time'] = $t0 + 899;
eq(code(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b5'])), 'login_locked', '02-B5 אחרי 14:59 — עדיין נעול');
$GLOBALS['apr_time'] = $t0 + 901;
eq(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b5'])[0], 200, '02-B5 אחרי 15 דקות — נכנסים');
check('login_attempts: בלי כתובת ובלי IP גלויים', !preg_match('/@|198\.51/', json_encode(apr_rows('SELECT * FROM login_attempts'))));
unset($GLOBALS['apr_time']);
// 4 כשלים ואז הצלחה — המונה מתאפס
$GLOBALS['t_ip'] = '198.51.100.77';
for ($i = 1; $i <= 4; $i++) call('POST', 'login', ['email' => 'dana@example.com', 'password' => "bad $i"], null, [], ['jar' => 'b5b']);
eq(call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 2'], null, [], ['jar' => 'b5b'])[0], 200, '4 כשלים ואז הסיסמה הנכונה — נכנסים');

// ── מקרה 9, §5.4, §5.5 ──
connected();
call('GET', 'me', [], $dana, [], ['jar' => 'tab']);
detached();
eq([$me('tab')[0], $me('tab')[1]['mode'] ?? null], [200, 'detached'], 'מקרה 9: session שנפתח במצב מחוברת ממשיך אחרי הניתוק');
eq([call('POST', 'logout', [], null, [], ['jar' => 'tab'])[0], jar()['tab'], $me('tab')[0]], [200, '', 401], '§5.4 מנותקת: יציאה מוחקת את ה-session ואת העוגייה');
connected();
eq([call('GET', 'me', [], $dana, [], ['jar' => 'b5b'])[1]['user']['pid'], call('GET', 'me', [], $dana, [], ['jar' => 'b5b'])[1]['mode']], [$danaPid, 'connected'], '§5.5 חזרה למחוברת: הזהות שוב מהשער, אותו חשבון');

// ── 02-C1: פעולה רגישה בלי אישור חוזר → 403 confirm_required; עם אישור → מתבצעת, ושתי השורות ביומן ──
$boaz = ident('30', 'בועז', 'boaz@example.com');
$B = office_for($boaz, 'משרד ב');
$boazPid = call('GET', 'me', [], $boaz)[1]['user']['pid'];
$count = fn(string $action) => (int)apr_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
$sensitive = [
    'השעיית משרד' => ['sys-office-status', ['office' => $B, 'status' => 'suspended', 'reason' => 'בדיקה'], 'office.suspend'],
    'הענקת מנהל מערכת' => ['sys-user-flags', ['user' => $boazPid, 'is_sysadmin' => true], 'user.grant_sysadmin'],
    'יצירת אסימון DevLoop' => ['dev-token-create', ['label' => 'Claude'], 'devtoken.create'],
];
foreach ($sensitive as $name => [$action, $in, $logged]) {
    [$c0, $a0] = [$count('confirm.ok'), $count($logged)];
    $r = call('POST', $action, $in, $owner);
    eq([$r[0], $r[1]['error']['code'] ?? $r[1]['code'] ?? null, $count('confirm.ok') - $c0, $count($logged) - $a0], [403, 'confirm_required', 0, 0], "02-C1 $name בלי אישור חוזר → 403 confirm_required, ולא בוצעה");
    eq(call('POST', $action, $in + ['confirm' => 'כן'], $owner)[0], 403, "02-C1 $name: מילה אחרת אינה אישור");
    $r = call('POST', $action, $in + ['confirm' => 'אישור'], $owner);
    eq([$r[0], $count('confirm.ok') - $c0, $count($logged) - $a0], [200, 1, 1], "02-C1 $name עם אישור → מתבצעת, ושתי השורות ביומן הפעולות");
}
$row = apr_row("SELECT * FROM audit_log WHERE action = 'confirm.ok' ORDER BY id DESC LIMIT 1");
eq([$row['entity_pid'], json_decode($row['meta'], true), (int)$row['actor_user_id'] > 0], ['dev-token-create', ['by' => 'word'], true], '02-C1 שורת האישור: איזו פעולה, איך אושרה, ומי');
eq([call('POST', 'sys-office-status', ['office' => $B, 'status' => 'active', 'reason' => 'סוף'], $owner)[0], call('POST', 'sys-user-flags', ['user' => $boazPid, 'is_sysadmin' => false], $owner)[0]], [200, 200], '§6 ביטול השעיה והסרת מנהל מערכת אינם רגישים');
eq(code(call('POST', 'sys-office-status', ['office' => $B, 'status' => 'suspended', 'reason' => 'x', 'confirm' => 'אישור'], $boaz)), 'not_found', 'אישור חוזר אינו הרשאה');
foreach (array_keys(APR_SENSITIVE) as $a) check("הפעולה הרגישה $a קיימת בטבלת ההרשאות", isset(APR_ACCESS[$a]));
// מנותקת: קוד למייל
detached();
$s = sso_start();
sso_callback(['state' => $s, 'token' => sso_token($s, ['sub' => '1', 'name' => 'הבעלים', 'email' => 'owner@example.com'])], 'own');
$od = fn(string $action, array $in = []) => call('POST', $action, $in, null, [], ['jar' => 'own']);
$suspend = ['office' => $B, 'status' => 'suspended', 'reason' => 'בדיקה'];
eq(code($od('sys-office-status', $suspend + ['confirm' => 'אישור'])), 'confirm_required', '02-C1 מנותקת: מילת האישור לא מספיקה — נדרש קוד');
$m0 = count($GLOBALS['t_mail']);
eq($od('confirm-request')[0], 200, '§6 מנותקת: בקשת קוד');
preg_match('/קוד האישור שלך: (\d{6})\b/u', end($GLOBALS['t_mail'])['text'], $cm);
check('§6 קוד בן 6 ספרות נשלח למייל של המשתמש', count($GLOBALS['t_mail']) === $m0 + 1 && isset($cm[1]) && end($GLOBALS['t_mail'])['to'] === 'owner@example.com');
check('הקוד לא נשמר גלוי', !str_contains(json_encode(apr_rows('SELECT * FROM confirm_codes')), $cm[1]));
eq(code($od('confirm-request')), 'too_soon', 'בקשת קוד נוספת מיד — "המתינו דקה"');
$bad = $cm[1] === '000000' ? '111111' : '000000';
eq(code($od('confirm-verify', ['code' => $bad])), 'confirm_code_invalid', '§6 קוד שגוי');
eq(code($od('sys-office-status', $suspend)), 'confirm_required', 'קוד שגוי אינו מאשר');
eq($od('confirm-verify', ['code' => $cm[1]])[0], 200, '§6 הקוד הנכון');
[$c0, $a0] = [$count('confirm.ok'), $count('office.suspend')];
eq([$od('sys-office-status', $suspend)[0], $count('confirm.ok') - $c0, $count('office.suspend') - $a0], [200, 1, 1], '02-C1 מנותקת, אחרי הקוד → מתבצעת, ושתי השורות ביומן');
eq(json_decode(apr_val("SELECT meta FROM audit_log WHERE action = 'confirm.ok' ORDER BY id DESC LIMIT 1"), true), ['by' => 'code'], 'נרשם שאושר בקוד');
eq(code($od('confirm-verify', ['code' => $cm[1]])), 'confirm_code_invalid', 'הקוד חד-פעמי');
$od('sys-office-status', ['office' => $B, 'status' => 'active', 'reason' => 'סוף']);
$GLOBALS['apr_time'] = time() + 601;
eq(code($od('sys-office-status', $suspend)), 'confirm_required', '§6 האישור תקף 10 דקות — אחריהן נדרש שוב');
// 5 ניסיונות, ואז הקוד מת
$od('confirm-request');
preg_match('/קוד האישור שלך: (\d{6})\b/u', end($GLOBALS['t_mail'])['text'], $c2);
$bad = $c2[1] === '000000' ? '111111' : '000000';
for ($i = 1; $i <= 5; $i++) $od('confirm-verify', ['code' => $bad]);
eq(code($od('confirm-verify', ['code' => $c2[1]])), 'confirm_code_invalid', '§6 אחרי 5 ניסיונות שגויים — גם הקוד הנכון נדחה');
$GLOBALS['apr_time'] = time() + 601 + 61;
$od('confirm-request');
preg_match('/קוד האישור שלך: (\d{6})\b/u', end($GLOBALS['t_mail'])['text'], $c3);
$GLOBALS['apr_time'] = time() + 601 + 61 + 601;
eq(code($od('confirm-verify', ['code' => $c3[1]])), 'confirm_code_invalid', '§6 קוד שעברו עליו 10 דקות — פג');
unset($GLOBALS['apr_time']);
connected();
eq(code(call('POST', 'confirm-request', [], $owner)), 'forbidden', 'מחוברת: אין קוד במייל — מילת אישור');

// ══ ביקורת סבב 1 (team/reviews/02-invites-detached.md) ══
unset($GLOBALS['apr_time']);
detached();

// 🟡2 Login CSRF: POST ציבורי — רק JSON, ורק מהאפליקציה עצמה
$GLOBALS['t_ip'] = '198.51.100.20';
$good = ['email' => 'dana@example.com', 'password' => 'brand new pass 2'];
$n0 = (int)apr_val('SELECT COUNT(*) FROM sessions');
foreach (['טופס text/plain' => ['t_content_type' => 'text/plain'], 'טופס רגיל' => ['t_content_type' => 'application/x-www-form-urlencoded'], 'בלי Content-Type' => ['t_content_type' => ''],
    'Origin זר' => ['t_origin' => 'https://evil.example'], 'Origin: null' => ['t_origin' => 'null'], 'Sec-Fetch-Site: cross-site' => ['t_fetch_site' => 'cross-site'], 'Sec-Fetch-Site: same-site' => ['t_fetch_site' => 'same-site']] as $name => $set) {
    foreach ($set as $k => $v) $GLOBALS[$k] = $v;
    foreach (['login' => $good, 'forgot' => ['email' => 'dana@example.com'], 'reset' => ['token' => str_repeat('a', 48), 'password' => 'whatever pass 9']] as $action => $in) {
        $r = call('POST', $action, $in, null, [], ['jar' => 'csrf']);
        eq([$r[0], code($r)], [403, 'csrf'], "🟡2 $action — $name → נדחה");
    }
    foreach (array_keys($set) as $k) unset($GLOBALS[$k]);
}
eq([(int)apr_val('SELECT COUNT(*) FROM sessions') - $n0, $me('csrf')[0]], [0, 401], '🟡2 ולא נפתח session');
$GLOBALS['t_origin'] = 'https://example.test'; $GLOBALS['t_fetch_site'] = 'same-origin';
eq(call('POST', 'login', $good, null, [], ['jar' => 'csrf'])[0], 200, '🟡2 מהאפליקציה עצמה, ב-JSON — עובר');
unset($GLOBALS['t_origin'], $GLOBALS['t_fetch_site']);

// 🟡7 מנותקת: ה-session נבדק מול המעטפת (grant_status) פעם ב-15 דקות
$t0 = time();
$GLOBALS['apr_time'] = $t0;
call('POST', 'login', $good, null, [], ['jar' => 'grant']);
$c0 = $GLOBALS['t_grant_calls'];
$GLOBALS['apr_time'] = $t0 + 899;
eq([$me('grant')[0], $GLOBALS['t_grant_calls'] - $c0], [200, 0], '🟡7 בתוך 15 דקות — בלי שאלה למעטפת');
$GLOBALS['apr_time'] = $t0 + 901;
eq([$me('grant')[0], $GLOBALS['t_grant_calls'] - $c0, $me('grant')[0], $GLOBALS['t_grant_calls'] - $c0], [200, 1, 200, 1], '🟡7 אחרי 15 דקות — שאלה אחת, והתשובה "כן" מחזיקה עוד 15 דקות');
// המעטפת לא עונה: ממשיכים עד 24 שעות מהתשובה החיובית האחרונה, ואז נכשל סגור
$GLOBALS['t_grant']['17'] = null;
$GLOBALS['apr_time'] = $t0 + 901 + 901;
eq($me('grant')[0], 200, '🟡7 המעטפת לא עונה — ממשיכים');
$c1 = $GLOBALS['t_grant_calls'];
eq([$me('grant')[0], $GLOBALS['t_grant_calls'] - $c1], [200, 0], 'ולא שואלים שוב בכל בקשה');
$GLOBALS['apr_time'] = $t0 + 901 + 901 + 61;
eq([$me('grant')[0], $GLOBALS['t_grant_calls'] - $c1], [200, 1], 'אלא אחרי דקה');
$GLOBALS['apr_time'] = $t0 + 901 + 86399;
eq($me('grant')[0], 200, '🟡7 23:59 מהתשובה האחרונה — עדיין בפנים');
$GLOBALS['apr_time'] = $t0 + 901 + 86401;
eq([$me('grant')[0], code($me('grant')), (int)apr_val('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', [hash('sha256', jar()['grant'])])], [503, 'login_unavailable', 1], '🟡7 אחרי 24 שעות בלי תשובה — נכשל סגור (ה-session לא נמחק)');
unset($GLOBALS['t_grant']['17']);
eq($me('grant')[0], 200, 'המעטפת חזרה — ממשיכים');
// ההרשאה בוטלה במעטפת
$GLOBALS['t_grant']['17'] = false;
$GLOBALS['apr_time'] = $t0 + 901 + 86401 + 901;
$tok = jar()['grant'];
eq([$me('grant')[0], code(call('GET', 'me', [], null, [], ['jar' => 'grant', 'cookie' => $tok])), (int)apr_val('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', [hash('sha256', $tok)])], [401, 'login_required', 0], '🟡7 allowed: false → ה-session נמחק');
$GLOBALS['t_ip'] = '198.51.100.21';
$r = call('POST', 'login', $good, null, [], ['jar' => 'grant2']);
eq([$r[0], code($r), $me('grant2')[0]], [401, 'login_failed', 401], '🟡7 כניסה בסיסמה של מי שההרשאה שלו בוטלה — כמו סיסמה שגויה');
$GLOBALS['t_grant']['17'] = null;
eq(code(call('POST', 'login', $good, null, [], ['jar' => 'grant2'])), 'login_unavailable', '🟡7 כניסה בסיסמה כשהמעטפת לא עונה — נכשל סגור');
unset($GLOBALS['t_grant']['17'], $GLOBALS['apr_time']);
connected();
$c2 = $GLOBALS['t_grant_calls'];
call('GET', 'me', [], $dana);
eq($GLOBALS['t_grant_calls'] - $c2, 0, '🟡7 במחוברת השער בודק — לא שואלים');
detached();

// 🟡8 בדיקות שחסרו
// (א) sso-callback במצב מחוברת: גם זהות תקינה לא פותחת session
$s = sso_start();
$tok = sso_token($s);
connected();
$n0 = (int)apr_val('SELECT COUNT(*) FROM sessions');
eq([...sso_callback(['state' => $s, 'token' => $tok], 'conn'), (int)apr_val('SELECT COUNT(*) FROM sessions') - $n0], [$HOME, null, false, 0], '🟡8 מחוברת: sso-callback רק מחזיר לאפליקציה, בלי session');
detached();
// (ב) משתמש מושעה בכניסה בסיסמה
$GLOBALS['t_ip'] = '198.51.100.22';
apr_exec("UPDATE users SET status = 'suspended' WHERE pid = ?", [$danaPid]);
$r = call('POST', 'login', $good, null, [], ['jar' => 'susp']);
eq([$r[0], code($r), $me('susp')[0]], [401, 'login_failed', 401], '🟡8 חשבון מושעה עם הסיסמה הנכונה — אותה תשובה כמו סיסמה שגויה, ובלי session');
$m0 = count($GLOBALS['t_mail']);
call('POST', 'forgot', ['email' => 'dana@example.com']);
eq(count($GLOBALS['t_mail']) - $m0, 0, 'ואין לו קישור איפוס');
apr_exec("UPDATE users SET status = 'active' WHERE pid = ?", [$danaPid]);
// 🟡4 forgot: רק למי שקבע סיסמה, ורק למייל מאומת
$GLOBALS['t_ip'] = '198.51.100.23';
$m0 = count($GLOBALS['t_mail']);
call('POST', 'forgot', ['email' => 'owner@example.com']);                       // לא קבע סיסמה
apr_exec("UPDATE users SET email_verified = 0 WHERE pid = ?", [$danaPid]);
call('POST', 'forgot', ['email' => 'dana@example.com']);                        // מייל לא מאומת
eq([count($GLOBALS['t_mail']) - $m0, (int)apr_val("SELECT COUNT(*) FROM password_resets WHERE created_at >= ?", [apr_now()])], [0, 0], '🟡4 forgot: בלי סיסמה או עם מייל לא מאומת — אין קישור');
apr_exec("UPDATE users SET email_verified = 1 WHERE pid = ?", [$danaPid]);
// (ד) ביטול שאר קישורי האיפוס
$GLOBALS['t_ip'] = '198.51.100.24';
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'f%'");
$links = [];
foreach ([1, 2] as $i) { call('POST', 'forgot', ['email' => 'dana@example.com']); preg_match('~#/reset/([a-f0-9]{48})~', end($GLOBALS['t_mail'])['text'], $mm); $links[] = $mm[1]; }
eq([$links[0] !== $links[1], call('POST', 'reset', ['token' => $links[1], 'password' => 'brand new pass 5'])[0], code(call('POST', 'reset', ['token' => $links[0], 'password' => 'brand new pass 6']))], [true, 200, 'reset_invalid'], '🟡8 שימוש בקישור איפוס אחד מבטל את האחרים');
// (ג) הגבלת הקצב של forgot: 5 ב-15 דקות לכתובת ול-IP
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'f%'");
$m0 = count($GLOBALS['t_mail']);
for ($i = 1; $i <= 7; $i++) $last = call('POST', 'forgot', ['email' => 'dana@example.com']);
eq([count($GLOBALS['t_mail']) - $m0, $last[1]], [5, ['ok' => true]], '🟡8 forgot: 5 מיילים ב-15 דקות לכתובת — והתשובה לא משתנה');
$GLOBALS['t_ip'] = '198.51.100.25';
$m0 = count($GLOBALS['t_mail']);
call('POST', 'forgot', ['email' => 'dana@example.com']);
eq(count($GLOBALS['t_mail']) - $m0, 0, 'גם מכתובת IP אחרת');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'f%'");
apr_exec("UPDATE users SET password_hash = ?, email = 'boaz@example.com', email_verified = 1 WHERE pid = ?", [password_hash('boaz password 1', PASSWORD_DEFAULT), $boazPid]);
$m0 = count($GLOBALS['t_mail']);
foreach (['dana@example.com', 'boaz@example.com', 'dana@example.com', 'boaz@example.com', 'dana@example.com', 'boaz@example.com', 'dana@example.com'] as $e) call('POST', 'forgot', ['email' => $e]);
eq(count($GLOBALS['t_mail']) - $m0, 5, '🟡8 והתקרה לפי IP — גם כשמחליפים כתובות');
// קביעת סיסמה מבטלת קישורי איפוס פתוחים
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'f%'");
call('POST', 'forgot', ['email' => 'dana@example.com']);
preg_match('~#/reset/([a-f0-9]{48})~', end($GLOBALS['t_mail'])['text'], $open);
$GLOBALS['t_ip'] = '198.51.100.26';
call('POST', 'login', ['email' => 'dana@example.com', 'password' => 'brand new pass 5'], null, [], ['jar' => 'pw']);
eq([call('POST', 'password-set', ['password' => 'brand new pass 7'], null, [], ['jar' => 'pw'])[0], code(call('POST', 'reset', ['token' => $open[1], 'password' => 'brand new pass 8']))], [200, 'reset_invalid'], 'קביעת סיסמה מבטלת קישורי איפוס פתוחים');
eq(code(call('POST', 'password-set', ['password' => str_repeat('a', 73)], null, [], ['jar' => 'pw'])), 'password_long', 'סיסמה מעל 72 תווים — נדחית (bcrypt חותך)');

// 🟡5 הניסיון נספר לפני בדיקת הסיסמה, באותה טרנזקציה שבודקת את הנעילה
$keys = ['e:test-atomic', 'i:test-atomic'];
eq(array_map(fn() => apr_login_attempt($keys), range(1, 6)), [true, true, true, true, true, false], '🟡5 חמישה ניסיונות נספרים — השישי נעול, בלי תלות בבדיקת הסיסמה');
$src = file_get_contents(dirname(__DIR__) . '/lib/login.php');
check('🟡5 ב-apr_login הספירה קודמת ל-password_verify', strpos($src, 'if (!apr_login_attempt($keys))') < strpos($src, '$ok = password_verify('));
eq([apr_limit('t:x', 2, 60), apr_limit('t:x', 2, 60), apr_limit('t:x', 2, 60)], [true, true, false], 'apr_limit: עד התקרה, ואז לא');

// 🟡3 קוד האישור: אחרי 5 ניחושים הקוד מת אבל השורה נשארת; ותקרה של 3 קודים ב-15 דקות
$s = sso_start();
sso_callback(['state' => $s, 'token' => sso_token($s, ['sub' => '1', 'name' => 'הבעלים', 'email' => 'owner@example.com'])], 'own2');
$o2 = fn(string $action, array $in = []) => call('POST', $action, $in, null, [], ['jar' => 'own2']);
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'otp:%'");
$t0 = time();
$GLOBALS['apr_time'] = $t0;
$o2('confirm-request');
preg_match('/קוד האישור שלך: (\d{6})\b/u', end($GLOBALS['t_mail'])['text'], $k1);
$bad = $k1[1] === '000000' ? '111111' : '000000';
for ($i = 1; $i <= 5; $i++) $o2('confirm-verify', ['code' => $bad]);
eq([code($o2('confirm-verify', ['code' => $k1[1]])), (int)apr_val('SELECT MAX(attempts) FROM confirm_codes'), code($o2('confirm-request'))], ['confirm_code_invalid', 5, 'too_soon'], '🟡3 אחרי 5 ניחושים הקוד מת, השורה נשארת — ואי אפשר לבקש קוד חדש מיד');
$GLOBALS['apr_time'] = $t0 + 61;
eq($o2('confirm-request')[0], 200, 'קוד שני, אחרי דקה');
$GLOBALS['apr_time'] = $t0 + 122;
eq($o2('confirm-request')[0], 200, 'קוד שלישי');
$GLOBALS['apr_time'] = $t0 + 183;
$m0 = count($GLOBALS['t_mail']);
eq([code($o2('confirm-request')), count($GLOBALS['t_mail']) - $m0], ['too_many_codes', 0], '🟡3 קוד רביעי ב-15 דקות — נדחה, בלי מייל');
$s = sso_start();
sso_callback(['state' => $s, 'token' => sso_token($s, ['sub' => '1', 'name' => 'הבעלים', 'email' => 'owner@example.com'])], 'own3');
eq(code(call('POST', 'confirm-request', [], null, [], ['jar' => 'own3'])), 'too_many_codes', '🟡3 התקרה למשתמש — גם מ-session אחר');
$GLOBALS['apr_time'] = $t0 + 901;
eq($o2('confirm-request')[0], 200, 'אחרי 15 דקות — שוב אפשר');
unset($GLOBALS['apr_time']);

// ── ניקוי ──
$GLOBALS['apr_time'] = time() + 4 * 86400;
apr_jobs_run(5.0);
eq([(int)apr_val('SELECT COUNT(*) FROM sso_states'), (int)apr_val('SELECT COUNT(*) FROM sso_jti'), (int)apr_val('SELECT COUNT(*) FROM login_attempts'), (int)apr_val('SELECT COUNT(*) FROM confirm_codes')], [0, 0, 0, 0], 'שירות הרקע auth.cleanup מנקה את מה שפג');
unset($GLOBALS['apr_time']);

done();
