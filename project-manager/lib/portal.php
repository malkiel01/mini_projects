<?php
/**
 * פורטל הלקוח והעו"ד — שלב 6.
 *
 * כל לקוח ועו"ד של פרויקט מקבל קישור אישי (portal/#t=<אסימון>), בלי סיסמה. בקישור:
 * מצב השלבים, הצעות המחיר שנשלחו אליו (המומלצת בראש), "ממתין לאישורך" — ואישור
 * או דחייה בלחיצה או בחתימה (לפי הגדרת הלקוח), והקבצים שסומנו "גלוי".
 *
 * האסימון: HMAC של מזהה הקישור ו-nonce בסוד השרת (data/config.json, מחוץ למסד ומחוץ
 * לגיט). במסד נשמר רק ה-hash שלו — ובכל זאת המנהל יכול לקבל את אותו קישור שוב בלי
 * לבטל את הקודם. "קישור חדש" מחליף את ה-nonce; "ביטול" — revoked_at.
 *
 * קישור תקף רק כל עוד איש הקשר הוא עדיין הלקוח או העו"ד של הפרויקט.
 */

declare(strict_types=1);

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/mail.php';

const PM_CONFIG = __DIR__ . '/../data/config.json';

function pm_portal_secret(): string
{
    $cfg = is_file(PM_CONFIG) ? (json_decode((string)file_get_contents(PM_CONFIG), true) ?: []) : [];
    if (empty($cfg['portal_secret'])) {
        $cfg['portal_secret'] = bin2hex(random_bytes(32));
        if (!is_dir(dirname(PM_CONFIG))) mkdir(dirname(PM_CONFIG), 0755, true);
        file_put_contents(PM_CONFIG, json_encode($cfg, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod(PM_CONFIG, 0600);
    }
    return $cfg['portal_secret'];
}

function pm_portal_token(int $id, string $nonce): string
{
    $raw = substr(hash_hmac('sha256', "portal:$id:$nonce", pm_portal_secret(), true), 0, 24);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** הכתובת המלאה. נקרא מ-api.php של האפליקציה (pm_app_url = תיקיית האפליקציה). */
function pm_portal_url(string $token): string
{
    return pm_app_url() . '/portal/#t=' . $token;
}

/** תפקיד איש הקשר בפרויקט — client · lawyer · supplier (כל ספק של בעל הפרויקט) — או חריגה. */
function pm_portal_role(array $p, int $contactId): string
{
    if ($contactId && (int)$p['client_id'] === $contactId) return 'client';
    if ($contactId && (int)$p['lawyer_id'] === $contactId) return 'lawyer';
    $st = pm_db()->prepare("SELECT 1 FROM contacts WHERE id = ? AND owner_id = ? AND kind = 'supplier'");
    $st->execute([$contactId, $p['owner_id']]);
    if ($contactId && $st->fetchColumn()) return 'supplier';
    throw new PmError('קישור אישי — ללקוח, לעו"ד או לספק של הפרויקט', 422);
}

function pm_portal_link_row(int $projectId, int $contactId): ?array
{
    $st = pm_db()->prepare('SELECT * FROM portal_links WHERE project_id = ? AND contact_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$projectId, $contactId]);
    return $st->fetch() ?: null;
}

function pm_portal_link_out(?array $l): ?array
{
    if (!$l) return null;
    return ['id' => (int)$l['id'], 'role' => $l['role'], 'url' => pm_portal_url(pm_portal_token((int)$l['id'], $l['nonce'])),
            'created_at' => $l['created_at'], 'last_seen_at' => $l['last_seen_at'], 'views' => (int)$l['views']];
}

/** הקישורים הפעילים של פרויקט — לצוות: מי קיבל, ומתי פתח לאחרונה. */
function pm_portal_links(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    $st = pm_db()->prepare('SELECT * FROM portal_links WHERE project_id = ? AND revoked_at IS NULL');
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $l) $out[(int)$l['contact_id']] = pm_portal_link_out($l);
    return $out;
}

/**
 * $do: get (קיים, או נוצר אם אין) · renew (חדש; הישן מפסיק לעבוד) · revoke.
 */
function pm_portal_link_save(array $user, int $projectId, int $contactId, string $do): array
{
    $p = pm_project_row($user, $projectId);
    $role = pm_portal_role($p, $contactId);
    $db = pm_db();
    $cur = pm_portal_link_row($projectId, $contactId);
    $name = pm_contact_name($contactId);
    if ($do === 'revoke') {
        if ($cur) {
            $db->prepare('UPDATE portal_links SET revoked_at = ? WHERE id = ?')->execute([pm_now(), $cur['id']]);
            $tpl = ['client' => 'בוטל הקישור האישי ללקוח: {name}', 'lawyer' => 'בוטל הקישור האישי לעו"ד: {name}', // i18n-ok
                    'supplier' => 'בוטל הקישור האישי לספק: {name}'][$role]; // i18n-ok
            pm_event($user, $projectId, 'portal', $tpl, ['contact' => $contactId], ['name' => $name]);
        }
        return ['link' => null];
    }
    if ($cur && $do === 'get') return ['link' => pm_portal_link_out($cur)];
    if (!in_array($do, ['get', 'renew'], true)) throw new PmError('פעולה לא מוכרת', 422);

    $db->beginTransaction();
    try {
        if ($cur) $db->prepare('UPDATE portal_links SET revoked_at = ? WHERE id = ?')->execute([pm_now(), $cur['id']]);
        $nonce = bin2hex(random_bytes(12));
        $db->prepare('INSERT INTO portal_links (project_id, contact_id, role, nonce, token_hash, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$projectId, $contactId, $role, $nonce, 'pending:' . $nonce, $user['id'], pm_now()]);
        $id = (int)$db->lastInsertId();
        $db->prepare('UPDATE portal_links SET token_hash = ? WHERE id = ?')->execute([hash('sha256', pm_portal_token($id, $nonce)), $id]);
        // i18n-ok-start — תבניות לציר הזמן (pm_event)
        $tpl = $cur
            ? ['client' => 'נוצר קישור אישי חדש ללקוח: {name} (הקודם בוטל)', 'lawyer' => 'נוצר קישור אישי חדש לעו"ד: {name} (הקודם בוטל)',
               'supplier' => 'נוצר קישור אישי חדש לספק: {name} (הקודם בוטל)'][$role]
            : ['client' => 'נוצר קישור אישי ללקוח: {name}', 'lawyer' => 'נוצר קישור אישי לעו"ד: {name}', 'supplier' => 'נוצר קישור אישי לספק: {name}'][$role];
        // i18n-ok-end
        pm_event($user, $projectId, 'portal', $tpl, ['contact' => $contactId], ['name' => $name]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['link' => pm_portal_link_out(pm_portal_link_row($projectId, $contactId))];
}

// ================= צד הפורטל =================

/**
 * מי פותח: האסימון מהכותרת X-Portal (או ?t= לקבצים — תמונה לא שולחת כותרות).
 * מחזיר את הקישור, הפרויקט ואיש הקשר — או 404 לכל מה שלא תקף.
 */
function pm_portal_access(string $token): array
{
    $bad = function (string $why) use ($token) {
        pm_log('warn', 'portal', 'portal.denied', ['message' => $why, 'data' => ['token_length' => strlen($token)]]);
        throw new PmError('הקישור לא תקף. בקשו קישור חדש ממנהל הפרויקט', 404);
    };
    // i18n-none-start — הסיבות ליומן בלבד; למבקר — "הקישור לא תקף"
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) $bad('אסימון בצורה לא תקינה');
    $st = pm_db()->prepare('SELECT * FROM portal_links WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $l = $st->fetch();
    if (!$l) $bad('אין קישור כזה');
    if ($l['revoked_at']) $bad('קישור שבוטל');
    $p = pm_db()->prepare('SELECT * FROM projects WHERE id = ?');
    $p->execute([$l['project_id']]);
    $project = $p->fetch();
    if (!$project) $bad('הפרויקט לא קיים');
    $c = pm_db()->prepare('SELECT * FROM contacts WHERE id = ?');
    $c->execute([$l['contact_id']]);
    $contact = $c->fetch();
    if (!$contact) $bad('איש הקשר לא קיים');
    if ($l['role'] === 'supplier') {
        if ($contact['kind'] !== 'supplier' || (int)$contact['owner_id'] !== (int)$project['owner_id']) $bad('הספק לא שייך לבעל הפרויקט');
    } else {
        $col = $l['role'] === 'client' ? 'client_id' : 'lawyer_id';
        if ((int)$project[$col] !== (int)$l['contact_id']) $bad('איש הקשר כבר לא ' . ($l['role'] === 'client' ? 'הלקוח' : 'העו"ד') . ' של הפרויקט');
    }
    // i18n-none-end
    $u = pm_db()->prepare('SELECT id, display_name, phone, email, role, lang FROM users WHERE id = ?');
    $u->execute([$project['owner_id']]);
    $owner = $u->fetch() ?: ['id' => (int)$project['owner_id'], 'display_name' => '', 'phone' => '', 'email' => '', 'role' => 'manager', 'lang' => ''];
    // השפה של מנהל הפרויקט — למיילים אליו ולשם שבציר הזמן שלו (לא נבחרה — עברית)
    $owner['lang'] = pm_lang_valid(($owner['lang'] ?? '') ?: 'he');
    $as = ['client' => '{name} (לקוח)', 'lawyer' => '{name} (עו"ד)', 'supplier' => '{name} (ספק)'][$l['role']]; // i18n-ok
    return ['link' => $l, 'project' => $project, 'contact' => $contact, 'owner' => ['id' => (int)$owner['id']] + $owner,
            // מי שמופיע בציר הזמן כשהלקוח / העו"ד פועל
            'actor' => ['id' => 0, 'display_name' => pm_t($as, ['name' => $contact['name']], $owner['lang']),
                        'actor' => 'portal:' . $l['id']]];
}

/** צפייה: נרשם "נפתח לאחרונה", ופתיחה ראשונה ביום — גם בציר הזמן. */
function pm_portal_seen(array $a): void
{
    $l = $a['link'];
    $now = pm_now();
    $firstToday = !$l['last_seen_at'] || substr($l['last_seen_at'], 0, 10) !== substr($now, 0, 10);
    pm_db()->prepare('UPDATE portal_links SET last_seen_at = ?, views = views + 1 WHERE id = ?')->execute([$now, $l['id']]);
    if ($firstToday) pm_event($a['actor'], (int)$a['project']['id'], 'portal_view', 'פתח את הקישור האישי', ['link' => (int)$l['id']]);
}

function pm_portal_view(array $a): array
{
    if ($a['link']['role'] === 'supplier') return pm_portal_supplier_view($a);
    $p = $a['project'];
    $role = $a['link']['role'];
    $pid = (int)$p['id'];
    $db = pm_db();

    $st = $db->prepare('SELECT s.id, s.name, s.status, s.plan_start, s.plan_end, s.needs_lawyer, c.name AS supplier
                          FROM stages s LEFT JOIN contacts c ON c.id = s.supplier_id WHERE s.project_id = ? ORDER BY s.sort, s.id');
    $st->execute([$pid]);
    $stages = array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'status' => $s['status'], 'supplier' => $s['supplier'],
        'plan_start' => $s['plan_start'], 'plan_end' => $s['plan_end'], 'needs_lawyer' => (bool)$s['needs_lawyer']], $st->fetchAll());

    // רק הצעות שנשלחו ללקוח. מבוטלות לא מוצגות.
    $q = $db->prepare("SELECT q.*, c.name AS supplier_name, c.company AS supplier_company, f.name AS file_name, f.mime AS file_mime,
                              f.thumb IS NOT NULL AS file_thumb, f.deleted_at AS file_deleted
                         FROM quotes q JOIN contacts c ON c.id = q.supplier_id LEFT JOIN files f ON f.id = q.file_id
                        WHERE q.project_id = ? AND q.sent_at IS NOT NULL AND q.status != 'cancelled' ORDER BY q.stage_id, q.amount");
    $q->execute([$pid]);
    $ap = $db->prepare('SELECT quote_id, quote_version, role, decision, method, by_name, note, created_at FROM approvals WHERE project_id = ? ORDER BY id');
    $ap->execute([$pid]);
    $decisions = [];
    foreach ($ap->fetchAll() as $d) $decisions[(int)$d['quote_id']][] = ['version' => (int)$d['quote_version'], 'role' => $d['role'],
        'decision' => $d['decision'], 'method' => $d['method'], 'by_name' => $d['by_name'], 'note' => $d['note'], 'at' => $d['created_at']];
    $stageStatus = array_column($stages, 'status', 'id');
    $quotes = array_map(function ($x) use ($role, $decisions, $stageStatus, $p) {
        $started = in_array($stageStatus[$x['stage_id']] ?? '', ['in_progress', 'done', 'paid'], true);
        $can = $role === 'client'
            ? !$started && in_array($x['status'], ['sent', 'declined', 'rejected'], true)
            : $x['status'] === 'client_approved';
        return [
            'id' => (int)$x['id'], 'stage_id' => (int)$x['stage_id'], 'supplier' => $x['supplier_name'], 'company' => $x['supplier_company'],
            'amount' => (int)$x['amount'] / 100, 'vat_rate' => pm_quote_vat($x), 'valid_until' => $x['valid_until'], 'terms' => $x['terms'], 'notes' => $x['notes'],
            'recommended' => (bool)$x['recommended'], 'status' => $x['status'], 'version' => (int)$x['version'],
            'file' => $x['file_id'] && !$x['file_deleted'] ? ['id' => (int)$x['file_id'], 'name' => $x['file_name'], 'mime' => $x['file_mime'], 'thumb' => (bool)$x['file_thumb']] : null,
            'can_decide' => $can, 'can_reject' => $can && in_array($x['status'], ['sent', 'client_approved'], true),
            'decisions' => $decisions[(int)$x['id']] ?? [],
        ];
    }, $q->fetchAll());

    $f = $db->prepare('SELECT id, name, mime, size, type, thumb IS NOT NULL AS has_thumb, created_at FROM files
                        WHERE project_id = ? AND visible = 1 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 500');
    $f->execute([$pid]);
    $files = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'mime' => $x['mime'], 'size' => (int)$x['size'],
        'type' => $x['type'], 'thumb' => (bool)$x['has_thumb'], 'at' => $x['created_at']], $f->fetchAll());

    $method = 'click';
    if ($role === 'client') $method = pm_client_settings((int)$a['contact']['id'])['approval_method'];

    // תשלומים: רק מה שכבר נשלחה עליו דרישה. פרטי הבנק — כשאפשר לשלם.
    $payments = array_map(function ($x) use ($role) {
        $x['can_pay'] = $x['payable'];
        $x['can_approve'] = $role === 'lawyer' && $x['needs_lawyer'] && !$x['lawyer'] && $x['status'] === 'requested';
        $x['bank'] = $x['payable'] ? pm_supplier_bank($x['supplier_id']) : null;
        $x['label'] = pm_t($x['label']);
        unset($x['quote_id'], $x['percent']);
        return $x;
    }, pm_payments_of($pid, true)['payments']);

    return [
        'role' => $role, 'name' => $a['contact']['name'], 'approval_method' => $method, 'lang' => pm_lang_valid($a['contact']['lang'] ?? 'he'),
        'project' => ['name' => $p['name'], 'address' => $p['address'], 'status' => $p['status'], 'kind' => $p['kind']],
        'manager' => ['name' => $a['owner']['display_name'], 'phone' => $a['owner']['phone'], 'email' => $a['owner']['email']],
        'stages' => $stages, 'quotes' => $quotes, 'files' => $files, 'payments' => $payments,
        // תוספות וחריגות (כ4) — מה שנשלח אליהם; לאישור / דחייה כמו הצעה
        'changes' => array_values(array_filter(pm_changes_of($pid, $role), fn($x) => $x['status'] !== 'cancelled')),
        'reports' => pm_reports_of($pid, true),
        // המצב הכספי (אגף הכספים): תקציב ראשוני מול היום, סוכם, שולם, יתרה והתשלום הבא — עם מע"מ
        'finance' => pm_finance_of($pid, true) + ['alerts' => array_map(fn($x) => array_diff_key($x, ['key' => 1, 'sort' => 1]),
            pm_fin_alerts($pid, pm_fin_alert_settings((int)$a['owner']['id']), pm_lang(), $role))],
        'labels' => ['stage' => pm_t_map(PM_STAGE_STATUSES), 'quote' => pm_t_map(PM_QUOTE_STATUSES), 'file' => pm_t_map(PM_FILE_TYPES), 'method' => pm_t_map(PM_PAY_METHODS)],
        'max_proof' => PM_PROOF_MAX,
    ];
}

/** אישור או דחייה מהפורטל. בחתימה — תמונת PNG (data URL) של החתימה. */
function pm_portal_decide(array $a, array $in): array
{
    $role = $a['link']['role'];
    if ($role === 'supplier') throw new PmError('לא זמין בקישור של ספק', 403);
    $id = (int)($in['quote_id'] ?? 0);
    $decision = (string)($in['decision'] ?? '');
    $view = pm_portal_view($a);
    $q = null;
    foreach ($view['quotes'] as $x) if ($x['id'] === $id) $q = $x;
    if (!$q) throw new PmError('ההצעה לא נמצאה', 404);
    if (!$q['can_decide'] || ($decision === 'rejected' && !$q['can_reject'])) throw new PmError('אי אפשר להחליט על ההצעה הזו עכשיו', 422);
    if ((int)($in['version'] ?? 0) !== $q['version']) throw new PmError('ההצעה עודכנה בינתיים — טענו את הדף מחדש ובדקו שוב', 409);

    $method = $view['approval_method'];
    $signature = '';
    if ($decision === 'approved' && $method === 'signature') {
        $signature = (string)($in['signature'] ?? '');
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $signature) || strlen($signature) > 400000) throw new PmError('נדרשת חתימה', 422);
    }
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 1000);
    pm_quote_decide($a['owner'], $id, $role, $decision, $note, $decision === 'approved' ? $method : 'click', $a['contact']['name'],
        $a['actor'], ['portal_id' => (int)$a['link']['id'], 'signature' => $signature]);
    pm_log('info', 'portal', 'portal.decide', ['data' => ['link' => (int)$a['link']['id'], 'quote' => $id, 'role' => $role, 'decision' => $decision, 'method' => $method]]);
    pm_portal_notify($a, $q, $decision, $note);
    return pm_portal_view($a);
}

/** מייל למנהל הפרויקט: הלקוח / העו"ד החליט. */
function pm_portal_notify(array $a, array $q, string $decision, string $note): void
{
    $to = pm_user_email($a['owner']);
    if ($to === '') return;
    $lang = $a['owner']['lang'];
    $v = ['who' => $a['actor']['display_name'], 'project' => $a['project']['name'], 'supplier' => $q['supplier'],
          'amount' => pm_money((int)round($q['amount'] * 100))];
    $ok = $decision === 'approved';
    $sent = pm_send_mail($to, pm_t($ok ? '{who} אישר הצעת מחיר — {project}' : '{who} דחה הצעת מחיר — {project}', $v, $lang),
        pm_t($ok ? '{who} אישר את ההצעה של {supplier} על {amount} בפרויקט {project}.' : '{who} דחה את ההצעה של {supplier} על {amount} בפרויקט {project}.', $v, $lang)
        . ($note !== '' ? "\n\n" . pm_t('הערה: {note}', ['note' => $note], $lang) : '') . "\n\n" . pm_app_url_root() . "/#/p/{$a['project']['id']}/quotes\n", $lang);
    pm_log($sent ? 'info' : 'warn', 'mail', 'mail.portal_decision', ['data' => ['to' => $to, 'sent' => $sent]]);
}

/** כתובת האפליקציה מתוך portal/api.php — תיקייה אחת למעלה. */
function pm_app_url_root(): string
{
    return preg_replace('#/portal$#', '', pm_app_url());
}

/** קובץ מהפורטל: רק "גלוי", קובץ של הצעה שנשלחה ללקוח, או אסמכתא של תשלום בפרויקט. */
function pm_portal_file(array $a, int $id, bool $thumb, bool $download): never
{
    $st = pm_db()->prepare('SELECT * FROM files WHERE id = ? AND project_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $a['project']['id']]);
    $x = $st->fetch();
    if ($x && $a['link']['role'] === 'supplier') {
        if (!pm_portal_supplier_can_file($a, $x)) throw new PmError('הקובץ לא נמצא', 404);
        pm_log('info', 'portal', 'portal.file', ['data' => ['link' => (int)$a['link']['id'], 'file' => $id, 'name' => $x['name']]]);
        pm_file_output($x, $thumb, $download);
    }
    $ok = $x && ((int)$x['visible'] === 1 || (function () use ($id, $a) {
        $q = pm_db()->prepare("SELECT 1 FROM quotes WHERE file_id = ? AND project_id = ? AND sent_at IS NOT NULL AND status != 'cancelled'
                               UNION SELECT 1 FROM payment_proofs x JOIN payments p ON p.id = x.payment_id WHERE x.file_id = ? AND p.project_id = ?");
        $q->execute([$id, $a['project']['id'], $id, $a['project']['id']]);
        return (bool)$q->fetchColumn();
    })());
    if (!$ok) throw new PmError('הקובץ לא נמצא', 404);
    if (!$thumb) pm_log('info', 'portal', 'portal.file', ['data' => ['link' => (int)$a['link']['id'], 'file' => $id, 'name' => $x['name']]]);
    pm_file_output($x, $thumb, $download);
}

// ================= תשלומים מהפורטל =================

const PM_PROOF_MAX = 8 * 1048576; // אסמכתא: צילום או PDF — תמונה מוקטנת בדפדפן לפני השליחה

function pm_portal_payment(array $a, int $id): array
{
    if ($a['link']['role'] === 'supplier') throw new PmError('לא זמין בקישור של ספק', 403);
    foreach (pm_portal_view($a)['payments'] as $x) if ($x['id'] === $id) return $x;
    throw new PmError('אבן הדרך לא נמצאה', 404);
}

/** העו"ד: אישור לתשלום. */
function pm_portal_pay_approve(array $a, array $in): array
{
    $x = pm_portal_payment($a, (int)($in['payment_id'] ?? 0));
    if (!$x['can_approve']) throw new PmError('אין כאן מה לאשר', 422);
    $note = (string)($in['note'] ?? '');
    pm_payment_lawyer_approve($a['owner'], $x['id'], $note, 'click', $a['contact']['name'], $a['actor']);
    $lang = $a['owner']['lang'];
    $v = ['who' => $a['actor']['display_name'], 'title' => $x['title'], 'supplier' => $x['supplier_name'], 'amount' => pm_money((int)round($x['amount'] * 100))];
    pm_portal_mail($a, pm_t('{who} אישר לתשלום: {title}', $v, $lang),
        pm_t('{who} אישר לתשלום את "{title}" ל{supplier} — {amount}.', $v, $lang) . ($note !== '' ? "\n\n" . pm_t('הערה: {note}', ['note' => $note], $lang) : ''));
    return pm_portal_view($a);
}

/**
 * "שילמתי": גוף הבקשה הוא הקובץ עצמו (תמונה או PDF), והפרטים בכתובת —
 * payment_id, amount, paid_on, method, note, name. הכול בבקשה אחת.
 */
function pm_portal_proof(array $a, array $q): array
{
    $x = pm_portal_payment($a, (int)($q['payment_id'] ?? 0));
    if (!$x['can_pay']) throw new PmError($x['needs_lawyer'] && !$x['lawyer'] ? 'ממתין לאישור העו"ד — אחריו אפשר לדווח תשלום' : 'אי אפשר לדווח תשלום עכשיו', 422);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) throw new PmError('נדרשת אסמכתא — צילום ההעברה, הצ\'ק או אישור הבנק', 422);
    if ($len > PM_PROOF_MAX) throw new PmError('הקובץ גדול מדי — עד 8MB', 413);
    $tmp = pm_tmp_dir() . '/proof_' . bin2hex(random_bytes(8));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $n = stream_copy_to_stream($in, $out, PM_PROOF_MAX + 1);
    fclose($in); fclose($out);
    if ($n <= 0 || $n > PM_PROOF_MAX) { @unlink($tmp); throw new PmError('הקובץ לא הגיע — נסו שוב', 422); }
    $mime = pm_detect_mime($tmp, '');
    if (!preg_match('#^(image/(jpeg|png|webp|heic|heif)|application/pdf)$#', $mime)) { @unlink($tmp); throw new PmError('אסמכתא — תמונה או PDF', 415); }
    $role = $a['link']['role'];
    // שם הקובץ והתיאור נשמרים — בשפה של מנהל הפרויקט
    $lang = $a['owner']['lang'];
    $name = trim((string)($q['name'] ?? '')) ?: pm_t('אסמכתא', [], $lang) . '.' . ($mime === 'application/pdf' ? 'pdf' : 'jpg');
    $fileId = pm_file_store((int)$a['project']['id'], $role, pm_t('אסמכתא — {title} — {name}', ['title' => $x['title'], 'name' => $name], $lang), $tmp, 'proof', 0,
        pm_t('הועלה בקישור האישי של {name}', ['name' => $a['contact']['name']], $lang));
    try {
        pm_payment_proof_add($a['owner'], $x['id'], $q, $role, $a['contact']['name'], $fileId, $a['actor'], (int)$a['link']['id']);
    } catch (Throwable $e) {
        pm_db()->prepare('UPDATE files SET deleted_at = ? WHERE id = ?')->execute([pm_now(), $fileId]);
        throw $e;
    }
    $amount = pm_quote_amount($q['amount'] ?? null);
    $v = ['who' => $a['actor']['display_name'], 'title' => $x['title'], 'supplier' => $x['supplier_name'], 'amount' => pm_money($amount)];
    pm_portal_mail($a, pm_t('{who} דיווח תשלום: {title}', $v, $lang),
        pm_t("{who} דיווח ששילם {amount} ל{supplier} עבור \"{title}\", עם אסמכתא.\nנא לאשר שהתקבל.", $v, $lang));
    pm_log('info', 'portal', 'portal.proof', ['data' => ['link' => (int)$a['link']['id'], 'payment' => $x['id'], 'file' => $fileId, 'bytes' => $n]]);
    return pm_portal_view($a);
}

function pm_portal_mail(array $a, string $subject, string $body, string $pane = 'payments'): void
{
    $to = pm_user_email($a['owner']);
    if ($to === '') return;
    $sent = pm_send_mail($to, "$subject — {$a['project']['name']}", $body . "\n\n" . pm_app_url_root() . "/#/p/{$a['project']['id']}/$pane\n",
        pm_lang_valid($a['owner']['lang'] ?? 'he'));
    pm_log($sent ? 'info' : 'warn', 'mail', 'mail.portal', ['data' => ['to' => $to, 'sent' => $sent, 'subject' => $subject]]);
}

// ================= פורטל ספק (שלב 9) =================

/** השלבים שהספק רואה: משויך אליהם, הוזמן להציע בהם, או שיש לו בהם הצעה / תשלום. */
function pm_portal_supplier_stages(array $a): array
{
    $sid = (int)$a['contact']['id'];
    $pid = (int)$a['project']['id'];
    $st = pm_db()->prepare('SELECT s.*, t.name AS trade_name,
            (s.supplier_id = :s) AS assigned,
            EXISTS (SELECT 1 FROM quote_invites i WHERE i.stage_id = s.id AND i.supplier_id = :s) AS invited
        FROM stages s LEFT JOIN trades t ON t.id = s.trade_id
        WHERE s.project_id = :p AND (s.supplier_id = :s
            OR s.id IN (SELECT stage_id FROM quote_invites WHERE supplier_id = :s)
            OR s.id IN (SELECT stage_id FROM quotes WHERE supplier_id = :s)
            OR s.id IN (SELECT stage_id FROM payments WHERE supplier_id = :s AND stage_id IS NOT NULL))
        ORDER BY s.sort, s.id');
    $st->execute([':s' => $sid, ':p' => $pid]);
    return array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'status' => $s['status'], 'trade' => $s['trade_name'],
        'plan_start' => $s['plan_start'], 'plan_end' => $s['plan_end'], 'assigned' => (bool)$s['assigned'], 'invited' => (bool)$s['invited'],
        // אפשר להגיש הצעה כל עוד השלב לא התחיל, ולא אושרה בו הצעה של ספק אחר
        'can_quote' => in_array($s['status'], ['planned', 'quotes', 'client_approval'], true) && ((bool)$s['invited'] || (bool)$s['assigned']),
    ], $st->fetchAll());
}

function pm_portal_supplier_view(array $a): array
{
    $sid = (int)$a['contact']['id'];
    $pid = (int)$a['project']['id'];
    $db = pm_db();
    $stages = pm_portal_supplier_stages($a);

    // ההצעות של הספק בלבד — בלי מחירים של אחרים
    $q = $db->prepare("SELECT q.*, f.name AS file_name, f.deleted_at AS file_deleted FROM quotes q LEFT JOIN files f ON f.id = q.file_id
                        WHERE q.project_id = ? AND q.supplier_id = ? ORDER BY q.created_at DESC");
    $q->execute([$pid, $sid]);
    $label = ['open' => 'התקבלה אצל מנהל הפרויקט', 'sent' => 'אצל הלקוח לבחירה', 'client_approved' => 'הלקוח אישר — ממתין לעו"ד', // i18n-ok
              'approved' => '✅ אושרה', 'declined' => 'לא נבחרה', 'rejected' => 'נדחתה', 'cancelled' => 'בוטלה']; // i18n-ok
    $quotes = array_map(fn($x) => ['id' => (int)$x['id'], 'stage_id' => (int)$x['stage_id'], 'amount' => (int)$x['amount'] / 100, 'vat_rate' => pm_quote_vat($x),
        'valid_until' => $x['valid_until'], 'terms' => $x['terms'], 'notes' => $x['notes'], 'status' => $x['status'], 'label' => pm_t($label[$x['status']] ?? $x['status']),
        'file' => $x['file_id'] && !$x['file_deleted'] ? ['id' => (int)$x['file_id'], 'name' => $x['file_name']] : null, 'at' => $x['created_at']], $q->fetchAll());

    // תשלומים אליו — עם האסמכתאות, ואישור "התקבל"
    $payments = array_values(array_filter(pm_payments_of($pid)['payments'], fn($p) => $p['supplier_id'] === $sid && $p['status'] !== 'cancelled'));
    $payments = array_map(function ($p) {
        foreach ($p['proofs'] as &$x) $x['can_confirm'] = $x['status'] === 'pending';
        $p['label'] = pm_t($p['label']);
        unset($p['quote_id'], $p['lawyer']);
        return $p;
    }, $payments);

    // קבצים: תוכניות שסומנו "גלוי", ומה שבתיקייה של הספק
    $f = $db->prepare("SELECT DISTINCT x.id, x.name, x.mime, x.size, x.type, x.thumb IS NOT NULL AS has_thumb, x.created_at
                         FROM files x JOIN file_links l ON l.file_id = x.id JOIN folders d ON d.id = l.folder_id
                        WHERE x.project_id = ? AND x.deleted_at IS NULL
                          AND ((d.kind = 'plans' AND x.visible = 1) OR (d.kind = 'contact' AND d.contact_id = ?))
                        ORDER BY x.created_at DESC LIMIT 300");
    $f->execute([$pid, $sid]);
    $files = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'mime' => $x['mime'], 'size' => (int)$x['size'],
        'type' => $x['type'], 'thumb' => (bool)$x['has_thumb'], 'at' => $x['created_at']], $f->fetchAll());

    return [
        'role' => 'supplier', 'name' => $a['contact']['name'], 'lang' => pm_lang_valid($a['contact']['lang'] ?? 'he'),
        'project' => ['name' => $a['project']['name'], 'address' => $a['project']['address'], 'status' => $a['project']['status']],
        'manager' => ['name' => $a['owner']['display_name'], 'phone' => $a['owner']['phone'], 'email' => $a['owner']['email']],
        'stages' => $stages, 'my_quotes' => $quotes, 'payments' => $payments, 'files' => $files,
        // כ4: תוספות שאושרו לו, וחשבוניות שחסרות על תשלומים שהתקבלו
        'changes' => array_values(array_filter(pm_changes_of($pid), fn($x) => $x['supplier_id'] === $sid && $x['status'] === 'approved')),
        'bank' => pm_supplier_bank($sid)['bank'], 'quotes' => [], 'reports' => [],
        'labels' => ['stage' => pm_t_map(PM_STAGE_STATUSES), 'file' => pm_t_map(PM_FILE_TYPES), 'method' => pm_t_map(PM_PAY_METHODS)],
        'max_proof' => PM_PROOF_MAX,
    ];
}

/** ספק רואה: תוכניות גלויות, התיקייה שלו, הקבצים של ההצעות / התשלומים / האסמכתאות שלו. */
function pm_portal_supplier_can_file(array $a, array $x): bool
{
    $sid = (int)$a['contact']['id'];
    $id = (int)$x['id'];
    $st = pm_db()->prepare("SELECT 1 FROM file_links l JOIN folders d ON d.id = l.folder_id JOIN files f ON f.id = l.file_id
                             WHERE l.file_id = ? AND ((d.kind = 'plans' AND f.visible = 1) OR (d.kind = 'contact' AND d.contact_id = ?))
                            UNION SELECT 1 FROM quotes WHERE file_id = ? AND supplier_id = ?
                            UNION SELECT 1 FROM payments WHERE file_id = ? AND supplier_id = ?
                            UNION SELECT 1 FROM payment_proofs pp JOIN payments p ON p.id = pp.payment_id WHERE pp.file_id = ? AND p.supplier_id = ?");
    $st->execute([$id, $sid, $id, $sid, $id, $sid, $id, $sid]);
    return (bool)$st->fetchColumn();
}

/** קובץ שהספק שלח בגוף הבקשה (אופציונלי) — לתיקייה שלו בפרויקט. null כשאין. */
function pm_portal_supplier_upload(array $a, string $label, string $type): ?int
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) return null;
    if ($len > PM_PROOF_MAX) throw new PmError('הקובץ גדול מדי — עד 8MB', 413);
    $tmp = pm_tmp_dir() . '/sup_' . bin2hex(random_bytes(8));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $n = stream_copy_to_stream($in, $out, PM_PROOF_MAX + 1);
    fclose($in); fclose($out);
    if ($n <= 0 || $n > PM_PROOF_MAX) { @unlink($tmp); throw new PmError('הקובץ לא הגיע — נסו שוב', 422); }
    $mime = pm_detect_mime($tmp, '');
    if (!preg_match('#^(image/(jpeg|png|webp|heic|heif)|application/pdf)$#', $mime)) { @unlink($tmp); throw new PmError('קובץ — תמונה או PDF', 415); }
    // שם הקובץ והתיאור נשמרים — בשפה של מנהל הפרויקט ($label כבר בשפה הזו)
    $lang = $a['owner']['lang'];
    $name = trim((string)($_GET['name'] ?? '')) ?: ($mime === 'application/pdf' ? pm_t('מסמך', [], $lang) . '.pdf' : pm_t('תמונה', [], $lang) . '.jpg');
    return pm_file_store((int)$a['project']['id'], 'contact', "$label — $name", $tmp, $type, 0,
        pm_t('הועלה בקישור האישי של {name}', ['name' => $a['contact']['name']], $lang), (int)$a['contact']['id']);
}

/** הספק מגיש הצעת מחיר. הפרטים ב-query, הקובץ (אם יש) בגוף הבקשה. */
function pm_portal_supplier_quote(array $a, array $q): array
{
    $stageId = (int)($q['stage_id'] ?? 0);
    $stage = null;
    foreach (pm_portal_supplier_stages($a) as $s) if ($s['id'] === $stageId) $stage = $s;
    if (!$stage || !$stage['can_quote']) throw new PmError('אי אפשר להגיש הצעה לשלב הזה עכשיו', 422);
    pm_quote_amount($q['amount'] ?? null); // בדיקה לפני שמירת הקובץ
    $lang = $a['owner']['lang'];
    $fileId = pm_portal_supplier_upload($a, pm_t('הצעת מחיר — {stage}', ['stage' => $stage['name']], $lang), 'quote');
    $r = pm_quote_save($a['owner'], ['stage_id' => $stageId, 'supplier_id' => (int)$a['contact']['id'], 'amount' => $q['amount'] ?? null,
        'valid_until' => $q['valid_until'] ?? '', 'terms' => $q['terms'] ?? '', 'notes' => $q['notes'] ?? '', 'file_id' => $fileId], $a['actor']);
    $v = ['name' => $a['contact']['name'], 'stage' => $stage['name'], 'amount' => pm_money(pm_quote_amount($q['amount']))];
    pm_portal_mail($a, pm_t('הצעת מחיר חדשה מ{name}: {stage}', $v, $lang),
        pm_t('{name} הגיש הצעת מחיר לשלב "{stage}": {amount} כולל מע"מ.', $v, $lang), 'quotes');
    pm_log('info', 'portal', 'portal.supplier_quote', ['data' => ['link' => (int)$a['link']['id'], 'quote' => $r['saved'], 'file' => $fileId]]);
    return pm_portal_view($a);
}

/** הספק מבקש תשלום — נכנס למנהל כאבן דרך "מתוכנן" עם סימון "בקשת הספק"; המנהל שולח ללקוח. */
function pm_portal_supplier_payreq(array $a, array $q): array
{
    $stageId = (int)($q['stage_id'] ?? 0) ?: null;
    if ($stageId) {
        $ok = false;
        foreach (pm_portal_supplier_stages($a) as $s) if ($s['id'] === $stageId) $ok = true;
        if (!$ok) throw new PmError('השלב לא נמצא', 422);
    }
    pm_quote_amount($q['amount'] ?? null);
    $title = trim((string)($q['title'] ?? ''));
    if ($title === '') throw new PmError('על מה התשלום? (למשל "מקדמה")', 422);
    $lang = $a['owner']['lang'];
    $fileId = pm_portal_supplier_upload($a, pm_t('דרישת תשלום — {title}', ['title' => $title], $lang), 'invoice');
    $r = pm_payment_save($a['owner'], ['project_id' => (int)$a['project']['id'], 'stage_id' => $stageId, 'supplier_id' => (int)$a['contact']['id'],
        'title' => $title, 'amount' => $q['amount'], 'notes' => $q['notes'] ?? '', 'payer' => 'client',
        'needs_lawyer' => (bool)$a['project']['lawyer_id']], $a['actor']);
    pm_db()->prepare('UPDATE payments SET supplier_request = 1, file_id = ? WHERE id = ?')->execute([$fileId, $r['saved']]);
    $v = ['name' => $a['contact']['name'], 'title' => $title, 'amount' => pm_money(pm_quote_amount($q['amount']))];
    pm_portal_mail($a, pm_t('{name} ביקש תשלום: {title}', $v, $lang),
        pm_t("{name} ביקש תשלום \"{title}\": {amount}.\nבלשונית התשלומים — לבדוק ולשלוח ללקוח.", $v, $lang), 'payments');
    return pm_portal_view($a);
}

/** החלטה על תוספת / חריגה מהפורטל — כמו הצעה: בלחיצה או בחתימה (לפי הגדרות הלקוח). */
function pm_portal_change_decide(array $a, array $in): array
{
    $role = $a['link']['role'];
    if ($role === 'supplier') throw new PmError('לא זמין בקישור של ספק', 403);
    $id = (int)($in['change_id'] ?? 0);
    $decision = (string)($in['decision'] ?? '');
    $view = pm_portal_view($a);
    $o = null;
    foreach ($view['changes'] as $x) if ($x['id'] === $id) $o = $x;
    if (!$o || !$o['can_decide']) throw new PmError('אי אפשר להחליט על התוספת הזו עכשיו', 422);
    $method = $view['approval_method'];
    $signature = '';
    if ($decision === 'approved' && $method === 'signature') {
        $signature = (string)($in['signature'] ?? '');
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $signature) || strlen($signature) > 400000) throw new PmError('נדרשת חתימה', 422);
    }
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 1000);
    pm_change_decide($a['owner'], $id, $role, $decision, $note, $decision === 'approved' ? $method : 'click', $a['contact']['name'],
        $a['actor'], ['portal_id' => (int)$a['link']['id'], 'signature' => $signature]);
    pm_log('info', 'portal', 'portal.change_decide', ['data' => ['link' => (int)$a['link']['id'], 'change' => $id, 'role' => $role, 'decision' => $decision]]);
    $lang = $a['owner']['lang'];
    $v = ['who' => $a['contact']['name'], 'supplier' => $o['supplier'], 'stage' => $o['stage'], 'amount' => pm_money((int)round($o['amount'] * 100))];
    pm_portal_mail($a, pm_t($decision === 'approved' ? '{who} אישר תוספת: {supplier}, {stage}' : '{who} דחה תוספת: {supplier}, {stage}', $v, $lang),
        pm_t($decision === 'approved' ? '{who} אישר תוספת של {supplier} בשלב {stage}: {amount}.' : '{who} דחה תוספת של {supplier} בשלב {stage}: {amount}.', $v, $lang)
        . ($note !== '' ? "\n\n" . pm_t('הערה: {note}', ['note' => $note], $lang) : ''), 'payments');
    return pm_portal_view($a);
}

