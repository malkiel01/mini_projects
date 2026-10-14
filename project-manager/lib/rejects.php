<?php
/**
 * 🔧 ריג'קטים (PM-028) — מטלות פתוחות לספקים, עד המסירה. README: "ריג'קטים".
 *
 * ריג'קט: של ספק (ושלב / דירה, לא חובה), כותרת, הסבר, יעד — ושרשור: כל הודעה עם הסבר ותמונות / מסמכים.
 * מצבים: open (פתוח) → fixed (הספק דיווח "תיקנתי") → done (רק המנהל סוגר) או returned (חזר — לא תוקן כמו שצריך,
 * עם הסבר וקבצים בשרשור; נחשב פתוח). ריג'קט שהלקוח פותח — pending (ממתין לאישור המנהל), ורק אחרי האישור נשלח לספק.
 * כל עוד יש ריג'קט שלא נסגר — אין מסירה (פרויקט "הסתיים" / דירה "נמסרה"), וממילא העיכבון לא משתחרר.
 */

declare(strict_types=1);

// i18n-ok-start
const PM_REJECT_STATUSES = ['pending' => 'ממתין לאישור המנהל', 'open' => 'פתוח', 'fixed' => 'הספק דיווח שתיקן', 'returned' => 'חזר — לא תוקן כמו שצריך',
    'done' => 'בוצע', 'cancelled' => 'בוטל'];
const PM_REJECT_KINDS = ['opened' => 'נפתח', 'note' => 'הודעה', 'fixed' => 'דיווח: תיקנתי', 'done' => 'סומן בוצע', 'returned' => 'הוחזר — לא תוקן כמו שצריך',
    'approved' => 'אושר ונשלח לספק', 'cancelled' => 'בוטל', 'reopened' => 'נפתח מחדש', 'reminded' => 'תזכורת לספק'];
// i18n-ok-end
const PM_REJECT_OPEN = ['open', 'fixed', 'returned']; // לא נסגרו — חוסמים מסירה (pending עוד לא אושר — לא חוסם)

function pm_reject_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM rejects WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch() ?: throw new PmError('הריג\'קט לא נמצא', 404);
    pm_project_row($user, (int)$r['project_id']);
    return $r;
}

/** התיקייה לקבצים של ריג'קטים: "🔧 ריג'קטים" בשורש הפרויקט. */
function pm_reject_folder(int $projectId): int
{
    $db = pm_db();
    $name = '🔧 ריג\'קטים'; // i18n-ok — שם תיקייה, נשמר כמו שהוא
    $st = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND parent_id IS NULL AND kind = 'custom' AND name = ?");
    $st->execute([$projectId, $name]);
    $id = (int)$st->fetchColumn();
    if ($id) return $id;
    $db->prepare("INSERT INTO folders (project_id, parent_id, kind, name, sort, created_at) VALUES (?, NULL, 'custom', ?, 95, ?)")->execute([$projectId, $name, pm_now()]);
    return (int)$db->lastInsertId();
}

/**
 * הריג'קטים של פרויקט, עם השרשור והקבצים. $who — לפורטל: ['role' => client|lawyer|supplier, 'contact' => id] —
 * ספק רואה רק את שלו; לקוח / עו"ד — הכול (חוץ מבוטלים); בלי פרטים פנימיים.
 */
