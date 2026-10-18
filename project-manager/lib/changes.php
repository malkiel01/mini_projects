<?php
/**
 * תוספות וחריגות — אגף הכספים, כ4 (README, "אגף הכספים").
 *
 * לשלב ולספק: סכום (גם שלילי — הפחתה), סיבה וקובץ. נשלחת ללקוח לאישור — באותו מנגנון של הצעה:
 * בקישור האישי, בלחיצה או בחתימה (לפי הגדרות הלקוח), או שהמנהל רושם ("אישר בטלפון"). בשלב שדורש
 * עו"ד — אחרי הלקוח גם העו"ד.
 *
 *   sent (אצל הלקוח) → client_approved (אצל העו"ד) → approved — נכנסת להתחייבות (lib/finance.php)
 *                    → rejected
 *   cancelled — המנהל ביטל לפני ההחלטה. שום דבר לא נמחק.
 */

declare(strict_types=1);

require_once __DIR__ . '/payments.php';

// i18n-ok-start — מתורגם בתצוגה
const PM_CHANGE_STATUSES = [
    'sent'            => 'ממתינה ללקוח',
    'client_approved' => 'הלקוח אישר — ממתינה לעו"ד',
    'approved'        => 'אושרה',
    'rejected'        => 'נדחתה',
    'cancelled'       => 'בוטלה',
];
// i18n-ok-end

function pm_change_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM change_orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) throw new PmError('התוספת לא נמצאה', 404);
    pm_project_row($user, (int)$o['project_id']);
    return $o;
}

