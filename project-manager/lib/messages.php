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
 *
 * שפות: לכל נוסח גרסה בכל שפה (message_templates: owner_id, key, lang). ברירת המחדל —
 * הנוסח העברי כאן, מתורגם במילון. המייל יוצא בשפת הנמען (contacts.lang). המשתנים
 * הם שמות עבריים ({קישור}), ולכל שפה גם כינוי משלה ({link}) — pm_msg_vars().
 */

declare(strict_types=1);

require_once __DIR__ . '/calendar.php';

/** key => [שם, למי, נושא, גוף] — העברית היא המפתח במילון; מתורגם ב-pm_msg_templates. */
// i18n-ok-start
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
    // אגף הכספים, כ4 — תוספות וחריגות
    'change_order' => ['תוספת / חריגה — לאישור הלקוח', 'לקוח', 'לאישורך: תוספת בשלב {שלב} — {פרויקט}',
        "שלום {שם},\nתוספת בשלב \"{שלב}\" בפרויקט {פרויקט}: {פרטים} — {סכום} כולל מע\"מ.\nלצפייה ולאישור:\n{קישור}\n\n{מנהל}"],
    'change_order_lawyer' => ['תוספת / חריגה — לאישור עו"ד', 'עו"ד', 'לאישורך: תוספת בשלב {שלב} — {פרויקט}',
        "שלום {שם},\nהלקוח אישר תוספת בשלב \"{שלב}\" בפרויקט {פרויקט}: {פרטים} — {סכום} כולל מע\"מ.\nלאישורך:\n{קישור}\n\n{מנהל}"],
    // 🔧 ריג'קטים (lib/rejects.php) — לספק: חדש, ועדכון (הוחזר / הודעה)
    'reject_new' => ['ריג\'קט חדש — לספק', 'ספק', 'ריג\'קט לתיקון — {פרויקט}',
        "שלום {שם},\nבפרויקט {פרויקט} נפתח ריג'קט לתיקון:\n{פרטים}\n\nהפרטים והתמונות, ו\"תיקנתי\" עם תמונה כשמסיימים:\n{קישור}\n\n{מנהל}"],
    'reject_update' => ['ריג\'קט — עדכון לספק', 'ספק', 'עדכון בריג\'קט — {פרויקט}',
        "שלום {שם},\nעדכון בריג'קט בפרויקט {פרויקט}:\n{פרטים}\n\nלפרטים ולתשובה:\n{קישור}\n\n{מנהל}"],
    // חריגה מהחוזה בהסכמת כולם (lib/deviations.php) — לכל צד: לקוח, עו"ד, ספק
    'deviation_request' => ['חריגה מהחוזה — להסכמתך', 'לקוח / עו"ד / ספק', 'להסכמתך: חריגה מהחוזה — {פרויקט}',
        "שלום {שם},\nבפרויקט {פרויקט} מבקשים חריגה מהחוזה, והיא תחול רק אם כל הצדדים יסכימו:\n{פרטים}\n\nלפרטים, ולהסכמה או לסירוב:\n{קישור}\n\n{מנהל}"],
    // הצעת תקציב לפי שלבים (lib/budget.php) — ההצעה הראשונה וכל שינוי
    'budget_request' => ['הצעת תקציב / שינוי — לאישור', 'לקוח / עו"ד', 'לאישורך: תקציב הפרויקט — {פרויקט}',
        "שלום {שם},\n{פרטים} בפרויקט {פרויקט} — סה\"כ {סכום} כולל מע\"מ.\nהפירוט לפי שלבים, ואישור:\n{קישור}\n\n{מנהל}"],
];

/** שמות המשתנים בנוסחים. בכל שפה הכינוי שלהם — התרגום של השם (מילה אחת). */
// במילון — עם הסוגריים ("{קישור}" ← "{link}"), כדי שמילה רגילה בנתונים ("שם") לא תיתפס ככינוי
const PM_MSG_VARS = ['{שם}', '{פרויקט}', '{מנהל}', '{כתובת}', '{פרטים}', '{סכום}', '{שלב}', '{קישור}'];
// i18n-ok-end

/** נוסחים שחייבים את הקישור — בלעדיו הנמען לא יכול לפעול. */
const PM_MSG_NEED_LINK = ['portal_link', 'quotes_sent', 'quote_lawyer', 'payment_request', 'payment_lawyer', 'quote_invite', 'proof_supplier', 'change_order', 'change_order_lawyer', 'budget_request', 'deviation_request', 'reject_new', 'reject_update'];

