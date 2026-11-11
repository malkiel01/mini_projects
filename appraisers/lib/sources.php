<?php
/**
 * פרוסה 09 — מקורות מידע, בדיקות, תכנון והיתרים: המסלול הידני (docs/spec/09-sources.md).
 *
 * כללי היסוד, שכל פונקציה כאן שומרת עליהם:
 *  - **שום קריאה לאתר חיצוני.** אין כאן HTTP, אין connector, ואין שום שדה שמקבל סיסמה, קוד חד-פעמי או פרטי תשלום של אתר
 *    חיצוני (09-C5). "אוטומטי" בפרופיל פירושו: נוצרת משימה לבד. מקור אינו "מחובר" בגלל הגדרה בפרופיל (09-B1).
 *  - **"לא אותר" ו"מקור לא זמין" אינם "אין".** apr_src_check_out() הוא המקום היחיד שבונה בדיקה לתשובה, והוא תמיד מצרף את
 *    נוסח ההסתייגות למצבים האלה (09-A2). אין בקוד מיפוי של מצב בדיקה לערך בוליאני, ואין שדה שמסיק "אין היתר".
 *  - **בדיקה היא שורה ביומן:** בדיקה חדשה, "התחלתי", אישור — כולם שורה חדשה; הקודמות נשארות כפי שהן (09-A7). בשורה קיימת
 *    משתנים רק found → verified, והוצאה שנרשמת פעם אחת. המסד אוכף את זה בטריגרים (מיגרציה 010).
 *  - **בקשה אינה היתר:** kind של פריט תכנון נקבע ביצירה ואינו משתנה (09-A4). היתר הוא פריט חדש, ואפשר לקשר ביניהם.
 *  - כל כתיבה בטרנזקציה אחת עם היומן; תיק סגור — קריאה בלבד.
 *
 * הרשאות (§10): צפייה — view; רישום בדיקה והוספת פריט — sources; "נבדק ואומת", קביעת תחולה ואישור — sources + שמאי
 * במשרד שמשתתף בתיק כאחראי או כשותף (apr_src_can_judge). מי שאינו שמאי אינו משנה ואינו מוחק פריט שתחולתו נקבעה.
 */

declare(strict_types=1);

const APR_SRC_INFO_KINDS = ['deals', 'planning', 'permits', 'rights', 'map', 'enforcement', 'stats'];
const APR_SRC_ACCESS = ['connector', 'guided', 'manual', 'unavailable'];
const APR_SRC_FEASIBILITY = ['approved', 'needs_inquiry', 'manual_only', 'unavailable'];
const APR_SRC_AUTH = ['none', 'personal_login', 'national_id', 'payment', 'agreement'];
const APR_SRC_LICENSE_KEYS = ['display', 'store', 'offline', 'in_report', 'distribute', 'ai'];
const APR_SRC_LICENSE_VALUES = ['yes', 'no', 'unknown'];
const APR_SRC_MODES = ['auto', 'manual', 'ask'];
const APR_SRC_STALE_DAYS = 180;
/** מה שמשתמש רושם כתוצאה. planned / awaiting_identification / needs_approval / in_progress נוצרים רק בידי המערכת; verified — רק ב-source-check-verify. */
const APR_SRC_RESULTS = ['found', 'partial', 'not_found', 'unavailable', 'needs_login', 'needs_clarification', 'failed'];
/** הבדיקה לא הושלמה ← משימת השלמה ידנית (§5, בדיקת קבלה 13). */
const APR_SRC_RETRY = ['unavailable', 'needs_login', 'failed'];
/** נוסח ההסתייגות — מופיע תמיד, בכל תשובה שמחזירה בדיקה במצב הזה (§5 "הכלל שאסור לשבור"). */
const APR_SRC_DISCLAIMERS = [
    'not_found' => 'לא נמצאה תוצאה בבדיקה הזו, במקור הזה. אין בכך הוכחה שאין זכות, תוכנית, היתר או עבירה.',
    'unavailable' => 'המקור לא היה זמין. לא נבדק.',
];
const APR_SRC_STORE_NOTES = [
    'no' => 'הפניה בלבד: תנאי המקור אינם מתירים לשמור את המסמך — נשמר רק הקישור.',
    'unknown' => 'תנאי השימוש של המקור לא נבדקו. ודאו שמותר לכם לשמור את המסמך.',
];
const APR_PLAN_KINDS = ['plan', 'application', 'decision', 'permit', 'building_doc', 'enforcement'];
const APR_PLAN_LABELS = ['plan' => 'תוכניות', 'application' => 'בקשות להיתר', 'decision' => 'החלטות', 'permit' => 'היתרים שהונפקו', 'building_doc' => 'מסמכים מתיק הבניין', 'enforcement' => 'רישומי פיקוח'];
const APR_PLAN_APPLICABILITY = ['unknown', 'applies', 'partial', 'not_applies'];
const APR_PLAN_EMPTY = 'לא נרשמו פריטים. רשימה ריקה אינה מעידה שאין תוכנית, היתר או הליך — ראו אילו מקורות נבדקו.';
const APR_PLAN_ZERO = 'לא נרשם בתיק. אין בכך הוכחה שאין — ראו אילו מקורות נבדקו.';

const APR_SRC_CHECK_SELECT = 'SELECT k.*, s.key AS source_key, s.name AS source_name, pu.name AS performed_name, vu.name AS verified_name, au.name AS approved_name,
        kf.pid AS file_pid, kf.original_name AS file_name, kf.deleted_at AS file_deleted
    FROM source_checks k JOIN sources s ON s.id = k.source_id LEFT JOIN users pu ON pu.id = k.performed_by LEFT JOIN users vu ON vu.id = k.verified_by
        LEFT JOIN users au ON au.id = k.approved_by LEFT JOIN files kf ON kf.id = k.file_id';
const APR_PLAN_SELECT = 'SELECT p.*, s.key AS source_key, s.name AS source_name, k.pid AS check_pid, r.pid AS related_pid, r.kind AS related_kind, r.title AS related_title,
        r.deleted_at AS related_deleted, pf.pid AS file_pid, pf.original_name AS file_name, pf.deleted_at AS file_deleted, cu.name AS created_name, au.name AS applicability_name
    FROM planning_items p LEFT JOIN sources s ON s.id = p.source_id LEFT JOIN source_checks k ON k.id = p.check_id LEFT JOIN planning_items r ON r.id = p.related_id
        LEFT JOIN files pf ON pf.id = p.file_id LEFT JOIN users cu ON cu.id = p.created_by LEFT JOIN users au ON au.id = p.applicability_by';

// ── קלט ───────────────────────────────────────────────────────

/** קישור שמשתמש הזין: http / https בלבד, בלי פרטי התחברות בתוכו. null כשריק. כל השאר (javascript:, data:, יחסי) — נדחה. */
function apr_src_url(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (!is_string($v)) apr_fail('url_invalid');
    $s = trim($v);
    if ($s === '') return null;
    if (strlen($s) > 500 || !preg_match('#^https?://[^\s<>"\'`\\\\\x00-\x20\x7f]+\z#i', $s) || filter_var($s, FILTER_VALIDATE_URL) === false) apr_fail('url_invalid');
    $p = parse_url($s);
    if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass'])) apr_fail('url_invalid');
    return $s;
}

function apr_src_enum(mixed $v, array $allowed, string $code): string
{
    if (!is_string($v) || !in_array($v, $allowed, true)) apr_fail($code);
    return $v;
}

/** הוצאה באגורות: שלם, 0 ומעלה. null = לא נרשם; 0 = ללא עלות (מקרה קצה 9). */
function apr_src_cost(mixed $v): ?int
{
    if ($v === null || $v === '') return null;
    if (!is_int($v) || $v < 0 || $v > 100_000_000) apr_fail('cost_invalid');
    return $v;
}

function apr_src_norm(?string $s): string
{
    return mb_strtolower(preg_replace('/[\s\-־–"״׳\'.]+/u', '', (string)$s) ?? '');
}

// ── הקטלוג (§4) ───────────────────────────────────────────────

