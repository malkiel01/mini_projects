<?php
/**
 * מחוברת או מנותקת (app-rules 2; platform/docs/auth.md, sso.md) — A2.
 *
 * מחוברת ("gate": true והשער פעיל): הזהות — $_SERVER['PLATFORM_USER'] מהשער, בכל בקשה (lib/auth.php).
 * מנותקת: השער לא עובר כאן. הזהות — "כניסה עם הכלים הדיגיטליים": sso_start → המעטפת → sso_callback עם זהות חתומה
 * (Ed25519), שנבדקת כאן כולה (sso.md §2, "מה האפליקציה חייבת לבדוק"): חתימה, aud, iss, state חד-פעמי וקשור לדפדפן,
 * return, exp, ו-jti שלא נוצל. אחרי זה — אותו טיפול כמו זהות מהשער (ts_user_for), ו-session של האפליקציה.
 *
 * המצב — sso.php?action=config של המעטפת, במטמון לדקה ב-data/platform-config.json (כישלון — 15 שניות). המעטפת לא
 * עונה → "unknown": נכשלים סגור — אין כניסה חדשה (spec-stage-a 20.1 ב').
 *
 * הרשאה במנותקת (20.1 ד'): grant_status — בקשה חתומה שרת-לשרת (platform/docs/embed.md §2, sso.md §6) במפתח של
 * האפליקציה. הפרטי — במסד (data/), מוצפן במפתח של lib/secret.php; הציבורי — api.php?action=platform_key.
 *
 * הכתובת של האפליקציה — מ-app.json (sso.return), **לעולם לא מהבקשה**: Host מגיע מהלקוח, וכתובת שנגזרת ממנו הייתה
 * מפנה את בקשת המפתח ואת ה-iss לשרת של תוקף.
 *
 * בפיתוח ובבדיקות (TS_DEV, tests/dev-prepend.php — לא רץ בשרת): TS_DEV_PLATFORM קובע את המצב בלי לפנות למעטפת,
 * TS_APP_URL מחליף את הכתובת, והמפתח והתשובות — מ-TS_HTTP_FIXTURES (lib/http.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/secret.php';

const TS_CLIENT_ID = 'trading-strategies';
const TS_SSO_COOKIE = 'ts_sso';          // ה-state של הכניסה, קשור לדפדפן שהתחיל אותה
const TS_SSO_STATE_TTL = 600;
const TS_SSO_SKEW = 60;                  // סטיית שעון מותרת בבדיקת exp
const TS_GRANT_TTL = 300;                // תשובת grant_status נשמרת ב-session עד 5 דקות (sso.md §6)

final class TsUnavailable extends RuntimeException {}

/** "https://mbe-plus.com/mini_projects/trading-strategies/" — מ-app.json. */
function ts_app_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        if (defined('TS_DEV') && ($u = getenv('TS_APP_URL'))) {
            $base = rtrim($u, '/') . '/';
        } else {
            $app = json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true);
            $ret = is_array($app) ? (string)($app['sso']['return'][0] ?? '') : '';
            if (!preg_match('~^(https://[a-z0-9.\-]+(?:/[A-Za-z0-9._\-]+)*/)api\.php\?action=sso_callback$~', $ret, $m)) {
                throw new RuntimeException('app.json: sso.return חסר או לא תקין');
            }
            $base = $m[1];
        }
    }
    return $base . $path;
}

/** "/mini_projects/trading-strategies/" */
function ts_app_path(): string
{
    return (string)parse_url(ts_app_url(), PHP_URL_PATH);
}

/** "https://mbe-plus.com/mini_projects" — התיקייה שמעל האפליקציה. */
function ts_platform_base(): string
{
    return rtrim(dirname(rtrim(ts_app_url(), '/')), '/');
}

function ts_platform_sso_url(string $query): string
{
    return ts_platform_base() . '/platform/sso.php?' . $query;
}

function ts_sso_return(): string
{
    return ts_app_url('api.php?action=sso_callback');
}

/** GET למעטפת: JSON של תשובת 200, או null. */
function ts_platform_get(string $url): ?array
{
    try {
        [$status, $body] = ts_http_get($url, [], 4);
    } catch (Throwable) {
        return null;
    }
    $out = $status === 200 ? json_decode($body, true) : null;
    return is_array($out) ? $out : null;
}

