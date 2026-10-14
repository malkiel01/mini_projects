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
require_once __DIR__ . '/dev.php'; // סמכות מפתח (pm_dev_mode)
require_once __DIR__ . '/units.php'; // מתחם ויחידות
require_once __DIR__ . '/engage.php'; // התקשרות: ספק × מקצוע
require_once __DIR__ . '/links.php';  // שיוך דו-צדדי: ספק ← מקצוע ושלב ← ספק, והסרת שיוך

// התוויות בעברית — מתורגמות בתצוגה (pm_t_map ב-api.php, t() בדפדפן)
// i18n-ok-start
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
// i18n-ok-end

function pm_date(array $in, string $key, string $label): string
{
    $v = pm_str($in, $key, 10, false, $label);
    if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))) throw new PmError('{label}: תאריך לא תקין', 422, ['label' => $label]);
    return $v;
}

/** פרויקט שהמשתמש רשאי לראות (שלו, או ששותף איתו כשותף מנהל) — או 404. */
function pm_project_row(array $user, int $id): array
{
    [$where, $args] = pm_project_clause($user, 'p');
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
    // של בעל הפרויקט — או של מי שבצוות שלו (שותף מנהל)
    $st = pm_db()->prepare('SELECT owner_id FROM contacts WHERE id = ? AND kind = ?');
    $st->execute([$id, $kind]);
    $o = $st->fetchColumn();
    if ($o === false || !pm_contact_in_team((int)$o, $ownerId)) throw new PmError('{label} לא נמצא', 422, ['label' => $label]);
    return $id;
}

/** רישום אירוע בציר הזמן, ועדכון "הפעילות האחרונה" של הפרויקט. */
/**
 * שורה בציר הזמן. $text — הטקסט העברי עם {משתנים}, ו-$vars — הערכים: נשמרת העברית
 * המלאה, ולצידה התבנית — כדי שכל אחד יקרא את השורה בשפה שלו (pm_event_text).
 */
function pm_event(array $user, int $projectId, string $kind, string $text, array $data = [], array $vars = []): void
{
    $now = pm_now();
    $full = pm_fill($text, $vars);
    $i18n = json_encode(['t' => $text, 'v' => $vars], JSON_UNESCAPED_UNICODE);
    pm_db()->prepare('INSERT INTO events (project_id, at, actor_id, actor_name, kind, text, data_json) VALUES (?,?,?,?,?,?,?)')
        ->execute([$projectId, $now, $user['id'], $user['display_name'], $kind, $full,
                   json_encode($data + ['i18n' => ['t' => $text, 'v' => $vars]], JSON_UNESCAPED_UNICODE)]);
    pm_db()->prepare('UPDATE projects SET last_activity_at = ?, last_event = ?, last_event_i18n = ? WHERE id = ?')
        ->execute([$now, mb_substr($full, 0, 140), $i18n, $projectId]);
    // מהפורטל: $user['actor'] = "portal:<קישור>" — לא משתמש של האפליקציה
    pm_audit($user['actor'] ?? "user:{$user['id']}", "project.$kind", 'project', $projectId, $data + ['text' => $full]);
}

/** הטקסט של אירוע בשפת הקורא. $json — data_json של האירוע, או last_event_i18n. */
function pm_event_text(string $text, ?string $json, ?string $lang = null): string
{
    $d = $json ? json_decode($json, true) : null;
    $x = $d['i18n'] ?? $d;
    if (!is_array($x) || !isset($x['t'])) return pm_t($text, [], $lang);
    return pm_t((string)$x['t'], is_array($x['v'] ?? null) ? $x['v'] : [], $lang);
}

// ---- רשימה ----

/** $filter: open (פעיל + מושהה) · done · archived · all */
function pm_projects_list(array $user, string $q, string $filter): array
{
    [$where, $args] = pm_project_clause($user, 'p');
    $sql = "SELECT p.id, p.owner_id, p.name, p.address, p.kind, p.status, p.last_activity_at, p.last_event, p.last_event_i18n,
                   c.name AS client_name, m.display_name AS owner_name,
                   EXISTS (SELECT 1 FROM project_partners x WHERE x.project_id = p.id) AS shared,
                   (SELECT COUNT(*) FROM units u WHERE u.project_id = p.id) AS units,
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
            'id' => (int)$r['id'], 'owner_id' => (int)$r['owner_id'], 'owner_name' => $r['owner_name'], 'shared' => (bool)$r['shared'], 'units' => (int)$r['units'],
            'name' => $r['name'], 'address' => $r['address'], 'kind' => $r['kind'], 'status' => $r['status'],
            'client_name' => $r['client_name'], 'last_activity_at' => $r['last_activity_at'], 'last_event' => pm_event_text($r['last_event'], $r['last_event_i18n']),
            'stages_total' => (int)$r['stages_total'], 'stages_done' => (int)$r['stages_done'], 'current_stage' => $cur,
        ];
    }, $st->fetchAll());
}

