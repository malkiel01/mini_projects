<?php
/**
 * שליחת מייל. mail() של השרת — בלי ספק חיצוני, כדי שתוכן לא ייצא מהשרת.
 *
 * בבדיקות DT_MAIL_SINK מוגדר לקובץ, והמיילים נכתבים אליו במקום להישלח.
 */

declare(strict_types=1);

function app_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function send_mail(string $to, string $subject, string $body): bool
{
    if (defined('DT_MAIL_SINK')) {
        file_put_contents(DT_MAIL_SINK, json_encode(['to' => $to, 'subject' => $subject, 'body' => $body],
            JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return true;
    }
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('תבניות מסמכים') . "?= <no-reply@$host>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($body)), $headers);
}

function mail_verify(string $to, string $name, string $token): bool
{
    $link = app_base_url() . '#verify=' . $token;
    return send_mail($to, 'אימות כתובת המייל — תבניות מסמכים',
        "שלום $name,\n\nכדי להשלים את ההרשמה, לחצו על הקישור:\n$link\n\n" .
        "הקישור בתוקף 48 שעות. אחרי האימות, הבקשה תעבור לאישור.\n\n" .
        "אם לא נרשמתם — אפשר להתעלם מהמייל הזה.");
}

function mail_reset(string $to, string $name, string $token): bool
{
    $link = app_base_url() . '#reset=' . $token;
    return send_mail($to, 'איפוס סיסמה — תבניות מסמכים',
        "שלום $name,\n\nלקביעת סיסמה חדשה, לחצו על הקישור:\n$link\n\n" .
        "הקישור בתוקף שעה אחת ולשימוש אחד.\n\n" .
        "אם לא ביקשתם איפוס — אפשר להתעלם מהמייל, והסיסמה לא תשתנה.");
}
