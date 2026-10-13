<?php
/**
 * חוזה עם ספק — מעקב לפי החוזה (README, "חוזה עם ספק"): מועד התחלה ("היום הקובע"), מועד סיום,
 * אבני דרך עם מועדים (נספח "אבני דרך"), וקנס איחור: X ₪ לכל יום איחור בסיום, אחרי ימי חסד.
 * סטייה של יותר מ-N ימים מאבן דרך — "הפרה יסודית" בחוזה; מתריעים לפני שמגיעים אליה.
 *
 * אבן דרך (כמו בנספח "אבני דרך" בחוזה) — קבוצה של שלבים: "סיום הריסה, קירות צד אחד ותשתיות אינסטלציה —
 * עד 4 חודשים מתחילת הפרויקט". הושגה: תאריך שנרשם, או — כשכל השלבים שלה סומנו "בוצע" (המאוחר מביניהם).
 * months — "עד X חודשים מההתחלה": המועד מחושב ממועד ההתחלה (ומתעדכן כשהוא משתנה).
 */

declare(strict_types=1);

require_once __DIR__ . '/finance.php';

/** ימים בין שני תאריכים (Y-m-d): $b − $a. */
function pm_days_between(string $a, string $b): int
{
    return (int)round((strtotime($b . 'T12:00:00Z') - strtotime($a . 'T12:00:00Z')) / 86400);
}

