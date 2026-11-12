<?php
/**
 * T1 — "⬇️ הורד את התוסף (zip)": תוסף הוורדפרס (wordpress-plugin/kol-maroko/) כ-ZIP שאפשר להעלות כמו שהוא בוורדפרס
 * (תוספים ← הוספת תוסף ← העלאה). בעל האתר בלבד (ACTION_ROLES: plugin_zip).
 *
 * רק קבצים רגילים מתוך התיקייה עצמה: קישור סימבולי, קובץ מיוחד, קובץ נסתר (.htaccess, .DS_Store) או נתיב שה-realpath
 * שלו יוצא מהתיקייה — לא נכנסים. בתוך ה-ZIP הכול תחת kol-maroko/ (מה שוורדפרס מצפה לו). הקבצים עצמם לא משתנים.
 * נקרא רק kol-maroko/wordpress-plugin/kol-maroko — שום קובץ מחוץ ל-kol-maroko/.
 */

declare(strict_types=1);

const PLUGIN_ZIP_DIR = __DIR__ . '/../wordpress-plugin/kol-maroko';
const PLUGIN_ZIP_TOP = 'kol-maroko';

/** הגרסה מכותרת התוסף (" * Version: 2.1.0"), או '' כשאין. */
function plugin_zip_version(string $dir = PLUGIN_ZIP_DIR): string
{
    $head = (string)@file_get_contents($dir . '/kol-maroko.php', false, null, 0, 4096);
    return preg_match('/^[ \t\/*#@]*Version:\s*([0-9A-Za-z.\-+]{1,32})\s*$/mi', $head, $m) ? $m[1] : '';
}

/**
 * הרשימה: [נתיב בתוך ה-ZIP => נתיב בדיסק], ממוינת. זורק 503 כשהתיקייה אינה בשרת (היא מוחרגת מהפריסה עד שהבקרה
 * מסירה את ההחרגה ב-deploy.yml), ו-500 כשאין בה kol-maroko.php.
 */
function plugin_zip_files(string $dir = PLUGIN_ZIP_DIR): array
{
    $root = realpath($dir);
    if ($root === false || !is_dir($root)) throw new ApiError('קובצי התוסף אינם בשרת הזה (התיקייה wordpress-plugin לא נפרסה). פנו למפתח', 503);
    if (!is_file($root . '/kol-maroko.php') || is_link($root . '/kol-maroko.php')) throw new ApiError('התוסף בשרת אינו שלם (חסר kol-maroko.php). פנו למפתח', 500);
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $rel = substr($f->getPathname(), strlen($root) + 1);
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || preg_match('#(^|/)\.#', $rel)) continue;                      // נסתר — לא חלק מהתוסף
        if ($f->isLink() || !$f->isFile()) continue;                                      // קישור / מיוחד — לעולם לא
        for ($p = dirname($f->getPathname()); strlen($p) > strlen($root); $p = dirname($p)) if (is_link($p)) continue 2;   // תיקייה שהיא קישור
        $real = realpath($f->getPathname());
        if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) continue;   // יוצא מהתיקייה
        $out[PLUGIN_ZIP_TOP . '/' . $rel] = $real;
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** בונה את ה-ZIP לקובץ $to. מחזיר [מספר הקבצים, הגרסה]. */
function plugin_zip_build(string $to, string $dir = PLUGIN_ZIP_DIR): array
{
    if (!class_exists('ZipArchive')) throw new ApiError('ZIP אינו זמין בשרת הזה (חסרה ההרחבה zip של PHP). פנו למפתח', 501);
    $files = plugin_zip_files($dir);
    $z = new ZipArchive();
    if ($z->open($to, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new ApiError('יצירת ה-ZIP נכשלה', 500);
    $z->addEmptyDir(PLUGIN_ZIP_TOP);
    foreach ($files as $name => $src) $z->addFile($src, $name);
    if (!$z->close()) throw new ApiError('יצירת ה-ZIP נכשלה', 500);
    return [count($files), plugin_zip_version($dir)];
}

/** api.php?action=plugin_zip — מוגש כקובץ מצורף בלבד. */
function plugin_zip(array $user): never
{
    $tmp = tempnam(sys_get_temp_dir(), 'kolplug');
    try {
        [$n, $ver] = plugin_zip_build($tmp);
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
    audit_as($user, 'plugin_zip', "$n קבצים" . ($ver !== '' ? " · גרסה $ver" : ''));
    $name = 'kol-maroko' . ($ver !== '' ? '-' . $ver : '') . '.zip';
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Kol-Plugin-Version: ' . $ver);
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
