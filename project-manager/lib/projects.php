<?php
/**
 * פרויקטים, שלבים, צוות וציר זמן — שלב 2.
 *
 * פרויקט הוא "צ'אט": כל שינוי בו נרשם כאירוע בציר הזמן (events), ומעדכן את
 * last_activity_at ואת last_event — מה שממיין את מסך הבית ומוצג מתחת לשם.
 *
 * בעלות: פרויקט שייך למנהל שיצר אותו, וכל איש קשר שמשויך אליו (לקוח, עו"ד,
 * ספק) חייב להיות של אותו מנהל. כל מנהל — גם המפתח — רואה רק את שלו.
 */

declare(strict_types=1);

require_once __DIR__ . '/contacts.php';

const PM_PROJECT_KINDS = ['renovation' => 'שיפוץ', 'construction' => 'בנייה', 'addition' => 'תוספת בנייה', 'other' => 'אחר'];
const PM_PROJECT_STATUSES = ['active' => 'פעיל', 'paused' => 'מושהה', 'done' => 'הסתיים', 'archived' => 'בארכיון'];
/** מסלול השלב (README, "שלב"). בשלב 2 המנהל מעביר ידנית; בהמשך הצעות ואישורים יקדמו לבד. */
const PM_STAGE_STATUSES = [
    'planned'         => 'מתוכנן',
    'quotes'          => 'איסוף הצעות',
    'client_approval' => 'ממתין ללקוח',
    'lawyer_approval' => 'ממתין לעו"ד',
    'approved'        => 'מאושר',
    'in_progress'     => 'בביצוע',
    'done'            => 'בוצע',
    'paid'            => 'שולם וסגור',
];

function pm_date(array $in, string $key, string $label): string
{
    $v = pm_str($in, $key, 10, false, $label);
    if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))) throw new PmError("$label: תאריך לא תקין", 422);
    return $v;
}

/** פרויקט שהמשתמש רשאי לראות — או 404. */
function pm_project_row(array $user, int $id): array
{
    [$where, $args] = pm_owner_clause($user, 'p');
    $st = pm_db()->prepare("SELECT p.* FROM projects p WHERE p.id = ? AND $where");
    $st->execute(array_merge([$id], $args));
    $row = $st->fetch();
    if (!$row) throw new PmError('הפרויקט לא נמצא', 404);
    return $row;
}

/** איש קשר מסוג מסוים של בעל הפרויקט — או חריגה. null נשאר null. */
function pm_owned_contact(?int $id, int $ownerId, string $kind, string $label): ?int
{
    if (!$id) return null;
    $st = pm_db()->prepare('SELECT 1 FROM contacts WHERE id = ? AND owner_id = ? AND kind = ?');
    $st->execute([$id, $ownerId, $kind]);
    if (!$st->fetchColumn()) throw new PmError("$label לא נמצא", 422);
    return $id;
}

