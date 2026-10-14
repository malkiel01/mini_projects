<?php
/**
 * מתחם ויחידות (שלב 1): פרויקט אחד עם כמה יחידות — דירה א', דירה ב'. כל שלב משותף (unit_id NULL —
 * היתר, חדר מדרגות) או של יחידה אחת ("חשמל · דירה א'"). פרויקט בלי יחידות — כמו תמיד.
 * בהמשך: חוזה לפי יחידות וחלוקת עלות (2), תשלום ועיכבון לפי יחידה (3), לקוח ופורטל ליחידה (4).
 */

declare(strict_types=1);

/** היחידה של שלב (NULL — משותף). */
function pm_stage_unit(int $stageId): ?int
{
    $st = pm_db()->prepare('SELECT unit_id FROM stages WHERE id = ?');
    $st->execute([$stageId]);
    $u = $st->fetchColumn();
    return $u ? (int)$u : null;
}

function pm_unit_name(int $id): string
{
    static $c = [];
    if (!isset($c[$id])) { $st = pm_db()->prepare('SELECT name FROM units WHERE id = ?'); $st->execute([$id]); $c[$id] = (string)$st->fetchColumn(); }
    return $c[$id];
}

/** היחידות של הפרויקט, לפי הסדר — עם מספר השלבים בכל אחת. */
function pm_units_of(int $projectId): array
{
    $st = pm_db()->prepare('SELECT u.*, cl.name AS client_name, lw.name AS lawyer_name, (SELECT COUNT(*) FROM stages s WHERE s.unit_id = u.id) AS stages,
            (SELECT COUNT(*) FROM stages s WHERE s.unit_id = u.id AND ' . PM_STAGE_USED . ' > 0) AS used
        FROM units u LEFT JOIN contacts cl ON cl.id = u.client_id LEFT JOIN contacts lw ON lw.id = u.lawyer_id WHERE u.project_id = ? ORDER BY u.sort, u.id');
    $st->execute([$projectId]);
    return array_map(fn($u) => [
        'id' => (int)$u['id'], 'name' => $u['name'], 'area' => $u['area'] !== null ? (float)$u['area'] : null,
        'budget' => (int)$u['budget'], 'notes' => $u['notes'], 'sort' => (int)$u['sort'], 'stages' => (int)$u['stages'],
        'used' => (int)$u['used'], 'handed_over_at' => $u['handed_over_at'],
        'client_id' => $u['client_id'] !== null ? (int)$u['client_id'] : null, 'client_name' => $u['client_name'],
        'lawyer_id' => $u['lawyer_id'] !== null ? (int)$u['lawyer_id'] : null, 'lawyer_name' => $u['lawyer_name'],
    ], $st->fetchAll());
}

function pm_unit_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM units WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch() ?: throw new PmError('היחידה לא נמצאה', 404);
    pm_project_row($user, (int)$u['project_id']);
    return $u;
}

/** יחידה של הפרויקט — או חריגה. null / 0 = משותף. */
function pm_unit_of_project(?int $unitId, int $projectId): ?int
{
    if (!$unitId) return null;
    $st = pm_db()->prepare('SELECT 1 FROM units WHERE id = ? AND project_id = ?');
    $st->execute([$unitId, $projectId]);
    if (!$st->fetchColumn()) throw new PmError('היחידה לא שייכת לפרויקט', 422);
    return $unitId;
}

/**
 * יחידה חדשה / עדכון: {id?, project_id, name, area, budget, notes, copy_from?, move_shared?}.
 * copy_from — לבנות לה שלבים: 'shared' (כמו השלבים המשותפים) או מזהה יחידה (כמו דירה א').
 * move_shared — היחידה הראשונה: השלבים הקיימים עוברים אליה (במקום להישאר משותפים).
 */
