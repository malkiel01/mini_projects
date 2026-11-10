<?php
/**
 * מייל (אפיון 02 §3): כל מייל שהמערכת שולחת עובר כאן ונרשם ב-mail_log — עם hash של הכתובת, לא הכתובת.
 * מפתח אידמפוטנטיות: אותו מייל לא יוצא פעמיים. מייל שנכשל אינו מוצג כנשלח.
 * בבדיקות: $GLOBALS['apr_mail'] = fn(string $to, string $subject, string $text): bool. ב-CLI בלי זה — לא נשלח דבר.
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
        if (!$ok) $error = 'transport returned false';
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

function apr_mail_transport(string $to, string $subject, string $text): bool
{
    if (isset($GLOBALS['apr_mail'])) return (bool)($GLOBALS['apr_mail'])($to, $subject, $text);
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || !function_exists('mail')) return false;
    $host = (string)parse_url(apr_app_url(), PHP_URL_HOST);
    $headers = "From: =?UTF-8?B?" . base64_encode('שמאות מקרקעין') . "?= <noreply@$host>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
}

/** יומן המיילים למפתח: hash, סוג, מצב ושגיאה — בלי כתובת. */
function apr_mail_rows(array $q = []): array
{
    $limit = max(1, min(200, (int)($q['limit'] ?? 100)));
    return ['rows' => array_map(fn($r) => ['at' => $r['at'], 'kind' => $r['kind'], 'to' => substr($r['to_hash'], 0, 12), 'status' => $r['status'], 'error' => $r['error']],
        apr_rows('SELECT at, kind, to_hash, status, error FROM mail_log ORDER BY id DESC LIMIT ' . $limit))];
}