function pm_rejects_of(int $projectId, ?array $who = null): array
{
    $db = pm_db();
    $w = ['r.project_id = ?'];
    $a = [$projectId];
    if ($who) {
        $w[] = "r.status != 'cancelled'";
        if ($who['role'] === 'supplier') { $w[] = 'r.supplier_id = ?'; $a[] = $who['contact']; $w[] = "r.status != 'pending'"; }
    }
    $st = $db->prepare('SELECT r.*, c.name AS supplier, s.name AS stage, u.name AS unit FROM rejects r LEFT JOIN contacts c ON c.id = r.supplier_id
                         LEFT JOIN stages s ON s.id = r.stage_id LEFT JOIN units u ON u.id = r.unit_id WHERE ' . implode(' AND ', $w) . '
                        ORDER BY CASE r.status WHEN \'pending\' THEN 0 WHEN \'returned\' THEN 1 WHEN \'open\' THEN 2 WHEN \'fixed\' THEN 3 WHEN \'done\' THEN 4 ELSE 5 END, r.id DESC');
    $st->execute($a);
    $rows = $st->fetchAll();
    $events = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $q = implode(',', array_fill(0, count($ids), '?'));
        $ev = $db->prepare("SELECT * FROM reject_events WHERE reject_id IN ($q) ORDER BY id");
        $ev->execute($ids);
        $evRows = $ev->fetchAll();
        $files = [];
        if ($evRows) {
            $eids = array_column($evRows, 'id');
            $q2 = implode(',', array_fill(0, count($eids), '?'));
            $f = $db->prepare("SELECT ef.event_id, f.id, f.name, f.mime, f.thumb IS NOT NULL AS thumb FROM reject_event_files ef JOIN files f ON f.id = ef.file_id AND f.deleted_at IS NULL
                                WHERE ef.event_id IN ($q2) ORDER BY ef.file_id");
            $f->execute($eids);
            foreach ($f->fetchAll() as $x) $files[(int)$x['event_id']][] = ['id' => (int)$x['id'], 'name' => $x['name'], 'mime' => $x['mime'], 'thumb' => (bool)$x['thumb']];
        }
        foreach ($evRows as $e) $events[(int)$e['reject_id']][] = ['id' => (int)$e['id'], 'kind' => $e['kind'], 'kind_label' => pm_t(PM_REJECT_KINDS[$e['kind']] ?? $e['kind']),
            'by_role' => $e['by_role'], 'by_name' => $e['by_name'], 'body' => $e['body'], 'at' => $e['created_at'], 'files' => $files[(int)$e['id']] ?? []];
    }
    $list = array_map(fn($r) => ['id' => (int)$r['id'], 'supplier_id' => $r['supplier_id'] !== null ? (int)$r['supplier_id'] : null, 'supplier' => $r['supplier'] ?? '',
        'stage_id' => $r['stage_id'] !== null ? (int)$r['stage_id'] : null, 'stage' => $r['stage'] ? pm_t($r['stage']) : '',
        'unit_id' => $r['unit_id'] !== null ? (int)$r['unit_id'] : null, 'unit' => $r['unit'] ?? '',
        'title' => $r['title'], 'due_date' => $r['due_date'], 'status' => $r['status'], 'status_label' => pm_t(PM_REJECT_STATUSES[$r['status']] ?? $r['status']),
        'opened_by' => $r['opened_by'], 'created_at' => $r['created_at'], 'closed_at' => $r['closed_at'],
        'late' => in_array($r['status'], ['open', 'returned'], true) && $r['due_date'] !== '' && $r['due_date'] < pm_today(),
        'events' => $events[(int)$r['id']] ?? []], $rows);
    $open = array_filter($list, fn($r) => in_array($r['status'], PM_REJECT_OPEN, true));
    return ['rejects' => $list, 'counts' => ['open' => count($open), 'fixed' => count(array_filter($list, fn($r) => $r['status'] === 'fixed')),
        'pending' => count(array_filter($list, fn($r) => $r['status'] === 'pending')), 'done' => count(array_filter($list, fn($r) => $r['status'] === 'done'))],
        'statuses' => pm_t_map(PM_REJECT_STATUSES)];
}

function pm_rejects(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    return pm_rejects_of($projectId) + ['folder_id' => pm_reject_folder($projectId)];
}

/** קבצים לשרשור: שהועלו לפרויקט (ובפורטל — באותו קישור), ועוד לא שויכו. */
function pm_reject_files_check(int $projectId, array $fileIds, ?int $portalId = null): array
{
    $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
    if (!$fileIds) return [];
    if (count($fileIds) > 20) throw new PmError('עד 20 קבצים בהודעה', 422);
    $q = implode(',', array_fill(0, count($fileIds), '?'));
    $st = pm_db()->prepare("SELECT id FROM files WHERE id IN ($q) AND project_id = ? AND deleted_at IS NULL" . ($portalId ? ' AND portal_id = ?' : ''));
    $st->execute(array_merge($fileIds, [$projectId], $portalId ? [$portalId] : []));
    if (count($st->fetchAll()) !== count($fileIds)) throw new PmError('קובץ לא נמצא בפרויקט', 422);
    return $fileIds;
}