/** רישום אירוע בציר הזמן, ועדכון "הפעילות האחרונה" של הפרויקט. */
function pm_event(array $user, int $projectId, string $kind, string $text, array $data = []): void
{
    $now = pm_now();
    pm_db()->prepare('INSERT INTO events (project_id, at, actor_id, actor_name, kind, text, data_json) VALUES (?,?,?,?,?,?,?)')
        ->execute([$projectId, $now, $user['id'], $user['display_name'], $kind, $text, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    pm_db()->prepare('UPDATE projects SET last_activity_at = ?, last_event = ? WHERE id = ?')
        ->execute([$now, mb_substr($text, 0, 140), $projectId]);
    // מהפורטל: $user['actor'] = "portal:<קישור>" — לא משתמש של האפליקציה
    pm_audit($user['actor'] ?? "user:{$user['id']}", "project.$kind", 'project', $projectId, $data + ['text' => $text]);
}

// ---- רשימה ----

/** $filter: open (פעיל + מושהה) · done · archived · all */
function pm_projects_list(array $user, string $q, string $filter): array
{
    [$where, $args] = pm_owner_clause($user, 'p');
    $sql = "SELECT p.id, p.owner_id, p.name, p.address, p.kind, p.status, p.last_activity_at, p.last_event,
                   c.name AS client_name, m.display_name AS owner_name,
                   (SELECT COUNT(*) FROM stages s WHERE s.project_id = p.id) AS stages_total,
                   (SELECT COUNT(*) FROM stages s WHERE s.project_id = p.id AND s.status IN ('done','paid')) AS stages_done,
                   (SELECT s.name || '|' || s.status FROM stages s WHERE s.project_id = p.id
                        AND s.status NOT IN ('done','paid') ORDER BY s.sort, s.id LIMIT 1) AS current_stage
              FROM projects p
              LEFT JOIN contacts c ON c.id = p.client_id
              LEFT JOIN users m ON m.id = p.owner_id
             WHERE $where";
    $sql .= match ($filter) {
        'done'     => " AND p.status = 'done'",
        'archived' => " AND p.status = 'archived'",
        'all'      => '',
        default    => " AND p.status IN ('active','paused')",
    };
    if ($q !== '') {
        $sql .= ' AND (p.name LIKE ? OR p.address LIKE ? OR c.name LIKE ?)';
        $like = '%' . strtr($q, ['%' => '', '_' => '']) . '%';
        array_push($args, $like, $like, $like);
    }
    $sql .= ' ORDER BY p.last_activity_at DESC';
    $st = pm_db()->prepare($sql);
    $st->execute($args);
    return array_map(function ($r) {
        $cur = null;
        if ($r['current_stage']) {
            [$name, $status] = explode('|', $r['current_stage'], 2);
            $cur = ['name' => $name, 'status' => $status];
        }
        return [
            'id' => (int)$r['id'], 'owner_id' => (int)$r['owner_id'], 'owner_name' => $r['owner_name'],
            'name' => $r['name'], 'address' => $r['address'], 'kind' => $r['kind'], 'status' => $r['status'],
            'client_name' => $r['client_name'], 'last_activity_at' => $r['last_activity_at'], 'last_event' => $r['last_event'],
            'stages_total' => (int)$r['stages_total'], 'stages_done' => (int)$r['stages_done'], 'current_stage' => $cur,
        ];
    }, $st->fetchAll());
}

// ---- פרויקט אחד ----

function pm_project_get(array $user, int $id): array
{
    $p = pm_project_row($user, $id);
    $db = pm_db();

    $st = $db->prepare(
        'SELECT s.*, t.name AS trade_name, c.name AS supplier_name, c.phone AS supplier_phone
           FROM stages s LEFT JOIN trades t ON t.id = s.trade_id LEFT JOIN contacts c ON c.id = s.supplier_id
          WHERE s.project_id = ? ORDER BY s.sort, s.id'
    );
    $st->execute([$id]);
    $stages = array_map(fn($s) => [
        'id' => (int)$s['id'], 'sort' => (int)$s['sort'], 'name' => $s['name'],
        'trade_id' => $s['trade_id'] !== null ? (int)$s['trade_id'] : null, 'trade_name' => $s['trade_name'],
        'supplier_id' => $s['supplier_id'] !== null ? (int)$s['supplier_id'] : null,
        'supplier_name' => $s['supplier_name'], 'supplier_phone' => $s['supplier_phone'],
        'status' => $s['status'], 'needs_lawyer' => (bool)$s['needs_lawyer'],
        'plan_start' => $s['plan_start'], 'plan_end' => $s['plan_end'], 'done_at' => $s['done_at'],
    ], $st->fetchAll());

    $ev = $db->prepare('SELECT id, at, actor_id, actor_name, kind, text FROM events WHERE project_id = ? ORDER BY id DESC LIMIT 300');
    $ev->execute([$id]);
    $events = array_reverse(array_map(fn($e) => ['id' => (int)$e['id'], 'actor_id' => (int)$e['actor_id']] + $e, $ev->fetchAll()));

    return [
        'project' => [
            'id' => (int)$p['id'], 'owner_id' => (int)$p['owner_id'], 'name' => $p['name'], 'address' => $p['address'],
            'kind' => $p['kind'], 'status' => $p['status'], 'budget' => (int)$p['budget'],
            'client_id' => $p['client_id'] !== null ? (int)$p['client_id'] : null,
            'lawyer_id' => $p['lawyer_id'] !== null ? (int)$p['lawyer_id'] : null,
            'start_date' => $p['start_date'], 'due_date' => $p['due_date'], 'notes' => $p['notes'],
            'created_at' => $p['created_at'], 'last_activity_at' => $p['last_activity_at'],
        ],
        'stages' => $stages,
        'team'   => pm_project_team($p, $stages),
        'events' => $events,
    ];
}

/** הצוות: הלקוח, העו"ד, ספקי השלבים ואנשי צוות נוספים — כל אחד פעם אחת. */
function pm_project_team(array $p, array $stages): array
{
    $db = pm_db();
    $team = [];
    $add = function (?int $id, string $role, bool $removable = false) use (&$team, $db) {
        if (!$id) return;
        if (isset($team[$id])) { $team[$id]['roles'][] = $role; return; }
        $st = $db->prepare('SELECT id, kind, name, company, phone, whatsapp, email FROM contacts WHERE id = ?');
        $st->execute([$id]);
        if ($c = $st->fetch()) $team[$id] = ['id' => (int)$c['id']] + $c + ['roles' => [$role], 'removable' => $removable];
    };
    $add($p['client_id'] !== null ? (int)$p['client_id'] : null, 'לקוח');
    $add($p['lawyer_id'] !== null ? (int)$p['lawyer_id'] : null, 'עו"ד');
    foreach ($stages as $s) if ($s['supplier_id']) $add($s['supplier_id'], $s['name']);
    $m = $db->prepare('SELECT contact_id, role FROM project_members WHERE project_id = ?');
    $m->execute([$p['id']]);
    foreach ($m->fetchAll() as $r) {
        $id = (int)$r['contact_id'];
        if (isset($team[$id])) { $team[$id]['removable'] = true; $team[$id]['roles'][] = $r['role'] ?: 'צוות'; }
        else $add($id, $r['role'] ?: 'צוות', true);
    }
    return array_values($team);
}

function pm_project_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? pm_project_row($user, $id) : null;
    $ownerId = $existing ? (int)$existing['owner_id'] : $user['id'];

    $kind = (string)($in['kind'] ?? 'renovation');
    if (!isset(PM_PROJECT_KINDS[$kind])) throw new PmError('סוג פרויקט לא מוכר', 422);
    $clientId = pm_owned_contact((int)($in['client_id'] ?? 0) ?: null, $ownerId, 'client', 'הלקוח');
    $lawyerId = pm_owned_contact((int)($in['lawyer_id'] ?? 0) ?: null, $ownerId, 'lawyer', 'עורך הדין');
    // פרויקט חדש בלי עו"ד — לוקחים את העו"ד מהגדרות הלקוח
    if (!$existing && !$lawyerId && $clientId) $lawyerId = pm_client_settings($clientId)['lawyer_id'];
    $budget = $in['budget'] ?? 0;
    if (!is_numeric($budget) || $budget < 0 || $budget > 1e10) throw new PmError('תקציב לא תקין', 422);

    $f = [
        'name'       => pm_str($in, 'name', 100, true, 'שם הפרויקט'),
        'address'    => pm_str($in, 'address', 200, false, 'כתובת'),
        'kind'       => $kind,
        'client_id'  => $clientId,
        'lawyer_id'  => $lawyerId,
        'budget'     => (int)round((float)$budget),
        'start_date' => pm_date($in, 'start_date', 'תאריך התחלה'),
        'due_date'   => pm_date($in, 'due_date', 'יעד מסירה'),
        'notes'      => pm_str($in, 'notes', 4000, false, 'הערות'),
    ];

    $db = pm_db();
    $db->beginTransaction();
    try {
        $now = pm_now();
        if ($existing) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $db->prepare("UPDATE projects SET $set, updated_at = ? WHERE id = ?")
               ->execute(array_merge(array_values($f), [$now, $id]));
            $changed = array_keys(array_filter($f, fn($v, $k) => (string)$v !== (string)$existing[$k], ARRAY_FILTER_USE_BOTH));
            if ($changed) pm_event($user, $id, 'details', 'פרטי הפרויקט עודכנו', ['fields' => $changed]);
        } else {
            $cols = implode(', ', array_keys($f));
            $qs = implode(', ', array_fill(0, count($f), '?'));
            $db->prepare("INSERT INTO projects (owner_id, $cols, created_at, updated_at, last_activity_at) VALUES (?, $qs, ?, ?, ?)")
               ->execute(array_merge([$ownerId], array_values($f), [$now, $now, $now]));
            $id = (int)$db->lastInsertId();
            $count = pm_apply_template($id, (int)($in['template_id'] ?? 0));
            if ($count && pm_project_survey_first($user, $id)) $count++;
            pm_event($user, $id, 'created', 'הפרויקט נפתח' . ($count ? " עם $count שלבים" : ''));
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_project_get($user, $id);
}

/** שלבים מתבנית. התחום נמצא לפי שם; תחום שנמחק — השלב נוצר בלעדיו. */
function pm_apply_template(int $projectId, int $templateId): int
{
    if (!$templateId) return 0;
    $st = pm_db()->prepare('SELECT stages_json FROM stage_templates WHERE id = ?');
    $st->execute([$templateId]);
    $json = $st->fetchColumn();
    if ($json === false) throw new PmError('התבנית לא נמצאה', 422);
    $trades = array_column(pm_trades(), 'id', 'name');
    $ins = pm_db()->prepare('INSERT INTO stages (project_id, sort, name, trade_id, created_at, updated_at) VALUES (?,?,?,?,?,?)');
    $now = pm_now();
    $n = 0;
    foreach (json_decode($json, true) ?: [] as $s) {
        if (!is_array($s) || trim((string)($s['name'] ?? '')) === '') continue;
        $n++;
        $ins->execute([$projectId, $n * 10, trim($s['name']), $trades[$s['trade'] ?? ''] ?? null, $now, $now]);
    }
    return $n;
}

function pm_project_status(array $user, int $id, string $status): array
{
    $p = pm_project_row($user, $id);
    if (!isset(PM_PROJECT_STATUSES[$status])) throw new PmError('סטטוס לא מוכר', 422);
    if ($p['status'] !== $status) {
        pm_db()->prepare('UPDATE projects SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, pm_now(), $id]);
        pm_event($user, $id, 'status', 'סטטוס הפרויקט: ' . PM_PROJECT_STATUSES[$status], ['status' => $status]);
    }
    return pm_project_get($user, $id);
}

// ---- שלבים ----

function pm_stage_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM stages WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) throw new PmError('השלב לא נמצא', 404);
    pm_project_row($user, (int)$s['project_id']); // בדיקת הרשאה
    return $s;
}

function pm_stage_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? pm_stage_row($user, $id) : null;
    $projectId = $old ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);

    $status = (string)($in['status'] ?? ($old['status'] ?? 'planned'));
    if (!isset(PM_STAGE_STATUSES[$status])) throw new PmError('סטטוס שלב לא מוכר', 422);
    $tradeId = (int)($in['trade_id'] ?? 0) ?: null;
    if ($tradeId && !in_array($tradeId, array_column(pm_trades(), 'id'), true)) throw new PmError('תחום לא מוכר', 422);
    $f = [
        'name'         => pm_str($in, 'name', 80, true, 'שם השלב'),
        'trade_id'     => $tradeId,
        'supplier_id'  => pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק'),
        'status'       => $status,
        'needs_lawyer' => !empty($in['needs_lawyer']) ? 1 : 0,
        'plan_start'   => pm_date($in, 'plan_start', 'התחלה מתוכננת'),
        'plan_end'     => pm_date($in, 'plan_end', 'סיום מתוכנן'),
    ];
    $now = pm_now();
    $doneAt = in_array($status, ['done', 'paid'], true) ? ($old['done_at'] ?? null ?: $now) : null;

    $db = pm_db();
    $db->beginTransaction();
    try {
        if ($old) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $db->prepare("UPDATE stages SET $set, done_at = ?, updated_at = ? WHERE id = ?")
               ->execute(array_merge(array_values($f), [$doneAt, $now, $id]));
            if ($old['status'] !== $status) {
                pm_event($user, $projectId, 'stage_status', "{$f['name']}: " . PM_STAGE_STATUSES[$status], ['stage' => $id, 'status' => $status]);
            }
            if ((int)$old['supplier_id'] !== (int)$f['supplier_id'] && $f['supplier_id']) {
                pm_event($user, $projectId, 'stage_supplier', "{$f['name']}: שויך " . pm_contact_name($f['supplier_id']), ['stage' => $id]);
            }
        } else {
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) + 10 FROM stages WHERE project_id = $projectId")->fetchColumn();
            $cols = implode(', ', array_keys($f));
            $qs = implode(', ', array_fill(0, count($f), '?'));
            $db->prepare("INSERT INTO stages (project_id, sort, $cols, done_at, created_at, updated_at) VALUES (?, ?, $qs, ?, ?, ?)")
               ->execute(array_merge([$projectId, $sort], array_values($f), [$doneAt, $now, $now]));
            pm_event($user, $projectId, 'stage_added', "נוסף שלב: {$f['name']}", ['stage' => (int)$db->lastInsertId()]);
        }
        $surveyAdded = pm_project_survey_first($user, $projectId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_project_get($user, $projectId) + ($surveyAdded ? ['notice' => 'מודד מוסמך תמיד לפני אדריכלות — שלב המדידה סודר לפני האדריכלות'] : []);
}

