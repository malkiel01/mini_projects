<?php
/**
 * הצעות מחיר — שלב 5 (README, "הצעת מחיר").
 *
 * הצעה שייכת לשלב ולספק: סכום כולל מע"מ, תוקף, תנאי תשלום וקובץ (מתיקיות הפרויקט).
 * המסלול:
 *
 *   open (התקבלה) → sent (נשלחה ללקוח) → approved (אושרה)
 *                                       → client_approved → approved   (כשהשלב דורש גם עו"ד)
 *                                       → rejected (נדחתה)
 *   כשהצעה אחת אושרה — האחרות של אותו שלב: declined (לא נבחרה).
 *   ביטול: cancelled. שום הצעה לא נמחקת.
 *
 * כל אישור או דחייה נרשם ב-approvals עם צילום מצב של ההצעה. שינוי מהותי בהצעה
 * שכבר אושרה (סכום, קובץ, תנאים, תוקף) מעלה את הגרסה ומחזיר אותה ל"התקבלה" —
 * האישור הישן נשאר בהיסטוריה ולא עובר לגרסה החדשה.
 *
 * בשלב הזה המנהל רושם את ההחלטה ("הלקוח אישר בטלפון"); בשלב 6 הלקוח והעו"ד
 * יאשרו בעצמם מהפורטל — לאותה טבלה, עם method = click · signature.
 */

declare(strict_types=1);

require_once __DIR__ . '/files.php';

const PM_QUOTE_STATUSES = [
    'open'            => 'התקבלה',
    'sent'            => 'נשלחה ללקוח',
    'client_approved' => 'הלקוח אישר — ממתין לעו"ד',
    'approved'        => 'אושרה',
    'declined'        => 'לא נבחרה',
    'rejected'        => 'נדחתה',
    'cancelled'       => 'בוטלה',
];
/** הצעות "חיות" — שעוד אפשר לבחור ביניהן. */
const PM_QUOTE_LIVE = ['open', 'sent', 'client_approved'];

function pm_quote_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM quotes WHERE id = ?');
    $st->execute([$id]);
    $q = $st->fetch();
    if (!$q) throw new PmError('ההצעה לא נמצאה', 404);
    pm_project_row($user, (int)$q['project_id']);
    return $q;
}

/** "₪45,000" — לאירועים בציר הזמן. */
function pm_money(int $agorot): string
{
    $v = $agorot / 100;
    return '₪' . number_format($v, fmod($v, 1.0) == 0.0 ? 0 : 2);
}

