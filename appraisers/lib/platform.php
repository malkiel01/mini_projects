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

/** הכתובת של sso.php במעטפת — נבנית מ-SERVER_NAME (לא מ-Host של הבקשה) ומהמיקום של האפליקציה. */
function apr_platform_url(string $query): string
{
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $base = rtrim(dirname(rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/appraisers/api.php')), '/')), '/');
    return 'https://' . $host . $base . '/platform/sso.php?' . $query;
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
