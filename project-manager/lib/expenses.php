<?php
/**
 * אגף הכספים, כ5 (README, "אגף הכספים"):
 *
 * - הוצאות ישירות (expenses) — מה שהלקוח קנה בעצמו בלי ספק בפרויקט: חומרים, כלים, אגרות, היתרים.
 *   סכום כולל מע"מ (או בלי מע"מ — קבלה מעוסק פטור), תאריך, שלב (לא חובה) וקבלה. נכנסות ל"סוכם"
 *   ול"שולם" (lib/finance.php). רושם המנהל, או הלקוח מהפורטל.
 * - אבני הדרך של שכר הטרחה (fee_payments) — ההכנסות של המנהל: תאריך, סכום, והתקבל.
 * - כרטסת ספק: הצעה ← תוספות ← אבני דרך ← תשלומים ← חשבוניות, עם יתרה מצטברת. בפרויקט אחד, או
 *   בכל הפרויקטים (כרטיס הספק). הספק רואה את שלו בפורטל.
 * - הכנסות שלי — שכר הטרחה בכל הפרויקטים ("💰 כספים").
 */

declare(strict_types=1);

require_once __DIR__ . '/finance.php';

// ================= הוצאות ישירות =================

function pm_expense_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM expenses WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $e = $st->fetch() ?: throw new PmError('ההוצאה לא נמצאה', 404);
    pm_project_row($user, (int)$e['project_id']);
    return $e;
}

