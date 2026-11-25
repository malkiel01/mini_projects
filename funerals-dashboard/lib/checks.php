<?php
declare(strict_types=1);

/**
 * פרוסה 11 — הבדיקות של התיק מטבלה, לא מקוד (אפיון 02 "הבדיקות", א6), והחסר הדחוף (ק3).
 * נטען מסוף lib.php. לא נקודת קצה: גישה ישירה → 404.
 *
 * check_defs (מיגרציה v5): org_id = 0 — ההגדרה הכללית של הבעלים; org_id = N — דריסה של ארגון (שורה מלאה, גוברת לפי key).
 * הסרה = removed = 1 (רכה). שום פעולה כאן לא נוגעת ב-funerals: בדיקה שהוסרה — הערכים שלה נשארים בתיק; בדיקה חדשה —
 * אין לה ערך בתיקים הקיימים, ולכן היא "חסר".
 *
 * לשכבת הבינה (פרוסה 10) ולממשק (12) — הקריאה הצרה: checkDefs($pdo, $orgId) (רשימה מסודרת של הבדיקות הפעילות),
 * caseChecks($pdo, $orgId, $case) (מצב כל בדיקה בתיק), caseUrgent(...). לא לקרוא את check_defs ישירות.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const CHECK_TYPES = ['document' => 'מסמך', 'value' => 'ערך', 'yesno' => 'כן/לא'];
const CHECK_VALUE_KINDS = ['text' => 'טקסט', 'number' => 'מספר', 'date' => 'תאריך'];
/** מצב בדיקה לפי האפיון (02 "מצב של בדיקה"). מיפוי מהסטטוסים השמורים בתיק — checkState(). */
const CHECK_STATES = ['missing' => 'חסר', 'found' => 'נמצא — ממתין לאישור', 'doubt' => 'ספק', 'done' => 'הושלם', 'na' => 'לא רלוונטי'];
/** סטטוסים שפירושם "יש משהו" — תנאי של בדיקה מותנית מתקיים כשהבדיקה שהוא מפנה אליה באחד מהם. */
const CHECK_PRESENT = ['document_present', 'verified', 'reported'];
/** ברירת המחדל של "דחוף" (ק3): ימים מהלוויה. ניתן לשינוי בארגון (orgs.settings.urgent_days). */
const URGENT_DAYS_DEFAULT = 7;

/**
 * 9 הבדיקות של ברירת המחדל — אותם מפתחות ואותו סדר כמו בתיקים הקיימים (ext-lib.php EXT_CHECKS, form.js), כדי שהערכים בתיקים יישארו שלהן.
 * critical — מסמך קריטי ל"דחוף" (ק3). role — תפקיד אחראי: מזהה תפקיד של הארגון, או בסיס (admin/office/field) בהגדרה הכללית.
 */
const CHECK_DEFAULTS = [
    ['key' => 'license_document', 'name' => 'רישיון קבורה', 'type' => 'document', 'doc_kinds' => ['רישיון קבורה'], 'critical' => 1],
    ['key' => 'license_number', 'name' => 'מספר רישיון קבורה', 'type' => 'value', 'value_kind' => 'text', 'doc_kinds' => ['רישיון קבורה']],
    ['key' => 'identity_document', 'name' => 'מסמך זיהוי נפטר', 'type' => 'document', 'doc_kinds' => ['תעודת זהות', 'דרכון', 'תעודת פטירה'], 'critical' => 1],
    ['key' => 'identity_source', 'name' => 'מקור הזיהוי', 'type' => 'value', 'value_kind' => 'text'],
    ['key' => 'payment_evidence', 'name' => 'אסמכתת תשלום', 'type' => 'document', 'doc_kinds' => ['קבלה', 'אסמכתת תשלום', 'חשבונית']],
    ['key' => 'hall_service', 'name' => 'שירות בית ההספדים', 'type' => 'document', 'doc_kinds' => ['שירות בית ההספדים']],
    ['key' => 'grave_purchase', 'name' => 'רכישת קבר נוסף', 'type' => 'document', 'doc_kinds' => ['רכישת קבר']],
    ['key' => 'partner_identity', 'name' => 'זיהוי בן/בת זוג', 'type' => 'document', 'doc_kinds' => ['תעודת זהות בן/בת זוג']],
    ['key' => 'national_insurance', 'name' => 'דיווח לביטוח לאומי', 'type' => 'document', 'doc_kinds' => ['דיווח לביטוח לאומי']],
];