/** שפה ← [שם עברי ← כינוי בשפה]. בעברית — השם עצמו. */
function pm_msg_vars(): array
{
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    foreach (array_keys(PM_LANGS) as $lang) {
        foreach (PM_MSG_VARS as $key) {
            $name = trim($key, '{}');
            $alias = $lang === 'he' ? $name : trim(pm_t($key, [], $lang), " {}");
            // כינוי חייב להיות מילה אחת בלי סוגריים — אחרת נשאר השם העברי
            if ($alias === '' || preg_match('/[\s{}]/u', $alias)) $alias = $name;
            $out[$lang][$name] = $alias;
        }
    }
    return $out;
}

/** שפת איש הקשר — למיילים ולהודעות אליו. */
function pm_contact_lang(int $id): string
{
    $st = pm_db()->prepare('SELECT lang FROM contacts WHERE id = ?');
    $st->execute([$id]);
    return pm_lang_valid((string)$st->fetchColumn());
}

/** ההודעות שנשלחו בבקשה הזו — חוזרות בתשובה (notified), והדפדפן מראה "נשלח מייל ל…". */
function pm_notified(?array $add = null): array
{
    static $list = [];
    if ($add !== null) $list[] = $add;
    return $list;
}

function pm_msg_templates(array $user): array
{
    $st = pm_db()->prepare('SELECT key, lang, subject, body FROM message_templates WHERE owner_id = ?');
    $st->execute([$user['id']]);
    $mine = [];
    foreach ($st->fetchAll() as $r) $mine[$r['key']][$r['lang']] = $r;
    $out = [];
    foreach (PM_MSG_TEMPLATES as $key => [$label, $to, $subject, $body]) {
        foreach (array_keys(PM_LANGS) as $lang) {
            $ds = pm_t($subject, [], $lang);
            $dbody = pm_t($body, [], $lang);
            $c = $mine[$key][$lang] ?? null;
            $out[] = ['key' => $key, 'lang' => $lang, 'label' => pm_t($label), 'to' => pm_t($to),
                'subject' => $c['subject'] ?? $ds, 'body' => $c['body'] ?? $dbody,
                'custom' => $c !== null, 'default_subject' => $ds, 'default_body' => $dbody];
        }
    }
    return $out;
}

