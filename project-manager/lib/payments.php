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
require_once __DIR__ . '/finance.php';

// i18n-ok-start — מתורגם בתצוגה (pm_t_map)
const PM_PAYMENT_STATUSES = [
    'planned'   => 'מתוכנן',
    'requested' => 'נשלחה דרישה',
    'paid'      => 'דווח ששולם',
    'partial'   => 'שולם חלקית',
    'received'  => 'התקבל',
    'cancelled' => 'בוטל',
];
const PM_PAY_METHODS = ['transfer' => 'העברה בנקאית', 'check' => 'צ\'ק', 'bit' => 'ביט / אפליקציה', 'cash' => 'מזומן', 'other' => 'אחר'];
// i18n-ok-end

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

/** הסטטוס לתצוגה — "ממתין לאישור עו"ד" / "ממתין לתשלום" במקום "נשלחה דרישה". בשפת הבקשה. */
function pm_payment_label(array $p): string
{
    if ($p['status'] === 'requested') return pm_t($p['needs_lawyer'] && !$p['lawyer_approved_at'] ? 'ממתין לאישור עו"ד' : 'ממתין לתשלום');
    if ($p['status'] === 'paid') return pm_t('דווח ששולם — ממתין לאישור קבלה');
    return pm_t(PM_PAYMENT_STATUSES[$p['status']] ?? $p['status']);
}

function pm_payment_out(array $p, array $proofs): array
{
    $received = 0;
    foreach ($proofs as $x) if ($x['status'] === 'received') $received += $x['received_amount'] * 100;
    return [
        'id' => (int)$p['id'], 'stage_id' => $p['stage_id'] !== null ? (int)$p['stage_id'] : null, 'supplier_id' => (int)$p['supplier_id'],
        'supplier_name' => $p['supplier_name'] ?? '', 'quote_id' => $p['quote_id'] !== null ? (int)$p['quote_id'] : null,
        'title' => $p['title'], 'amount' => (int)$p['amount'] / 100, 'percent' => $p['percent'] !== null ? (float)$p['percent'] : null,
        'due_date' => $p['due_date'], 'due_kind' => $p['due_kind'] ?? 'date', 'due_pct' => isset($p['due_pct']) ? (int)$p['due_pct'] : null,
        'vat_rate' => pm_payment_vat($p), 'payer' => $p['payer'], 'needs_lawyer' => (bool)$p['needs_lawyer'],
        'lawyer' => $p['lawyer_approved_at'] ? ['at' => $p['lawyer_approved_at'], 'by' => $p['lawyer_by'], 'method' => $p['lawyer_method'], 'note' => $p['lawyer_note']] : null,
        'status' => $p['status'], 'label' => pm_payment_label($p), 'payable' => pm_payment_payable($p),
        'notes' => $p['notes'], 'requested_at' => $p['requested_at'], 'created_at' => $p['created_at'],
        'supplier_request' => (bool)($p['supplier_request'] ?? false), 'file_id' => isset($p['file_id']) ? (int)$p['file_id'] ?: null : null,
        'received' => $received / 100, 'balance' => max(0, pm_payment_due_amount($p) - $received) / 100,
        // כ4: עיכבון, הצמדה, חשבונית
        'kind' => $p['kind'] ?? 'milestone', 'retention' => (int)($p['retention'] ?? 0) / 100, 'due_amount' => pm_payment_due_amount($p) / 100,
        'index_value' => isset($p['index_value']) ? (float)$p['index_value'] : null, 'index_diff' => (int)($p['index_diff'] ?? 0) / 100,
        'change_order_id' => isset($p['change_order_id']) ? (int)$p['change_order_id'] ?: null : null,
        'invoice' => !empty($p['invoice_at']) ? ['file_id' => isset($p['invoice_file_id']) ? (int)$p['invoice_file_id'] ?: null : null, 'no' => $p['invoice_no'] ?? '', 'at' => $p['invoice_at']] : null,
        'proofs' => $proofs,
        'unit_id' => !empty($p['unit_id']) ? (int)$p['unit_id'] : null, 'unit_name' => !empty($p['unit_id']) ? pm_unit_name((int)$p['unit_id']) : null,
    ];
}