final class CheckError extends RuntimeException {}

/** מילוי ההגדרה הכללית (org_id = 0) — מהמיגרציה. INSERT OR IGNORE: הגדרה שהבעלים כבר שינה לא נדרסת. */
function checksSeed(PDO $pdo): void {
    $st = $pdo->prepare('INSERT OR IGNORE INTO check_defs(org_id, key, name, type, value_kind, doc_kinds, role, cond, critical, position, removed, updated_at, updated_by)
        VALUES (0,?,?,?,?,?,NULL,NULL,?,?,0,?,\'system\')');
    foreach (CHECK_DEFAULTS as $i => $d) {
        $st->execute([$d['key'], $d['name'], $d['type'], $d['value_kind'] ?? '', json_encode($d['doc_kinds'] ?? [], JSON_UNESCAPED_UNICODE),
            (int)($d['critical'] ?? 0), ($i + 1) * 10, gmdate('c')]);
    }
}

function checkRowOut(array $r, string $scope): array {
    return ['key' => $r['key'], 'name' => $r['name'], 'type' => $r['type'], 'value_kind' => (string)$r['value_kind'],
        'doc_kinds' => json_decode((string)$r['doc_kinds'], true) ?: [], 'role' => $r['role'] === null ? null : (string)$r['role'],
        'cond' => $r['cond'] === null ? null : json_decode((string)$r['cond'], true), 'critical' => (int)$r['critical'] === 1,
        'position' => (int)$r['position'], 'removed' => (int)$r['removed'] === 1, 'scope' => $scope,
        'updated_at' => $r['updated_at'], 'updated_by' => $r['updated_by']];
}

/**
 * כל ההגדרות של הארגון, כולל שהוסרו (למסך ההגדרות): הכללית ועליה הדריסה של הארגון, לפי key.
 * scope: global — מהבעלים, org — דריסה או בדיקה של הארגון. סדר: check_order של הארגון (orgs.settings) ואחריו position.
 * $orgId = 0 — ההגדרה הכללית בלבד.
 */
function checkDefsAll(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT * FROM check_defs WHERE org_id IN (0, ?) ORDER BY org_id, position, key');
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();
    $out = [];
    foreach ($rows as $r) {
        $scope = (int)$r['org_id'] === 0 ? 'global' : 'org';
        $d = checkRowOut($r, $scope);
        if ($scope === 'org' && isset($out[$r['key']])) $d['overrides'] = true;
        $out[$r['key']] = $d;
    }
    $order = $orgId ? (orgSettings($pdo, $orgId)['check_order'] ?? []) : [];
    $rank = is_array($order) ? array_flip(array_values(array_filter($order, 'is_string'))) : [];
    uasort($out, static function ($a, $b) use ($rank) {
        $ra = $rank[$a['key']] ?? null; $rb = $rank[$b['key']] ?? null;
        if ($ra !== null && $rb !== null) return $ra <=> $rb;
        if ($ra !== null || $rb !== null) return $ra === null ? 1 : -1;
        return [$a['position'], $a['key']] <=> [$b['position'], $b['key']];
    });
    return array_values($out);
}

/**
 * הקריאה הצרה (לבינה, לממשק, ל-API): הבדיקות הפעילות של הארגון, בסדר שלהן.
 * כל פריט: key, name, type, value_kind, doc_kinds, role (מזהה תפקיד בארגון או null), cond, critical.
 */
function checkDefs(PDO $pdo, int $orgId): array {
    static $cache = [];
    $k = spl_object_id($pdo) . ':' . $orgId . ':' . ($GLOBALS['fd_checks_gen'] ?? 0);
    if (isset($cache[$k])) return $cache[$k];
    $out = [];
    foreach (checkDefsAll($pdo, $orgId) as $d) {
        if ($d['removed']) continue;
        $d['role'] = checkRoleResolve($pdo, $orgId, $d['role']);
        unset($d['removed'], $d['position'], $d['updated_at'], $d['updated_by'], $d['overrides']);
        $out[] = $d;
    }
    return $cache[$k] = $out;
}

/** מפתח → שם, לבדיקות הפעילות. ריק (אין טבלה, למשל לפני המיגרציה) — המילון הקבוע. */
function checkNames(PDO $pdo, int $orgId): array {
    $names = [];
    foreach (checkDefs($pdo, $orgId) as $d) $names[$d['key']] = $d['name'];
    return $names;
}

/** "office" (בסיס, בהגדרה הכללית) → מזהה התפקיד המובנה של הארגון; מזהה → אותו מזהה אם הוא של הארגון ולא בארכיון. */
function checkRoleResolve(PDO $pdo, int $orgId, ?string $role): ?int {
    if ($role === null || $role === '' || $orgId === 0) return null;
    if (ctype_digit($role)) {
        $st = $pdo->prepare('SELECT id FROM roles WHERE id = ? AND org_id = ? AND archived = 0');
        $st->execute([(int)$role, $orgId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM roles WHERE org_id = ? AND base = ? AND builtin = 1 AND archived = 0');
        $st->execute([$orgId, $role]);
    }
    $id = $st->fetchColumn();
    $st->closeCursor();
    return $id === false ? null : (int)$id;
}

/** orgs.settings (JSON) של הארגון. */
function orgSettings(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT settings FROM orgs WHERE id = ?');
    $st->execute([$orgId]);
    $s = $st->fetchColumn();
    $st->closeCursor();
    $v = $s === false ? [] : json_decode((string)$s, true);
    return is_array($v) ? $v : [];
}
function orgSettingsPut(PDO $pdo, int $orgId, array $patch): array {
    $s = array_replace(orgSettings($pdo, $orgId), $patch);
    $pdo->prepare('UPDATE orgs SET settings = ? WHERE id = ?')->execute([json_encode($s, JSON_UNESCAPED_UNICODE), $orgId]);
    return $s;
}

/* ---------- כתיבה ---------- */

/**
 * הוספה או שינוי של בדיקה. $orgId = 0 — ההגדרה הכללית (מפתח בלבד, נבדק בקורא); אחרת — דריסה/בדיקה של הארגון.
 * $in: key (ריק = חדשה, נוצר מפתח), name, type, value_kind, doc_kinds[], role, cond {check}, critical, position.
 * שדה שלא נשלח — נשאר כמו בהגדרה הקיימת (הכללית, כשאין עדיין דריסה). מחזיר את ההגדרה השמורה.
 */
function checkSave(PDO $pdo, int $orgId, array $in, string $by): array {
    $key = trim((string)($in['key'] ?? ''));
    $cur = null;
    $all = [];
    foreach (checkDefsAll($pdo, $orgId) as $d) $all[$d['key']] = $d;
    if ($key !== '') {
        if (!preg_match('/^[a-z_]{2,50}$/D', $key)) throw new CheckError('מפתח בדיקה לא תקין (אותיות לטיניות קטנות וקו תחתון)');
        $cur = $all[$key] ?? null;
    } else {
        do { $key = 'c_' . checkRandKey(); } while (isset($all[$key]));
    }
    if ($cur === null && count(array_filter($all, fn($d) => !$d['removed'])) >= 60) throw new CheckError('אפשר עד 60 בדיקות');
    $name = array_key_exists('name', $in) ? trim(preg_replace('/\s+/u', ' ', (string)$in['name'])) : (string)($cur['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 80) throw new CheckError('נדרש שם לבדיקה (עד 80 תווים)');
    $type = (string)($in['type'] ?? ($cur['type'] ?? 'document'));
    if (!isset(CHECK_TYPES[$type])) throw new CheckError('סוג בדיקה: מסמך, ערך או כן/לא');
    $vk = $type === 'value' ? (string)($in['value_kind'] ?? (($cur['value_kind'] ?? '') ?: 'text')) : '';
    if ($type === 'value' && !isset(CHECK_VALUE_KINDS[$vk])) throw new CheckError('סוג ערך: טקסט, מספר או תאריך');
    $kinds = $in['doc_kinds'] ?? ($cur['doc_kinds'] ?? []);
    if (!is_array($kinds) || count($kinds) > 20) throw new CheckError('סוגי מסמכים: רשימה של עד 20');
    $kinds = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)(is_scalar($x) ? $x : '')), 0, 80), $kinds), 'strlen')));
    $role = array_key_exists('role', $in) ? $in['role'] : ($cur['role'] ?? null);
    $role = $role === null || $role === '' ? null : (string)$role;
    if ($role !== null) {
        if ($orgId === 0 ? !in_array($role, ['admin', 'office', 'field'], true) : checkRoleResolve($pdo, $orgId, $role) === null) {
            throw new CheckError('התפקיד האחראי לא נמצא');
        }
    }
    $cond = array_key_exists('cond', $in) ? $in['cond'] : ($cur['cond'] ?? null);
    if ($cond !== null) {
        $ck = is_array($cond) ? (string)($cond['check'] ?? '') : '';
        if ($ck === '' || $ck === $key || !isset($all[$ck])) throw new CheckError('בדיקה מותנית: התנאי צריך להפנות לבדיקה אחרת שקיימת');
        $cond = ['check' => $ck];
    }
    $critical = array_key_exists('critical', $in) ? (int)!empty($in['critical']) : (int)($cur['critical'] ?? false);
    $position = isset($in['position']) ? (int)$in['position'] : (int)($cur['position'] ?? (max([0, ...array_column($all, 'position')]) + 10));
    $pdo->prepare('INSERT INTO check_defs(org_id, key, name, type, value_kind, doc_kinds, role, cond, critical, position, removed, updated_at, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?)
        ON CONFLICT(org_id, key) DO UPDATE SET name=excluded.name, type=excluded.type, value_kind=excluded.value_kind, doc_kinds=excluded.doc_kinds,
        role=excluded.role, cond=excluded.cond, critical=excluded.critical, position=excluded.position, removed=0,
        updated_at=excluded.updated_at, updated_by=excluded.updated_by')
        ->execute([$orgId, $key, $name, $type, $vk, json_encode($kinds, JSON_UNESCAPED_UNICODE), $role,
            $cond === null ? null : json_encode($cond), $critical, $position, gmdate('c'), $by]);
    $GLOBALS['fd_checks_gen'] = ($GLOBALS['fd_checks_gen'] ?? 0) + 1;
    foreach (checkDefsAll($pdo, $orgId) as $d) if ($d['key'] === $key) return $d;
    throw new LogicException('checkSave');
}