/** חוזה לפי המזהה (בפרויקט), עם אבני הדרך והחישוב — או null. */
function pm_contract_by_id(int $projectId, int $id): ?array
{
    $st = pm_db()->prepare('SELECT c.*, f.name AS file_name, f.deleted_at AS file_deleted FROM contracts c LEFT JOIN files f ON f.id = c.file_id
                             WHERE c.project_id = ? AND c.id = ?');
    $st->execute([$projectId, $id]);
    $c = $st->fetch();
    return $c ? pm_contract_out($c) : null;
}

/** כל החוזים של הפרויקט (רשימה). לספק יכולים להיות כמה — כל אחד חל על שלבים אחרים. */
function pm_contracts_of(int $projectId, ?int $supplierId = null): array
{
    $st = pm_db()->prepare('SELECT c.*, f.name AS file_name, f.deleted_at AS file_deleted FROM contracts c LEFT JOIN files f ON f.id = c.file_id
                             WHERE c.project_id = ?' . ($supplierId ? ' AND c.supplier_id = ' . (int)$supplierId : '') . ' ORDER BY c.id');
    $st->execute([$projectId]);
    return array_map('pm_contract_out', $st->fetchAll());
}

/**
 * החוזה של ספק בשלב: קודם חוזה שהשלב רשום בו במפורש, אחרת חוזה "לכל השלבים" (stage_ids ריק) — העדכני.
 * בלי שלב (תשלום בלי שלב) — החוזה "לכל השלבים", ואם אין — הראשון.
 */
function pm_contract_for(array $contracts, int $supplierId, ?int $stageId): ?array
{
    $mine = array_values(array_filter($contracts, fn($c) => $c['supplier_id'] === $supplierId));
    if (!$mine) return null;
    if ($stageId) foreach (array_reverse($mine) as $c) if ($c['stage_ids'] !== null && in_array($stageId, $c['stage_ids'], true)) return $c;
    foreach (array_reverse($mine) as $c) if ($c['stage_ids'] === null) return $c;
    return $stageId ? null : $mine[0];
}

function pm_contract_out(array $c): array
{
    $today = pm_today();
    $m = pm_db()->prepare('SELECT * FROM contract_milestones WHERE contract_id = ? ORDER BY sort, due_date, id');
    $m->execute([$c['id']]);
    $st = pm_db()->prepare('SELECT id, name, done_at FROM stages WHERE project_id = ?');
    $st->execute([$c['project_id']]);
    $stages = [];
    foreach ($st->fetchAll() as $s) $stages[(int)$s['id']] = $s;
    $maxDev = (int)$c['max_deviation_days'];
    $worst = 0;
    $milestones = array_map(function ($x) use ($today, $maxDev, &$worst, $stages, $c) {
        $ids = array_values(array_filter(array_map('intval', json_decode((string)$x['stage_ids'], true) ?: []), fn($i) => isset($stages[$i])));
        // "עד X חודשים מההתחלה" — מחושב ממועד ההתחלה של החוזה
        if ($x['months'] !== null && $c['start_date'] !== '') $x['due_date'] = date('Y-m-d', strtotime($c['start_date'] . ' +' . (int)$x['months'] . ' months'));
        // הושגה כשכל השלבים שלה בוצעו — ביום של האחרון
        $byStages = '';
        if ($ids && !array_filter($ids, fn($i) => !$stages[$i]['done_at'])) $byStages = max(array_map(fn($i) => substr((string)$stages[$i]['done_at'], 0, 10), $ids));
        $done = $x['done_on'] !== '' ? $x['done_on'] : $byStages;
        $x['stage'] = implode(', ', array_map(fn($i) => $stages[$i]['name'], $ids));
        $x['stage_ids'] = $ids;
        $x['stages_done'] = count(array_filter($ids, fn($i) => (bool)$stages[$i]['done_at']));
        $late = $done !== '' ? max(0, pm_days_between($x['due_date'], $done)) : max(0, pm_days_between($x['due_date'], $today));
        if ($done === '') $worst = max($worst, $late);
        $left = $done === '' ? pm_days_between($today, $x['due_date']) : null;
        $status = $done !== '' ? ($late ? 'done_late' : 'done') : ($late ? ($late >= $maxDev ? 'breach' : 'late') : ($left <= 7 ? 'soon' : 'ok'));
        return ['id' => (int)$x['id'], 'title' => $x['title'], 'due_date' => $x['due_date'], 'months' => $x['months'] !== null ? (int)$x['months'] : null,
            'stage_ids' => $x['stage_ids'], 'stages' => $x['stage'], 'stages_done' => $x['stages_done'],
            'done_on' => $x['done_on'], 'done' => $done, 'by_stage' => $x['done_on'] === '' && $done !== '',
            'late_days' => $late, 'days_left' => $left, 'status' => $status];
    }, $m->fetchAll());

    // סיום: איחור מהמועד בחוזה, והקנס — רק על הימים שאחרי ימי החסד
    $delay = 0;
    if ($c['end_date'] !== '') {
        $end = $c['completed_on'] !== '' ? $c['completed_on'] : $today;
        $delay = max(0, pm_days_between($c['end_date'], $end));
    }
    $penaltyDays = max(0, $delay - (int)$c['grace_days']);
    $penalty = $penaltyDays * (int)$c['penalty_per_day'];
    return [
        'id' => (int)$c['id'], 'project_id' => (int)$c['project_id'], 'supplier_id' => (int)$c['supplier_id'], 'title' => $c['title'] ?? '',
        // על אילו שלבים החוזה חל: null — כל השלבים של הספק; [] — אף שלב (כל השלבים עברו לחוזים אחרים)
        'stage_ids' => ($c['stage_ids'] ?? '') === '' ? null : array_map('intval', json_decode($c['stage_ids'], true) ?: []),
        'signed_on' => $c['signed_on'], 'start_date' => $c['start_date'], 'end_date' => $c['end_date'], 'completed_on' => $c['completed_on'],
        'penalty_per_day' => (int)$c['penalty_per_day'] / 100, 'grace_days' => (int)$c['grace_days'], 'max_deviation_days' => $maxDev,
        'notes' => $c['notes'], 'file' => $c['file_id'] && empty($c['file_deleted']) ? ['id' => (int)$c['file_id'], 'name' => $c['file_name']] : null,
        'milestones' => $milestones,
        'unit_ids' => array_map('intval', json_decode($c['unit_ids'] ?? '[]', true) ?: []), 'split' => pm_split_of($c['split'] ?? ''),
        'delay_days' => $delay, 'penalty_days' => $penaltyDays, 'penalty' => $penalty / 100,
        'worst_deviation' => $worst,
        'days_left' => $c['end_date'] !== '' && $c['completed_on'] === '' ? pm_days_between($today, $c['end_date']) : null,
    ];
}

/**
 * החוזה לשורת ספק בשלב — וכל החוזים של הספק בפרויקט, כדי לבחור: החוזה הזה, חוזה אחר שלו, או חוזה חדש.
 * id — חוזה מסוים מתוך הרשימה.
 */
function pm_contract(array $user, int $projectId, int $supplierId, ?int $stageId = null, int $id = 0): array
{
    pm_project_row($user, $projectId);
    $all = pm_contracts_of($projectId, $supplierId);
    $c = $id ? (array_values(array_filter($all, fn($x) => $x['id'] === $id))[0] ?? throw new PmError('החוזה לא נמצא', 404)) : pm_contract_for($all, $supplierId, $stageId);
    return ['contract' => $c, 'contracts' => $all];
}

/**
 * שמירה: {id? (בלי — חוזה חדש), project_id, supplier_id, title, stage_ids (null — כל השלבים של הספק), signed_on, start_date, end_date, completed_on, penalty_per_day, grace_days,
 * max_deviation_days, notes, file_id?, milestones: [{id?, title, due_date, stage_id?, done_on?}]} — אבני הדרך מוחלפות.
 */
function pm_contract_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $supplierId = pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק') // i18n-ok
        ?? throw new PmError('ספק: שדה חובה', 422);
    $int = function (string $k, int $max, string $label) use ($in): int {
        $v = $in[$k] ?? 0;
        if ($v === '' || $v === null) return 0;
        if (!is_numeric($v) || $v < 0 || $v > $max) throw new PmError('{field}: בין {min} ל-{max}', 422, ['field' => $label, 'min' => 0, 'max' => $max]);
        return (int)$v;
    };
    $f = [
        'signed_on'          => pm_date($in, 'signed_on', 'תאריך חתימה'), // i18n-ok
        'start_date'         => pm_date($in, 'start_date', 'מועד התחלה'), // i18n-ok
        'end_date'           => pm_date($in, 'end_date', 'מועד סיום'), // i18n-ok
        'completed_on'       => pm_date($in, 'completed_on', 'הסתיים בפועל'), // i18n-ok
        'penalty_per_day'    => (int)round((float)$int('penalty_per_day', 1000000, 'קנס ליום איחור') * 100), // i18n-ok
        'grace_days'         => $int('grace_days', 365, 'ימי חסד'), // i18n-ok
        'max_deviation_days' => $int('max_deviation_days', 365, 'סטייה מרבית מאבן דרך') ?: 30, // i18n-ok
        'notes'              => pm_str($in, 'notes', 2000, false, 'הערות'),
        'title'              => pm_str($in, 'title', 120, false, 'שם החוזה'), // i18n-ok
    ];
    // על אילו שלבים החוזה חל — שלב שנרשם כאן יוצא מחוזים אחרים של אותו ספק (לשלב — חוזה אחד)
    $stageIds = null;
    if (is_array($in['stage_ids'] ?? null)) {
        $stageIds = array_values(array_unique(array_filter(array_map('intval', $in['stage_ids']))));
        if ($stageIds) {
            $s = pm_db()->prepare('SELECT COUNT(*) FROM stages WHERE project_id = ? AND id IN (' . implode(',', $stageIds) . ')');
            $s->execute([$projectId]);
            if ((int)$s->fetchColumn() !== count($stageIds)) throw new PmError('השלב לא שייך לפרויקט', 422);
        }
    }
    $f['stage_ids'] = $stageIds === null ? '' : json_encode($stageIds);
    // מתחם: על אילו יחידות החוזה חל, ואיך מחלקים ביניהן את העלות
    $f['unit_ids'] = json_encode(array_values(array_map(fn($u) => (int)pm_unit_of_project((int)$u, $projectId),
        array_unique(array_filter(array_map('intval', is_array($in['unit_ids'] ?? null) ? $in['unit_ids'] : []))))));
    $f['split'] = json_encode(pm_split_clean(is_array($in['split'] ?? null) ? $in['split'] : [], $projectId));
    if ($f['start_date'] !== '' && $f['end_date'] !== '' && $f['end_date'] < $f['start_date']) throw new PmError('מועד הסיום לפני מועד ההתחלה', 422);
    $fileId = (int)($in['file_id'] ?? 0) ?: null;
    if ($fileId) {
        $file = pm_file_row($user, $fileId);
        if ((int)$file['project_id'] !== $projectId) throw new PmError('הקובץ לא שייך לפרויקט', 422);
    }
    $ms = [];
    foreach (is_array($in['milestones'] ?? null) ? $in['milestones'] : [] as $i => $m) {
        $title = mb_substr(trim((string)($m['title'] ?? '')), 0, 200);
        if ($title === '') continue;
        $months = ($m['months'] ?? '') === '' || $m['months'] === null ? null : (int)$m['months'];
        if ($months !== null && ($months < 0 || $months > 120)) throw new PmError('{title}: חודשים — בין 0 ל-120', 422, ['title' => $title]);
        if ($months !== null && $f['start_date'] === '') throw new PmError('"עד X חודשים" — צריך מועד התחלה', 422);
        $due = $months !== null ? date('Y-m-d', strtotime($f['start_date'] . " +$months months"))
             : (pm_date($m, 'due_date', 'מועד אבן הדרך') ?: throw new PmError('{title}: חסר מועד', 422, ['title' => $title])); // i18n-ok
        $sids = array_values(array_unique(array_filter(array_map('intval', is_array($m['stage_ids'] ?? null) ? $m['stage_ids'] : []))));
        if ($sids) {
            $s = pm_db()->prepare('SELECT COUNT(*) FROM stages WHERE project_id = ? AND id IN (' . implode(',', $sids) . ')');
            $s->execute([$projectId]);
            if ((int)$s->fetchColumn() !== count($sids)) throw new PmError('השלב לא שייך לפרויקט', 422);
        }
        $ms[] = [$title, $due, json_encode($sids), $months, pm_date($m, 'done_on', 'הושגה ב'), $i]; // i18n-ok
    }
    $db = pm_db();
    $now = pm_now();
    $id = (int)($in['id'] ?? 0);
    $old = $id ? (pm_contract_by_id($projectId, $id) ?? throw new PmError('החוזה לא נמצא', 404)) : null;
    if ($old && $old['supplier_id'] !== $supplierId) throw new PmError('החוזה של ספק אחר', 422);
    $db->beginTransaction();
    try {
        if ($old) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $db->prepare("UPDATE contracts SET $set, file_id = ?, updated_at = ? WHERE id = ?")->execute(array_merge(array_values($f), [$fileId ?? ($old['file']['id'] ?? null), $now, $old['id']]));
            $cid = $old['id'];
            $db->prepare('DELETE FROM contract_milestones WHERE contract_id = ?')->execute([$cid]);
        } else {
            $cols = implode(', ', array_keys($f));
            $db->prepare("INSERT INTO contracts (project_id, supplier_id, $cols, file_id, created_at, updated_at) VALUES (?, ?, " . implode(',', array_fill(0, count($f), '?')) . ', ?, ?, ?)')
               ->execute(array_merge([$projectId, $supplierId], array_values($f), [$fileId, $now, $now]));
            $cid = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO contract_milestones (contract_id, title, due_date, stage_ids, months, done_on, sort) VALUES (?,?,?,?,?,?,?)');
        foreach ($ms as [$t, $due, $sids, $months, $done, $i]) $ins->execute([$cid, $t, $due, $sids, $months, $done, $i]);
        if ($fileId) $db->prepare("UPDATE files SET type = 'contract' WHERE id = ?")->execute([$fileId]);
        if ($stageIds) {
            $o = $db->prepare("SELECT id, stage_ids FROM contracts WHERE project_id = ? AND supplier_id = ? AND id != ? AND stage_ids != ''");
            $o->execute([$projectId, $supplierId, $cid]);
            foreach ($o->fetchAll() as $x) {
                $ids = array_map('intval', json_decode($x['stage_ids'], true) ?: []);
                $left = array_values(array_diff($ids, $stageIds));
                if ($left !== $ids) $db->prepare('UPDATE contracts SET stage_ids = ? WHERE id = ?')->execute([json_encode($left), $x['id']]);
            }
        }
        pm_event($user, $projectId, 'finance', $old ? 'עודכן החוזה עם {supplier}' : '📄 נרשם חוזה עם {supplier} — סיום עד {date}', ['contract' => $cid],
            ['supplier' => pm_contact_name($supplierId), 'date' => $f['end_date'] !== '' ? pm_short_date($f['end_date']) : '—']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_finance($user, $projectId) + ['contract' => pm_contract_by_id($projectId, $cid), 'contracts' => pm_contracts_of($projectId, $supplierId)];
}

/** התראות החוזים — למנהל: אבן דרך מתקרבת / באיחור, לקראת הפרה יסודית, איחור בסיום וקנס מצטבר. */
function pm_contract_alerts(int $projectId, callable $add, string $lang): void
{
    $today = pm_today();
    foreach (pm_contracts_of($projectId) as $c) {
        $sup = pm_contact_name($c['supplier_id']) . ($c['title'] !== '' ? ' (' . $c['title'] . ')' : '');
        foreach ($c['milestones'] as $m) {
            $v = ['supplier' => $sup, 'title' => $m['title'], 'date' => pm_short_date($m['due_date']), 'n' => $m['late_days'], 'max' => $c['max_deviation_days']];
            if ($m['status'] === 'breach') {
                $add('late', 'contract_breach', 'cbreach:' . $m['id'], 'הפרה יסודית: {supplier} — "{title}" באיחור {n} ימים (מעל {max})', $v, $m['due_date']); // i18n-ok
            } elseif ($m['status'] === 'late') {
                $near = $m['late_days'] >= $c['max_deviation_days'] - 7;
                $add('late', 'contract_late', 'clate:' . $m['id'], $near ? 'לקראת הפרה יסודית: {supplier} — "{title}" באיחור {n} ימים (הגבול {max})' : 'אבן דרך באיחור: {supplier} — "{title}" ({n} ימים, היה עד {date})', $v, $m['due_date']); // i18n-ok
            } elseif ($m['status'] === 'soon') {
                $add('soon', 'contract_soon', 'csoon:' . $m['id'], 'אבן דרך מתקרבת: {supplier} — "{title}" עד {date}', $v, $m['due_date']); // i18n-ok
            }
        }
        if ($c['completed_on'] === '' && $c['end_date'] !== '' && $c['delay_days'] > 0) {
            $add('late', 'contract_delay', 'cdelay:' . $c['id'], $c['penalty'] > 0 ? 'איחור בסיום: {supplier} — {n} ימים, קנס מצטבר {amount}' : 'איחור בסיום: {supplier} — {n} ימים (הקנס מתחיל אחרי {grace} ימי חסד)', // i18n-ok
                ['supplier' => $sup, 'n' => $c['delay_days'], 'amount' => pm_money((int)round($c['penalty'] * 100)), 'grace' => $c['grace_days']], $c['end_date']);
        }
    }
}

// ---- מתחם: חלוקת העלות של חוזה בין היחידות ----

/**
 * method: auto — לפי השלבים (שורה בשלב של דירה — לדירה; בשלב משותף — נשארת "משותף");
 * equal — שווה בשווה; area — לפי השטח; fixed — סכום קבוע לכל יחידה (₪), והשאר לפי rest (equal / area).
 */
function pm_split_of(string $json): array
{
    $s = $json !== '' ? (json_decode($json, true) ?: []) : [];
    return ['method' => in_array($s['method'] ?? '', ['auto', 'equal', 'area', 'fixed'], true) ? $s['method'] : 'auto',
            'fixed' => array_map('floatval', is_array($s['fixed'] ?? null) ? $s['fixed'] : []),
            'rest' => ($s['rest'] ?? '') === 'area' ? 'area' : 'equal'];
}

function pm_split_clean(array $in, int $projectId): array
{
    $s = pm_split_of(json_encode($in));
    $fixed = [];
    foreach ($s['fixed'] as $u => $v) {
        if ($v < 0 || $v > 1e9) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'סכום ליחידה']); // i18n-ok
        if ($v > 0) $fixed[(string)pm_unit_of_project((int)$u, $projectId)] = round($v, 2);
    }
    $s['fixed'] = $fixed;
    return $s;
}

/**
 * החלוקה של סכום (בשלבים המשותפים) בין היחידות שהחוזה חל עליהן: unit_id => יחס (סכום היחסים = 1).
 * [] — לא מחלקים (auto, או בלי יחידות). $total — הסכום שמחלקים (₪), בשביל "סכום קבוע".
 */
function pm_contract_ratios(?array $c, array $units, float $total): array
{
    if (!$c || !$units) return [];
    $sp = $c['split'];
    if ($sp['method'] === 'auto') return [];
    $cover = $c['unit_ids'] ? array_values(array_filter($units, fn($u) => in_array($u['id'], $c['unit_ids'], true))) : $units;
    if (!$cover) return [];
    $weights = function (string $how) use ($cover): array {
        $area = array_sum(array_map(fn($u) => (float)$u['area'], $cover));
        $w = [];
        foreach ($cover as $u) $w[$u['id']] = $how === 'area' && $area > 0 ? (float)$u['area'] / $area : 1 / count($cover);
        return $w;
    };
    if ($sp['method'] !== 'fixed') return $weights($sp['method']);
    // סכום קבוע לכל יחידה, והשאר לפי rest
    $fixed = [];
    foreach ($cover as $u) $fixed[$u['id']] = (float)($sp['fixed'][(string)$u['id']] ?? 0);
    $sumFixed = array_sum($fixed);
    $base = max($total, $sumFixed);
    if ($base <= 0) return $weights($sp['rest']);
    $rest = $base - $sumFixed;
    $w = $weights($sp['rest']);
    $out = [];
    foreach ($cover as $u) $out[$u['id']] = ($fixed[$u['id']] + $rest * $w[$u['id']]) / $base;
    return $out;
}
