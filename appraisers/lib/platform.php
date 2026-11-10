<?php
/**
 * המעטפת (הפלטפורמה שהאפליקציה יושבת בה): מי המשתמש, והאם האפליקציה מחוברת — אפיון 01 §5.1,
 * platform/docs/auth.md, sso.md §0.
 *
 * מחוברת ("gate": true): השער כותב את $_SERVER['PLATFORM_USER'] לכל בקשת PHP. המפתח אינו HTTP_*, ולכן אי אפשר
 * לזייף אותו בבקשה. אין זהות → שואלים את המעטפת אם האפליקציה מחוברת (sso.php?action=config, ב-HTTP — לא בקריאת
 * קובץ), והתשובה נשמרת 60 שניות (כישלון — 15).
 *
 * בפרוסה הזו: מנותקת או לא זמינה → כל פעולה שאינה ציבורית מחזירה 503. נכשל סגור.
 *
 * בדיקות (רק כש-PHP_SAPI הוא cli): APR_PLATFORM קובע את המצב, ו-APR_TEST_IDENTITY מזריק זהות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const APR_CLIENT_ID = 'appraisers';

/** ה-JSON של הזהות כפי שהגיע — או null. $server ו-$sapi מוזרקים בבדיקות. */
function apr_platform_raw(?array $server = null, ?string $sapi = null): ?string
{
    $server ??= $_SERVER;
    $raw = $server['PLATFORM_USER'] ?? null;
    if (is_string($raw) && $raw !== '') return $raw;
    if (($sapi ?? PHP_SAPI) === 'cli') {
        $t = getenv('APR_TEST_IDENTITY');
        if (is_string($t) && $t !== '') return $t;
    }
    return null;
}

/**
 * הזהות, מנוקה: sub (מחרוזת ספרות), name, email (באותיות קטנות או null), email_verified, platform_admin, granted.
 * null — אין זהות תקינה.
 */
function apr_platform_identity(?string $raw): ?array
{
    $id = json_decode((string)$raw, true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || !preg_match('/^[0-9]{1,12}$/', (string)$id['sub'])) return null;
    $email = is_string($id['email'] ?? null) && mb_strlen($id['email']) <= 200 && filter_var($id['email'], FILTER_VALIDATE_EMAIL)
        ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($id['name'] ?? '')) ?? ''), 0, 120),
        'email' => $email,
        'email_verified' => $email !== null && ($id['email_verified'] ?? false) === true,
        'platform_admin' => ($id['platform_admin'] ?? false) === true,
        'granted' => ($id['granted'] ?? null) === true,   // לא סומכים על כך שהשער כבר חסם
    ];
}

/** connected · detached · unavailable. */
function apr_platform_mode(?string $raw, ?string $sapi = null): string
{
    if (apr_platform_identity($raw)) return 'connected';
    if (defined('APR_DEV_PLATFORM')) return APR_DEV_PLATFORM;          // tests/dev-prepend.php — שרת פיתוח מקומי בלבד
    if (($sapi ?? PHP_SAPI) === 'cli') {
        $m = getenv('APR_PLATFORM');
        return in_array($m, ['connected', 'detached', 'unavailable'], true) ? $m : 'connected';
    }
    $cfg = apr_platform_config();
    if ($cfg === null) return 'unavailable';
    return ($cfg['connected'] ?? null) === true ? 'connected' : 'detached';
}

/** שורש המעטפת ("https://host/mini_projects") — נבנה מ-SERVER_NAME (לא מ-Host של הבקשה) ומהמיקום של האפליקציה. */
function apr_platform_base(): string
{
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $base = rtrim(dirname(rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/appraisers/api.php')), '/')), '/');
    return 'https://' . $host . $base;
}

/** הכתובת של sso.php במעטפת. */
function apr_platform_url(string $query): string
{
    return apr_platform_base() . '/platform/sso.php?' . $query;
}

/** הנתיב של האפליקציה באתר ("/mini_projects/appraisers/"), והכתובת המלאה של קובץ בה. */
function apr_app_path(): string
{
    return rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/appraisers/api.php')), '/') . '/';
}

function apr_app_url(string $path = ''): string
{
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
    return 'https://' . $host . apr_app_path() . $path;
}

/** בקשת GET קצרה. מחזיר [status, body], או null בכישלון. בבדיקות — $GLOBALS['apr_http'] מחליף אותה. */
function apr_http_get(string $url, int $timeout = 3): ?array
{
    if (isset($GLOBALS['apr_http'])) return ($GLOBALS['apr_http'])($url);
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'appraisers/health']);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $body === false ? null : [$status, (string)$body];
}

/** ההגדרות של האפליקציה במעטפת: {connected, login}. null — המעטפת לא עונה. נשמר במטמון: 60 שניות, כישלון — 15. */
function apr_platform_config(bool $fresh = false): ?array
{
    $now = apr_time();
    $cached = json_decode((string)apr_meta('platform_config_cache', ''), true);
    if (!$fresh && is_array($cached) && (int)($cached['until'] ?? 0) > $now) return $cached['config'];
    $res = apr_http_get(apr_platform_url('action=config&client=' . APR_CLIENT_ID));
    $cfg = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
    $cfg = is_array($cfg) && array_key_exists('connected', $cfg) ? ['connected' => $cfg['connected'] === true, 'login' => (array)($cfg['login'] ?? [])] : null;
    apr_meta_set('platform_config_cache', json_encode(['until' => $now + ($cfg ? 60 : 15), 'config' => $cfg]));
    return $cfg;
}