// ---- פרויקט אחד ----

function pm_project_get(array $user, int $id): array
{
    $p = pm_project_row($user, $id);
    $db = pm_db();
    // התקשרות (lib/engage.php): אבני דרך בלי שלב — לשלב היחיד של הספק; שלב בלי ספק שספק אחד עובד בו — שלו
    pm_payments_auto_stage($id);
    $engaged = pm_stage_sync_suppliers($id);

    $st = $db->prepare(
        'SELECT s.*, t.name AS trade_name, c.name AS supplier_name, c.phone AS supplier_phone, un.name AS unit_name,
                (SELECT COUNT(*) FROM quotes q WHERE q.stage_id = s.id) AS quotes, ' . PM_STAGE_USED . ' AS used
           FROM stages s LEFT JOIN trades t ON t.id = s.trade_id LEFT JOIN contacts c ON c.id = s.supplier_id
                LEFT JOIN units un ON un.id = s.unit_id
          WHERE s.project_id = ? ORDER BY ' . PM_STAGE_ORDER
    );
    $st->execute([$id]);
    $stages = array_map(fn($s) => [
        'id' => (int)$s['id'], 'sort' => (int)$s['sort'], 'name' => $s['name'],
        'trade_id' => $s['trade_id'] !== null ? (int)$s['trade_id'] : null, 'trade_name' => $s['trade_name'],
        'supplier_id' => $s['supplier_id'] !== null ? (int)$s['supplier_id'] : null,
        'supplier_name' => $s['supplier_name'], 'supplier_phone' => $s['supplier_phone'],
        'status' => $s['status'], 'needs_lawyer' => (bool)$s['needs_lawyer'],
        'plan_start' => $s['plan_start'], 'plan_end' => $s['plan_end'], 'done_at' => $s['done_at'],
        'progress' => pm_stage_progress($s), 'budget' => (int)$s['budget'] / 100, 'quotes' => (int)$s['quotes'], 'used' => (int)$s['used'],
        'unit_id' => $s['unit_id'] !== null ? (int)$s['unit_id'] : null, 'unit_name' => $s['unit_name'],
        // מי עובד בשלב בפועל — מהצעות, אבני דרך ותוספות (גם כשלא נבחר ספק בחלון השלב)
        'engaged' => $engaged[(int)$s['id']] ?? [],
    ], $st->fetchAll());

    $ev = $db->prepare('SELECT id, at, actor_id, actor_name, kind, text, data_json FROM events WHERE project_id = ? ORDER BY id DESC LIMIT 300');
    $ev->execute([$id]);
    $events = array_reverse(array_map(fn($e) => ['id' => (int)$e['id'], 'at' => $e['at'], 'actor_id' => (int)$e['actor_id'],
        'actor_name' => pm_t($e['actor_name']), 'kind' => $e['kind'], 'text' => pm_event_text($e['text'], $e['data_json'])], $ev->fetchAll()));

    return [
        'project' => [
            'id' => (int)$p['id'], 'owner_id' => (int)$p['owner_id'], 'name' => $p['name'], 'address' => $p['address'],
            'kind' => $p['kind'], 'status' => $p['status'], 'budget' => (int)$p['budget'],
            'client_id' => $p['client_id'] !== null ? (int)$p['client_id'] : null,
            'lawyer_id' => $p['lawyer_id'] !== null ? (int)$p['lawyer_id'] : null,
            'start_date' => $p['start_date'], 'due_date' => $p['due_date'], 'notes' => $p['notes'],
            'created_at' => $p['created_at'], 'last_activity_at' => $p['last_activity_at'],
            'portal_shared' => (bool)($p['portal_shared'] ?? 1),
        ],
        'stages' => $stages,
        'units'  => pm_units_of($id),
        // הגדרות הכספים (אגף הכספים) — לחלונות: עיכבון, הצמדה
        'finance' => pm_finance_settings($p),
        // שותף מנהל: הבעלים והשותפים; is_owner — רק הבעלים מצרף ומסיר
        'partners' => pm_partners_of($p), 'is_owner' => (int)$p['owner_id'] === (int)$user['id'],
        'team'   => pm_project_team($p, $stages),
        'events' => $events,
    ];
}

