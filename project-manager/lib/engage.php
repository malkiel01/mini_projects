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
            UNION SELECT stage_id FROM payments WHERE project_id = ? AND supplier_id = ? AND stage_id IS NOT NULL AND status != 'cancelled'
            UNION SELECT CAST(j.value AS INTEGER) FROM project_members m, json_each('[' || substr(m.role_key, 7) || ']') j
                  WHERE m.project_id = ? AND m.contact_id = ? AND m.role_key LIKE 'stage:%')
        ORDER BY s.sort, s.id");
    $st->execute([$projectId, $projectId, $supplierId, $projectId, $supplierId, $projectId, $supplierId, $projectId, $supplierId, $projectId, $supplierId]);
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
            // העיכבון שנוכה מהן — לשחרור של השלב (כמו ב"שיוך לשלב"), אחרת הוא לא משתחרר לעולם
            pm_retention_sync(['id' => 0, 'display_name' => 'מערכת'], $projectId, (int)$stages[0], (int)$sid);
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
    // במתחם היא בתוך קבוצת המקצוע ("חשמל" ← דירה) — לפי ספק ושלב, לא לפי ההורה (lib/links.php)
    $f = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'trade' AND contact_id = ? AND stage_id = ?");
    $f->execute([$projectId, $supplierId, $stageId]);
    $id = (int)$f->fetchColumn();
    if ($id) return $id;
    $db->prepare("INSERT INTO folders (project_id, parent_id, kind, contact_id, stage_id, name, sort, created_at) VALUES (?, ?, 'trade', ?, ?, ?, 0, ?)")
       ->execute([$projectId, $parent, $supplierId, $stageId, pm_trade_folder_name($stageId), pm_now()]);
    return (int)$db->lastInsertId();
}