function apr_src_license(?string $json): array
{
    $l = json_decode((string)$json, true);
    $out = [];
    foreach (APR_SRC_LICENSE_KEYS as $k) $out[$k] = is_array($l) && in_array($l[$k] ?? null, APR_SRC_LICENSE_VALUES, true) ? $l[$k] : 'unknown';
    return $out;
}

function apr_src_get(mixed $key): array
{
    return (is_string($key) && preg_match('/^[a-z][a-z0-9_]{1,39}\z/', $key) ? apr_row('SELECT * FROM sources WHERE key = ?', [$key]) : null) ?? apr_fail('not_found');
}

/**
 * כרטיס מקור לתשובה. connected נגזר **רק** מהכרטיס עצמו (חיבור שאושר — פרוסה 20); שום הגדרה בפרופיל אינה משנה אותו.
 * card_stale: הכרטיס עצמו לא נבדק מעל 180 יום, או מעולם.
 */
function apr_source_out(array $s): array
{
    $lic = apr_src_license($s['license']);
    $stale = $s['last_verified_at'] === null || strtotime((string)$s['last_verified_at']) < apr_time() - APR_SRC_STALE_DAYS * 86400;
    return ['key' => $s['key'], 'name' => $s['name'], 'owner' => $s['owner'], 'url' => $s['url'], 'info_kinds' => json_decode($s['info_kinds'], true) ?: [],
        'region' => $s['region'], 'municipality' => $s['municipality'], 'access_mode' => $s['access_mode'], 'feasibility' => $s['feasibility'],
        'connected' => $s['access_mode'] === 'connector' && $s['feasibility'] === 'approved',
        'auth_required' => $s['auth_required'], 'paid' => $s['auth_required'] === 'payment', 'cost_note' => $s['cost_note'], 'terms_url' => $s['terms_url'],
        'license' => $lic, 'store_note' => APR_SRC_STORE_NOTES[$lic['store']] ?? null, 'attribution' => $s['attribution'], 'coverage_note' => $s['coverage_note'],
        'limitations' => $s['limitations'], 'how_to' => $s['how_to'], 'research_ref' => $s['research_ref'], 'last_verified_at' => $s['last_verified_at'], 'card_stale' => $stale,
        'last_incident' => $s['last_incident'], 'status' => $s['status'], 'sort' => (int)$s['sort']];
}

