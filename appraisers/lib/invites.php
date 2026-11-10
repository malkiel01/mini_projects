<?php
/**
 * הזמנה דרך המעטפת (אפיון 02 §4): אחרי שההזמנה נשמרה — בקשת provision חתומה למעטפת (חשבון עם הרשאה לאפליקציה הזו
 * בלבד), ומייל הזמנה משלנו. נכשלים סגור: מעטפת שלא עונה אינה "החשבון נפתח", ומייל שנכשל אינו "נשלח".
 * הבקשה נשלחת רק מפעולת הזמנה של מנהל משרד פעיל (invite-create / invite-resend), למייל אחד בכל פעם, ומחוץ לטרנזקציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/jobs.php';

const APR_INVITES_PER_DAY = 20;            // למשרד, וגם למשתמש המזמין; התקרה הכללית — הגדרת המערכת invites_per_day
const APR_INVITE_RESEND_GAP = 60;

/** מה שהמנהל רואה על הזמנה: מצב פתיחת החשבון ומצב המייל. */
function apr_invite_state(array $i): array
{
    return ['provision' => $i['provision_status'], 'mail' => $i['email_status']];
}

/** בקשת provision להזמנה אחת, ועדכון השורה. מחזיר invited / granted / blocked / failed. */
function apr_invite_provision(array $i): string
{
    $name = $i['name'] !== '' ? $i['name'] : (string)strstr($i['email'], '@', true);
    $res = apr_provision($i['email'], $name);
    $status = $res['status'] ?? 'failed';
    apr_tx(function () use ($i, $res, $status) {
        // invitee_sub נרשם רק כשהמעטפת החזירה אותו; כישלון לא נוגע במה שכבר נרשם
        if ($res && $res['sub'] !== null) apr_exec('UPDATE invitations SET provision_status = ?, invitee_sub = ? WHERE id = ?', [$status, $res['sub'], $i['id']]);
        else apr_exec('UPDATE invitations SET provision_status = ? WHERE id = ?', [$status, $i['id']]);
        apr_audit('invite.provision', 'invitation', $i['pid'], ['status' => $status], office: (int)$i['office_id']);
    });
    return $status;
}

/** מייל ההזמנה שלנו, עם הקישור. מחזיר sent / failed. כישלון אינו דורס את החותמת של שליחה שכבר הצליחה. */
function apr_invite_mail(array $i, string $token, string $idem): string
{
    $office = (string)apr_val('SELECT name FROM offices WHERE id = ?', [$i['office_id']]);
    $link = apr_app_url('#/invite/' . $token);
    $status = apr_mail_send('invite', $i['email'], "הוזמנת למשרד $office — שמאות מקרקעין",
        "שלום,\n\nהוזמנת להצטרף למשרד \"$office\" במערכת שמאות מקרקעין.\n\nלהצטרפות:\n$link\n\nהקישור בתוקף עד " . substr($i['expires_at'], 0, 10)
        . ".\nאם אין לך עדיין חשבון, יגיע אליך מייל נפרד לבחירת סיסמה — בחרו סיסמה, ואז פתחו את הקישור.\n", $idem, (int)$i['office_id']);
    if ($status === 'sent') apr_exec("UPDATE invitations SET email_status = 'sent', email_sent_at = ? WHERE id = ?", [apr_now(), $i['id']]);
    else apr_exec("UPDATE invitations SET email_status = 'failed' WHERE id = ?", [$i['id']]);
    return $status;
}

/**
 * provision, ואם נכשל — משימת רקע לניסיון חוזר. מפתח ה-dedupe נושא את הזמן: סדרת ניסיונות שמוצתה (3 כישלונות) אינה
 * חוסמת סדרה חדשה אחרי "שליחה שוב".
 */
function apr_invite_provision_or_queue(array $i): string
{
    $status = apr_invite_provision($i);
    if ($status === 'failed') {
        apr_job_add('invite.provision', ['invite' => $i['pid']], 'invite.provision:' . $i['pid'] . ':' . apr_time(), (int)$i['office_id'], APR_JOB_BACKOFF[0]);
    }
    return $status;
}

/** אחרי יצירת הזמנה: provision (וניסיון חוזר ברקע אם נכשל), ומייל — בכל מקרה שאינו blocked. */
function apr_invite_deliver(int $inviteId, string $token): array
{
    $i = apr_row('SELECT * FROM invitations WHERE id = ?', [$inviteId]);
    $status = apr_invite_provision_or_queue($i);
    $mail = $status === 'blocked' ? null : apr_invite_mail($i, $token, 'invite:' . $i['pid']);
    return ['provision' => $status, 'mail' => $mail];
}

/**
 * "שליחה שוב": קישור חדש, ניסיון חוזר לפתיחת החשבון אם עוד לא נפתח, ומייל חדש. פעם בדקה להזמנה — גם כשהמייל
 * הקודם נכשל (המרווח נספר לפי הניסיון, לא לפי ההצלחה).
 */
function apr_invite_resend(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    [$i, $token] = apr_tx(function () use ($in, $office) {
        $i = apr_invite_mine($office, $in['invite'] ?? null);
        if ($i['email_sent_at'] !== null && strtotime($i['email_sent_at']) > apr_time() - APR_INVITE_RESEND_GAP) apr_fail('too_soon');
        if (!apr_limit('resend:' . $i['id'], 1, APR_INVITE_RESEND_GAP)) apr_fail('too_soon');
        $token = bin2hex(random_bytes(24));
        apr_exec('UPDATE invitations SET token_hash = ? WHERE id = ?', [hash('sha256', $token), $i['id']]);
        apr_audit('invite.resend', 'invitation', $i['pid'], [], office: $office);
        return [$i, $token];
    });
    $status = in_array($i['provision_status'], ['invited', 'granted'], true) ? $i['provision_status'] : apr_invite_provision_or_queue($i);
    $mail = $status === 'blocked' ? null : apr_invite_mail($i, $token, 'invite:' . $i['pid'] . ':' . apr_time());
    return ['invite' => $i['pid'], 'token' => $token, 'expires_at' => $i['expires_at'], 'provision' => $status, 'mail' => $mail];
}

// ניסיון חוזר לפתיחת החשבון (3 ניסיונות). לא שולח מייל: המייל שלנו כבר יצא עם ההזמנה, והאסימון אינו שמור.
apr_job_register('invite.provision', 'ניסיון חוזר לפתיחת חשבון במעטפת למוזמן', function (array $p): string {
    $i = apr_row("SELECT * FROM invitations WHERE pid = ? AND status = 'pending'", [(string)($p['invite'] ?? '')]);
    if (!$i || in_array($i['provision_status'], ['invited', 'granted', 'blocked'], true)) return 'done';
    if (apr_invite_provision($i) === 'failed') throw new RuntimeException('provision: no answer');
    return 'done';
});