/** שיעור המע"מ של אבן דרך: 0 לספק עוסק פטור, השיעור שנשמר בה, או השיעור היום. */
function pm_payment_vat(array $p): float
{
    static $exempt = [];
    $cid = (int)$p['supplier_id'];
    if (!isset($exempt[$cid])) {
        $st = pm_db()->prepare('SELECT vat_exempt FROM contacts WHERE id = ?');
        $st->execute([$cid]);
        $exempt[$cid] = (bool)$st->fetchColumn();
    }
    return $exempt[$cid] ? 0.0 : (isset($p['vat_rate']) && $p['vat_rate'] !== null ? (float)$p['vat_rate'] : pm_vat_rate());
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
        'statuses' => pm_t_map(PM_PAYMENT_STATUSES), 'methods' => pm_t_map(PM_PAY_METHODS),
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
    if ($old && !pm_dev_mode($user) && !in_array($old['status'], ['planned', 'requested'], true)) throw new PmError('כבר דווח תשלום — אי אפשר לשנות. אפשר לבטל וליצור חדשה', 422);
    $projectId = $old ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);

    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    $stage = null;
    if ($stageId) {
        $stage = pm_stage_row($user, $stageId);
        if ((int)$stage['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    }
    $supplierId = pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק') // i18n-ok
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
    $kind = $old['kind'] ?? 'milestone'; // שחרור העיכבון נוצר לבד (pm_retention_sync), לא מהחלון
    $dueKind = (string)($in['due_kind'] ?? ($old['due_kind'] ?? 'date'));
    if ($kind === 'retention') $dueKind = $old['due_kind'];
    if (!in_array($dueKind, ['date', 'stage_done', 'progress', 'handover'], true)) throw new PmError('מועד לא מוכר', 422);
    if (!in_array($dueKind, ['date', 'handover'], true) && !$stageId) throw new PmError('"בגמר שלב" או "בהתקדמות" — רק כשנבחר שלב', 422);
    $duePct = (int)($in['due_pct'] ?? 0);
    if ($dueKind === 'progress' && ($duePct < 1 || $duePct > 100)) throw new PmError('התקדמות: בין 1 ל-100%', 422);
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
        'due_date'     => pm_date($in, 'due_date', 'תאריך יעד'), // i18n-ok
        // מועד (אגף הכספים): תאריך, "בגמר שלב", או כשהשלב מגיע לאחוז התקדמות
        'due_kind'     => $dueKind,
        'due_pct'      => $dueKind === 'progress' ? $duePct : null,
        'payer'        => $payer,
        'needs_lawyer' => !empty($in['needs_lawyer']) && $p['lawyer_id'] ? 1 : 0,
        'notes'        => pm_str($in, 'notes', 2000, false, 'מה כלול'),
        // עיכבון (לפי הגדרות הפרויקט): החלק מהתשלום שנשאר אצל הלקוח עד המסירה
        'retention'    => $kind === 'retention' ? 0 : (int)round($amount * (float)$p['retention_pct'] / 100),
        // "במסירה" — של איזו יחידה (מתחם); בלי — כל המתחם. ברירת מחדל: היחידה של השלב
        'unit_id'      => $dueKind === 'handover' ? (array_key_exists('unit_id', $in) ? pm_unit_of_project((int)($in['unit_id'] ?? 0) ?: null, $projectId)
                          : ($old['unit_id'] ?? ($stageId ? pm_stage_unit($stageId) : null))) : null,
    ];
    // הצמדה: המדד הקובע לתשלום — והפרשי ההצמדה על מה שמשלמים עכשיו
    if ($p['index_on'] && array_key_exists('index_value', $in)) {
        $iv = $in['index_value'];
        if ($iv === '' || $iv === null) { $f['index_value'] = null; $f['index_diff'] = 0; }
        else {
            if (!is_numeric($iv) || $iv <= 0 || $iv > 1e6) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'מדד קובע']);
            $f['index_value'] = (float)$iv;
            $f['index_diff'] = (int)round(($amount - $f['retention']) * ((float)$iv / (float)$p['index_base'] - 1));
        }
    }
    $supplier = pm_contact_name($supplierId);
    $ev = ['title' => $f['title'], 'supplier' => $supplier, 'amount' => pm_money($f['amount']), 'date' => pm_short_date($f['due_date'])];
    $now = pm_now();
    $db = pm_db();
    if ($old) {
        // סכום או ספק השתנו אחרי אישור העו"ד — האישור לא חל על הדרישה החדשה
        $resetLawyer = $old['lawyer_approved_at'] && ((int)$old['amount'] !== $f['amount'] || (int)$old['supplier_id'] !== $supplierId);
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        $db->prepare("UPDATE payments SET $set, updated_at = ?" . ($resetLawyer ? ", lawyer_approved_at = NULL, lawyer_by = '', lawyer_method = '', lawyer_note = ''" : '') . ' WHERE id = ?')
           ->execute(array_merge(array_values($f), [$now, $id]));
        if ((int)$old['amount'] !== $f['amount'] || $old['due_date'] !== $f['due_date'] || $old['title'] !== $f['title']) {
            // i18n-ok-start — משפט שלם לכל צירוף
            $text = $f['due_date']
                ? ($resetLawyer ? 'אבן דרך עודכנה: {title} — {supplier}, {amount} עד {date}. נדרש אישור עו"ד מחדש' : 'אבן דרך עודכנה: {title} — {supplier}, {amount} עד {date}')
                : ($resetLawyer ? 'אבן דרך עודכנה: {title} — {supplier}, {amount}. נדרש אישור עו"ד מחדש' : 'אבן דרך עודכנה: {title} — {supplier}, {amount}');
            // i18n-ok-end
            pm_event($actor, $projectId, 'payment', $text, ['payment' => $id], $ev);
        }
    } else {
        $cols = implode(', ', array_keys($f));
        $qs = implode(', ', array_fill(0, count($f), '?'));
        $db->prepare("INSERT INTO payments ($cols, project_id, vat_rate, created_by, created_at, updated_at) VALUES ($qs, ?, ?, ?, ?, ?)")
           ->execute(array_merge(array_values($f), [$projectId, pm_vat_rate(), $user['id'], $now, $now]));
        $id = (int)$db->lastInsertId();
        pm_event($actor, $projectId, 'payment', $f['due_date'] ? 'אבן דרך לתשלום: {title} — {supplier}, {amount} עד {date}' : 'אבן דרך לתשלום: {title} — {supplier}, {amount}',
            ['payment' => $id], $ev);
    }
    if ($stageId && $kind === 'milestone') pm_retention_sync($user, $projectId, $stageId, $supplierId);
    if ($old && $old['stage_id'] && ((int)$old['stage_id'] !== (int)$stageId || (int)$old['supplier_id'] !== $supplierId)) {
        pm_retention_sync($user, $projectId, (int)$old['stage_id'], (int)$old['supplier_id']);
    }
    return pm_payments($user, $projectId) + ['saved' => $id];
}