/** לכל תיקיית ספק — תת-תיקייה לכל מקצוע שהוא עובד בו (pm_ensure_folders). */
function pm_ensure_trade_folders(int $projectId): void
{
    pm_ensure_link_tree($projectId); // ספק ← מקצוע, ושלב ← ספק (lib/links.php)
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

/**
 * שיוך כללי של קבצים להתקשרות (ספק × שלב) — "מסמך אחר" (ביטוח, רישיון, תעודת אחריות, פרוטוקול):
 * {project_id, file_ids[], supplier_id, stage_id?, note?}. הקבצים עוברים ל"ספק / מקצוע" (אם עוד לא מוינו).
 */
function pm_file_ref_add(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $supplierId = pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק') // i18n-ok
        ?? throw new PmError('ספק: שדה חובה', 422);
    pm_supplier_require($projectId, $supplierId, 'project'); // מסמך של ספק — רק של ספק בתיק
    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    if ($stageId) {
        $s = pm_stage_row($user, $stageId);
        if ((int)$s['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($in['file_ids'] ?? null) ? $in['file_ids'] : []))));
    if (!$ids) throw new PmError('לא נבחרו קבצים', 422);
    $note = pm_str($in, 'note', 500, false, 'הערה');
    $db = pm_db();
    $ins = $db->prepare('INSERT OR REPLACE INTO file_refs (file_id, project_id, supplier_id, stage_id, kind, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($ids as $fid) {
        // קודם השיוך, אחר כך המקום — כך גם ספק שעוד אין לו תיקייה (רק מסמך) מקבל אותה (lib/links.php)
        $ins->execute([$fid, $projectId, $supplierId, $stageId, 'doc', $note, $user['id'], pm_now()]);
        pm_file_claim($projectId, $fid, null, $supplierId, false, $stageId);
    }
    pm_event($user, $projectId, 'file', count($ids) === 1 ? '📎 מסמך שויך ל{supplier}{stage}' : '📎 {n} מסמכים שויכו ל{supplier}{stage}',
        ['files' => $ids, 'supplier' => $supplierId], ['n' => count($ids), 'supplier' => pm_contact_name($supplierId), 'stage' => $stageId ? ' · ' . pm_trade_folder_name($stageId) : '']);
    return ['ok' => true, 'count' => count($ids)];
}

/** ביטול שיוך כללי: {file_id, supplier_id, stage_id?} */
function pm_file_ref_remove(array $user, array $in): array
{
    $x = pm_file_row($user, (int)($in['file_id'] ?? 0));
    pm_db()->prepare('DELETE FROM file_refs WHERE file_id = ? AND supplier_id = ? AND stage_id IS ?')
        ->execute([(int)$x['id'], (int)($in['supplier_id'] ?? 0), (int)($in['stage_id'] ?? 0) ?: null]);
    return ['file' => pm_file_get($user, (int)$x['id'])];
}

/**
 * תיק ההתקשרות — כל מה שקשור לספק במקצוע, במקום אחד (README "התקשרות", שלב 3):
 * {project_id, supplier_id, stage_id?} → סיכום (סוכם / שולם / יתרה / פיגור), חוזה, הצעות, תוספות,
 * אבני דרך (עם אסמכתאות וחשבונית), תיעוד מהשטח, מסמכים ששויכו, והקבצים בתיקייה "ספק / מקצוע".
 * בלי שלב — כל המקצועות של הספק בפרויקט.
 */
function pm_engagement(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    pm_project_row($user, $projectId);
    $supplierId = (int)($in['supplier_id'] ?? 0);
    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    $db = pm_db();
    $c = $db->prepare("SELECT id, name, phone, email, company FROM contacts WHERE id = ? AND kind = 'supplier'");
    $c->execute([$supplierId]);
    $sup = $c->fetch() ?: throw new PmError('הספק לא נמצא', 404);
    $stage = null;
    if ($stageId) {
        $s = pm_stage_row($user, $stageId);
        if ((int)$s['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
        $stage = ['id' => (int)$s['id'], 'name' => pm_trade_folder_name($stageId), 'status' => $s['status'], 'progress' => pm_stage_progress($s)];
    }
    $inStage = fn($sid) => !$stageId || (int)$sid === $stageId;

    // סיכום — משורות הכספים של הספק (בשלב, או בכל השלבים)
    $fin = pm_finance_of($projectId);
    $sum = ['committed' => [0, 0], 'paid' => [0, 0], 'balance' => [0, 0], 'gap' => [0, 0]];
    foreach ($fin['stages'] as $fs) {
        if ($stageId && (int)$fs['stage_id'] !== $stageId) continue;
        foreach ($fs['rows'] as $r) {
            if ((int)$r['supplier_id'] !== $supplierId) continue;
            $base = $r['committed']['g'] ? $r['committed'] : $r['scheduled'];
            foreach (['committed' => $base, 'paid' => $r['paid'], 'balance' => $r['balance'], 'gap' => $r['gap']] as $k => $m) { $sum[$k][0] += $m['g']; $sum[$k][1] += $m['n']; }
        }
    }
    $summary = array_map(fn($v) => ['g' => round($v[0], 2), 'n' => round($v[1], 2)], $sum);

    // חוזה: החוזה של המקצוע (או כולם, בלי מקצוע)
    $contracts = pm_contracts_of($projectId, $supplierId);
    $contract = pm_contract_for($contracts, $supplierId, $stageId);

    $q = $db->prepare("SELECT q.id, q.stage_id, q.amount, q.status, q.version, q.created_at, q.file_id, f.name AS file_name, s.name AS stage
                         FROM quotes q JOIN stages s ON s.id = q.stage_id LEFT JOIN files f ON f.id = q.file_id AND f.deleted_at IS NULL
                        WHERE q.project_id = ? AND q.supplier_id = ? ORDER BY q.id DESC");
    $q->execute([$projectId, $supplierId]);
    $quotes = array_values(array_map(fn($x) => ['id' => (int)$x['id'], 'stage_id' => (int)$x['stage_id'], 'stage' => $x['stage'], 'amount' => (int)$x['amount'] / 100,
        'status' => $x['status'], 'label' => pm_t(PM_QUOTE_STATUSES[$x['status']] ?? $x['status']), 'at' => $x['created_at'],
        'file' => $x['file_name'] !== null ? ['id' => (int)$x['file_id'], 'name' => $x['file_name']] : null], array_filter($q->fetchAll(), fn($x) => $inStage($x['stage_id']))));

    $changes = array_values(array_filter(pm_changes_of($projectId), fn($x) => $x['supplier_id'] === $supplierId && $inStage($x['stage_id'])));

    $pays = pm_payments_of($projectId);
    $milestones = array_values(array_filter($pays['payments'], fn($p) => $p['supplier_id'] === $supplierId && $p['status'] !== 'cancelled'
        && (!$stageId || $p['stage_id'] === $stageId || $p['stage_id'] === null)));

    // תיעוד מהשטח: של הספק בשלב, וגם דיווחים בשלב בלי ספק (שייתכן שהם שלו)
    $field = array_values(array_filter(pm_reports_of($projectId), fn($r) => ($r['supplier_id'] === $supplierId && $inStage($r['stage_id']))
        || ($stageId && $r['stage_id'] === $stageId && $r['supplier_id'] === null)));

    $d = $db->prepare('SELECT d.file_id, d.stage_id, d.note, d.created_at, f.name, f.mime, f.thumb IS NOT NULL AS thumb FROM file_refs d JOIN files f ON f.id = d.file_id AND f.deleted_at IS NULL
                        WHERE d.project_id = ? AND d.supplier_id = ? ORDER BY d.created_at DESC');
    $d->execute([$projectId, $supplierId]);
    $docs = array_values(array_map(fn($x) => ['id' => (int)$x['file_id'], 'name' => $x['name'], 'mime' => $x['mime'], 'has_thumb' => (bool)$x['thumb'],
        'note' => $x['note'], 'at' => $x['created_at'], 'stage_id' => $x['stage_id'] !== null ? (int)$x['stage_id'] : null],
        array_filter($d->fetchAll(), fn($x) => !$stageId || $x['stage_id'] === null || (int)$x['stage_id'] === $stageId)));

    // הקבצים בתיקייה "ספק / מקצוע" (או בכל תיקיית הספק)
    pm_ensure_folders(['id' => $projectId]);
    $folder = $stageId ? pm_trade_folder($projectId, $supplierId, $stageId) : null;
    if (!$folder) {
        $fr = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'contact' AND contact_id = ? AND parent_id IS NULL");
        $fr->execute([$projectId, $supplierId]);
        $folder = (int)$fr->fetchColumn() ?: null;
    }
    // כל המקצועות — גם מה שבתיקיות המקצוע שמתחת; וכל מה שמשויך (lib/links.php)
    $files = $folder ? pm_files_list($user, $projectId, ['folder_id' => $folder, 'type' => '', 'deep' => !$stageId]) : [];

    return [
        'supplier' => ['id' => (int)$sup['id'], 'name' => $sup['name'], 'phone' => $sup['phone'], 'company' => $sup['company']],
        'stage' => $stage, 'stages' => array_map(fn($id) => ['id' => $id, 'name' => pm_trade_folder_name($id)], pm_supplier_stages($projectId, $supplierId)), 'folder_id' => $folder,
        'summary' => $summary, 'contract' => $contract, 'contracts' => count($contracts),
        'quotes' => $quotes, 'changes' => $changes, 'milestones' => $milestones, 'field' => $field, 'docs' => $docs, 'files' => $files,
    ];
}
