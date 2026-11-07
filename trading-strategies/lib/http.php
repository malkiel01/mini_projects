<?php
/**
 * פנייה החוצה לספקי הנתונים — כל ה-HTTP של האפליקציה עובר כאן.
 *
 * מפתחות עוברים רק בכותרות, לעולם לא בכתובת: כתובת נכנסת ללוגים של שרתים, לשגיאות ולהיסטוריה.
 *
 * בבדיקות (רק כש-TS_DEV מוגדר — tests/dev-prepend.php, שלא רץ בשרת) אפשר לענות מקבצים במקום מהרשת:
 * TS_HTTP_FIXTURES = תיקייה עם index.json [{match: regex על הכתובת, file, status}], ו-TS_HTTP_LOG = קובץ שאליו
 * נרשמת כל פנייה (כדי לספור כמה פעמים פנינו לספק).
 */

declare(strict_types=1);

final class TsHttpError extends RuntimeException {}

/**
 * האם לעקוב אחרי הפניה (3xx). רק בבקשה בלי כותרות משלנו: curl שולח כותרות מותאמות (Authorization, X-Finnhub-Token)
 * גם למארח שהפנו אליו — ספק שמפנה, או מתקפה על ההפניה, היה מקבל את המפתח. בקשה עם מפתח — הפניה חוזרת כשגיאה.
 */
function ts_http_follow(array $headers): bool
{
    return $headers === [];
}

/** GET. מחזיר [status, body]. זורק TsHttpError על כשל רשת. */
function ts_http_get(string $url, array $headers = [], int $timeout = 15): array
{
    if (defined('TS_DEV') && ($dir = getenv('TS_HTTP_FIXTURES'))) return ts_http_fixture($dir, $url, $headers);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => ts_http_follow($headers),
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: trading-strategies/1.0'], $headers),
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new TsHttpError('הספק לא ענה' . ($err !== '' ? ' (' . preg_replace('/https?:\S+/', '', $err) . ')' : ''));
    return [$status, (string)$body];
}

function ts_http_fixture(string $dir, string $url, array $headers): array
{
    if ($log = getenv('TS_HTTP_LOG')) {
        file_put_contents($log, json_encode(['url' => $url, 'headers' => $headers]) . "\n", FILE_APPEND | LOCK_EX);
    }
    $index = json_decode((string)@file_get_contents($dir . '/index.json'), true) ?: [];
    foreach ($index as $rule) {
        if (preg_match($rule['match'], $url)) {
            // php — ספק מדומה שמייצר תשובה לפי הכתובת (נרות לטווח שביקשו). רק בבדיקות (TS_DEV).
            if (isset($rule['php'])) return (require $dir . '/' . $rule['php'])($url, $headers);
            $body = isset($rule['body']) ? (is_string($rule['body']) ? $rule['body'] : json_encode($rule['body']))
                : (string)@file_get_contents($dir . '/' . $rule['file']);
            // {{HEADER:name}} — ספק שמחזיר בשגיאה את מה שקיבל (כמו שספקים אמיתיים עושים לפעמים)
            $body = preg_replace_callback('/\{\{HEADER:([\w-]+)\}\}/', function ($m) use ($headers) {
                foreach ($headers as $h) {
                    [$k, $v] = array_map('trim', explode(':', $h, 2)) + [1 => ''];
                    if (strcasecmp($k, $m[1]) === 0) return $v;
                }
                return '';
            }, $body);
            return [(int)($rule['status'] ?? 200), $body];
        }
    }
    return [404, '{"error":"no fixture"}'];
}