function pm_contact_name(int $id): string
{
    $st = pm_db()->prepare('SELECT name FROM contacts WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

function pm_stage_delete(array $user, int $id): array
{
    $s = pm_stage_row($user, $id);
    // הצעות ואישורים לא נמחקים — וגם לא השלב שהם שייכים לו
    $q = pm_db()->prepare('SELECT COUNT(*) FROM quotes WHERE stage_id = ?');
    $q->execute([$id]);
    if ($q->fetchColumn()) throw new PmError('יש בשלב הצעות מחיר — אי אפשר למחוק אותו. אפשר לשנות לו שם או סדר', 422);
    $survey = pm_trade_id(PM_TRADE_SURVEYOR);
    if ($survey && (int)$s['trade_id'] === $survey) {
        $o = pm_db()->prepare('SELECT COUNT(*) FROM stages WHERE project_id = ? AND id != ? AND trade_id = ?');
        $o->execute([$s['project_id'], $id, $survey]);
        $a = pm_db()->prepare('SELECT COUNT(*) FROM stages WHERE project_id = ? AND trade_id = ?');
        $a->execute([$s['project_id'], pm_trade_id(PM_TRADE_ARCHITECT)]);
        if (!$o->fetchColumn() && $a->fetchColumn()) throw new PmError('יש בפרויקט אדריכלות — ולפניה תמיד מדידה של מודד מוסמך. אי אפשר למחוק את שלב המדידה', 422);
    }
    pm_db()->prepare('DELETE FROM stages WHERE id = ?')->execute([$id]);
    pm_event($user, (int)$s['project_id'], 'stage_removed', "הוסר שלב: {$s['name']}", ['stage' => $id]);
    return pm_project_get($user, (int)$s['project_id']);
}

/** סדר חדש: רשימת מזהי השלבים בסדר הרצוי. */
function pm_stages_order(array $user, int $projectId, array $ids): array
{
    pm_project_row($user, $projectId);
    $st = pm_db()->prepare('SELECT id FROM stages WHERE project_id = ?');
    $st->execute([$projectId]);
    $mine = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $ids = array_map('intval', $ids);
    sort($mine);
    $check = $ids;
    sort($check);
    if ($check !== $mine) throw new PmError('רשימת השלבים לא תואמת', 422);
    // מדידה לפני אדריכלות — לא מאפשרים להזיז את האדריכלות מעל המדידה
    $trade = pm_db()->prepare('SELECT id, trade_id FROM stages WHERE project_id = ?');
    $trade->execute([$projectId]);
    $tradeOf = array_column($trade->fetchAll(), 'trade_id', 'id');
    $survey = pm_trade_id(PM_TRADE_SURVEYOR);
    $arch = pm_trade_id(PM_TRADE_ARCHITECT);
    foreach ($ids as $sid) {
        $t = (int)($tradeOf[$sid] ?? 0);
        if ($t === $survey) break;
        if ($t === $arch && in_array($survey, array_map('intval', $tradeOf), true)) throw new PmError('מדידה (מודד מוסמך) תמיד לפני אדריכלות', 422);
    }
    $up = pm_db()->prepare('UPDATE stages SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $sid) $up->execute([($i + 1) * 10, $sid]);
    pm_db()->prepare('UPDATE projects SET last_activity_at = ? WHERE id = ?')->execute([pm_now(), $projectId]);
    return pm_project_get($user, $projectId);
}

// ---- ציר זמן וצוות ----

function pm_note(array $user, int $projectId, string $text): array
{
    pm_project_row($user, $projectId);
    $text = trim($text);
    if ($text === '') throw new PmError('ההערה ריקה', 422);
    if (mb_strlen($text) > 4000) throw new PmError('הערה: עד 4000 תווים', 422);
    pm_event($user, $projectId, 'note', $text);
    return pm_project_get($user, $projectId);
}

function pm_member(array $user, int $projectId, int $contactId, bool $add, string $role): array
{
    $p = pm_project_row($user, $projectId);
    $st = pm_db()->prepare('SELECT name, kind FROM contacts WHERE id = ? AND owner_id = ?');
    $st->execute([$contactId, $p['owner_id']]);
    $c = $st->fetch();
    if (!$c) throw new PmError('איש הקשר לא נמצא', 422);
    if ($add) {
        $role = mb_substr(trim($role), 0, 40);
        pm_db()->prepare('INSERT INTO project_members (project_id, contact_id, role) VALUES (?,?,?)
                          ON CONFLICT(project_id, contact_id) DO UPDATE SET role = excluded.role')
            ->execute([$projectId, $contactId, $role]);
        pm_event($user, $projectId, 'member_added', "הצטרף לצוות: {$c['name']}" . ($role ? " ($role)" : ''));
    } else {
        pm_db()->prepare('DELETE FROM project_members WHERE project_id = ? AND contact_id = ?')->execute([$projectId, $contactId]);
        pm_event($user, $projectId, 'member_removed', "הוסר מהצוות: {$c['name']}");
    }
    return pm_project_get($user, $projectId);
}

// ---- מודד מוסמך לפני אדריכלות ----

/**
 * רשימת שלבים [['name' => …, 'trade' => שם תחום], …] — ואם יש שלב אדריכלות בלי
 * שלב מדידה לפניו, נוסף אחד מיד לפניו.
 */
function pm_survey_first(array $stages): array
{
    foreach ($stages as $i => $s) {
        if (($s['trade'] ?? '') === PM_TRADE_SURVEYOR) return $stages;      // יש מדידה לפני
        if (($s['trade'] ?? '') === PM_TRADE_ARCHITECT) {
            array_splice($stages, $i, 0, [['name' => PM_SURVEY_STAGE, 'trade' => PM_TRADE_SURVEYOR]]);
            return $stages;
        }
    }
    return $stages;
}

function pm_trade_id(string $name): ?int
{
    $st = pm_db()->prepare('SELECT id FROM trades WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * הכלל בפרויקט: אם יש שלב אדריכלות שעוד לא התחיל, ואין שלב מדידה לפניו — נוסף
 * שלב מדידה מיד לפניו. מחזיר true אם נוסף.
 */
function pm_project_survey_first(array $user, int $projectId): bool
{
    $survey = pm_trade_id(PM_TRADE_SURVEYOR);
    $arch = pm_trade_id(PM_TRADE_ARCHITECT);
    if (!$survey || !$arch) return false;
    $st = pm_db()->prepare('SELECT id, sort, trade_id, status FROM stages WHERE project_id = ? ORDER BY sort, id');
    $st->execute([$projectId]);
    $rows = $st->fetchAll();
    foreach ($rows as $i => $r) {
        if ((int)$r['trade_id'] === $survey) return false;
        if ((int)$r['trade_id'] !== $arch) continue;
        if (!in_array($r['status'], ['planned', 'quotes'], true)) return false; // האדריכלות כבר בדרך — לא משנים בדיעבד
        $db = pm_db();
        $db->prepare('UPDATE stages SET sort = sort + 10 WHERE project_id = ? AND (sort > ? OR (sort = ? AND id >= ?))')
           ->execute([$projectId, $r['sort'], $r['sort'], $r['id']]);
        $now = pm_now();
        // שלב מדידה שכבר קיים אחרי האדריכלות — עובר לפניה; אחרת נוצר חדש
        $later = null;
        foreach (array_slice($rows, $i + 1) as $x) if ((int)$x['trade_id'] === $survey) { $later = $x; break; }
        if ($later) {
            $db->prepare('UPDATE stages SET sort = ?, updated_at = ? WHERE id = ?')->execute([(int)$r['sort'], $now, $later['id']]);
            pm_event($user, $projectId, 'stage_moved', 'שלב המדידה הוזז לפני האדריכלות — מודד מוסמך תמיד לפני אדריכלות', ['stage' => (int)$later['id']]);
            return true;
        }
        $db->prepare('INSERT INTO stages (project_id, sort, name, trade_id, created_at, updated_at) VALUES (?,?,?,?,?,?)')
           ->execute([$projectId, (int)$r['sort'], PM_SURVEY_STAGE, $survey, $now, $now]);
        pm_event($user, $projectId, 'stage_added', 'נוסף שלב: ' . PM_SURVEY_STAGE . ' (מודד מוסמך — תמיד לפני אדריכלות)',
            ['stage' => (int)$db->lastInsertId()]);
        return true;
    }
    return false;
}

/** פעם אחת בשרת: התבניות והפרויקטים הקיימים מקבלים את הכלל. */
function pm_survey_migrate(): void
{
    if (pm_setting('survey_rule') === '1') return;
    $db = pm_db();
    foreach ($db->query('SELECT id, stages_json FROM stage_templates')->fetchAll() as $t) {
        $stages = json_decode($t['stages_json'], true) ?: [];
        $fixed = pm_survey_first($stages);
        if ($fixed !== $stages) {
            $db->prepare('UPDATE stage_templates SET stages_json = ? WHERE id = ?')->execute([json_encode($fixed, JSON_UNESCAPED_UNICODE), $t['id']]);
        }
    }
    $system = ['id' => 0, 'display_name' => 'מערכת'];
    foreach ($db->query("SELECT id FROM projects WHERE status IN ('active','paused')")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        pm_project_survey_first($system, (int)$pid);
    }
    pm_setting_set('survey_rule', '1');
    pm_log('info', 'data', 'migrate.survey_rule');
}

// ---- תבניות שלבים (המפתח עורך) ----

function pm_templates(): array
{
    $rows = pm_db()->query('SELECT id, name, stages_json FROM stage_templates ORDER BY sort, name')->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'stages' => json_decode($r['stages_json'], true) ?: []], $rows);
}

function pm_template_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $name = pm_str($in, 'name', 60, true, 'שם התבנית');
    $stages = [];
    foreach (is_array($in['stages'] ?? null) ? $in['stages'] : [] as $s) {
        $n = is_array($s) ? trim((string)($s['name'] ?? '')) : '';
        if ($n === '') continue;
        if (mb_strlen($n) > 80) throw new PmError('שם שלב: עד 80 תווים', 422);
        $stages[] = ['name' => $n, 'trade' => mb_substr(trim((string)($s['trade'] ?? '')), 0, 40)];
    }
    if (!$stages) throw new PmError('תבנית צריכה לפחות שלב אחד', 422);
    $stages = pm_survey_first($stages);
    if (count($stages) > 60) throw new PmError('עד 60 שלבים בתבנית', 422);
    $json = json_encode($stages, JSON_UNESCAPED_UNICODE);
    $db = pm_db();
    $dup = $db->prepare('SELECT 1 FROM stage_templates WHERE name = ? AND id != ?');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) throw new PmError('תבנית בשם הזה כבר קיימת', 409);
    if ($id) {
        $db->prepare('UPDATE stage_templates SET name = ?, stages_json = ? WHERE id = ?')->execute([$name, $json, $id]);
    } else {
        $sort = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM stage_templates')->fetchColumn();
        $db->prepare('INSERT INTO stage_templates (name, stages_json, sort) VALUES (?,?,?)')->execute([$name, $json, $sort]);
    }
    pm_audit("user:{$user['id']}", 'template.save', 'template', $id ?: (int)$db->lastInsertId(), ['name' => $name]);
    return pm_templates();
}

function pm_template_delete(array $user, int $id): array
{
    pm_db()->prepare('DELETE FROM stage_templates WHERE id = ?')->execute([$id]);
    pm_audit("user:{$user['id']}", 'template.delete', 'template', $id);
    return pm_templates();
}
