<?php
/**
 * מייל (אפיון 02 §3): כל מייל שהמערכת שולחת עובר כאן ונרשם ב-mail_log — עם hash של הכתובת, לא הכתובת.
 * מפתח אידמפוטנטיות: אותו מייל לא יוצא פעמיים. מייל שנכשל אינו מוצג כנשלח.
 * בבדיקות: $GLOBALS['apr_mail'] = fn(string $to, string $subject, string $text): bool. ב-CLI בלי זה — לא נשלח דבר.
 * בעמודה error של שליחה שהצליחה רשומה הדרך שבה יצאה ("envelope -f" / "no envelope …") — לא שגיאה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platform.php';

function apr_mail_hash(string $email): string
{
    return hash_hmac('sha256', 'mail:' . mb_strtolower(trim($email)), apr_secret());
}

/** שולח ורושם. מחזיר 'sent' / 'failed'; מפתח שכבר נרשם — המצב שנרשם אז, בלי שליחה נוספת. */
function apr_mail_send(string $kind, string $to, string $subject, string $text, string $idem, ?int $office = null): string
{
    $prev = apr_val('SELECT status FROM mail_log WHERE idempotency_key = ?', [$idem]);
    if ($prev !== false) return (string)$prev;
    $error = null;
    try {
        $ok = apr_mail_transport($to, $subject, $text);
        // לשליחה שהצליחה: באיזו דרך יצאה (עם כתובת מעטפה או בלי) — כדי שיומן המיילים של המפתח יראה את זה
        $error = $ok ? ($GLOBALS['apr_mail_via'] ?? null) : 'transport returned false' . (isset($GLOBALS['apr_mail_via']) ? ' · ' . $GLOBALS['apr_mail_via'] : '');
    } catch (Throwable $e) {
        $ok = false;
        $error = get_class($e);                         // בלי הודעת החריגה — עלולה לכלול את הכתובת
    }
    try {
        apr_insert('mail_log', ['at' => apr_now(), 'kind' => $kind, 'to_hash' => apr_mail_hash($to), 'status' => $ok ? 'sent' : 'failed',
            'error' => $error, 'idempotency_key' => $idem, 'office_id' => $office]);
    } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), 'UNIQUE')) throw $e;
    }
    return $ok ? 'sent' : 'failed';
}

/**
 * השליחה עצמה. כתובת המעטפה (`-f`, ה-Return-Path) נקבעת במפורש: בלעדיה השרת שולח בשם משתמש המערכת, ומיילים כאלה
 * נדחים או נופלים לספאם. שרת שאוסר `-f` מחזיר false — ואז שולחים שוב בלי הפרמטר.
 * הכתובת נבנית **רק** מה-host של האפליקציה (app.json), ורק אם הוא שם מתחם תקין — שום קלט של משתמש לא מגיע לפרמטרים
 * של sendmail. באיזו דרך נשלח — ב-$GLOBALS['apr_mail_via'], ומשם ל-mail_log.
 * בבדיקות: $GLOBALS['apr_mail_fn'] מחליף את mail() (אותם חמישה פרמטרים).
 */
function apr_mail_transport(string $to, string $subject, string $text): bool
{
    unset($GLOBALS['apr_mail_via']);
    if (isset($GLOBALS['apr_mail'])) return (bool)($GLOBALS['apr_mail'])($to, $subject, $text);
    $send = $GLOBALS['apr_mail_fn'] ?? null;
    if ($send === null) {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || !function_exists('mail')) return false;
        $send = fn(string ...$a): bool => @mail(...$a);
    }
    $host = mb_strtolower((string)parse_url(apr_app_url(), PHP_URL_HOST));
    $safe = (bool)preg_match('/^(?=.{4,253}\z)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\z/', $host);
    $from = 'noreply@' . ($safe ? $host : 'localhost');
    $headers = "From: =?UTF-8?B?" . base64_encode('שמאות מקרקעין') . "?= <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if ($safe && $send($to, $subject, $text, $headers, '-f' . $from)) {
        $GLOBALS['apr_mail_via'] = 'envelope -f';
        return true;
    }
    $ok = (bool)$send($to, $subject, $text, $headers, '');
    $GLOBALS['apr_mail_via'] = $safe ? 'no envelope (-f refused)' : 'no envelope (host not usable)';
    return $ok;
}

/** יומן המיילים למפתח: hash, סוג, מצב ושגיאה — בלי כתובת. */
function apr_mail_rows(array $q = []): array
{
    $limit = max(1, min(200, (int)($q['limit'] ?? 100)));
    return ['rows' => array_map(fn($r) => ['at' => $r['at'], 'kind' => $r['kind'], 'to' => substr($r['to_hash'], 0, 12), 'status' => $r['status'], 'error' => $r['error']],
        apr_rows('SELECT at, kind, to_hash, status, error FROM mail_log ORDER BY id DESC LIMIT ' . $limit))];
}
