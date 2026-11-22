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
    return sf_origin_of($server ?? $_SERVER);
}

/**
 * מ-SERVER_NAME; כשאין (CLI, cron בלי שם אתר) — הכתובת שנשמרה בבקשה האחרונה של מנהל (sf_note_origin), ורק בלית ברירה localhost.
 * (10: קישור "https://localhost/…" במייל שנשלח מ-cron.)
 */
function sf_origin_of(array $server): string
{
    $host = strtolower((string)($server['SERVER_NAME'] ?? ''));
    if (preg_match('/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $host) && $host !== 'localhost' && PHP_SAPI !== 'cli') return 'https://' . $host;
    return sf_known_url()['origin'] ?? 'https://' . (preg_match('/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $host) ? $host : 'localhost');
}

/** הכתובת הציבורית שנשמרה (meta public_url): ['origin', 'base'] או []. */
function sf_known_url(): array
{
    try {
        $u = json_decode((string)sf_meta('public_url', ''), true);
    } catch (Throwable) {
        return [];
    }
    return is_array($u) && is_string($u['origin'] ?? null) && preg_match('~^https://[a-z0-9.\-]+\z~', $u['origin'])
        && is_string($u['base'] ?? null) && preg_match('~^/[A-Za-z0-9._/\-]*/\z~', $u['base']) ? $u : [];
}

/**
 * נקרא בכל בקשה של מנהל מחובר (הגיע דרך השער — המארח אמיתי): שומר את המקור והתיקייה, רק כשהשתנו. לא מ-CLI.
 */
function sf_note_origin(?array $server = null): void
{
    if (PHP_SAPI === 'cli' && !defined('SF_ORIGIN')) return;
    $u = ['origin' => sf_origin($server), 'base' => sf_base_path($server)];
    if ($u['origin'] === 'https://localhost' || sf_known_url() === $u) return;
    sf_meta_set('public_url', json_encode($u, JSON_UNESCAPED_SLASHES));
}

/** הכתובת המלאה של קובץ בתוך ספורנו. מ-CLI (כלים) — המקור והתיקייה שנשמרו, אם יש. */
function sf_self_url(string $path = ''): string
{
    $known = PHP_SAPI === 'cli' && !defined('SF_ORIGIN') ? sf_known_url() : [];
    return ($known['origin'] ?? sf_origin()) . ($known['base'] ?? sf_base_path()) . ltrim($path, '/');
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
