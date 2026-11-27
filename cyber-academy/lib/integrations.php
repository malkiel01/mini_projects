<?php
/**
 * אינטגרציות מנהל — שמירת אסימון קליטת הדיווחים בצד השרת בלבד.
 *
 * - רק מנהל (progress.managers) עם בקשה ממקור זהה (Origin = Host). בלי זהות — אין גישה.
 * - האסימון נכתב לקובץ ingest.token_file בלבד: נתיב מוחלט, מחוץ לעץ האתר, התיקייה קיימת ואינה כתיבה לכולם.
 *   הכתיבה אטומית (קובץ זמני + rename), הרשאות 0600. אין תיקיות שנוצרות אוטומטית.
 * - האסימון לעולם לא חוזר בתשובה ולא נרשם בלוג. הסטטוס בודק רק קיום והרשאות, בלי לקרוא את התוכן.
 * - שום מקור נתונים לא מחובר כאן: ה-sync מחזיר "אין מקור מחובר" כל עוד ingest.sources ריק.
 * אפיון: docs/spec/integrations.md
 */

declare(strict_types=1);

require_once __DIR__ . '/progress-api.php';

const INTEGRATION_TOKEN_MIN = 20;
const INTEGRATION_TOKEN_MAX = 512;

/** זהות + הרשאת כתיבה (מנהל בלבד). null אם לא מנהל. */
function integration_manager(array $config, array $server, string $sapi): ?array {
    $actor = academy_progress_actor($config, $server, $sapi);
    if ($actor === null || !academy_can('progress.write', $actor['role'])) return null;
    return $actor;
}

/** נתיב קובץ האסימון — מוחלט, מחוץ לעץ האתר, בתיקייה קיימת שאינה כתיבה לכולם. null אם לא תקין. */
function integration_token_path(array $config): ?string {
    $file = trim((string) (($config['ingest'] ?? [])['token_file'] ?? ''));
    if ($file === '' || !str_starts_with($file, '/')) return null;
    if (array_key_exists('token', (array) ($config['ingest'] ?? []))) return null;   // אסימון בקונפיג אסור
    if (academy_path_in_webroot($file)) return null;
    $dir = dirname($file);
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || academy_path_in_webroot($real)) return null;
    if ((fileperms($real) & 0o022) !== 0) return null;   // כתיבה לקבוצה או לכולם
    return $file;
}

/** שמירת האסימון. מחזיר [status, body] — בלי האסימון בתשובה. */
function integration_save_token(array $config, mixed $body, array $server, string $sapi): array {
    if (!academy_progress_same_origin($server, $sapi)) return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'בקשה ממקור אחר נדחתה']];
    if (integration_manager($config, $server, $sapi) === null) return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'למנהל בלבד']];
    $token = is_array($body) ? ($body['token'] ?? null) : null;
    if (!is_string($token)) return [422, ['ok' => false, 'code' => 'bad_request', 'error' => 'נדרש שדה token']];
    $token = trim($token);
    $len = strlen($token);
    if ($len < INTEGRATION_TOKEN_MIN || $len > INTEGRATION_TOKEN_MAX || preg_match('/\s/', $token)) {
        return [422, ['ok' => false, 'code' => 'token_invalid', 'error' => 'האסימון אינו בפורמט תקין']];
    }
    $path = integration_token_path($config);
    if ($path === null) return [503, ['ok' => false, 'code' => 'not_configured', 'error' => 'נתיב אחסון האסימון לא הוגדר בשרת']];

    $tmp = dirname($path) . '/.ingest-token.' . bin2hex(random_bytes(8)) . '.tmp';
    $ok = @file_put_contents($tmp, $token, LOCK_EX) !== false && @chmod($tmp, 0o600) && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
        error_log('cyber-academy integrations: save_token failed');   // בלי נתיב ובלי ערך
        return [500, ['ok' => false, 'code' => 'internal', 'error' => 'שמירת האסימון נכשלה']];
    }
    error_log('cyber-academy integrations: ingest token saved');
    return [200, ['ok' => true, 'configured' => true]];
}

/** סטטוס בלי לקרוא את התוכן: קיום, הרשאות, ונתיב מוגדר. */
function integration_token_status(array $config, array $server, string $sapi): array {
    if (integration_manager($config, $server, $sapi) === null) return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'למנהל בלבד']];
    $path = integration_token_path($config);
    $present = $path !== null && is_file($path) && (fileperms($path) & 0o077) === 0;
    return [200, ['ok' => true, 'pathConfigured' => $path !== null, 'tokenStored' => $present]];
}

/** סנכרון: רק מקורות שהוגדרו במפורש (ingest.sources). ריק = אין מקור מחובר, ואין שום פעולה. */
function integration_sync(array $config, array $server, string $sapi): array {
    if (!academy_progress_same_origin($server, $sapi)) return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'בקשה ממקור אחר נדחתה']];
    if (integration_manager($config, $server, $sapi) === null) return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'למנהל בלבד']];
    $sources = (array) (($config['ingest'] ?? [])['sources'] ?? []);
    if ($sources === []) return [200, ['ok' => true, 'status' => 'no_source_connected', 'results' => []]];
    return [501, ['ok' => false, 'code' => 'not_implemented', 'error' => 'מקורות נתונים עדיין לא נתמכים']];
}