/** התוספות של פרויקט, עם ההחלטות. $role — לפורטל: client / lawyer (מי יכול להחליט עכשיו). */
function pm_changes_of(int $projectId, string $role = ''): array
{
    $db = pm_db();
    $st = $db->prepare("SELECT o.*, c.name AS supplier, c.vat_exempt, s.name AS stage, f.name AS file_name, f.deleted_at AS file_deleted
                          FROM change_orders o JOIN contacts c ON c.id = o.supplier_id JOIN stages s ON s.id = o.stage_id
                          LEFT JOIN files f ON f.id = o.file_id
                         WHERE o.project_id = ? ORDER BY o.id DESC");
    $st->execute([$projectId]);
    $rows = $st->fetchAll();
    $dec = [];
    if ($rows) {
        $in = implode(',', array_map('intval', array_column($rows, 'id')));
        foreach ($db->query("SELECT * FROM change_decisions WHERE change_order_id IN ($in) ORDER BY id") as $d) {
            $dec[(int)$d['change_order_id']][] = ['role' => $d['role'], 'decision' => $d['decision'], 'method' => $d['method'],
                'by_name' => $d['by_name'], 'note' => $d['note'], 'at' => $d['created_at']];
        }
    }
    return array_map(fn($o) => [
        'id' => (int)$o['id'], 'stage_id' => (int)$o['stage_id'], 'stage' => $o['stage'], 'supplier_id' => (int)$o['supplier_id'], 'supplier' => $o['supplier'],
        'amount' => (int)$o['amount'] / 100, 'vat_rate' => $o['vat_exempt'] ? 0.0 : ($o['vat_rate'] !== null ? (float)$o['vat_rate'] : pm_vat_rate($projectId)),
        'reason' => $o['reason'], 'status' => $o['status'], 'label' => pm_t(PM_CHANGE_STATUSES[$o['status']] ?? $o['status']),
        'needs_lawyer' => (bool)$o['needs_lawyer'], 'created_at' => $o['created_at'], 'updated_at' => $o['updated_at'],
        'file' => $o['file_id'] && !$o['file_deleted'] ? ['id' => (int)$o['file_id'], 'name' => $o['file_name']] : null,
        'decisions' => $dec[(int)$o['id']] ?? [],
        'can_decide' => ($role === 'client' && $o['status'] === 'sent') || ($role === 'lawyer' && $o['status'] === 'client_approved'),
    ], $rows);
}

function pm_changes(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    return ['changes' => pm_changes_of($projectId), 'statuses' => pm_t_map(PM_CHANGE_STATUSES)];
}

/** תוספת / חריגה חדשה: {project_id, stage_id, supplier_id, amount (גם שלילי), reason, file_id?} — נשלחת ללקוח. */
function pm_change_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $stage = pm_stage_row($user, (int)($in['stage_id'] ?? 0));
    if ((int)$stage['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    $supplierId = pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק') // i18n-ok
        ?? throw new PmError('ספק: שדה חובה', 422);
    pm_supplier_require($projectId, $supplierId);
    $v = $in['amount'] ?? null;
    if (!is_numeric($v) || (float)$v == 0.0 || abs((float)$v) > 1e9) throw new PmError('סכום: מספר (חיובי לתוספת, שלילי להפחתה)', 422);
    $amount = (int)round((float)$v * 100);
    $reason = pm_str($in, 'reason', 1000, true, 'סיבה');
    $fileId = (int)($in['file_id'] ?? 0) ?: null;
    // הלקוח צריך לראות את הפירוט; מ"ממתין למיון" — לתיקיית הספק
    pm_file_claim($projectId, $fileId, null, $supplierId, true, (int)$stage['id']);
    $needsLawyer = $stage['needs_lawyer'] && $p['lawyer_id'] ? 1 : 0;
    $now = pm_now();
    $db = pm_db();
    $db->prepare('INSERT INTO change_orders (project_id, stage_id, supplier_id, amount, reason, file_id, status, needs_lawyer, vat_rate, created_by, created_at, updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$projectId, $stage['id'], $supplierId, $amount, $reason, $fileId, 'sent', $needsLawyer, pm_vat_rate($projectId), $user['id'], $now, $now]);
    $id = (int)$db->lastInsertId();
    $supplier = pm_contact_name($supplierId);
    pm_event($user, $projectId, 'change', $amount > 0 ? 'תוספת לאישור הלקוח: {supplier}, {stage} — {amount}: {reason}' : 'הפחתה לאישור הלקוח: {supplier}, {stage} — {amount}: {reason}',
        ['change' => $id], ['supplier' => $supplier, 'stage' => $stage['name'], 'amount' => pm_money($amount), 'reason' => $reason]);
    if ($p['client_id']) pm_change_notify($user, $id, (int)$p['client_id'], 'change_order');
    return pm_finance($user, $projectId) + pm_changes($user, $projectId) + ['saved' => $id];
}

/** מייל לנמען עם הקישור האישי — {פרטים} בשפה שלו. */
function pm_change_notify(array $actor, int $id, int $contactId, string $key): void
{
    $st = pm_db()->prepare('SELECT o.*, c.name AS supplier, s.name AS stage FROM change_orders o JOIN contacts c ON c.id = o.supplier_id JOIN stages s ON s.id = o.stage_id WHERE o.id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    $lang = pm_contact_lang($contactId);
    // i18n-none-start — שמות המשתנים בנוסח
    pm_notify((int)$o['project_id'], $contactId, $key, [
        'שלב' => $o['stage'], 'סכום' => pm_money((int)$o['amount']),
        'פרטים' => pm_t('{supplier}: {reason}', ['supplier' => $o['supplier'], 'reason' => $o['reason']], $lang),
        'קישור' => pm_notify_link((int)$o['project_id'], $contactId),
    ], $actor);
    // i18n-none-end
}

/**
 * החלטה על תוספת — כמו בהצעה: $role client / lawyer, $decision approved / rejected, $method manual (המנהל
 * רשם) · click · signature (מהפורטל). $user — בשמו נבדקת ההרשאה; $actor — מי בציר הזמן.
 */
function pm_change_decide(array $user, int $id, string $role, string $decision, string $note, string $method = 'manual', string $byName = '',
                          ?array $actor = null, array $portal = []): array
{
    $actor ??= $user;
    $o = pm_change_row($user, $id);
    $p = pm_project_row($user, (int)$o['project_id']);
    if (!in_array($role, ['client', 'lawyer'], true) || !in_array($decision, ['approved', 'rejected'], true)) throw new PmError('החלטה לא מוכרת', 422);
    if ($role === 'client' && $o['status'] !== 'sent') throw new PmError('התוספת כבר {status}', 422, ['status' => PM_CHANGE_STATUSES[$o['status']]]);
    if ($role === 'lawyer' && $o['status'] !== 'client_approved') throw new PmError('העו"ד מאשר אחרי הלקוח', 422);
    $note = mb_substr(trim($note), 0, 1000);
    if ($byName === '') $byName = pm_contact_name((int)($role === 'client' ? $p['client_id'] : $p['lawyer_id'])) ?: pm_t($role === 'client' ? 'הלקוח' : 'עו"ד');
    $status = $decision === 'rejected' ? 'rejected' : ($role === 'client' && $o['needs_lawyer'] ? 'client_approved' : 'approved');
    $now = pm_now();
    $db = pm_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO change_decisions (change_order_id, role, decision, method, by_name, note, signature, portal_id, recorded_by, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$id, $role, $decision, $method, $byName, $note, (string)($portal['signature'] ?? ''), $portal['portal_id'] ?? null,
                      $method === 'manual' ? $user['id'] : null, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $now]);
        $db->prepare('UPDATE change_orders SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, $now, $id]);
        $stage = pm_stage_row($user, (int)$o['stage_id']);
        // משפט שלם לכל צירוף: מי × החלטה
        // i18n-ok-start
        $text = [
            'client' => ['approved' => 'הלקוח אישר תוספת: {supplier}, {stage} — {amount}{note}{how}', 'rejected' => 'הלקוח דחה תוספת: {supplier}, {stage} — {amount}{note}{how}'],
            'lawyer' => ['approved' => 'העו"ד אישר תוספת: {supplier}, {stage} — {amount}{note}{how}', 'rejected' => 'העו"ד דחה תוספת: {supplier}, {stage} — {amount}{note}{how}'],
        ][$role][$decision];
        $how = ['manual' => ' (נרשם על ידי מנהל הפרויקט)', 'signature' => ' — בחתימה בקישור', 'click' => ' — בקישור'][$method] ?? '';
        // i18n-ok-end
        pm_event($actor, (int)$o['project_id'], 'change', $text, ['change' => $id, 'role' => $role, 'decision' => $decision], [
            'supplier' => pm_contact_name((int)$o['supplier_id']), 'stage' => $stage['name'], 'amount' => pm_money((int)$o['amount']),
            'note' => $note !== '' ? ": $note" : '', 'how' => $how,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    // אחרי הלקוח — לעו"ד
    if ($status === 'client_approved' && $p['lawyer_id']) pm_change_notify($user, $id, (int)$p['lawyer_id'], 'change_order_lawyer');
    return pm_finance($user, (int)$o['project_id']) + pm_changes($user, (int)$o['project_id']);
}

function pm_change_cancel(array $user, int $id): array
{
    $o = pm_change_row($user, $id);
    if (!in_array($o['status'], ['sent', 'client_approved'], true)) throw new PmError('אפשר לבטל רק תוספת שעוד ממתינה', 422);
    pm_db()->prepare("UPDATE change_orders SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([pm_now(), $id]);
    pm_event($user, (int)$o['project_id'], 'change', 'בוטלה תוספת: {amount} — {reason}', ['change' => $id], ['amount' => pm_money((int)$o['amount']), 'reason' => $o['reason']]);
    return pm_finance($user, (int)$o['project_id']) + pm_changes($user, (int)$o['project_id']);
}