/** הודעה בשרשור (עם קבצים). */
function pm_reject_event(int $rejectId, string $kind, string $role, string $name, string $body, array $fileIds, ?int $portalId = null): int
{
    $db = pm_db();
    $db->prepare('INSERT INTO reject_events (reject_id, kind, by_role, by_name, body, portal_id, created_at) VALUES (?,?,?,?,?,?,?)')
       ->execute([$rejectId, $kind, $role, $name, mb_substr(trim($body), 0, 4000), $portalId, pm_now()]);
    $eid = (int)$db->lastInsertId();
    $ins = $db->prepare('INSERT OR IGNORE INTO reject_event_files (event_id, file_id) VALUES (?, ?)');
    foreach ($fileIds as $f) $ins->execute([$eid, $f]);
    $db->prepare('UPDATE rejects SET updated_at = ? WHERE id = ?')->execute([pm_now(), $rejectId]);
    return $eid;
}

/** ריג'קט חדש מהמנהל: {project_id, supplier_id, stage_id?, unit_id?, title, body, due_date?, file_ids[]} — נשלח לספק. */
function pm_reject_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $id = (int)($in['id'] ?? 0);
    $supplierId = pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק') // i18n-ok
        ?? throw new PmError('ספק: שדה חובה', 422);
    $stageId = (int)($in['stage_id'] ?? 0) ?: null;
    if ($stageId && (int)pm_stage_row($user, $stageId)['project_id'] !== $projectId) throw new PmError('השלב לא שייך לפרויקט', 422);
    $unitId = array_key_exists('unit_id', $in) ? pm_unit_of_project((int)($in['unit_id'] ?? 0) ?: null, $projectId) : null;
    if (!$unitId && $stageId) $unitId = pm_stage_unit($stageId);
    $title = pm_str($in, 'title', 200, true, 'מה צריך לתקן');
    $due = pm_date($in, 'due_date', 'עד מתי'); // i18n-ok
    $db = pm_db();
    $now = pm_now();
    if ($id) {
        // עריכה — של מה, למי ועד מתי (השרשור נשאר)
        $old = pm_reject_row($user, $id);
        $db->prepare('UPDATE rejects SET supplier_id = ?, stage_id = ?, unit_id = ?, title = ?, due_date = ?, updated_at = ? WHERE id = ?')
           ->execute([$supplierId, $stageId, $unitId, $title, $due, $now, $id]);
        return pm_rejects($user, $projectId) + ['saved' => $id];
    }
    $files = pm_reject_files_check($projectId, (array)($in['file_ids'] ?? []));
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO rejects (project_id, supplier_id, stage_id, unit_id, title, due_date, status, opened_by, created_by, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,'open','manager',?,?,?)")
           ->execute([$projectId, $supplierId, $stageId, $unitId, $title, $due, $user['id'], $now, $now]);
        $id = (int)$db->lastInsertId();
        pm_reject_event($id, 'opened', 'manager', (string)$user['display_name'], (string)($in['body'] ?? ''), $files);
        pm_event($user, $projectId, 'reject', '🔧 ריג\'קט ל{supplier}: {title}', ['reject' => $id], ['supplier' => pm_contact_name($supplierId), 'title' => $title]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    pm_reject_notify($user, $id, $supplierId, 'reject_new');
    return pm_rejects($user, $projectId) + ['saved' => $id];
}

function pm_reject_notify(array $actor, int $id, int $contactId, string $key): void
{
    $r = pm_db()->query('SELECT * FROM rejects WHERE id = ' . $id)->fetch();
    if (!$r || !$contactId) return;
    // i18n-none-start — שמות המשתנים בנוסח
    pm_notify((int)$r['project_id'], $contactId, $key, ['פרטים' => $r['title'] . ($r['due_date'] !== '' ? ' — ' . pm_t('עד {date}', ['date' => pm_short_date($r['due_date'])], pm_contact_lang($contactId)) : ''),
        'קישור' => pm_notify_link((int)$r['project_id'], $contactId)], $actor);
    // i18n-none-end
}

