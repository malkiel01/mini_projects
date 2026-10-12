<?php
/**
 * שליחת אימייל — איפוס סיסמה, אישור חשבון, הודעה למפתח על הרשמה.
 *
 * עטיפה דקה סביב mail() של PHP (כמו recipes-app/lib/mail.php). mail() מחזירה
 * true כשהשרת קיבל את ההודעה — לא שהיא הגיעה ליעד. לכן כל מקום ששולח גם מציג
 * את הקישור למפתח (להעתקה / וואטסאפ), וכל שליחה נרשמת ביומן.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';

function pm_mail_available(): bool
{
    return function_exists('mail');
}

function pm_mail_from(): string
{
    $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]; // בלי פורט
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    return 'no-reply@' . preg_replace('/^www\./', '', $host);
}

/** כתובת האפליקציה (התיקייה של api.php) — לקישורים באימייל. */
function pm_app_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https://' : 'http://') . $host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

/**
 * $lang — שפת הנמען (lib/i18n.php): שם השולח בשפה שלו. הנושא והגוף — הקורא כבר
 * מתרגם (pm_t עם אותה שפה). בלי $lang — עברית, כמו קודם. המייל טקסט פשוט, בלי HTML.
 */
function pm_send_mail(string $to, string $subject, string $body, ?string $lang = null): bool
{
    if (!pm_mail_available() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = pm_mail_from();
    $fromName = pm_t('ניהול פרויקטים', [], $lang ?? 'he');
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: project-manager',
    ]);
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // -f: כתובת המעטפה על הדומיין שלנו (SPF); אם השרת חוסם -f — בלעדיו.
    $sent = @mail($to, $subj, $body, $headers, '-f' . $from);
    if (!$sent) $sent = @mail($to, $subj, $body, $headers);
    return $sent;
}
