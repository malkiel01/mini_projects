<?php
/**
 * טוען המחלקות של הספריות שב-vendor/ — במקום ה-autoload של composer, שנשען על קבצים שקוצצו. נטען רק מ-lib/export_pdf.php, ורק
 * כשמפיקים PDF. מה מצורף, באילו גרסאות ומה קוצץ: vendor/README.md.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    static $map = [
        'Mpdf\\PsrLogAwareTrait\\' => __DIR__ . '/mpdf/psr-log-aware-trait/src/',
        'Mpdf\\' => __DIR__ . '/mpdf/mpdf/src/',
        'setasign\\Fpdi\\' => __DIR__ . '/setasign/fpdi/src/',
        'Psr\\Log\\' => __DIR__ . '/psr/log/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        // שם מחלקה הוא מזהה של PHP: אותיות, ספרות, קו תחתון ומפריד מרחב שמות — שום דבר ממנו אינו יוצא מהתיקייה
        $rel = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z0-9_\\\\]+\z/', $rel) !== 1) return;
        $file = $dir . str_replace('\\', '/', $rel) . '.php';
        if (is_file($file)) require $file;
        return;
    }
});