/** הצוות: הלקוח, העו"ד, ספקי השלבים ואנשי צוות נוספים — כל אחד פעם אחת. */
/**
 * תפקידים בצוות — לפי סוג איש הקשר. ספק — גם התחומים שלו (trade:<id>). key => תווית (מתורגמת בדפדפן).
 */
// i18n-ok-start
const PM_MEMBER_ROLES = [
    'client'   => ['co_client' => 'לקוח נוסף', 'spouse' => 'בן / בת זוג', 'family' => 'בן משפחה', 'client_rep' => 'נציג הלקוח'],
    'lawyer'   => ['co_lawyer' => 'עו"ד נוסף'],
    'supplier' => ['supervisor' => 'מפקח', 'architect' => 'אדריכל', 'engineer' => 'מהנדס', 'designer' => 'מעצב פנים',
                   'consultant' => 'יועץ', 'surveyor' => 'מודד', 'foreman' => 'מנהל עבודה'],
];
// i18n-ok-end

/** התווית של תפקיד שמור — או ברירת המחדל לסוג איש הקשר. */
function pm_member_role_label(string $key, string $kind): string
{
    if (preg_match('/^trade:(\d+)$/', $key, $m)) {
        $st = pm_db()->prepare('SELECT name FROM trades WHERE id = ?');
        $st->execute([(int)$m[1]]);
        if ($n = $st->fetchColumn()) return (string)$n;
    }
    return PM_MEMBER_ROLES[$kind][$key] ?? (['client' => 'לקוח נוסף', 'lawyer' => 'עו"ד נוסף'][$kind] ?? 'צוות'); // i18n-ok
}

