<?php
declare(strict_types=1);

/**
 * פרוסה 11 — תפקידים גמישים ומשימות (אפיון 01 "משתמשים ותפקידים", א8).
 * נטען מסוף lib.php. לא נקודת קצה: גישה ישירה → 404.
 *
 * תפקיד = שם + הרשאות (רשימה סגורה, ROLE_PERMS) + סוגי משימות, לכל ארגון (טבלת roles, מיגרציה v5).
 * לכל ארגון שלושה מובנים (builtin, base = admin/office/field) — אותן הרשאות כמו PERMS לפני הפרוסה, כך ששום חבר קיים לא מאבד או
 * מרוויח הרשאה. members.role_id — התפקיד; members.role נשאר עמודת תאימות (admin/office/field) ומחושב מההרשאות (roleBase()),
 * כי קוד קיים (אסימונים, OAuth, "המנהל האחרון") קורא אותה. ההרשאה עצמה — רק can() (lib/access.php) דרך ctxPerms().
 *
 * משימה = בדיקה או פעולה בתיק שהוקצתה לאדם או לתפקיד (טבלת tasks). "המשימות שלי" = משימות שהוקצו לי או לתפקיד שלי,
 * ועוד הבדיקות שהתפקיד שלי אחראי עליהן (check_defs.role) ועדיין לא הושלמו — מחושבות, לא נשמרות.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** ההרשאות שמנהל ארגון מסמן לתפקיד — רשימה סגורה (אפיון 01). */
const ROLE_PERMS = [
    'case.view'          => 'צפייה בכל התיקים',
    'case.view_assigned' => 'צפייה רק בתיקים שהוקצו לי',
    'case.track'         => 'עדכון בדיקות, הערות ומיקום קבר',
    'case.edit'          => 'עדכון פרטים ותשלומים, תיק חדש',
    'case.approve'       => 'אישור בדיקה',
    'files.upload'       => 'העלאת קבצים',
    'case.import'        => 'ייבוא תיקים',
    'case.export'        => 'ייצוא תיקים',
    'tasks.manage'       => 'הקצאת משימות',
    'settings.manage'    => 'ניהול הגדרות (בדיקות, תפקידים, Dropbox, אסימונים)',
    'members.manage'     => 'ניהול משתמשים',
];
/** הרשאות פנימיות שנגזרות מהרשאה ברשימה. */
const PERM_FROM = ['dropbox.manage' => 'settings.manage', 'token.manage' => 'settings.manage'];
/** התפקידים המובנים — בדיוק PERMS שלפני הפרוסה, ועוד case.approve לכולם (שטח ומשרד אישרו בדיקה גם קודם). */
const ROLE_BUILTIN = [
    'admin'  => ['name' => 'מנהל', 'perms' => ['case.view', 'case.track', 'case.edit', 'case.approve', 'files.upload', 'case.import', 'case.export',
        'tasks.manage', 'settings.manage', 'members.manage']],
    'office' => ['name' => 'משרד', 'perms' => ['case.view', 'case.track', 'case.edit', 'case.approve', 'files.upload', 'case.import', 'case.export']],
    'field'  => ['name' => 'שטח', 'perms' => ['case.view', 'case.track', 'case.approve']],
];
const TASK_STATUSES = ['open' => 'פתוחה', 'done' => 'בוצעה', 'cancelled' => 'בוטלה'];

final class RoleError extends RuntimeException {}

/** members.role (תאימות) לפי ההרשאות: ניהול משתמשים → admin; עדכון פרטים → office; אחרת field (הכי מוגבל). */
function roleBase(array $perms): string {
    return in_array('members.manage', $perms, true) ? 'admin' : (in_array('case.edit', $perms, true) ? 'office' : 'field');
}

