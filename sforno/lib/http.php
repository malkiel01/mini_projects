<?php
/**
 * HTTP: בסיס הכתובת, כתובת עצמית, בקשות יוצאות קצרות והעוגייה (אפיון 01 §3, §5.3, 00 §3.3).
 *
 * **אין נתיב קבוע:** בסיס הכתובת הוא התיקייה של הסקריפט שרץ (api.php / cron.php) — כך ספורנו עובדת אותו דבר
 * בתוך הפלטפורמה ובסאב-דומיין משלה, בהעתקת התיקייה בלבד.
 */

declare(strict_types=1);

/** הנתיב של התיקייה באתר, עם / בסוף ("/x/sforno/" או "/"). */
function sf_base_path(?array $server = null): string
{
    $server ??= $_SERVER;
    if (defined('SF_BASE_PATH')) return SF_BASE_PATH;   // בדיקות בלבד
    $dir = str_replace('\\', '/', dirname((string)($server['SCRIPT_NAME'] ?? '/')));
    $dir = preg_match('~^[A-Za-z0-9._/\-]*$~', $dir) ? rtrim($dir, '/') : '';
    return $dir . '/';
}

/**
 * scheme + host ("https://example.com"). השם מ-SERVER_NAME (שם האתר שהוגדר בשרת), רק אם הוא שם מארח תקין;
 * בדיקות — SF_ORIGIN.
 */
function sf_origin(?array $server = null): string
{
    if (defined('SF_ORIGIN')) return SF_ORIGIN;
    $server ??= $_SERVER;
    $host = strtolower((string)($server['SERVER_NAME'] ?? ''));
    if (!preg_match('/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $host)) $host = 'localhost';
    return 'https://' . $host;
}

/** הכתובת המלאה של קובץ בתוך ספורנו. */
function sf_self_url(string $path = ''): string
{
    return sf_origin() . sf_base_path() . ltrim($path, '/');
}

/** בקשת GET קצרה: [status, body, ms] או null בכישלון. בבדיקות — $GLOBALS['sf_http'] מחליף אותה (אין רשת בבדיקות). */
function sf_http_get(string $url, int $timeout = 5): ?array
{
    if (isset($GLOBALS['sf_http'])) return ($GLOBALS['sf_http'])($url);
    if (!function_exists('curl_init')) return null;
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_USERAGENT => 'sforno/health']);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $body === false ? null : [$status, (string)$body, (int)round((microtime(true) - $t0) * 1000)];
}

const SF_COOKIE = 'sf_staff';

/** כותב את עוגיית המנהל. ריק = מחיקה. בבדיקות (CLI) — נרשמת ב-$GLOBALS['sf_cookie_out'] במקום כותרת. */
function sf_set_cookie(string $token, int $ttl): void
{
    $GLOBALS['sf_cookie_out'] = $token;
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    setcookie(SF_COOKIE, $token, [
        'expires' => $token === '' ? 1 : time() + $ttl,
        'path' => sf_base_path(),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
