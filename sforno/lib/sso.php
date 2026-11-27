<?php
/**
 * המצב המנותק — ספורנו בלי שער (אפיון 11 §1.2–1.3, platform/docs/sso.md §2, PF-002).
 *
 * **מופעל רק לפי data/launch.json.** בלי הקובץ — אין כאן שום פעולה: sso-start / sso-finish / platform-key מחזירות
 * unknown_action (404) כמו פעולה שלא קיימת, וזיהוי המנהל נשאר בדיוק כמו ב-01 (PLATFORM_USER בלבד).
 *
 *   {"detached": true,
 *    "platform": "https://<המעטפת>/",                              כתובת הכלים הדיגיטליים (https, מסתיים ב-/)
 *    "sso_key":  "<base64url>",                                    המפתח הציבורי מ-platform/sso.php?action=key
 *    "return":   "https://sforno.mbe-plus.com/"}                    כתובת החזרה — בדיוק כמו ב-sso.return של app.json
 *
 *   "detached": false (או חסר) — רק המפתח של ספורנו (platform-key) זמין, כדי שהמעטפת תוכל לרשום אותו לפני המעבר.
 *
 * הזרימה: "כניסה עם הכלים הדיגיטליים" → sso-start (state אקראי: בעוגיית sf_sso, ו-hash שלו ב-meta לשימוש חד-פעמי, 10 דקות)
 * → platform/sso.php → חזרה ל-return עם ?token=&state= → הדפדפן שולח ל-sso-finish → בדיקת חתימה (Ed25519), aud, iss, state,
 * return, exp → grant_status (תמיד טרי) → מנהל לפי sub (כמו 01) → עוגיית sf_staff.
 * בכל בקשה אחרי כן: ה-session מהעוגייה + grant_status, שמור 5 דקות. הפלטפורמה לא עונה — נכשל סגור (503, ה-session נשאר).
 * ההרשאה בוטלה — ה-session נמחק (403 no_grant).
 *
 * grant_status — POST חתום (platform/docs/embed.md §2) במפתח הפרטי של ספורנו: נוצר בבקשה הראשונה, נשמר מוצפן (sf_seal) ב-meta.
 * בבדיקות: $GLOBALS['sf_http_post'] מחליף את הבקשה, $GLOBALS['sf_sso_cookie_out'] — את העוגייה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/readers.php';

const SF_SSO_COOKIE = 'sf_sso';
const SF_SSO_STATE_TTL = 600;
const SF_GRANT_TTL = 300;

/** ההגדרה מ-data/launch.json, מנוקה; null — אין קובץ (ההתנהגות של 01). 'ok' = false — מנותקת אבל ההגדרה חסרה (נכשל סגור). */
function sf_launch(): ?array
{
    $f = sf_data_dir() . '/launch.json';
    if (!is_file($f)) return null;
    $c = json_decode((string)@file_get_contents($f), true);
    if (!is_array($c)) return ['detached' => false, 'ok' => false];
    $platform = is_string($c['platform'] ?? null) && preg_match('~^https://[A-Za-z0-9.\-]+(:[0-9]{1,5})?/([A-Za-z0-9._\-]+/)*$~', $c['platform']) ? $c['platform'] : null;
    $key = is_string($c['sso_key'] ?? null) ? sf_b64url_decode($c['sso_key']) : null;
    $return = is_string($c['return'] ?? null) && str_starts_with($c['return'], 'https://') && filter_var($c['return'], FILTER_VALIDATE_URL) ? $c['return'] : null;
    $ok = $platform !== null && $key !== null && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && $return !== null;
    return ['detached' => ($c['detached'] ?? false) === true, 'ok' => $ok, 'platform' => $platform, 'key' => $ok ? $key : null, 'return' => $return];
}

/** ספורנו מנותקת (data/launch.json עם "detached": true)? */
function sf_detached(): bool
{
    return (sf_launch()['detached'] ?? false) === true;
}

function sf_b64url_decode(string $s): ?string
{
    if ($s === '' || preg_match('/^[A-Za-z0-9_\-+\/]+=*$/', $s) !== 1) return null;
    $d = base64_decode(strtr($s, '-_', '+/'), true);
    return $d === false ? null : $d;
}

