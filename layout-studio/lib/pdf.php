<?php
// פלט PDF: רשימת הציור שהדפדפן חישב (assets/js/pdf.js) → PDF, במנוע המשותף pdf-core (lib/pdf-core — עותק מסונכרן,
// לא עורכים כאן). השרת רק מתרגם מפתחות לקבצים: גופנים מ-assets/fonts (רשימה סגורה), ותמונות של המשתמש מ-data/files.

require_once __DIR__ . '/media.php';
require_once __DIR__ . '/pdf-core/draw.php';

const LS_PDF_MAX = 64 * 1024 * 1024;   // הרשימה אחרי פריסת הדחיסה — ספר של כמה מאות עמודים נכנס בהרבה

/** מפתח → bytes, או null (לא מוכר / לא קיים). */
function ls_pdf_resolve(int $owner, string $key): ?string
{
    if (preg_match('#^fonts/((?:static/)?[A-Za-z]+(?:-[0-9]{3})?\.ttf)$#', $key, $m)) {
        $p = __DIR__ . '/../assets/fonts/' . $m[1];
        return is_file($p) ? file_get_contents($p) : null;
    }
    if (str_starts_with($key, 'media/')) {
        $p = ls_media_path($owner, substr($key, 6));
        return $p ? file_get_contents($p) : null;
    }
    return null;
}

/**
 * גוף הבקשה (JSON דחוס ב-gzip) → PDF.
 * @throws InvalidArgumentException bad_list / too_big, או הודעה של pdf-core (מה חסר ואיפה)
 */
function ls_pdf(int $owner, string $body): string
{
    $json = @gzdecode($body, LS_PDF_MAX + 1);
    if ($json === false) throw new InvalidArgumentException('bad_list');
    if (strlen($json) > LS_PDF_MAX) throw new InvalidArgumentException('too_big');
    $list = json_decode($json, true);
    if (!is_array($list)) throw new InvalidArgumentException('bad_list');
    return pdf_draw($list, fn(string $key) => ls_pdf_resolve($owner, $key));
}