function checkRandKey(): string {
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= chr(97 + random_int(0, 25));
    return $s;
}

/**
 * הסרה רכה: removed = 1 — בארגון (דריסה שמסתירה גם בדיקה כללית) או בהגדרה הכללית ($orgId = 0).
 * הנתונים בתיקים לא נוגעים: הערכים נשארים ב-funerals.body, וחזרה (checkSave עם אותו key) מחזירה אותם.
 */
function checkRemove(PDO $pdo, int $orgId, string $key, string $by): void {
    $cur = null;
    foreach (checkDefsAll($pdo, $orgId) as $d) if ($d['key'] === $key) $cur = $d;
    if ($cur === null) throw new CheckError('הבדיקה לא נמצאה', 404);
    $pdo->prepare('INSERT INTO check_defs(org_id, key, name, type, value_kind, doc_kinds, role, cond, critical, position, removed, updated_at, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?) ON CONFLICT(org_id, key) DO UPDATE SET removed=1, updated_at=excluded.updated_at, updated_by=excluded.updated_by')
        ->execute([$orgId, $key, $cur['name'], $cur['type'], $cur['value_kind'], json_encode($cur['doc_kinds'], JSON_UNESCAPED_UNICODE),
            $cur['role'], $cur['cond'] === null ? null : json_encode($cur['cond']), (int)$cur['critical'], $cur['position'], gmdate('c'), $by]);
    $GLOBALS['fd_checks_gen'] = ($GLOBALS['fd_checks_gen'] ?? 0) + 1;
}