/** ההוצאות של פרויקט — מהחדשה. */
function pm_expenses_of(int $projectId): array
{
    $st = pm_db()->prepare('SELECT e.*, s.name AS stage, f.name AS file_name, f.deleted_at AS file_deleted FROM expenses e
                              LEFT JOIN stages s ON s.id = e.stage_id LEFT JOIN files f ON f.id = e.file_id
                             WHERE e.project_id = ? AND e.deleted_at IS NULL ORDER BY e.spent_on DESC, e.id DESC');
    $st->execute([$projectId]);
    return array_map(fn($e) => [
        'id' => (int)$e['id'], 'title' => $e['title'], 'stage_id' => $e['stage_id'] ? (int)$e['stage_id'] : null, 'stage' => (string)$e['stage'],
        'amount' => pm_m((int)$e['amount'], pm_net((int)$e['amount'], (float)$e['vat_rate'])), 'vat_rate' => (float)$e['vat_rate'],
        'spent_on' => $e['spent_on'], 'notes' => $e['notes'], 'by_role' => $e['by_role'], 'by_name' => $e['by_name'],
        'file' => $e['file_id'] && !$e['file_deleted'] ? ['id' => (int)$e['file_id'], 'name' => $e['file_name']] : null,
    ], $st->fetchAll());
}

/** הסכום, השלב והתאריך — בדיקה משותפת למנהל ולפורטל. */
function pm_expense_fields(int $projectId, array $in): array
{
    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    if ($stageId) {
        $s = pm_db()->prepare('SELECT 1 FROM stages WHERE id = ? AND project_id = ?');
        $s->execute([$stageId, $projectId]);
        if (!$s->fetchColumn()) throw new PmError('השלב לא שייך לפרויקט', 422);
    }
    $spent = pm_date($in, 'spent_on', 'תאריך') ?: pm_today(); // i18n-ok
    if ($spent > pm_today(1)) throw new PmError('תאריך: עוד לא הגיע', 422);
    return [
        'title' => pm_str($in, 'title', 200, true, 'מה נקנה'), // i18n-ok
        'amount' => pm_quote_amount($in['amount'] ?? null),
        'vat_rate' => array_key_exists('vat', $in) && !$in['vat'] ? 0.0 : pm_vat_rate(),
        'spent_on' => $spent, 'stage_id' => $stageId,
        'notes' => pm_str($in, 'notes', 1000, false, 'הערה'), // i18n-ok
    ];
}

/** קבלה: קובץ של הפרויקט — וגלוי בפורטל (הלקוח קנה, הוא צריך לראות). */
function pm_expense_file(int $projectId, ?int $fileId): ?int
{
    // סוג "חשבונית" (קבלה), מ"ממתין למיון" — לתיקיית הלקוח
    return pm_file_claim($projectId, $fileId, 'invoice', null, true);
}

/** הוצאה חדשה או עדכון: {id?, project_id, title, amount, vat, spent_on, stage_id?, notes?, file_id?} */
function pm_expense_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? pm_expense_row($user, $id) : null;
    $projectId = $old ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    pm_project_row($user, $projectId);
    $f = pm_expense_fields($projectId, $in);
    $fileId = array_key_exists('file_id', $in) ? pm_expense_file($projectId, (int)$in['file_id'] ?: null) : ($old['file_id'] ?? null);
    $now = pm_now();
    $db = pm_db();
    if ($old) {
        $db->prepare('UPDATE expenses SET title = ?, amount = ?, vat_rate = ?, spent_on = ?, stage_id = ?, notes = ?, file_id = ?, updated_at = ? WHERE id = ?')
           ->execute([$f['title'], $f['amount'], $f['vat_rate'], $f['spent_on'], $f['stage_id'], $f['notes'], $fileId, $now, $id]);
        pm_event($user, $projectId, 'expense', 'עודכנה הוצאה: {title} — {amount}', ['expense' => $id], ['title' => $f['title'], 'amount' => pm_money($f['amount'])]);
    } else {
        $db->prepare('INSERT INTO expenses (project_id, stage_id, title, amount, vat_rate, spent_on, file_id, notes, by_role, by_name, created_by, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$projectId, $f['stage_id'], $f['title'], $f['amount'], $f['vat_rate'], $f['spent_on'], $fileId, $f['notes'], 'manager', $user['display_name'], $user['id'], $now, $now]);
        $id = (int)$db->lastInsertId();
        pm_event($user, $projectId, 'expense', '🧾 הוצאה ישירה: {title} — {amount}', ['expense' => $id], ['title' => $f['title'], 'amount' => pm_money($f['amount'])]);
    }
    return pm_finance($user, $projectId) + ['saved' => $id];
}

/** מהפורטל: הלקוח רושם קנייה שלו, עם צילום קבלה. $a — הקישור (pm_portal_auth). */
function pm_expense_add_portal(array $a, array $in, ?int $fileId): int
{
    $pid = (int)$a['project_id'];
    $f = pm_expense_fields($pid, $in);
    $now = pm_now();
    $db = pm_db();
    $db->prepare('INSERT INTO expenses (project_id, stage_id, title, amount, vat_rate, spent_on, file_id, notes, by_role, by_name, created_by, created_at, updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$pid, $f['stage_id'], $f['title'], $f['amount'], $f['vat_rate'], $f['spent_on'], $fileId, $f['notes'], $a['role'], $a['name'], null, $now, $now]);
    $id = (int)$db->lastInsertId();
    pm_event($a['actor'], $pid, 'expense', '🧾 {name} רשם הוצאה: {title} — {amount}', ['expense' => $id],
        ['name' => $a['name'], 'title' => $f['title'], 'amount' => pm_money($f['amount'])]);
    return $id;
}

function pm_expense_delete(array $user, int $id): array
{
    $e = pm_expense_row($user, $id);
    pm_db()->prepare('UPDATE expenses SET deleted_at = ? WHERE id = ?')->execute([pm_now(), $id]);
    pm_event($user, (int)$e['project_id'], 'expense', 'נמחקה הוצאה: {title} — {amount}', ['expense' => $id], ['title' => $e['title'], 'amount' => pm_money((int)$e['amount'])]);
    return pm_finance($user, (int)$e['project_id']);
}

// ================= שכר הטרחה — אבני דרך =================

function pm_fee_payments_of(int $projectId): array
{
    $st = pm_db()->prepare('SELECT * FROM fee_payments WHERE project_id = ? ORDER BY CASE WHEN due_date = \'\' THEN 1 ELSE 0 END, due_date, id');
    $st->execute([$projectId]);
    $today = pm_today();
    $vat = pm_vat_rate();
    return array_map(fn($x) => [
        'id' => (int)$x['id'], 'title' => $x['title'], 'amount' => pm_m((int)$x['amount'], pm_net((int)$x['amount'], $vat)),
        'due_date' => $x['due_date'], 'notes' => $x['notes'],
        'received' => $x['received_on'] !== null, 'received_on' => $x['received_on'],
        'received_amount' => $x['received_amount'] !== null ? (int)$x['received_amount'] / 100 : null,
        'late' => $x['received_on'] === null && $x['due_date'] !== '' && $x['due_date'] < $today,
    ], $st->fetchAll());
}

function pm_fee_payment_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM fee_payments WHERE id = ?');
    $st->execute([$id]);
    $x = $st->fetch() ?: throw new PmError('אבן הדרך לא נמצאה', 404);
    pm_project_row($user, (int)$x['project_id']);
    return $x;
}

/** {id?, project_id, title, amount, due_date} */
function pm_fee_payment_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? pm_fee_payment_row($user, $id) : null;
    $projectId = $old ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    pm_project_row($user, $projectId);
    $title = pm_str($in, 'title', 100, true, 'שם');
    $amount = pm_quote_amount($in['amount'] ?? null);
    $due = pm_date($in, 'due_date', 'תאריך יעד'); // i18n-ok
    $notes = pm_str($in, 'notes', 500, false, 'הערה'); // i18n-ok
    $now = pm_now();
    $db = pm_db();
    if ($old) {
        $db->prepare('UPDATE fee_payments SET title = ?, amount = ?, due_date = ?, notes = ?, updated_at = ? WHERE id = ?')->execute([$title, $amount, $due, $notes, $now, $id]);
    } else {
        $db->prepare('INSERT INTO fee_payments (project_id, title, amount, due_date, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$projectId, $title, $amount, $due, $notes, $user['id'], $now, $now]);
        $id = (int)$db->lastInsertId();
    }
    return pm_finance($user, $projectId) + ['saved' => $id];
}

/** התקבל (או ביטול "התקבל"): {id, received (bool), received_on, amount?} */
function pm_fee_payment_receive(array $user, array $in): array
{
    $x = pm_fee_payment_row($user, (int)($in['id'] ?? 0));
    $projectId = (int)$x['project_id'];
    if (empty($in['received'])) {
        pm_db()->prepare('UPDATE fee_payments SET received_on = NULL, received_amount = NULL, updated_at = ? WHERE id = ?')->execute([pm_now(), $x['id']]);
        return pm_finance($user, $projectId);
    }
    $on = pm_date($in, 'received_on', 'תאריך') ?: pm_today(); // i18n-ok
    $amount = ($in['amount'] ?? '') === '' ? (int)$x['amount'] : pm_quote_amount($in['amount']);
    pm_db()->prepare('UPDATE fee_payments SET received_on = ?, received_amount = ?, updated_at = ? WHERE id = ?')->execute([$on, $amount, pm_now(), $x['id']]);
    pm_event($user, $projectId, 'payment', '💼 התקבל שכר טרחה: {title} — {amount}', ['fee_payment' => (int)$x['id']], ['title' => $x['title'], 'amount' => pm_money($amount)]);
    return pm_finance($user, $projectId);
}

function pm_fee_payment_delete(array $user, int $id): array
{
    $x = pm_fee_payment_row($user, $id);
    pm_db()->prepare('DELETE FROM fee_payments WHERE id = ?')->execute([$id]);
    return pm_finance($user, (int)$x['project_id']);
}

// ================= כרטסת ספק =================

/**
 * הכרטסת של ספק בפרויקט, לפי התאריך: הצעה שאושרה (+), תוספת שאושרה (±), אבן דרך (מידע), תשלום
 * שהתקבל (−), חשבונית (מידע). יתרה מצטברת = מה שסוכם פחות מה ששולם.
 */
function pm_ledger_of(int $projectId, int $supplierId, ?string $lang = null): array
{
    $db = pm_db();
    $vat = pm_vat_rate();
    $ex = $db->prepare('SELECT vat_exempt FROM contacts WHERE id = ?');
    $ex->execute([$supplierId]);
    $exempt = (bool)$ex->fetchColumn();
    $rate = fn($stored) => $exempt ? 0.0 : ($stored !== null ? (float)$stored : $vat);
    $E = [];
    $add = function (string $at, string $kind, string $text, int $delta, float $r, array $extra = []) use (&$E) {
        $E[] = ['at' => $at, 'kind' => $kind, 'text' => $text, 'delta' => $delta, 'rate' => $r] + $extra + ['files' => [], 'stage' => '', 'when' => '', 'ref' => null];
    };
    // לכל שורה: השלב (עם היחידה), הקבצים, ולאן היא מובילה באפליקציה
    $st = $db->prepare('SELECT s.id, s.name, u.name AS unit FROM stages s LEFT JOIN units u ON u.id = s.unit_id WHERE s.project_id = ?');
    $st->execute([$projectId]);
    $stageName = [];
    foreach ($st->fetchAll() as $s) $stageName[(int)$s['id']] = pm_t($s['name'], [], $lang) . ($s['unit'] ? ' · ' . $s['unit'] : '');
    $files = function (...$ids) use ($db): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $f = $db->query('SELECT id, name FROM files WHERE deleted_at IS NULL AND id IN (' . implode(',', $ids) . ')');
        return array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name']], $f->fetchAll());
    };
    $whenOf = fn(array $p) => match ($p['due_kind'] ?? 'date') {
        'advance'    => pm_t('מקדמה — בתחילת העבודה', [], $lang),
        'stage_done' => pm_t('בגמר השלב', [], $lang),
        'progress'   => pm_t('בהתקדמות {n}%', ['n' => (int)$p['due_pct']], $lang),
        'handover'   => pm_t('במסירה', [], $lang),
        default      => $p['due_date'] !== '' ? pm_t('עד {date}', ['date' => pm_short_date($p['due_date'])], $lang) : '',
    };

    // הצעות שאושרו — העדכנית לכל שלב (כמו בהתחייבות)
    $q = $db->prepare("SELECT q.*, s.name AS stage, (SELECT MAX(created_at) FROM approvals a WHERE a.quote_id = q.id AND a.decision = 'approved') AS approved_at
                         FROM quotes q JOIN stages s ON s.id = q.stage_id WHERE q.project_id = ? AND q.supplier_id = ? AND q.status = 'approved' ORDER BY q.id");
    $q->execute([$projectId, $supplierId]);
    $byStage = [];
    foreach ($q->fetchAll() as $x) $byStage[(int)$x['stage_id']] = $x;
    // שלב בלי הצעה מאושרת — ההתחייבות היא אבני הדרך (כמו בכספים, pm_finance: "בלי הצעה — מאבני הדרך").
    // תשלום בלי שלב (מקדמה) — על חשבון השלב העיקרי של הספק: ההצעה הגדולה, או השלב היחיד שלו
    $mainStage = pm_main_stages($projectId)[$supplierId] ?? 0;
    foreach ($byStage as $x) {
        $add(substr((string)($x['approved_at'] ?: $x['updated_at']), 0, 10), 'quote', pm_t('הצעה שאושרה — {stage}', ['stage' => pm_t($x['stage'], [], $lang)], $lang), (int)$x['amount'], $rate($x['vat_rate']),
            ['stage' => $stageName[(int)$x['stage_id']] ?? '', 'files' => $files($x['file_id']), 'ref' => ['type' => 'quote', 'id' => (int)$x['id']]]);
    }
    $co = $db->prepare("SELECT o.*, s.name AS stage FROM change_orders o JOIN stages s ON s.id = o.stage_id WHERE o.project_id = ? AND o.supplier_id = ? AND o.status = 'approved'");
    $co->execute([$projectId, $supplierId]);
    foreach ($co->fetchAll() as $x) {
        $add(substr($x['updated_at'], 0, 10), 'change', pm_t((int)$x['amount'] > 0 ? 'תוספת — {reason}' : 'הפחתה — {reason}', ['reason' => $x['reason']], $lang), (int)$x['amount'], $rate($x['vat_rate']),
            ['stage' => $stageName[(int)$x['stage_id']] ?? '', 'files' => $files($x['file_id']), 'ref' => ['type' => 'change', 'id' => (int)$x['id']]]);
    }
    $pay = $db->prepare("SELECT * FROM payments WHERE project_id = ? AND supplier_id = ? AND status != 'cancelled' ORDER BY id");
    $pay->execute([$projectId, $supplierId]);
    $payments = $pay->fetchAll();
    $missing = 0;
    foreach ($payments as $p) {
        $r = $rate($p['vat_rate']);
        $ref = ['type' => 'payment', 'id' => (int)$p['id']];
        $stg = $p['stage_id'] ? ($stageName[(int)$p['stage_id']] ?? '') : '';
        if ((int)$p['index_diff']) $add(substr($p['created_at'], 0, 10), 'index', pm_t('הפרשי הצמדה — {title}', ['title' => $p['title']], $lang), (int)$p['index_diff'], $r, ['ref' => $ref, 'stage' => $stg]);
        $effStage = (int)$p['stage_id'] ?: $mainStage;
        // אין הצעה מאושרת בשלב — אבן הדרך היא ההתחייבות (בלי הפרשי ההצמדה — הם בשורה משלהם)
        $commit = !isset($byStage[$effStage]) ? pm_payment_due_amount($p) - (int)$p['index_diff'] : 0;
        $add(substr($p['created_at'], 0, 10), 'milestone', $p['title'], $commit, $r, ['commit' => $commit > 0, 'amount' => pm_payment_due_amount($p) / 100, 'payment_id' => (int)$p['id'],
            'label' => pm_t(pm_payment_label($p), [], $lang), 'due_kind' => $p['due_kind'], 'due_date' => $p['due_date'],
            'stage' => $stg, 'when' => $whenOf($p), 'files' => $files($p['file_id'] ?? null), 'ref' => $ref, 'status' => $p['status'], 'notes' => $p['notes']]);
        $pr = $db->prepare("SELECT * FROM payment_proofs WHERE payment_id = ? AND status = 'received' ORDER BY id");
        $pr->execute([$p['id']]);
        $got = false;
        foreach ($pr->fetchAll() as $x) {
            $got = true;
            $add($x['paid_on'], 'paid', pm_t('התקבל תשלום — {title}', ['title' => $p['title']], $lang), -(int)$x['received_amount'], $r, ['payment_id' => (int)$p['id'],
                'stage' => $stg, 'when' => pm_t(PM_PAY_METHODS[$x['method']] ?? $x['method'], [], $lang) . (($x['ref_no'] ?? '') !== '' ? ' #' . $x['ref_no'] : '') . ($x['note'] !== '' ? ' · ' . $x['note'] : ''), 'files' => $files($x['file_id']), 'ref' => $ref]);
        }
        if ($p['invoice_at']) $add(substr($p['invoice_at'], 0, 10), 'invoice', $p['invoice_no'] !== '' ? pm_t('חשבונית {no} — {title}', ['no' => $p['invoice_no'], 'title' => $p['title']], $lang) : pm_t('חשבונית — {title}', ['title' => $p['title']], $lang), 0, $r,
            ['stage' => $stg, 'files' => $files($p['invoice_file_id']), 'ref' => $ref]);
        elseif ($got) $missing++;
    }
    // לפי התאריך; באותו יום — התחייבות לפני תשלום
    $order = ['quote' => 0, 'change' => 1, 'index' => 2, 'milestone' => 3, 'paid' => 4, 'invoice' => 5];
    usort($E, fn($a, $b) => [$a['at'], $order[$a['kind']]] <=> [$b['at'], $order[$b['kind']]]);
    $bal = 0;
    $byMilestones = false;
    $committed = [0, 0];
    $paid = [0, 0];
    foreach ($E as &$e) {
        $d = $e['delta'];
        // התחייבות: כל מה שמוסיף, וגם הפחתה והפרש הצמדה שלילי (תשלום — לא)
        if ($d > 0 || in_array($e['kind'], ['change', 'index'], true)) { $committed[0] += $d; $committed[1] += pm_net($d, $e['rate']); }
        if (!empty($e['commit'])) $byMilestones = true;
        if ($e['kind'] === 'paid') { $paid[0] -= $d; $paid[1] += pm_net(-$d, $e['rate']); }
        $bal += $d;
        $e['delta'] = pm_m($d, pm_net($d, $e['rate']));
        $e['balance'] = $bal / 100;
        unset($e['rate']);
    }
    unset($e);
    $b = $committed[0] - $paid[0];
    return [
        'entries' => $E,
        'committed' => pm_m($committed[0], $committed[1]), 'paid' => pm_m($paid[0], $paid[1]), 'balance' => pm_m($b, $committed[1] - $paid[1]),
        'missing_invoices' => $missing,
        // חלק מה"סוכם" — מאבני הדרך, כי אין עליהן הצעה מאושרת (המסך מסביר)
        'by_milestones' => $byMilestones,
    ];
}

/** הכרטסת — למנהל: בפרויקט אחד, או (בלי project_id) בכל הפרויקטים שלו — מכרטיס הספק. */
function pm_ledger(array $user, array $in): array
{
    $supplierId = (int)($in['supplier_id'] ?? 0);
    $projectId = (int)($in['project_id'] ?? 0);
    $c = pm_db()->prepare("SELECT id, name, owner_id FROM contacts WHERE id = ? AND kind = 'supplier'");
    $c->execute([$supplierId]);
    $sup = $c->fetch() ?: throw new PmError('הספק לא נמצא', 404);
    if ($projectId) {
        pm_project_row($user, $projectId);
        return ['supplier' => ['id' => $supplierId, 'name' => $sup['name']], 'projects' => [['id' => $projectId, 'name' => pm_project_name($projectId)] + pm_ledger_of($projectId, $supplierId)]];
    }
    [$where, $args] = pm_project_clause($user, 'p');
    $st = pm_db()->prepare("SELECT p.id, p.name FROM projects p WHERE $where AND p.id IN (
        SELECT project_id FROM quotes WHERE supplier_id = ? AND status = 'approved' UNION SELECT project_id FROM payments WHERE supplier_id = ?) ORDER BY p.last_activity_at DESC");
    $st->execute(array_merge($args, [$supplierId, $supplierId]));
    $out = [];
    $T = [];
    foreach ($st->fetchAll() as $p) {
        $l = pm_ledger_of((int)$p['id'], $supplierId);
        $out[] = ['id' => (int)$p['id'], 'name' => $p['name']] + $l;
        foreach (['committed', 'paid', 'balance'] as $k) { $T[$k][0] = ($T[$k][0] ?? 0) + $l[$k]['g']; $T[$k][1] = ($T[$k][1] ?? 0) + $l[$k]['n']; }
        $T['missing'] = ($T['missing'] ?? 0) + $l['missing_invoices'];
    }
    $M = fn($k) => pm_m((int)round(($T[$k][0] ?? 0) * 100), (int)round(($T[$k][1] ?? 0) * 100));
    return ['supplier' => ['id' => $supplierId, 'name' => $sup['name']], 'projects' => $out,
        'totals' => ['committed' => $M('committed'), 'paid' => $M('paid'), 'balance' => $M('balance'), 'missing_invoices' => $T['missing'] ?? 0]];
}

function pm_project_name(int $id): string
{
    $st = pm_db()->prepare('SELECT name FROM projects WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

// ================= הכנסות שלי =================

/** שכר הטרחה בכל הפרויקטים של המנהל: סוכם, התקבל, צפוי (יתרה), באיחור, והקרוב. */
function pm_my_income(array $projects): array
{
    $rows = [];
    $T = ['amount' => [0, 0], 'received' => [0, 0], 'balance' => [0, 0], 'late' => [0, 0]];
    $next = null;
    foreach ($projects as $p) {
        $f = pm_finance_of((int)$p['id']);
        if (!$f['fee']) continue;
        $F = $f['fee'];
        $late = 0;
        foreach ($F['payments'] as $x) {
            if ($x['late']) $late += (int)round($x['amount']['g'] * 100);
            if (!$x['received'] && $x['due_date'] !== '' && (!$next || $x['due_date'] < $next['date'])) {
                $next = ['project_id' => (int)$p['id'], 'project' => $p['name'], 'title' => $x['title'], 'amount' => $x['amount'], 'date' => $x['due_date']];
            }
        }
        $vat = pm_vat_rate();
        foreach (['amount' => $F['amount'], 'received' => $F['paid'], 'balance' => $F['balance']] as $k => $m) {
            $T[$k][0] += (int)round($m['g'] * 100);
            $T[$k][1] += (int)round($m['n'] * 100);
        }
        $T['late'][0] += $late;
        $T['late'][1] += pm_net($late, $vat);
        $rows[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'amount' => $F['amount'], 'received' => $F['paid'], 'balance' => $F['balance'],
            'late' => pm_m($late, pm_net($late, $vat)), 'type' => $F['type'], 'value' => $F['value'], 'milestones' => count($F['payments'])];
    }
    return ['totals' => array_map(fn($v) => pm_m($v[0], $v[1]), $T), 'projects' => $rows, 'next' => $next];
}
