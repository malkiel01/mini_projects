<?php
/**
 * הודעות — שלב 11 (README, "הודעות").
 *
 * שלושה ערוצים: **מייל** — אוטומטי מהשרת, ברגע שקורה משהו שנוגע לאיש הקשר;
 * **וואטסאפ** ו-**SMS** — הודעה מוכנה, והמנהל לוחץ (חלון השליחה בדפדפן). שליחה
 * אוטומטית בוואטסאפ / SMS דורשת ספק בתשלום — תוסף בהמשך.
 *
 * מי מקבל מייל: לקוח — לפי "ערוצי הודעות" בכרטיס שלו; עו"ד וספק — כשיש להם אימייל.
 *
 * הנוסחים: לכל מנהל משלו (message_templates), וברירת המחדל כאן. אותו נוסח משמש
 * למייל האוטומטי ולהודעה המוכנה בוואטסאפ. משתנים: {שם} {פרויקט} {כתובת} {שלב}
 * {סכום} {פרטים} {קישור} {מנהל}.
 *
 * כל הודעה נרשמת ב-messages (גם ידנית: "נפתח בוואטסאפ"), ושורה בציר הזמן.
 */

declare(strict_types=1);

require_once __DIR__ . '/calendar.php';

/** key => [שם, למי, נושא, גוף] */
const PM_MSG_TEMPLATES = [
    'portal_link' => ['הקישור האישי', 'לקוח / עו"ד / ספק', 'הקישור שלך לפרויקט {פרויקט}',
        "שלום {שם},\nזה הקישור האישי שלך לפרויקט \"{פרויקט}\" — {פרטים}:\n{קישור}\n\n{מנהל}"],
    'quotes_sent' => ['הצעות מחיר נשלחו ללקוח', 'לקוח', 'הצעות מחיר לשלב {שלב} — {פרויקט}',
        "שלום {שם},\nהצעות מחיר לשלב \"{שלב}\" בפרויקט {פרויקט}:\n\n{פרטים}\n\nהסכומים כוללים מע\"מ. לצפייה בהצעות ולאישור:\n{קישור}\n\n{מנהל}"],
    'quote_lawyer' => ['הצעה ממתינה לאישור עו"ד', 'עו"ד', 'לאישורך: הצעת מחיר לשלב {שלב} — {פרויקט}',
        "שלום {שם},\nהלקוח אישר הצעת מחיר לשלב \"{שלב}\" בפרויקט {פרויקט}: {פרטים}, {סכום} כולל מע\"מ.\nלאישורך:\n{קישור}\n\n{מנהל}"],
    'payment_request' => ['דרישת תשלום', 'משלם (לקוח / עו"ד)', 'דרישת תשלום — {פרויקט}',
        "שלום {שם},\nדרישת תשלום בפרויקט {פרויקט}:\n{פרטים} — {סכום} כולל מע\"מ.\n\nפרטי הבנק של הספק, והעלאת אסמכתא אחרי התשלום:\n{קישור}\n\n{מנהל}"],
    'payment_lawyer' => ['דרישת תשלום לאישור עו"ד', 'עו"ד', 'לאישורך: דרישת תשלום — {פרויקט}',
        "שלום {שם},\nדרישת תשלום לאישורך בפרויקט {פרויקט}:\n{פרטים} — {סכום} כולל מע\"מ.\nלאישור:\n{קישור}\n\n{מנהל}"],
    'quote_invite' => ['בקשת הצעת מחיר מספק', 'ספק', 'בקשת הצעת מחיר — {שלב}, {פרויקט}',
        "שלום {שם},\nאשמח להצעת מחיר לשלב \"{שלב}\" בפרויקט {פרויקט}{כתובת}.\nלהגשת ההצעה — סכום וקובץ:\n{קישור}\n\n{מנהל}"],
    'quote_result' => ['תשובה לספק על ההצעה', 'ספק', 'ההצעה שלך לשלב {שלב} — {פרטים}',
        "שלום {שם},\nההצעה שלך לשלב \"{שלב}\" בפרויקט {פרויקט} ({סכום}) — {פרטים}.\nהפרטים בקישור:\n{קישור}\n\n{מנהל}"],
    'proof_supplier' => ['דווח תשלום לספק', 'ספק', 'דווח תשלום אליך — {פרויקט}',
        "שלום {שם},\nדווח תשלום אליך בפרויקט {פרויקט}: {פרטים}, {סכום}.\nהאסמכתא, ואישור שהכסף התקבל:\n{קישור}\n\n{מנהל}"],
    'stage_done' => ['שלב בוצע — ללקוח', 'לקוח', 'שלב {שלב} בוצע — {פרויקט}',
        "שלום {שם},\nשלב \"{שלב}\" בפרויקט {פרויקט} בוצע.{פרטים}\nתמונות ועדכונים:\n{קישור}\n\n{מנהל}"],
];