/** שלושת התפקידים המובנים לארגון, אם חסרים. נקרא מהמיגרציה, ביצירת ארגון, ולפני כל קריאה של תפקידים. */
function rolesSeed(PDO $pdo, int $orgId): void {
    $now = gmdate('c');
    $st = $pdo->prepare('INSERT INTO roles(org_id, name, perms, task_types, base, builtin, archived, created_at, updated_at)
        SELECT ?, ?, ?, \'[]\', ?, 1, 0, ?, ? WHERE NOT EXISTS (SELECT 1 FROM roles WHERE org_id = ? AND builtin = 1 AND base = ?)');
    foreach (ROLE_BUILTIN as $base => $r) $st->execute([$orgId, $r['name'], json_encode($r['perms']), $base, $now, $now, $orgId, $base]);
    // חברים בלי role_id (ותיקים, או שנוספו בקוד ישן) → התפקיד המובנה לפי members.role
    $pdo->prepare('UPDATE members SET role_id = (SELECT r.id FROM roles r WHERE r.org_id = members.org_id AND r.builtin = 1 AND r.base = members.role)
        WHERE org_id = ? AND role_id IS NULL')->execute([$orgId]);
}

function roleOut(array $r, ?int $members = null): array {
    return ['id' => (int)$r['id'], 'name' => $r['name'], 'perms' => json_decode((string)$r['perms'], true) ?: [],
        'task_types' => json_decode((string)$r['task_types'], true) ?: [], 'builtin' => (int)$r['builtin'] === 1,
        'base' => $r['base'], 'archived' => (int)$r['archived'] === 1] + ($members === null ? [] : ['members' => $members]);
}

/** התפקידים של הארגון (כולל בארכיון — $all). */
function rolesOf(PDO $pdo, int $orgId, bool $all = false): array {
    rolesSeed($pdo, $orgId);
    $st = $pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM members m WHERE m.org_id = r.org_id AND m.role_id = r.id) AS n FROM roles r
        WHERE r.org_id = ?' . ($all ? '' : ' AND r.archived = 0') . ' ORDER BY r.builtin DESC, r.id');
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return array_map(fn($r) => roleOut($r, (int)$r['n']), $rows);
}

function roleGet(PDO $pdo, int $orgId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM roles WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $r ? roleOut($r) : null;
}

/** תפקיד לפי מזהה, או לפי בסיס ישן (admin/office/field) — לקלט של ממשק ישן (invite, member_role). */
function roleResolveInput(PDO $pdo, int $orgId, mixed $roleId, mixed $legacy): array {
    rolesSeed($pdo, $orgId);
    if ($roleId !== null && $roleId !== '') {
        $r = roleGet($pdo, $orgId, (int)$roleId);
        if ($r === null || $r['archived']) throw new RoleError('התפקיד לא נמצא', 404);
        return $r;
    }
    if (is_string($legacy) && isset(ROLE_BUILTIN[$legacy])) {
        $st = $pdo->prepare('SELECT id FROM roles WHERE org_id = ? AND builtin = 1 AND base = ?');
        $st->execute([$orgId, $legacy]);
        $id = $st->fetchColumn();
        $st->closeCursor();
        if ($id !== false) return roleGet($pdo, $orgId, (int)$id);
    }
    throw new RoleError('תפקיד לא תקין');
}

/** ההרשאות שנבחרו → רשימה נקייה מתוך ROLE_PERMS. הרשאה לא מוכרת → שגיאה (לא מתעלמים בשקט). */
function rolePermsClean(mixed $perms): array {
    if (!is_array($perms)) throw new RoleError('הרשאות: רשימה');
    $out = [];
    foreach ($perms as $p) {
        if (!is_string($p) || !isset(ROLE_PERMS[$p])) throw new RoleError('הרשאה לא מוכרת: ' . (is_scalar($p) ? $p : '?'));
        $out[] = $p;
    }
    return array_values(array_intersect(array_keys(ROLE_PERMS), $out));   // בסדר של הרשימה
}

