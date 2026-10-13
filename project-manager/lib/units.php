<?php
/**
 * מתחם ויחידות (שלב 1): פרויקט אחד עם כמה יחידות — דירה א', דירה ב'. כל שלב משותף (unit_id NULL —
 * היתר, חדר מדרגות) או של יחידה אחת ("חשמל · דירה א'"). פרויקט בלי יחידות — כמו תמיד.
 * בהמשך: חוזה לפי יחידות וחלוקת עלות (2), תשלום ועיכבון לפי יחידה (3), לקוח ופורטל ליחידה (4).
 */

declare(strict_types=1);

/** היחידות של הפרויקט, לפי הסדר — עם מספר השלבים בכל אחת. */
function pm_units_of(int $projectId): array
{
    $st = pm_db()->prepare('SELECT u.*, (SELECT COUNT(*) FROM stages s WHERE s.unit_id = u.id) AS stages
        FROM units u WHERE u.project_id = ? ORDER BY u.sort, u.id');
    $st->execute([$projectId]);
    return array_map(fn($u) => [
        'id' => (int)$u['id'], 'name' => $u['name'], 'area' => $u['area'] !== null ? (float)$u['area'] : null,
        'budget' => (int)$u['budget'], 'notes' => $u['notes'], 'sort' => (int)$u['sort'], 'stages' => (int)$u['stages'],
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
    pm_project_row($user, $projectId);
    $area = $in['area'] ?? '';
    if ($area !== '' && $area !== null && (!is_numeric($area) || $area < 0 || $area > 1e6)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'שטח']);
    $budget = $in['budget'] ?? '';
    if ($budget !== '' && $budget !== null && (!is_numeric($budget) || $budget < 0 || $budget > 1e9)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'תקציב']);
    $f = [
        'name'   => pm_str($in, 'name', 60, true, 'שם היחידה'),
        'area'   => $area === '' || $area === null ? null : (float)$area,
        'budget' => (int)round((float)($budget ?: 0)),
        'notes'  => pm_str($in, 'notes', 500, false, 'הערות'),
    ];
    $db = pm_db();
    $dup = $db->prepare('SELECT 1 FROM units WHERE project_id = ? AND name = ? AND id != ?');
    $dup->execute([$projectId, $f['name'], $id]);
    if ($dup->fetchColumn()) throw new PmError('כבר יש יחידה בשם הזה', 422);
    $now = pm_now();
    $db->beginTransaction();
    try {
        if ($old) {
            $db->prepare('UPDATE units SET name = ?, area = ?, budget = ?, notes = ? WHERE id = ?')->execute([...array_values($f), $id]);
        } else {
            $first = !(int)$db->query("SELECT COUNT(*) FROM units WHERE project_id = $projectId")->fetchColumn();
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) + 10 FROM units WHERE project_id = $projectId")->fetchColumn();
            $db->prepare('INSERT INTO units (project_id, name, area, budget, notes, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
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
function pm_unit_delete(array $user, int $id): array
{
    $u = pm_unit_row($user, $id);
    $db = pm_db();
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
    return pm_project_get($user, (int)$u['project_id']);
}
