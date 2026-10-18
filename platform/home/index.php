<?php
/**
 * הבית של אפליקציית הפלטפורמה המותקנת (start_url ו-scope ב-manifest.webmanifest).
 *
 * למה תיקייה משלה: הטווח (scope) של אפליקציה מותקנת הוא תיקייה, וכל מה שבתוכה נפתח בתוך החלון שלה.
 * כשהטווח היה השורש — כל הכלים היו בתוכו, כלי שנפתח "תפס" את חלון הפלטפורמה, ופתיחה חוזרת של
 * הכלים הדיגיטליים החזירה את הכלי האחרון. כאן הטווח הוא רק התיקייה הזו: כלי נפתח מחוץ לה (בדפדפן
 * או באפליקציה המותקנת שלו), והפלטפורמה תמיד נפתחת על רשימת האפליקציות.
 *
 * אותו דף כמו index.html שבשורש — לא עותק: נקרא משם, עם <base> כדי שהנתיבים היחסיים בו יעבדו.
 */

declare(strict_types=1);

$html = @file_get_contents(dirname(__DIR__, 2) . '/index.html');
if ($html === false) {
    http_response_code(500);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo preg_replace('/<head>/i', '<head><base href="../../">', $html, 1);