/** מנהלים פעילים בארגון (members.role = admin) אחרי שינוי משוער. */
function roleAdminsLeft(PDO $pdo, int $orgId, ?int $roleId, string $newBase, ?int $uid = null, ?string $uidBase = null): int {
    $st = $pdo->prepare('SELECT m.user_id, m.role_id, m.role FROM members m JOIN users u ON u.id = m.user_id WHERE m.org_id = ? AND m.active = 1 AND u.active = 1');
    $st->execute([$orgId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $b = $m['role'];
        if ($roleId !== null && (int)$m['role_id'] === $roleId) $b = $newBase;
        if ($uid !== null && (int)$m['user_id'] === $uid) $b = $uidBase;
        if ($b === 'admin') $n++;
    }
    return $n;
}

/**
 * יצירה או שינוי של תפקיד. $in: id (ריק = חדש), name, perms[], task_types[]. מובנה — אפשר לשנות שם, הרשאות וסוגי משימות.
 * החברים בתפקיד מקבלים את ההרשאות החדשות מיד (can() קורא מהטבלה בכל בקשה). אסור להשאיר ארגון בלי מנהל משתמשים.
 */
function roleSave(PDO $pdo, int $orgId, array $in): array {
    rolesSeed($pdo, $orgId);
    $id = (int)($in['id'] ?? 0);
    $cur = $id ? roleGet($pdo, $orgId, $id) : null;
    if ($id && ($cur === null || $cur['archived'])) throw new RoleError('התפקיד לא נמצא', 404);
    $name = array_key_exists('name', $in) ? trim(preg_replace('/\s+/u', ' ', (string)$in['name'])) : (string)($cur['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 60) throw new RoleError('נדרש שם לתפקיד (עד 60 תווים)');
    foreach (rolesOf($pdo, $orgId) as $r) if ($r['id'] !== $id && $r['name'] === $name) throw new RoleError('יש כבר תפקיד בשם הזה');
    $perms = array_key_exists('perms', $in) ? rolePermsClean($in['perms']) : ($cur['perms'] ?? []);
    $types = $in['task_types'] ?? ($cur['task_types'] ?? []);
    if (!is_array($types) || count($types) > 30) throw new RoleError('סוגי משימות: רשימה של עד 30');
    $types = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)(is_scalar($x) ? $x : '')), 0, 60), $types), 'strlen')));
    $base = roleBase($perms);
    if ($cur !== null && roleBase($cur['perms']) === 'admin' && $base !== 'admin' && roleAdminsLeft($pdo, $orgId, $id, $base) < 1) {
        throw new RoleError('אחרי השינוי לא יישאר בארגון אף מנהל משתמשים. קודם לתת את ההרשאה לתפקיד אחר.');
    }
    $now = gmdate('c');
    if ($cur === null) {
        $pdo->prepare('INSERT INTO roles(org_id, name, perms, task_types, base, builtin, archived, created_at, updated_at) VALUES (?,?,?,?,NULL,0,0,?,?)')
            ->execute([$orgId, $name, json_encode($perms), json_encode($types, JSON_UNESCAPED_UNICODE), $now, $now]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE roles SET name = ?, perms = ?, task_types = ?, updated_at = ? WHERE id = ? AND org_id = ?')
            ->execute([$name, json_encode($perms), json_encode($types, JSON_UNESCAPED_UNICODE), $now, $id, $orgId]);
        $pdo->prepare('UPDATE members SET role = ? WHERE org_id = ? AND role_id = ?')->execute([$base, $orgId, $id]);
    }
    return roleGet($pdo, $orgId, $id);
}

/** תפקיד לארכיון — רק כשאין בו חברים (כדי שאף אחד לא יישאר בלי תפקיד). מובנה — לא. */
function roleArchive(PDO $pdo, int $orgId, int $id): void {
    $r = roleGet($pdo, $orgId, $id) ?? throw new RoleError('התפקיד לא נמצא', 404);
    if ($r['builtin']) throw new RoleError('תפקיד מובנה אי אפשר להעביר לארכיון — אפשר לשנות לו הרשאות');
    $st = $pdo->prepare('SELECT COUNT(*) FROM members WHERE org_id = ? AND role_id = ?');
    $st->execute([$orgId, $id]);
    if ((int)$st->fetchColumn()) throw new RoleError('יש חברים בתפקיד הזה. קודם להעביר אותם לתפקיד אחר.');
    $pdo->prepare('UPDATE roles SET archived = 1, updated_at = ? WHERE id = ? AND org_id = ?')->execute([gmdate('c'), $id, $orgId]);
}

/** שיוך חבר לתפקיד. מחזיר [הקודם, החדש] (שמות) — לרישום ב-events. */
function memberSetRole(PDO $pdo, int $orgId, int $uid, array $role): array {
    $st = $pdo->prepare('SELECT m.role, m.role_id, r.name FROM members m LEFT JOIN roles r ON r.id = m.role_id WHERE m.org_id = ? AND m.user_id = ?');
    $st->execute([$orgId, $uid]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$m) throw new RoleError('החבר לא נמצא', 404);
    $base = roleBase($role['perms']);
    if ($m['role'] === 'admin' && $base !== 'admin' && roleAdminsLeft($pdo, $orgId, null, '', $uid, $base) < 1) {
        throw new RoleError('זה המנהל האחרון של הארגון. קודם למנות מנהל נוסף.');
    }
    $pdo->prepare('UPDATE members SET role_id = ?, role = ? WHERE org_id = ? AND user_id = ?')->execute([$role['id'], $base, $orgId, $uid]);
    return [(string)($m['name'] ?? $m['role']), $role['name']];
}