/** הספק מעלה חשבונית על תשלום שהתקבל: ?payment_id=…&no=… — הקובץ בגוף הבקשה. */
function pm_portal_supplier_invoice(array $a, array $q): array
{
    $pid = (int)($q['payment_id'] ?? 0);
    $st = pm_db()->prepare("SELECT title FROM payments WHERE id = ? AND supplier_id = ? AND project_id = ? AND status != 'cancelled'");
    $st->execute([$pid, $a['contact']['id'], $a['project']['id']]);
    $title = $st->fetchColumn();
    if ($title === false) throw new PmError('התשלום לא נמצא', 422);
    $lang = $a['owner']['lang'];
    $fileId = pm_portal_supplier_upload($a, pm_t('חשבונית — {title}', ['title' => $title], $lang), 'invoice');
    pm_payment_invoice($a['owner'], ['id' => $pid, 'file_id' => $fileId, 'invoice_no' => (string)($q['no'] ?? '')], $a['actor']);
    $v = ['name' => $a['contact']['name'], 'title' => $title];
    pm_portal_mail($a, pm_t('{name} העלה חשבונית: {title}', $v, $lang), pm_t('{name} העלה חשבונית על "{title}".', $v, $lang), 'payments');
    return pm_portal_view($a);
}

/** הספק: הכסף התקבל (או לא) — על אסמכתא שהלקוח העלה. */
function pm_portal_supplier_receipt(array $a, array $in): array
{
    $proofId = (int)($in['proof_id'] ?? 0);
    $st = pm_db()->prepare("SELECT 1 FROM payment_proofs x JOIN payments p ON p.id = x.payment_id WHERE x.id = ? AND p.supplier_id = ? AND p.project_id = ? AND x.status = 'pending'");
    $st->execute([$proofId, $a['contact']['id'], $a['project']['id']]);
    if (!$st->fetchColumn()) throw new PmError('אין כאן מה לאשר', 422);
    pm_payment_proof_review($a['owner'], $proofId, !empty($in['ok']), $in['received'] ?? null, (string)($in['note'] ?? ''), $a['actor']);
    $lang = $a['owner']['lang'];
    $v = ['name' => $a['contact']['name']];
    $note = (string)($in['note'] ?? '');
    pm_portal_mail($a, pm_t(!empty($in['ok']) ? '{name} אישר שהתשלום התקבל' : '{name} דיווח שהתשלום לא התקבל', $v, $lang),
        pm_t(!empty($in['ok']) ? '{name} אישר שהתשלום התקבל.' : '{name} דיווח שהתשלום לא התקבל.', $v, $lang)
        . ($note !== '' ? "\n\n" . pm_t('הערה: {note}', ['note' => $note], $lang) : ''), 'payments');
    return pm_portal_view($a);
}

