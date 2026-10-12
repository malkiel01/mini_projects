<?php
/**
 * אבני דרך לתשלום — שלב 7 (README, "אבן דרך לתשלום").
 *
 * **האפליקציה רק מתעדת:** הלקוח משלם ישירות לספק, בעצמו או דרך העו"ד. אין סליקה.
 *
 *   planned (מתוכנן) → requested (נשלחה דרישת תשלום)
 *     → [אישור עו"ד, כשנדרש — לפניו אין "שילמתי"]
 *     → paid (הלקוח / העו"ד העלה אסמכתא) → received (המנהל אישר שהתקבל — נסגר)
 *                                         → partial (התקבל חלק — נשארת יתרה, אפשר לשלם שוב)
 *   cancelled — ביטול. שום דבר לא נמחק.
 *
 * סכום — קבוע, או אחוז מההצעה שאושרה בשלב. כל שינוי נרשם בציר הזמן.
 * כשכל אבני הדרך של שלב שבוצע התקבלו — השלב "שולם וסגור".
 */

declare(strict_types=1);

require_once __DIR__ . '/quotes.php';

const PM_PAYMENT_STATUSES = [
    'planned'   => 'מתוכנן',
    'requested' => 'נשלחה דרישה',
    'paid'      => 'דווח ששולם',
    'partial'   => 'שולם חלקית',
    'received'  => 'התקבל',
    'cancelled' => 'בוטל',
];
const PM_PAY_METHODS = ['transfer' => 'העברה בנקאית', 'check' => 'צ\'ק', 'bit' => 'ביט / אפליקציה', 'cash' => 'מזומן', 'other' => 'אחר'];

function pm_payment_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) throw new PmError('אבן הדרך לא נמצאה', 404);
    pm_project_row($user, (int)$p['project_id']);
    return $p;
}