/** כל ההצעות של הפרויקט, עם ההחלטות על כל אחת, וסיכום מול התקציב. */
function pm_quotes(array $user, int $projectId): array
{
    $p = pm_project_row($user, $projectId);
    $db = pm_db();
    $st = $db->prepare(
        'SELECT q.*, c.name AS supplier_name, c.company AS supplier_company, c.phone AS supplier_phone,
                f.name AS file_name, f.mime AS file_mime, f.thumb IS NOT NULL AS file_thumb, f.deleted_at AS file_deleted,
                u.display_name AS creator
           FROM quotes q
           JOIN contacts c ON c.id = q.supplier_id
           LEFT JOIN files f ON f.id = q.file_id
           LEFT JOIN users u ON u.id = q.created_by
          WHERE q.project_id = ? ORDER BY q.stage_id, q.amount, q.id'
    );
    $st->execute([$projectId]);
    $rows = $st->fetchAll();

    $ap = $db->prepare('SELECT id, quote_id, quote_version, role, decision, method, by_name, note, created_at, recorded_by,
                               json_extract(snapshot_json, \'$.amount\') AS amount, signature != \'\' AS signed
                          FROM approvals WHERE project_id = ? ORDER BY id');
    $ap->execute([$projectId]);
    $decisions = [];
    foreach ($ap->fetchAll() as $a) {
        $decisions[(int)$a['quote_id']][] = [
            'id' => (int)$a['id'], 'version' => (int)$a['quote_version'], 'role' => $a['role'], 'decision' => $a['decision'],
            'method' => $a['method'], 'by_name' => $a['by_name'], 'note' => $a['note'], 'at' => $a['created_at'],
            'amount' => (float)$a['amount'], 'signed' => (bool)$a['signed'],
        ];
    }

    $quotes = array_map(fn($q) => [
        'id' => (int)$q['id'], 'stage_id' => (int)$q['stage_id'], 'supplier_id' => (int)$q['supplier_id'],
        'supplier_name' => $q['supplier_name'], 'supplier_company' => $q['supplier_company'], 'supplier_phone' => $q['supplier_phone'],
        'amount' => (int)$q['amount'] / 100, 'valid_until' => $q['valid_until'], 'terms' => $q['terms'], 'notes' => $q['notes'],
        'file_id' => $q['file_id'] !== null && !$q['file_deleted'] ? (int)$q['file_id'] : null,
        'file_name' => $q['file_deleted'] ? null : $q['file_name'], 'file_mime' => $q['file_mime'], 'file_thumb' => (bool)$q['file_thumb'],
        'recommended' => (bool)$q['recommended'], 'status' => $q['status'], 'version' => (int)$q['version'],
        'creator' => $q['creator'], 'created_at' => $q['created_at'], 'updated_at' => $q['updated_at'], 'sent_at' => $q['sent_at'],
        'decisions' => $decisions[(int)$q['id']] ?? [],
    ], $rows);

    $approved = array_sum(array_map(fn($q) => $q['status'] === 'approved' ? $q['amount'] : 0, $quotes));
    return [
        'quotes'  => $quotes,
        'summary' => ['budget' => (int)$p['budget'], 'approved' => $approved,
                      'open' => count(array_filter($quotes, fn($q) => in_array($q['status'], PM_QUOTE_LIVE, true)))],
        'statuses' => PM_QUOTE_STATUSES,
    ];
}

function pm_quote_amount($v): int
{
    if (is_string($v)) $v = str_replace([',', '₪', ' '], '', $v);
    if (!is_numeric($v) || $v <= 0 || $v > 1e9) throw new PmError('סכום: מספר חיובי (כולל מע"מ)', 422);
    return (int)round((float)$v * 100);
}

/** שלב שמתקדם לבד: קיבל הצעה ראשונה → "איסוף הצעות". */
function pm_stage_set_status(array $user, array $stage, string $status, string $why = ''): void
{
    if ($stage['status'] === $status) return;
    pm_db()->prepare('UPDATE stages SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, pm_now(), $stage['id']]);
    pm_event($user, (int)$stage['project_id'], 'stage_status', "{$stage['name']}: " . PM_STAGE_STATUSES[$status] . ($why ? " ($why)" : ''),
        ['stage' => (int)$stage['id'], 'status' => $status]);
}

function pm_quote_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? pm_quote_row($user, $id) : null;
    if ($old && $old['status'] === 'cancelled') throw new PmError('ההצעה בוטלה — אי אפשר לערוך', 422);
    $stage = pm_stage_row($user, (int)($in['stage_id'] ?? ($old['stage_id'] ?? 0)));
    $projectId = (int)$stage['project_id'];
    if ($old && (int)$old['project_id'] !== $projectId) throw new PmError('אי אפשר להעביר הצעה לפרויקט אחר', 422);
    $p = pm_project_row($user, $projectId);

    $fileId = (int)($in['file_id'] ?? 0) ?: null;
    if ($fileId) {
        $file = pm_file_row($user, $fileId);
        if ((int)$file['project_id'] !== $projectId) throw new PmError('הקובץ לא שייך לפרויקט', 422);
    }
    $f = [
        'stage_id'    => (int)$stage['id'],
        'supplier_id' => pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק')
                         ?? throw new PmError('ספק: שדה חובה', 422),
        'amount'      => pm_quote_amount($in['amount'] ?? null),
        'valid_until' => pm_date($in, 'valid_until', 'בתוקף עד'),
        'terms'       => pm_str($in, 'terms', 1000, false, 'תנאי תשלום'),
        'notes'       => pm_str($in, 'notes', 2000, false, 'הערות'),
        'file_id'     => $fileId,
    ];
    $supplier = pm_contact_name($f['supplier_id']);
    $now = pm_now();
    $db = pm_db();
    $db->beginTransaction();
    try {
        if ($old) {
            // שינוי מהותי — גרסה חדשה. הצעה שכבר אושרה חוזרת ל"התקבלה", והשלב לאיסוף הצעות.
            $material = array_filter(['amount', 'valid_until', 'terms', 'file_id', 'supplier_id', 'stage_id'],
                fn($k) => (string)$f[$k] !== (string)$old[$k]);
            $version = (int)$old['version'] + ($material ? 1 : 0);
            $status = $old['status'];
            $reset = $material && in_array($status, ['sent', 'client_approved', 'approved', 'rejected', 'declined'], true);
            if ($reset) $status = 'open';
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $db->prepare("UPDATE quotes SET $set, version = ?, status = ?, updated_at = ? WHERE id = ?")
               ->execute(array_merge(array_values($f), [$version, $status, $now, $id]));
            if ($material) {
                $what = ['amount' => 'סכום', 'valid_until' => 'תוקף', 'terms' => 'תנאים', 'file_id' => 'קובץ', 'supplier_id' => 'ספק', 'stage_id' => 'שלב'];
                $text = "הצעה עודכנה: $supplier, {$stage['name']} — " . implode(', ', array_map(fn($k) => $what[$k], $material))
                      . ($old['amount'] != $f['amount'] ? ' (' . pm_money((int)$old['amount']) . ' → ' . pm_money($f['amount']) . ')' : '')
                      . ($reset ? '. האישור הקודם לא חל על הגרסה החדשה' : '');
                pm_event($user, $projectId, 'quote', $text, ['quote' => $id, 'version' => $version, 'changed' => array_values($material)]);
                if ($reset && in_array($old['status'], ['client_approved', 'approved'], true)
                    && in_array($stage['status'], ['client_approval', 'lawyer_approval', 'approved'], true)) {
                    pm_stage_set_status($user, $stage, 'quotes', 'ההצעה שאושרה השתנתה');
                }
            }
        } else {
            $cols = implode(', ', array_keys($f));
            $qs = implode(', ', array_fill(0, count($f), '?'));
            $db->prepare("INSERT INTO quotes ($cols, project_id, created_by, created_at, updated_at) VALUES ($qs, ?, ?, ?, ?)")
               ->execute(array_merge(array_values($f), [$projectId, $user['id'], $now, $now]));
            $id = (int)$db->lastInsertId();
            pm_event($user, $projectId, 'quote', "הצעת מחיר: $supplier, {$stage['name']} — " . pm_money($f['amount']),
                ['quote' => $id, 'stage' => (int)$stage['id']]);
            if ($stage['status'] === 'planned') pm_stage_set_status($user, $stage, 'quotes');
        }
        if ($fileId) pm_quote_file_home($projectId, $fileId, $f['supplier_id']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_quotes($user, $projectId) + ['saved' => $id];
}

/**
 * הקובץ של ההצעה מסומן "הצעת מחיר", ואם הוא עוד ב"ממתין למיון" — עובר לתיקיית
 * הספק. קובץ שכבר מוין לא זז.
 */
function pm_quote_file_home(int $projectId, int $fileId, int $supplierId): void
{
    $db = pm_db();
    $db->prepare("UPDATE files SET type = 'quote' WHERE id = ? AND type IN ('other', 'photo')")->execute([$fileId]);
    pm_ensure_folders(['id' => $projectId]);
    $home = $db->prepare('SELECT l.folder_id, f.kind, f.parent_id FROM file_links l JOIN folders f ON f.id = l.folder_id WHERE l.file_id = ? AND l.is_home = 1');
    $home->execute([$fileId]);
    $h = $home->fetch();
    if (!$h || $h['kind'] !== 'inbox' || $h['parent_id'] !== null) return;
    $to = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'contact' AND contact_id = ? AND parent_id IS NULL");
    $to->execute([$projectId, $supplierId]);
    $target = $to->fetchColumn();
    if (!$target) return;
    $db->prepare('DELETE FROM file_links WHERE file_id = ? AND folder_id = ?')->execute([$fileId, $target]);
    $db->prepare('UPDATE file_links SET folder_id = ? WHERE file_id = ? AND is_home = 1')->execute([$target, $fileId]);
}

/** ההצעה המומלצת — אחת לשלב. מה שהלקוח יראה מודגש בראש. */
function pm_quote_recommend(array $user, int $id, bool $on): array
{
    $q = pm_quote_row($user, $id);
    if ($on && !in_array($q['status'], PM_QUOTE_LIVE, true)) throw new PmError('אפשר להמליץ רק על הצעה פתוחה', 422);
    $db = pm_db();
    if ($on) $db->prepare('UPDATE quotes SET recommended = 0 WHERE stage_id = ?')->execute([$q['stage_id']]);
    $db->prepare('UPDATE quotes SET recommended = ?, updated_at = ? WHERE id = ?')->execute([$on ? 1 : 0, pm_now(), $id]);
    $stage = pm_stage_row($user, (int)$q['stage_id']);
    pm_event($user, (int)$q['project_id'], 'quote', ($on ? 'הצעה מומלצת: ' : 'בוטלה ההמלצה: ') . pm_contact_name((int)$q['supplier_id']) . ", {$stage['name']}",
        ['quote' => $id]);
    return pm_quotes($user, (int)$q['project_id']);
}

/** "נשלח ללקוח": ההצעות הפתוחות של השלב מסומנות, והשלב ממתין ללקוח. */
function pm_quotes_send(array $user, int $stageId): array
{
    $stage = pm_stage_row($user, $stageId);
    $db = pm_db();
    $st = $db->prepare("SELECT COUNT(*) FROM quotes WHERE stage_id = ? AND status IN ('open','sent')");
    $st->execute([$stageId]);
    $n = (int)$st->fetchColumn();
    if (!$n) throw new PmError('אין הצעות פתוחות בשלב הזה', 422);
    $db->prepare("UPDATE quotes SET status = 'sent', sent_at = ?, updated_at = ? WHERE stage_id = ? AND status IN ('open','sent')")
       ->execute([pm_now(), pm_now(), $stageId]);
    pm_event($user, (int)$stage['project_id'], 'quote', "נשלח ללקוח לאישור: {$stage['name']} ($n " . ($n > 1 ? 'הצעות' : 'הצעה') . ')', ['stage' => $stageId]);
    if (in_array($stage['status'], ['planned', 'quotes'], true)) pm_stage_set_status($user, $stage, 'client_approval');
    return pm_quotes($user, (int)$stage['project_id']);
}

/** צילום מצב של ההצעה — מה בדיוק אושר. */
function pm_quote_snapshot(array $q): array
{
    $file = null;
    if ($q['file_id']) {
        $st = pm_db()->prepare('SELECT name, size, created_at FROM files WHERE id = ?');
        $st->execute([$q['file_id']]);
        $file = $st->fetch() ?: null;
    }
    $c = pm_db()->prepare('SELECT name, company, tax_id, phone FROM contacts WHERE id = ?');
    $c->execute([$q['supplier_id']]);
    return [
        'quote_id' => (int)$q['id'], 'version' => (int)$q['version'], 'amount' => (int)$q['amount'] / 100,
        'vat_included' => true, 'valid_until' => $q['valid_until'], 'terms' => $q['terms'], 'notes' => $q['notes'],
        'supplier' => $c->fetch() ?: null, 'file' => $file ? ['id' => (int)$q['file_id']] + $file : null,
        'updated_at' => $q['updated_at'],
    ];
}

/**
 * החלטה על הצעה: $role = client · lawyer, $decision = approved · rejected.
 * בשלב הזה רושם אותה המנהל (method = manual) — עם שם מי שהחליט ואיך ("אישר בטלפון").
 */
function pm_quote_decide(array $user, int $id, string $role, string $decision, string $note, string $method = 'manual', string $byName = '',
                         ?array $actor = null, array $portal = []): array
{
    // $user — בשמו נבדקת ההרשאה (מהפורטל: בעל הפרויקט). $actor — מי שמופיע בציר
    // הזמן (מהפורטל: הלקוח או העו"ד). $portal — portal_id, signature.
    $actor ??= $user;
    $q = pm_quote_row($user, $id);
    if (!in_array($role, ['client', 'lawyer'], true) || !in_array($decision, ['approved', 'rejected'], true)) throw new PmError('החלטה לא מוכרת', 422);
    $stage = pm_stage_row($user, (int)$q['stage_id']);
    $p = pm_project_row($user, (int)$q['project_id']);
    $note = mb_substr(trim($note), 0, 1000);

    if ($role === 'lawyer') {
        if (!$p['lawyer_id']) throw new PmError('אין עו"ד בפרויקט', 422);
        if ($q['status'] !== 'client_approved') throw new PmError('העו"ד מאשר אחרי הלקוח', 422);
    } elseif (!in_array($q['status'], ['open', 'sent', 'declined', 'rejected'], true)) {
        throw new PmError('ההצעה כבר ' . PM_QUOTE_STATUSES[$q['status']], 422);
    }
    if ($byName === '') $byName = pm_contact_name((int)($role === 'client' ? $p['client_id'] : $p['lawyer_id'])) ?: ($role === 'client' ? 'הלקוח' : 'עו"ד');

    if ($decision === 'approved' && in_array($stage['status'], ['in_progress', 'done', 'paid'], true)) {
        throw new PmError('השלב כבר ' . PM_STAGE_STATUSES[$stage['status']] . ' — אי אפשר להחליף בו הצעה', 422);
    }
    $needsLawyer = (bool)$stage['needs_lawyer'] && $p['lawyer_id'];
    $status = $decision === 'rejected' ? 'rejected' : ($role === 'client' && $needsLawyer ? 'client_approved' : 'approved');
    $supplier = pm_contact_name((int)$q['supplier_id']);
    $who = $role === 'client' ? 'הלקוח' : 'העו"ד';
    $now = pm_now();
    $db = pm_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO approvals (project_id, stage_id, quote_id, quote_version, role, decision, method, by_name, recorded_by, note, snapshot_json, ip, ua, created_at, portal_id, signature)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$q['project_id'], $q['stage_id'], $id, $q['version'], $role, $decision, $method, $byName,
                      $method === 'manual' ? $user['id'] : null, $note, json_encode(pm_quote_snapshot($q), JSON_UNESCAPED_UNICODE),
                      (string)($_SERVER['REMOTE_ADDR'] ?? ''), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), $now,
                      $portal['portal_id'] ?? null, (string)($portal['signature'] ?? '')]);
        $db->prepare('UPDATE quotes SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, $now, $id]);

        $how = $method === 'manual' ? ' (נרשם ע"י ' . $user['display_name'] . ')' : ($method === 'signature' ? ' — בחתימה בקישור' : ' — בקישור');
        $text = ($decision === 'approved' ? "$who אישר" : "$who דחה") . " את ההצעה של $supplier, {$stage['name']} — " . pm_money((int)$q['amount'])
              . ($note !== '' ? ": $note" : '') . $how;
        pm_event($actor, (int)$q['project_id'], 'approval', $text, ['quote' => $id, 'role' => $role, 'decision' => $decision]);

        if ($decision === 'approved') {
            // ההצעה נבחרה: האחרות בשלב — "לא נבחרה" (גם הצעה שאושרה קודם, אם הלקוח
            // החליף), הספק משויך לשלב, והשלב מתקדם
            $db->prepare("UPDATE quotes SET status = 'declined', updated_at = ? WHERE stage_id = ? AND id != ? AND status IN ('open','sent','client_approved','approved')")
               ->execute([$now, $q['stage_id'], $id]);
            if ((int)$stage['supplier_id'] !== (int)$q['supplier_id']) {
                $db->prepare('UPDATE stages SET supplier_id = ?, updated_at = ? WHERE id = ?')->execute([$q['supplier_id'], $now, $stage['id']]);
                pm_event($actor, (int)$q['project_id'], 'stage_supplier', "{$stage['name']}: שויך $supplier", ['stage' => (int)$stage['id']]);
            }
            if (in_array($stage['status'], ['planned', 'quotes', 'client_approval', 'lawyer_approval'], true)) {
                pm_stage_set_status($actor, $stage, $status === 'approved' ? 'approved' : 'lawyer_approval');
            }
        } elseif ($role === 'lawyer' || $q['status'] === 'client_approved') {
            if (in_array($stage['status'], ['client_approval', 'lawyer_approval'], true)) pm_stage_set_status($actor, $stage, 'quotes', 'ההצעה נדחתה');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_quotes($user, (int)$q['project_id']);
}

/** ביטול — ההצעה נשארת בהיסטוריה. הצעה שאושרה ובוטלה מחזירה את השלב לאיסוף הצעות. */
function pm_quote_cancel(array $user, int $id, string $note): array
{
    $q = pm_quote_row($user, $id);
    if ($q['status'] === 'cancelled') return pm_quotes($user, (int)$q['project_id']);
    $stage = pm_stage_row($user, (int)$q['stage_id']);
    pm_db()->prepare("UPDATE quotes SET status = 'cancelled', recommended = 0, updated_at = ? WHERE id = ?")->execute([pm_now(), $id]);
    $note = mb_substr(trim($note), 0, 500);
    pm_event($user, (int)$q['project_id'], 'quote', 'בוטלה הצעה: ' . pm_contact_name((int)$q['supplier_id']) . ", {$stage['name']} — " . pm_money((int)$q['amount'])
        . ($note !== '' ? ": $note" : ''), ['quote' => $id]);
    if (in_array($q['status'], ['approved', 'client_approved'], true) && in_array($stage['status'], ['approved', 'lawyer_approval'], true)) {
        pm_stage_set_status($user, $stage, 'quotes', 'ההצעה שאושרה בוטלה');
    }
    return pm_quotes($user, (int)$q['project_id']);
}

/** מה בדיוק אושר — צילום המצב של החלטה אחת. */
function pm_approval_get(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM approvals WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) throw new PmError('האישור לא נמצא', 404);
    pm_project_row($user, (int)$a['project_id']);
    return ['id' => (int)$a['id'], 'role' => $a['role'], 'decision' => $a['decision'], 'method' => $a['method'],
            'by_name' => $a['by_name'], 'note' => $a['note'], 'at' => $a['created_at'], 'version' => (int)$a['quote_version'],
            'ip' => $a['ip'], 'ua' => $a['ua'], 'signature' => $a['signature'] ?? '', 'snapshot' => json_decode($a['snapshot_json'], true)];
}
