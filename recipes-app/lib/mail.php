<?php
/**
 * שליחת דוא"ל — אימות חשבון ואיפוס סיסמה.
 *
 * זהו העטיפה היחידה סביב mail() של PHP, ויש לה תפקיד אחד נוסף: **לדעת
 * אם היא בכלל עובדת.** האפיון (פרק 11, עובדה 3) מציין שאין לי דרך לבדוק
 * מכאן אם mail() פועל בשרת, ולכן הקוד לא מניח שכן.
 *
 * מה שקורה כשהשליחה נכשלת מוכרע כאן ולא נדחה: החשבון נוצר ונשאר **לא
 * מאומת**, והמשתמש מקבל הודעה שתאמר לו לפנות למנהל. מה שאסור היה לעשות
 * הוא להציג לו את קישור האימות על המסך — זה היה הופך את האימות לחסר
 * משמעות, כי כל נרשם היה מאמת את עצמו.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function mailFrom(): string {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    return 'no-reply@' . $host;
}

/** כתובת בסיס לקישורים שבדוא"ל. נגזרת מהבקשה ולא מוגדרת פעמיים. */
function appBaseUrl(): string {
    $https  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/recipes-app/api.php');
    return ($https ? 'https://' : 'http://') . $host . rtrim(dirname($script), '/');
}

/**
 * שולח דוא"ל. מחזיר true אם ה-MTA קיבל אותו — **לא** שהוא הגיע ליעד.
 * mail() אינה יכולה לדעת את זה, ולכן גם אנחנו לא מתיימרים.
 */
function sendMail(string $to, string $subject, string $body): bool {
    if (!function_exists('mail')) return false;

    $from = mailFrom();
    $headers = implode("\r\n", [
        // שם תצוגה מקודד + כתובת על הדומיין שלנו. ספקים כמו Gmail מסננים
        // הודעה שה-From שלה לא מיושר עם הדומיין ששלח אותה בפועל.
        'From: =?UTF-8?B?' . base64_encode('אפליקציית מתכונים') . '?= <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: recipes-app',
    ]);
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // הפרמטר החמישי קובע את כתובת המעטפה (Return-Path). בלעדיו sendmail
    // שולח מ-"nobody@server" — וזו אי־התאמה ש-SPF תופס, וגם הכתובת שאליה
    // חוזרות הודעות שגיאה. כך ה-From והמעטפה זהים, ושניהם על הדומיין.
    $sent = @mail($to, $encoded, $body, $headers, '-f' . $from);
    if (!$sent) $sent = @mail($to, $encoded, $body, $headers);   // שרת שחוסם -f
    return $sent;
}

function sendVerifyEmail(string $to, string $token): bool {
    $link = appBaseUrl() . '/verify.php?token=' . urlencode($token);
    return sendMail($to, 'אימות חשבון — אפליקציית מתכונים',
        "שלום,\n\nכדי להשלים את ההרשמה יש לאשר את הכתובת הזאת:\n\n$link\n\n" .
        "הקישור תקף 24 שעות. אם לא נרשמת — אין צורך לעשות דבר.\n");
}

function sendResetEmail(string $to, string $token): bool {
    $link = appBaseUrl() . '/reset.php?token=' . urlencode($token);
    return sendMail($to, 'איפוס סיסמה — אפליקציית מתכונים',
        "שלום,\n\nלאיפוס הסיסמה:\n\n$link\n\n" .
        "הקישור תקף שעה אחת ולשימוש חד־פעמי. אם לא ביקשת איפוס — " .
        "אין צורך לעשות דבר, והסיסמה הקיימת נשארת בתוקף.\n");
}
