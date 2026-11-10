<?php
/**
 * תיקים (אפיון 04): פתיחה, מצבים, משתתפים והרשאות ברמת התיק, תוכנית העבודה מהפרופיל, והחלת גרסה חדשה.
 * המזהים, הגורמים, המשימות והדרישות — ב-lib/case_parts.php.
 *
 * **אין גישה לתיק בלי שורה פעילה ב-case_members** — חברות במשרד אינה מספיקה, וגם ניהול המשרד אינו מספיק.
 * הכול עובר ב-apr_case_get(): תיק של משרד אחר, תיק שאינני משתתף בו ותיק שלא קיים — אותה תשובה (404); משתתף בלי
 * ההרשאה הנדרשת — 403. כל פעולה משנה נרשמת ביומן הפעולות עם case_id (apr_case_audit), וכך נבנית לשונית הפעילות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/profiles.php';

const APR_CASE_STATUSES = ['draft', 'collecting', 'awaiting_reply', 'checking', 'visit', 'analysis', 'review', 'approved', 'delivered', 'closed'];
const APR_CASE_STATUS_BY_REPORT = ['approved', 'delivered'];   // נקבעים רק בידי הדוח (פרוסות 17, 18)
const APR_CASE_PERMS = ['view', 'edit', 'tasks', 'documents', 'review_docs', 'sources', 'visit', 'contact', 'members', 'approve_report', 'export'];
const APR_CASE_ROLES = ['responsible', 'partner', 'visitor', 'staff'];
const APR_CASE_TZ = 'Asia/Jerusalem';

/** ברירת המחדל של ההרשאות לפי תפקיד בתיק (אפיון 04 §6). tasks יכול להיות 'own' — רק המשימות שלו. */
function apr_case_default_perms(string $role, string $officeRole = ''): array
{
    $on = match ($role) {
        'responsible' => APR_CASE_PERMS,
        'partner' => ['view', 'edit', 'tasks', 'documents', 'review_docs', 'sources', 'visit', 'contact'],
        'staff' => ['view', 'tasks', 'documents', 'sources', 'contact'],
        default => ['view', 'visit'],
    };
    $perms = array_fill_keys(APR_CASE_PERMS, false);
    foreach ($on as $p) $perms[$p] = true;
    if ($role === 'visitor') {
        $perms['tasks'] = 'own';
        if ($officeRole === 'trainee') $perms['documents'] = true;   // מתמחה: עמודת "מבקר" + דרישות וקבצים
    }
    return $perms;
}

/** התפקיד שמשתתף מקבל כברירת מחדל, לפי התפקיד שלו במשרד. */
function apr_case_role_for(string $officeRole): string
{
    return match ($officeRole) { 'appraiser' => 'partner', 'trainee' => 'visitor', default => 'staff' };
}

/** רישום ביומן הפעולות, עם case_id — כך הפעולה מופיעה בלשונית הפעילות של התיק. */
function apr_case_audit(array $case, string $action, string $entityType = 'case', ?string $entityPid = null, array $meta = [], ?string $reason = null, ?int $actor = null, string $kind = 'user'): void
{
    $GLOBALS['apr_audit_case'] = (int)$case['id'];
    try {
        apr_audit($action, $entityType, $entityPid ?? $case['pid'], $meta, $reason, (int)$case['office_id'], $actor, $kind);
    } finally {
        unset($GLOBALS['apr_audit_case']);
    }
}

/**
 * התיק לפי pid, אם אני משתתף בו ויש לי את $perm. מחזיר את השורה + 'perms' (שלי) + 'my_role'.
 * משרד אחר / אינני משתתף / לא קיים → 404, בלי הבדל. אין הרשאה → 403. פעולה משנה ($perm אינו view) על תיק סגור → 409,
 * אלא אם $closedOk (שינוי המצב עצמו).
 */
function apr_case_get(array $ctx, mixed $pid, string $perm = 'view', bool $closedOk = false): array
{
    $office = apr_office_id($ctx);
    $c = apr_is_pid($pid) ? apr_row('SELECT * FROM cases WHERE pid = ? AND office_id = ? AND deleted_at IS NULL', [$pid, $office]) : null;
    $m = $c ? apr_row('SELECT * FROM case_members WHERE case_id = ? AND user_id = ? AND revoked_at IS NULL', [$c['id'], $ctx['user']['id']]) : null;
    if (!$m) apr_fail('not_found');
    $perms = (json_decode($m['perms'], true) ?: []) + array_fill_keys(APR_CASE_PERMS, false);
    if (empty($perms['view'])) apr_fail('not_found');
    if (empty($perms[$perm])) apr_fail('forbidden');
    $c += ['perms' => $perms, 'my_role' => $m['role']];
    if ($perm !== 'view' && !$closedOk) apr_case_writable($c);
    return $c;
}

/** תיק סגור — קריאה בלבד, עד שהשמאי האחראי פותח אותו מחדש (case-status). */
function apr_case_writable(array $c): void
{
    if ($c['status'] === 'closed') apr_fail('case_closed');
}

/** על כמה תיקים פתוחים במשרד המשתמש אחראי. */
function apr_cases_responsible_open(int $office, int $user): int
{
    return (int)apr_val("SELECT COUNT(*) FROM cases WHERE office_id = ? AND responsible_user_id = ? AND deleted_at IS NULL AND status <> 'closed'", [$office, $user]);
}

