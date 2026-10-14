<?php
/**
 * התקשרות — ספק × מקצוע (שלב) בפרויקט: "אינסטלציה עם נתנאל פלאי". היחידה שכל מסמך, תשלום ותיעוד
 * שייכים אליה (README, "התקשרות").
 *
 * - pm_supplier_stages   — באילו שלבים הספק עובד בפרויקט (שלב שהוא הספק שלו, הצעה, תוספת, אבן דרך)
 * - pm_payments_auto_stage — אבן דרך בלי שלב, לספק שעובד בשלב אחד בלבד — לשלב הזה (החלטת המשתמש:
 *   "לשייך לבד לשלב שלו"); בכמה שלבים — "שיוך לשלב" בממשק (pm_payments_set_stage)
 * - pm_trade_folder      — תת-תיקייה לכל מקצוע בתוך תיקיית הספק ("נתנאל פלאי / אינסטלציה")
 */

declare(strict_types=1);

/** השלבים שהספק עובד בהם בפרויקט — לפי סדר השלבים. */
function pm_supplier_stages(int $projectId, int $supplierId): array
{
    $st = pm_db()->prepare("SELECT s.id FROM stages s WHERE s.project_id = ? AND s.id IN (
            SELECT id FROM stages WHERE project_id = ? AND supplier_id = ?
            UNION SELECT stage_id FROM quotes WHERE project_id = ? AND supplier_id = ? AND status NOT IN ('rejected', 'declined', 'cancelled')
            UNION SELECT stage_id FROM change_orders WHERE project_id = ? AND supplier_id = ? AND status != 'cancelled'
            UNION SELECT stage_id FROM payments WHERE project_id = ? AND supplier_id = ? AND stage_id IS NOT NULL AND status != 'cancelled')
        ORDER BY s.sort, s.id");
    $st->execute([$projectId, $projectId, $supplierId, $projectId, $supplierId, $projectId, $supplierId, $projectId, $supplierId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * אבני דרך בלי שלב (לא מקדמה — היא בכוונה בלי שלב) של ספק שעובד בשלב אחד בלבד — לשלב הזה.
 * רץ בכל פתיחה של הכספים: זול, ולא משנה דבר כשאין מה לשייך. מחזיר כמה שויכו.
 */
function pm_payments_auto_stage(int $projectId): int
{
    $db = pm_db();
    $st = $db->prepare("SELECT DISTINCT supplier_id FROM payments WHERE project_id = ? AND stage_id IS NULL AND due_kind != 'advance'
                         AND kind = 'milestone' AND status != 'cancelled'");
    $st->execute([$projectId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $stages = pm_supplier_stages($projectId, (int)$sid);
        if (count($stages) !== 1) continue;
        $u = $db->prepare("UPDATE payments SET stage_id = ?, updated_at = ? WHERE project_id = ? AND supplier_id = ? AND stage_id IS NULL
                            AND due_kind != 'advance' AND kind = 'milestone' AND status != 'cancelled'");
        $u->execute([$stages[0], pm_now(), $projectId, $sid]);
        if ($u->rowCount()) {
            $n += $u->rowCount();
            pm_audit('system', 'payments.auto_stage', 'project', $projectId, ['supplier' => (int)$sid, 'stage' => $stages[0], 'count' => $u->rowCount()]);
        }
    }
    return $n;
}

/** "שיוך לשלב": כל אבני הדרך בלי שלב של הספק (לא מקדמה) — לשלב שנבחר. {project_id, supplier_id, stage_id} */
function pm_payments_set_stage(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    pm_project_row($user, $projectId);
    $supplierId = (int)($in['supplier_id'] ?? 0);
    $stage = pm_stage_row($user, (int)($in['stage_id'] ?? 0));
    if ((int)$stage['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    $u = pm_db()->prepare("UPDATE payments SET stage_id = ?, updated_at = ? WHERE project_id = ? AND supplier_id = ? AND stage_id IS NULL
                            AND due_kind != 'advance' AND kind = 'milestone' AND status != 'cancelled'");
    $u->execute([(int)$stage['id'], pm_now(), $projectId, $supplierId]);
    $n = $u->rowCount();
    if ($n) {
        pm_retention_sync($user, $projectId, (int)$stage['id'], $supplierId);
        pm_event($user, $projectId, 'finance', '🧱 {n} אבני דרך של {supplier} שויכו לשלב {stage}', ['stage' => (int)$stage['id']],
            ['n' => $n, 'supplier' => pm_contact_name($supplierId), 'stage' => $stage['name']]);
    }
    return pm_finance($user, $projectId) + ['moved' => $n];
}

/** שם תיקיית המקצוע: שם השלב, ובמתחם — עם היחידה. */
function pm_trade_folder_name(int $stageId): string
{
    $st = pm_db()->prepare('SELECT s.name, u.name AS unit FROM stages s LEFT JOIN units u ON u.id = s.unit_id WHERE s.id = ?');
    $st->execute([$stageId]);
    $s = $st->fetch();
    return $s ? $s['name'] . ($s['unit'] ? ' · ' . $s['unit'] : '') : '';
}

/** תת-תיקייה למקצוע בתוך תיקיית הספק — נוצרת אם חסרה. null — אין לספק תיקייה בפרויקט. */
function pm_trade_folder(int $projectId, int $supplierId, int $stageId): ?int
{
    $db = pm_db();
    $p = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'contact' AND contact_id = ? AND parent_id IS NULL");
    $p->execute([$projectId, $supplierId]);
    $parent = (int)$p->fetchColumn();
    if (!$parent) return null;
    $f = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND parent_id = ? AND kind = 'trade' AND stage_id = ?");
    $f->execute([$projectId, $parent, $stageId]);
    $id = (int)$f->fetchColumn();
    if ($id) return $id;
    $db->prepare("INSERT INTO folders (project_id, parent_id, kind, contact_id, stage_id, name, sort, created_at) VALUES (?, ?, 'trade', ?, ?, ?, 0, ?)")
       ->execute([$projectId, $parent, $supplierId, $stageId, pm_trade_folder_name($stageId), pm_now()]);
    return (int)$db->lastInsertId();
}

/** לכל תיקיית ספק — תת-תיקייה לכל מקצוע שהוא עובד בו (pm_ensure_folders). */
function pm_ensure_trade_folders(int $projectId): void
{
    $st = pm_db()->prepare("SELECT contact_id FROM folders WHERE project_id = ? AND kind = 'contact' AND parent_id IS NULL AND contact_id IS NOT NULL");
    $st->execute([$projectId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        foreach (pm_supplier_stages($projectId, (int)$cid) as $sid) pm_trade_folder($projectId, (int)$cid, $sid);
    }
}

/**
 * מי עובד בכל שלב: stage_id => [{supplier_id, name, quotes, approved, milestones, amount}] — מהצעות, אבני דרך ותוספות.
 * כך שלב "יודע" על הספק ואבני הדרך שנבנו לו מהכספים, גם בלי שנבחר ספק בחלון השלב.
 */
function pm_stage_engagements(int $projectId): array
{
    $st = pm_db()->prepare("SELECT x.stage_id, x.supplier_id, c.name, SUM(x.q) AS quotes, SUM(x.a) AS approved, SUM(x.m) AS milestones, SUM(x.amt) AS amount FROM (
            SELECT stage_id, supplier_id, 1 AS q, CASE WHEN status = 'approved' THEN 1 ELSE 0 END AS a, 0 AS m, 0 AS amt
              FROM quotes WHERE project_id = ? AND status NOT IN ('rejected', 'declined', 'cancelled')
            UNION ALL SELECT stage_id, supplier_id, 0, 0, 1, amount FROM payments
              WHERE project_id = ? AND stage_id IS NOT NULL AND status != 'cancelled' AND kind = 'milestone'
            UNION ALL SELECT stage_id, supplier_id, 0, 0, 0, 0 FROM change_orders WHERE project_id = ? AND status != 'cancelled'
        ) x JOIN contacts c ON c.id = x.supplier_id GROUP BY x.stage_id, x.supplier_id ORDER BY x.stage_id, SUM(x.a) DESC, SUM(x.m) DESC");
    $st->execute([$projectId, $projectId, $projectId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['stage_id']][] = ['supplier_id' => (int)$r['supplier_id'], 'name' => $r['name'],
        'quotes' => (int)$r['quotes'], 'approved' => (int)$r['approved'] > 0, 'milestones' => (int)$r['milestones'], 'amount' => (int)$r['amount'] / 100];
    return $out;
}

/** שלב בלי ספק, שבדיוק ספק אחד עובד בו (הצעה / אבני דרך) — הספק של השלב. מחזיר את ההתקשרויות. */
function pm_stage_sync_suppliers(int $projectId): array
{
    $eng = pm_stage_engagements($projectId);
    $db = pm_db();
    foreach ($eng as $sid => $list) {
        if (count($list) !== 1) continue;
        $u = $db->prepare('UPDATE stages SET supplier_id = ? WHERE id = ? AND project_id = ? AND supplier_id IS NULL');
        $u->execute([$list[0]['supplier_id'], $sid, $projectId]);
        if ($u->rowCount()) pm_audit('system', 'stage.auto_supplier', 'stage', $sid, ['supplier' => $list[0]['supplier_id']]);
    }
    return $eng;
}