/** ביטול הדריסה של הארגון לבדיקה כללית — חוזרים להגדרה של הבעלים. בדיקה של הארגון בלבד (בלי כללית) — לא מתבטלת, רק מוסרת. */
function checkReset(PDO $pdo, int $orgId, string $key): void {
    $st = $pdo->prepare('SELECT 1 FROM check_defs WHERE org_id = 0 AND key = ?');
    $st->execute([$key]);
    $global = $st->fetchColumn();
    $st->closeCursor();
    if (!$global) throw new CheckError('אין הגדרה כללית לחזור אליה — אפשר רק להסיר');
    $pdo->prepare('DELETE FROM check_defs WHERE org_id = ? AND key = ?')->execute([$orgId, $key]);
    $GLOBALS['fd_checks_gen'] = ($GLOBALS['fd_checks_gen'] ?? 0) + 1;
}

/** סדר הבדיקות: בארגון — orgs.settings.check_order; בהגדרה הכללית — position. מפתחות לא מוכרים נדחים. */
function checkOrder(PDO $pdo, int $orgId, array $keys): void {
    $known = array_column(checkDefsAll($pdo, $orgId), 'key');
    $keys = array_values(array_unique(array_map('strval', $keys)));
    foreach ($keys as $k) if (!in_array($k, $known, true)) throw new CheckError('בדיקה לא מוכרת: ' . $k);
    if ($orgId === 0) {
        $st = $pdo->prepare('UPDATE check_defs SET position = ? WHERE org_id = 0 AND key = ?');
        foreach ($keys as $i => $k) $st->execute([($i + 1) * 10, $k]);
    } else {
        orgSettingsPut($pdo, $orgId, ['check_order' => $keys]);
    }
    $GLOBALS['fd_checks_gen'] = ($GLOBALS['fd_checks_gen'] ?? 0) + 1;
}