/** ההגדרה המנותקת התקינה, או חריגה: בלי קובץ / לא מנותקת — unknown_action; מנותקת וחסרה — login_unavailable. */
function sf_launch_detached(): array
{
    $l = sf_launch();
    if (!($l['detached'] ?? false)) sf_fail('unknown_action');
    if (!$l['ok']) sf_fail('login_unavailable');
    return $l;
}

// ── המפתח של ספורנו (לחתימת grant_status) ───────────────────

/** [secret, public, kid]. נוצר פעם אחת; הפרטי מוצפן ב-meta, לא בגיט ולא בקובץ פתוח. */
function sf_sign_keypair(): array
{
    $sealed = sf_meta('sso_sign_key');
    $kp = is_string($sealed) ? sf_unseal($sealed) : null;
    if (!is_string($kp) || strlen($kp) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
        if (is_string($sealed)) throw new SfError('storage');   // יש מפתח ולא נפתח — לא מחליפים בשקט
        // אטומי: INSERT (k הוא מפתח ראשי). בקשה מקבילה שכבר יצרה — מנצחת, וקוראים את שלה
        $kp = sodium_crypto_sign_keypair();
        try {
            sf_exec('INSERT INTO meta (k, v) VALUES (?, ?)', ['sso_sign_key', sf_seal($kp)]);
            sf_audit('sso.key_create', 'meta', null, [], actor: null);
        } catch (PDOException) {
            $kp = sf_unseal((string)sf_meta('sso_sign_key'));
            if (!is_string($kp) || strlen($kp) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) throw new SfError('storage');
        }
    }
    $pub = sodium_crypto_sign_publickey($kp);
    return [sodium_crypto_sign_secretkey($kp), $pub, substr(hash('sha256', $pub), 0, 12)];
}

/** platform-key — המבנה של provision.key_url / grant_status.key_url (sso.md §6). מפתח ציבורי בלבד. */
function sf_platform_key_out(): array
{
    if (sf_launch() === null) sf_fail('unknown_action');
    [, $pub, $kid] = sf_sign_keypair();
    return ['client' => SF_CLIENT_ID, 'keys' => [['kid' => $kid, 'alg' => 'Ed25519', 'key' => base64_encode($pub)]]];
}

/** הכותרות החתומות לבקשה שרת-לשרת (embed.md §2). */
function sf_signed_headers(string $action, string $body): array
{
    [$sk, , $kid] = sf_sign_keypair();
    $time = (string)sf_time();
    $nonce = bin2hex(random_bytes(16));
    $sig = sodium_crypto_sign_detached("$action\n$time\n$nonce\n" . hash('sha256', $body), $sk);
    return ['X-Embed-Client' => SF_CLIENT_ID, 'X-Embed-Time' => $time, 'X-Embed-Nonce' => $nonce, 'X-Embed-Signature' => $kid . '.' . base64_encode($sig)];
}

/** POST קצר עם כותרות: [status, body] או null. בבדיקות — $GLOBALS['sf_http_post']($url, $body, $headers). */
function sf_http_post(string $url, string $body, array $headers, int $timeout = 5): ?array
{
    if (isset($GLOBALS['sf_http_post'])) return ($GLOBALS['sf_http_post'])($url, $body, $headers);
    if (!function_exists('curl_init')) return null;
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'sforno/sso']);
    $out = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $out === false ? null : [$status, (string)$out];
}

/**
 * האם ל-sub יש הרשאה ל-sforno בפלטפורמה — true / false, או null: הפלטפורמה לא ענתה כמו שצריך (נכשלים סגור).
 * $fresh — בכניסה תמיד; אחרת התשובה נשמרת 5 דקות (sso.md: "לא לשאול בלולאה").
 */
