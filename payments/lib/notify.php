<?php
/**
 * הודעות ללקוח המשלם — מייל ומסרון (פרק 8.5, ופרק 10.6: ההודעות שהחוק מחייב).
 * כל הודעה נכנסת לטבלה עם מפתח ייחודי (dedupe) — אותה הודעה חוקית לא נשלחת פעמיים, וגם יש תיעוד שנשלחה.
 * מסרון: דרך הספק שהמנוי הגדיר (lib/sms.php). בלי ספק — נרשם "no_provider" ומופיע אצל המנהל, וחוזר לתור כשמגדירים.
 */

declare(strict_types=1);

require_once __DIR__ . '/util.php';

/** תור הודעה. מחזיר false אם כבר נשלחה הודעה עם אותו dedupe. */
function notify(string $accountId, string $channel, string $template, string $dedupe, string $subject, string $body): bool
{
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$accountId]);
    if (!$acc) return false;
    $to = $channel === 'sms' ? (string)$acc['phone'] : (string)$acc['email'];
    $body .= "\n\n" . cancel_line($acc);
    $n = db_run('INSERT OR IGNORE INTO notification (id, account, channel, template, dedupe, to_addr, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        new_id('ntf'), $accountId, $channel, $template, $dedupe, $to, $subject, $body,
        $to === '' ? 'failed' : 'pending', now(),
    ]);
    return $n === 1;
}

/** דרכי הביטול — חובה על כל הודעה ומסמך (§14ט). */
function cancel_line(array $acc): string
{
    $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
    $mail = $m['email'] ?? '';
    return 'ביטול המנוי: בעמוד "החשבון שלי" באפליקציה' . ($mail !== '' ? ", או במייל $mail" : '') . '.';
}

/** שולח את ההודעות שבתור. מייל — mail() של השרת; בבדיקות — PAY_MAIL_HOOK. */
function notifications_send(int $limit = 50): int
{
    $sent = 0;
    foreach (db_all("SELECT * FROM notification WHERE status = 'pending' ORDER BY created_at LIMIT ?", [$limit]) as $n) {
        if ($n['channel'] === 'sms') {
            $tenant = db_one('SELECT m.tenant FROM account a JOIN merchant m ON m.id = a.merchant WHERE a.id = ?', [$n['account']])['tenant'] ?? '';
            $cfg = function_exists('sms_config') ? sms_config((string)$tenant) : null;
            if (!$cfg) { db_run("UPDATE notification SET status = 'no_provider' WHERE id = ?", [$n['id']]); continue; }
            $hook = $GLOBALS['PAY_SMS_HOOK'] ?? null;
            [$ok] = is_callable($hook) ? [(bool)$hook($n['to_addr'], $n['body'])] : sms_send($cfg, $n['to_addr'], $n['body']);
            db_run('UPDATE notification SET status = ?, sent_at = ? WHERE id = ?', [$ok ? 'sent' : 'failed', now(), $n['id']]);
            $sent += (int)$ok;
            continue;
        }
        $ok = send_mail($n['to_addr'], $n['subject'], $n['body'], $n['account']);
        db_run('UPDATE notification SET status = ?, sent_at = ? WHERE id = ?', [$ok ? 'sent' : 'failed', now(), $n['id']]);
        $sent += (int)$ok;
    }
    return $sent;
}

function send_mail(string $to, string $subject, string $body, string $accountId): bool
{
    $hook = $GLOBALS['PAY_MAIL_HOOK'] ?? null;
    if (is_callable($hook)) return (bool)$hook($to, $subject, $body);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $acc = db_one('SELECT a.merchant, m.name, m.email FROM account a JOIN merchant m ON m.id = a.merchant WHERE a.id = ?', [$accountId]);
    $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $from = setting('mail_from') ?: "no-reply@$host";
    $name = '=?UTF-8?B?' . base64_encode((string)($acc['name'] ?? 'תשלומים')) . '?=';
    $headers = [
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
        "From: $name <$from>",
    ];
    if (!empty($acc['email'])) $headers[] = 'Reply-To: ' . $acc['email'];
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

function money_text(int $agorot): string
{
    return '₪' . number_format($agorot / 100, 2);
}

function date_text(int $t): string
{
    $d = new DateTime('@' . $t);
    $d->setTimezone(new DateTimeZone('Asia/Jerusalem'));
    return $d->format('d.m.Y');
}