/* ---------- מצב בתיק ---------- */

/** הסטטוס השמור בתיק → מצב לפי האפיון. בדיקה שאין לה ערך בתיק (למשל בדיקה חדשה) — unknown → "חסר". */
function checkState(string $status): string {
    return match ($status) {
        'verified', 'reported' => 'done',
        'not_applicable' => 'na',
        'document_present' => 'found',
        'unreadable' => 'doubt',
        default => 'missing',
    };
}

/**
 * מצב כל בדיקה פעילה בתיק, בסדר ההגדרות. בדיקה מותנית שהתנאי שלה לא מתקיים — required: false, state: na.
 * מחזיר גם את מה שבתיק ואין לו הגדרה פעילה (בדיקה שהוסרה) ב-extra — לתצוגה, לא נספר.
 */
function caseChecks(PDO $pdo, int $orgId, array $case): array {
    $defs = checkDefs($pdo, $orgId);
    $have = is_array($case['checks'] ?? null) ? $case['checks'] : [];
    $rows = [];
    $n = array_fill_keys(array_keys(CHECK_STATES), 0);
    foreach ($defs as $d) {
        $c = is_array($have[$d['key']] ?? null) ? $have[$d['key']] : [];
        $status = (string)($c['status'] ?? 'unknown');
        $required = true;
        if ($d['cond'] !== null) $required = checkCondMet($defs, $have, $d['cond']['check']);
        $state = $required ? checkState($status) : 'na';
        $n[$state]++;
        $rows[] = ['key' => $d['key'], 'name' => $d['name'], 'type' => $d['type'], 'role' => $d['role'], 'critical' => $d['critical'],
            'required' => $required, 'state' => $state, 'state_name' => CHECK_STATES[$state], 'status' => $status,
            'value' => (string)($c['value'] ?? ''), 'note' => (string)($c['note'] ?? ''),
            'by' => $c['by'] ?? null, 'via' => $c['via'] ?? null, 'at' => $c['at'] ?? null];
    }
    $extra = [];
    $keys = array_column($defs, 'key');
    foreach ($have as $k => $c) if (!in_array($k, $keys, true) && is_array($c)) $extra[] = ['key' => (string)$k] + $c;
    $complete = $rows !== [] && $n['done'] + $n['na'] === count($rows);   // א13: הכול הושלם או לא רלוונטי → ארכיון
    return ['checks' => $rows, 'counts' => $n, 'complete' => $complete, 'extra' => $extra];
}

/** ימי "דחוף" של הארגון (ק3). */
function urgentDays(PDO $pdo, int $orgId): int {
    $d = orgSettings($pdo, $orgId)['urgent_days'] ?? null;
    return is_int($d) && $d >= 0 && $d <= 365 ? $d : URGENT_DAYS_DEFAULT;
}

/**
 * דחוף (ק3): חסר מסמך קריטי (בדיקה critical במצב "חסר"), או שעברו urgent_days ימים מהלוויה והתיק לא הושלם.
 * מחזיר [] (לא דחוף) או רשימת סיבות: {code: critical, key, name} / {code: days, days}.
 */
function caseUrgent(PDO $pdo, int $orgId, array $case, ?array $state = null, ?DateTimeImmutable $now = null): array {
    $state ??= caseChecks($pdo, $orgId, $case);
    if ($state['complete']) return [];
    $why = [];
    foreach ($state['checks'] as $c) if ($c['critical'] && $c['state'] === 'missing') $why[] = ['code' => 'critical', 'key' => $c['key'], 'name' => $c['name']];
    $date = (string)($case['date'] ?? '');
    if ($date !== '') {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem'));
        $days = (int)(new DateTimeImmutable($date, new DateTimeZone('Asia/Jerusalem')))->diff($now->setTime(0, 0))->format('%r%a');
        if ($days >= urgentDays($pdo, $orgId)) $why[] = ['code' => 'days', 'days' => $days];
    }
    return $why;
}

