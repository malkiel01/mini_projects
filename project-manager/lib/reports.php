<?php
/**
 * דיווח מהשטח — שלב 8 (README, "דיווח ביצוע").
 *
 * עדכון: טקסט ותמונות / סרטונים מהמצלמה, אופציונלית לשלב. "השלב בוצע": דיווח מסוג
 * done על שלב — **חובה לפחות תמונה או סרטון אחד** — והוא מה שמעביר את השלב ל"בוצע"
 * (בחלון השלב אי אפשר לסמן "בוצע" ישירות). כשכל תשלומי השלב כבר התקבלו — "שולם וסגור".
 *
 * הקבצים — בתיקייה "תמונות מהשטח / <שם השלב>" (נוצרת לבד). דיווח גלוי (ברירת מחדל)
 * מסמן את הקבצים שלו "גלוי", והלקוח והעו"ד רואים אותו בפורטל.
 */

declare(strict_types=1);

require_once __DIR__ . '/payments.php';

function pm_report_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM reports WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new PmError('הדיווח לא נמצא', 404);
    pm_project_row($user, (int)$r['project_id']);
    return $r;
}

/** התיקייה לתמונות של דיווח: "תמונות מהשטח", ובתוכה תיקייה לשלב. */
function pm_report_folder(array $user, int $projectId, ?int $stageId): int
{
    $p = pm_project_row($user, $projectId);
    pm_ensure_folders($p);
    $db = pm_db();
    $st = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'photos' AND parent_id IS NULL");
    $st->execute([$projectId]);
    $photos = (int)$st->fetchColumn();
    if (!$stageId) return $photos;
    $stage = pm_stage_row($user, $stageId);
    $sub = $db->prepare('SELECT id FROM folders WHERE project_id = ? AND parent_id = ? AND name = ?');
    $sub->execute([$projectId, $photos, $stage['name']]);
    $id = (int)$sub->fetchColumn();
    if ($id) return $id;
    $db->prepare("INSERT INTO folders (project_id, parent_id, kind, name, sort, created_at) VALUES (?, ?, 'custom', ?, ?, ?)")
       ->execute([$projectId, $photos, $stage['name'], (int)$stage['sort'], pm_now()]);
    return (int)$db->lastInsertId();
}

/** הדיווחים של פרויקט, החדש למעלה. $portal — רק הגלויים, בלי פרטים פנימיים. */
function pm_reports_of(int $projectId, bool $portal = false): array
{
    $st = pm_db()->prepare('SELECT r.*, s.name AS stage_name, u.display_name AS author FROM reports r
                             LEFT JOIN stages s ON s.id = r.stage_id LEFT JOIN users u ON u.id = r.created_by
                            WHERE r.project_id = ? AND r.deleted_at IS NULL' . ($portal ? ' AND r.visible = 1' : '') . '
                            ORDER BY r.created_at DESC, r.id DESC LIMIT 300');
    $st->execute([$projectId]);
    $rows = $st->fetchAll();
    $media = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $f = pm_db()->prepare("SELECT rf.report_id, f.id, f.name, f.mime, f.size, f.thumb IS NOT NULL AS thumb FROM report_files rf
                                JOIN files f ON f.id = rf.file_id AND f.deleted_at IS NULL WHERE rf.report_id IN ($in) ORDER BY rf.sort");
        $f->execute($ids);
        foreach ($f->fetchAll() as $x) $media[(int)$x['report_id']][] = ['id' => (int)$x['id'], 'name' => $x['name'], 'mime' => $x['mime'],
            'size' => (int)$x['size'], 'thumb' => (bool)$x['thumb'], 'video' => str_starts_with($x['mime'], 'video/')];
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'stage_id' => $r['stage_id'] !== null ? (int)$r['stage_id'] : null, 'stage_name' => $r['stage_name'],
        'kind' => $r['kind'], 'text' => $r['text'], 'visible' => (bool)$r['visible'], 'at' => $r['created_at'],
        'author' => $portal ? null : $r['author'], 'media' => $media[(int)$r['id']] ?? [],
    ], $rows);
}

function pm_reports(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    return ['reports' => pm_reports_of($projectId)];
}

/**
 * דיווח חדש: {project_id, stage_id?, kind, text, visible, file_ids[]}.
 * הקבצים כבר הועלו (upload-start … upload-finish quiet) לתיקייה מ-report-folder.
 */
