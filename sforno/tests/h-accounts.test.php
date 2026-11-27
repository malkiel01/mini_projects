<?php
/**
 * פרוסה 05 — חשבונות הקוראים (docs/spec/05-readers-sync.md §7): קריטריונים 1, 2, 3, 9 (מכשירים), 10, 11, 12.
 * הסנכרון עצמו (4–8, 9 בפריטים) — i-sync.test.php. בלי רשת: המיילים נאספים ב-$GLOBALS['sf_mail_out'], והמפתחות של
 * Google — מפתח RSA שנוצר כאן.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$GLOBALS['sf_mail_out'] = [];
$link = function (string $kind): string {
    $m = end($GLOBALS['sf_mail_out']);
    preg_match('~#/account/' . $kind . '/([a-f0-9]{64})~', (string)($m['body'] ?? ''), $x);
    return $x[1] ?? '';
};

// ── K1: הרשמה → אימות → יציאה → כניסה → איפוס ──────────────
$r = rcall('POST', 'account-register', ['email' => ' Dana@Example.com ', 'password' => 'סיסמה-ארוכה-1', 'name' => 'דנה'], 'A');
eq($r[0], 200, 'K1 הרשמה');
eq($r[1]['reader']['email'] ?? null, 'dana@example.com', 'K1 המייל מנורמל');
eq($r[1]['reader']['verified'] ?? null, false, 'K1 אחרי הרשמה — עוד לא מאומת');
check('K1 מחובר מיד (עוגיית sf_reader)', strlen(rcookie('A')) === 64);
check('K1 נשלח מייל אימות עם קישור', $link('verify') !== '' && $GLOBALS['sf_mail_out'][0]['to'] === 'dana@example.com');
$vt = $link('verify');
check('K1 האסימון לא נשמר כמו שהוא במסד', !sf_val('SELECT 1 FROM reader_tokens WHERE token_hash = ?', [$vt]));
eq(code(rcall('POST', 'account-register', ['email' => 'dana@example.com', 'password' => 'aaaaaaaa1'], 'Z')), 'email_taken', 'K1 אותו מייל פעמיים — נדחה');
eq(code(rcall('POST', 'account-register', ['email' => 'x@example.com', 'password' => 'short'], 'Z')), 'bad_input', 'K1 סיסמה קצרה — נדחית');
eq(code(rcall('POST', 'account-register', ['email' => 'לא מייל', 'password' => 'aaaaaaaa1'], 'Z')), 'bad_input', 'K1 מייל לא תקין — נדחה');
$h = (string)sf_val("SELECT password_hash FROM readers WHERE email = 'dana@example.com'");
check('K1 הסיסמה — password_hash (Argon2id / bcrypt), לא הסיסמה עצמה', str_starts_with($h, '$argon2id$') || str_starts_with($h, '$2y$'));

eq(rcall('POST', 'account-verify', ['token' => $vt], 'other')[0], 200, 'K1 אימות — גם מדפדפן אחר, בלי כניסה');
eq(rcall('GET', 'account-me', [], 'A')[1]['reader']['verified'] ?? null, true, 'K1 אחרי האימות — מאומת');
eq(code(rcall('POST', 'account-verify', ['token' => $vt], 'other')), 'token', 'K1 קישור אימות — חד-פעמי');
eq(code(rcall('POST', 'account-verify', ['token' => str_repeat('a', 64)], 'other')), 'token', 'K1 אסימון שלא קיים — נדחה');

eq(rcall('POST', 'account-logout', [], 'A')[0], 200, 'K1 יציאה');
eq(rcookie('A'), '', 'K1 יציאה מוחקת את העוגייה');
eq(code(rcall('GET', 'account-devices', [], 'A')), 'reader_login', 'K1 אחרי יציאה — אין גישה');
eq(array_key_exists('reader', $x = rcall('GET', 'account-me', [], 'A')[1]) ? $x['reader'] : 'x', null, 'K1 account-me בלי חשבון — reader: null, בלי שגיאה');
$r = rcall('POST', 'account-login', ['email' => 'DANA@example.com', 'password' => 'סיסמה-ארוכה-1'], 'A');
eq($r[0], 200, 'K1 כניסה (המייל לא תלוי באותיות גדולות)');

// איפוס: כל ה-sessions מתבטלים
rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'סיסמה-ארוכה-1'], 'B');
eq(rcall('GET', 'account-devices', [], 'B')[0], 200, 'K1 מכשיר ב׳ מחובר');
eq(rcall('POST', 'account-reset-request', ['email' => 'dana@example.com'], 'C')[1]['sent'] ?? null, true, 'K1 בקשת איפוס');
$rt = $link('reset');
check('K1 קישור האיפוס במייל', $rt !== '');
eq(rcall('POST', 'account-reset-request', ['email' => 'nobody@example.com'], 'C')[1]['sent'] ?? null, true, 'K1 בקשת איפוס למייל שלא קיים — אותה תשובה');
eq(end($GLOBALS['sf_mail_out'])['to'], 'dana@example.com', 'K1 …ולא נשלח מייל');
$GLOBALS['sf_time'] = time() + 3601;
eq(code(rcall('POST', 'account-reset', ['token' => $rt, 'password' => 'חדשה-חדשה-2'], 'C')), 'token', 'K1 קישור איפוס — שעה בלבד');
unset($GLOBALS['sf_time']);
$GLOBALS['sf_time'] = time() + 3610 + SF_LOGIN_WINDOW;   // אחרי חלון ההגבלה של בקשות המייל
rcall('POST', 'account-reset-request', ['email' => 'dana@example.com'], 'C');
$rt = $link('reset');
eq(rcall('POST', 'account-reset', ['token' => $rt, 'password' => 'חדשה-חדשה-2'], 'C')[0], 200, 'K1 איפוס');
eq(code(rcall('GET', 'account-devices', [], 'A')), 'reader_login', 'K1 האיפוס ביטל את ה-session של מכשיר א׳');
eq(code(rcall('GET', 'account-devices', [], 'B')), 'reader_login', 'K1 …ושל מכשיר ב׳');
eq(rcall('GET', 'account-devices', [], 'C')[0], 200, 'K1 המכשיר שאיפס — מחובר מחדש');
eq(code(rcall('POST', 'account-reset', ['token' => $rt, 'password' => 'שלישית-333'], 'C')), 'token', 'K1 קישור איפוס — חד-פעמי');
eq(code(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'סיסמה-ארוכה-1'], 'A')), 'bad_login', 'K1 הסיסמה הישנה כבר לא עובדת');
eq(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'A')[0], 200, 'K1 החדשה — כן');
unset($GLOBALS['sf_time']);

// ── ביקורת 05 🟡1: CSRF בכניסה ובהרשמה (בלי אסימון) ───────────
foreach (['text/plain; x=application/json', 'text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=application/json'] as $ct) {
    eq(code(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'CS', [], ['content_type' => $ct])), 'content_type', "CSRF סוג מדיה \"$ct\" — נדחה");
}
eq(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'CS', [], ['content_type' => 'Application/JSON; charset=utf-8'])[0], 200, 'CSRF application/json עם charset — עובר');
foreach (['cross-site', 'same-site'] as $site) {
    eq(code(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'CS2', [], ['sec_fetch_site' => $site])), 'csrf', "CSRF Sec-Fetch-Site: $site — נדחה");
    eq(code(rcall('POST', 'account-register', ['email' => 'csrf@example.com', 'password' => 'aaaaaaaa1'], 'CS2', [], ['sec_fetch_site' => $site])), 'csrf', "CSRF הרשמה, Sec-Fetch-Site: $site — נדחה");
}
eq(code(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'CS2', [], ['sec_fetch_site' => '', 'origin' => 'https://evil.example'])), 'csrf', 'CSRF בלי Sec-Fetch-Site, Origin של אתר אחר — נדחה');
eq(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'CS2', [], ['sec_fetch_site' => '', 'origin' => 'https://example.test'])[0], 200, 'CSRF Origin שלנו — עובר');
eq(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'CS3', [], ['sec_fetch_site' => 'none'])[0], 200, 'CSRF Sec-Fetch-Site: none (הקלדה / סימניה) — עובר');
eq(code(rcall('POST', 'account-logout', [], 'CS3', [], ['sec_fetch_site' => 'cross-site'])), 'csrf', 'CSRF גם פעולת account מאתר אחר — נדחית');
check('CSRF לא נוצר חשבון מהבקשות שנדחו', !sf_val("SELECT 1 FROM readers WHERE email = 'csrf@example.com'"));

// ── ביקורת 05 🟡3: בלי השם שהמשתמש בחר במיילים ─────────────────
$GLOBALS['sf_mail_out'] = [];
rcall('POST', 'account-register', ['email' => 'phish@example.com', 'password' => 'phish-phish-1', 'name' => 'זכיתם! היכנסו ל-evil.example'], 'PH');
rcall('POST', 'account-reset-request', ['email' => 'phish@example.com'], 'PH');
eq(count($GLOBALS['sf_mail_out']), 2, 'מיילים: אימות ואיפוס נשלחו');
check('מיילים: השם שהמשתמש בחר לא מופיע (לא בגוף ולא בנושא)', !array_filter($GLOBALS['sf_mail_out'], fn($m) => str_contains($m['body'] . $m['subject'], 'evil.example') || str_contains($m['body'], 'זכיתם')));

// ── K2: 5 שגויות → נעילה 15 דקות; ההודעה זהה למייל קיים ולא קיים ──
rcall('POST', 'account-register', ['email' => 'lock@example.com', 'password' => 'נכונה-נכונה-1'], 'L');
$msgs = [];
foreach (['lock@example.com', 'ghost@example.com'] as $em) {
    $seq = [];
    for ($i = 1; $i <= 6; $i++) $seq[] = rcall('POST', 'account-login', ['email' => $em, 'password' => 'שגויה' . $i], 'L', [], ['ip' => '198.51.100.' . $i])[1]['error'] ?? null;
    $msgs[$em] = $seq;
}
eq($msgs['lock@example.com'], $msgs['ghost@example.com'], 'K2 אותן תשובות בדיוק — למייל קיים ולמייל שלא קיים');
eq($msgs['lock@example.com'][0]['message'] ?? null, 'פרטי הכניסה שגויים', 'K2 ההודעה: "פרטי הכניסה שגויים"');
eq(array_column(array_slice($msgs['lock@example.com'], 0, 5), 'code'), array_fill(0, 5, 'bad_login'), 'K2 חמש ראשונות — "שגויים"');
eq($msgs['lock@example.com'][5]['code'] ?? null, 'locked', 'K2 השישית — נעול');
eq(code(rcall('POST', 'account-login', ['email' => 'lock@example.com', 'password' => 'נכונה-נכונה-1'], 'L', [], ['ip' => '198.51.100.99'])), 'locked', 'K2 בזמן הנעילה גם סיסמה נכונה נדחית (וגם מ-IP אחר)');
$GLOBALS['sf_time'] = time() + 14 * 60;
eq(code(rcall('POST', 'account-login', ['email' => 'lock@example.com', 'password' => 'נכונה-נכונה-1'], 'L', [], ['ip' => '198.51.100.99'])), 'locked', 'K2 אחרי 14 דקות — עדיין נעול');
$GLOBALS['sf_time'] = time() + 15 * 60 + 5;
eq(rcall('POST', 'account-login', ['email' => 'lock@example.com', 'password' => 'נכונה-נכונה-1'], 'L', [], ['ip' => '198.51.100.99'])[0], 200, 'K2 אחרי 15 דקות — נפתח');
unset($GLOBALS['sf_time']);
check('K2 המייל לא נשמר בטבלת הניסיונות (רק HMAC)', !sf_val("SELECT 1 FROM login_attempts WHERE key_hash LIKE '%@%' OR key_hash LIKE '%ghost%'"));
$GLOBALS['sf_time'] = time() + 3 * 3600;
for ($i = 0; $i < SF_LOGIN_IP_MAX; $i++) rcall('POST', 'account-login', ['email' => "spray$i@example.com", 'password' => 'x'], 'S', [], ['ip' => '192.0.2.50']);
eq(code(rcall('POST', 'account-login', ['email' => 'lock@example.com', 'password' => 'נכונה-נכונה-1'], 'S', [], ['ip' => '192.0.2.50'])), 'locked', 'K2 הגבלה לפי IP: 30 כישלונות על מיילים שונים ← נחסם');
eq(rcall('POST', 'account-login', ['email' => 'lock@example.com', 'password' => 'נכונה-נכונה-1'], 'S', [], ['ip' => '192.0.2.51'])[0], 200, 'K2 …רק ה-IP הזה');
unset($GLOBALS['sf_time']);

// ── K3: Google — aud אחר, פג תוקף, חתימה שגויה ← נדחה ─────────
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$d = openssl_pkey_get_details($key)['rsa'];
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$jwks = json_encode(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1', 'n' => $b64($d['n']), 'e' => $b64($d['e'])]]]);
$prevHttp = $GLOBALS['sf_http'];
$GLOBALS['sf_http'] = function (string $url) use ($jwks, $prevHttp) {
    if ($url === SF_GOOGLE_CERTS) { $GLOBALS['t_http_log'][] = $url; return [200, $jwks, 10]; }
    return $prevHttp($url);
};
$CID = '123456789012-abcdefghijklmnop0123456789abcdef.apps.googleusercontent.com';
$jwt = function (array $over = [], $signWith = null, string $kid = 'k1') use ($key, $b64, $CID) {
    $c = $over + ['iss' => 'https://accounts.google.com', 'aud' => $CID, 'sub' => '1100220033', 'email' => 'gila@example.com',
        'email_verified' => true, 'name' => 'גילה', 'iat' => sf_time() - 10, 'exp' => sf_time() + 3600];
    $in = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'])) . '.' . $b64(json_encode($c));
    openssl_sign($in, $sig, $signWith ?? $key, OPENSSL_ALGO_SHA256);
    return $in . '.' . $b64($sig);
};
eq(code(rcall('POST', 'account-google', ['credential' => $jwt()], 'G')), 'google_off', 'K3 בלי מזהה לקוח — הכניסה עם Google כבויה');
$DEV = ident('501', 'מפתחת', 'dev@example.com', ['platform_admin' => true]);
eq(code(call('POST', 'dev-google-set', ['client_id' => 'not-a-client'], $DEV)), 'bad_input', 'K3 מזהה לקוח בצורה לא נכונה — נדחה');
eq(call('POST', 'dev-google-set', ['client_id' => $CID], $DEV)[0], 200, 'K3 המפתח מגדיר את מזהה הלקוח');
eq(code(call('POST', 'dev-google-set', ['client_id' => $CID], ident('509', 'מנהל'))), 'forbidden', 'K3 …רק המפתח');
eq(rcall('GET', 'account-me', [], 'G')[1]['google_client_id'] ?? null, $CID, 'K3 account-me מחזיר את מזהה הלקוח לכפתור');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt(['aud' => '999-other.apps.googleusercontent.com'])], 'G')), 'google', 'K3 aud אחר — נדחה');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt(['exp' => sf_time() - 120])], 'G')), 'google', 'K3 פג תוקף — נדחה');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt([], $other)], 'G')), 'google', 'K3 חתימה במפתח אחר — נדחה');
$t = $jwt();
eq(code(rcall('POST', 'account-google', ['credential' => substr($t, 0, strrpos($t, '.') + 1) . $b64(str_repeat('x', 256))], 'G')), 'google', 'K3 חתימה משובשת — נדחה');
$p = explode('.', $t);
$forged = $p[0] . '.' . $b64(json_encode(json_decode((string)base64_decode(strtr($p[1], '-_', '+/')), true) + ['x' => 1] ?: [])) . '.' . $p[2];
eq(code(rcall('POST', 'account-google', ['credential' => $forged], 'G')), 'google', 'K3 תוכן ששונה אחרי החתימה — נדחה');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt(['iss' => 'https://evil.example'])], 'G')), 'google', 'K3 iss אחר — נדחה');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt([], null, 'unknown-kid')], 'G')), 'google', 'K3 kid שלא קיים — נדחה');
$alg = $b64(json_encode(['alg' => 'none', 'kid' => 'k1'])) . '.' . $p[1] . '.';
eq(code(rcall('POST', 'account-google', ['credential' => $alg], 'G')), 'google', 'K3 alg=none — נדחה');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt(['email_verified' => false])], 'G')), 'google', 'K3 מייל לא מאומת ב-Google — לא נפתח חשבון');
check('K3 שום חשבון לא נוצר מהניסיונות שנדחו', !sf_val("SELECT 1 FROM readers WHERE email = 'gila@example.com'"));
$r = rcall('POST', 'account-google', ['credential' => $jwt()], 'G');
eq($r[0], 200, 'K3 טוקן תקין — נכנס');
eq([$r[1]['reader']['google'] ?? null, $r[1]['reader']['verified'] ?? null, $r[1]['reader']['password'] ?? null], [true, true, false], 'K3 חשבון חדש: Google, מאומת, בלי סיסמה');
eq(rcall('POST', 'account-google', ['credential' => $jwt(['email' => 'changed@example.com'])], 'G2')[1]['reader']['email'] ?? null, 'gila@example.com', 'K3 כניסה חוזרת — לפי sub, לא לפי המייל');
// קישור לחשבון קיים: מאומת → מקושר, הסיסמה נשארת
eq(rcall('POST', 'account-google', ['credential' => $jwt(['sub' => '77', 'email' => 'dana@example.com'])], 'G3')[1]['reader']['password'] ?? null, true, 'K3 חשבון מאומת באותו מייל ← מקושר, הסיסמה נשארת');
// חשבון לא מאומת באותו מייל → קישור, הסיסמה וה-sessions בוטלו (מי שנרשם בלי להוכיח בעלות)
rcall('POST', 'account-register', ['email' => 'squat@example.com', 'password' => 'squatter-1'], 'SQ');
$r = rcall('POST', 'account-google', ['credential' => $jwt(['sub' => '88', 'email' => 'squat@example.com'])], 'G4');
eq([$r[1]['reader']['google'] ?? null, $r[1]['reader']['password'] ?? null], [true, false], 'K3 חשבון לא מאומת באותו מייל ← Google, בלי הסיסמה הקודמת');
eq(code(rcall('GET', 'account-devices', [], 'SQ')), 'reader_login', 'K3 …וה-session הקודם בוטל');
check('K3 המפתחות של Google נטענו פעם אחת (מטמון)', count(array_filter($GLOBALS['t_http_log'], fn($u) => $u === SF_GOOGLE_CERTS)) <= 3);
$GLOBALS['t_http_log'] = [];
$GLOBALS['sf_time'] = time() + 120;
for ($i = 0; $i < 5; $i++) rcall('POST', 'account-google', ['credential' => $jwt([], null, 'made-up-' . $i)], 'G5');
eq(count(array_filter($GLOBALS['t_http_log'], fn($u) => $u === SF_GOOGLE_CERTS)), 1, 'K3 kid מומצא — רענון כפוי של המפתחות לכל היותר פעם בדקה');
$GLOBALS['sf_time'] = time() + 200;
rcall('POST', 'account-google', ['credential' => $jwt([], null, 'made-up-x')], 'G5');
eq(count(array_filter($GLOBALS['t_http_log'], fn($u) => $u === SF_GOOGLE_CERTS)), 2, 'K3 …ואחרי דקה — שוב מותר');
unset($GLOBALS['sf_time']);

// ── K9 (מכשירים) + K11: הפרדה בין עולמות ───────────────────
rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'D1', [], ['ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Version/17.0 Mobile Safari/604.1']);
rcall('POST', 'account-register', ['email' => 'eli@example.com', 'password' => 'eli-eli-eli-1'], 'E1');
$devs = rcall('GET', 'account-devices', [], 'D1')[1]['devices'] ?? [];
check('K1 שם המכשיר מה-User-Agent ("Safari · iPhone")', in_array('Safari · iPhone', array_column($devs, 'label'), true), array_column($devs, 'label'));
$mine = array_values(array_filter($devs, fn($x) => $x['current']))[0]['pid'] ?? '';
eq(code(rcall('POST', 'account-device-revoke', ['device' => $mine], 'E1')), 'not_found', 'K9 קורא א׳ לא מבטל מכשיר של קורא ב׳ — 404 (לא 403)');
eq(rcall('GET', 'account-devices', [], 'D1')[0], 200, 'K9 …והמכשיר עדיין מחובר');
check('K9 רשימת המכשירים — רק של הקורא עצמו', !array_intersect(array_column(rcall('GET', 'account-devices', [], 'E1')[1]['devices'] ?? [], 'pid'), array_column($devs, 'pid')));
eq(code(rcall('POST', 'account-device-revoke', ['device' => $mine], 'D1', [], ['csrf' => 'wrong'])), 'csrf', 'K1 POST בלי X-Reader-CSRF נכון — 403');
eq(code(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'x'], 'D1', [], ['content_type' => 'text/plain'])), 'content_type', 'K1 כניסה רק ב-JSON (חוסם טופס מאתר אחר)');
$other = array_values(array_filter(rcall('GET', 'account-devices', [], 'D1')[1]['devices'], fn($x) => !$x['current']))[0]['pid'] ?? '';
eq(rcall('POST', 'account-device-revoke', ['device' => $other], 'D1')[0], 200, 'K1 ביטול מכשיר אחר');
check('K1 המכשיר שבוטל לא ברשימה', !in_array($other, array_column(rcall('GET', 'account-devices', [], 'D1')[1]['devices'], 'pid'), true));

$STAFF = ident('510', 'מנהלת', 'boss@example.com');
call('GET', 'me', [], $STAFF);
$staffCookie = jar()[$STAFF] ?? '';
$rc = rcookie('D1');
// עוגיית sf_reader (גם אם נשלחת בשם sf_staff) לא פותחת שום פעולת ניהול
$bad = [];
foreach (SF_ACTIONS as $a => $s) {
    if (!in_array($s['who'], ['staff', 'dev'], true)) continue;
    foreach ([$rc, ''] as $c) {
        $res = rcall($s['m'], $a, [], 'D1', [], ['cookie' => $c]);
        if ($res[0] !== 401 && $res[0] !== 503) $bad[] = "$a:{$res[0]}";
    }
}
eq($bad, [], 'K11 עוגיית sf_reader לא פותחת אף פעולת ניהול (401 בכולן)');
$bad = [];
foreach (SF_ACTIONS as $a => $s) {
    if ($s['who'] !== 'account') continue;
    $res = rcall($s['m'], $a, [], 'nobody', [], ['platform_user' => $STAFF, 'cookie' => $staffCookie, 'reader_cookie' => $staffCookie]);
    if ($res[0] !== 401) $bad[] = "$a:{$res[0]}";
}
eq($bad, [], 'K11 זהות המנהל ועוגיית sf_staff (גם בשם sf_reader) לא פותחות שום פעולה של קורא');
eq(array_key_exists('reader', $x = rcall('GET', 'account-me', [], 'nobody', [], ['platform_user' => $STAFF, 'cookie' => $staffCookie])[1]) ? $x['reader'] : 'x', null, 'K11 account-me של מנהל מחובר — אין קורא');
check('K11 העוגיות בשמות שונים', SF_READER_COOKIE === 'sf_reader' && SF_COOKIE === 'sf_staff');
foreach (SF_ACTIONS as $a => $s) if (in_array($s['who'], ['guest', 'account'], true)) check("K11 $a — לא ב-public של app.json (נפתח בהשקה, 11)", !str_contains(implode(' ', sf_app_json()['public'] ?? []), $a));

// ── K10: הערה ושם לתפילה מוצפנים; לא ביומנים ולא במסך ניהול ──
$SECRET_NOTE = 'הערה-סודית-' . bin2hex(random_bytes(4));
$SECRET_NAME = 'שם-לתפילה-' . bin2hex(random_bytes(4));
$r = rcall('POST', 'sync-push', ['items' => [
    ['pid' => 'n1', 'kind' => 'note', 'ref' => 'abcdabcdabcdabcd', 'data' => ['note' => $SECRET_NOTE, 'sid' => 's1']],
    ['pid' => 'pn1', 'kind' => 'prayer_name', 'data' => ['name' => $SECRET_NAME, 'note' => 'לרפואת']],
    ['pid' => 'b1', 'kind' => 'bookmark', 'ref' => 'abcdabcdabcdabcd', 'data' => ['sid' => 's9']],
]], 'D1');
eq(array_column($r[1]['results'] ?? [], 'status'), ['new', 'new', 'new'], 'K10 נשמר');
$raw = (string)sf_val("SELECT data FROM personal_items WHERE pid = 'n1'");
check('K10 במסד: מוצפן (s1:…), בלי הטקסט', str_starts_with($raw, 's1:') && !str_contains($raw, $SECRET_NOTE));
sf_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$file = (string)file_get_contents(SF_DB_PATH) . (string)@file_get_contents(SF_DB_PATH . '-wal');
check('K10 קובץ המסד (ו-WAL) לא מכיל את ההערה', !str_contains($file, $SECRET_NOTE));
check('K10 …ולא את השם לתפילה', !str_contains($file, $SECRET_NAME));
check('K10 סימניה רגילה — לא מוצפנת (רק מה שהאפיון דורש)', str_contains((string)sf_val("SELECT data FROM personal_items WHERE pid = 'b1'"), 's9'));
check('K10 המפתח ב-data/secret.key, 32 בתים', strlen((string)file_get_contents(SF_DATA_DIR . '/secret.key')) === 32);
eq(array_column(rcall('GET', 'sync-pull', [], 'D1', ['since' => '0'])[1]['items'] ?? [], 'data', 'pid')['n1']['note'] ?? null, $SECRET_NOTE, 'K10 בעל המידע מקבל אותו מפוענח');
// היומנים, ומסכי הניהול והמפתח (כל GET של staff/dev)
$logs = (string)@file_get_contents(SF_DB_PATH);
foreach (glob(SF_DATA_DIR . '/*') ?: [] as $f) if (is_file($f)) $logs .= (string)file_get_contents($f);
check('K10 שום קובץ ב-data/ (היומן הטכני, יומן הפעולות) לא מכיל את ההערה או את השם', !str_contains($logs, $SECRET_NOTE) && !str_contains($logs, $SECRET_NAME));
check('K10 יומן הפעולות — בלי הטקסט', !sf_val('SELECT 1 FROM audit_log WHERE meta LIKE ? OR meta LIKE ?', ['%' . $SECRET_NOTE . '%', '%' . $SECRET_NAME . '%']));
$leak = [];
foreach (SF_ACTIONS as $a => $s) {
    if (!in_array($s['who'], ['staff', 'dev'], true) || $s['m'] !== 'GET' || !empty($s['raw']) && in_array($a, ['content-file', 'import-source', 'dev-file'], true)) continue;
    ob_start();
    $res = call('GET', $a, [], $DEV);
    $out = (string)ob_get_clean() . json_encode($res[1], JSON_UNESCAPED_UNICODE);
    if (str_contains($out, $SECRET_NOTE) || str_contains($out, $SECRET_NAME)) $leak[] = $a;
}
eq($leak, [], 'K10 אף מסך ניהול / מפתח (כל פעולות ה-GET) לא מציג הערה או שם לתפילה');
// 08: reminders.php קורא את התזכורות של הקורא — רק לתכנון ולשליחה אליו עצמו (l-reminders בודק שאין שם תוכן ב-dev-reminders)
check('K10 אין פעולת ניהול שקוראת את personal_items', !preg_match('/personal_items/', implode("\n", array_map(fn($f) => (string)file_get_contents($f),
    array_diff(glob(dirname(__DIR__) . '/lib/*.php'), [dirname(__DIR__) . '/lib/sync.php', dirname(__DIR__) . '/lib/readers.php', dirname(__DIR__) . '/lib/reminders.php'])))));

// ── K12: ייצוא, ובקשת מחיקה ───────────────────────────────────
$ex = rcall('GET', 'account-export', [], 'D1')[1]['export'] ?? [];
eq(array_column($ex['items'] ?? [], 'pid'), ['n1', 'pn1', 'b1'], 'K12 הייצוא — כל הפריטים');
eq(array_column($ex['items'] ?? [], 'data', 'pid')['pn1']['name'] ?? null, $SECRET_NAME, 'K12 …כולל שמות לתפילה מפוענחים');
eq($ex['account']['email'] ?? null, 'dana@example.com', 'K12 …ופרטי החשבון');
check('K12 הייצוא — בלי hash של סיסמה ובלי אסימונים', !str_contains(json_encode($ex), '$argon2') && !str_contains(json_encode($ex), 'token'));
eq(array_column(rcall('GET', 'account-export', [], 'E1')[1]['export']['items'] ?? [], 'pid'), [], 'K9 הייצוא של קורא אחר — בלי הפריטים של דנה');
eq(code(rcall('POST', 'account-delete', ['confirm' => 'yes'], 'D1')), 'bad_input', 'K12 מחיקה — רק עם אישור מפורש');
rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'D2');
eq(rcall('POST', 'account-delete', ['confirm' => true], 'D1')[1]['deleted'] ?? null, true, 'K12 בקשת מחיקה');
eq(code(rcall('GET', 'account-devices', [], 'D1')), 'reader_login', 'K12 המכשיר שביקש — מנותק מיד');
eq(code(rcall('GET', 'sync-pull', [], 'D2')), 'reader_login', 'K12 …וגם כל מכשיר אחר');
eq(code(rcall('POST', 'account-login', ['email' => 'dana@example.com', 'password' => 'חדשה-חדשה-2'], 'D3')), 'bad_login', 'K12 הכניסה חסומה מיד (אותה הודעה כמו "לא קיים")');
eq(code(rcall('POST', 'account-google', ['credential' => $jwt(['sub' => '77', 'email' => 'dana@example.com'])], 'D3')), 'bad_login', 'K12 …גם דרך Google');
rcall('POST', 'account-reset-request', ['email' => 'dana@example.com'], 'D3', [], ['ip' => '192.0.2.200']);
eq(end($GLOBALS['sf_mail_out'])['to'] === 'dana@example.com' && str_contains(end($GLOBALS['sf_mail_out'])['body'], '#/account/reset/'), false, 'K12 …ואין איפוס לחשבון שנמחק');
eq(audit_count('reader.delete_request'), 1, 'K12 נרשם ביומן הפעולות (בלי פרטים אישיים)');
check('K12 הנתונים עצמם נשארים עד המחיקה המלאה (⏳ 10)', (int)sf_val("SELECT COUNT(*) FROM personal_items WHERE pid IN ('n1','pn1','b1')") === 3);

done();