/** התנאי של בדיקה מותנית: בדיקת "כן/לא" — רק "כן"; אחרת — יש בה משהו (נמצא / הושלם) או הערך "כן". */
function checkCondMet(array $defs, array $have, string $ref): bool {
    $c = is_array($have[$ref] ?? null) ? $have[$ref] : [];
    $value = trim((string)($c['value'] ?? ''));
    foreach ($defs as $d) if ($d['key'] === $ref && $d['type'] === 'yesno') return $value === 'כן';
    return in_array((string)($c['status'] ?? 'unknown'), CHECK_PRESENT, true) || $value === 'כן';
}

/** ערך לבדיקה לפי הסוג שלה (S1): מספר, תאריך YYYY-MM-DD, כן/לא. null — תקין; אחרת — הודעה. ערך ריק תמיד תקין. */
function checkValueError(PDO $pdo, int $orgId, string $key, string $value): ?string {
    if ($value === '') return null;
    foreach (checkDefs($pdo, $orgId) as $d) {
        if ($d['key'] !== $key) continue;
        if ($d['type'] === 'yesno' && !in_array($value, ['כן', 'לא'], true)) return '"' . $d['name'] . '": כן או לא';
        if ($d['type'] === 'value' && $d['value_kind'] === 'number' && !is_numeric(str_replace(',', '', $value))) return '"' . $d['name'] . '": נדרש מספר';
        if ($d['type'] === 'value' && $d['value_kind'] === 'date'
            && (!preg_match('/^(\d{4})-(\d\d)-(\d\d)$/D', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) return '"' . $d['name'] . '": נדרש תאריך (YYYY-MM-DD)';
        return null;
    }
    return null;   // בדיקה שאין לה הגדרה פעילה — כמו קודם
}

/* ---------- ארכיון (א13) ---------- */

/** האם התיק בארכיון (נכנס ולא הוחזר). */
function caseArchived(PDO $pdo, int $orgId, string $id): bool {
    $st = $pdo->prepare('SELECT 1 FROM case_archive WHERE org_id = ? AND case_id = ? AND restored_at IS NULL');
    $st->execute([$orgId, $id]);
    $r = $st->fetchColumn();
    $st->closeCursor();
    return (bool)$r;
}

/**
 * אחרי שמירה: תיק שעבר עכשיו מ"לא הושלם" ל"הושלם" → לארכיון (גם אם הוחזר קודם). $old = null — תיק חדש.
 * בלי שמירה (רשימה) — caseArchiveBackfill(): תיק שהושלם ואין לו שורה בכלל. בדיקה חדשה בהגדרות לא מוציאה מהארכיון.
 */
function caseArchiveAfterSave(PDO $pdo, int $orgId, ?array $old, array $new): void {
    if (!caseChecks($pdo, $orgId, $new)['complete']) return;
    if ($old !== null && caseChecks($pdo, $orgId, $old)['complete']) return;
    $pdo->prepare('INSERT INTO case_archive(org_id, case_id, archived_at, restored_at) VALUES (?,?,?,NULL)
        ON CONFLICT(org_id, case_id) DO UPDATE SET archived_at = excluded.archived_at, restored_at = NULL')->execute([$orgId, $new['id'], gmdate('c')]);
}
function caseArchiveBackfill(PDO $pdo, int $orgId, array $cases): void {
    $st = $pdo->prepare('INSERT OR IGNORE INTO case_archive(org_id, case_id, archived_at) VALUES (?,?,?)');
    foreach ($cases as $c) if (is_array($c) && isset($c['id']) && caseChecks($pdo, $orgId, $c)['complete']) $st->execute([$orgId, $c['id'], gmdate('c')]);
}
/** "החזרה" מהארכיון. */
function caseRestore(PDO $pdo, int $orgId, string $id): bool {
    $st = $pdo->prepare('UPDATE case_archive SET restored_at = ? WHERE org_id = ? AND case_id = ? AND restored_at IS NULL');
    $st->execute([gmdate('c'), $orgId, $id]);
    return $st->rowCount() === 1;
}
/** מזהי התיקים שבארכיון. */
function archivedIds(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT case_id FROM case_archive WHERE org_id = ? AND restored_at IS NULL');
    $st->execute([$orgId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