/** כמה כבר התקבל (אישור המנהל). */
function pm_payment_received(int $id): int
{
    $st = pm_db()->prepare("SELECT COALESCE(SUM(received_amount), 0) FROM payment_proofs WHERE payment_id = ? AND status = 'received'");
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/** אפשר לשלם: נשלחה דרישה (או נשארה יתרה), ואם נדרש עו"ד — הוא אישר. */
function pm_payment_payable(array $p): bool
{
    return in_array($p['status'], ['requested', 'partial'], true) && (!$p['needs_lawyer'] || $p['lawyer_approved_at']);
}

/** הסטטוס לתצוגה — "ממתין לאישור עו"ד" / "ממתין לתשלום" במקום "נשלחה דרישה". */
function pm_payment_label(array $p): string
{
    if ($p['status'] === 'requested') return $p['needs_lawyer'] && !$p['lawyer_approved_at'] ? 'ממתין לאישור עו"ד' : 'ממתין לתשלום';
    if ($p['status'] === 'paid') return 'דווח ששולם — ממתין לאישור קבלה';
    return PM_PAYMENT_STATUSES[$p['status']] ?? $p['status'];
}

function pm_payment_out(array $p, array $proofs): array
{
    $received = 0;
    foreach ($proofs as $x) if ($x['status'] === 'received') $received += $x['received_amount'] * 100;
    return [
        'id' => (int)$p['id'], 'stage_id' => $p['stage_id'] !== null ? (int)$p['stage_id'] : null, 'supplier_id' => (int)$p['supplier_id'],
        'supplier_name' => $p['supplier_name'] ?? '', 'quote_id' => $p['quote_id'] !== null ? (int)$p['quote_id'] : null,
        'title' => $p['title'], 'amount' => (int)$p['amount'] / 100, 'percent' => $p['percent'] !== null ? (float)$p['percent'] : null,
        'due_date' => $p['due_date'], 'payer' => $p['payer'], 'needs_lawyer' => (bool)$p['needs_lawyer'],
        'lawyer' => $p['lawyer_approved_at'] ? ['at' => $p['lawyer_approved_at'], 'by' => $p['lawyer_by'], 'method' => $p['lawyer_method'], 'note' => $p['lawyer_note']] : null,
        'status' => $p['status'], 'label' => pm_payment_label($p), 'payable' => pm_payment_payable($p),
        'notes' => $p['notes'], 'requested_at' => $p['requested_at'], 'created_at' => $p['created_at'],
        'supplier_request' => (bool)($p['supplier_request'] ?? false), 'file_id' => isset($p['file_id']) ? (int)$p['file_id'] ?: null : null,
        'received' => $received / 100, 'balance' => max(0, (int)$p['amount'] - $received) / 100,
        'proofs' => $proofs,
    ];
}

function pm_payment_proofs(array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = pm_db()->prepare("SELECT x.*, f.name AS file_name, f.mime AS file_mime, f.deleted_at AS file_deleted
                              FROM payment_proofs x LEFT JOIN files f ON f.id = x.file_id WHERE x.payment_id IN ($in) ORDER BY x.id");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $x) {
        $out[(int)$x['payment_id']][] = [
            'id' => (int)$x['id'], 'amount' => (int)$x['amount'] / 100, 'paid_on' => $x['paid_on'], 'method' => $x['method'],
            'file' => $x['file_id'] && !$x['file_deleted'] ? ['id' => (int)$x['file_id'], 'name' => $x['file_name'], 'mime' => $x['file_mime']] : null,
            'note' => $x['note'], 'by_role' => $x['by_role'], 'by_name' => $x['by_name'], 'status' => $x['status'],
            'received_amount' => $x['received_amount'] !== null ? (int)$x['received_amount'] / 100 : null,
            'reviewed_at' => $x['reviewed_at'], 'review_note' => $x['review_note'], 'created_at' => $x['created_at'],
        ];
    }
    return $out;
}

/** כל אבני הדרך של הפרויקט + סיכום: כמה מתוכנן, כמה התקבל, כמה ממתין. */
function pm_payments(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    return pm_payments_of($projectId);
}

function pm_payments_of(int $projectId, bool $portal = false): array
{
    $st = pm_db()->prepare('SELECT p.*, c.name AS supplier_name FROM payments p JOIN contacts c ON c.id = p.supplier_id
                             WHERE p.project_id = ?' . ($portal ? " AND p.status NOT IN ('planned','cancelled')" : '') . '
                             ORDER BY p.stage_id IS NULL, p.stage_id, CASE WHEN p.due_date = \'\' THEN 1 ELSE 0 END, p.due_date, p.id');
    $st->execute([$projectId]);
    $rows = $st->fetchAll();
    $proofs = pm_payment_proofs(array_map('intval', array_column($rows, 'id')));
    $list = array_map(fn($p) => pm_payment_out($p, $proofs[(int)$p['id']] ?? []), $rows);
    $live = array_filter($list, fn($p) => $p['status'] !== 'cancelled');
    return [
        'payments' => $list,
        'summary'  => [
            'total'    => array_sum(array_column($live, 'amount')),
            'received' => array_sum(array_column($live, 'received')),
            'waiting'  => count(array_filter($live, fn($p) => $p['status'] === 'paid')),
            'late'     => count(array_filter($live, fn($p) => $p['due_date'] !== '' && $p['due_date'] < gmdate('Y-m-d') && !in_array($p['status'], ['received'], true))),
        ],
        'statuses' => PM_PAYMENT_STATUSES, 'methods' => PM_PAY_METHODS,
    ];
}

/** ההצעה שאושרה בשלב — בסיס לאחוז, והספק ברירת המחדל. */
function pm_stage_approved_quote(int $stageId): ?array
{
    $st = pm_db()->prepare("SELECT * FROM quotes WHERE stage_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $st->execute([$stageId]);
    return $st->fetch() ?: null;
}

function pm_payment_save(array $user, array $in, ?array $actor = null): array
{
    $actor ??= $user;
    $id = (int)($in['id'] ?? 0);
    $old = $id ? pm_payment_row($user, $id) : null;
    if ($old && !in_array($old['status'], ['planned', 'requested'], true)) throw new PmError('כבר דווח תשלום — אי אפשר לשנות. אפשר לבטל וליצור חדשה', 422);
    $projectId = $old ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);

    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    $stage = null;
    if ($stageId) {
        $stage = pm_stage_row($user, $stageId);
        if ((int)$stage['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    }
    $supplierId = pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק')
        ?? throw new PmError('ספק: שדה חובה', 422);

    // סכום, או אחוז מההצעה שאושרה בשלב
    $quote = $stageId ? pm_stage_approved_quote($stageId) : null;
    $percent = null;
    if (($in['percent'] ?? '') !== '' && $in['percent'] !== null) {
        $percent = (float)$in['percent'];
        if ($percent <= 0 || $percent > 100) throw new PmError('אחוז: בין 0 ל-100', 422);
        if (!$quote) throw new PmError('אחוז — רק כשיש בשלב הצעה שאושרה', 422);
        $amount = (int)round($quote['amount'] * $percent / 100);
    } else {
        $amount = pm_quote_amount($in['amount'] ?? null);
    }
    $payer = (string)($in['payer'] ?? 'client');
    if (!in_array($payer, ['client', 'lawyer'], true)) throw new PmError('משלם לא מוכר', 422);
    if ($payer === 'lawyer' && !$p['lawyer_id']) throw new PmError('אין עו"ד בפרויקט', 422);
    $f = [
        'stage_id'     => $stageId,
        'supplier_id'  => $supplierId,
        'quote_id'     => $quote ? (int)$quote['id'] : null,
        'title'        => pm_str($in, 'title', 100, true, 'שם אבן הדרך'),
        'amount'       => $amount,
        'percent'      => $percent,
        'due_date'     => pm_date($in, 'due_date', 'תאריך יעד'),
        'payer'        => $payer,
        'needs_lawyer' => !empty($in['needs_lawyer']) && $p['lawyer_id'] ? 1 : 0,
        'notes'        => pm_str($in, 'notes', 2000, false, 'מה כלול'),
    ];
    $supplier = pm_contact_name($supplierId);
    $now = pm_now();
    $db = pm_db();
    if ($old) {
        // סכום או ספק השתנו אחרי אישור העו"ד — האישור לא חל על הדרישה החדשה
        $resetLawyer = $old['lawyer_approved_at'] && ((int)$old['amount'] !== $f['amount'] || (int)$old['supplier_id'] !== $supplierId);
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        $db->prepare("UPDATE payments SET $set, updated_at = ?" . ($resetLawyer ? ", lawyer_approved_at = NULL, lawyer_by = '', lawyer_method = '', lawyer_note = ''" : '') . ' WHERE id = ?')
           ->execute(array_merge(array_values($f), [$now, $id]));
        if ((int)$old['amount'] !== $f['amount'] || $old['due_date'] !== $f['due_date'] || $old['title'] !== $f['title']) {
            pm_event($actor, $projectId, 'payment', "אבן דרך עודכנה: {$f['title']} — $supplier, " . pm_money($f['amount'])
                . ($f['due_date'] ? ' עד ' . pm_short_date($f['due_date']) : '') . ($resetLawyer ? '. נדרש אישור עו"ד מחדש' : ''), ['payment' => $id]);
        }
    } else {
        $cols = implode(', ', array_keys($f));
        $qs = implode(', ', array_fill(0, count($f), '?'));
        $db->prepare("INSERT INTO payments ($cols, project_id, created_by, created_at, updated_at) VALUES ($qs, ?, ?, ?, ?)")
           ->execute(array_merge(array_values($f), [$projectId, $user['id'], $now, $now]));
        $id = (int)$db->lastInsertId();
        pm_event($actor, $projectId, 'payment', "אבן דרך לתשלום: {$f['title']} — $supplier, " . pm_money($f['amount'])
            . ($f['due_date'] ? ' עד ' . pm_short_date($f['due_date']) : ''), ['payment' => $id]);
    }
    return pm_payments($user, $projectId) + ['saved' => $id];
}

function pm_short_date(string $ymd): string
{
    return $ymd === '' ? '' : implode('.', array_slice(array_reverse(explode('-', $ymd)), 0, 2));
}

/** "דרישת תשלום": נשלחה ללקוח / לעו"ד (ההודעה עצמה יוצאת מהדפדפן של המנהל). */
function pm_payment_request(array $user, int $id): array
{
    $p = pm_payment_row($user, $id);
    if (!in_array($p['status'], ['planned', 'requested'], true)) throw new PmError('אבן הדרך כבר ' . PM_PAYMENT_STATUSES[$p['status']], 422);
    if ($p['status'] === 'planned') {
        pm_db()->prepare("UPDATE payments SET status = 'requested', requested_at = ?, updated_at = ? WHERE id = ?")->execute([pm_now(), pm_now(), $id]);
        pm_event($user, (int)$p['project_id'], 'payment', "נשלחה דרישת תשלום: {$p['title']} — " . pm_contact_name((int)$p['supplier_id']) . ', ' . pm_money((int)$p['amount'])
            . ($p['needs_lawyer'] ? ' (ממתין לאישור עו"ד)' : ''), ['payment' => $id]);
    }
    return pm_payments($user, (int)$p['project_id']);
}

/** אישור העו"ד לתשלום — מהפורטל (click) או שהמנהל רושם (manual). */
function pm_payment_lawyer_approve(array $user, int $id, string $note, string $method = 'manual', string $byName = '', ?array $actor = null): array
{
    $actor ??= $user;
    $p = pm_payment_row($user, $id);
    if (!$p['needs_lawyer']) throw new PmError('אבן הדרך לא דורשת אישור עו"ד', 422);
    if ($p['lawyer_approved_at']) throw new PmError('העו"ד כבר אישר', 422);
    if ($p['status'] !== 'requested') throw new PmError('אפשר לאשר רק דרישת תשלום שנשלחה', 422);
    $pr = pm_project_row($user, (int)$p['project_id']);
    if ($byName === '') $byName = pm_contact_name((int)$pr['lawyer_id']) ?: 'עו"ד';
    $note = mb_substr(trim($note), 0, 1000);
    pm_db()->prepare('UPDATE payments SET lawyer_approved_at = ?, lawyer_by = ?, lawyer_method = ?, lawyer_note = ?, updated_at = ? WHERE id = ?')
       ->execute([pm_now(), $byName, $method, $note, pm_now(), $id]);
    pm_event($actor, (int)$p['project_id'], 'payment', "העו\"ד אישר לתשלום: {$p['title']} — " . pm_money((int)$p['amount'])
        . ($note !== '' ? ": $note" : '') . ($method === 'manual' ? " (נרשם ע\"י {$user['display_name']})" : ' — בקישור'), ['payment' => $id]);
    return pm_payments($user, (int)$p['project_id']);
}

/**
 * "שילמתי" — אסמכתא. מהפורטל: $by = client · lawyer (עם הקישור); מהמנהל: manager.
 * $fileId — כבר שמור בתיקיות הפרויקט.
 */
function pm_payment_proof_add(array $user, int $id, array $in, string $byRole, string $byName, ?int $fileId, ?array $actor = null, ?int $portalId = null): array
{
    $actor ??= $user;
    $p = pm_payment_row($user, $id);
    if (!pm_payment_payable($p) && !($byRole === 'manager' && in_array($p['status'], ['planned', 'requested', 'partial', 'paid'], true))) {
        throw new PmError($p['needs_lawyer'] && !$p['lawyer_approved_at'] ? 'ממתין לאישור העו"ד — לפניו אין "שילמתי"' : 'אי אפשר לדווח תשלום עכשיו', 422);
    }
    $amount = pm_quote_amount($in['amount'] ?? null);
    $paidOn = pm_date($in, 'paid_on', 'תאריך התשלום') ?: gmdate('Y-m-d');
    $method = (string)($in['method'] ?? 'transfer');
    if (!isset(PM_PAY_METHODS[$method])) throw new PmError('אמצעי תשלום לא מוכר', 422);
    if (!$fileId && $byRole !== 'manager') throw new PmError('נדרשת אסמכתא — צילום ההעברה, הצ\'ק או אישור הבנק', 422);
    if ($fileId) {
        $f = pm_db()->prepare('SELECT project_id FROM files WHERE id = ? AND deleted_at IS NULL');
        $f->execute([$fileId]);
        if ((int)$f->fetchColumn() !== (int)$p['project_id']) throw new PmError('הקובץ לא שייך לפרויקט', 422);
        pm_db()->prepare("UPDATE files SET type = 'proof' WHERE id = ?")->execute([$fileId]);
    }
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 1000);
    $db = pm_db();
    $db->prepare('INSERT INTO payment_proofs (payment_id, amount, paid_on, method, file_id, note, by_role, by_name, portal_id, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$id, $amount, $paidOn, $method, $fileId, $note, $byRole, $byName, $portalId, (string)($_SERVER['REMOTE_ADDR'] ?? ''), pm_now()]);
    $db->prepare("UPDATE payments SET status = 'paid', updated_at = ? WHERE id = ?")->execute([pm_now(), $id]);
    pm_event($actor, (int)$p['project_id'], 'payment', "💳 דווח תשלום: {$p['title']} — " . pm_money($amount) . ' ב' . PM_PAY_METHODS[$method]
        . ' (' . pm_short_date($paidOn) . ')' . ($note !== '' ? ": $note" : '') . ($fileId ? ', עם אסמכתא' : ''), ['payment' => $id]);
    return pm_payments($user, (int)$p['project_id']);
}

/** המנהל: הכסף התקבל (סכום — גם חלקי), או לא התקבל. */
function pm_payment_proof_review(array $user, int $proofId, bool $ok, $receivedAmount, string $note, ?array $actor = null): array
{
    $actor ??= $user; // מהקישור של הספק: "התקבל" בשם הספק
    $st = pm_db()->prepare('SELECT * FROM payment_proofs WHERE id = ?');
    $st->execute([$proofId]);
    $x = $st->fetch();
    if (!$x) throw new PmError('האסמכתא לא נמצאה', 404);
    $p = pm_payment_row($user, (int)$x['payment_id']);
    if ($x['status'] !== 'pending') throw new PmError('כבר טופל', 422);
    $note = mb_substr(trim($note), 0, 500);
    $db = pm_db();
    $db->beginTransaction();
    try {
        if ($ok) {
            $got = $receivedAmount === null || $receivedAmount === '' ? (int)$x['amount'] : pm_quote_amount($receivedAmount);
            $db->prepare("UPDATE payment_proofs SET status = 'received', received_amount = ?, reviewed_at = ?, reviewed_by = ?, review_note = ? WHERE id = ?")
               ->execute([$got, pm_now(), $user['id'], $note, $proofId]);
            $total = pm_payment_received((int)$p['id']);
            $status = $total >= (int)$p['amount'] ? 'received' : 'partial';
            pm_event($actor, (int)$p['project_id'], 'payment', "✅ התקבל: {$p['title']} — " . pm_money($got)
                . ($status === 'partial' ? ' (נשארה יתרה ' . pm_money((int)$p['amount'] - $total) . ')' : ' — אבן הדרך נסגרה'), ['payment' => (int)$p['id']]);
        } else {
            $db->prepare("UPDATE payment_proofs SET status = 'rejected', reviewed_at = ?, reviewed_by = ?, review_note = ? WHERE id = ?")
               ->execute([pm_now(), $user['id'], $note, $proofId]);
            $total = pm_payment_received((int)$p['id']);
            $status = $total > 0 ? 'partial' : 'requested';
            pm_event($actor, (int)$p['project_id'], 'payment', "✖ התשלום לא התקבל: {$p['title']} — " . pm_money((int)$x['amount']) . ($note !== '' ? ": $note" : ''),
                ['payment' => (int)$p['id']]);
        }
        // עוד אסמכתא שממתינה — נשאר "דווח ששולם"
        $more = $db->prepare("SELECT COUNT(*) FROM payment_proofs WHERE payment_id = ? AND status = 'pending'");
        $more->execute([$p['id']]);
        if ($more->fetchColumn() && $status !== 'received') $status = 'paid';
        $db->prepare('UPDATE payments SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, pm_now(), $p['id']]);
        if ($status === 'received' && $p['stage_id']) pm_stage_close_if_paid($user, (int)$p['stage_id']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_payments($user, (int)$p['project_id']);
}

/** שלב שבוצע, וכל אבני הדרך שלו התקבלו — "שולם וסגור". */
function pm_stage_close_if_paid(array $user, int $stageId): void
{
    $stage = pm_stage_row($user, $stageId);
    if ($stage['status'] !== 'done') return;
    $st = pm_db()->prepare("SELECT COUNT(*) FROM payments WHERE stage_id = ? AND status NOT IN ('received','cancelled')");
    $st->execute([$stageId]);
    if ((int)$st->fetchColumn() === 0) pm_stage_set_status($user, $stage, 'paid', 'כל התשלומים התקבלו');
}

function pm_payment_cancel(array $user, int $id, string $note): array
{
    $p = pm_payment_row($user, $id);
    if ($p['status'] === 'cancelled') return pm_payments($user, (int)$p['project_id']);
    if (pm_payment_received($id) > 0) throw new PmError('כבר התקבל כסף באבן הדרך הזו — אי אפשר לבטל', 422);
    pm_db()->prepare("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([pm_now(), $id]);
    $note = mb_substr(trim($note), 0, 500);
    pm_event($user, (int)$p['project_id'], 'payment', "בוטלה אבן דרך: {$p['title']} — " . pm_money((int)$p['amount']) . ($note !== '' ? ": $note" : ''), ['payment' => $id]);
    return pm_payments($user, (int)$p['project_id']);
}

/** פרטי הבנק של הספק — מוצגים למשלם בפורטל. */
function pm_supplier_bank(int $supplierId): array
{
    $st = pm_db()->prepare('SELECT name, company, tax_id, bank_json FROM contacts WHERE id = ?');
    $st->execute([$supplierId]);
    $c = $st->fetch() ?: [];
    return ['name' => $c['name'] ?? '', 'company' => $c['company'] ?? '', 'tax_id' => $c['tax_id'] ?? '',
            'bank' => json_decode($c['bank_json'] ?? '{}', true) ?: []];
}