/**
 * ההרשאות של המשתמש בארגון הפעיל — מ-roles דרך members.role_id, בכל בקשה (שינוי תפקיד חל מיד).
 * נשמר ב-ctx לאותה בקשה. חבר בלי role_id → התפקיד המובנה לפי members.role; בלי טבלה (לפני v5) → PERMS הישן.
 */
function ctxPerms(): array {
    $c = ctx();
    $org = (int)($c['org']['id'] ?? 0);
    if (isset($c['perms'], $c['perms_org']) && $c['perms_org'] === $org) return $c['perms'];
    $perms = [];
    $role = null;
    $uid = (int)($c['user']['id'] ?? 0);
    if ($org && $uid) {
        $pdo = db();
        $st = $pdo->prepare('SELECT m.role, r.id, r.name, r.perms, r.archived FROM members m
            LEFT JOIN roles r ON r.org_id = m.org_id AND (r.id = m.role_id OR (m.role_id IS NULL AND r.builtin = 1 AND r.base = m.role))
            WHERE m.org_id = ? AND m.user_id = ? AND m.active = 1');
        $st->execute([$org, $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($r) {
            if ($r['id'] !== null && !(int)$r['archived']) {
                $perms = json_decode((string)$r['perms'], true) ?: [];
                $role = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
            } elseif ($r['id'] === null && isset(ROLE_BUILTIN[$r['role']])) {
                $perms = ROLE_BUILTIN[$r['role']]['perms'];
            }
        }
    }
    $GLOBALS['fd_ctx']['perms'] = $perms;
    $GLOBALS['fd_ctx']['perms_org'] = $org;
    $GLOBALS['fd_ctx']['role_def'] = $role;
    return $perms;
}

/** התפקיד (מזהה ושם) של המשתמש בארגון הפעיל, או null. */
function ctxRole(): ?array {
    ctxPerms();
    return $GLOBALS['fd_ctx']['role_def'] ?? null;
}

/* ---------- משימות ---------- */

function taskOut(array $t): array {
    return ['id' => (int)$t['id'], 'case_id' => $t['case_id'], 'check_key' => $t['check_key'], 'type' => $t['type'], 'title' => $t['title'],
        'assignee_user_id' => $t['assignee_user_id'] === null ? null : (int)$t['assignee_user_id'],
        'assignee_role_id' => $t['assignee_role_id'] === null ? null : (int)$t['assignee_role_id'],
        'status' => $t['status'], 'status_name' => TASK_STATUSES[$t['status']] ?? $t['status'], 'due' => $t['due'], 'note' => $t['note'],
        'created_at' => $t['created_at'], 'created_by' => $t['created_by'] === null ? null : (int)$t['created_by'],
        'done_at' => $t['done_at'], 'done_by' => $t['done_by'] === null ? null : (int)$t['done_by']]
        + (isset($t['case_name']) ? ['case_name' => $t['case_name']] : []);
}

/**
 * יצירה או עדכון של משימה בתיק. $in: id, case_id, title, type, check_key, assignee_user_id | assignee_role_id (אחד מהם), due, note.
 * התיק — של הארגון (existing → 404). check_key — בדיקה פעילה. אדם — חבר פעיל בארגון. תפקיד — של הארגון.
 */
function taskSave(PDO $pdo, int $orgId, array $in, int $by): array {
    $id = (int)($in['id'] ?? 0);
    $cur = $id ? taskGet($pdo, $orgId, $id) : null;
    if ($id && $cur === null) throw new RoleError('המשימה לא נמצאה', 404);
    $caseId = (string)($cur['case_id'] ?? ($in['case_id'] ?? ''));
    if ($caseId === '' || existing($pdo, $caseId, $orgId) === null) throw new RoleError('התיק לא נמצא', 404);
    $check = array_key_exists('check_key', $in) ? ($in['check_key'] === null || $in['check_key'] === '' ? null : (string)$in['check_key']) : ($cur['check_key'] ?? null);
    $names = checkNames($pdo, $orgId);
    if ($check !== null && !isset($names[$check])) throw new RoleError('בדיקה לא מוכרת: ' . $check);
    $title = trim((string)($in['title'] ?? ($cur['title'] ?? '')));
    if ($title === '' && $check !== null) $title = $names[$check];
    if ($title === '' || mb_strlen($title) > 200) throw new RoleError('נדרשת כותרת למשימה (עד 200 תווים)');
    $type = mb_substr(trim((string)($in['type'] ?? ($cur['type'] ?? ''))), 0, 60);
    $au = array_key_exists('assignee_user_id', $in) ? ($in['assignee_user_id'] ? (int)$in['assignee_user_id'] : null) : ($cur['assignee_user_id'] ?? null);
    $ar = array_key_exists('assignee_role_id', $in) ? ($in['assignee_role_id'] ? (int)$in['assignee_role_id'] : null) : ($cur['assignee_role_id'] ?? null);
    if (($au === null) === ($ar === null)) throw new RoleError('משימה מוקצית לאדם או לתפקיד — אחד מהם');
    if ($au !== null) {
        $st = $pdo->prepare('SELECT 1 FROM members WHERE org_id = ? AND user_id = ? AND active = 1');
        $st->execute([$orgId, $au]);
        $okU = $st->fetchColumn();
        $st->closeCursor();
        if (!$okU) throw new RoleError('האדם אינו חבר פעיל בארגון', 404);
    }
    if ($ar !== null && ((roleGet($pdo, $orgId, $ar)['archived'] ?? true) !== false)) throw new RoleError('התפקיד לא נמצא', 404);
    $due = (string)($in['due'] ?? ($cur['due'] ?? ''));
    if ($due !== '' && !preg_match('/^\d{4}-\d\d-\d\d$/D', $due)) throw new RoleError('תאריך יעד לא תקין');
    $note = mb_substr(trim((string)($in['note'] ?? ($cur['note'] ?? ''))), 0, 800);
    $now = gmdate('c');
    if ($cur === null) {
        $pdo->prepare('INSERT INTO tasks(org_id, case_id, check_key, type, title, assignee_user_id, assignee_role_id, status, due, note, created_at, created_by)
            VALUES (?,?,?,?,?,?,?,\'open\',?,?,?,?)')->execute([$orgId, $caseId, $check, $type, $title, $au, $ar, $due ?: null, $note, $now, $by]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE tasks SET check_key=?, type=?, title=?, assignee_user_id=?, assignee_role_id=?, due=?, note=? WHERE id=? AND org_id=?')
            ->execute([$check, $type, $title, $au, $ar, $due ?: null, $note, $id, $orgId]);
    }
    return taskGet($pdo, $orgId, $id);
}

function taskGet(PDO $pdo, int $orgId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $t ? taskOut($t) : null;
}

/** סימון: done / open / cancelled. */
function taskStatus(PDO $pdo, int $orgId, int $id, string $status, int $by): array {
    if (!isset(TASK_STATUSES[$status])) throw new RoleError('מצב משימה: open, done או cancelled');
    if (taskGet($pdo, $orgId, $id) === null) throw new RoleError('המשימה לא נמצאה', 404);
    $done = $status === 'open' ? [null, null] : [gmdate('c'), $by];
    $pdo->prepare('UPDATE tasks SET status = ?, done_at = ?, done_by = ? WHERE id = ? AND org_id = ?')->execute([$status, ...$done, $id, $orgId]);
    return taskGet($pdo, $orgId, $id);
}

/** המשימות של תיק. */
function caseTasks(PDO $pdo, int $orgId, string $caseId): array {
    $st = $pdo->prepare('SELECT * FROM tasks WHERE org_id = ? AND case_id = ? ORDER BY status = \'open\' DESC, id');
    $st->execute([$orgId, $caseId]);
    return array_map('taskOut', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** האם התיק הוקצה למשתמש: יש בו משימה (בכל מצב) שלו או של התפקיד שלו. ל-"צפייה רק בתיקים שהוקצו לי". */
function caseAssigned(PDO $pdo, int $orgId, string $caseId, int $uid, ?int $roleId): bool {
    $st = $pdo->prepare('SELECT 1 FROM tasks WHERE org_id = ? AND case_id = ? AND status <> \'cancelled\' AND (assignee_user_id = ? OR assignee_role_id = ?) LIMIT 1');
    $st->execute([$orgId, $caseId, $uid, $roleId ?? -1]);
    $r = $st->fetchColumn();
    $st->closeCursor();
    return (bool)$r;
}

/** מזהי התיקים שהוקצו למשתמש — לסינון רשימות. */
function assignedCaseIds(PDO $pdo, int $orgId, int $uid, ?int $roleId): array {
    $st = $pdo->prepare('SELECT DISTINCT case_id FROM tasks WHERE org_id = ? AND status <> \'cancelled\' AND (assignee_user_id = ? OR assignee_role_id = ?)');
    $st->execute([$orgId, $uid, $roleId ?? -1]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * "המשימות שלי": משימות פתוחות שהוקצו לי או לתפקיד שלי, ועוד הבדיקות שהתפקיד שלי אחראי עליהן בתיקים שלא הושלמו
 * (kind: check — מחושב מ-check_defs.role ומצב הבדיקה, לא נשמר). בדיקה שיש לה כבר משימה מפורשת פתוחה — לא פעמיים.
 * $caseVisible(caseId) — האם המשתמש רואה את התיק (משתמש "רק שהוקצו" רואה רק את המשימות המפורשות שלו).
 */
function myTasks(PDO $pdo, int $orgId, int $uid, ?int $roleId, callable $caseVisible): array {
    $st = $pdo->prepare('SELECT t.*, f.name AS case_name FROM tasks t JOIN funerals f ON f.id = t.case_id AND f.org_id = t.org_id
        WHERE t.org_id = ? AND t.status = \'open\' AND (t.assignee_user_id = ? OR t.assignee_role_id = ?) ORDER BY t.due IS NULL, t.due, t.id');
    $st->execute([$orgId, $uid, $roleId ?? -1]);
    $items = [];
    $seen = [];
    // S8: בדיקה שיש לה משימה מפורשת פתוחה (לכל אחד) — ההקצאה גוברת על "התפקיד האחראי"
    $ex = $pdo->prepare('SELECT case_id, check_key FROM tasks WHERE org_id = ? AND status = \'open\' AND check_key IS NOT NULL');
    $ex->execute([$orgId]);
    foreach ($ex->fetchAll(PDO::FETCH_NUM) as [$ci, $ck]) $seen[$ci . '|' . $ck] = true;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $items[] = ['kind' => 'task'] + taskOut($t);
        if ($t['check_key'] !== null) $seen[$t['case_id'] . '|' . $t['check_key']] = true;
    }
    if ($roleId !== null) {
        $mine = array_filter(checkDefs($pdo, $orgId), fn($d) => $d['role'] === $roleId);
        if ($mine) {
            $st = $pdo->prepare('SELECT id, body FROM funerals WHERE org_id = ? ORDER BY month DESC, name');
            $st->execute([$orgId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $case = json_decode((string)$row['body'], true);
                if (!is_array($case) || !$caseVisible((string)$row['id'])) continue;
                $state = caseChecks($pdo, $orgId, $case);
                if ($state['complete']) continue;
                foreach ($state['checks'] as $c) {
                    if ($c['role'] !== $roleId || in_array($c['state'], ['done', 'na'], true) || isset($seen[$row['id'] . '|' . $c['key']])) continue;
                    $items[] = ['kind' => 'check', 'case_id' => (string)$row['id'], 'case_name' => (string)($case['name'] ?? ''),
                        'date' => (string)($case['date'] ?? ''), 'check_key' => $c['key'], 'title' => $c['name'],
                        'state' => $c['state'], 'state_name' => $c['state_name']];
                }
            }
        }
    }
    return $items;
}

/** S8: אחרי שמירה — משימה פתוחה לבדיקה שהושלמה (או לא רלוונטית) נסגרת. */
function tasksCloseDone(PDO $pdo, int $orgId, array $case, ?int $by): void {
    $st = $pdo->prepare('SELECT id, check_key FROM tasks WHERE org_id = ? AND case_id = ? AND status = \'open\' AND check_key IS NOT NULL');
    $st->execute([$orgId, $case['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;
    $state = [];
    foreach (caseChecks($pdo, $orgId, $case)['checks'] as $c) $state[$c['key']] = $c['state'];
    $up = $pdo->prepare('UPDATE tasks SET status = \'done\', done_at = ?, done_by = ? WHERE id = ?');
    foreach ($rows as $t) if (in_array($state[$t['check_key']] ?? '', ['done', 'na'], true)) $up->execute([gmdate('c'), $by, $t['id']]);
}