function apr_sources_all(bool $activeOnly = false): array
{
    return apr_rows('SELECT * FROM sources' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY sort, id');
}

/** הקטלוג לקריאה — כל משתמש פעיל. מקור מוסתר אינו מופיע. */
function apr_sources_list(): array
{
    return ['sources' => array_map('apr_source_out', apr_sources_all(true)), 'stale_days' => APR_SRC_STALE_DAYS];
}

function apr_sys_sources(): array
{
    return ['info_kinds' => APR_SRC_INFO_KINDS, 'access_modes' => ['guided', 'manual', 'unavailable'], 'feasibility' => ['needs_inquiry', 'manual_only', 'unavailable'], 'auth' => APR_SRC_AUTH,
        'license_keys' => APR_SRC_LICENSE_KEYS, 'stale_days' => APR_SRC_STALE_DAYS,
        'sources' => array_map(fn($s) => apr_source_out($s) + ['in_use' => (bool)apr_val('SELECT 1 FROM source_checks WHERE source_id = ? LIMIT 1', [$s['id']])], apr_sources_all())];
}

/**
 * מנהל המערכת מוסיף ועורך כרטיס: key (חדש — עם create), name, owner, url, info_kinds[], region, municipality, access_mode, feasibility,
 * auth_required, cost_note, terms_url, license{}, attribution, coverage_note, limitations, how_to, last_incident, status, sort,
 * mark_verified (הכרטיס נבדק עכשיו). מקור אינו נמחק — רק מוסתר.
 * **connector / approved אינם ניתנים לקביעה בפרוסה הזו:** חיבור נוצר רק בפרוסה 20, אחרי בדיקת היתכנות — הגדרה אינה יוצרת חיבור.
 */
function apr_sys_source_save(array $ctx, array $in): array
{
    $key = $in['key'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{1,39}\z/', $key)) apr_fail('source_invalid');
    $access = apr_src_enum($in['access_mode'] ?? 'manual', APR_SRC_ACCESS, 'source_invalid');
    $feas = apr_src_enum($in['feasibility'] ?? 'manual_only', APR_SRC_FEASIBILITY, 'source_invalid');
    if ($access === 'connector' || $feas === 'approved') apr_fail('source_connector_later');
    $kinds = $in['info_kinds'] ?? [];
    if (!is_array($kinds) || !array_is_list($kinds) || !$kinds || array_diff($kinds, APR_SRC_INFO_KINDS) || count($kinds) !== count(array_unique($kinds))) apr_fail('source_invalid');
    $region = apr_src_enum($in['region'] ?? 'national', ['national', 'municipality'], 'source_invalid');
    $lic = [];
    foreach (APR_SRC_LICENSE_KEYS as $k) $lic[$k] = apr_src_enum(is_array($in['license'] ?? null) ? ($in['license'][$k] ?? 'unknown') : 'unknown', APR_SRC_LICENSE_VALUES, 'source_invalid');
    $sort = $in['sort'] ?? 0;
    if (!is_int($sort) || $sort < 0 || $sort > 100000) apr_fail('source_invalid');
    $row = ['name' => apr_clean_name($in['name'] ?? null, 120), 'owner' => apr_clean_name($in['owner'] ?? null, 120), 'url' => apr_src_url($in['url'] ?? null),
        'info_kinds' => json_encode(array_values($kinds)), 'region' => $region, 'municipality' => $region === 'municipality' ? apr_clean_text($in['municipality'] ?? null, 80) : null,
        'access_mode' => $access, 'feasibility' => $feas, 'auth_required' => apr_src_enum($in['auth_required'] ?? 'none', APR_SRC_AUTH, 'source_invalid'),
        'cost_note' => apr_clean_text($in['cost_note'] ?? null, 300), 'terms_url' => apr_src_url($in['terms_url'] ?? null), 'license' => json_encode($lic),
        'attribution' => apr_clean_text($in['attribution'] ?? null, 300), 'coverage_note' => apr_clean_text($in['coverage_note'] ?? null, 1000) ?? '',
        'limitations' => apr_clean_text($in['limitations'] ?? null, 1000) ?? '', 'how_to' => apr_clean_text($in['how_to'] ?? null, 2000) ?? '',
        'last_incident' => apr_clean_text($in['last_incident'] ?? null, 500), 'status' => apr_src_enum($in['status'] ?? 'active', ['active', 'hidden'], 'source_invalid'),
        'sort' => $sort, 'updated_at' => apr_now()];
    if (($in['mark_verified'] ?? false) === true) $row += ['last_verified_at' => apr_now(), 'verified_by' => (int)$ctx['user']['id']];
    return apr_tx(function () use ($key, $row, $in) {
        $old = apr_row('SELECT * FROM sources WHERE key = ?', [$key]);
        if (!$old) {
            if (($in['create'] ?? false) !== true) apr_fail('not_found');
            apr_insert('sources', ['pid' => apr_pid(), 'key' => $key, 'created_at' => apr_now()] + $row);
        } else {
            if (($in['create'] ?? false) === true) apr_fail('source_exists');
            if (apr_exec('UPDATE sources SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $old['id']]) !== 1) apr_fail('not_found');
        }
        apr_audit($old ? 'source.update' : 'source.create', 'source', $key, ['fields' => array_keys($row), 'status' => $row['status']]);
        return ['source' => apr_source_out(apr_src_get($key))];
    });
}

// ── גישה ──────────────────────────────────────────────────────

/** מי רשאי ל"נבדק ואומת", לקביעת תחולה ולאישור: sources בתיק + שמאי במשרד שמשתתף בתיק כאחראי או כשותף (§10, 09-C4). */
function apr_src_can_judge(array $ctx, array $c): bool
{
    return !empty($c['perms']['sources']) && in_array($c['my_role'], ['responsible', 'partner'], true) && ($ctx['office']['role'] ?? '') === 'appraiser';
}

/**
 * התיק לפעולה על מקורות ותכנון. $casePid מגיע מהבקשה, או נגזר ממזהה-בן (בדיקה / פריט) — תיק של משרד אחר, תיק שאינני משתתף
 * בו ומזהה שלא קיים: אותו 404 (09-C3). $need: view | write | judge. פעולה משנה על תיק סגור ← 409.
 */
function apr_src_case(array $ctx, mixed $casePid, string $need = 'view'): array
{
    $c = apr_case_get($ctx, $casePid);
    if ($need === 'view') return $c;
    if ($need === 'write' ? empty($c['perms']['sources']) : !apr_src_can_judge($ctx, $c)) apr_fail('forbidden');
    apr_case_writable($c);
    return $c;
}

function apr_src_perms(array $ctx, array $c): array
{
    $open = $c['status'] !== 'closed';
    return ['write' => $open && !empty($c['perms']['sources']), 'judge' => $open && apr_src_can_judge($ctx, $c)];
}

function apr_src_case_head(array $c): array
{
    return ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title']];
}

function apr_src_check_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row(APR_SRC_CHECK_SELECT . ' WHERE k.pid = ? AND k.case_id = ?', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/** קובץ לצירוף: של התיק הזה, לא נמחק, ושמותר לי לראות. כל השאר — "לא נמצא", כמו מזהה שלא קיים. */
function apr_src_file(array $ctx, array $c, mixed $pid): int
{
    $f = apr_is_pid($pid) ? apr_row('SELECT * FROM files WHERE pid = ? AND case_id = ? AND deleted_at IS NULL', [$pid, $c['id']]) : null;
    if (!$f || !apr_file_visible($ctx, $c, $f)) apr_fail('not_found');
    return (int)$f['id'];
}

/** הקובץ המצורף, לתצוגה: מי שאינו רואה אותו מקבל restricted בלבד. */
function apr_src_file_out(array $ctx, array $c, array $row): ?array
{
    if ($row['file_id'] === null) return null;
    if (!apr_file_visible($ctx, $c, ['id' => (int)$row['file_id'], 'pid' => $row['file_pid'], 'case_id' => $c['id']])) return ['restricted' => true];
    return $row['file_deleted'] !== null ? ['deleted' => true, 'name' => $row['file_name']] : ['pid' => $row['file_pid'], 'name' => $row['file_name']];
}

// ── מזהי הנכס ─────────────────────────────────────────────────

function apr_src_ident_label(array $i): string
{
    $parts = [];
    if ($i['gush'] !== null) $parts[] = 'גוש ' . $i['gush'] . ($i['helka'] !== null ? ' חלקה ' . $i['helka'] : '') . ($i['tat_helka'] !== null ? ' תת-חלקה ' . $i['tat_helka'] : '');
    $addr = trim(implode(' ', array_filter([$i['street'], $i['house_no']], fn($x) => $x !== null && $x !== '')));
    if ($i['apartment_no'] !== null && $i['apartment_no'] !== '') $addr = trim($addr . ' דירה ' . $i['apartment_no']);
    $addr = implode(', ', array_filter([$addr, $i['locality']], fn($x) => $x !== null && $x !== ''));
    if ($addr !== '') $parts[] = $addr;
    if ($i['value_text'] !== null && $i['value_text'] !== '') $parts[] = $i['value_text'];
    return implode(' · ', $parts);
}

function apr_src_idents(int $caseId): array
{
    return apr_rows('SELECT * FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL ORDER BY id', [$caseId]);
}

/** "מזהה נכס מספיק" לבדיקת מקור (§8): גוש וחלקה, או כתובת עם יישוב ורחוב. מזהה אחר אינו מספיק. */
function apr_src_identified(array $idents): bool
{
    foreach ($idents as $i) {
        if ($i['kind'] === 'parcel' && $i['gush'] !== null && $i['helka'] !== null) return true;
        if (in_array($i['kind'], ['address', 'apartment'], true) && $i['locality'] !== null && $i['street'] !== null) return true;
    }
    return false;
}

/** היישובים שבמזהי התיק, מנורמלים. */
function apr_src_localities(array $idents): array
{
    $out = [];
    foreach ($idents as $i) foreach ([$i['municipality'], $i['locality']] as $v) if (apr_src_norm($v) !== '') $out[apr_src_norm($v)] = true;
    return array_keys($out);
}

function apr_src_loc_match(string $a, string $b): bool
{
    return $a === $b || (mb_strlen($a) >= 3 && mb_strlen($b) >= 3 && (str_starts_with($a, $b) || str_starts_with($b, $a)));
}

/**
 * המקורות הרלוונטיים לתיק (§6): הארציים, אלה של הרשות לפי היישוב שבמזהים, והכרטיס הכללי כשליישוב אין כרטיס משלו.
 * תיק בלי יישוב — רק הארציים (מקרה קצה 1).
 */
function apr_src_relevant(array $sources, array $locs): array
{
    $named = array_values(array_filter($sources, fn($s) => $s['region'] === 'municipality' && $s['municipality'] !== null));
    $orphan = false;      // יישוב שאין לו כרטיס משלו
    foreach ($locs as $l) {
        $hit = false;
        foreach ($named as $s) if (apr_src_loc_match(apr_src_norm($s['municipality']), $l)) $hit = true;
        if (!$hit) $orphan = true;
    }
    return array_values(array_filter($sources, function ($s) use ($locs, $orphan) {
        if ($s['region'] === 'national') return true;
        if ($s['municipality'] === null) return $orphan;
        foreach ($locs as $l) if (apr_src_loc_match(apr_src_norm($s['municipality']), $l)) return true;
        return false;
    }));
}

// ── בדיקה: לתשובה ─────────────────────────────────────────────

/**
 * בדיקה לתשובה — **המקום היחיד** שבונה אותה. disclaimer: נוסח ההסתייגות, תמיד, למצבים not_found ו-unavailable (09-A2).
 * ids_changed: הבדיקה נעשתה לפני שמזהה שנבדק בה שונה או הוסר (מקרה קצה 4). no_evidence: "אותר" בלי קישור ובלי קובץ (מקרה קצה 7).
 * $labels: התוויות הנוכחיות של מזהי התיק, לפי pid.
 */
function apr_src_check_out(array $ctx, array $c, array $k, array $labels): array
{
    $q = json_decode($k['query'], true);
    $ids = is_array($q['identifiers'] ?? null) ? $q['identifiers'] : [];
    $changed = false;
    foreach ($ids as $i) if (($labels[$i['pid'] ?? ''] ?? null) !== ($i['label'] ?? '')) $changed = true;
    return ['pid' => $k['pid'], 'source' => $k['source_key'], 'source_name' => $k['source_name'], 'status' => $k['status'], 'disclaimer' => APR_SRC_DISCLAIMERS[$k['status']] ?? null,
        'query' => ['identifiers' => array_map(fn($i) => ['pid' => $i['pid'] ?? null, 'label' => $i['label'] ?? ''], $ids), 'text' => $q['text'] ?? null], 'ids_changed' => $changed,
        'performed_by_kind' => $k['performed_by_kind'], 'performed_by' => $k['performed_name'], 'performed_at' => $k['performed_at'],
        'coverage_note' => $k['coverage_note'], 'result_summary' => $k['result_summary'], 'link_url' => $k['link_url'], 'file' => apr_src_file_out($ctx, $c, $k),
        'limitation' => $k['limitation'], 'no_evidence' => in_array($k['status'], ['found', 'partial', 'verified'], true) && $k['link_url'] === null && $k['file_id'] === null,
        'approved_by' => $k['approved_name'], 'approved_at' => $k['approved_at'], 'verified_by' => $k['verified_name'], 'verified_at' => $k['verified_at'],
        'cost_amount' => $k['cost_amount'] === null ? null : (int)$k['cost_amount'], 'cost_note' => $k['cost_note']];
}

function apr_src_labels(array $idents): array
{
    $out = [];
    foreach ($idents as $i) $out[$i['pid']] = apr_src_ident_label($i);
    return $out;
}

// ── הפרופיל: מקטע "מקורות" (§8) ───────────────────────────────

/** מנקה את מקטע "מקורות" של פרופיל: items [{source, mode, when}], spend_needs_approval. מקור שאינו בקטלוג אינו נדחה כאן — מתריעים בבדיקות. */
function apr_pc_sources(mixed $v): array
{
    $out = ['items' => [], 'spend_needs_approval' => true];
    if ($v === null) return $out;
    if (!is_array($v) || (array_is_list($v) && $v !== [])) apr_fail('content_invalid');
    foreach (apr_pc_list($v['items'] ?? null, 60) as $it) {
        $key = $it['source'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{1,39}\z/', $key) || isset($seen[$key])) apr_fail('content_invalid');
        $seen[$key] = true;
        $out['items'][] = ['source' => $key, 'mode' => apr_pc_enum($it['mode'] ?? null, APR_SRC_MODES, 'manual'), 'when' => apr_pc_condition($it['when'] ?? null)];
    }
    if (array_key_exists('spend_needs_approval', $v) && !is_bool($v['spend_needs_approval'])) apr_fail('content_invalid');
    $out['spend_needs_approval'] = ($v['spend_needs_approval'] ?? true) !== false;
    return $out;
}

/** אזהרות למקטע "מקורות" לפני פרסום: מקור שהוסר או הוסתר בקטלוג (מקרה קצה 10) — לא חוסם; בפתיחת תיק מתעלמים ממנו. */
function apr_pc_sources_checks(array $sources): array
{
    $out = [];
    $known = array_column(apr_sources_all(), 'status', 'key');
    foreach ($sources['items'] ?? [] as $it) {
        if (!isset($known[$it['source']])) $out[] = ['source_unknown', 'המקור "' . $it['source'] . '" אינו קיים בקטלוג — בפתיחת תיק מתעלמים ממנו', 'source:' . $it['source']];
        elseif ($known[$it['source']] !== 'active') $out[] = ['source_hidden', 'המקור "' . $it['source'] . '" הוסתר בקטלוג — בפתיחת תיק מתעלמים ממנו', 'source:' . $it['source']];
    }
    return $out;
}

function apr_src_case_content(array $c): array
{
    return apr_case_version_content((int)$c['profile_version_id'])['sources'] ?? ['items' => [], 'spend_needs_approval' => true];
}

// ── משימות של מקור ────────────────────────────────────────────

/**
 * משימת מערכת לאחראי על התיק, מקושרת לבדיקה — אחת פתוחה לכל מקור ולכל סוג. $what: check (לבדוק ידנית) · retry (השלמה ידנית
 * אחרי בדיקה שלא הושלמה) · approve (אישור של שמאי לפני הבדיקה).
 */
function apr_src_task(array $c, array $s, string $what, string $checkPid): string
{
    $key = "source-$what:" . $s['key'];
    $open = apr_val("SELECT pid FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $key]);
    if ($open) return (string)$open;
    $title = match ($what) {
        'check' => 'בדיקת מקור: ' . $s['name'],
        'retry' => 'השלמה ידנית — הבדיקה לא הושלמה: ' . $s['name'],
        'approve' => 'אישור לפני בדיקת מקור: ' . $s['name'],
    };
    $assignee = apr_val('SELECT c.responsible_user_id FROM cases c JOIN case_members m ON m.case_id = c.id AND m.user_id = c.responsible_user_id AND m.revoked_at IS NULL WHERE c.id = ?', [$c['id']]);
    $pid = apr_pid();
    apr_insert('tasks', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'system', 'title' => $title, 'assignee_user_id' => $assignee ?: null,
        'origin_key' => $key, 'link_type' => 'source_check', 'link_pid' => $checkPid, 'created_by' => null, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
    return $pid;
}

function apr_src_tasks_done(array $c, array $s, array $what): void
{
    foreach ($what as $w) {
        apr_exec("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')",
            [apr_now(), apr_now(), $c['id'], "source-$w:" . $s['key']]);
    }
}

// ── בדיקה: כתיבה ──────────────────────────────────────────────

function apr_src_last(array $c, array $s): ?array
{
    return apr_row('SELECT * FROM source_checks WHERE case_id = ? AND source_id = ? ORDER BY id DESC LIMIT 1', [$c['id'], $s['id']]);
}

/** מוסיף שורה ליומן הבדיקות — המקום היחיד שכותב ל-source_checks. בתוך טרנזקציה. */
function apr_src_insert(array $c, array $s, string $status, array $row, ?int $by): string
{
    $pid = apr_pid();
    apr_insert('source_checks', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'source_id' => $s['id'], 'status' => $status,
        'performed_by_kind' => $by === null ? 'system' : 'user', 'performed_by' => $by, 'performed_at' => apr_now(), 'created_at' => apr_now(), 'updated_at' => apr_now()] + $row);
    apr_case_audit($c, 'source.' . (in_array($status, APR_SRC_RESULTS, true) ? 'check' : 'plan'), 'source_check', $pid, ['source' => $s['key'], 'status' => $status], null, $by, $by === null ? 'system' : 'user');
    apr_case_touch($c);
    return $pid;
}

/**
 * פתיחת תיק: לפי מקטע "מקורות" של הפרופיל (§8). auto ← משימה לבדיקה ידנית והבדיקה planned (אין חיבור — דבר אינו רץ);
 * בלי מזהה נכס מספיק ← awaiting_identification, בלי משימה. ask ← needs_approval ומשימת אישור לשמאי. manual ← כלום: המקור
 * מופיע כ"לא נבדק". מקור שאינו בקטלוג או שהוסתר — מתעלמים. בתוך הטרנזקציה של case-start.
 */
function apr_sources_on_start(array $c, array $content, array $input): int
{
    $n = 0;
    $identified = apr_src_identified(apr_src_idents((int)$c['id']));
    foreach ($content['sources']['items'] ?? [] as $it) {
        if ($it['mode'] === 'manual' || !apr_pc_eval($it['when'] ?? null, $input)) continue;
        $s = apr_row("SELECT * FROM sources WHERE key = ? AND status = 'active'", [$it['source']]);
        if (!$s) continue;
        $status = $it['mode'] === 'ask' ? 'needs_approval' : ($identified ? 'planned' : 'awaiting_identification');
        $pid = apr_src_insert($c, $s, $status, [], null);
        if ($status === 'planned') apr_src_task($c, $s, 'check', $pid);
        if ($status === 'needs_approval') apr_src_task($c, $s, 'approve', $pid);
        $n++;
    }
    return $n;
}

/** נוסף מזהה נכס: כל מקור שהבדיקה האחרונה שלו "ממתין לזיהוי" — אם יש עכשיו מזהה מספיק — מקבל שורת planned ומשימה. בתוך הטרנזקציה. */
function apr_sources_on_identifier(array $c): void
{
    if (!apr_src_identified(apr_src_idents((int)$c['id']))) return;
    foreach (apr_rows("SELECT s.* FROM sources s JOIN source_checks k ON k.source_id = s.id WHERE k.case_id = ? AND k.status = 'awaiting_identification'
        AND k.id = (SELECT MAX(id) FROM source_checks WHERE case_id = k.case_id AND source_id = k.source_id)", [$c['id']]) as $s) {
        if ($s['status'] !== 'active') continue;
        apr_src_task($c, $s, 'check', apr_src_insert($c, $s, 'planned', [], null));
    }
}

/** האם רישום הוצאה למקור הזה בתיק דורש אישור שעוד לא ניתן (§6 "מקור בתשלום"). שמאי בתיק מאשר בעצם הרישום. */
function apr_src_spend_blocked(array $ctx, array $c, array $s, ?int $cost): bool
{
    if ($cost === null || $cost === 0 || apr_src_can_judge($ctx, $c) || !apr_src_case_content($c)['spend_needs_approval']) return false;
    return !apr_val('SELECT 1 FROM source_checks WHERE case_id = ? AND source_id = ? AND approved_at IS NOT NULL LIMIT 1', [$c['id'], $s['id']]);
}

/**
 * "אני מתחיל לבדוק" / בקשת אישור: case, source, spend? (צפויה הוצאה). בלי מזהה נכס מספיק ← awaiting_identification. מקור בתשלום
 * (או spend) כשהפרופיל דורש אישור להוצאה, ומי שמבקש אינו שמאי בתיק ← needs_approval ומשימת אישור. אחרת ← in_progress.
 * פתיחת האתר עצמה אינה פעולה במערכת, ואינה מחייבת דבר.
 */
function apr_source_check_plan(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, $in['case'] ?? null, 'write');
    $s = apr_src_get($in['source'] ?? null);
    if ($s['status'] !== 'active') apr_fail('source_hidden');
    return apr_tx(function () use ($ctx, $c, $s, $in) {
        apr_case_still_open($c);
        $idents = apr_src_idents((int)$c['id']);
        $spend = $s['auth_required'] === 'payment' || ($in['spend'] ?? false) === true;
        $approved = (bool)apr_val('SELECT 1 FROM source_checks WHERE case_id = ? AND source_id = ? AND approved_at IS NOT NULL LIMIT 1', [$c['id'], $s['id']]);
        $last = apr_src_last($c, $s);
        $status = !apr_src_identified($idents) ? 'awaiting_identification'
            : ((($spend && apr_src_case_content($c)['spend_needs_approval'] && !$approved) || ($last && $last['status'] === 'needs_approval')) && !apr_src_can_judge($ctx, $c) ? 'needs_approval' : 'in_progress');
        // אותו מצב פתוח פעמיים ברצף — אין שורה נוספת
        $pid = $last && $last['status'] === $status ? $last['pid'] : apr_src_insert($c, $s, $status, [], (int)$ctx['user']['id']);
        if ($status === 'needs_approval') apr_src_task($c, $s, 'approve', $pid);
        return ['check' => apr_src_check_out($ctx, $c, apr_src_check_row($c, $pid), apr_src_labels($idents))];
    });
}

/**
 * "רישום התוצאה" (§6): case, source, status, identifiers[] (pid של מזהי התיק שנבדקו), query_text?, coverage_note, result_summary,
 * link_url?, file? (pid של קובץ בתיק), limitation?, cost_amount? (אגורות), cost_note?, op? (מזהה פעולה — אותה בקשה פעמיים = בדיקה אחת).
 * **תמיד שורה חדשה** — הבדיקה הקודמת לאותו מקור אינה משתנה (09-A7).
 * not_found / partial בלי coverage_note ← נדחה (09-A3). קובץ למקור ש-license.store שלו no ← נדחה; קישור מתקבל (09-B4).
 * unavailable / needs_login / failed ← משימת השלמה ידנית (09-A1).
 */
function apr_source_check_record(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, $in['case'] ?? null, 'write');
    $s = apr_src_get($in['source'] ?? null);
    if ($s['status'] !== 'active') apr_fail('source_hidden');      // מקרה קצה 5: בדיקות קיימות נשארות; חדשה אי אפשר לפתוח
    $status = apr_src_enum($in['status'] ?? null, APR_SRC_RESULTS, 'check_status');
    $coverage = apr_clean_text($in['coverage_note'] ?? null, 1000);
    $summary = apr_clean_text($in['result_summary'] ?? null, 2000);
    if (in_array($status, ['not_found', 'partial'], true) && $coverage === null) apr_fail('check_coverage_required');
    if (in_array($status, ['found', 'needs_clarification'], true) && $summary === null) apr_fail('check_summary_required');
    $link = apr_src_url($in['link_url'] ?? null);
    $file = null;
    if (($in['file'] ?? null) !== null && $in['file'] !== '') {
        if (apr_src_license($s['license'])['store'] === 'no') apr_fail('source_store_forbidden');
        $file = apr_src_file($ctx, $c, $in['file']);
    }
    $cost = apr_src_cost($in['cost_amount'] ?? null);
    $idPids = $in['identifiers'] ?? [];
    if (!is_array($idPids) || !array_is_list($idPids) || count($idPids) > 50) apr_fail('check_query_required');
    $qText = apr_clean_text($in['query_text'] ?? null, 300);
    $key = apr_upload_key($ctx, $in['op'] ?? null);
    $row = ['coverage_note' => $coverage, 'result_summary' => $summary, 'link_url' => $link, 'file_id' => $file, 'limitation' => apr_clean_text($in['limitation'] ?? null, 1000),
        'cost_amount' => $cost, 'cost_note' => $cost === null ? null : apr_clean_text($in['cost_note'] ?? null, 300), 'idempotency_key' => $key];
    return apr_tx(function () use ($ctx, $c, $s, $status, $row, $idPids, $qText, $key, $cost) {
        apr_case_still_open($c);
        $idents = apr_src_idents((int)$c['id']);
        $labels = apr_src_labels($idents);
        if ($key !== null && ($dup = apr_row('SELECT pid, case_id FROM source_checks WHERE idempotency_key = ?', [$key]))) {
            if ((int)$dup['case_id'] !== (int)$c['id']) apr_fail('not_found');
            return ['check' => apr_src_check_out($ctx, $c, apr_src_check_row($c, $dup['pid']), $labels), 'task' => null, 'repeat' => true];
        }
        $used = [];
        foreach ($idPids as $p) {
            if (!is_string($p) || !isset($labels[$p])) apr_fail('not_found');      // מזהה של תיק אחר, מזהה שהוסר, ומזהה שלא קיים — אותה תשובה
            $used[$p] = ['pid' => $p, 'label' => $labels[$p]];
        }
        // "מה בדיוק חיפשו": תוצאה (אותר / חלקית / לא אותר) בלי אף מזהה ובלי טקסט חיפוש אינה נרשמת
        if (in_array($status, ['found', 'partial', 'not_found'], true) && !$used && $qText === null) apr_fail('check_query_required');
        $last = apr_src_last($c, $s);
        if ($last && $last['status'] === 'needs_approval' && !apr_src_can_judge($ctx, $c)) apr_fail('check_needs_approval');
        if (apr_src_spend_blocked($ctx, $c, $s, $cost)) apr_fail('check_spend_approval');
        $pid = apr_src_insert($c, $s, $status, ['query' => json_encode(['identifiers' => array_values($used), 'text' => $qText], JSON_UNESCAPED_UNICODE)] + $row, (int)$ctx['user']['id']);
        apr_src_tasks_done($c, $s, ['check', 'approve']);
        $task = null;
        if (in_array($status, APR_SRC_RETRY, true)) $task = apr_src_task($c, $s, 'retry', $pid);
        else apr_src_tasks_done($c, $s, ['retry']);
        return ['check' => apr_src_check_out($ctx, $c, apr_src_check_row($c, $pid), $labels), 'task' => $task];
    });
}

/** שמאי בתיק מאשר בדיקה שממתינה לאישור (ask בפרופיל, או הוצאה): שורה חדשה planned עם מי אישר ומתי, ומשימה לבדיקה. */
function apr_source_check_approve(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('source_checks', $in['check'] ?? null), 'judge');
    return apr_tx(function () use ($ctx, $c, $in) {
        apr_case_still_open($c);
        $k = apr_src_check_row($c, $in['check'] ?? null);
        $s = apr_row('SELECT * FROM sources WHERE id = ?', [$k['source_id']]);
        $last = apr_src_last($c, $s);
        if ($k['status'] !== 'needs_approval' || (int)$last['id'] !== (int)$k['id']) apr_fail('check_state');
        if ($s['status'] !== 'active') apr_fail('source_hidden');
        $idents = apr_src_idents((int)$c['id']);
        $status = apr_src_identified($idents) ? 'planned' : 'awaiting_identification';
        $pid = apr_src_insert($c, $s, $status, ['approved_by' => (int)$ctx['user']['id'], 'approved_at' => apr_now()], (int)$ctx['user']['id']);
        apr_src_tasks_done($c, $s, ['approve']);
        if ($status === 'planned') apr_src_task($c, $s, 'check', $pid);
        return ['check' => apr_src_check_out($ctx, $c, apr_src_check_row($c, $pid), apr_src_labels($idents))];
    });
}

/** "נבדק ואומת": רק שמאי בתיק, ורק בדיקה שבמצב "אותר". "לא אותר" ו"מקור לא זמין" אינם ניתנים לאימות — הם אינם ממצא. */
function apr_source_check_verify(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('source_checks', $in['check'] ?? null), 'judge');
    return apr_tx(function () use ($ctx, $c, $in) {
        apr_case_still_open($c);
        $k = apr_src_check_row($c, $in['check'] ?? null);
        if (apr_exec("UPDATE source_checks SET status = 'verified', verified_by = ?, verified_at = ?, updated_at = ? WHERE id = ? AND status = 'found'",
            [(int)$ctx['user']['id'], apr_now(), apr_now(), $k['id']]) !== 1) apr_fail('check_state');
        apr_case_audit($c, 'source.verify', 'source_check', $k['pid'], ['source' => $k['source_key']]);
        apr_case_touch($c);
        return ['check' => apr_src_check_out($ctx, $c, apr_src_check_row($c, $k['pid']), apr_src_labels(apr_src_idents((int)$c['id'])))];
    });
}

/** רישום הוצאה אחרי הבדיקה — פעם אחת (check, cost_amount, cost_note?). בדיקה מאומתת — רק שמאי בתיק נוגע בה. */
function apr_source_check_cost(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('source_checks', $in['check'] ?? null), 'write');
    $cost = apr_src_cost($in['cost_amount'] ?? null) ?? apr_fail('cost_invalid');
    $note = apr_clean_text($in['cost_note'] ?? null, 300);
    return apr_tx(function () use ($ctx, $c, $in, $cost, $note) {
        apr_case_still_open($c);
        $k = apr_src_check_row($c, $in['check'] ?? null);
        if ($k['status'] === 'verified' && !apr_src_can_judge($ctx, $c)) apr_fail('forbidden');
        if (!in_array($k['status'], [...APR_SRC_RESULTS, 'verified'], true)) apr_fail('check_state');
        if (apr_src_spend_blocked($ctx, $c, ['id' => $k['source_id']], $cost)) apr_fail('check_spend_approval');
        if (apr_exec('UPDATE source_checks SET cost_amount = ?, cost_note = ?, updated_at = ? WHERE id = ? AND cost_amount IS NULL', [$cost, $note, apr_now(), $k['id']]) !== 1) apr_fail('check_state');
        apr_case_audit($c, 'source.cost', 'source_check', $k['pid'], ['source' => $k['source_key'], 'amount' => $cost]);
        apr_case_touch($c);
        return ['check' => apr_src_check_out($ctx, $c, apr_src_check_row($c, $k['pid']), apr_src_labels(apr_src_idents((int)$c['id'])))];
    });
}

// ── בדיקה: קריאה ──────────────────────────────────────────────

/**
 * "מקורות ותכנון" של תיק: המקורות הרלוונטיים (וכל מקור שכבר נבדק בתיק, גם אם הוסתר), ולכל אחד — הבדיקה האחרונה, הבדיקה
 * המאומתת האחרונה (נשארת גלויה גם כשנרשמה אחריה בדיקה חדשה), ומספר הבדיקות. מקור בלי בדיקה: status = not_checked, latest = null.
 */
function apr_case_sources(array $ctx, array $q): array
{
    $c = apr_src_case($ctx, $q['case'] ?? null);
    $idents = apr_src_idents((int)$c['id']);
    $labels = apr_src_labels($idents);
    $locs = apr_src_localities($idents);
    $by = [];
    foreach (apr_rows(APR_SRC_CHECK_SELECT . ' WHERE k.case_id = ? ORDER BY k.id', [$c['id']]) as $k) $by[(int)$k['source_id']][] = $k;
    $content = apr_src_case_content($c);
    $modes = array_column($content['items'], 'mode', 'source');
    $all = apr_sources_all();
    $relevant = array_column(apr_src_relevant(array_values(array_filter($all, fn($s) => $s['status'] === 'active')), $locs), 'id');
    $out = [];
    foreach ($all as $s) {
        $rows = $by[(int)$s['id']] ?? [];
        if (!$rows && !in_array($s['id'], $relevant)) continue;
        $last = $rows ? $rows[count($rows) - 1] : null;
        $ver = null;
        foreach ($rows as $k) if ($k['status'] === 'verified') $ver = $k;
        $out[] = apr_source_out($s) + ['mode' => $modes[$s['key']] ?? 'manual', 'check_status' => $last['status'] ?? 'not_checked',
            'latest' => $last ? apr_src_check_out($ctx, $c, $last, $labels) : null,
            'verified' => $ver && $ver !== $last ? apr_src_check_out($ctx, $c, $ver, $labels) : null, 'checks' => count($rows)];
    }
    return ['case' => apr_src_case_head($c), 'perms' => apr_src_perms($ctx, $c), 'identified' => apr_src_identified($idents),
        'locality_hint' => $locs ? null : 'הוסיפו יישוב כדי לראות את מקורות הרשות המקומית',
        'identifiers' => array_map(fn($i) => ['pid' => $i['pid'], 'kind' => $i['kind'], 'label' => $labels[$i['pid']]], $idents),
        'spend_needs_approval' => $content['spend_needs_approval'], 'results' => APR_SRC_RESULTS, 'sources' => $out, 'planning' => apr_planning_summary((int)$c['id'])];
}

/** מקור אחד בתיק: הכרטיס המלא, וכל הבדיקות שנעשו בתיק — מהחדשה לישנה. */
function apr_case_source(array $ctx, array $q): array
{
    $c = apr_src_case($ctx, $q['case'] ?? null);
    $s = apr_src_get($q['source'] ?? null);
    $idents = apr_src_idents((int)$c['id']);
    $labels = apr_src_labels($idents);
    $rows = apr_rows(APR_SRC_CHECK_SELECT . ' WHERE k.case_id = ? AND k.source_id = ? ORDER BY k.id DESC', [$c['id'], $s['id']]);
    // מקור מוסתר נראה בתיק רק אם כבר נבדק בו
    if ($s['status'] !== 'active' && !$rows) apr_fail('not_found');
    $content = apr_src_case_content($c);
    return ['case' => apr_src_case_head($c), 'perms' => apr_src_perms($ctx, $c), 'identified' => apr_src_identified($idents),
        'identifiers' => array_map(fn($i) => ['pid' => $i['pid'], 'kind' => $i['kind'], 'label' => $labels[$i['pid']]], $idents),
        'spend_needs_approval' => $content['spend_needs_approval'], 'results' => APR_SRC_RESULTS,
        'source' => apr_source_out($s) + ['mode' => array_column($content['items'], 'mode', 'source')[$s['key']] ?? 'manual', 'check_status' => $rows[0]['status'] ?? 'not_checked'],
        'checks' => array_map(fn($k) => apr_src_check_out($ctx, $c, $k, $labels), $rows)];
}

/** בדיקה אחת: התוצאה, המקור, הנתונים ופריטי התכנון שנגזרו ממנה, והמשימה הפתוחה שלה. */
function apr_source_check_view(array $ctx, array $q): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('source_checks', $q['check'] ?? null));
    $k = apr_src_check_row($c, $q['check'] ?? null);
    $s = apr_row('SELECT * FROM sources WHERE id = ?', [$k['source_id']]);
    return ['case' => apr_src_case_head($c), 'perms' => apr_src_perms($ctx, $c), 'source' => apr_source_out($s),
        'check' => apr_src_check_out($ctx, $c, $k, apr_src_labels(apr_src_idents((int)$c['id']))),
        'facts' => apr_rows("SELECT f.pid, a.label FROM facts f JOIN attributes a ON a.key = f.attribute_key WHERE f.case_id = ? AND f.source_ref_pid = ? AND f.deleted_at IS NULL ORDER BY f.id", [$c['id'], $k['pid']]),
        'items' => array_map(fn($p) => ['pid' => $p['pid'], 'kind' => $p['kind'], 'title' => $p['title']],
            apr_rows('SELECT pid, kind, title FROM planning_items WHERE case_id = ? AND check_id = ? AND deleted_at IS NULL ORDER BY id', [$c['id'], $k['id']])),
        'tasks' => array_map('apr_task_out', apr_rows(APR_TASK_SELECT . " WHERE t.case_id = ? AND t.link_type = 'source_check' AND t.origin_key LIKE ? AND t.status IN ('open', 'in_progress') ORDER BY t.id",
            [$c['id'], 'source-%:' . $s['key']]))];
}

/**
 * הסיכום של התיק לכרטיס התיק: כמה מקורות נבדקו, אילו בדיקות אחרונות דורשות תשומת לב (כל אחת כבדיקה מלאה — עם נוסח ההסתייגות),
 * וסיכום התכנון. אין כאן שום שדה שמסיק "אין".
 */
function apr_case_sources_summary(array $ctx, array $c): array
{
    $labels = apr_src_labels(apr_src_idents((int)$c['id']));
    $latest = apr_rows(APR_SRC_CHECK_SELECT . ' WHERE k.case_id = ? AND k.id IN (SELECT MAX(id) FROM source_checks WHERE case_id = ? GROUP BY source_id) ORDER BY k.id', [$c['id'], $c['id']]);
    return ['checked' => count(array_filter($latest, fn($k) => in_array($k['status'], [...APR_SRC_RESULTS, 'verified'], true))),
        'attention' => array_values(array_map(fn($k) => apr_src_check_out($ctx, $c, $k, $labels),
            array_filter($latest, fn($k) => !in_array($k['status'], ['found', 'verified', 'planned', 'in_progress'], true)))),
        'planning' => apr_planning_summary((int)$c['id'])];
}

// ── תכנון והיתרים (§7) ────────────────────────────────────────

/**
 * סיכום הרשימה: לכל סוג — כמה נרשמו. סוג בלי אף פריט נושא caveat: "0 נרשמו" אינו "אין" (09-A5). רשימה ריקה כולה — disclaimer.
 * count הוא ספירה של מה שנרשם בתיק, ותו לא: אין כאן שדה שאומר אם יש או אין היתר.
 */
function apr_planning_summary(int $caseId): array
{
    $n = array_column(apr_rows('SELECT kind, COUNT(*) AS n FROM planning_items WHERE case_id = ? AND deleted_at IS NULL GROUP BY kind', [$caseId]), 'n', 'kind');
    $kinds = [];
    foreach (APR_PLAN_KINDS as $k) {
        $count = (int)($n[$k] ?? 0);
        $kinds[] = ['kind' => $k, 'label' => APR_PLAN_LABELS[$k], 'count' => $count, 'text' => APR_PLAN_LABELS[$k] . ': ' . $count . ' נרשמו', 'caveat' => $count === 0 ? APR_PLAN_ZERO : null];
    }
    return ['total' => array_sum($n), 'kinds' => $kinds, 'disclaimer' => array_sum($n) === 0 ? APR_PLAN_EMPTY : null];
}

function apr_plan_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row(APR_PLAN_SELECT . ' WHERE p.pid = ? AND p.case_id = ? AND p.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_plan_out(array $ctx, array $c, array $p): array
{
    return ['pid' => $p['pid'], 'kind' => $p['kind'], 'ref_number' => $p['ref_number'], 'title' => $p['title'], 'status_reported' => $p['status_reported'], 'item_date' => $p['item_date'],
        'source' => $p['source_key'], 'source_name' => $p['source_name'], 'check' => $p['check_pid'], 'url' => $p['url'], 'file' => apr_src_file_out($ctx, $c, $p),
        'related' => $p['related_pid'] === null || $p['related_deleted'] !== null ? null : ['pid' => $p['related_pid'], 'kind' => $p['related_kind'], 'title' => $p['related_title']],
        'applicability' => $p['applicability'], 'applicability_by' => $p['applicability_name'], 'applicability_at' => $p['applicability_at'], 'applicability_note' => $p['applicability_note'],
        'note' => $p['note'], 'created_by' => $p['created_name'], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at']];
}

/** הרשימה של תיק — לפי סוג, כל סוג בנפרד. רשימה של בקשות בלבד אינה מחזירה אף היתר, כי אין מי שיהפוך בקשה להיתר. */
function apr_case_planning(array $ctx, array $q): array
{
    $c = apr_src_case($ctx, $q['case'] ?? null);
    $order = "CASE p.kind WHEN 'plan' THEN 0 WHEN 'application' THEN 1 WHEN 'decision' THEN 2 WHEN 'permit' THEN 3 WHEN 'building_doc' THEN 4 ELSE 5 END";
    return ['case' => apr_src_case_head($c), 'perms' => apr_src_perms($ctx, $c), 'kinds' => APR_PLAN_KINDS, 'applicability' => APR_PLAN_APPLICABILITY,
        'summary' => apr_planning_summary((int)$c['id']),
        'items' => array_map(fn($p) => apr_plan_out($ctx, $c, $p), apr_rows(APR_PLAN_SELECT . " WHERE p.case_id = ? AND p.deleted_at IS NULL ORDER BY $order, (p.item_date IS NULL), p.item_date, p.id", [$c['id']]))];
}

function apr_planning_item(array $ctx, array $q): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('planning_items', $q['item'] ?? null));
    $p = apr_plan_row($c, $q['item'] ?? null);
    return ['case' => apr_src_case_head($c), 'perms' => apr_src_perms($ctx, $c) + ['edit_item' => apr_src_perms($ctx, $c)['write'] && apr_plan_editable($ctx, $c, $p)],
        'applicability' => APR_PLAN_APPLICABILITY, 'item' => apr_plan_out($ctx, $c, $p),
        'tasks' => array_map('apr_task_out', apr_rows(APR_TASK_SELECT . " WHERE t.case_id = ? AND t.link_type = 'planning_item' AND t.link_pid = ? ORDER BY t.id", [$c['id'], $p['pid']])),
        'history' => array_map(fn($r) => ['at' => $r['at'], 'action' => $r['action'], 'by' => $r['name'], 'reason' => $r['reason']],
            apr_rows("SELECT a.at, a.action, a.reason, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.entity_type = 'planning_item' AND a.entity_pid = ? AND a.case_id = ? ORDER BY a.id", [$p['pid'], $c['id']]))];
}

/** פריט שתחולתו נקבעה בידי שמאי — רק שמאי בתיק משנה או מוחק אותו (הלקח מפרוסה 10: מי שאינו שמאי אינו מבטל קביעה של שמאי). */
function apr_plan_editable(array $ctx, array $c, array $p): bool
{
    return $p['applicability'] === 'unknown' || apr_src_can_judge($ctx, $c);
}

/** השדות שאפשר להזין ולעדכן בפריט — **בלי kind**. $base: הערכים הקיימים (בעדכון). */
function apr_plan_fields(array $ctx, array $c, array $in, array $base = []): array
{
    $get = fn(string $k) => array_key_exists($k, $in);
    $row = [];
    if ($get('title') || !$base) $row['title'] = apr_clean_name($in['title'] ?? null, 200);
    if ($get('ref_number')) $row['ref_number'] = apr_clean_text($in['ref_number'], 80);
    if ($get('status_reported')) $row['status_reported'] = apr_clean_text($in['status_reported'], 200);
    if ($get('item_date')) $row['item_date'] = apr_clean_date($in['item_date']);
    if ($get('note')) $row['note'] = apr_clean_text($in['note'], 1000);
    if ($get('url')) $row['url'] = apr_src_url($in['url']);
    $sourceId = $base['source_id'] ?? null;
    if ($get('check')) {
        $row['check_id'] = null;
        if ($in['check'] !== null && $in['check'] !== '') {
            $k = apr_src_check_row($c, $in['check']);                 // בדיקה של תיק אחר — "לא נמצא"
            $row['check_id'] = (int)$k['id'];
            $row['source_id'] = $sourceId = (int)$k['source_id'];
        }
    }
    if ($get('source') && !isset($row['check_id'])) {
        $row['source_id'] = $sourceId = $in['source'] === null || $in['source'] === '' ? null : (int)apr_src_get($in['source'])['id'];
    }
    if ($get('related')) {
        $row['related_id'] = $in['related'] === null || $in['related'] === '' ? null : (int)apr_plan_row($c, $in['related'])['id'];
        if ($row['related_id'] !== null && $row['related_id'] === (int)($base['id'] ?? 0)) apr_fail('not_found');
    }
    if ($get('file')) {
        $row['file_id'] = null;
        if ($in['file'] !== null && $in['file'] !== '') {
            if ($sourceId !== null && apr_src_license((string)apr_val('SELECT license FROM sources WHERE id = ?', [$sourceId]))['store'] === 'no') apr_fail('source_store_forbidden');
            $row['file_id'] = apr_src_file($ctx, $c, $in['file']);
        }
    } elseif ($sourceId !== null && ($base['file_id'] ?? null) !== null && ($get('source') || $get('check'))
        && apr_src_license((string)apr_val('SELECT license FROM sources WHERE id = ?', [$sourceId]))['store'] === 'no') {
        apr_fail('source_store_forbidden');          // פריט עם קובץ אינו עובר למקור שאסור לשמור ממנו
    }
    return $row;
}

/**
 * הוספת פריט: case, kind, title, ref_number?, status_reported?, item_date?, source? / check?, url?, file?, related?, note?.
 * רישום כפול (אותו kind ואותו ref_number בתיק) אינו נחסם — התשובה נושאת duplicate (מקרה קצה 8).
 */
function apr_planning_add(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, $in['case'] ?? null, 'write');
    $kind = apr_src_enum($in['kind'] ?? null, APR_PLAN_KINDS, 'planning_kind');
    return apr_tx(function () use ($ctx, $c, $kind, $in) {
        apr_case_still_open($c);
        $row = apr_plan_fields($ctx, $c, $in);
        $dup = ($row['ref_number'] ?? null) === null ? false
            : apr_val('SELECT pid FROM planning_items WHERE case_id = ? AND kind = ? AND ref_number = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$c['id'], $kind, $row['ref_number']]);
        $pid = apr_pid();
        apr_insert('planning_items', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => $kind, 'created_by' => (int)$ctx['user']['id'],
            'created_at' => apr_now(), 'updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()] + $row);
        apr_case_audit($c, 'planning.add', 'planning_item', $pid, ['kind' => $kind]);
        apr_case_touch($c);
        return ['item' => apr_plan_out($ctx, $c, apr_plan_row($c, $pid)), 'duplicate' => $dup ?: null];
    });
}

/**
 * עדכון פריט: item + השדות שמשתנים. **kind אינו ניתן לשינוי** — בקשה שנושאת kind אחר נדחית (09-A4); היתר הוא פריט חדש.
 * תחולה משתנה רק ב-planning-applicability.
 */
function apr_planning_update(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('planning_items', $in['item'] ?? null), 'write');
    return apr_tx(function () use ($ctx, $c, $in) {
        apr_case_still_open($c);
        $p = apr_plan_row($c, $in['item'] ?? null);
        if (array_key_exists('kind', $in) && $in['kind'] !== $p['kind']) apr_fail('planning_kind_fixed');
        if (array_key_exists('applicability', $in) || array_key_exists('applicability_note', $in)) apr_fail('forbidden');
        if (!apr_plan_editable($ctx, $c, $p)) apr_fail('forbidden');
        $row = apr_plan_fields($ctx, $c, $in, $p);
        if ($row) {
            $row += ['updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()];
            if (apr_exec('UPDATE planning_items SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ? AND deleted_at IS NULL', [...array_values($row), $p['id']]) !== 1) apr_fail('not_found');
            apr_case_audit($c, 'planning.update', 'planning_item', $p['pid'], ['fields' => array_values(array_diff(array_keys($row), ['updated_by', 'updated_at']))]);
            apr_case_touch($c);
        }
        return ['item' => apr_plan_out($ctx, $c, apr_plan_row($c, $p['pid']))];
    });
}

/** קביעת תחולה — רק שמאי בתיק, ותמיד עם הערה (item, applicability, note). חזרה ל"לא ידוע" — גם היא קביעה של שמאי. */
function apr_planning_applicability(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('planning_items', $in['item'] ?? null), 'judge');
    $to = apr_src_enum($in['applicability'] ?? null, APR_PLAN_APPLICABILITY, 'planning_applicability');
    $note = apr_reason($in['note'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $to, $note) {
        apr_case_still_open($c);
        $p = apr_plan_row($c, $in['item'] ?? null);
        if (apr_exec('UPDATE planning_items SET applicability = ?, applicability_by = ?, applicability_at = ?, applicability_note = ?, updated_by = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$to, (int)$ctx['user']['id'], apr_now(), $note, (int)$ctx['user']['id'], apr_now(), $p['id']]) !== 1) apr_fail('not_found');
        apr_case_audit($c, 'planning.applicability', 'planning_item', $p['pid'], ['from' => $p['applicability'], 'to' => $to], $note);
        apr_case_touch($c);
        return ['item' => apr_plan_out($ctx, $c, apr_plan_row($c, $p['pid']))];
    });
}

/** מחיקה רכה, עם נימוק. השורה נשארת. */
function apr_planning_delete(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('planning_items', $in['item'] ?? null), 'write');
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $reason) {
        apr_case_still_open($c);
        $p = apr_plan_row($c, $in['item'] ?? null);
        if (!apr_plan_editable($ctx, $c, $p)) apr_fail('forbidden');
        if (apr_exec('UPDATE planning_items SET deleted_at = ?, deleted_by = ?, delete_reason = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [apr_now(), (int)$ctx['user']['id'], $reason, apr_now(), $p['id']]) !== 1) apr_fail('not_found');
        apr_exec("UPDATE tasks SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE case_id = ? AND link_type = 'planning_item' AND link_pid = ? AND status IN ('open', 'in_progress')",
            ['הפריט נמחק', apr_now(), $c['id'], $p['pid']]);
        apr_case_audit($c, 'planning.delete', 'planning_item', $p['pid'], ['kind' => $p['kind']], $reason);
        apr_case_touch($c);
        return [];
    });
}

/** משימת "אי התאמה אפשרית" לפריט — אחת פתוחה לכל פריט. זו משימה לבירור, לא קביעה: אין במערכת שדה "עבירה" ואין שדה "חוקי". */
function apr_planning_task(array $ctx, array $in): array
{
    $c = apr_src_case($ctx, apr_doc_case_pid('planning_items', $in['item'] ?? null), 'write');
    $detail = apr_clean_text($in['detail'] ?? null, 1000);
    return apr_tx(function () use ($c, $in, $detail) {
        apr_case_still_open($c);
        $p = apr_plan_row($c, $in['item'] ?? null);
        $key = 'planning:' . $p['pid'];
        $open = apr_val("SELECT pid FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $key]);
        if ($open) return ['task' => (string)$open, 'existing' => true];
        $assignee = apr_val('SELECT c.responsible_user_id FROM cases c JOIN case_members m ON m.case_id = c.id AND m.user_id = c.responsible_user_id AND m.revoked_at IS NULL WHERE c.id = ?', [$c['id']]);
        $pid = apr_pid();
        apr_insert('tasks', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'clarification', 'title' => 'אי התאמה אפשרית — לבירור: ' . $p['title'], 'detail' => $detail,
            'assignee_user_id' => $assignee ?: null, 'origin_key' => $key, 'link_type' => 'planning_item', 'link_pid' => $p['pid'], 'created_by' => apr_actor(), 'created_at' => apr_now(), 'updated_at' => apr_now()]);
        apr_case_audit($c, 'planning.task', 'planning_item', $p['pid'], ['task' => $pid]);
        apr_case_touch($c);
        return ['task' => $pid, 'existing' => false];
    });
}
