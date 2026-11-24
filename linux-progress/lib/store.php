<?php
/**
 * ללימוד סייבר התקפי — קריאה מהריפו של האקדמיה, בלי לכתוב אליו.
 *
 * הקובץ נקרא מ-malkiel01/offensive-cyber-academy בנתיב PROGRESS_PATH (תיקייה ייעודית; שום דבר אחר לא נוגעים בו).
 * הסוד (טוקן קריאה בלבד) נשמר בשרת מחוץ לאתר, ולעולם לא מגיע לדפדפן ולא נרשם בלוג.
 * אפליקציה עצמאית: הקוד כאן לא קורא שום קובץ מחוץ לתיקיית האפליקציה.
 */

declare(strict_types=1);

const LP_REPO = 'malkiel01/offensive-cyber-academy';
const LP_PATH = 'progress/linux-progress.json';
const LP_TOKEN_MIN = 20;
const LP_TOKEN_MAX = 512;
const LP_STATUSES = ['done', 'verified'];
const LP_MODULE_ID = '/^\d{2}\.\d{2}$/';

/** תיקיית האפליקציה: linux-progress/. השורש: mini_projects/. */
function lp_app_dir(): string {
    return dirname(__DIR__);
}

/**
 * נתיב הסוד: <home>/academy-secrets/linux-progress-token.
 * מתיקיית האפליקציה: linux-progress → mini_projects → public_html → <home>. מחוץ לאתר.
 */
function lp_secret_path(): string {
    return dirname(lp_app_dir(), 3) . '/academy-secrets/linux-progress-token';
}

function lp_in_webroot(string $path): bool {
    $root = dirname(lp_app_dir());
    if ($path === $root || str_starts_with($path, $root . '/')) return true;
    return (bool) preg_match('~/(public_html|www|htdocs)(/|$)~', $path);
}

/** גישה: רק מי שהשער אישר (granted). בלי זהות — אין גישה. */
function lp_allowed(array $server): bool {
    $u = json_decode((string) ($server['PLATFORM_USER'] ?? ''), true);
    return is_array($u) && ($u['granted'] ?? null) === true;
}

/** קורא את הסוד. null אם אין או אם לא בטוח. לעולם לא מחזיר ערך לחיצוני. */
function lp_read_token(?string $path = null): ?string {
    $path ??= lp_secret_path();
    $real = realpath($path);
    if ($real === false || lp_in_webroot($path) || lp_in_webroot($real) || !is_file($real)) return null;
    clearstatcache(true, $real);
    if ((fileperms($real) & 0o077) !== 0) return null;   // רק הבעלים
    $raw = @file_get_contents($real);
    if (!is_string($raw)) return null;
    $t = trim($raw);
    return (strlen($t) >= LP_TOKEN_MIN && !preg_match('/\s/', $t)) ? $t : null;
}

/** שמירת הסוד: אטומית, 0600, בתיקייה מחוץ לאתר בלבד (נוצרת ב-0700 אם היא לא קיימת). */
function lp_save_token(string $token, ?string $path = null): bool {
    $path ??= lp_secret_path();
    if (lp_in_webroot($path)) return false;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        // הורה חייב להיות קיים ומחוץ לאתר; רק התיקייה הסופית נוצרת
        if (!is_dir(dirname($dir)) || lp_in_webroot($dir)) return false;
        if (!@mkdir($dir, 0o700)) return false;
    }
    if ((fileperms($dir) & 0o022) !== 0) return false;
    $tmp = $dir . '/.lp-token.' . bin2hex(random_bytes(8)) . '.tmp';
    $ok = @file_put_contents($tmp, $token, LOCK_EX) !== false && @chmod($tmp, 0o600) && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
        error_log('linux-progress: save_token failed');   // בלי נתיב ובלי ערך
    }
    return $ok;
}

/** אימות צורה: מחזיר רשימת פריטים נקיה, או null אם הקובץ לא תקין. שדות נוספים נזרקים. */
function lp_validate(mixed $data): ?array {
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) return null;
    $out = [];
    foreach ($data['items'] as $it) {
        if (!is_array($it)) return null;
        $date = $it['date'] ?? null;
        $topic = $it['topic'] ?? null;
        $status = $it['status'] ?? null;
        $note = $it['note'] ?? '';
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) return null;
        if (!is_string($topic) || $topic === '' || mb_strlen($topic) > 200) return null;
        if (!is_string($status) || !in_array($status, LP_STATUSES, true)) return null;
        if (!is_string($note) || mb_strlen($note) > 500) return null;
        $row = ['date' => $date, 'topic' => $topic, 'status' => $status, 'note' => $note];
        // moduleId אופציונלי: מזהה מודול בתוכנית, למשל "03.02". צורה לא תקינה → הרשומה נפסלת.
        if (isset($it['moduleId'])) {
            if (!is_string($it['moduleId']) || !preg_match(LP_MODULE_ID, $it['moduleId'])) return null;
            $row['moduleId'] = $it['moduleId'];
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * קריאת הקובץ מהריפו. מחזיר [status, body] בלי הסוד.
 * status: ok | no_token | no_file | unauthorized | invalid | unavailable
 * $fetch — להזרקה בבדיקות: fn(string $url, string $token): array{0:int,1:string|false}
 */
function lp_fetch_progress(?string $token, ?callable $fetch = null): array {
    if ($token === null) return ['no_token', []];
    $url = 'https://api.github.com/repos/' . LP_REPO . '/contents/' . LP_PATH;
    [$code, $raw] = ($fetch ?? 'lp_http_get')($url, $token);
    if ($code === 404) return ['no_file', []];
    if ($code === 401 || $code === 403) return ['unauthorized', []];
    if ($code !== 200 || !is_string($raw)) return ['unavailable', []];
    $items = lp_validate(json_decode($raw, true));
    if ($items === null) return ['invalid', []];
    return ['ok', $items];
}

/** בקשת GET לגיטהאב עם הטוקן בכותרת בלבד. */
function lp_http_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github.raw+json',
            'User-Agent: linux-progress',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : false];
}
