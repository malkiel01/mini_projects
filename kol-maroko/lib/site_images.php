<?php
/**
 * R2 — "תמונות באתר" בבורר התמונות של העורך: התמונות של העמוד שעדיין כתובת באתר (library_site_images — היום תמונת הרקע
 * של המילון) מוצגות בבורר לצד קבצי הפרויקט והספרייה. בחירה ← השרת מוריד עותק אל הספרייה של הכלי, והעורך מקשר אותו
 * לפרויקט תחת media/ (pf_link, כמו כל קובץ מהספרייה).
 *
 * ⚠️ הגבולות (SSRF): רק כתובת שמופיעה ברשימת "באתר" של העמוד עצמו (לא כתובת שהדפדפן ממציא), ורק דרך מסלול ההורדה של
 *    ההקלטות — rec_http (https, הדומיין של האתר, תחת /wp-content/uploads/, IP ציבורי ננעל, בלי הפניה החוצה, בלי עוגיות).
 *    תמונה בלבד (לפי התוכן, file_store — כולל העיבוד מחדש של התמונה), עד FILE_MAX כמו העלאה, ובתוך תקרת הדיסק.
 *    כתובת שכבר יש לה עותק בכלי (files.origin) — לא מורידים שוב.
 */

declare(strict_types=1);

require_once __DIR__ . '/library.php';
require_once __DIR__ . '/recordings.php';

/** העותק בכלי של כתובת באתר (אם יש): השורה ב-files, או null. */
function site_image_copy(string $url): ?array
{
    $st = db()->prepare("SELECT * FROM files WHERE origin = ? AND kind = 'image' AND deleted_at IS NULL ORDER BY at DESC LIMIT 1");
    $st->execute([$url]);
    return $st->fetch() ?: null;
}

/** לבורר: התמונות "באתר", וכל אחת — העותק שכבר בכלי (אם יש). */
function site_images_pick(): array
{
    return ['images' => array_map(function (array $im): array {
        $c = site_image_copy($im['url']);
        return ['url' => $im['url'], 'label' => $im['label']] + ($c ? ['file' => file_public($c)] : []);
    }, library_site_images())];
}

/** בחירה בבורר: מוריד את התמונה מהאתר אל הספרייה של הכלי (או מחזיר את העותק הקיים). מחזיר { file, reused }. */
function site_image_take(array $user, array $b): array
{
    $url = is_string($b['url'] ?? null) ? trim($b['url']) : '';
    $why = rec_url_check($url);
    if ($why !== '') throw new ApiError('הכתובת אינה מותרת: ' . $why);
    if (!in_array($url, array_column(library_site_images(), 'url'), true)) throw new ApiError('הכתובת אינה מהתמונות של העמוד באתר', 404);
    if ($c = site_image_copy($url)) return ['file' => file_public($c), 'reused' => true];
    if (disk_used() >= disk_cap()) throw new ApiError('אין מקום בתקרת הדיסק', 413);

    $dir = files_dir() . '/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new ApiError('אין גישה לתיקיית הקבצים', 500);
    $tmp = $dir . '/img-' . bin2hex(random_bytes(8));
    $x = rec_http(['img' => ['method' => 'GET', 'url' => $url, 'save' => $tmp, 'max' => FILE_MAX]], 1)['img'];
    $code = $x['error'];
    if ($code === '' && rec_is_block($x)) $code = 'blocked';
    elseif ($code === '' && in_array($x['status'], [404, 410], true)) $code = 'missing';
    elseif ($code === '' && ($x['status'] < 200 || $x['status'] >= 300)) $code = 'http';
    if ($code !== '') {
        @unlink($tmp);
        devlog_write('warn', 'media', 'site_image_fail', ['message' => 'הבאת תמונה מהאתר נכשלה — ' . (REC_REASONS[$code] ?? $code), 'user' => $user,
            'data' => ['url' => $url, 'code' => $code, 'status' => $x['status'], 'note' => $x['note']]]);
        throw new ApiError('לא הצלחתי להביא את התמונה מהאתר: ' . (REC_REASONS[$code] ?? $code) . ($code === 'http' ? " (HTTP {$x['status']})" : ''), 502);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!isset(FILE_TYPES[$mime]) || FILE_TYPES[$mime][0] !== 'image') {
        @unlink($tmp);
        throw new ApiError($mime === 'text/html' ? 'מהאתר הגיע דף אינטרנט במקום תמונה' : 'הקובץ באתר אינו תמונה', 415);
    }
    if (disk_used() + (int)filesize($tmp) > disk_cap()) { @unlink($tmp); throw new ApiError('אין מקום בתקרת הדיסק', 413); }
    $base = rawurldecode(basename((string)parse_url($x['url'], PHP_URL_PATH)));
    $name = str_replace(['/', '\\', "\0"], '_', mb_substr($base !== '' ? $base : 'site-image', 0, 150));
    $fid = file_store($tmp, $name, $user, $mime);                 // מוחק את הזמני בכל כשל
    db()->prepare('UPDATE files SET origin = ? WHERE id = ?')->execute([$url, $fid]);
    audit_as($user, 'file_add', $name . ' ← ' . $url);
    return ['file' => file_public(file_row($fid)), 'reused' => false];
}