function pm_report_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    pm_project_row($user, $projectId);
    $kind = (string)($in['kind'] ?? 'update');
    if (!in_array($kind, ['update', 'done'], true)) throw new PmError('סוג דיווח לא מוכר', 422);
    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    $stage = null;
    if ($stageId) {
        $stage = pm_stage_row($user, $stageId);
        if ((int)$stage['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    }
    $text = pm_str($in, 'text', 4000, false, 'תיאור');
    $fileIds = array_values(array_unique(array_map('intval', is_array($in['file_ids'] ?? null) ? $in['file_ids'] : [])));
    $media = [];
    if ($fileIds) {
        $q = implode(',', array_fill(0, count($fileIds), '?'));
        $st = pm_db()->prepare("SELECT id, mime FROM files WHERE id IN ($q) AND project_id = ? AND deleted_at IS NULL");
        $st->execute(array_merge($fileIds, [$projectId]));
        $media = $st->fetchAll();
        if (count($media) !== count($fileIds)) throw new PmError('קובץ לא נמצא בפרויקט', 422);
    }
    $pics = count(array_filter($media, fn($m) => preg_match('#^(image|video)/#', $m['mime'])));
    if ($kind === 'done') {
        if (!$stage) throw new PmError('"השלב בוצע" — צריך לבחור שלב', 422);
        if (!$pics) throw new PmError('כדי לסמן שלב "בוצע" — צריך לפחות תמונה או סרטון אחד', 422);
        if (in_array($stage['status'], ['done', 'paid'], true)) throw new PmError('השלב כבר מסומן ' . PM_STAGE_STATUSES[$stage['status']], 422);
    }
    if ($text === '' && !$fileIds) throw new PmError('דיווח ריק — כתבו משהו או צרפו תמונה', 422);
    $visible = !array_key_exists('visible', $in) || !empty($in['visible']) ? 1 : 0;

    $db = pm_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO reports (project_id, stage_id, kind, text, visible, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$projectId, $stageId, $kind, $text, $visible, $user['id'], pm_now()]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO report_files (report_id, file_id, sort) VALUES (?,?,?)');
        foreach ($fileIds as $i => $f) $ins->execute([$id, $f, $i]);
        pm_report_files_visible($id, (bool)$visible);
        $count = $fileIds ? ' (' . count($fileIds) . ' ' . (count($fileIds) > 1 ? 'קבצים' : 'קובץ') . ')' : '';
        if ($kind === 'done') {
            pm_event($user, $projectId, 'report', "✅ {$stage['name']}: בוצע$count" . ($text !== '' ? " — $text" : ''), ['report' => $id, 'stage' => $stageId]);
            $db->prepare("UPDATE stages SET status = 'done', done_at = ?, updated_at = ? WHERE id = ?")->execute([pm_now(), pm_now(), $stageId]);
            pm_stage_close_if_paid($user, $stageId);
        } else {
            pm_event($user, $projectId, 'report', '📷 עדכון מהשטח' . ($stage ? " — {$stage['name']}" : '') . $count . ($text !== '' ? ": $text" : ''),
                ['report' => $id, 'stage' => $stageId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['reports' => pm_reports_of($projectId), 'saved' => $id];
}

/** קבצי הדיווח גלויים בפורטל כמו הדיווח עצמו. */
function pm_report_files_visible(int $reportId, bool $visible): void
{
    pm_db()->prepare('UPDATE files SET visible = ? WHERE id IN (SELECT file_id FROM report_files WHERE report_id = ?)')
       ->execute([$visible ? 1 : 0, $reportId]);
}

/** עריכה: טקסט וגלוי בפורטל. */
function pm_report_update(array $user, array $in): array
{
    $r = pm_report_row($user, (int)($in['id'] ?? 0));
    $text = pm_str($in, 'text', 4000, false, 'תיאור');
    $visible = !empty($in['visible']) ? 1 : 0;
    pm_db()->prepare('UPDATE reports SET text = ?, visible = ? WHERE id = ?')->execute([$text, $visible, $r['id']]);
    if ((int)$r['visible'] !== $visible) {
        pm_report_files_visible((int)$r['id'], (bool)$visible);
        pm_event($user, (int)$r['project_id'], 'report', ($visible ? '👁 דיווח מהשטח גלוי ללקוח' : 'דיווח מהשטח הוסתר מהלקוח') . ' (' . pm_short_date(substr($r['created_at'], 0, 10)) . ')', ['report' => (int)$r['id']]);
    }
    return ['reports' => pm_reports_of((int)$r['project_id'])];
}

/** מחיקה = הסתרה מכל מקום (deleted_at). הקבצים נשארים בתיקייה, פנימיים. */
function pm_report_delete(array $user, int $id): array
{
    $r = pm_report_row($user, $id);
    if ($r['kind'] === 'done') throw new PmError('דיווח "בוצע" לא נמחק — אפשר להסתיר אותו מהלקוח', 422);
    pm_db()->prepare('UPDATE reports SET deleted_at = ? WHERE id = ?')->execute([pm_now(), $id]);
    pm_report_files_visible($id, false);
    pm_event($user, (int)$r['project_id'], 'report', 'נמחק דיווח מהשטח (' . pm_short_date(substr($r['created_at'], 0, 10)) . ')', ['report' => $id]);
    return ['reports' => pm_reports_of((int)$r['project_id'])];
}