/** ההודעות שנשלחו בבקשה הזו — חוזרות בתשובה (notified), והדפדפן מראה "נשלח מייל ל…". */
function pm_notified(?array $add = null): array
{
    static $list = [];
    if ($add !== null) $list[] = $add;
    return $list;
}

function pm_msg_templates(array $user): array
{
    $st = pm_db()->prepare('SELECT key, subject, body FROM message_templates WHERE owner_id = ?');
    $st->execute([$user['id']]);
    $mine = [];
    foreach ($st->fetchAll() as $r) $mine[$r['key']] = $r;
    $out = [];
    foreach (PM_MSG_TEMPLATES as $key => [$label, $to, $subject, $body]) {
        $out[] = ['key' => $key, 'label' => $label, 'to' => $to,
            'subject' => $mine[$key]['subject'] ?? $subject, 'body' => $mine[$key]['body'] ?? $body,
            'custom' => isset($mine[$key]), 'default_subject' => $subject, 'default_body' => $body];
    }
    return $out;
}

function pm_msg_template_save(array $user, array $in): array
{
    $key = (string)($in['key'] ?? '');
    if (!isset(PM_MSG_TEMPLATES[$key])) throw new PmError('נוסח לא מוכר', 422);
    if (!empty($in['reset'])) {
        pm_db()->prepare('DELETE FROM message_templates WHERE owner_id = ? AND key = ?')->execute([$user['id'], $key]);
    } else {
        $subject = pm_str($in, 'subject', 200, true, 'נושא');
        $body = pm_str($in, 'body', 3000, true, 'הנוסח');
        if (!str_contains($body, '{קישור}') && in_array($key, ['portal_link', 'quotes_sent', 'quote_lawyer', 'payment_request', 'payment_lawyer', 'quote_invite', 'proof_supplier'], true)) {
            throw new PmError('הנוסח צריך לכלול {קישור} — בלעדיו הנמען לא יוכל לפעול', 422);
        }
        pm_db()->prepare('INSERT INTO message_templates (owner_id, key, subject, body) VALUES (?,?,?,?)
                          ON CONFLICT(owner_id, key) DO UPDATE SET subject = excluded.subject, body = excluded.body')
           ->execute([$user['id'], $key, $subject, $body]);
    }
    return ['templates' => pm_msg_templates($user)];
}

/** [נושא, גוף] — הנוסח של בעל הפרויקט, עם המשתנים. */
function pm_msg_render(array $owner, string $key, array $vars): array
{
    $t = null;
    foreach (pm_msg_templates($owner) as $x) if ($x['key'] === $key) $t = $x;
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string)$v;
    $map += ['{מנהל}' => $owner['display_name'] ?? '', '{כתובת}' => '', '{פרטים}' => '', '{סכום}' => '', '{שלב}' => '', '{קישור}' => ''];
    return [trim(strtr($t['subject'], $map)), trim(strtr($t['body'], $map))];
}