function ts_platform_cfg_path(): string
{
    return defined('TS_DB_PATH') ? TS_DB_PATH . '.platform-config.json' : dirname(__DIR__) . '/data/platform-config.json';
}

/** ההגדרות של האפליקציה במעטפת: {connected, login: {password, google}}. null — המעטפת לא עונה. */
function ts_platform_config(): ?array
{
    static $memo = false;
    if ($memo !== false) return $memo;
    $now = time();
    $path = ts_platform_cfg_path();
    $cached = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (is_array($cached) && (int)($cached['until'] ?? 0) > $now) return $memo = $cached['config'] ?? null;
    $out = ts_platform_get(ts_platform_sso_url(http_build_query(['action' => 'config', 'client' => TS_CLIENT_ID])));
    $cfg = is_array($out) && is_bool($out['connected'] ?? null)
        ? ['connected' => $out['connected'], 'login' => [
            'password' => ($out['login']['password'] ?? null) === true,
            'google' => ($out['login']['google'] ?? null) === true,
        ]] : null;
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
    @file_put_contents($path, json_encode(['until' => $now + ($cfg ? 60 : 15), 'config' => $cfg]), LOCK_EX);
    return $memo = $cfg;
}

/** connected · detached · unknown — כשאין זהות מהשער בבקשה. */
function ts_platform_mode(): string
{
    if (defined('TS_DEV') && defined('TS_DEV_PLATFORM') && TS_DEV_PLATFORM !== 'live') return TS_DEV_PLATFORM;
    $cfg = ts_platform_config();
    if ($cfg === null) return 'unknown';
    return $cfg['connected'] ? 'connected' : 'detached';
}

/** login.password של המעטפת (רק במנותקת). בפיתוח — TS_DEV_PASSWORD. */
function ts_platform_password_on(): bool
{
    if (defined('TS_DEV') && defined('TS_DEV_PLATFORM') && TS_DEV_PLATFORM !== 'live') return defined('TS_DEV_PASSWORD') && TS_DEV_PASSWORD;
    return (ts_platform_config()['login']['password'] ?? false) === true;
}