/**
 * פעולה של המנהל על ריג'קט: note (הודעה), done (בוצע — סוגר), returned (חזר — לא תוקן, עם הסבר), approve (ריג'קט של
 * הלקוח — אושר ונשלח לספק), cancel, reopen. {id, action, body, file_ids[]}
 */
function pm_reject_act(array $user, array $in): array
{
    $r = pm_reject_row($user, (int)($in['id'] ?? 0));
    $id = (int)$r['id'];
    $pid = (int)$r['project_id'];
    $action = (string)($in['action'] ?? '');
    $body = trim((string)($in['body'] ?? ''));
    $files = pm_reject_files_check($pid, (array)($in['file_ids'] ?? []));
    $next = match ($action) {
        'note' => $r['status'],
        'done' => in_array($r['status'], PM_REJECT_OPEN, true) ? 'done' : throw new PmError('אפשר לסמן "בוצע" רק ריג\'קט פתוח', 422),
        'returned' => in_array($r['status'], ['fixed', 'open', 'returned'], true) ? 'returned' : throw new PmError('אפשר להחזיר רק ריג\'קט שעוד לא נסגר', 422),
        'approve' => $r['status'] === 'pending' ? 'open' : throw new PmError('הריג\'קט כבר אושר', 422),
        'cancel' => $r['status'] !== 'cancelled' ? 'cancelled' : throw new PmError('הריג\'קט כבר בוטל', 422),
        'reopen' => in_array($r['status'], ['done', 'cancelled'], true) ? 'open' : throw new PmError('הריג\'קט לא סגור', 422),
        default => throw new PmError('פעולה לא מוכרת', 422),
    };
    if ($action === 'note' && $body === '' && !$files) throw new PmError('הודעה ריקה — כתבו משהו או צרפו קובץ', 422);
    if ($action === 'returned' && $body === '') throw new PmError('כתבו למה זה לא תוקן כמו שצריך', 422);
    // ריג'קט שהלקוח פתח — באישור קובעים לאיזה ספק (ושלב) הוא שייך
    if ($action === 'approve') {
        $p = pm_project_row($user, $pid);
        $sup = (int)($in['supplier_id'] ?? 0) ?: (int)$r['supplier_id'];
        $sup = pm_owned_contact($sup ?: null, (int)$p['owner_id'], 'supplier', 'הספק') ?? throw new PmError('לפני האישור — לאיזה ספק הריג\'קט', 422); // i18n-ok
        $stageId = (int)($in['stage_id'] ?? 0) ?: ($r['stage_id'] !== null ? (int)$r['stage_id'] : null);
        pm_db()->prepare('UPDATE rejects SET supplier_id = ?, stage_id = ?, unit_id = COALESCE(unit_id, ?) WHERE id = ?')
           ->execute([$sup, $stageId, $stageId ? pm_stage_unit($stageId) : null, $id]);
        $r['supplier_id'] = $sup;
    }
    $db = pm_db();
    $db->beginTransaction();
    try {
        pm_reject_event($id, $action === 'approve' ? 'approved' : ($action === 'cancel' ? 'cancelled' : ($action === 'reopen' ? 'reopened' : $action)),
            'manager', (string)$user['display_name'], $body, $files);
        if ($next !== $r['status']) {
            $db->prepare('UPDATE rejects SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?')
               ->execute([$next, $next === 'done' ? pm_now() : null, pm_now(), $id]);
            // i18n-ok-start
            $text = ['done' => '✅ ריג\'קט בוצע: {title} — {supplier}', 'returned' => '↩️ ריג\'קט הוחזר — לא תוקן כמו שצריך: {title} — {supplier}',
                'open' => $action === 'approve' ? '🔧 ריג\'קט של הלקוח אושר ונשלח ל{supplier}: {title}' : '🔧 ריג\'קט נפתח מחדש: {title} — {supplier}',
                'cancelled' => '🔧 ריג\'קט בוטל: {title} — {supplier}'][$next] ?? '';
            // i18n-ok-end
            if ($text) pm_event($user, $pid, 'reject', $text, ['reject' => $id], ['title' => $r['title'], 'supplier' => pm_contact_name((int)$r['supplier_id'])]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    // לספק: ריג'קט שנשלח אליו (אושר / נפתח מחדש), שהוחזר, או הודעה חדשה
    if (in_array($action, ['approve', 'reopen'], true)) pm_reject_notify($user, $id, (int)$r['supplier_id'], 'reject_new');
    elseif (in_array($action, ['returned', 'note'], true)) pm_reject_notify($user, $id, (int)$r['supplier_id'], 'reject_update');
    return pm_rejects($user, $pid);
}

/** ריג'קטים שעוד לא נסגרו — לחסימת המסירה. $unitId — דירה: שלה ושל השלבים המשותפים; null — כל הפרויקט. */
function pm_rejects_open(int $projectId, ?int $unitId = null): array
{
    $q = 'SELECT r.title, c.name AS supplier FROM rejects r LEFT JOIN contacts c ON c.id = r.supplier_id WHERE r.project_id = ? AND r.status IN (\'open\',\'fixed\',\'returned\')';
    $a = [$projectId];
    if ($unitId) { $q .= ' AND (r.unit_id = ? OR r.unit_id IS NULL)'; $a[] = $unitId; }
    $st = pm_db()->prepare($q . ' ORDER BY r.id');
    $st->execute($a);
    return $st->fetchAll();
}

/** חסימת מסירה: יש ריג'קטים פתוחים — שגיאה שאומרת מה ואצל מי. */
function pm_rejects_guard_handover(int $projectId, ?int $unitId = null): void
{
    $open = pm_rejects_open($projectId, $unitId);
    if (!$open) return;
    $by = [];
    foreach ($open as $r) $by[$r['supplier'] ?: '—'][] = $r['title'];
    $list = implode(' · ', array_map(fn($s, $t) => $s . ': ' . count($t), array_keys($by), $by));
    throw new PmError('אי אפשר למסור — יש {n} ריג\'קטים פתוחים ({list}). סגרו אותם ב"🔧 ריג\'קטים"', 409, ['n' => count($open), 'list' => $list]);
}

/**
 * תזכורת לספק על ריג'קט שעבר את היעד ועוד לא תוקן (פתוח / הוחזר): מייל עם הקישור, ושורה בשרשור. חוזרת כל N ימים
 * לפי הגדרת המנהל (הגדרות ← התראות, reject_days; 0 — בלי תזכורת) — עד שהספק מדווח "תיקנתי" או שהריג'קט נסגר.
 * נקרא מהמייל היומי (pm_digest_tick), פעם ביום.
 */
function pm_rejects_tick(): void
{
    $db = pm_db();
    $today = pm_today();
    $st = $db->prepare("SELECT r.*, p.owner_id FROM rejects r JOIN projects p ON p.id = r.project_id WHERE r.status IN ('open','returned') AND r.supplier_id IS NOT NULL
                         AND r.due_date != '' AND r.due_date < ? AND p.status IN ('active','paused')");
    $st->execute([$today]);
    $system = ['id' => 0, 'display_name' => 'מערכת']; // i18n-ok
    foreach ($st->fetchAll() as $r) {
        $every = (int)pm_fin_alert_settings((int)$r['owner_id'])['reject_days'];
        if ($every <= 0 || ($r['reminded_at'] !== null && $r['reminded_at'] > pm_today(-$every))) continue;
        $db->prepare('UPDATE rejects SET reminded_at = ? WHERE id = ?')->execute([$today, $r['id']]); // קודם מסמנים — שלא יישלח פעמיים
        $days = (int)((strtotime($today) - strtotime($r['due_date'])) / 86400);
        pm_reject_event((int)$r['id'], 'reminded', 'system', pm_t('מערכת'), pm_t('תזכורת לספק: עבר היעד ({date}) לפני {n} ימים', ['date' => pm_short_date($r['due_date']), 'n' => $days]), []);
        pm_reject_notify($system, (int)$r['id'], (int)$r['supplier_id'], 'reject_late');
        pm_log('info', 'mail', 'reject.reminder', ['data' => ['reject' => (int)$r['id'], 'supplier' => (int)$r['supplier_id'], 'days' => $days]]);
    }
}