function pm_unit_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? pm_unit_row($user, $id) : null;
    $projectId = $old ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $area = $in['area'] ?? '';
    if ($area !== '' && $area !== null && (!is_numeric($area) || $area < 0 || $area > 1e6)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'שטח']);
    $budget = $in['budget'] ?? '';
    if ($budget !== '' && $budget !== null && (!is_numeric($budget) || $budget < 0 || $budget > 1e9)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'תקציב']);
    $f = [
        'name'   => pm_str($in, 'name', 60, true, 'שם היחידה'),
        'area'   => $area === '' || $area === null ? null : (float)$area,
        'budget' => (int)round((float)($budget ?: 0)),
        'notes'  => pm_str($in, 'notes', 500, false, 'הערות'),
        // מסירה (שלב 3): תאריך שבו היחידה נמסרה; ריק — עוד לא
        'handed_over_at' => array_key_exists('handed_over_at', $in) ? (pm_date($in, 'handed_over_at', 'נמסרה ב') ?: null) : ($old['handed_over_at'] ?? null), // i18n-ok
        // לקוח ועו"ד ליחידה (שלב 4); ריק — של הפרויקט
        'client_id' => array_key_exists('client_id', $in) ? pm_owned_contact((int)($in['client_id'] ?? 0) ?: null, (int)$p['owner_id'], 'client', 'הלקוח') : ($old['client_id'] ?? null), // i18n-ok
        'lawyer_id' => array_key_exists('lawyer_id', $in) ? pm_owned_contact((int)($in['lawyer_id'] ?? 0) ?: null, (int)$p['owner_id'], 'lawyer', 'עורך הדין') : ($old['lawyer_id'] ?? null), // i18n-ok
    ];
    $db = pm_db();
    $dup = $db->prepare('SELECT 1 FROM units WHERE project_id = ? AND name = ? AND id != ?');
    $dup->execute([$projectId, $f['name'], $id]);
    if ($dup->fetchColumn()) throw new PmError('כבר יש יחידה בשם הזה', 422);
    $now = pm_now();
    $db->beginTransaction();
    try {
        if ($old) {
            $db->prepare('UPDATE units SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
            if (($old['handed_over_at'] ?? null) !== $f['handed_over_at']) pm_event($user, $projectId, 'unit', $f['handed_over_at'] ? '🔑 נמסרה: {name}' : '🔑 בוטלה המסירה: {name}', ['unit' => $id], ['name' => $f['name']]);
        } else {
            $first = !(int)$db->query("SELECT COUNT(*) FROM units WHERE project_id = $projectId")->fetchColumn();
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) + 10 FROM units WHERE project_id = $projectId")->fetchColumn();
            $db->prepare('INSERT INTO units (project_id, ' . implode(', ', array_keys($f)) . ', sort, created_at) VALUES (?, ' . str_repeat('?, ', count($f)) . '?, ?)')
               ->execute([$projectId, ...array_values($f), $sort, $now]);
            $id = (int)$db->lastInsertId();
            if ($first && !empty($in['move_shared'])) {
                $db->prepare('UPDATE stages SET unit_id = ? WHERE project_id = ? AND unit_id IS NULL')->execute([$id, $projectId]);
            } elseif (($from = $in['copy_from'] ?? '') !== '' && $from !== null) {
                pm_unit_copy_stages($projectId, $from === 'shared' ? null : pm_unit_of_project((int)$from, $projectId), $id);
            }
            pm_event($user, $projectId, 'unit', '🏢 נוספה יחידה: {name}', ['unit' => $id], ['name' => $f['name']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    pm_budget_sync($projectId); // תקציב שפורק לשלבים — תקציב הדירה מחושב (השלבים שלה + חלקה במשותפים)
    return pm_project_get($user, $projectId) + ['saved_unit' => $id];
}

/** שלבים ליחידה חדשה — כמו של יחידה אחרת (או המשותפים): שם, תחום, עו"ד, סדר. בלי הצעות והתקדמות. */
function pm_unit_copy_stages(int $projectId, ?int $fromUnit, int $toUnit): void
{
    $db = pm_db();
    $st = $db->prepare('SELECT name, trade_id, needs_lawyer FROM stages WHERE project_id = ? AND unit_id IS ? ORDER BY sort, id');
    $st->execute([$projectId, $fromUnit]);
    $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) FROM stages WHERE project_id = $projectId")->fetchColumn();
    $ins = $db->prepare("INSERT INTO stages (project_id, unit_id, sort, name, trade_id, needs_lawyer, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'planned', ?, ?)");
    $now = pm_now();
    foreach ($st->fetchAll() as $s) $ins->execute([$projectId, $toUnit, $sort += 10, $s['name'], $s['trade_id'], $s['needs_lawyer'], $now, $now]);
}

/** מחיקת יחידה — רק כשהשלבים שלה ריקים (אז נמחקים איתה). אחרת — סמכות מפתח. */
function pm_unit_delete(array $user, int $id, ?string $moveTo = null): array
{
    $u = pm_unit_row($user, $id);
    $db = pm_db();
    // איחוד: השלבים (עם ההצעות, התשלומים והדיווחים) עוברים ליחידה אחרת או למשותף, והיחידה נמחקת
    if ($moveTo !== null && $moveTo !== '') {
        $to = $moveTo === 'shared' ? null : pm_unit_of_project((int)$moveTo, (int)$u['project_id']);
        if ($to === $id) throw new PmError('אי אפשר לאחד יחידה עם עצמה', 422);
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE stages SET unit_id = ? WHERE unit_id = ?')->execute([$to, $id]);
            $db->prepare('UPDATE payments SET unit_id = ? WHERE unit_id = ?')->execute([$to, $id]);
            $cs = $db->prepare('SELECT id, unit_ids FROM contracts WHERE project_id = ?');
            $cs->execute([$u['project_id']]);
            foreach ($cs->fetchAll() as $c) {
                $ids = json_decode($c['unit_ids'], true) ?: [];
                if (!in_array($id, $ids, true)) continue;
                $ids = array_values(array_unique(array_filter(array_map(fn($x) => $x === $id ? $to : $x, $ids))));
                $db->prepare('UPDATE contracts SET unit_ids = ? WHERE id = ?')->execute([json_encode($ids), $c['id']]);
            }
            $db->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
            pm_event($user, (int)$u['project_id'], 'unit', '🏢 {name} אוחדה עם {to}', ['unit' => $id], ['name' => $u['name'], 'to' => $to ? pm_unit_name($to) : pm_t('משותף לכל המתחם')]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return pm_project_get($user, (int)$u['project_id']);
    }
    $q = $db->prepare('SELECT s.id FROM stages s WHERE s.unit_id = ? AND ' . PM_STAGE_USED . ' > 0');
    $q->execute([$id]);
    $used = $q->fetchAll(PDO::FETCH_COLUMN);
    // סמכות מפתח: שלבים עם נתונים — מחיקה גמורה שלהם, ואז היחידה
    if ($used && pm_dev_mode($user)) { foreach ($used as $sid) pm_dev_purge($user, 'stage', (int)$sid); $used = []; }
    if ($used) throw new PmError('ביחידה יש שלבים עם הצעות, תשלומים או דיווחים — אי אפשר למחוק אותה', 422);
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM stages WHERE unit_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
        pm_event($user, (int)$u['project_id'], 'unit', '🏢 הוסרה יחידה: {name}', ['unit' => $id], ['name' => $u['name']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    pm_budget_sync((int)$u['project_id']);
    return pm_project_get($user, (int)$u['project_id']);
}

/** "🔑 מסירת כל היחידות" — כל מה שעוד לא נמסר, באותו תאריך (מסירה יחד). */
function pm_units_handover(array $user, int $projectId, string $date): array
{
    pm_project_row($user, $projectId);
    $date = pm_date(['d' => $date ?: pm_today()], 'd', 'תאריך המסירה'); // i18n-ok
    $st = pm_db()->prepare('UPDATE units SET handed_over_at = ? WHERE project_id = ? AND handed_over_at IS NULL');
    $st->execute([$date, $projectId]);
    if ($st->rowCount()) pm_event($user, $projectId, 'unit', '🔑 כל היחידות נמסרו ({n})', [], ['n' => $st->rowCount()]);
    return pm_project_get($user, $projectId);
}

/** הגדרות המתחם: portal_shared — להציג ללקוח של יחידה גם את השלבים המשותפים (ברירת מחדל: כן). */
function pm_units_settings(array $user, int $projectId, array $in): array
{
    pm_project_row($user, $projectId);
    if (array_key_exists('portal_shared', $in)) pm_db()->prepare('UPDATE projects SET portal_shared = ? WHERE id = ?')->execute([!empty($in['portal_shared']) ? 1 : 0, $projectId]);
    return pm_project_get($user, $projectId);
}

/**
 * מה איש קשר (לקוח / עו"ד) רואה בפורטל של מתחם: null — הכול (אין יחידות, או שכולן שלו);
 * אחרת {units, stages, budget}: היחידות שלו, השלבים שלהן (ומשותפים — אם portal_shared), והתקציב שלהן (₪).
 * יחידה בלי לקוח / עו"ד משלה — של הלקוח / העו"ד של הפרויקט.
 */
function pm_portal_scope(array $project, int $contactId, string $role): ?array
{
    if (!in_array($role, ['client', 'lawyer'], true)) return null;
    $units = pm_units_of((int)$project['id']);
    if (!$units) return null;
    $col = $role === 'client' ? 'client_id' : 'lawyer_id';
    $mine = array_values(array_filter($units, fn($u) => (int)($u[$col] ?? $project[$col]) === $contactId));
    if (count($mine) === count($units)) return null;
    $ids = array_column($mine, 'id');
    $st = pm_db()->prepare('SELECT id, unit_id FROM stages WHERE project_id = ?');
    $st->execute([$project['id']]);
    $shared = (int)($project['portal_shared'] ?? 1) === 1;
    $stages = [];
    foreach ($st->fetchAll() as $s) if ($s['unit_id'] === null ? $shared : in_array((int)$s['unit_id'], $ids, true)) $stages[] = (int)$s['id'];
    return ['units' => $ids, 'names' => array_column($mine, 'name'), 'stages' => $stages, 'budget' => array_sum(array_column($mine, 'budget')), 'shared' => $shared];
}

/** הרשאה בפורטל לפי היחידות: שלב (או יחידה של תשלום) שמחוץ לתחום — "לא נמצא". */
function pm_portal_in_scope(?array $scope, ?int $stageId, ?int $unitId = null): bool
{
    if ($scope === null) return true;
    if ($stageId) return in_array($stageId, $scope['stages'], true);
    return $unitId ? in_array($unitId, $scope['units'], true) : $scope['shared'];
}