/** חבר פעיל במשרד לפי pid של המשתמש — או not_found. */
function apr_case_office_member(int $office, mixed $userPid): array
{
    return (apr_is_pid($userPid) ? apr_row("SELECT u.id, u.pid, u.name, m.role FROM memberships m JOIN users u ON u.id = m.user_id
        WHERE m.office_id = ? AND u.pid = ? AND m.deleted_at IS NULL AND m.status = 'active' AND u.status = 'active'", [$office, $userPid]) : null) ?? apr_fail('not_found');
}

function apr_case_touch(array $c): void
{
    apr_exec('UPDATE cases SET updated_at = ? WHERE id = ?', [apr_now(), $c['id']]);
}

// ── מספר תיק ──────────────────────────────────────────────────

/** המספר הבא של המשרד לשנה הנוכחית (שעון ישראל): '2026-0001'. בתוך טרנזקציה — אין שני תיקים עם אותו מספר. */
function apr_case_next_number(int $office): string
{
    $year = (int)(new DateTimeImmutable('@' . apr_time()))->setTimezone(new DateTimeZone(APR_CASE_TZ))->format('Y');
    apr_exec('INSERT INTO case_counters (office_id, year, last) VALUES (?, ?, 1) ON CONFLICT(office_id, year) DO UPDATE SET last = last + 1', [$office, $year]);
    return sprintf('%d-%04d', $year, (int)apr_val('SELECT last FROM case_counters WHERE office_id = ? AND year = ?', [$office, $year]));
}

// ── ניקוי קלט ─────────────────────────────────────────────────

function apr_clean_date(mixed $v): ?string
{
    if ($v === null || $v === '') return null;      // חסר אינו אפס: לא ידוע = NULL
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) apr_fail('date_invalid');
    return $v;
}

function apr_clean_choice(mixed $v, array $allowed): ?string
{
    if ($v === null || $v === '') return null;
    if (!is_string($v) || !in_array($v, $allowed, true)) apr_fail('content_invalid');
    return $v;
}

// ── פתיחה ─────────────────────────────────────────────────────

/** הפרופיל לתיק חדש: פעיל, מפורסם, ומותר לי להשתמש בו. אם למשרד פרופיל מחייב — רק הוא. */
function apr_case_profile(array $ctx, mixed $pid): array
{
    $p = apr_profile_get($ctx, $pid);
    if ($p['status'] === 'archived') apr_fail('profile_archived');
    if ($p['status'] !== 'active' || $p['current_version_id'] === null) apr_fail('profile_state');
    apr_case_required_profile(apr_office_id($ctx), $p['pid']);
    return $p;
}

/** פרופיל משרדי מחייב (אם נקבע, והוא פעיל): תיק נפתח — ויוצא מטיוטה — רק איתו. */
function apr_case_required_profile(int $office, string $profilePid): void
{
    $required = (json_decode((string)apr_val('SELECT settings FROM offices WHERE id = ?', [$office]), true) ?: [])['required_profile'] ?? null;
    if ($required !== null && $required !== $profilePid && apr_val("SELECT 1 FROM profiles WHERE pid = ? AND status = 'active' AND deleted_at IS NULL", [$required])) apr_fail('profile_required');
}

/** מה שהאשף צריך: מי יכול להיות אחראי / משתתף, והפרופיל המחייב. */
function apr_case_options(array $ctx): array
{
    $office = apr_office_id($ctx);
    return ['members' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name'], 'role' => $r['role'], 'me' => (int)$r['id'] === (int)$ctx['user']['id']],
        apr_rows("SELECT u.id, u.pid, u.name, m.role FROM memberships m JOIN users u ON u.id = m.user_id
            WHERE m.office_id = ? AND m.deleted_at IS NULL AND m.status = 'active' AND u.status = 'active' ORDER BY u.name, u.id", [$office])),
        'required_profile' => (json_decode((string)apr_val('SELECT settings FROM offices WHERE id = ?', [$office]), true) ?: [])['required_profile'] ?? null,
        'statuses' => APR_CASE_STATUSES, 'perms' => APR_CASE_PERMS, 'roles' => APR_CASE_ROLES];
}

/**
 * תיק חדש — טיוטה. נדרשים רק פרופיל ושמאי אחראי (ברירת המחדל: אני, אם אני שמאי). המספר מוקצה כבר עכשיו.
 * op — מזהה פעולה: לחיצה כפולה = תיק אחד.
 */
function apr_case_create(array $ctx, array $in): array
{
    $o = apr_office($ctx);
    $office = (int)$o['office_id'];
    $uid = (int)$ctx['user']['id'];
    $p = apr_case_profile($ctx, $in['profile'] ?? null);
    $resp = ($in['responsible'] ?? null) !== null ? apr_case_office_member($office, $in['responsible'])
        : ['id' => $uid, 'pid' => $ctx['user']['pid'], 'role' => $o['role']];
    if ($resp['role'] !== 'appraiser') apr_fail('responsible_invalid');   // בדיוק שמאי אחראי אחד, והוא שמאי
    $op = is_string($in['op'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $in['op']) ? $in['op'] : null;
    return apr_tx(function () use ($ctx, $o, $office, $uid, $p, $resp, $op) {
        if ($op !== null && ($prev = apr_row("SELECT entity_pid FROM audit_log WHERE action = 'case.create' AND actor_user_id = ? AND office_id = ? AND json_extract(meta, '$.op') = ?", [$uid, $office, $op]))) {
            return ['case' => $prev['entity_pid'], 'repeat' => true];
        }
        $now = apr_now();
        $pid = apr_pid();
        $number = apr_case_next_number($office);
        $id = apr_insert('cases', ['pid' => $pid, 'office_id' => $office, 'number' => $number, 'profile_id' => $p['id'], 'profile_version_id' => $p['current_version_id'],
            'responsible_user_id' => $resp['id'], 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
        $case = ['id' => $id, 'pid' => $pid, 'office_id' => $office];
        apr_case_member_insert($id, (int)$resp['id'], 'responsible', apr_case_default_perms('responsible'), $uid);
        if ((int)$resp['id'] !== $uid) {
            $role = apr_case_role_for($o['role']);
            apr_case_member_insert($id, $uid, $role, apr_case_default_perms($role, $o['role']), $uid);
        }
        apr_case_audit($case, 'case.create', 'case', $pid, array_filter(['op' => $op]));
        return ['case' => $pid, 'number' => $number];
    });
}

function apr_case_member_insert(int $caseId, int $userId, string $role, array $perms, ?int $by): void
{
    apr_insert('case_members', ['case_id' => $caseId, 'user_id' => $userId, 'role' => $role, 'perms' => json_encode($perms), 'added_by' => $by, 'created_at' => apr_now()]);
}

/** עריכת פרטי התיק. שדה שלא נשלח — לא משתנה; ריק — NULL ("לא ידוע"). */
function apr_case_update(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    $set = [];
    if (array_key_exists('title', $in)) $set['title'] = apr_clean_text($in['title'], 200) ?? '';
    if (array_key_exists('purpose', $in)) $set['purpose'] = apr_clean_choice($in['purpose'], APR_PURPOSES);
    if (array_key_exists('purpose_note', $in)) $set['purpose_note'] = apr_clean_text($in['purpose_note'], 300);
    if (array_key_exists('property_type', $in)) $set['property_type'] = apr_clean_choice($in['property_type'], APR_PROPERTY_TYPES);
    if (array_key_exists('key_date', $in)) $set['key_date'] = apr_clean_date($in['key_date']);
    if (array_key_exists('due_date', $in)) $set['due_date'] = apr_clean_date($in['due_date']);
    if (array_key_exists('flags', $in)) {
        if (!is_array($in['flags'])) apr_fail('content_invalid');
        $flags = [];
        foreach ($in['flags'] as $k => $v) if (is_string($k) && preg_match('/^[a-z0-9_]{1,40}\z/', $k)) $flags[$k] = $v === true;
        $set['flags'] = json_encode((object)$flags);
    }
    // "אחר" תמיד מחייב הערה חופשית (אפיון 03 §4.4)
    $purpose = array_key_exists('purpose', $set) ? $set['purpose'] : $c['purpose'];
    $note = array_key_exists('purpose_note', $set) ? $set['purpose_note'] : $c['purpose_note'];
    if ($purpose === 'other' && $note === null) apr_fail('purpose_note_required');
    if (!$set) return [];
    return apr_tx(function () use ($c, $set) {
        $changed = array_keys(array_filter($set, fn($v, $k) => $v !== $c[$k], ARRAY_FILTER_USE_BOTH));
        if (!$changed) return [];
        apr_exec('UPDATE cases SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', updated_at = ? WHERE id = ?', [...array_values($set), apr_now(), $c['id']]);
        apr_case_audit($c, 'case.update', 'case', null, ['fields' => $changed]);   // שמות השדות בלבד — לא התוכן
        return [];
    });
}

/** מה חסר כדי לצאת מטיוטה (אפיון 04 §4.1): מטרת שומה, מזהה נכס אחד לפחות, ומזמין. מוצג גם אחר כך כ"להשלמה". */
function apr_case_missing(array $c): array
{
    $missing = [];
    if ($c['purpose'] === null) $missing[] = 'purpose';
    if (!apr_val('SELECT 1 FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL', [$c['id']])) $missing[] = 'identifier';
    if (!apr_val('SELECT 1 FROM case_parties WHERE case_id = ? AND removed_at IS NULL AND is_orderer = 1', [$c['id']])) $missing[] = 'orderer';
    return $missing;
}

/** ועוד מה שכדאי להשלים, ואינו חוסם דבר. */
function apr_case_todo(array $c): array
{
    $todo = apr_case_missing($c);
    foreach (['property_type', 'key_date', 'due_date'] as $f) if ($c[$f] === null) $todo[] = $f;
    if ($c['title'] === '') $todo[] = 'title';
    if ($c['responsible_user_id'] === null) $todo[] = 'responsible';
    return $todo;
}

// ── תוכנית העבודה ─────────────────────────────────────────────

/** הקלט של מנוע הפרופיל, מתוך התיק: מטרה, סוג נכס, הדגלים שסומנו, ואילו גורמים יש. */
function apr_case_plan_input(array $c): array
{
    $parties = array_fill_keys(array_column(apr_rows('SELECT DISTINCT kind FROM case_parties WHERE case_id = ? AND removed_at IS NULL', [$c['id']]), 'kind'), true);
    return ['purpose' => $c['purpose'], 'property_type' => $c['property_type'], 'flags' => json_decode($c['flags'], true) ?: [], 'parties' => $parties];
}

function apr_case_version_content(int $versionId): array
{
    return apr_profile_content_read((string)apr_val('SELECT content FROM profile_versions WHERE id = ?', [$versionId]));
}

/** ההסבר הפנימי של כל דרישה בגרסה, לפי key — תצוגת הניסיון משמיטה אותו, והתיק צריך אותו. */
function apr_case_internal_notes(array $content): array
{
    $out = [];
    foreach ($content['requirements']['items'] as $r) $out[$r['key']] = $r['explain_internal'] ?? '';
    foreach ($content['rules'] as $rule) foreach ($rule['then'] as $a) if (($a['do'] ?? '') === 'add_requirement') $out[$a['item']['key']] = $a['item']['explain_internal'] ?? '';
    return $out;
}

/** מה ייווצר: אותו מנוע של תצוגת הניסיון (פרוסה 03), עם הנתונים של התיק. קריאה בלבד. */
function apr_case_plan(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    $content = apr_case_version_content((int)$c['profile_version_id']);
    return ['plan' => apr_profile_try($content, apr_case_plan_input($c)), 'flags' => $content['flags'], 'missing' => apr_case_missing($c)];
}

function apr_case_due(array $due): ?string
{
    return ($due['mode'] ?? '') === 'relative' ? apr_iso(apr_time() + 86400 * (int)$due['days']) : null;
}

/** הגורם בתיק שמספק דרישה מהסוג הזה — הראשון מהסוג; "המשרד משיג" ואין גורם כזה → NULL. */
function apr_case_party_for(int $caseId, string $kind): ?int
{
    $id = apr_val('SELECT id FROM case_parties WHERE case_id = ? AND kind = ? AND removed_at IS NULL ORDER BY id LIMIT 1', [$caseId, $kind]);
    return $id === false ? null : (int)$id;
}

function apr_case_requirement_insert(array $c, array $r, string $origin): string
{
    $now = apr_now();
    $pid = apr_pid();
    apr_insert('requirements', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'origin' => $origin, 'origin_key' => $r['key'] ?? null,
        'group_key' => ($r['group'] ?? '') === '' ? null : $r['group'], 'doc_type' => $r['doc_type'] ?? 'other', 'title' => $r['title'], 'explain_recipient' => $r['explain_recipient'] ?? '',
        'explain_internal' => $r['explain_internal'] ?? '', 'priority' => $r['priority'] ?? 'normal', 'mandatory' => (int)($r['mandatory'] ?? false), 'sort' => (int)($r['sort'] ?? 0),
        'party_kind' => $r['party_kind'] ?? null, 'party_id' => isset($r['party_kind']) ? apr_case_party_for((int)$c['id'], $r['party_kind']) : null,
        'due_at' => apr_case_due($r['due'] ?? []), 'created_at' => $now, 'updated_at' => $now]);
    return $pid;
}

function apr_case_task_from_plan(array $c, array $t, string $kind): string
{
    $now = apr_now();
    $pid = apr_pid();
    $assignee = match ($t['assignee'] ?? 'responsible') { 'responsible' => $c['responsible_user_id'], 'creator' => $c['created_by'], default => null };
    // מי שאינו משתתף פעיל בתיק לא מקבל משימה
    if ($assignee !== null && !apr_val('SELECT 1 FROM case_members WHERE case_id = ? AND user_id = ? AND revoked_at IS NULL', [$c['id'], $assignee])) $assignee = null;
    apr_insert('tasks', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => $kind, 'title' => $t['title'], 'assignee_user_id' => $assignee,
        'due_at' => apr_case_due($t['due'] ?? []), 'origin_key' => $t['key'] ?? null, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now]);
    return $pid;
}

/**
 * "יצירה": התיק יוצא מטיוטה. נבדק מה שנדרש (§4.1), נוצרות הדרישות והמשימות מגרסת הפרופיל — הכללים רצים **פעם אחת**,
 * כאן — נשמר plan_snapshot, והמצב עובר ל-collecting.
 * התאמות לפני היצירה (plan): skip {key: נימוק} — "לא רלוונטי", חובה נימוק; set {key: {priority?, party_kind?}};
 * add [{title, doc_type?, party_kind?, priority?, mandatory?}] — דרישות ידניות.
 */
function apr_case_start(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    $adj = is_array($in['plan'] ?? null) ? $in['plan'] : [];
    if (isset($GLOBALS['apr_before_case_start'])) ($GLOBALS['apr_before_case_start'])();   // בדיקות בלבד: בקשה מקבילה שנכנסת בדיוק כאן
    return apr_tx(function () use ($ctx, $c, $adj) {
        // היציאה מטיוטה נתפסת בתוך הטרנזקציה: שתי בקשות במקביל — רק אחת יוצרת את הדרישות והמשימות
        if (apr_exec("UPDATE cases SET status = 'collecting' WHERE id = ? AND status = 'draft'", [$c['id']]) !== 1) apr_fail('case_state');
        $missing = apr_case_missing($c);
        if ($missing) throw new AprCaseIncomplete($missing);
        $p = apr_row('SELECT * FROM profiles WHERE id = ?', [$c['profile_id']]);
        if (!$p || $p['deleted_at'] !== null || $p['status'] !== 'active') apr_fail('profile_archived');
        apr_case_required_profile((int)$c['office_id'], $p['pid']);   // טיוטה שנפתחה לפני שנקבע פרופיל מחייב
        // טיוטה נפתחת עם הגרסה העדכנית של הפרופיל ברגע היצירה
        $version = (int)$p['current_version_id'];
        $content = apr_case_version_content($version);
        $plan = apr_profile_try($content, apr_case_plan_input($c));
        $notes = apr_case_internal_notes($content);
        $skip = is_array($adj['skip'] ?? null) ? $adj['skip'] : [];
        $set = is_array($adj['set'] ?? null) ? $adj['set'] : [];
        $snap = ['version' => (int)apr_val('SELECT number FROM profile_versions WHERE id = ?', [$version]), 'created_at' => apr_now(), 'input' => apr_case_plan_input($c),
            'requirements' => [], 'tasks' => [], 'alerts' => $plan['alerts'], 'skipped' => [], 'rules' => [], 'applications' => []];
        $c['profile_version_id'] = $version;
        foreach ($plan['requirements'] as $r) {
            if (array_key_exists($r['key'], $skip)) {
                $reason = apr_reason($skip[$r['key']]);   // "לא רלוונטי" — תמיד עם נימוק
                $snap['skipped'][] = ['key' => $r['key'], 'title' => $r['title'], 'reason' => $reason];
                continue;
            }
            if (is_array($set[$r['key']] ?? null)) {
                if (isset($set[$r['key']]['priority'])) $r['priority'] = apr_clean_choice($set[$r['key']]['priority'], APR_PRIORITIES) ?? $r['priority'];
                if (isset($set[$r['key']]['party_kind'])) $r['party_kind'] = apr_clean_choice($set[$r['key']]['party_kind'], APR_PARTY_KINDS) ?? $r['party_kind'];
            }
            apr_case_requirement_insert($c, $r + ['explain_internal' => $notes[$r['key']] ?? ''], $r['rule'] ? 'rule' : 'profile');
            $snap['requirements'][] = ['key' => $r['key'], 'title' => $r['title'], 'origin' => $r['rule'] ? 'rule' : 'profile', 'rule' => $r['rule']['key'] ?? null];
            if ($r['rule']) $snap['rules'][$r['rule']['key']] = $r['rule']['title'];
        }
        foreach (is_array($adj['add'] ?? null) ? array_slice($adj['add'], 0, 50) : [] as $a) {
            if (!is_array($a)) apr_fail('content_invalid');
            apr_case_requirement_insert($c, ['title' => apr_clean_name($a['title'] ?? null, 160), 'doc_type' => is_string($a['doc_type'] ?? null) && in_array($a['doc_type'], apr_doc_type_keys(), true) ? $a['doc_type'] : 'other',
                'party_kind' => apr_clean_choice($a['party_kind'] ?? null, APR_PARTY_KINDS) ?? 'client', 'priority' => apr_clean_choice($a['priority'] ?? null, APR_PRIORITIES) ?? 'normal',
                'mandatory' => ($a['mandatory'] ?? false) === true, 'sort' => 999], 'manual');
        }
        foreach ($plan['tasks'] as $t) {
            apr_case_task_from_plan($c, $t, $t['rule'] ? 'rule' : 'profile');
            $snap['tasks'][] = ['key' => $t['key'], 'title' => $t['title'], 'origin' => $t['rule'] ? 'rule' : 'profile', 'rule' => $t['rule']['key'] ?? null];
            if ($t['rule']) $snap['rules'][$t['rule']['key']] = $t['rule']['title'];
        }
        foreach ($plan['alerts'] as $a) $snap['rules'][$a['rule']['key']] = $a['rule']['title'];
        apr_exec("UPDATE cases SET status = 'collecting', profile_version_id = ?, plan_snapshot = ?, updated_at = ? WHERE id = ?", [$version, json_encode($snap, JSON_UNESCAPED_UNICODE), apr_now(), $c['id']]);
        apr_case_audit($c, 'case.start', 'case', null, ['version' => $snap['version'], 'requirements' => count($snap['requirements']), 'tasks' => count($snap['tasks']), 'skipped' => count($snap['skipped'])]);
        return ['status' => 'collecting', 'requirements' => count($snap['requirements']), 'tasks' => count($snap['tasks'])];
    });
}

/** יציאה מטיוטה נדחתה: 422 עם רשימת החסרים. */
final class AprCaseIncomplete extends AprError
{
    public function __construct(public readonly array $missing)
    {
        parent::__construct('case_incomplete');
    }
}

// ── מצב, סגירה, מחיקה ─────────────────────────────────────────

/**
 * שינוי מצב. קדימה — חופשי; אחורה — עם נימוק; סגירה כשיש דרישת חובה פתוחה או משימה פתוחה — עם נימוק; פתיחה מחדש של
 * תיק סגור — רק האחראי, עם נימוק. approved ו-delivered נקבעים רק בידי הדוח. מטיוטה יוצאים רק ב-case-start.
 */
function apr_case_status(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit', true);
    $to = is_string($in['status'] ?? null) && in_array($in['status'], APR_CASE_STATUSES, true) ? $in['status'] : apr_fail('status_invalid');
    $from = $c['status'];
    if ($to === $from) return [];
    if (in_array($to, APR_CASE_STATUS_BY_REPORT, true)) apr_fail('status_not_manual');
    if ($from === 'draft' || $to === 'draft') apr_fail('case_state');
    $needReason = array_search($to, APR_CASE_STATUSES, true) < array_search($from, APR_CASE_STATUSES, true);
    if ($from === 'closed' && $c['my_role'] !== 'responsible') apr_fail('forbidden');
    if ($to === 'closed' && (apr_val("SELECT 1 FROM requirements WHERE case_id = ? AND mandatory = 1 AND status = 'required'", [$c['id']])
        || apr_val("SELECT 1 FROM tasks WHERE case_id = ? AND status IN ('open', 'in_progress')", [$c['id']]))) $needReason = true;
    $reason = $needReason ? apr_reason($in['reason'] ?? null) : (apr_clean_text($in['reason'] ?? '', 500));
    return apr_tx(function () use ($c, $from, $to, $reason) {
        apr_exec('UPDATE cases SET status = ?, closed_at = ?, close_reason = ?, updated_at = ? WHERE id = ?',
            [$to, $to === 'closed' ? apr_now() : null, $to === 'closed' ? $reason : null, apr_now(), $c['id']]);
        apr_case_audit($c, 'case.status', 'case', null, ['from' => $from, 'to' => $to], $reason);
        return [];
    });
}

/** מחיקה — רק לטיוטה (רכה). המספר שלה אינו חוזר לשימוש. תיק שיצא מטיוטה נסגר, לא נמחק. */
function apr_case_delete(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    if ($c['status'] !== 'draft') apr_fail('case_state');
    return apr_tx(function () use ($c) {
        apr_exec('UPDATE cases SET deleted_at = ?, updated_at = ? WHERE id = ?', [apr_now(), apr_now(), $c['id']]);
        apr_case_audit($c, 'case.delete');
        return [];
    });
}

// ── משתתפים והרשאות ───────────────────────────────────────────

function apr_case_perms_clean(mixed $in, array $base): array
{
    if (!is_array($in)) apr_fail('content_invalid');
    foreach ($in as $k => $v) {
        if (!in_array($k, APR_CASE_PERMS, true) || $k === 'view') continue;   // view תמיד דלוק: מי שלא רואה — מוסר מהתיק
        $base[$k] = $k === 'tasks' && $v === 'own' ? 'own' : $v === true;
    }
    return $base;
}

function apr_case_member_row(array $c, mixed $userPid): array
{
    return (apr_is_pid($userPid) ? apr_row('SELECT cm.*, u.pid AS user_pid, u.name FROM case_members cm JOIN users u ON u.id = cm.user_id
        WHERE cm.case_id = ? AND u.pid = ? AND cm.revoked_at IS NULL', [$c['id'], $userPid]) : null) ?? apr_fail('not_found');
}

/** הוספת משתתף מהמשרד. התפקיד — לפי בקשה, או ברירת המחדל לפי התפקיד שלו במשרד. לא "אחראי": זה בהעברת אחריות. */
function apr_case_member_add(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'members');
    $u = apr_case_office_member((int)$c['office_id'], $in['user'] ?? null);
    $role = ($in['role'] ?? null) === null ? apr_case_role_for($u['role']) : (in_array($in['role'], ['partner', 'visitor', 'staff'], true) ? $in['role'] : apr_fail('role_invalid'));
    return apr_tx(function () use ($ctx, $c, $u, $role) {
        if (apr_val('SELECT 1 FROM case_members WHERE case_id = ? AND user_id = ? AND revoked_at IS NULL', [$c['id'], $u['id']])) apr_fail('already_member');
        // מי שאינו האחראי אינו מעניק הרשאה שאין לו עצמו
        $perms = apr_case_default_perms($role, $u['role']);
        if ($c['my_role'] !== 'responsible') foreach ($perms as $k => $v) {
            if ($k === 'view' || !$v) continue;
            $mine = $c['perms'][$k] ?? false;
            $perms[$k] = $mine === true ? $v : ($k === 'tasks' && $mine === 'own' ? 'own' : false);
        }
        apr_case_member_insert((int)$c['id'], (int)$u['id'], $role, $perms, (int)$ctx['user']['id']);
        apr_case_audit($c, 'case.member_add', 'user', $u['pid'], ['role' => $role]);
        apr_case_touch($c);
        return [];
    });
}

/**
 * שינוי תפקיד (מחזיר את ההרשאות לברירת המחדל שלו) או שינוי תיבות בודדות — **רק השמאי האחראי** (אפיון 04 §6): הרשאת
 * members מוסיפה ומסירה משתתפים, ואינה משנה הרשאות — גם לא של עצמה. role = responsible — העברת אחריות: רק האחראי, עם נימוק.
 */
function apr_case_member_update(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'members');
    if ($c['my_role'] !== 'responsible') apr_fail('forbidden');
    $reason = ($in['role'] ?? null) === 'responsible' ? apr_reason($in['reason'] ?? null) : null;
    return apr_tx(function () use ($ctx, $c, $in, $reason) {
        $m = apr_case_member_row($c, $in['user'] ?? null);
        if (($in['role'] ?? null) === 'responsible') {
            apr_case_set_responsible($c, (int)$m['user_id'], (int)$ctx['user']['id']);
            apr_case_audit($c, 'case.responsible', 'user', $m['user_pid'], ['by' => 'member'], $reason);
            return [];
        }
        if ($m['role'] === 'responsible') apr_fail('responsible_fixed');   // לאחראי כל ההרשאות; מחליפים אחראי, לא מצמצמים אותו
        $role = $m['role'];
        $perms = json_decode($m['perms'], true) ?: [];
        if (array_key_exists('role', $in)) {
            $role = in_array($in['role'], ['partner', 'visitor', 'staff'], true) ? $in['role'] : apr_fail('role_invalid');
            $officeRole = (string)apr_val('SELECT role FROM memberships WHERE office_id = ? AND user_id = ?', [$c['office_id'], $m['user_id']]);
            $perms = apr_case_default_perms($role, $officeRole);
        }
        if (array_key_exists('perms', $in)) $perms = apr_case_perms_clean($in['perms'], $perms + array_fill_keys(APR_CASE_PERMS, false));
        apr_exec('UPDATE case_members SET role = ?, perms = ? WHERE id = ?', [$role, json_encode($perms), $m['id']]);
        apr_case_audit($c, 'case.member_update', 'user', $m['user_pid'], ['role' => $role, 'perms' => array_keys(array_filter($perms))]);
        apr_case_touch($c);
        return [];
    });
}

function apr_case_member_remove(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'members');
    return apr_tx(function () use ($ctx, $c, $in) {
        $m = apr_case_member_row($c, $in['user'] ?? null);
        if ($m['role'] === 'responsible') apr_fail('responsible_fixed');
        apr_exec('UPDATE case_members SET revoked_at = ?, revoked_by = ? WHERE id = ?', [apr_now(), $ctx['user']['id'], $m['id']]);
        apr_exec("UPDATE tasks SET assignee_user_id = NULL, updated_at = ? WHERE case_id = ? AND assignee_user_id = ? AND status IN ('open', 'in_progress')", [apr_now(), $c['id'], $m['user_id']]);
        apr_case_audit($c, 'case.member_remove', 'user', $m['user_pid']);
        apr_case_touch($c);
        return [];
    });
}

/**
 * קובע אחראי חדש (בתוך טרנזקציה): חבר פעיל במשרד בתפקיד שמאי. הקודם נשאר שותף — אם הוא עדיין חבר פעיל במשרד.
 */
function apr_case_set_responsible(array $c, int $userId, ?int $by): void
{
    if (!apr_val("SELECT 1 FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ? AND m.user_id = ? AND m.deleted_at IS NULL AND m.status = 'active'
        AND m.role = 'appraiser' AND u.status = 'active'", [$c['office_id'], $userId])) apr_fail('responsible_invalid');
    $old = apr_row("SELECT * FROM case_members WHERE case_id = ? AND role = 'responsible' AND revoked_at IS NULL", [$c['id']]);
    if ($old && (int)$old['user_id'] === $userId) return;
    if ($old) apr_exec("UPDATE case_members SET role = 'partner', perms = ? WHERE id = ?", [json_encode(apr_case_default_perms('partner')), $old['id']]);
    $mine = apr_row('SELECT id FROM case_members WHERE case_id = ? AND user_id = ? AND revoked_at IS NULL', [$c['id'], $userId]);
    if ($mine) apr_exec("UPDATE case_members SET role = 'responsible', perms = ? WHERE id = ?", [json_encode(apr_case_default_perms('responsible')), $mine['id']]);
    else apr_case_member_insert((int)$c['id'], $userId, 'responsible', apr_case_default_perms('responsible'), $by);
    apr_exec('UPDATE cases SET responsible_user_id = ?, updated_at = ? WHERE id = ?', [$userId, apr_now(), $c['id']]);
}

/**
 * חבר הושעה, הוסר או עזב את המשרד: כל שורות ההשתתפות שלו בתיקי המשרד מבוטלות. תיקים שהיה אחראי להם נשארים "בלי
 * אחראי פעיל" (שורת הניהול של מנהל המשרד), עד שמנהל המשרד מעביר אחריות. נקרא מתוך הטרנזקציה של הפעולה במשרד.
 */
function apr_cases_on_member_out(int $office, int $user): void
{
    $rows = apr_rows('SELECT cm.id, cm.role, c.id AS case_id, c.pid, c.office_id FROM case_members cm JOIN cases c ON c.id = cm.case_id
        WHERE c.office_id = ? AND cm.user_id = ? AND cm.revoked_at IS NULL', [$office, $user]);
    foreach ($rows as $r) {
        apr_exec('UPDATE case_members SET revoked_at = ?, revoked_by = NULL WHERE id = ?', [apr_now(), $r['id']]);
        if ($r['role'] === 'responsible') apr_exec('UPDATE cases SET responsible_user_id = NULL WHERE id = ?', [$r['case_id']]);
        apr_exec("UPDATE tasks SET assignee_user_id = NULL, updated_at = ? WHERE case_id = ? AND assignee_user_id = ? AND status IN ('open', 'in_progress')", [apr_now(), $r['case_id'], $user]);
        apr_case_audit(['id' => $r['case_id'], 'pid' => $r['pid'], 'office_id' => $r['office_id']], 'case.member_out', 'case', null, ['was' => $r['role']], null, null, 'system');
    }
}

// ── מנהל משרד: שורות ניהול והעברת אחריות (§6.1) ───────────────

/** שורת ניהול לכל תיק במשרד: מספר, אחראי, מצב, יעד, עדכון — ושום דבר אחר. */
function apr_office_cases(array $ctx): array
{
    $office = apr_office_id($ctx);
    return ['cases' => array_map(fn($r) => ['pid' => $r['pid'], 'number' => $r['number'], 'responsible' => $r['responsible'], 'status' => $r['status'],
        'due_date' => $r['due_date'], 'updated_at' => $r['updated_at']],
        apr_rows('SELECT c.pid, c.number, c.status, c.due_date, c.updated_at, u.name AS responsible FROM cases c LEFT JOIN users u ON u.id = c.responsible_user_id
            WHERE c.office_id = ? AND c.deleted_at IS NULL ORDER BY (c.responsible_user_id IS NULL) DESC, c.id DESC', [$office])),
        'appraisers' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name']], apr_rows("SELECT u.pid, u.name FROM memberships m JOIN users u ON u.id = m.user_id
            WHERE m.office_id = ? AND m.deleted_at IS NULL AND m.status = 'active' AND m.role = 'appraiser' AND u.status = 'active' AND u.id <> ? ORDER BY u.name", [$office, $ctx['user']['id']]))];
}

/** מנהל המשרד מעביר אחריות לשמאי **אחר** במשרד — עם נימוק. אינו יכול להעביר לעצמו, וכך אינו מוסיף את עצמו לתיק. */
function apr_office_case_transfer(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $reason = apr_reason($in['reason'] ?? null);
    $c = (apr_is_pid($in['case'] ?? null) ? apr_row('SELECT * FROM cases WHERE pid = ? AND office_id = ? AND deleted_at IS NULL', [$in['case'], $office]) : null) ?? apr_fail('not_found');
    $u = apr_case_office_member($office, $in['user'] ?? null);
    if ((int)$u['id'] === (int)$ctx['user']['id']) apr_fail('transfer_self');   // §6.1: לשמאי אחר; מנהל אינו מוסיף את עצמו לתיק
    return apr_tx(function () use ($ctx, $c, $u, $reason) {
        apr_case_set_responsible($c, (int)$u['id'], (int)$ctx['user']['id']);
        apr_case_audit($c, 'case.responsible', 'user', $u['pid'], ['by' => 'office_admin'], $reason);
        return [];
    });
}

// ── החלת גרסת פרופיל חדשה (§8) ────────────────────────────────

/**
 * ההבדלים בין מה שיש בתיק למה שהגרסה העדכנית של הפרופיל הייתה יוצרת עכשיו (עם הדגלים והגורמים של היום):
 * added — ייווספו; changed — השתנו בפרופיל, ועדיין לא טופלו בתיק; removed — אינם בגרסה החדשה (נשארים בתיק).
 */
function apr_case_profile_diff_calc(array $c): array
{
    $p = apr_row('SELECT * FROM profiles WHERE id = ?', [$c['profile_id']]);
    $target = (int)$p['current_version_id'];
    $number = fn(int $id) => (int)apr_val('SELECT number FROM profile_versions WHERE id = ?', [$id]);
    $content = apr_case_version_content($target);
    $plan = apr_profile_try($content, apr_case_plan_input($c));
    $notes = apr_case_internal_notes($content);
    foreach ($plan['requirements'] as $i => $r) $plan['requirements'][$i] += ['explain_internal' => $notes[$r['key']] ?? ''];
    $out = ['from' => $number((int)$c['profile_version_id']), 'to' => $number($target), 'target' => $target, 'profile' => $p['name'],
        'requirements' => ['added' => [], 'changed' => [], 'removed' => []], 'tasks' => ['added' => [], 'changed' => [], 'removed' => []], 'plan' => $plan];
    $skipped = array_column((json_decode($c['plan_snapshot'], true) ?: [])['skipped'] ?? [], 'key');
    $have = array_column(apr_rows("SELECT * FROM requirements WHERE case_id = ? AND origin IN ('profile', 'rule') AND origin_key IS NOT NULL", [$c['id']]), null, 'origin_key');
    $new = array_column($plan['requirements'], null, 'key');
    foreach ($new as $key => $r) {
        if (!isset($have[$key])) {
            if (!in_array($key, $skipped, true)) $out['requirements']['added'][] = ['key' => $key, 'title' => $r['title'], 'mandatory' => $r['mandatory'], 'rule' => $r['rule']['title'] ?? null];
            continue;
        }
        $h = $have[$key];
        $diff = [];
        foreach (['title' => $r['title'], 'priority' => $r['priority'], 'mandatory' => (int)$r['mandatory']] as $f => $v) if ($h[$f] != $v) $diff[$f] = ['case' => $h[$f], 'profile' => $v];
        // מה שכבר טופל (אינו required) — לא משתנה, ולכן גם לא מוצע
        if ($diff && $h['status'] === 'required') $out['requirements']['changed'][] = ['key' => $key, 'title' => $h['title'], 'fields' => $diff];
    }
    foreach (array_diff_key($have, $new) as $key => $h) $out['requirements']['removed'][] = ['key' => $key, 'title' => $h['title']];
    $haveT = array_column(apr_rows("SELECT * FROM tasks WHERE case_id = ? AND kind IN ('profile', 'rule') AND origin_key IS NOT NULL", [$c['id']]), null, 'origin_key');
    $newT = array_column($plan['tasks'], null, 'key');
    foreach ($newT as $key => $t) {
        if (!isset($haveT[$key])) { $out['tasks']['added'][] = ['key' => $key, 'title' => $t['title'], 'rule' => $t['rule']['title'] ?? null]; continue; }
        if ($haveT[$key]['title'] !== $t['title'] && $haveT[$key]['status'] === 'open') $out['tasks']['changed'][] = ['key' => $key, 'title' => $haveT[$key]['title'], 'fields' => ['title' => ['case' => $haveT[$key]['title'], 'profile' => $t['title']]]];
    }
    foreach (array_diff_key($haveT, $newT) as $key => $h) $out['tasks']['removed'][] = ['key' => $key, 'title' => $h['title']];
    return $out;
}

function apr_case_profile_diff(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    if ($c['status'] === 'draft') apr_fail('case_state');
    return ['diff' => array_diff_key(apr_case_profile_diff_calc($c), ['target' => 0, 'plan' => 0])];
}

/**
 * החלה: רק מה שסומן. requirements_add / tasks_add / requirements_change / tasks_change — רשימות של key.
 * שום דבר לא נמחק; דרישה שכבר אינה required ומשימה שאינה open לא משתנות. profile_version_id מתעדכן,
 * ו-plan_snapshot מקבל רשומת החלה.
 */
function apr_case_profile_apply(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    if ($c['status'] === 'draft') apr_fail('case_state');
    $pick = fn(string $k) => array_values(array_filter(is_array($in[$k] ?? null) ? $in[$k] : [], 'is_string'));
    return apr_tx(function () use ($ctx, $c, $pick) {
        $d = apr_case_profile_diff_calc($c);
        $plan = ['r' => array_column($d['plan']['requirements'], null, 'key'), 't' => array_column($d['plan']['tasks'], null, 'key')];
        $done = ['requirements_add' => [], 'tasks_add' => [], 'requirements_change' => [], 'tasks_change' => []];
        foreach (array_intersect($pick('requirements_add'), array_column($d['requirements']['added'], 'key')) as $key) {
            apr_case_requirement_insert($c, $plan['r'][$key], $plan['r'][$key]['rule'] ? 'rule' : 'profile');
            $done['requirements_add'][] = $key;
        }
        foreach (array_intersect($pick('tasks_add'), array_column($d['tasks']['added'], 'key')) as $key) {
            apr_case_task_from_plan($c, $plan['t'][$key], $plan['t'][$key]['rule'] ? 'rule' : 'profile');
            $done['tasks_add'][] = $key;
        }
        foreach (array_intersect($pick('requirements_change'), array_column($d['requirements']['changed'], 'key')) as $key) {
            $r = $plan['r'][$key];
            apr_exec("UPDATE requirements SET title = ?, priority = ?, mandatory = ?, updated_at = ? WHERE case_id = ? AND origin_key = ? AND status = 'required'",
                [$r['title'], $r['priority'], (int)$r['mandatory'], apr_now(), $c['id'], $key]);
            $done['requirements_change'][] = $key;
        }
        foreach (array_intersect($pick('tasks_change'), array_column($d['tasks']['changed'], 'key')) as $key) {
            apr_exec("UPDATE tasks SET title = ?, updated_at = ? WHERE case_id = ? AND origin_key = ? AND status = 'open'", [$plan['t'][$key]['title'], apr_now(), $c['id'], $key]);
            $done['tasks_change'][] = $key;
        }
        $snap = json_decode($c['plan_snapshot'], true) ?: [];
        $snap['applications'][] = ['at' => apr_now(), 'by' => $ctx['user']['pid'], 'from' => $d['from'], 'to' => $d['to']] + $done;
        apr_exec('UPDATE cases SET profile_version_id = ?, plan_snapshot = ?, updated_at = ? WHERE id = ?', [$d['target'], json_encode($snap, JSON_UNESCAPED_UNICODE), apr_now(), $c['id']]);
        apr_case_audit($c, 'case.profile_apply', 'case', null, ['from' => $d['from'], 'to' => $d['to']] + array_map('count', $done));
        return ['applied' => array_map('count', $done), 'version' => $d['to']];
    });
}