function sf_grant_status(array $launch, string $sub, bool $fresh = false): ?bool
{
    $k = 'sso_grant:' . $sub;
    $c = json_decode((string)sf_meta($k, ''), true);
    if (!$fresh && is_array($c) && (int)($c['at'] ?? 0) > sf_time() - SF_GRANT_TTL) return (bool)$c['allowed'];
    $body = (string)json_encode(['sub' => $sub]);
    $res = sf_http_post($launch['platform'] . 'platform/provision.php?action=grant_status', $body, sf_signed_headers('grant_status', $body));
    $j = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
    if (!is_array($j) || !is_bool($j['allowed'] ?? null)) return null;
    sf_meta_set($k, (string)json_encode(['allowed' => $j['allowed'], 'at' => sf_time()]));
    return $j['allowed'];
}

// ── הזרימה ───────────────────────────────────────────────────

function sf_set_sso_cookie(string $state, int $ttl): void
{
    $GLOBALS['sf_sso_cookie_out'] = $state;
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    setcookie(SF_SSO_COOKIE, $state, ['expires' => $state === '' ? 1 : time() + $ttl, 'path' => sf_base_path(),
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

/** POST בלי אסימון מהדפדפן שלנו: סוג מדיה מדויק + Sec-Fetch-Site / Origin (כמו guest ב-05). */
function sf_sso_browser_check(array $env): void
{
    if (sf_media_type($env['content_type'] ?? '') !== 'application/json') sf_fail('content_type');
    if (!sf_same_site($env)) sf_fail('csrf');
}

/** sso-start → {url}: לאן לשלוח את הדפדפן. */
function sf_sso_start(array $env): array
{
    $l = sf_launch_detached();
    sf_sso_browser_check($env);
    sf_rate('sso_start', (string)($env['ip'] ?? ''), 30, 600);
    $now = sf_time();
    sf_exec("DELETE FROM meta WHERE k LIKE 'sso_state:%' AND v < ?", [(string)$now]);
    $state = bin2hex(random_bytes(24));
    sf_meta_set('sso_state:' . hash('sha256', $state), (string)($now + SF_SSO_STATE_TTL));
    sf_set_sso_cookie($state, SF_SSO_STATE_TTL);
    return ['url' => $l['platform'] . 'platform/sso.php?' . http_build_query(['client' => SF_CLIENT_ID, 'state' => $state, 'return' => $l['return']])];
}

/**
 * הזהות מהטוקן אחרי כל הבדיקות של sso.md §2 ("כולם!"), או null. $state — מה שנשמר אצלנו (כבר נבדק שהוא שלנו).
 * מחזירה גם את הסיבה ב-$why (ליומן — בלי הטוקן).
 */
function sf_sso_verify(array $l, string $token, string $state, ?string &$why = null): ?array
{
    [$p, $s] = array_pad(explode('.', $token, 2), 2, '');
    $payload = sf_b64url_decode($p);
    $sig = sf_b64url_decode($s);
    if ($payload === null || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) { $why = 'format'; return null; }
    if (!sodium_crypto_sign_verify_detached($sig, $p, $l['key'])) { $why = 'signature'; return null; }
    $id = json_decode($payload, true);
    if (!is_array($id)) { $why = 'format'; return null; }
    $checks = [
        'aud' => ($id['aud'] ?? null) === SF_CLIENT_ID,
        'iss' => ($id['iss'] ?? null) === $l['platform'],
        'state' => is_string($id['state'] ?? null) && hash_equals($state, $id['state']),
        'return' => ($id['return'] ?? null) === $l['return'],
        'exp' => is_int($id['exp'] ?? null) && $id['exp'] >= sf_time(),
        'sub' => is_scalar($id['sub'] ?? null) && preg_match('/^[0-9]{1,12}$/', (string)$id['sub']) === 1,
    ];
    foreach ($checks as $k => $ok) if (!$ok) { $why = $k; return null; }
    // אותו ניקוי כמו הזהות מהשער; platform_admin לא מגיע בטוקן — ההגדרה "מפתח" נשארת כמו שהיא (keep_developer)
    $clean = sf_platform_identity((string)json_encode(['sub' => (string)$id['sub'], 'name' => $id['name'] ?? '', 'email' => $id['email'] ?? null,
        'email_verified' => ($id['email_verified'] ?? false) === true, 'granted' => true]));
    return $clean === null ? null : $clean + ['keep_developer' => true];
}

/** sso-finish {token, state} — או {error} כשהמשתמש ביטל. מחזיר {staff}; העוגייה sf_staff נכתבת. */
function sf_sso_finish(array $in, array $env): array
{
    $l = sf_launch_detached();
    sf_sso_browser_check($env);
    sf_rate('sso_finish', (string)($env['ip'] ?? ''), 30, 600);
    $cookie = (string)($env['sso_cookie'] ?? '');
    $state = is_string($in['state'] ?? null) ? $in['state'] : '';
    sf_set_sso_cookie('', 0);   // חד-פעמי, מה שלא יקרה
    // חד-פעמי ואטומי: רק הבקשה שמחקה את השורה (בתוקף) ממשיכה — שתי בקשות במקביל עם אותו state ← אחת בלבד
    $mk = 'sso_state:' . hash('sha256', $cookie);
    $fresh = preg_match('/^[a-f0-9]{48}$/', $cookie) === 1
        && sf_exec('DELETE FROM meta WHERE k = ? AND v >= ?', [$mk, (string)sf_time()]) === 1;
    if (!$fresh || !hash_equals($cookie, $state)) {
        sf_audit('staff.sso_fail', 'staff', null, ['why' => 'state'], actor: null);
        sf_fail('sso');
    }
    if (isset($in['error'])) sf_fail('sso', 'הכניסה בוטלה');
    $why = null;
    $id = sf_sso_verify($l, is_string($in['token'] ?? null) ? $in['token'] : '', $cookie, $why);
    if ($id === null) {
        sf_audit('staff.sso_fail', 'staff', null, ['why' => $why], actor: null);
        sf_fail('sso');
    }
    $allowed = sf_grant_status($l, $id['sub'], true);
    if ($allowed === null) sf_fail('login_unavailable');
    if (!$allowed) {
        sf_audit('staff.sso_denied', 'staff', null, ['sub' => $id['sub']], actor: null);
        sf_session_drop((string)($env['cookie'] ?? ''));
        sf_fail('no_grant');
    }
    $staff = sf_staff_for($id);
    if ($staff['status'] !== 'active') sf_fail('suspended');
    sf_session_drop((string)($env['cookie'] ?? ''));   // session ישן בדפדפן (אולי של מישהו אחר) — לא ממשיכים אותו
    sf_session_for($staff, '', (string)($env['ua'] ?? ''));
    sf_audit('staff.sso_login', 'staff', $staff['pid'], [], actor: (int)$staff['id']);
    return ['staff' => sf_staff_public($staff, (int)$staff['id'])];
}

/**
 * זיהוי מנהל במצב מנותק (נקרא מ-sf_authenticate כשאין PLATFORM_USER): העוגייה sf_staff + grant_status.
 * 401 sso_required — אין session; 403 no_grant — ההרשאה בוטלה (ה-session נמחק); 503 — הפלטפורמה לא עונה.
 */
function sf_authenticate_detached(array $env): array
{
    $l = sf_launch();
    if (!$l['ok']) sf_fail('login_unavailable');
    $cookie = (string)($env['cookie'] ?? '');
    $s = preg_match('/^[a-f0-9]{64}$/', $cookie) === 1 ? sf_row('SELECT * FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $cookie)]) : null;
    $staff = $s && $s['expires_at'] > sf_now() ? sf_row('SELECT * FROM staff WHERE id = ?', [$s['staff_id']]) : null;
    if (!$staff || (string)$staff['platform_sub'] === '' || $staff['platform_sub'] !== $s['platform_sub']) {
        sf_session_drop($cookie);
        sf_fail('sso_required');
    }
    if ($staff['status'] !== 'active') {
        sf_session_drop($cookie);
        sf_fail('suspended');
    }
    $allowed = sf_grant_status($l, (string)$staff['platform_sub']);
    if ($allowed === null) sf_fail('login_unavailable');
    if (!$allowed) {
        sf_session_drop($cookie);
        sf_fail('no_grant');
    }
    $session = sf_session_for($staff, $cookie, (string)($env['ua'] ?? ''));
    sf_actor((int)$staff['id'], true);
    return ['staff' => $staff, 'session' => $session];
}