/** המפתח הציבורי של המעטפת (sso.php?action=key) — בינארי, או null. מטמון 24 שעות; $fresh — אחרי חתימה שנכשלה. */
function ts_platform_sso_key(bool $fresh = false): ?string
{
    $now = time();
    $cached = json_decode((string)ts_setting('platform_sso_key', ''), true);
    if (!$fresh && is_array($cached) && (int)($cached['until'] ?? 0) > $now) {
        $k = base64_decode((string)($cached['key'] ?? ''), true);
        if (is_string($k) && strlen($k) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return $k;
    }
    $out = ts_platform_get(ts_platform_sso_url('action=key'));
    $key = is_array($out) && ($out['alg'] ?? '') === 'Ed25519' && is_string($out['key'] ?? null)
        ? base64_decode(strtr($out['key'], '-_', '+/'), true) : false;
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return null;
    ts_setting_set('platform_sso_key', json_encode(['until' => $now + 86400, 'key' => base64_encode($key)]));
    return $key;
}

/**
 * מה מסך הכניסה מציג (20.1 ד'). mode: connected / detached / unknown. platform — "כניסה עם הכלים הדיגיטליים" (תמיד
 * כשמנותקת); password — מייל וסיסמה (רק כשמנותקת ו-login.password). Google — לא בפרוסה הזו (ש8), גם כשהמעטפת מתירה.
 */
function ts_login_config(): array
{
    $mode = ts_identity() ? 'connected' : ts_platform_mode();
    return ['mode' => $mode, 'platform' => $mode === 'detached', 'password' => $mode === 'detached' && ts_platform_password_on()];
}

/** לאן חוזרים אחרי הכניסה (או כשנדחתה): הדף של האפליקציה, ואולי #/login/<סיבה>. */
function ts_login_redirect(string $error = ''): string
{
    return ts_app_path() . ($error !== '' ? '#/login/' . $error : '');
}

/** "כניסה עם הכלים הדיגיטליים": מחזיר את הכתובת להפניה. */
function ts_sso_start(): string
{
    if (ts_login_config()['mode'] !== 'detached') return ts_login_redirect();
    $state = bin2hex(random_bytes(16));
    $now = time();
    $db = ts_db();
    $db->prepare('INSERT INTO sso_states (state_hash, created_at, expires_at) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $state), $now, $now + TS_SSO_STATE_TTL]);
    if (random_int(1, 20) === 1) ts_sso_cleanup();
    ts_sso_cookie($state);
    return ts_platform_sso_url(http_build_query(['client' => TS_CLIENT_ID, 'state' => $state, 'return' => ts_sso_return()]));
}

function ts_sso_cleanup(): void
{
    $now = time();
    $db = ts_db();
    $db->prepare('DELETE FROM sso_states WHERE expires_at < ?')->execute([$now - 86400]);
    $db->prepare('DELETE FROM sso_jti WHERE exp < ?')->execute([$now]);
}

function ts_sso_cookie(string $state): void
{
    if (headers_sent()) return;
    setcookie(TS_SSO_COOKIE, $state, [
        'expires' => $state === '' ? time() - 3600 : time() + TS_SSO_STATE_TTL,
        'path' => ts_cookie_path(),
        'secure' => !defined('TS_DEV'),
        'httponly' => true,
        'samesite' => 'Lax',          // חוזרים מהמעטפת בניווט רגיל (GET) — Lax שולח את העוגייה
    ]);
}

/** דחייה: שורה ביומן (הסיבה, בלי האסימון), וחזרה למסך הכניסה. */
function ts_sso_reject(string $reason, string $error = 'invalid'): string
{
    if (function_exists('devloop_log_write')) devloop_log_write('warn', 'auth', 'sso_rejected', ['message' => $reason]);
    return ts_login_redirect($error);
}

/**
 * בודק זהות חתומה (sso.md §2, כל ארבעת הסעיפים, ועוד iss ו-jti). מחזיר את ה-payload, או מחרוזת — הסיבה לדחייה.
 */
function ts_sso_verify(string $token, string $state): array|string
{
    [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
    $b64 = fn(string $s) => base64_decode(strtr($s, '-_', '+/'), true);
    $sigBin = $b64($sig);
    if ($payload === '' || strlen($token) > 4000 || !is_string($sigBin) || strlen($sigBin) !== SODIUM_CRYPTO_SIGN_BYTES) return 'bad_token';
    // 1. החתימה — במפתח הציבורי. נכשלה: מרעננים את המפתח פעם אחת (אולי התחלף)
    $ok = false;
    foreach ([false, true] as $fresh) {
        $key = ts_platform_sso_key($fresh);
        if ($key !== null && sodium_crypto_sign_verify_detached($sigBin, $payload, $key)) { $ok = true; break; }
    }
    if (!$ok) return 'bad_signature';
    $id = json_decode((string)$b64($payload), true);
    if (!is_array($id)) return 'bad_payload';
    // 2. aud — זהות שנחתמה לאפליקציה אחרת נדחית
    if (($id['aud'] ?? null) !== TS_CLIENT_ID) return 'bad_aud';
    if (($id['iss'] ?? null) !== ts_platform_base() . '/') return 'bad_iss';
    // 3. state — מה שנשמר אצלנו (הקורא כבר סימן אותו כנוצל)
    if (!is_string($id['state'] ?? null) || !hash_equals($state, $id['state'])) return 'bad_state';
    // 4. return ו-exp
    if (($id['return'] ?? null) !== ts_sso_return()) return 'bad_return';
    if (!is_int($id['exp'] ?? null) || $id['exp'] + TS_SSO_SKEW < time()) return 'expired';
    // jti — אותה זהות לא מתקבלת פעמיים
    if (!is_string($id['jti'] ?? null) || $id['jti'] === '' || strlen($id['jti']) > 100) return 'bad_jti';
    try {
        ts_db()->prepare('INSERT INTO sso_jti (jti, exp) VALUES (?, ?)')->execute([$id['jti'], $id['exp'] + TS_SSO_SKEW]);
    } catch (PDOException) {
        return 'replay';
    }
    return $id;
}

/**
 * החזרה מהמעטפת. מחזיר את הכתובת להפניה; בהצלחה — פותח session (ts_session_open) וכותב את העוגייה.
 * $cookieState — מה שבעוגייה ts_sso של הדפדפן הזה.
 */
function ts_sso_callback(array $get, string $cookieState): string
{
    if (ts_login_config()['mode'] !== 'detached') return ts_login_redirect();
    ts_sso_cookie('');
    $state = is_string($get['state'] ?? null) ? $get['state'] : '';
    // state: נוצר כאן, בדפדפן הזה, לא נוצל ולא פג — ומסומן כנוצל מיד (חד-פעמי)
    if (!preg_match('/^[a-f0-9]{32}$/', $state) || $cookieState === '' || !hash_equals($cookieState, $state)) return ts_sso_reject('state_unknown');
    $st = ts_db()->prepare('UPDATE sso_states SET used_at = ? WHERE state_hash = ? AND used_at IS NULL AND expires_at > ?');
    $st->execute([time(), hash('sha256', $state), time()]);
    if ($st->rowCount() !== 1) return ts_sso_reject('state_used_or_expired');
    if (isset($get['error'])) return ts_sso_reject('cancelled', 'cancelled');
    $id = ts_sso_verify(is_string($get['token'] ?? null) ? $get['token'] : '', $state);
    if (is_string($id)) return ts_sso_reject($id);
    $ident = ts_identity_clean(['sub' => $id['sub'] ?? null, 'name' => $id['name'] ?? '', 'email' => $id['email'] ?? null,
        'email_verified' => $id['email_verified'] ?? false]);
    if (!$ident) return ts_sso_reject('bad_sub');
    $ident['platform_admin'] = null;   // הזהות החתומה לא נושאת אותו — לא נוגעים בדגל השמור (ts_user_for, 20.1 ו')
    try {
        $user = ts_user_for($ident);
    } catch (TsConflict) {
        return ts_sso_reject('account_conflict', 'conflict');   // 20.1 ה'.3 — נרשם ב-admin_log
    }
    // זהות חתומה ניתנת רק למי שמורשה — זו בדיקת ההרשאה של הכניסה הזו (grant_checked_at = עכשיו)
    ts_session_open($user, 'sso');
    return ts_login_redirect();
}

// ── המפתח של האפליקציה מול המעטפת (grant_status) ──────────────

/** ['kid', 'public' (base64), 'secret' (בינארי)]. נוצר בפעם הראשונה; הפרטי — במסד, מוצפן (lib/secret.php). */
function ts_app_key(): array
{
    $row = json_decode((string)ts_setting('platform_sign_key', ''), true);
    if (!is_array($row)) {
        $pair = sodium_crypto_sign_keypair();
        $new = ['kid' => gmdate('Y-m') . '-' . bin2hex(random_bytes(3)), 'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(ts_encrypt(sodium_crypto_sign_secretkey($pair)))];
        // INSERT OR IGNORE — שתי בקשות במקביל: מי שהפסיד קורא את מה שנכתב
        ts_db()->prepare('INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)')->execute(['platform_sign_key', json_encode($new)]);
        $row = json_decode((string)ts_setting('platform_sign_key', ''), true);
    }
    return ['kid' => (string)$row['kid'], 'public' => (string)$row['public'], 'secret' => ts_decrypt(base64_decode((string)$row['secret']))];
}

/** הפעולה הציבורית platform_key: המפתח הציבורי בלבד. */
function ts_app_key_public(): array
{
    $k = ts_app_key();
    return ['client' => TS_CLIENT_ID, 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => $k['public']]]];
}

/**
 * האם למשתמש יש הרשאה לאפליקציה במעטפת (sso.md §6). true / false, או null — המעטפת לא ענתה (הקורא נכשל סגור).
 * בקשה חתומה: "<action>\n<time>\n<nonce>\n<sha256 של הגוף>" (embed.md §2).
 */
function ts_grant_status(string $sub): ?bool
{
    if (!preg_match('/^[0-9]{1,12}$/', $sub)) return false;
    try {
        $key = ts_app_key();
        $body = json_encode(['sub' => $sub]);
        $time = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $sig = sodium_crypto_sign_detached("grant_status\n$time\n$nonce\n" . hash('sha256', $body), $key['secret']);
        [$status, $res] = ts_http_post(ts_platform_base() . '/platform/provision.php?action=grant_status',
            ['Content-Type: application/json', 'X-Embed-Client: ' . TS_CLIENT_ID, "X-Embed-Time: $time", "X-Embed-Nonce: $nonce",
                'X-Embed-Signature: ' . $key['kid'] . '.' . base64_encode($sig)], $body, 5);
    } catch (Throwable) {
        return null;
    }
    $out = $status === 200 ? json_decode($res, true) : null;
    return is_bool($out['allowed'] ?? null) ? $out['allowed'] : null;
}