/** בקשת POST קצרה עם גוף JSON. מחזיר [status, body], או null בכישלון. בבדיקות — $GLOBALS['apr_http_post']. */
function apr_http_post(string $url, string $body, array $headers = [], int $timeout = 5): ?array
{
    if (isset($GLOBALS['apr_http_post'])) return ($GLOBALS['apr_http_post'])($url, $body, $headers);
    if (!function_exists('curl_init') || PHP_SAPI === 'cli') return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'appraisers/provision',
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $res === false ? null : [$status, (string)$res];
}

// ── מפתח החתימה של המערכת מול המעטפת (אפיון 02 §4.1) ─────────

/** המפתח הפעיל: ['kid', 'public' (base64), 'secret' (בינארי)]. נוצר בהרצה הראשונה; הפרטי נשמר מוצפן במסד. */
function apr_pkey(): array
{
    $row = apr_row("SELECT * FROM platform_keys WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    if (!$row) {
        $row = apr_tx(function () {
            $again = apr_row("SELECT * FROM platform_keys WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            if ($again) return $again;
            $pair = sodium_crypto_sign_keypair();
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $id = apr_insert('platform_keys', ['kid' => gmdate('Y-m', apr_time()) . '-' . bin2hex(random_bytes(3)),
                'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'private_key' => $nonce . sodium_crypto_secretbox(sodium_crypto_sign_secretkey($pair), $nonce, apr_secret()), 'created_at' => apr_now()]);
            return apr_row('SELECT * FROM platform_keys WHERE id = ?', [$id]);
        });
    }
    $blob = (string)$row['private_key'];
    $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    $secret = sodium_crypto_secretbox_open(substr($blob, $n), substr($blob, 0, $n), apr_secret());
    if ($secret === false) throw new AprError('storage');
    return ['kid' => $row['kid'], 'public' => $row['public_key'], 'secret' => $secret];
}

/** הפעולה הציבורית platform_key: המפתחות הציבוריים בלבד. */
function apr_pkey_public(): array
{
    apr_pkey();
    return ['client' => APR_CLIENT_ID, 'keys' => array_map(fn($r) => ['kid' => $r['kid'], 'alg' => $r['alg'], 'key' => $r['public_key']],
        apr_rows("SELECT kid, alg, public_key FROM platform_keys WHERE status = 'active' ORDER BY id"))];
}

/**
 * בקשה חתומה שרת-לשרת למעטפת (platform/docs/embed.md §2): provision.php, action = "provision".
 * מחזיר ['status' => invited|granted|blocked, 'sub' => ?string], או null — המעטפת לא ענתה או ענתה משהו לא מובן.
 * נכשלים סגור: null אינו "החשבון נפתח".
 */
function apr_provision(string $email, string $name): ?array
{
    try {
        $key = apr_pkey();
        $body = json_encode(['email' => $email, 'name' => $name, 'grant' => true, 'next' => apr_app_path()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $sig = sodium_crypto_sign_detached("provision\n$time\n$nonce\n" . hash('sha256', $body), $key['secret']);
        $res = apr_http_post(apr_platform_base() . '/platform/provision.php', $body, ['X-Embed-Client: ' . APR_CLIENT_ID, "X-Embed-Time: $time",
            "X-Embed-Nonce: $nonce", 'X-Embed-Signature: ' . $key['kid'] . '.' . base64_encode($sig)]);
    } catch (Throwable) {
        return null;
    }
    $out = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
    $status = is_array($out) ? ($out['status'] ?? null) : null;
    if ($status === 'blocked') return ['status' => 'blocked', 'sub' => null];
    $sub = is_array($out) && is_scalar($out['sub'] ?? null) ? (string)$out['sub'] : '';
    if (!in_array($status, ['invited', 'granted'], true) || !preg_match('/^[0-9]{1,12}$/', $sub)) return null;
    return ['status' => $status, 'sub' => $sub];
}

/** המפתח הציבורי של המעטפת לזהות חתומה (sso.php?action=key) — בינארי, או null. מטמון של 24 שעות. */
function apr_platform_sso_key(bool $fresh = false): ?string
{
    $cached = json_decode((string)apr_meta('platform_sso_key', ''), true);
    if (!$fresh && is_array($cached) && (int)($cached['until'] ?? 0) > apr_time()) return base64_decode((string)$cached['key']) ?: null;
    $res = apr_http_get(apr_platform_url('action=key'));
    $out = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
    $key = is_array($out) && ($out['alg'] ?? '') === 'Ed25519' && is_string($out['key'] ?? null) ? base64_decode(strtr($out['key'], '-_', '+/'), true) : false;
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return null;
    apr_meta_set('platform_sso_key', json_encode(['until' => apr_time() + 86400, 'key' => base64_encode($key)]));
    return $key;
}
