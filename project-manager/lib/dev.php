<?php
/**
 * סמכות מפתח — רק למשתמש בתפקיד developer, ורק כשהמתג "🛠️ סמכות מפתח" דולק (כותרת X-PM-Dev: 1).
 *
 * 1. מחיקה גמורה (dev-purge): הישות וכל מה שתלוי בה נמחקים מהמסד, וגם האירועים שלה בציר הזמן —
 *    "כאילו לא הייתה". הקבצים עצמם נשארים בקבצים של הפרויקט (חוץ ממחיקת פרויקט שלם).
 * 2. עקיפת נעילות: עריכת הצעה שאושרה / נדחתה, אבן דרך ששולמה, וסימון "בוצע" בלי דיווח מהשטח.
 *
 * כל מחיקה נרשמת ביומן המערכת (admin / dev.purge) עם כל השורות שנמחקו — לשחזור ידני אם צריך.
 */

declare(strict_types=1);

function pm_dev_mode(array $user): bool
{
    return ($user['role'] ?? '') === 'developer' && ($_SERVER['HTTP_X_PM_DEV'] ?? '') === '1';
}

const PM_PURGE_KINDS = ['quote', 'payment', 'change', 'stage', 'expense', 'fee_payment', 'contract', 'report', 'project'];

/** מחיקה גמורה. מחזיר את מה שנמחק (מספר שורות לכל טבלה) ואת מזהה הפרויקט. */
function pm_dev_purge(array $user, string $kind, int $id): array
{
    if (!pm_dev_mode($user)) throw new PmError('רק המפתח, כשסמכות המפתח פעילה', 403);
    if (!in_array($kind, PM_PURGE_KINDS, true)) throw new PmError('סוג לא מוכר', 422);
    $table = ['quote' => 'quotes', 'payment' => 'payments', 'change' => 'change_orders', 'stage' => 'stages', 'expense' => 'expenses',
              'fee_payment' => 'fee_payments', 'contract' => 'contracts', 'report' => 'reports', 'project' => 'projects'][$kind];
    $db = pm_db();
    $st = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new PmError('לא נמצא', 404);
    $projectId = $kind === 'project' ? $id : (int)$row['project_id'];
    pm_project_row($user, $projectId); // הרשאה: רק פרויקט שהמפתח רואה

    $P = new PmPurge($db, $projectId);
    $db->beginTransaction();
    try {
        match ($kind) {
            'quote'       => $P->quote($id),
            'payment'     => $P->payment($id),
            'change'      => $P->change($id),
            'stage'       => $P->stage($id),
            'expense'     => $P->simple('expenses', 'expense', $id),
            'fee_payment' => $P->simple('fee_payments', 'fee_payment', $id),
            'contract'    => $P->contract($id),
            'report'      => $P->report($id),
            'project'     => $P->project(),
        };
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    if ($kind === 'project') pm_dev_rmdir(pm_files_dir($projectId));
    pm_log('warn', 'admin', 'dev.purge', ['message' => "$kind #$id", 'data' => ['kind' => $kind, 'id' => $id, 'project' => $projectId,
        'counts' => $P->counts, 'rows' => $P->rows]]);
    return ['purged' => ['kind' => $kind, 'id' => $id, 'counts' => $P->counts], 'project_id' => $kind === 'project' ? null : $projectId];
}

/** הסרה של תיקיית הקבצים של פרויקט שנמחק כולו. */
function pm_dev_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

final class PmPurge
{
    public array $counts = [];
    public array $rows = [];

    public function __construct(private PDO $db, private int $projectId) {}

    /** מחיקה + רישום מה נמחק. */
    private function del(string $table, string $where, array $args): void
    {
        $s = $this->db->prepare("SELECT * FROM $table WHERE $where");
        $s->execute($args);
        $rows = $s->fetchAll();
        if (!$rows) return;
        $this->db->prepare("DELETE FROM $table WHERE $where")->execute($args);
        $this->counts[$table] = ($this->counts[$table] ?? 0) + count($rows);
        foreach ($rows as $r) $this->rows[$table][] = $r;
    }

    /** האירועים של הישות בציר הזמן (data_json.<key> = id). */
    private function events(string $key, int $id): void
    {
        $this->del('events', "project_id = ? AND json_extract(data_json, '$.$key') = CAST(? AS INTEGER)", [$this->projectId, $id]);
    }

    private function ids(string $sql, array $args): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    public function payment(int $id, bool $sync = true): void
    {
        $s = $this->db->prepare('SELECT * FROM payments WHERE id = ?');
        $s->execute([$id]);
        $pay = $s->fetch();
        if (!$pay) return;
        $this->del('payment_proofs', 'payment_id = ?', [$id]);
        $this->events('payment', $id);
        $this->del('payments', 'id = ?', [$id]);
        if ($sync && $pay['kind'] === 'milestone' && (int)$pay['retention'] > 0 && $pay['stage_id']) $this->retention((int)$pay['stage_id'], (int)$pay['supplier_id']);
    }

    /**
     * "שחרור עיכבון" של הספק בשלב — לפי אבני הדרך שנשארו: בלי עיכבון — נמחק (כאילו לא היה);
     * עם — הסכום מתעדכן. שחרור ששולם כבר — לא נוגעים.
     */
    private function retention(int $stageId, int $supplierId): void
    {
        $s = $this->db->prepare("SELECT COALESCE(SUM(retention), 0) FROM payments WHERE stage_id = ? AND supplier_id = ? AND kind = 'milestone' AND status != 'cancelled'");
        $s->execute([$stageId, $supplierId]);
        $sum = (int)$s->fetchColumn();
        foreach ($this->ids("SELECT id FROM payments WHERE stage_id = ? AND supplier_id = ? AND kind = 'retention' AND status IN ('planned','requested','cancelled')", [$stageId, $supplierId]) as $rid) {
            if ($sum <= 0) $this->payment($rid, false);
            else $this->db->prepare("UPDATE payments SET amount = ?, updated_at = ? WHERE id = ? AND status != 'cancelled'")->execute([$sum, pm_now(), $rid]);
        }
    }

    public function quote(int $id): void
    {
        $stage = (int)$this->db->query('SELECT stage_id FROM quotes WHERE id = ' . $id)->fetchColumn();
        foreach ($this->ids('SELECT id FROM payments WHERE quote_id = ?', [$id]) as $pid) $this->payment($pid);
        $this->del('approvals', 'quote_id = ?', [$id]);
        $this->events('quote', $id);
        $this->del('quotes', 'id = ?', [$id]);
        $this->restage($stage);
    }

    /**
     * השלב חוזר למצב שמתאים להצעות שנשארו בו — כאילו ההצעה שנמחקה לא הייתה: בלי הצעה מאושרת
     * "מאושר" / "ממתין" חוזר ל"איסוף הצעות", ובלי הצעות בכלל — ל"מתוכנן". בביצוע ואילך — לא נוגעים.
     */
    private function restage(int $stageId): void
    {
        $s = $this->db->prepare('SELECT status FROM stages WHERE id = ?');
        $s->execute([$stageId]);
        $status = $s->fetchColumn();
        if (!in_array($status, ['quotes', 'client_approval', 'lawyer_approval', 'approved'], true)) return;
        if ($this->ids("SELECT id FROM quotes WHERE stage_id = ? AND status = 'approved'", [$stageId])) return;
        $left = $this->ids("SELECT id FROM quotes WHERE stage_id = ? AND status != 'cancelled'", [$stageId]);
        $to = $left ? ($status === 'approved' ? 'quotes' : $status) : 'planned';
        if ($to === $status) return;
        $this->db->prepare('UPDATE stages SET status = ?, updated_at = ? WHERE id = ?')->execute([$to, pm_now(), $stageId]);
        // הספק שנקבע מההצעה המאושרת — יורד, אם אין לו עוד הצעה בשלב
        $this->db->prepare("UPDATE stages SET supplier_id = NULL WHERE id = ? AND supplier_id IS NOT NULL AND supplier_id NOT IN
            (SELECT supplier_id FROM quotes WHERE stage_id = ? AND status != 'cancelled')")->execute([$stageId, $stageId]);
    }

    public function change(int $id): void
    {
        foreach ($this->ids('SELECT id FROM payments WHERE change_order_id = ?', [$id]) as $pid) $this->payment($pid);
        $this->del('change_decisions', 'change_order_id = ?', [$id]);
        $this->events('change', $id);
        $this->del('change_orders', 'id = ?', [$id]);
    }

    public function simple(string $table, string $key, int $id): void
    {
        $this->events($key, $id);
        $this->del($table, 'id = ?', [$id]);
    }

    public function contract(int $id): void
    {
        $this->del('contract_milestones', 'contract_id = ?', [$id]);
        $this->events('contract', $id);
        $this->del('contracts', 'id = ?', [$id]);
    }

    public function report(int $id): void
    {
        $this->del('report_files', 'report_id = ?', [$id]);
        $this->events('report', $id);
        $this->del('reports', 'id = ?', [$id]);
    }

    /** שלב: כל ההצעות, התוספות ואבני הדרך שלו. הוצאות ודיווחים נשארים — בלי שלב. */
    public function stage(int $id): void
    {
        foreach ($this->ids('SELECT id FROM change_orders WHERE stage_id = ?', [$id]) as $x) $this->change($x);
        foreach ($this->ids('SELECT id FROM quotes WHERE stage_id = ?', [$id]) as $x) $this->quote($x);
        foreach ($this->ids('SELECT id FROM payments WHERE stage_id = ?', [$id]) as $x) $this->payment($x);
        $this->db->prepare('UPDATE expenses SET stage_id = NULL WHERE stage_id = ?')->execute([$id]);
        // אבני דרך בחוזים — בלי השלב הזה
        $ms = $this->db->prepare('SELECT m.id, m.stage_ids FROM contract_milestones m JOIN contracts c ON c.id = m.contract_id WHERE c.project_id = ?');
        $ms->execute([$this->projectId]);
        $up = $this->db->prepare('UPDATE contract_milestones SET stage_ids = ? WHERE id = ?');
        foreach ($ms->fetchAll() as $m) {
            $ids = json_decode($m['stage_ids'], true) ?: [];
            if (in_array($id, $ids, true)) $up->execute([json_encode(array_values(array_diff($ids, [$id]))), $m['id']]);
        }
        $this->events('stage', $id);
        $this->del('stages', 'id = ?', [$id]);
    }

    /** פרויקט שלם: מה שלא נמחק ב-CASCADE (אסמכתאות, החלטות על תוספות), ואז הפרויקט. */
    public function project(): void
    {
        $p = $this->projectId;
        $this->del('payment_proofs', 'payment_id IN (SELECT id FROM payments WHERE project_id = ?)', [$p]);
        $this->del('change_decisions', 'change_order_id IN (SELECT id FROM change_orders WHERE project_id = ?)', [$p]);
        $this->del('approvals', 'project_id = ?', [$p]);
        $this->del('payments', 'project_id = ?', [$p]);
        $this->del('change_orders', 'project_id = ?', [$p]);
        $this->del('quotes', 'project_id = ?', [$p]);
        $this->del('contract_milestones', 'contract_id IN (SELECT id FROM contracts WHERE project_id = ?)', [$p]);
        $this->del('contracts', 'project_id = ?', [$p]);
        $this->del('report_files', 'report_id IN (SELECT id FROM reports WHERE project_id = ?)', [$p]);
        $this->del('reports', 'project_id = ?', [$p]);
        $this->del('expenses', 'project_id = ?', [$p]);
        $this->del('fee_payments', 'project_id = ?', [$p]);
        $this->del('fin_alert_sent', 'project_id = ?', [$p]);
        // השאר (שלבים, קבצים, תיקיות, קישורים, ציר הזמן, הודעות, יומן, שותפים) — ב-CASCADE
        $n = [];
        foreach (['stages', 'files', 'events', 'messages', 'appointments'] as $t) {
            $c = $this->db->prepare("SELECT COUNT(*) FROM $t WHERE project_id = ?");
            $c->execute([$p]);
            if ($k = (int)$c->fetchColumn()) $n[$t] = $k;
        }
        $this->del('projects', 'id = ?', [$p]);
        $this->counts += $n;
    }
}