function pm_project_team(array $p, array $stages): array
{
    $db = pm_db();
    $team = [];
    $add = function (?int $id, string $role, bool $removable = false) use (&$team, $db) {
        if (!$id) return;
        if (isset($team[$id])) { $team[$id]['roles'][] = $role; return; }
        $st = $db->prepare('SELECT id, kind, name, company, phone, whatsapp, email, lang FROM contacts WHERE id = ?');
        $st->execute([$id]);
        if ($c = $st->fetch()) $team[$id] = ['id' => (int)$c['id']] + $c + ['roles' => [$role], 'removable' => $removable];
    };
    // התפקידים בעברית — הדפדפן משווה אליהם (roles.includes('לקוח')) ומתרגם בתצוגה
    $add($p['client_id'] !== null ? (int)$p['client_id'] : null, 'לקוח'); // i18n-ok
    $add($p['lawyer_id'] !== null ? (int)$p['lawyer_id'] : null, 'עו"ד'); // i18n-ok
    // מתחם: לקוח / עו"ד של יחידה — גם בצוות (לקישור האישי שלהם)
    foreach (pm_units_of((int)$p['id']) as $u) {
        if ($u['client_id'] && $u['client_id'] !== (int)$p['client_id']) { $add($u['client_id'], 'לקוח'); $team[$u['client_id']]['roles'][] = '🏢 ' . $u['name']; } // i18n-ok
        if ($u['lawyer_id'] && $u['lawyer_id'] !== (int)$p['lawyer_id']) { $add($u['lawyer_id'], 'עו"ד'); $team[$u['lawyer_id']]['roles'][] = '🏢 ' . $u['name']; } // i18n-ok
    }
    foreach ($stages as $s) if ($s['supplier_id']) $add($s['supplier_id'], $s['name']);
    $m = $db->prepare('SELECT m.contact_id, m.role, m.role_key, m.note, c.kind FROM project_members m JOIN contacts c ON c.id = m.contact_id WHERE m.project_id = ?');
    $m->execute([$p['id']]);
    foreach ($m->fetchAll() as $r) {
        $id = (int)$r['contact_id'];
        // תפקיד מהרשימה (לקוח / עו"ד נוסף — עם קישור אישי משלו, lib/portal.php: pm_portal_co); הערה — רק לנו.
        // role — טקסט חופשי מלפני הרשימה: מוצג כהערה
        $role = pm_member_role_label((string)$r['role_key'], $r['kind']);
        $note = trim((string)$r['note']) !== '' ? (string)$r['note'] : (string)$r['role'];
        if (isset($team[$id])) { $team[$id]['removable'] = true; if ($r['role_key'] !== '' || !in_array($r['kind'], ['client', 'lawyer'], true)) $team[$id]['roles'][] = $role; }
        else $add($id, $role, true);
        if (isset($team[$id])) $team[$id] += ['member' => ['role_key' => (string)$r['role_key'], 'note' => $note]];
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
    $clientId = pm_owned_contact((int)($in['client_id'] ?? 0) ?: null, $ownerId, 'client', 'הלקוח'); // i18n-ok
    $lawyerId = pm_owned_contact((int)($in['lawyer_id'] ?? 0) ?: null, $ownerId, 'lawyer', 'עורך הדין'); // i18n-ok
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
        'start_date' => pm_date($in, 'start_date', 'תאריך התחלה'), // i18n-ok
        'due_date'   => pm_date($in, 'due_date', 'יעד מסירה'), // i18n-ok
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
            // התקציב בפתיחה — "תקציב ראשוני" (אגף הכספים: ראשוני מול היום)
            $db->prepare("INSERT INTO projects (owner_id, $cols, budget_initial, created_at, updated_at, last_activity_at) VALUES (?, $qs, ?, ?, ?, ?)")
               ->execute(array_merge([$ownerId], array_values($f), [$f['budget'], $now, $now, $now]));
            $id = (int)$db->lastInsertId();
            $count = pm_apply_template($id, (int)($in['template_id'] ?? 0));
            if ($count && pm_project_survey_first($user, $id)) $count++;
            if ($count) pm_event($user, $id, 'created', 'הפרויקט נפתח עם {n} שלבים', [], ['n' => $count]);
            else pm_event($user, $id, 'created', 'הפרויקט נפתח');
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
        pm_event($user, $id, 'status', 'סטטוס הפרויקט: {status}', ['status' => $status], ['status' => PM_PROJECT_STATUSES[$status]]);
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
        'supplier_id'  => pm_owned_contact((int)($in['supplier_id'] ?? 0) ?: null, (int)$p['owner_id'], 'supplier', 'הספק'), // i18n-ok
        'status'       => $status,
        'needs_lawyer' => !empty($in['needs_lawyer']) ? 1 : 0,
        'plan_start'   => pm_date($in, 'plan_start', 'התחלה מתוכננת'), // i18n-ok
        'plan_end'     => pm_date($in, 'plan_end', 'סיום מתוכנן'), // i18n-ok
        // אגף הכספים: התקדמות (אחוז; "בוצע" = 100) ותקציב לשלב (אגורות)
        'progress'     => max(0, min(100, (int)($in['progress'] ?? ($old['progress'] ?? 0)))),
        'budget'       => pm_stage_budget($in, $old),
        // מתחם: יחידה (NULL — משותף). בלי השדה — נשאר
        'unit_id'      => array_key_exists('unit_id', $in) ? pm_unit_of_project((int)($in['unit_id'] ?? 0) ?: null, $projectId) : ($old['unit_id'] ?? null),
    ];
    if (in_array($status, ['done', 'paid'], true)) $f['progress'] = 100;
    // "בוצע" — רק דרך דיווח מהשטח עם תמונה (lib/reports.php)
    if (in_array($status, ['done', 'paid'], true) && !in_array($old['status'] ?? 'planned', ['done', 'paid'], true) && !pm_dev_mode($user)) {
        throw new PmError('כדי לסמן שלב "בוצע" — "דיווח מהשטח" עם לפחות תמונה אחת', 409);
    }
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
                pm_event($user, $projectId, 'stage_status', '{stage}: {status}', ['stage' => $id, 'status' => $status],
                    ['stage' => $f['name'], 'status' => PM_STAGE_STATUSES[$status]]);
            }
            if ((int)$old['progress'] !== $f['progress'] && !in_array($status, ['done', 'paid'], true)) {
                pm_event($user, $projectId, 'stage_progress', '{stage}: התקדמות {n}%', ['stage' => $id], ['stage' => $f['name'], 'n' => $f['progress']]);
            }
            // שלב שעבר יחידה — גם שחרור העיכבון שלו עובר (משתחרר במסירה של היחידה החדשה)
            if (($old['unit_id'] ?? null) != $f['unit_id']) $db->prepare("UPDATE payments SET unit_id = ? WHERE stage_id = ? AND kind = 'retention'")->execute([$f['unit_id'], $id]);
            if ((int)$old['supplier_id'] !== (int)$f['supplier_id'] && $f['supplier_id']) {
                pm_event($user, $projectId, 'stage_supplier', '{stage}: שויך {supplier}', ['stage' => $id],
                    ['stage' => $f['name'], 'supplier' => pm_contact_name($f['supplier_id'])]);
            }
        } else {
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) + 10 FROM stages WHERE project_id = $projectId")->fetchColumn();
            $cols = implode(', ', array_keys($f));
            $qs = implode(', ', array_fill(0, count($f), '?'));
            $db->prepare("INSERT INTO stages (project_id, sort, $cols, done_at, created_at, updated_at) VALUES (?, ?, $qs, ?, ?, ?)")
               ->execute(array_merge([$projectId, $sort], array_values($f), [$doneAt, $now, $now]));
            $newId = (int)$db->lastInsertId();
            // "+ שלב כאן" במצב עריכה: after = מזהה השלב שלפניו (0 — בראש הרשימה)
            if (array_key_exists('after', $in) && $in['after'] !== null && $in['after'] !== '') pm_stage_place($projectId, $newId, (int)$in['after']);
            pm_event($user, $projectId, 'stage_added', 'נוסף שלב: {stage}', ['stage' => $newId], ['stage' => $f['name']]);
        }
        $surveyAdded = pm_project_survey_first($user, $projectId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_project_get($user, $projectId) + ($surveyAdded ? ['notice' => pm_t('מודד מוסמך תמיד לפני אדריכלות — שלב המדידה סודר לפני האדריכלות')] : []);
}

