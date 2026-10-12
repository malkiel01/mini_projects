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

require_once __DIR__ . '/payments.php';
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

/** תפקיד איש הקשר בפרויקט — client · lawyer — או חריגה. */
function pm_portal_role(array $p, int $contactId): string
{
    if ($contactId && (int)$p['client_id'] === $contactId) return 'client';
    if ($contactId && (int)$p['lawyer_id'] === $contactId) return 'lawyer';
    throw new PmError('קישור אישי — רק ללקוח או לעו"ד של הפרויקט', 422);
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
    $who = $role === 'client' ? 'ללקוח' : 'לעו"ד';
    if ($do === 'revoke') {
        if ($cur) {
            $db->prepare('UPDATE portal_links SET revoked_at = ? WHERE id = ?')->execute([pm_now(), $cur['id']]);
            pm_event($user, $projectId, 'portal', "בוטל הקישור האישי $who: $name", ['contact' => $contactId]);
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
        pm_event($user, $projectId, 'portal', ($cur ? 'נוצר קישור אישי חדש ' : 'נוצר קישור אישי ') . "$who: $name" . ($cur ? ' (הקודם בוטל)' : ''),
            ['contact' => $contactId]);
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
    $col = $l['role'] === 'client' ? 'client_id' : 'lawyer_id';
    if ((int)$project[$col] !== (int)$l['contact_id']) $bad('איש הקשר כבר לא ' . ($l['role'] === 'client' ? 'הלקוח' : 'העו"ד') . ' של הפרויקט');
    $c = pm_db()->prepare('SELECT * FROM contacts WHERE id = ?');
    $c->execute([$l['contact_id']]);
    $contact = $c->fetch();
    $u = pm_db()->prepare('SELECT id, display_name, phone, email, role FROM users WHERE id = ?');
    $u->execute([$project['owner_id']]);
    $owner = $u->fetch() ?: ['id' => (int)$project['owner_id'], 'display_name' => '', 'phone' => '', 'email' => '', 'role' => 'manager'];
    return ['link' => $l, 'project' => $project, 'contact' => $contact, 'owner' => ['id' => (int)$owner['id']] + $owner,
            // מי שמופיע בציר הזמן כשהלקוח / העו"ד פועל
            'actor' => ['id' => 0, 'display_name' => $contact['name'] . ($l['role'] === 'client' ? ' (לקוח)' : ' (עו"ד)'),
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
            'amount' => (int)$x['amount'] / 100, 'valid_until' => $x['valid_until'], 'terms' => $x['terms'], 'notes' => $x['notes'],
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
        unset($x['quote_id'], $x['percent']);
        return $x;
    }, pm_payments_of($pid, true)['payments']);

    return [
        'role' => $role, 'name' => $a['contact']['name'], 'approval_method' => $method,
        'project' => ['name' => $p['name'], 'address' => $p['address'], 'status' => $p['status'], 'kind' => $p['kind']],
        'manager' => ['name' => $a['owner']['display_name'], 'phone' => $a['owner']['phone'], 'email' => $a['owner']['email']],
        'stages' => $stages, 'quotes' => $quotes, 'files' => $files, 'payments' => $payments,
        'labels' => ['stage' => PM_STAGE_STATUSES, 'quote' => PM_QUOTE_STATUSES, 'file' => PM_FILE_TYPES, 'method' => PM_PAY_METHODS],
        'max_proof' => PM_PROOF_MAX,
    ];
}

/** אישור או דחייה מהפורטל. בחתימה — תמונת PNG (data URL) של החתימה. */
function pm_portal_decide(array $a, array $in): array
{
    $role = $a['link']['role'];
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
    $who = $a['actor']['display_name'];
    $what = $decision === 'approved' ? 'אישר' : 'דחה';
    $sent = pm_send_mail($to, "$who $what הצעת מחיר — {$a['project']['name']}",
        "$who $what את ההצעה של {$q['supplier']} על " . pm_money((int)round($q['amount'] * 100)) . " בפרויקט {$a['project']['name']}."
        . ($note !== '' ? "\n\nהערה: $note" : '') . "\n\n" . pm_app_url_root() . "/#/p/{$a['project']['id']}/quotes\n");
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
    pm_portal_mail($a, "{$a['actor']['display_name']} אישר לתשלום: {$x['title']}",
        "{$a['actor']['display_name']} אישר לתשלום את \"{$x['title']}\" ל{$x['supplier_name']} — " . pm_money((int)round($x['amount'] * 100)) . '.' . ($note !== '' ? "\n\nהערה: $note" : ''));
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
    $name = trim((string)($q['name'] ?? '')) ?: 'אסמכתא.' . ($mime === 'application/pdf' ? 'pdf' : 'jpg');
    $fileId = pm_file_store((int)$a['project']['id'], $role, 'אסמכתא — ' . $x['title'] . ' — ' . $name, $tmp, 'proof', 0,
        'הועלה בקישור האישי של ' . $a['contact']['name']);
    try {
        pm_payment_proof_add($a['owner'], $x['id'], $q, $role, $a['contact']['name'], $fileId, $a['actor'], (int)$a['link']['id']);
    } catch (Throwable $e) {
        pm_db()->prepare('UPDATE files SET deleted_at = ? WHERE id = ?')->execute([pm_now(), $fileId]);
        throw $e;
    }
    $amount = pm_quote_amount($q['amount'] ?? null);
    pm_portal_mail($a, "{$a['actor']['display_name']} דיווח תשלום: {$x['title']}",
        "{$a['actor']['display_name']} דיווח ששילם " . pm_money($amount) . " ל{$x['supplier_name']} עבור \"{$x['title']}\", עם אסמכתא.\nנא לאשר שהתקבל.");
    pm_log('info', 'portal', 'portal.proof', ['data' => ['link' => (int)$a['link']['id'], 'payment' => $x['id'], 'file' => $fileId, 'bytes' => $n]]);
    return pm_portal_view($a);
}

function pm_portal_mail(array $a, string $subject, string $body): void
{
    $to = pm_user_email($a['owner']);
    if ($to === '') return;
    $sent = pm_send_mail($to, "$subject — {$a['project']['name']}", $body . "\n\n" . pm_app_url_root() . "/#/p/{$a['project']['id']}/payments\n");
    pm_log($sent ? 'info' : 'warn', 'mail', 'mail.portal', ['data' => ['to' => $to, 'sent' => $sent, 'subject' => $subject]]);
}