function pm_contact_full(int $id): ?array
{
    $st = pm_db()->prepare('SELECT * FROM contacts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** בעל הפרויקט — לחתימה ("{מנהל}") ולנוסחים. */
function pm_project_owner(int $projectId): array
{
    $st = pm_db()->prepare('SELECT u.id, u.display_name, u.phone, u.email, u.role, p.name AS project_name, p.address
                              FROM projects p JOIN users u ON u.id = p.owner_id WHERE p.id = ?');
    $st->execute([$projectId]);
    return $st->fetch() ?: throw new PmError('הפרויקט לא נמצא', 404);
}

/**
 * מייל אוטומטי לאיש קשר בפרויקט — אם יש לו אימייל ומייל מותר לו. מחזיר true כשנשלח.
 * $actor — מי מופיע בציר הזמן (המנהל, או המערכת). לא זורק — תקלה במייל לא מפילה פעולה.
 */
function pm_notify(int $projectId, int $contactId, string $key, array $vars, ?array $actor = null): bool
{
    try {
        $c = pm_contact_full($contactId);
        if (!$c || $c['email'] === '') return false;
        if ($c['kind'] === 'client' && !in_array('email', pm_client_settings($contactId)['channels'], true)) return false;
        $owner = pm_project_owner($projectId);
        $vars += ['שם' => $c['name'], 'פרויקט' => $owner['project_name']];
        [$subject, $body] = pm_msg_render($owner, $key, $vars);
        $ok = pm_send_mail($c['email'], $subject, $body);
        pm_db()->prepare('INSERT INTO messages (project_id, contact_id, channel, key, recipient, subject, body, status, auto, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$projectId, $contactId, 'email', $key, $c['email'], $subject, $body, $ok ? 'sent' : 'failed', 1, (int)($actor['id'] ?? 0), pm_now()]);
        pm_log($ok ? 'info' : 'warn', 'mail', "mail.$key", ['data' => ['to' => $c['email'], 'contact' => $contactId, 'project' => $projectId, 'sent' => $ok]]);
        if ($ok) {
            pm_event($actor ?? ['id' => 0, 'display_name' => 'מערכת'], $projectId, 'message', "✉️ נשלח מייל ל{$c['name']}: $subject", ['contact' => $contactId, 'key' => $key]);
            pm_notified(['name' => $c['name'], 'email' => $c['email']]);
        }
        return $ok;
    } catch (Throwable $e) {
        pm_log('error', 'mail', 'notify.failed', ['message' => $e->getMessage(), 'data' => ['key' => $key, 'contact' => $contactId]]);
        return false;
    }
}

/** הקישור האישי של איש קשר (נוצר אם אין) — בשם בעל הפרויקט. */
function pm_notify_link(int $projectId, int $contactId): string
{
    $owner = pm_project_owner($projectId);
    try { return pm_portal_link_save(['id' => (int)$owner['id'], 'display_name' => $owner['display_name'], 'role' => $owner['role']], $projectId, $contactId, 'get')['link']['url'] ?? ''; }
    catch (Throwable) { return ''; }
}

/** הודעה ידנית שהמנהל פתח בוואטסאפ / SMS / מייל — נרשמת ("נפתח"), בלי ודאות שנשלחה. */
function pm_message_log(array $user, array $in): array
{
    $channel = (string)($in['channel'] ?? '');
    if (!in_array($channel, ['whatsapp', 'sms', 'email', 'copy'], true)) throw new PmError('ערוץ לא מוכר', 422);
    $projectId = (int)($in['project_id'] ?? 0) ?: null;
    if ($projectId) pm_project_row($user, $projectId);
    $contactId = (int)($in['contact_id'] ?? 0) ?: null;
    $name = '';
    if ($contactId) {
        $c = pm_contact_full($contactId);
        if (!$c || (int)$c['owner_id'] !== (int)$user['id']) $contactId = null; else $name = $c['name'];
    }
    $body = mb_substr((string)($in['text'] ?? ''), 0, 3000);
    pm_db()->prepare('INSERT INTO messages (project_id, contact_id, channel, key, recipient, subject, body, status, auto, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$projectId, $contactId, $channel, mb_substr((string)($in['key'] ?? ''), 0, 40), $name, mb_substr((string)($in['subject'] ?? ''), 0, 200), $body, 'opened', 0, $user['id'], pm_now()]);
    $via = ['whatsapp' => 'וואטסאפ', 'sms' => 'SMS', 'email' => 'מייל', 'copy' => 'העתקה'][$channel];
    if ($projectId) pm_event($user, $projectId, 'message', "💬 $via ל" . ($name ?: 'איש קשר') . ': ' . mb_substr(strtok($body, "\n") ?: '', 0, 60), ['contact' => $contactId, 'channel' => $channel]);
    return ['ok' => true];
}

/** ההודעות של פרויקט — לצוות: מי קיבל מה ומתי. */
function pm_messages(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    $st = pm_db()->prepare('SELECT m.*, c.name AS contact_name FROM messages m LEFT JOIN contacts c ON c.id = m.contact_id
                             WHERE m.project_id = ? ORDER BY m.id DESC LIMIT 100');
    $st->execute([$projectId]);
    return ['messages' => array_map(fn($m) => ['id' => (int)$m['id'], 'contact_id' => $m['contact_id'] !== null ? (int)$m['contact_id'] : null,
        'contact_name' => $m['contact_name'] ?: $m['recipient'], 'channel' => $m['channel'], 'subject' => $m['subject'], 'body' => $m['body'],
        'status' => $m['status'], 'auto' => (bool)$m['auto'], 'at' => $m['created_at']], $st->fetchAll())];
}

/** מייל בדיקה לעצמי — לבדוק שהשרת באמת שולח. */
function pm_mail_test(array $user): array
{
    $to = pm_user_email($user + ['username' => $user['username'] ?? '']);
    if ($to === '') throw new PmError('אין אימייל בחשבון שלך — מוסיפים ב"החשבון שלי"', 422);
    $ok = pm_send_mail($to, 'מייל בדיקה — ניהול פרויקטים', "שלום {$user['display_name']},\n\nאם המייל הזה הגיע — השרת שולח מיילים, וההודעות האוטומטיות יגיעו ללקוחות, לעורכי הדין ולספקים.\n\n" . pm_app_url() . "\n");
    pm_log($ok ? 'info' : 'warn', 'mail', 'mail.test', ['user' => $user, 'data' => ['to' => $to, 'sent' => $ok, 'from' => pm_mail_from()]]);
    return ['sent' => $ok, 'to' => $to, 'from' => pm_mail_from()];
}