/** סדר השלבים בתצוגה: קודם המשותפים, ואז לפי היחידות (מתחם); בתוך כל קבוצה — sort. alias s, LEFT JOIN units un. */
const PM_STAGE_ORDER = '(s.unit_id IS NOT NULL), COALESCE(un.sort, 0), COALESCE(un.id, 0), s.sort, s.id';

/** שלב חדש — אחרי שלב נתון (0 = ראשון); מספור מחדש של הסדר. */
function pm_stage_place(int $projectId, int $id, int $after): void
{
    $db = pm_db();
    $st = $db->prepare('SELECT s.id FROM stages s LEFT JOIN units un ON un.id = s.unit_id WHERE s.project_id = ? AND s.id != ? ORDER BY ' . PM_STAGE_ORDER);
    $st->execute([$projectId, $id]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $at = $after ? array_search($after, $ids, true) : -1;
    if ($at === false) throw new PmError('השלב לא נמצא', 404);
    array_splice($ids, $at + 1, 0, [$id]);
    $up = $db->prepare('UPDATE stages SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $sid) $up->execute([($i + 1) * 10, $sid]);
}

/** תקציב לשלב — ₪ מהטופס, באגורות במסד. בלי שדה — נשאר מה שהיה. */
function pm_stage_budget(array $in, ?array $old): int
{
    if (!array_key_exists('budget', $in)) return (int)($old['budget'] ?? 0);
    $v = $in['budget'];
    if ($v === '' || $v === null) return 0;
    if (!is_numeric($v) || $v < 0 || $v > 1e9) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'תקציב לשלב']);
    return (int)round((float)$v * 100);
}