function pm_msg_template_save(array $user, array $in): array
{
    $key = (string)($in['key'] ?? '');
    if (!isset(PM_MSG_TEMPLATES[$key])) throw new PmError('נוסח לא מוכר', 422);
    $lang = isset(PM_LANGS[(string)($in['lang'] ?? '')]) ? (string)$in['lang'] : pm_lang();
    if (!empty($in['reset'])) {
        pm_db()->prepare('DELETE FROM message_templates WHERE owner_id = ? AND key = ? AND lang = ?')->execute([$user['id'], $key, $lang]);
    } else {
        $subject = pm_str($in, 'subject', 200, true, 'נושא');
        $body = pm_str($in, 'body', 3000, true, 'הנוסח');
        $link = pm_msg_vars()[$lang]['קישור']; // i18n-none
        if (!str_contains($body, '{קישור}') && !str_contains($body, '{' . $link . '}') && in_array($key, PM_MSG_NEED_LINK, true)) { // i18n-none
            throw new PmError('הנוסח צריך לכלול {tag} — בלעדיו הנמען לא יוכל לפעול', 422, ['tag' => '{' . $link . '}']);
        }
        pm_db()->prepare('INSERT INTO message_templates (owner_id, key, lang, subject, body) VALUES (?,?,?,?,?)
                          ON CONFLICT(owner_id, key, lang) DO UPDATE SET subject = excluded.subject, body = excluded.body')
           ->execute([$user['id'], $key, $lang, $subject, $body]);
    }
    return ['templates' => pm_msg_templates($user)];
}

/**
 * [נושא, גוף] — הנוסח של בעל הפרויקט בשפה $lang (שלו בשפה הזו, אחרת ברירת המחדל
 * מתורגמת), עם המשתנים: גם {שם עברי} וגם {כינוי בשפה}. ערך שהוא מפתח במילון
 * ("אושרה ✅", שם שלב שנזרע) — מתורגם לשפה.
 */
function pm_msg_render(array $owner, string $key, array $vars, ?string $lang = null): array
{
    $lang = pm_lang_valid($lang ?? pm_lang());
    $def = PM_MSG_TEMPLATES[$key] ?? throw new PmError('נוסח לא מוכר', 422);
    $st = pm_db()->prepare('SELECT subject, body FROM message_templates WHERE owner_id = ? AND key = ? AND lang = ?');
    $st->execute([$owner['id'], $key, $lang]);
    $t = $st->fetch() ?: ['subject' => pm_t($def[2], [], $lang), 'body' => pm_t($def[3], [], $lang)];
    // i18n-none-start — שמות המשתנים, לא טקסט
    $vars += ['מנהל' => $owner['display_name'] ?? '', 'כתובת' => '', 'פרטים' => '', 'סכום' => '', 'שלב' => '', 'קישור' => ''];
    // i18n-none-end
    $alias = pm_msg_vars()[$lang];
    $map = [];
    foreach ($vars as $k => $v) {
        $v = is_string($v) && $v !== '' ? pm_t($v, [], $lang) : (string)$v;
        $map['{' . $k . '}'] = $v;
        if (isset($alias[$k])) $map['{' . $alias[$k] . '}'] ??= $v;
    }
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
        $vars += ['שם' => $c['name'], 'פרויקט' => $owner['project_name']]; // i18n-none
        $lang = pm_lang_valid((string)($c['lang'] ?? 'he'));
        [$subject, $body] = pm_msg_render($owner, $key, $vars, $lang);
        $ok = pm_send_mail($c['email'], $subject, $body, $lang);
        pm_db()->prepare('INSERT INTO messages (project_id, contact_id, channel, key, recipient, subject, body, status, auto, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$projectId, $contactId, 'email', $key, $c['email'], $subject, $body, $ok ? 'sent' : 'failed', 1, (int)($actor['id'] ?? 0), pm_now()]);
        pm_log($ok ? 'info' : 'warn', 'mail', "mail.$key", ['data' => ['to' => $c['email'], 'contact' => $contactId, 'project' => $projectId, 'sent' => $ok]]);
        if ($ok) {
            pm_event($actor ?? ['id' => 0, 'display_name' => 'מערכת'], $projectId, 'message', '✉️ נשלח מייל ל{name}: {subject}', // i18n-ok
                ['contact' => $contactId, 'key' => $key], ['name' => $c['name'], 'subject' => $subject]);
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
        try { $name = pm_contact_row($user, $contactId)['name']; } catch (PmError) { $contactId = null; } // רק כרטיס שהוא רואה
    }
    $body = mb_substr((string)($in['text'] ?? ''), 0, 3000);
    pm_db()->prepare('INSERT INTO messages (project_id, contact_id, channel, key, recipient, subject, body, status, auto, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$projectId, $contactId, $channel, mb_substr((string)($in['key'] ?? ''), 0, 40), $name, mb_substr((string)($in['subject'] ?? ''), 0, 200), $body, 'opened', 0, $user['id'], pm_now()]);
    // i18n-ok-start — שורה שלמה לכל ערוץ; מתורגמת בקריאה (pm_event_text)
    $tpl = ['whatsapp' => '💬 וואטסאפ ל{name}: {text}', 'sms' => '💬 SMS ל{name}: {text}',
            'email' => '💬 מייל ל{name}: {text}', 'copy' => '💬 העתקה ל{name}: {text}'][$channel];
    if ($projectId) pm_event($user, $projectId, 'message', $tpl, ['contact' => $contactId, 'channel' => $channel],
        ['name' => $name ?: 'איש קשר', 'text' => mb_substr(strtok($body, "\n") ?: '', 0, 60)]);
    // i18n-ok-end
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
    $lang = pm_user_lang((int)$user['id']);
    $ok = pm_send_mail($to, pm_t('מייל בדיקה — ניהול פרויקטים', [], $lang),
        pm_t("שלום {name},\n\nאם המייל הזה הגיע — השרת שולח מיילים, וההודעות האוטומטיות יגיעו ללקוחות, לעורכי הדין ולספקים.\n\n{url}\n",
            ['name' => $user['display_name'], 'url' => pm_app_url()], $lang), $lang);

    pm_log($ok ? 'info' : 'warn', 'mail', 'mail.test', ['user' => $user, 'data' => ['to' => $to, 'sent' => $ok, 'from' => pm_mail_from()]]);
    return ['sent' => $ok, 'to' => $to, 'from' => pm_mail_from()];
}