/** הספק מעדכן את פרטי הבנק שלו — מה שהלקוח רואה בתשלום. */
function pm_portal_supplier_bank(array $a, array $in): array
{
    $bank = [];
    foreach (PM_BANK_FIELDS as $f) $bank[$f] = pm_str($in, $f, 60, false, 'פרטי בנק');
    pm_db()->prepare('UPDATE contacts SET bank_json = ?, updated_at = ? WHERE id = ?')
       ->execute([json_encode(array_filter($bank, 'strlen'), JSON_UNESCAPED_UNICODE), pm_now(), $a['contact']['id']]);
    pm_audit($a['actor']['actor'], 'contact.bank', 'contact', (int)$a['contact']['id'], ['by' => 'portal']);
    pm_event($a['actor'], (int)$a['project']['id'], 'portal', 'עדכן את פרטי הבנק שלו');
    return pm_portal_view($a);
}

/**
 * "📨 בקשת הצעות": ספקים לשלב — כל אחד מקבל את הקישור האישי שלו, ורואה בו את השלב
 * כ"מבקשים ממך הצעה". מחזיר לכל ספק את הקישור, להודעה (וואטסאפ / SMS / מייל).
 */
function pm_quote_invite(array $user, int $stageId, array $supplierIds): array
{
    $stage = pm_stage_row($user, $stageId);
    $p = pm_project_row($user, (int)$stage['project_id']);
    $ids = array_values(array_unique(array_filter(array_map('intval', $supplierIds))));
    if (!$ids) throw new PmError('בחרו ספק אחד לפחות', 422);
    $out = [];
    $names = [];
    foreach ($ids as $sid) {
        pm_owned_contact($sid, (int)$p['owner_id'], 'supplier', 'הספק'); // i18n-ok
        pm_db()->prepare('INSERT OR IGNORE INTO quote_invites (project_id, stage_id, supplier_id, created_at) VALUES (?,?,?,?)')
           ->execute([$p['id'], $stageId, $sid, pm_now()]);
        $link = pm_portal_link_save($user, (int)$p['id'], $sid, 'get')['link'];
        $c = pm_db()->prepare('SELECT id, name, company, phone, whatsapp, email FROM contacts WHERE id = ?');
        $c->execute([$sid]);
        $contact = $c->fetch();
        $names[] = $contact['name'];
        $out[] = ['contact' => ['id' => (int)$contact['id']] + $contact, 'url' => $link['url']];
    }
    pm_event($user, (int)$p['id'], 'quote', '📨 בקשת הצעת מחיר — {stage}: {names}', ['stage' => $stageId], ['stage' => $stage['name'], 'names' => implode(', ', $names)]);
    foreach ($out as $x) {
        // i18n-none-start — שמות המשתנים בנוסח ההודעה
        pm_notify((int)$p['id'], (int)$x['contact']['id'], 'quote_invite', ['שלב' => $stage['name'], 'כתובת' => $p['address'] ? " ({$p['address']})" : '',
            'קישור' => $x['url']], $user);
        // i18n-none-end
    }
    if ($stage['status'] === 'planned') pm_stage_set_status($user, $stage, 'quotes');
    return ['invites' => $out];
}
