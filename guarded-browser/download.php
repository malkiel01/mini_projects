<?php
/**
 * הורדת אפליקציית הילד — מהשרת שלנו, בקישור אחד ישיר.
 *
 * למה לא ישר מ-GitHub: שם הקובץ עובר הפניה לכתובת חתומה בשרת אחר (release-assets.githubusercontent.com),
 * ובטלפון ההורדה נתקעה ב"ההורדה מתבצעת…" — בעיקר כשהקישור נפתח בחלון דפדפן קטן מתוך אפליקציה.
 * כאן: השרת מוריד את ה-APK מ-GitHub פעם אחת לכל גרסה (לפי latest.json), שומר ב-data/apk/,
 * ומגיש אותו ישירות עם הכותרות של APK. אם משהו נכשל — הפניה ל-GitHub, כמו קודם.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/appversion.php';

const APK_GITHUB = 'https://github.com/malkiel01/mini_projects/releases/download/guarded-browser-apk/migdalor.apk';

/** הקובץ השמור לגרסה הזו, או '' אם לא הצלחנו להשיג אותו. */
function apkFile(int $code): string {
    $dir = dataDir() . '/apk';
    $file = "$dir/migdalor-$code.apk";
    if (is_file($file)) return $file;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return '';

    // מנעול: שני הורים שלוחצים יחד לא יורידו פעמיים.
    $lock = fopen("$dir/.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return '';
    try {
        if (is_file($file)) return $file;
        $tmp = "$file.part";
        $fh = fopen($tmp, 'wb');
        $ch = curl_init(APK_GITHUB);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                                CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FAILONERROR => true]);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fh);
        // APK הוא קובץ ZIP: מתחיל ב-PK, ולא קטן ממגה. כל דבר אחר — דף שגיאה, לא אפליקציה.
        if (!$ok || filesize($tmp) < 1_000_000 || file_get_contents($tmp, false, null, 0, 2) !== 'PK') {
            @unlink($tmp);
            return '';
        }
        rename($tmp, $file);
        // גרסאות ישנות — נמחקות; נשמרת רק האחרונה.
        foreach (glob("$dir/migdalor-*.apk") ?: [] as $old) if ($old !== $file) @unlink($old);
        return $file;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    $latest = latestApp();
    $file = $latest ? apkFile($latest['code']) : '';
} catch (Throwable $e) {
    error_log('migdalor download: ' . $e->getMessage());
    $file = '';
}

if ($file === '') {
    header('Location: ' . APK_GITHUB, true, 302);
    exit;
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="migdalor-' . $latest['name'] . '.apk"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($file);