/** כמה צריך לשלם באבן הדרך (אגורות): הסכום, פחות העיכבון, ועוד הפרשי ההצמדה. שחרור העיכבון — כולו. */
function pm_payment_due_amount(array $p): int
{
    $base = ($p['kind'] ?? 'milestone') === 'retention' ? (int)$p['amount'] : (int)$p['amount'] - (int)($p['retention'] ?? 0);
    return $base + (int)($p['index_diff'] ?? 0);
}

/**
 * העיכבון של ספק בשלב — אבן דרך אחת "שחרור עיכבון", בסכום כל העיכבונות, שמועדה במסירת הפרויקט.
 * נוצרת / מתעדכנת / מתבטלת לבד כשאבני הדרך משתנות.
 */
function pm_retention_sync(array $user, int $projectId, int $stageId, int $supplierId): void
{
    $db = pm_db();
    $st = $db->prepare("SELECT COALESCE(SUM(retention), 0) FROM payments WHERE project_id = ? AND stage_id = ? AND supplier_id = ? AND kind = 'milestone' AND status != 'cancelled'");
    $st->execute([$projectId, $stageId, $supplierId]);
    $sum = (int)$st->fetchColumn();
    $r = $db->prepare("SELECT * FROM payments WHERE project_id = ? AND stage_id = ? AND supplier_id = ? AND kind = 'retention' AND status != 'cancelled' ORDER BY id DESC LIMIT 1");
    $r->execute([$projectId, $stageId, $supplierId]);
    $rel = $r->fetch();
    $now = pm_now();
    if ($rel && !in_array($rel['status'], ['planned', 'requested'], true)) return; // כבר שולם — לא נוגעים
    if ($sum <= 0) {
        if ($rel) $db->prepare("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([$now, $rel['id']]);
        return;
    }
    $unit = pm_stage_unit($stageId); // מתחם: העיכבון של שלב בדירה — משתחרר במסירת הדירה
    if ($rel) {
        if ((int)$rel['amount'] !== $sum || ($rel['unit_id'] ?? null) != $unit) $db->prepare('UPDATE payments SET amount = ?, unit_id = ?, updated_at = ? WHERE id = ?')->execute([$sum, $unit, $now, $rel['id']]);
        return;
    }
    $p = pm_project_row($user, $projectId);
    $db->prepare("INSERT INTO payments (project_id, stage_id, supplier_id, title, amount, due_date, due_kind, payer, needs_lawyer, kind, vat_rate, notes, created_by, created_at, updated_at, unit_id)
                  VALUES (?, ?, ?, ?, ?, '', 'handover', 'client', ?, 'retention', ?, '', ?, ?, ?, ?)")
       ->execute([$projectId, $stageId, $supplierId, pm_t('שחרור עיכבון'), $sum, $p['lawyer_id'] ? 1 : 0, pm_vat_rate(), $user['id'], $now, $now, $unit]);
}

function pm_short_date(string $ymd): string
{
    return $ymd === '' ? '' : implode('.', array_slice(array_reverse(explode('-', $ymd)), 0, 2));
}

/** "דרישת תשלום": נשלחה ללקוח / לעו"ד (ההודעה עצמה יוצאת מהדפדפן של המנהל). */
function pm_payment_request(array $user, int $id): array
{
    $p = pm_payment_row($user, $id);
    if (!in_array($p['status'], ['planned', 'requested'], true)) throw new PmError('אבן הדרך כבר {status}', 422, ['status' => PM_PAYMENT_STATUSES[$p['status']]]);
    if ($p['status'] === 'planned') {
        pm_db()->prepare("UPDATE payments SET status = 'requested', requested_at = ?, updated_at = ? WHERE id = ?")->execute([pm_now(), pm_now(), $id]);
        pm_event($user, (int)$p['project_id'], 'payment',
            $p['needs_lawyer'] ? 'נשלחה דרישת תשלום: {title} — {supplier}, {amount} (ממתין לאישור עו"ד)' : 'נשלחה דרישת תשלום: {title} — {supplier}, {amount}',
            ['payment' => $id], ['title' => $p['title'], 'supplier' => pm_contact_name((int)$p['supplier_id']), 'amount' => pm_money((int)$p['amount'])]);
    }
    pm_payment_notify_request($user, $p);
    return pm_payments($user, (int)$p['project_id']);
}

/** מיילים לדרישת תשלום (שלב 11): לעו"ד — כשנדרש אישורו; למשלם — כשאפשר לשלם. */
function pm_payment_notify_request(array $actor, array $p, bool $afterLawyer = false): void
{
    $pid = (int)$p['project_id'];
    $pr = pm_db()->prepare('SELECT client_id, lawyer_id FROM projects WHERE id = ?');
    $pr->execute([$pid]);
    $proj = $pr->fetch();
    $supplier = pm_contact_name((int)$p['supplier_id']);
    // "{פרטים}" — בשפת הנמען
    // i18n-none-start — שמות המשתנים בנוסח
    $vars = fn(int $cid) => [
        'פרטים' => $p['due_date'] ? pm_t('{title} — {supplier}, עד {date}', ['title' => $p['title'], 'supplier' => $supplier, 'date' => pm_short_date($p['due_date'])], pm_contact_lang($cid))
                                  : $p['title'] . ' — ' . $supplier,
        'סכום' => pm_money((int)$p['amount']), 'קישור' => pm_notify_link($pid, $cid),
    ];
    // i18n-none-end
    $waitLawyer = $p['needs_lawyer'] && !$p['lawyer_approved_at'] && !$afterLawyer;
    if ($waitLawyer && $proj['lawyer_id']) {
        pm_notify($pid, (int)$proj['lawyer_id'], 'payment_lawyer', $vars((int)$proj['lawyer_id']), $actor);
        return; // המשלם יקבל אחרי אישור העו"ד
    }
    $payer = $p['payer'] === 'lawyer' ? $proj['lawyer_id'] : $proj['client_id'];
    if ($payer) pm_notify($pid, (int)$payer, 'payment_request', $vars((int)$payer), $actor);
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
    if ($byName === '') $byName = pm_contact_name((int)$pr['lawyer_id']) ?: 'עו"ד'; // i18n-ok
    $note = mb_substr(trim($note), 0, 1000);
    pm_db()->prepare('UPDATE payments SET lawyer_approved_at = ?, lawyer_by = ?, lawyer_method = ?, lawyer_note = ?, updated_at = ? WHERE id = ?')
       ->execute([pm_now(), $byName, $method, $note, pm_now(), $id]);
    pm_event($actor, (int)$p['project_id'], 'payment',
        $method === 'manual' ? 'העו"ד אישר לתשלום: {title} — {amount}{note} (נרשם על ידי {by})' : 'העו"ד אישר לתשלום: {title} — {amount}{note} — בקישור',
        ['payment' => $id], ['title' => $p['title'], 'amount' => pm_money((int)$p['amount']), 'note' => $note !== '' ? ": $note" : '', 'by' => $user['display_name']]);
    pm_payment_notify_request($actor, $p, true);
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
    $paidOn = pm_date($in, 'paid_on', 'תאריך התשלום') ?: gmdate('Y-m-d'); // i18n-ok
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
    pm_event($actor, (int)$p['project_id'], 'payment',
        $fileId ? '💳 דווח תשלום: {title} — {amount} ב{method} ({date}){note}, עם אסמכתא' : '💳 דווח תשלום: {title} — {amount} ב{method} ({date}){note}',
        ['payment' => $id], ['title' => $p['title'], 'amount' => pm_money($amount), 'method' => PM_PAY_METHODS[$method],
                             'date' => pm_short_date($paidOn), 'note' => $note !== '' ? ": $note" : '']);
    // מייל לספק: דווח תשלום אליו — לאשר שהכסף התקבל
    // i18n-none-start — שמות המשתנים בנוסח
    pm_notify((int)$p['project_id'], (int)$p['supplier_id'], 'proof_supplier', ['פרטים' => $p['title'], 'סכום' => pm_money($amount),
        'קישור' => pm_notify_link((int)$p['project_id'], (int)$p['supplier_id'])], $actor);
    // i18n-none-end
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
            $status = $total >= pm_payment_due_amount($p) ? 'received' : 'partial';
            pm_event($actor, (int)$p['project_id'], 'payment',
                $status === 'partial' ? '✅ התקבל: {title} — {amount} (נשארה יתרה {balance})' : '✅ התקבל: {title} — {amount} — אבן הדרך נסגרה',
                ['payment' => (int)$p['id']], ['title' => $p['title'], 'amount' => pm_money($got), 'balance' => pm_money(pm_payment_due_amount($p) - $total)]);
        } else {
            $db->prepare("UPDATE payment_proofs SET status = 'rejected', reviewed_at = ?, reviewed_by = ?, review_note = ? WHERE id = ?")
               ->execute([pm_now(), $user['id'], $note, $proofId]);
            $total = pm_payment_received((int)$p['id']);
            $status = $total > 0 ? 'partial' : 'requested';
            pm_event($actor, (int)$p['project_id'], 'payment', '✖ התשלום לא התקבל: {title} — {amount}{note}',
                ['payment' => (int)$p['id']], ['title' => $p['title'], 'amount' => pm_money((int)$x['amount']), 'note' => $note !== '' ? ": $note" : '']);
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
    // שחרור העיכבון — רק במסירה; לא מעכב את סגירת השלב
    $st = pm_db()->prepare("SELECT COUNT(*) FROM payments WHERE stage_id = ? AND kind != 'retention' AND status NOT IN ('received','cancelled')");
    $st->execute([$stageId]);
    if ((int)$st->fetchColumn() === 0) pm_stage_set_status($user, $stage, 'paid', 'כל התשלומים התקבלו'); // i18n-ok
}

function pm_payment_cancel(array $user, int $id, string $note): array
{
    $p = pm_payment_row($user, $id);
    if ($p['status'] === 'cancelled') return pm_payments($user, (int)$p['project_id']);
    if (pm_payment_received($id) > 0) throw new PmError('כבר התקבל כסף באבן הדרך הזו — אי אפשר לבטל', 422);
    pm_db()->prepare("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([pm_now(), $id]);
    $note = mb_substr(trim($note), 0, 500);
    pm_event($user, (int)$p['project_id'], 'payment', 'בוטלה אבן דרך: {title} — {amount}{note}', ['payment' => $id],
        ['title' => $p['title'], 'amount' => pm_money((int)$p['amount']), 'note' => $note !== '' ? ": $note" : '']);
    if ($p['stage_id'] && ($p['kind'] ?? 'milestone') === 'milestone') pm_retention_sync($user, (int)$p['project_id'], (int)$p['stage_id'], (int)$p['supplier_id']);

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

/**
 * חשבונית / קבלה מהספק על תשלום שהתקבל: {id, invoice_no, file_id} — מהמנהל (הקובץ כבר הועלה), או
 * מהקישור של הספק (pm_portal_supplier_invoice). בלי חשבונית אחרי X ימים — התראה (כ4).
 */
function pm_payment_invoice(array $user, array $in, ?array $actor = null): array
{
    $actor ??= $user;
    $p = pm_payment_row($user, (int)($in['id'] ?? 0));
    if (pm_payment_received((int)$p['id']) <= 0) throw new PmError('חשבונית — אחרי שהתשלום התקבל', 422);
    $fileId = (int)($in['file_id'] ?? 0) ?: null;
    if ($fileId) {
        $f = pm_db()->prepare('SELECT 1 FROM files WHERE id = ? AND project_id = ? AND deleted_at IS NULL');
        $f->execute([$fileId, $p['project_id']]);
        if (!$f->fetchColumn()) throw new PmError('הקובץ לא נמצא', 422);
    }
    $no = pm_str($in, 'invoice_no', 40, false, 'מספר חשבונית');
    if (!$fileId && $no === '') throw new PmError('חשבונית: מספר או קובץ', 422);
    pm_db()->prepare('UPDATE payments SET invoice_file_id = ?, invoice_no = ?, invoice_at = ?, updated_at = ? WHERE id = ?')
       ->execute([$fileId, $no, pm_now(), pm_now(), $p['id']]);
    pm_event($actor, (int)$p['project_id'], 'payment', $no !== '' ? '🧾 התקבלה חשבונית {no}: {title} — {supplier}' : '🧾 התקבלה חשבונית: {title} — {supplier}',
        ['payment' => (int)$p['id']], ['no' => $no, 'title' => $p['title'], 'supplier' => pm_contact_name((int)$p['supplier_id'])]);
    return pm_payments($user, (int)$p['project_id']);
}