function pm_contact_name(int $id): string
{
    $st = pm_db()->prepare('SELECT name FROM contacts WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

/**
 * כמה דברים רשומים על השלב (s.id): הצעות, תשלומים, תוספות, דיווחים והוצאות. שלב "ריק" (0) — אפשר
 * למחוק; אחרת רק בסמכות מפתח (מחיקה גמורה, lib/dev.php).
 */
const PM_STAGE_USED = '((SELECT COUNT(*) FROM quotes x WHERE x.stage_id = s.id) + (SELECT COUNT(*) FROM payments x WHERE x.stage_id = s.id)
    + (SELECT COUNT(*) FROM change_orders x WHERE x.stage_id = s.id) + (SELECT COUNT(*) FROM reports x WHERE x.stage_id = s.id AND x.deleted_at IS NULL)
    + (SELECT COUNT(*) FROM expenses x WHERE x.stage_id = s.id AND x.deleted_at IS NULL))';

function pm_stage_delete(array $user, int $id): array
{
    $s = pm_stage_row($user, $id);
    // רק שלב ריק — בלי הצעות, תשלומים, תוספות, דיווחים או הוצאות
    $q = pm_db()->prepare('SELECT ' . PM_STAGE_USED . ' FROM stages s WHERE s.id = ?');
    $q->execute([$id]);
    if ($q->fetchColumn()) throw new PmError('השלב לא ריק — יש בו הצעות, תשלומים או דיווחים. אפשר לשנות לו שם או סדר', 422);
    $survey = pm_trade_id(PM_TRADE_SURVEYOR);
    if ($survey && (int)$s['trade_id'] === $survey) {
        $o = pm_db()->prepare('SELECT COUNT(*) FROM stages WHERE project_id = ? AND id != ? AND trade_id = ?');
        $o->execute([$s['project_id'], $id, $survey]);
        $a = pm_db()->prepare('SELECT COUNT(*) FROM stages WHERE project_id = ? AND trade_id = ?');
        $a->execute([$s['project_id'], pm_trade_id(PM_TRADE_ARCHITECT)]);
        if (!$o->fetchColumn() && $a->fetchColumn()) throw new PmError('יש בפרויקט אדריכלות — ולפניה תמיד מדידה של מודד מוסמך. אי אפשר למחוק את שלב המדידה', 422);
    }
    pm_db()->prepare('DELETE FROM stages WHERE id = ?')->execute([$id]);
    pm_event($user, (int)$s['project_id'], 'stage_removed', 'הוסר שלב: {stage}', ['stage' => $id], ['stage' => $s['name']]);
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

function pm_member(array $user, int $projectId, int $contactId, bool $add, string $roleKey, string $note = ''): array
{
    $p = pm_project_row($user, $projectId);
    $st = pm_db()->prepare('SELECT name, kind, owner_id FROM contacts WHERE id = ?');
    $st->execute([$contactId]);
    $c = $st->fetch();
    if (!$c || !pm_contact_in_team((int)$c['owner_id'], (int)$p['owner_id'])) throw new PmError('איש הקשר לא נמצא', 422);
    if ($add) {
        // התפקיד — מהרשימה של סוג איש הקשר (או תחום של הספק); הערה — טקסט חופשי לתצוגה אצלנו
        $roleKey = trim($roleKey);
        $ok = $roleKey === '' || isset(PM_MEMBER_ROLES[$c['kind']][$roleKey])
            || ($c['kind'] === 'supplier' && preg_match('/^trade:(\d+)$/', $roleKey, $m) && pm_db()->query('SELECT 1 FROM trades WHERE id = ' . (int)$m[1])->fetchColumn());
        if (!$ok) throw new PmError('תפקיד לא מוכר', 422);
        $note = mb_substr(trim($note), 0, 100);
        $was = pm_db()->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND contact_id = ?');
        $was->execute([$projectId, $contactId]);
        $existed = (bool)$was->fetchColumn();
        pm_db()->prepare("INSERT INTO project_members (project_id, contact_id, role, role_key, note) VALUES (?,?,'',?,?)
                          ON CONFLICT(project_id, contact_id) DO UPDATE SET role = '', role_key = excluded.role_key, note = excluded.note")
            ->execute([$projectId, $contactId, $roleKey, $note]);
        $label = pm_member_role_label($roleKey, $c['kind']);
        pm_event($user, $projectId, 'member_added', $existed ? 'עודכן בצוות: {name} ({role})' : 'הצטרף לצוות: {name} ({role})', [], ['name' => $c['name'], 'role' => $label]);
    } else {
        pm_db()->prepare('DELETE FROM project_members WHERE project_id = ? AND contact_id = ?')->execute([$projectId, $contactId]);
        pm_event($user, $projectId, 'member_removed', 'הוסר מהצוות: {name}', [], ['name' => $c['name']]);
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
        pm_event($user, $projectId, 'stage_added', 'נוסף שלב: {stage} (מודד מוסמך — תמיד לפני אדריכלות)',
            ['stage' => (int)$db->lastInsertId()], ['stage' => PM_SURVEY_STAGE]);
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
    $system = ['id' => 0, 'display_name' => 'מערכת']; // i18n-ok — actor_name, מתורגם בקריאה

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

// ================= שותף מנהל =================

/** הבעלים והשותפים של פרויקט — לחלונית "צוות". */
function pm_partners_of(array $p): array
{
    $o = pm_db()->prepare('SELECT id, display_name, username FROM users WHERE id = ?');
    $o->execute([$p['owner_id']]);
    $st = pm_db()->prepare('SELECT u.id, u.display_name, u.username, x.created_at FROM project_partners x JOIN users u ON u.id = x.user_id WHERE x.project_id = ? ORDER BY x.created_at');
    $st->execute([$p['id']]);
    $out = [];
    if ($u = $o->fetch()) $out[] = ['id' => (int)$u['id'], 'name' => $u['display_name'], 'username' => $u['username'], 'owner' => true, 'since' => null];
    foreach ($st->fetchAll() as $u) $out[] = ['id' => (int)$u['id'], 'name' => $u['display_name'], 'username' => $u['username'], 'owner' => false, 'since' => $u['created_at']];
    return $out;
}

/**
 * צירוף או הסרה של שותף מנהל: {project_id, do: add|remove, login (שם משתמש או אימייל) | user_id}.
 * מצרף ומסיר — רק הבעלים. שותף יכול להסיר את עצמו (עזיבה).
 */
function pm_partner_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $owner = (int)$p['owner_id'] === (int)$user['id'];
    $db = pm_db();
    if (($in['do'] ?? '') === 'remove') {
        $uid = (int)($in['user_id'] ?? 0);
        if (!$owner && $uid !== (int)$user['id']) throw new PmError('רק בעל הפרויקט מסיר שותפים', 403);
        $u = $db->prepare('SELECT u.display_name FROM project_partners x JOIN users u ON u.id = x.user_id WHERE x.project_id = ? AND x.user_id = ?');
        $u->execute([$projectId, $uid]);
        $name = $u->fetchColumn();
        if ($name === false) throw new PmError('השותף לא נמצא', 404);
        $db->prepare('DELETE FROM project_partners WHERE project_id = ? AND user_id = ?')->execute([$projectId, $uid]);
        pm_team_reset();
        pm_event($user, $projectId, 'partner', $uid === (int)$user['id'] ? '{name} עזב את הפרויקט (שותף מנהל)' : 'הוסר שותף מנהל: {name}', ['user' => $uid], ['name' => $name]);
        // מי שעזב — כבר לא רואה את הפרויקט
        return $uid === (int)$user['id'] ? ['left' => true] : pm_project_get($user, $projectId);
    }
    if (!$owner) throw new PmError('רק בעל הפרויקט מצרף שותפים', 403);
    $login = trim((string)($in['login'] ?? ''));
    if ($login === '') throw new PmError('שם משתמש או אימייל: שדה חובה', 422);
    $u = $db->prepare("SELECT id, display_name, email, lang, username, role, status FROM users WHERE (username = ? COLLATE NOCASE OR email = ? COLLATE NOCASE)");
    $u->execute([$login, $login]);
    $partner = $u->fetch();
    // משתמש פעיל באפליקציה בלבד — לא איש קשר ולא חשבון שממתין לאישור
    if (!$partner || $partner['status'] !== 'active') throw new PmError('אין משתמש פעיל כזה באפליקציה', 404);
    if ((int)$partner['id'] === (int)$p['owner_id']) throw new PmError('זה בעל הפרויקט', 422);
    $db->prepare('INSERT OR IGNORE INTO project_partners (project_id, user_id, added_by, created_at) VALUES (?,?,?,?)')
       ->execute([$projectId, $partner['id'], $user['id'], pm_now()]);
    pm_team_reset();
    pm_event($user, $projectId, 'partner', 'צורף שותף מנהל: {name}', ['user' => (int)$partner['id']], ['name' => $partner['display_name']]);
    // מייל לשותף — בשפה שלו
    $to = pm_user_email($partner);
    if ($to !== '') {
        $lang = pm_user_lang($partner);
        $ok = pm_send_mail($to, pm_t('{name} שיתף איתך פרויקט: {project}', ['name' => $user['display_name'], 'project' => $p['name']], $lang),
            pm_t("{name} צירף אותך כשותף מנהל בפרויקט {project}.\nמעכשיו הוא מופיע ברשימת הפרויקטים שלך — עם כל מה שבו.\n\n{url}\n", [
                'name' => $user['display_name'], 'project' => $p['name'], 'url' => pm_app_url() . "/#/p/$projectId",
            ], $lang), $lang);
        pm_log($ok ? 'info' : 'warn', 'mail', 'mail.partner', ['data' => ['to' => $to, 'project' => $projectId, 'sent' => $ok]]);
    }
    return pm_project_get($user, $projectId);
}
