<?php
/**
 * פרוסה 15 — עסקאות השוואה (docs/spec/15-comparables.md): רישום חיפושים, עסקאות (ידנית / ייבוא — lib/comp_import.php), מצבי עסקה,
 * התאמות מנומקות, וקבוצות השוואה. **החישוב עצמו — lib/comp_calc.php בלבד**: כאן רק קוראים מהמסד, מוסרים למנוע שלמים, ומציגים.
 * המערכת אינה בוחרת עסקאות, אינה קובעת משקולות ואינה ממלאת מסקנה — כל אלה נכתבים רק מקלט של שמאי בתיק.
 *
 * הרשאות (§9), במקום אחד — apr_comp_case():
 *   view   — view בתיק (ושותף חיצוני — רק עם see_internal; apr_comp_visible)
 *   write  — edit: רישום חיפוש, הזנה, ייבוא, תיקון, הערה
 *   judge  — edit + שמאי במשרד שהוא האחראי או שותף בתיק: בחירה, החרגה, התאמות, משקולות, שיטה, מסקנה
 * מזהה של משרד אחר, של תיק שאינני משתתף בו ומזהה שלא קיים — אותו 404 (15-C2). תיק סגור — קריאה בלבד.
 *
 * מספרים: שלמים בלבד, בלי נקודה צפה. מחיר — אגורות; שטח וחדרים — מאיות; אחוז ומשקל — מיליוניות. קלט עשרוני נקרא כמחרוזת
 * (apr_comp_dec), פלט — מחרוזת (apr_comp_dec_out). שדה שלא הוזן הוא NULL ומוחזר null — "לא ידוע" אינו אפס (15-B2).
 * raw אינו נערך לעולם (טריגר במיגרציה 012); תיקון נרשם ב-corrected, עם הערך הקודם, מי, מתי ולמה (15-B1).
 */

declare(strict_types=1);

require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/visits.php';       // AprVisitError — שגיאה שנושאת פרטים (current)
require_once __DIR__ . '/comp_calc.php';

const APR_COMP_STATUSES = ['candidate', 'selected', 'excluded', 'pending'];
const APR_COMP_PRECISIONS = ['exact', 'street', 'block', 'locality', 'unknown'];
const APR_COMP_PRECISE = ['exact', 'street'];           // רק ברמות האלה מותר מרחק, ורק בהן הטקסט מוצג ככתובת (§4.3)
const APR_COMP_CURRENCIES = ['ILS', 'USD', 'EUR', 'other'];
const APR_COMP_METHODS = ['mean', 'weighted', 'median', 'range_only', 'manual'];
const APR_COMP_ROUNDINGS = ['none', '1', '100', '1000', '10000'];
const APR_COMP_BASES = ['total', 'per_sqm'];
const APR_COMP_SET_AREA_KINDS = ['registered', 'built', 'gross', 'net', 'primary', 'other'];
/** שדות הערך של עסקה — אלה שמזינים, מייבאים ומתקנים. הסדר הוא סדר התצוגה. */
const APR_COMP_FIELDS = ['deal_date', 'price', 'currency', 'address_text', 'locality', 'gush', 'helka', 'tat_helka', 'location_precision', 'distance_m',
    'property_type', 'area', 'area_kind', 'rooms', 'floor', 'floors_total', 'build_year', 'condition', 'attachments', 'source_ref'];
/** שדות ש"חסר" בהם מוצג במפורש ברשימת השדות החסרים (§4.3). */
const APR_COMP_CORE = ['deal_date', 'price', 'address_text', 'locality', 'property_type', 'area', 'area_kind', 'rooms', 'floor', 'floors_total', 'build_year', 'condition'];
const APR_COMP_TEXT_MAX = ['address_text' => 200, 'locality' => 80, 'gush' => 20, 'helka' => 20, 'tat_helka' => 20, 'property_type' => 80, 'condition' => 200, 'attachments' => 300, 'source_ref' => 120];
const APR_COMP_ADJ_MAX = 30;
const APR_COMP_SET_MAX = 20;
const APR_COMP_SELECT = 'SELECT k.*, cb.name AS created_name, ub.name AS updated_name, s.pid AS search_pid, d.pid AS dup_pid
    FROM comps k LEFT JOIN users cb ON cb.id = k.created_by LEFT JOIN users ub ON ub.id = k.updated_by
    LEFT JOIN comp_searches s ON s.id = k.search_id LEFT JOIN comps d ON d.id = k.dup_of AND d.deleted_at IS NULL';

// ── גישה ──────────────────────────────────────────────────────

/**
 * עסקאות וחישובים הם "תחשיב פנימי": שותף חיצוני (פרוסה 05) רואה אותם רק עם see_internal (15-C3). עד ש-05 תיבנה אין בתיק תפקיד
 * כזה — הכלל כבר כאן, כדי ש-05 לא תצטרך לזכור אותו.
 */
function apr_comp_visible(array $c): bool
{
    return apr_can($c, 'comps');      // ההכרעה עצמה ב-lib/shares.php: בהיקף השיתוף, ועם see_internal
}

/** מי רשאי לבחור, להחריג, להתאים, לשקלל ולהסיק: edit בתיק + שמאי במשרד שהוא האחראי או שותף בתיק (§9, 15-C4). */
function apr_comp_can_judge(array $ctx, array $c): bool
{
    return !empty($c['perms']['edit']) && in_array($c['my_role'], ['responsible', 'partner'], true) && ($ctx['office']['role'] ?? '') === 'appraiser';
}

/** התיק לפעולה על עסקאות. $need: view | write | judge. פעולה משנה על תיק סגור ← 409. */
function apr_comp_case(array $ctx, mixed $casePid, string $need = 'view'): array
{
    $c = apr_case_get($ctx, $casePid);
    if (!apr_comp_visible($c)) apr_fail('not_found');
    if ($need === 'view') return $c;
    if (empty($c['perms']['edit']) || ($need === 'judge' && !apr_comp_can_judge($ctx, $c))) apr_fail('forbidden');
    apr_case_writable($c);
    return $c;
}

function apr_comp_perms(array $ctx, array $c): array
{
    $open = $c['status'] !== 'closed';
    return ['write' => $open && !empty($c['perms']['edit']), 'judge' => $open && apr_comp_can_judge($ctx, $c)];
}

function apr_comp_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row(APR_COMP_SELECT . ' WHERE k.pid = ? AND k.case_id = ? AND k.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_comp_set_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT g.*, ub.name AS updated_name, cb.name AS conclusion_name FROM comp_sets g LEFT JOIN users ub ON ub.id = g.updated_by LEFT JOIN users cb ON cb.id = g.conclusion_by
        WHERE g.pid = ? AND g.case_id = ? AND g.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_comp_search_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM comp_searches WHERE pid = ? AND case_id = ?', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_comp_rev(mixed $v): int
{
    if (!is_int($v) || $v < 1) apr_fail('rev_required');
    return $v;
}

/** העדכון היחיד של עסקה: rev + 1, רק אם rev לא השתנה. אפס שורות ← 409 "עודכן בידי <שם>", עם העסקה שבשרת; שום דבר לא נכתב. */
function apr_comp_update(array $ctx, array $c, array $k, int $rev, array $set): void
{
    $set += ['updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()];
    $n = apr_exec('UPDATE comps SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ', rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL', [...array_values($set), $k['id'], $rev]);
    if ($n === 1) return;
    $cur = apr_comp_row($c, $k['pid']);
    throw new AprVisitError('comp_changed', ['current' => apr_comp_out($cur), 'by' => $cur['updated_name']], apr_comp_changed_msg($cur['updated_name']));
}

function apr_comp_changed_msg(?string $name): string
{
    return $name !== null && $name !== '' ? 'עודכן בידי ' . $name . ' — רעננו ונסו שוב' : APR_ERRORS['comp_changed'][1];
}

function apr_comp_set_update(array $ctx, array $c, array $g, int $rev, array $set): void
{
    $set += ['updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()];
    $n = apr_exec('UPDATE comp_sets SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ', rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL', [...array_values($set), $g['id'], $rev]);
    if ($n === 1) return;
    $cur = apr_comp_set_row($c, $g['pid']);
    throw new AprVisitError('comp_changed', ['current' => apr_comp_set_out($cur), 'by' => $cur['updated_name']], apr_comp_changed_msg($cur['updated_name']));
}

// ── ערכים ─────────────────────────────────────────────────────

/**
 * מספר עשרוני מהקלט ← שלם מוגדל ב-10^$scale, בלי לעבור דרך נקודה צפה. null / '' ← null ("לא ידוע"). אין מספר שלילי:
 * הסימן של התאמה הוא שדה נפרד. מקבל שלם, מחרוזת עשרונית, או מספר JSON שהייצוג הקצר שלו עשרוני פשוט.
 */
function apr_comp_dec(mixed $v, int $scale, int $digits, string $err = 'comp_value'): ?int
{
    if ($v === null || $v === '') return null;
    if (is_int($v)) $s = (string)$v;
    elseif (is_float($v) && is_finite($v)) $s = (string)json_encode($v);
    elseif (is_string($v)) $s = trim($v);
    else apr_fail($err);
    if (preg_match('/^(\d{1,' . $digits . '})(?:\.(\d{0,' . $scale . '})0*)?\z/', $s, $m) !== 1) apr_fail($err);
    return (int)$m[1] * 10 ** $scale + (int)str_pad($m[2] ?? '', $scale, '0');
}

/** שלם מוגדל ← מחרוזת עשרונית מדויקת, בלי אפסים מיותרים ("78.5", "2000000", "0"). null נשאר null. */
function apr_comp_dec_out(mixed $n, int $scale): ?string
{
    if ($n === null) return null;
    if ($scale === 0) return (string)(int)$n;
    $u = str_pad((string)abs((int)$n), $scale + 1, '0', STR_PAD_LEFT);
    $frac = rtrim(substr($u, -$scale), '0');
    return ((int)$n < 0 ? '-' : '') . substr($u, 0, strlen($u) - $scale) . ($frac === '' ? '' : '.' . $frac);
}

function apr_comp_int(mixed $v, int $min, int $max, string $err = 'comp_value'): ?int
{
    if ($v === null || $v === '') return null;
    if (is_string($v) && preg_match('/^-?\d{1,9}\z/', trim($v)) === 1) $v = (int)trim($v);
    if (!is_int($v) || $v < $min || $v > $max) apr_fail($err);
    return $v;
}

function apr_comp_enum(mixed $v, array $allowed, string $default, string $err = 'comp_value'): string
{
    if ($v === null || $v === '') return $default;
    if (!is_string($v) || !in_array($v, $allowed, true)) apr_fail($err);
    return $v;
}

/** ערך של שדה אחד מהקלט ← הערך שנשמר בעמודה. null = לא ידוע. */
function apr_comp_clean_field(string $f, mixed $v): mixed
{
    return match ($f) {
        'deal_date' => apr_clean_date($v),
        'price' => apr_comp_dec($v, 2, 11),
        'area' => apr_comp_dec($v, 2, 7),
        'rooms' => apr_comp_dec($v, 2, 3),
        'currency' => apr_comp_enum($v, APR_COMP_CURRENCIES, 'ILS'),
        'location_precision' => apr_comp_enum($v, APR_COMP_PRECISIONS, 'unknown'),
        'area_kind' => apr_comp_enum($v, APR_AREA_KINDS, 'unknown'),
        'distance_m' => apr_comp_int($v, 0, 1000000),
        'floor' => apr_comp_int($v, -20, 300),
        'floors_total' => apr_comp_int($v, 0, 300),
        'build_year' => apr_comp_int($v, 1500, 2200),
        default => isset(APR_COMP_TEXT_MAX[$f]) ? (is_scalar($v) || $v === null ? apr_clean_text($v === null ? '' : (string)$v, APR_COMP_TEXT_MAX[$f]) : apr_fail('comp_value')) : apr_fail('comp_field'),
    };
}

/** ערך מהעמודה ← הערך לתשובה: מספר עשרוני כמחרוזת, שלם כשלם, null נשאר null. */
function apr_comp_field_out(string $f, mixed $v): mixed
{
    if ($v === null) return null;
    return match ($f) {
        'price', 'area', 'rooms' => apr_comp_dec_out($v, 2),
        'distance_m', 'floor', 'floors_total', 'build_year' => (int)$v,
        default => (string)$v,
    };
}

/** כל שדות הערך מהקלט. מיקום שאינו מדויק ← בלי מרחק (15-B3). */
function apr_comp_values(mixed $in): array
{
    if (!is_array($in)) apr_fail('comp_value');
    if (array_diff(array_keys($in), APR_COMP_FIELDS)) apr_fail('comp_field');
    $v = [];
    foreach (APR_COMP_FIELDS as $f) $v[$f] = apr_comp_clean_field($f, $in[$f] ?? null);
    if (!in_array($v['location_precision'], APR_COMP_PRECISE, true)) $v['distance_m'] = null;
    return $v;
}

function apr_comp_norm_text(?string $s): string
{
    return trim((string)preg_replace('/[\s,."\'\-־–\/]+/u', ' ', mb_strtolower((string)$s)));
}

/**
 * חשד לכפילות (§4.4): אותם תאריך, מחיר ומטבע — ואותם גוש / חלקה / תת-חלקה, או אותה כתובת. בלי תאריך או בלי מחיר אין חשד.
 * מחזיר את ה-id של העסקה הוותיקה שמתאימה, או null. **התרעה בלבד** — שום דבר אינו נמחק ואינו מאוחד (15-B4).
 */
function apr_comp_find_dup(array $c, array $v, int $selfId): ?int
{
    if ($v['deal_date'] === null || $v['price'] === null) return null;
    $addr = apr_comp_norm_text($v['address_text']);
    foreach (apr_rows('SELECT id, address_text, locality, gush, helka, tat_helka FROM comps WHERE case_id = ? AND deleted_at IS NULL AND id <> ? AND deal_date = ? AND price = ? AND currency = ? ORDER BY id',
        [$c['id'], $selfId, $v['deal_date'], $v['price'], $v['currency']]) as $o) {
        if ($v['gush'] !== null && $v['helka'] !== null && $o['gush'] === $v['gush'] && $o['helka'] === $v['helka'] && $o['tat_helka'] === $v['tat_helka']) return (int)$o['id'];
        if ($addr !== '' && apr_comp_norm_text($o['address_text']) === $addr
            && ($v['locality'] === null || $o['locality'] === null || apr_comp_norm_text($o['locality']) === apr_comp_norm_text($v['locality']))) return (int)$o['id'];
    }
    return null;
}

/** שורה חדשה ב-comps. בתוך טרנזקציה. מחזיר [id, pid, dup_of (id | null)]. */
function apr_comp_insert(array $c, array $v, string $origin, array $raw, ?int $searchId, array $extra, int $uid): array
{
    $pid = apr_pid();
    $dup = apr_comp_find_dup($c, $v, 0);
    $now = apr_now();
    $id = apr_insert('comps', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'search_id' => $searchId, 'origin' => $origin,
        'raw' => json_encode((object)$raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        'extra' => json_encode((object)$extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        'dup_of' => $dup, 'created_by' => $uid, 'updated_by' => $uid, 'created_at' => $now, 'updated_at' => $now] + $v);
    return [$id, $pid, $dup];
}

/** אילו שדות חסרים בעסקה — מוצג במפורש (§4.3). area_kind = unknown נחשב חסר. */
function apr_comp_missing(array $k): array
{
    $out = [];
    foreach (APR_COMP_CORE as $f) {
        if ($f === 'area_kind' ? $k['area_kind'] === 'unknown' && $k['area'] !== null : $k[$f] === null) $out[] = $f;
    }
    // מיקום: גוש וחלקה מספיקים במקום כתובת
    if ($k['gush'] !== null && $k['helka'] !== null) $out = array_values(array_diff($out, ['address_text']));
    return $out;
}

/**
 * עסקה לתשובה. values — הערכים שבשימוש (אחרי תיקונים); raw — רק ב-$full. מיקום מקורב אינו מוחזר ככתובת: address הוא null,
 * והטקסט שהוזן מוחזר ב-approx_location (15-D3). מרחק קיים רק במיקום מדויק או ברמת רחוב — ה-CHECK במיגרציה אוכף זאת.
 */
function apr_comp_out(array $k, bool $full = false, array $names = []): array
{
    $precise = in_array($k['location_precision'], APR_COMP_PRECISE, true);
    $vals = [];
    foreach (APR_COMP_FIELDS as $f) $vals[$f] = apr_comp_field_out($f, $k[$f]);
    $vals['address'] = $precise ? $vals['address_text'] : null;
    $vals['approx_location'] = $precise ? null : $vals['address_text'];
    unset($vals['address_text']);
    $corr = json_decode((string)$k['corrected'], true) ?: [];
    foreach ($corr as &$x) $x['by'] = $names[(int)($x['by'] ?? 0)] ?? null;
    unset($x);
    $out = ['pid' => $k['pid'], 'rev' => (int)$k['rev'], 'origin' => $k['origin'], 'status' => $k['status'], 'status_reason' => $k['status_reason'], 'status_at' => $k['status_at'],
        'weight' => apr_comp_dec_out($k['weight'], 6), 'note' => $k['note'], 'search' => $k['search_pid'] ?? null, 'dup_of' => $k['dup_pid'] ?? null,
        'values' => $vals, 'missing' => apr_comp_missing($k), 'corrected' => (object)$corr, 'corrected_fields' => array_keys($corr),
        'created_by' => $k['created_name'] ?? null, 'created_at' => $k['created_at'], 'updated_by' => $k['updated_name'] ?? null, 'updated_at' => $k['updated_at']];
    if ($full) $out += ['raw' => json_decode((string)$k['raw'], true) ?: (object)[], 'extra' => json_decode((string)$k['extra'], true) ?: (object)[]];
    return $out;
}

/** שמות המשתמשים שתיקנו שדות בעסקאות האלה: id => שם. */
function apr_comp_corrector_names(array $rows): array
{
    $ids = [];
    foreach ($rows as $k) foreach (json_decode((string)$k['corrected'], true) ?: [] as $x) $ids[(int)($x['by'] ?? 0)] = true;
    unset($ids[0]);
    if (!$ids) return [];
    $in = implode(', ', array_fill(0, count($ids), '?'));
    return array_column(apr_rows("SELECT id, name FROM users WHERE id IN ($in)", array_keys($ids)), 'name', 'id');
}

// ── הנכס הנישום: "מה דומה ומה שונה" (§4.3) ────────────────────

/**
 * הערכים הידועים של הנכס הנישום, מנתוני התיק (פרוסה 10): לכל מאפיין — ערך רק אם יש נתון פעיל וכל הנתונים הפעילים שקולים.
 * סתירה פתוחה או היעדר נתון ← אין ערך (לא ידוע). שטח — לכל סוג שטח בנפרד. מחזיר [מאפיין => ערך מנורמל | ['area' => [סוג => מאיות]]].
 */
function apr_comp_subject(array $c): array
{
    $keys = ['property_type', 'area', 'floor', 'floors_total', 'rooms', 'year_built'];
    $in = implode(', ', array_fill(0, count($keys), '?'));
    $by = [];
    foreach (apr_rows("SELECT * FROM facts WHERE case_id = ? AND deleted_at IS NULL AND state <> 'rejected' AND attribute_key IN ($in) ORDER BY id", [$c['id'], ...$keys]) as $f) {
        if (apr_fact_has_value($f)) $by[$f['attribute_key']][] = $f;
    }
    $out = array_fill_keys($keys, null);
    $out['area'] = [];
    foreach ($by as $key => $facts) {
        $attr = apr_attr($key);
        $groups = [];
        foreach ($facts as $f) $groups[$key === 'area' ? (string)($f['area_kind'] ?? 'unknown') : ''][] = apr_fact_norm($f, $attr);
        foreach ($groups as $kind => $norms) {
            if (count(array_unique(array_map('json_encode', $norms))) !== 1 || $norms[0][0] === null) continue;      // נתונים שאינם שקולים: לא ידוע
            if ($key === 'area') {
                if ($kind !== 'unknown' && $kind !== '') $out['area'][$kind] = $norms[0][1];
            } else {
                $out[$key] = $norms[0][1];
            }
        }
    }
    return $out;
}

/**
 * השוואה לנכס הנישום, **רק בשדות ששני הצדדים ידועים בהם**. rows: {field, comp, subject, verdict: same | differs, area_kind?};
 * unknown: {field, side: comp | subject | both | area_kind} — שדה חסר מוצג כ"לא ידוע", ולעולם לא כדומה או כשונה (15-B2).
 * אין ניחוש: מצב הנכס וזכויות אינם מושווים כלל.
 */
function apr_comp_similar(array $k, array $subject): array
{
    $rows = [];
    $unknown = [];
    $pairs = ['property_type' => 'property_type', 'floor' => 'floor', 'floors_total' => 'floors_total', 'rooms' => 'rooms', 'build_year' => 'year_built'];
    foreach ($pairs as $field => $attr) {
        $cv = $k[$field];
        $sv = $subject[$attr] ?? null;
        if ($cv === null || $sv === null) {
            $unknown[] = ['field' => $field, 'side' => $cv === null ? ($sv === null ? 'both' : 'comp') : 'subject'];
            continue;
        }
        // נתוני התיק נשמרים במאיות (קומה 4 = 400); בעסקה — קומה ושנה כשלם, חדרים במאיות
        $cmpComp = $field === 'property_type' ? apr_comp_norm_text((string)$cv) : (in_array($field, ['floor', 'floors_total', 'build_year'], true) ? (int)$cv * 100 : (int)$cv);
        $cmpSubj = $field === 'property_type' ? apr_comp_norm_text((string)$sv) : (int)$sv;
        $rows[] = ['field' => $field, 'comp' => apr_comp_field_out($field, $cv), 'subject' => $field === 'property_type' ? (string)$sv : apr_fact_num_out($sv),
            'verdict' => $cmpComp === $cmpSubj ? 'same' : 'differs'];
    }
    // שטח: רק מול שטח נישום מאותו סוג. אין המרה בין סוגי שטח (§6.3)
    if ($k['area'] === null || (int)$k['area'] <= 0) $unknown[] = ['field' => 'area', 'side' => $subject['area'] ? 'comp' : 'both'];
    elseif ($k['area_kind'] === 'unknown' || !isset($subject['area'][$k['area_kind']])) $unknown[] = ['field' => 'area', 'side' => !$subject['area'] ? 'subject' : 'area_kind'];
    else $rows[] = ['field' => 'area', 'comp' => apr_comp_dec_out($k['area'], 2), 'subject' => apr_fact_num_out($subject['area'][$k['area_kind']]), 'area_kind' => $k['area_kind'],
        'verdict' => (int)$k['area'] === (int)$subject['area'][$k['area_kind']] ? 'same' : 'differs'];
    if ($k['condition'] === null) $unknown[] = ['field' => 'condition', 'side' => 'comp'];
    return ['rows' => $rows, 'unknown' => $unknown];
}

// ── חיפושים (§4.1) ────────────────────────────────────────────

/** הקריטריונים של חיפוש: רק מפתחות מוכרים; כל אחד רשות. */
function apr_comp_criteria(mixed $v): array
{
    if ($v === null) return [];
    if (!is_array($v) || (array_is_list($v) && $v !== [])) apr_fail('comp_value');
    $text = ['area_text' => 200, 'gush' => 20, 'helka' => 20, 'property_type' => 80, 'other' => 300];
    $out = [];
    foreach ($v as $key => $val) {
        if ($val === null || $val === '') continue;
        $out[$key] = match (true) {
            isset($text[$key]) => apr_clean_text(is_scalar($val) ? (string)$val : apr_fail('comp_value'), $text[$key]),
            in_array($key, ['date_from', 'date_to'], true) => apr_clean_date($val),
            in_array($key, ['area_min', 'area_max'], true) => apr_comp_dec_out(apr_comp_dec($val, 2, 7), 2),
            in_array($key, ['rooms_min', 'rooms_max'], true) => apr_comp_dec_out(apr_comp_dec($val, 2, 3), 2),
            default => apr_fail('comp_field'),
        };
    }
    return array_filter($out, fn($x) => $x !== null);
}

function apr_comp_search_out(array $ctx, array $c, array $s, array $counts = []): array
{
    $src = $s['source_id'] !== null ? apr_row('SELECT key, name, url FROM sources WHERE id = ?', [$s['source_id']]) : null;
    $file = $s['file_id'] !== null ? apr_row('SELECT * FROM files WHERE id = ?', [$s['file_id']]) : null;
    return ['pid' => $s['pid'], 'rev' => (int)$s['rev'], 'source' => $src ? ['key' => $src['key'], 'name' => $src['name'], 'url' => $src['url']] : null, 'source_text' => $s['source_text'],
        'criteria' => json_decode($s['criteria'], true) ?: (object)[], 'by' => apr_val('SELECT name FROM users WHERE id = ?', [$s['performed_by']]) ?: null, 'at' => $s['performed_at'],
        'result_count' => $s['result_count'] === null ? null : (int)$s['result_count'], 'limitations' => $s['limitations'],
        'import' => $s['file_id'] !== null || $s['import_sha256'] !== null ? ['name' => $file ? $file['original_name'] : $s['import_name'], 'rows' => $s['import_rows'] === null ? null : (int)$s['import_rows'],
            'file' => $file && $file['deleted_at'] === null && apr_file_visible($ctx, $c, $file) ? $file['pid'] : null] : null,
        'rights_confirmed' => $s['rights_by'] !== null, 'comps' => (int)($counts[(int)$s['id']] ?? 0)];
}

function apr_comp_search_fields(array $in, array $base = []): array
{
    $set = [];
    if (array_key_exists('source', $in) || array_key_exists('source_text', $in) || !$base) {
        $key = $in['source'] ?? null;
        $src = $key !== null && $key !== '' ? apr_src_get($key) : null;
        if ($src && $src['status'] !== 'active' && (int)$src['id'] !== (int)($base['source_id'] ?? 0)) apr_fail('source_hidden');
        $set['source_id'] = $src ? (int)$src['id'] : null;
        $set['source_text'] = apr_clean_text($in['source_text'] ?? '', 200);
        if ($set['source_id'] === null && $set['source_text'] === null) apr_fail('comp_search_source');
    }
    if (array_key_exists('criteria', $in) || !$base) $set['criteria'] = json_encode((object)apr_comp_criteria($in['criteria'] ?? null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (array_key_exists('result_count', $in) || !$base) $set['result_count'] = apr_comp_int($in['result_count'] ?? null, 0, 10000000);
    if (array_key_exists('limitations', $in) || !$base) $set['limitations'] = apr_clean_text($in['limitations'] ?? '', 500);
    return $set;
}

/** comp-search-add {case, source? | source_text?, criteria{}, result_count?, limitations?} — רישום חיפוש: מה חיפשו, איפה, מתי וכמה נמצא. */
function apr_comp_search_add(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, $in['case'] ?? null, 'write');
    $set = apr_comp_search_fields($in);
    return apr_tx(function () use ($ctx, $c, $set) {
        $pid = apr_pid();
        $now = apr_now();
        apr_insert('comp_searches', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'performed_by' => (int)$ctx['user']['id'], 'performed_at' => $now, 'created_at' => $now, 'updated_at' => $now] + $set);
        apr_case_audit($c, 'comp.search', 'comp_search', $pid, ['result_count' => $set['result_count']]);
        apr_case_touch($c);
        return ['search' => $pid];
    });
}

/** comp-search-update {search, rev, …אותם שדות, רק מה שנשלח} — מי ומתי חיפשו אינם משתנים. */
function apr_comp_search_update(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comp_searches', $in['search'] ?? null), 'write');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($c, $in, $rev) {
        $s = apr_comp_search_row($c, $in['search'] ?? null);
        $set = apr_comp_search_fields($in, $s);
        $changed = array_filter($set, fn($v, $f) => $v !== ($f === 'source_id' || $f === 'result_count' ? ($s[$f] === null ? null : (int)$s[$f]) : $s[$f]), ARRAY_FILTER_USE_BOTH);
        if (!$changed) apr_fail('nothing_to_change');
        if (apr_exec('UPDATE comp_searches SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($changed))) . ', updated_at = ?, rev = rev + 1 WHERE id = ? AND rev = ?', [...array_values($changed), apr_now(), $s['id'], $rev]) !== 1) apr_fail('comp_changed');
        apr_case_audit($c, 'comp.search_update', 'comp_search', $s['pid'], ['fields' => array_keys($changed)]
            + (array_key_exists('result_count', $changed) ? ['from' => $s['result_count'] === null ? null : (int)$s['result_count'], 'to' => $changed['result_count']] : []));
        return ['search' => $s['pid'], 'rev' => $rev + 1];
    });
}

// ── עסקאות: הזנה, תיקון, מצב (§4, §5) ─────────────────────────

/** comp-add {case, search?, values{…}, note?} — הזנה ידנית. שדה שלא הוזן נשאר NULL. מה שהוזן נשמר גם ב-raw, ואינו נערך עוד. */
function apr_comp_add(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, $in['case'] ?? null, 'write');
    $v = apr_comp_values($in['values'] ?? null);
    if (!array_filter(array_diff_key($v, array_flip(['currency', 'location_precision', 'area_kind'])), fn($x) => $x !== null)) apr_fail('comp_empty');
    $note = apr_clean_text($in['note'] ?? '', 1000);
    return apr_tx(function () use ($ctx, $c, $in, $v, $note) {
        $searchId = ($in['search'] ?? '') !== '' && ($in['search'] ?? null) !== null ? (int)apr_comp_search_row($c, $in['search'])['id'] : null;
        $raw = [];
        foreach ($v as $f => $x) $raw[$f] = apr_comp_field_out($f, $x);
        [$id, $pid, $dup] = apr_comp_insert($c, $v, 'manual', ['entered' => $raw], $searchId, [], (int)$ctx['user']['id']);
        if ($note !== null) apr_exec('UPDATE comps SET note = ?, rev = rev + 1 WHERE id = ?', [$note, $id]);
        apr_case_audit($c, 'comp.add', 'comp', $pid, ['origin' => 'manual'] + ($dup !== null ? ['dup' => true] : []));
        apr_case_touch($c);
        return ['comp' => $pid, 'dup_of' => $dup !== null ? apr_val('SELECT pid FROM comps WHERE id = ?', [$dup]) : null];
    });
}

/**
 * comp-correct {comp, rev, changes{שדה: ערך}, reason} — תיקון ערכים. raw אינו משתנה; לכל שדה נרשם ב-corrected: מה היה, מה נהיה,
 * מי, מתי ולמה (15-B1). מיקום שהפך למקורב ← המרחק מתנקה (15-B3). החשד לכפילות מחושב מחדש.
 */
function apr_comp_correct(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'write');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $reason = apr_reason($in['reason'] ?? null);
    $changes = $in['changes'] ?? null;
    if (!is_array($changes) || !$changes) apr_fail('nothing_to_change');
    if (array_diff(array_keys($changes), APR_COMP_FIELDS)) apr_fail('comp_field');
    $new = [];
    foreach ($changes as $f => $val) $new[$f] = apr_comp_clean_field($f, $val);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $reason, $new) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        $typed = fn(string $f, mixed $x) => $x === null ? null : (in_array($f, ['price', 'area', 'rooms', 'distance_m', 'floor', 'floors_total', 'build_year'], true) ? (int)$x : (string)$x);
        $precision = $new['location_precision'] ?? $k['location_precision'];
        if (!in_array($precision, APR_COMP_PRECISE, true) && (($new['distance_m'] ?? $k['distance_m']) !== null)) $new['distance_m'] = null;
        $set = [];
        $corr = json_decode((string)$k['corrected'], true) ?: [];
        $log = [];
        foreach ($new as $f => $val) {
            $old = $typed($f, $k[$f]);
            if ($old === $val) continue;
            $set[$f] = $val;
            $log[$f] = ['from' => apr_comp_field_out($f, $old), 'to' => apr_comp_field_out($f, $val)];
            $corr[$f] = $log[$f] + ['by' => (int)$ctx['user']['id'], 'at' => apr_now(), 'reason' => $reason];
        }
        if (!$set) apr_fail('nothing_to_change');
        $after = $set + array_intersect_key($k, array_flip(APR_COMP_FIELDS));
        foreach (['price', 'area', 'rooms', 'distance_m', 'floor', 'floors_total', 'build_year'] as $f) $after[$f] = $after[$f] === null ? null : (int)$after[$f];
        $dup = apr_comp_find_dup($c, $after, (int)$k['id']);
        apr_comp_update($ctx, $c, $k, $rev, $set + ['corrected' => json_encode((object)$corr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'dup_of' => $dup]);
        apr_case_audit($c, 'comp.correct', 'comp', $k['pid'], ['changes' => $log], $reason);
        apr_case_touch($c);
        return ['comp' => $k['pid'], 'rev' => $rev + 1];
    });
}

/** comp-status {comp, rev, status, reason?} — בחירה, החרגה, המתנה לבירור, או חזרה למועמדת. החרגה והמתנה דורשות נימוק (15-A4). */
function apr_comp_status(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $to = is_string($in['status'] ?? null) && in_array($in['status'], APR_COMP_STATUSES, true) ? $in['status'] : apr_fail('comp_status');
    $reason = in_array($to, ['excluded', 'pending'], true) ? apr_reason($in['reason'] ?? null) : apr_clean_text($in['reason'] ?? '', 500);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $to, $reason) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        if ($k['status'] === $to && $k['status_reason'] === $reason) apr_fail('nothing_to_change');
        apr_comp_update($ctx, $c, $k, $rev, ['status' => $to, 'status_reason' => $reason, 'status_by' => (int)$ctx['user']['id'], 'status_at' => apr_now()]);
        apr_case_audit($c, 'comp.status', 'comp', $k['pid'], ['from' => $k['status'], 'to' => $to], $reason);
        apr_case_touch($c);
        return ['comp' => $k['pid'], 'rev' => $rev + 1];
    });
}

/** comp-weight {comp, rev, weight | null} — המשקל שהשמאי קבע. אפס מותר לעסקה אחת; משקל שלילי אינו קיים (§10.13). */
function apr_comp_weight(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $w = apr_comp_dec($in['weight'] ?? null, 6, 6, 'comp_weight');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $w) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        $old = $k['weight'] === null ? null : (int)$k['weight'];
        if ($old === $w) apr_fail('nothing_to_change');
        apr_comp_update($ctx, $c, $k, $rev, ['weight' => $w]);
        apr_case_audit($c, 'comp.weight', 'comp', $k['pid'], ['from' => apr_comp_dec_out($old, 6), 'to' => apr_comp_dec_out($w, 6)]);
        apr_case_touch($c);
        return ['comp' => $k['pid'], 'rev' => $rev + 1];
    });
}

/** comp-note {comp, rev, note} — הערה חופשית לעסקה. */
function apr_comp_note(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'write');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $note = apr_clean_text($in['note'] ?? '', 1000);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $note) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        if ($k['note'] === $note) apr_fail('nothing_to_change');
        apr_comp_update($ctx, $c, $k, $rev, ['note' => $note]);
        apr_case_audit($c, 'comp.note', 'comp', $k['pid']);
        return ['comp' => $k['pid'], 'rev' => $rev + 1];
    });
}

/**
 * comp-delete {comp, rev, reason} — מחיקה רכה של עסקה שהוזנה בטעות. מי שיש לו edit מוחק רק עסקה מועמדת בלי התאמות ובלי משקל; עסקה
 * שנבחרה, הוחרגה או ממתינה (יש בה נימוק של שמאי), או שיש לה התאמות או משקל — רק מי שרשאי לבחור.
 * (עסקה שאינה רלוונטית אינה נמחקת — היא מוחרגת, עם נימוק, ונשארת.)
 */
function apr_comp_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'write');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $reason) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        // מי שרק מזין מוחק רק את מה שעוד אין בו הכרעה של שמאי: מועמדת, בלי התאמות ובלי משקל. כל השאר — מי שרשאי לבחור (ביקורת 15, 🟡3)
        $untouched = $k['status'] === 'candidate' && $k['weight'] === null && !apr_comp_adjs((int)$k['id']);
        if (!$untouched && !apr_comp_can_judge($ctx, $c)) apr_fail('forbidden');
        apr_comp_update($ctx, $c, $k, $rev, ['deleted_at' => apr_now(), 'deleted_by' => (int)$ctx['user']['id'], 'delete_reason' => $reason]);
        // מי שסומנה ככפילות של העסקה שנמחקה — החשד יורד
        foreach (apr_rows('SELECT id FROM comps WHERE dup_of = ? AND deleted_at IS NULL', [$k['id']]) as $o) apr_exec('UPDATE comps SET dup_of = NULL, rev = rev + 1 WHERE id = ?', [$o['id']]);
        apr_case_audit($c, 'comp.delete', 'comp', $k['pid'], ['status' => $k['status']], $reason);
        apr_case_touch($c);
        return ['deleted' => true];
    });
}

// ── התאמות (§6.2) ─────────────────────────────────────────────

function apr_comp_adjs(int $compId): array
{
    return apr_rows('SELECT * FROM comp_adjustments WHERE comp_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$compId]);
}

function apr_comp_adj_out(array $a): array
{
    return ['pid' => $a['pid'], 'sort' => (int)$a['sort'], 'label' => $a['label'], 'kind' => $a['kind'], 'value' => apr_comp_dec_out($a['value'], $a['kind'] === 'amount' ? 2 : 6),
        'sign' => $a['sign'], 'basis' => $a['basis'], 'applies_to' => $a['applies_to'], 'reason' => $a['reason']];
}

/**
 * comp-adj-save {comp, rev, adj?, label, kind, value, sign, basis?, applies_to?, reason} — התאמה חדשה, או עדכון של קיימת (adj).
 * הערך תמיד חיובי; הסימן שדה נפרד. **אין התאמה בלי נימוק** (15-A4). סכום שייך לבסיס אחד (₪ לעסקה או ₪ למ"ר) — חובה לבחור;
 * אחוז חל על שני הבסיסים אלא אם נקבע אחרת. rev הוא של העסקה: כל שינוי בהתאמות מקדם אותו.
 */
function apr_comp_adj_save(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $label = apr_clean_text($in['label'] ?? '', 80) ?? apr_fail('comp_adj_label');
    $kind = is_string($in['kind'] ?? null) && in_array($in['kind'], ['amount', 'percent'], true) ? $in['kind'] : apr_fail('comp_adj_kind');
    $value = $kind === 'amount' ? apr_comp_dec($in['value'] ?? null, 2, 11, 'comp_adj_value') : apr_comp_dec($in['value'] ?? null, 6, 4, 'comp_adj_value');
    if ($value === null || $value === 0 || ($kind === 'percent' && $value > 1000 * 1000000)) apr_fail('comp_adj_value');
    $sign = is_string($in['sign'] ?? null) && in_array($in['sign'], ['plus', 'minus'], true) ? $in['sign'] : apr_fail('comp_adj_kind');
    $basis = $kind === 'percent' ? apr_comp_enum($in['basis'] ?? null, ['base', 'running'], 'base', 'comp_adj_kind') : 'base';
    $applies = $kind === 'percent' ? apr_comp_enum($in['applies_to'] ?? null, ['total', 'per_sqm', 'both'], 'both', 'comp_adj_applies')
        : (is_string($in['applies_to'] ?? null) && in_array($in['applies_to'], APR_COMP_BASES, true) ? $in['applies_to'] : apr_fail('comp_adj_applies'));
    $reason = apr_reason($in['reason'] ?? null);
    $row = ['label' => $label, 'kind' => $kind, 'value' => $value, 'sign' => $sign, 'basis' => $basis, 'applies_to' => $applies, 'reason' => $reason];
    return apr_tx(function () use ($ctx, $c, $in, $rev, $row) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        $now = apr_now();
        if (($in['adj'] ?? null) !== null && $in['adj'] !== '') {
            $a = (apr_is_pid($in['adj']) ? apr_row('SELECT * FROM comp_adjustments WHERE pid = ? AND comp_id = ? AND deleted_at IS NULL', [$in['adj'], $k['id']]) : null) ?? apr_fail('not_found');
            $before = apr_comp_adj_out($a);
            apr_exec('UPDATE comp_adjustments SET label = ?, kind = ?, value = ?, sign = ?, basis = ?, applies_to = ?, reason = ?, updated_at = ? WHERE id = ?', [...array_values($row), $now, $a['id']]);
            $pid = $a['pid'];
            $after = apr_comp_adj_out($row + $a);
            if (array_diff_key($before, ['sort' => 1]) == array_diff_key($after, ['sort' => 1])) apr_fail('nothing_to_change');
            apr_case_audit($c, 'comp.adj_update', 'comp', $k['pid'], ['adj' => $pid, 'from' => $before, 'to' => $after], $row['reason']);
        } else {
            if ((int)apr_val('SELECT COUNT(*) FROM comp_adjustments WHERE comp_id = ? AND deleted_at IS NULL', [$k['id']]) >= APR_COMP_ADJ_MAX) apr_fail('comp_adj_limit');
            $pid = apr_pid();
            $sort = (int)apr_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM comp_adjustments WHERE comp_id = ?', [$k['id']]);
            apr_insert('comp_adjustments', ['pid' => $pid, 'comp_id' => $k['id'], 'sort' => $sort, 'created_by' => (int)$ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now] + $row);
            apr_case_audit($c, 'comp.adj_add', 'comp', $k['pid'], ['adj' => $pid, 'from' => null, 'to' => apr_comp_adj_out($row + ['pid' => $pid, 'sort' => $sort])], $row['reason']);
        }
        apr_comp_update($ctx, $c, $k, $rev, []);
        apr_case_touch($c);
        return ['comp' => $k['pid'], 'adj' => $pid, 'rev' => $rev + 1];
    });
}

/** comp-adj-delete {comp, rev, adj} — ההתאמה יוצאת מהחישוב; הערך שהיה נרשם ביומן. */
function apr_comp_adj_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        $a = (apr_is_pid($in['adj'] ?? null) ? apr_row('SELECT * FROM comp_adjustments WHERE pid = ? AND comp_id = ? AND deleted_at IS NULL', [$in['adj'], $k['id']]) : null) ?? apr_fail('not_found');
        apr_comp_update($ctx, $c, $k, $rev, []);
        apr_exec('UPDATE comp_adjustments SET deleted_at = ?, updated_at = ? WHERE id = ?', [apr_now(), apr_now(), $a['id']]);
        apr_case_audit($c, 'comp.adj_delete', 'comp', $k['pid'], ['adj' => $a['pid'], 'from' => apr_comp_adj_out($a), 'to' => null]);
        apr_case_touch($c);
        return ['comp' => $k['pid'], 'rev' => $rev + 1];
    });
}

/** comp-adj-order {comp, rev, order[]} — סדר החישוב. order חייב להיות כל ההתאמות הפעילות של העסקה, כל אחת פעם אחת. */
function apr_comp_adj_order(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $in['comp'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $order = $in['order'] ?? null;
    if (!is_array($order) || !array_is_list($order)) apr_fail('comp_adj_order');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $order) {
        $k = apr_comp_row($c, $in['comp'] ?? null);
        $cur = array_column(apr_comp_adjs((int)$k['id']), 'id', 'pid');
        if (count($order) !== count($cur) || array_diff($order, array_keys($cur)) || count(array_unique($order)) !== count($order)) apr_fail('comp_adj_order');
        if ($order === array_keys($cur)) apr_fail('nothing_to_change');
        apr_comp_update($ctx, $c, $k, $rev, []);
        foreach ($order as $i => $pid) apr_exec('UPDATE comp_adjustments SET sort = ?, updated_at = ? WHERE id = ?', [$i + 1, apr_now(), $cur[$pid]]);
        apr_case_audit($c, 'comp.adj_order', 'comp', $k['pid'], ['from' => array_keys($cur), 'to' => $order]);
        return ['comp' => $k['pid'], 'rev' => $rev + 1];
    });
}

// ── קבוצות השוואה (§3, §6) ────────────────────────────────────

/** ברירות המחדל של המסך, ממקטע "עסקאות" בפרופיל שממנו נוצר התיק (§7). לא כללים שהמערכת אוכפת. */
function apr_comp_defaults(array $c): array
{
    return apr_pc_comps(apr_case_version_content((int)$c['profile_version_id'])['comps'] ?? null);
}

/** השטח הנישום של קבוצה: נתון שטח של התיק הזה, עם ערך, מאותו סוג שטח שהקבוצה משווה. מחזיר את שורת הנתון. */
function apr_comp_subject_fact(array $c, mixed $factPid, string $areaKind): array
{
    $f = apr_fact_row($c, $factPid);
    if ($f['attribute_key'] !== 'area' || !apr_fact_has_value($f) || $f['state'] === 'rejected' || $f['area_kind'] !== $areaKind
        || apr_fact_norm($f, apr_attr('area'))[0] !== 'area') apr_fail('comp_subject');
    return $f;
}

function apr_comp_set_out(array $g): array
{
    return ['pid' => $g['pid'], 'rev' => (int)$g['rev'], 'name' => $g['name'], 'unit_basis' => $g['unit_basis'], 'area_kind' => $g['area_kind'], 'method' => $g['method'], 'rounding' => $g['rounding'],
        'conclusion' => $g['conclusion_value'] === null ? null : ['value' => apr_comp_dec_out($g['conclusion_value'], 2), 'agorot' => (string)(int)$g['conclusion_value'], 'reason' => $g['conclusion_reason'],
            'by' => $g['conclusion_name'] ?? null, 'at' => $g['conclusion_at']],
        'updated_by' => $g['updated_name'] ?? null, 'updated_at' => $g['updated_at']];
}

/**
 * comp-set-save {case, set?, rev?, name?, unit_basis, area_kind?, method, rounding, subject_area_fact?} — קבוצה חדשה או עדכון.
 * קבוצה שמשווה למ"ר חייבת לדעת איזה סוג שטח משווים. המסקנה אינה נוגעת כאן — ושום שדה כאן אינו ממלא אותה (15-B6).
 * recheck: true (עם אותו נתון שטח) = "נבדק מחדש": הסימון stale יורד (פרוסה 10), ונרשם ביומן. שמירה רגילה אינה מורידה אותו.
 */
function apr_comp_set_save(array $ctx, array $in): array
{
    $isNew = ($in['set'] ?? null) === null || $in['set'] === '';
    $c = apr_comp_case($ctx, $isNew ? ($in['case'] ?? null) : apr_doc_case_pid('comp_sets', $in['set']), 'judge');
    $rev = $isNew ? 0 : apr_comp_rev($in['rev'] ?? null);
    $basis = is_string($in['unit_basis'] ?? null) && in_array($in['unit_basis'], APR_COMP_BASES, true) ? $in['unit_basis'] : apr_fail('comp_set_field');
    $name = apr_clean_text($in['name'] ?? '', 80);
    $row = ['name' => $name ?? 'קבוצת השוואה', 'unit_basis' => $basis,
        'area_kind' => $basis === 'per_sqm' ? (is_string($in['area_kind'] ?? null) && in_array($in['area_kind'], APR_COMP_SET_AREA_KINDS, true) ? $in['area_kind'] : apr_fail('comp_set_area_kind')) : null,
        'method' => is_string($in['method'] ?? null) && in_array($in['method'], APR_COMP_METHODS, true) ? $in['method'] : apr_fail('comp_set_field'),
        'rounding' => in_array((string)($in['rounding'] ?? ''), APR_COMP_ROUNDINGS, true) ? (string)$in['rounding'] : apr_fail('comp_set_field')];
    return apr_tx(function () use ($ctx, $c, $in, $isNew, $rev, $row, $name) {
        $factPid = $row['unit_basis'] === 'per_sqm' && ($in['subject_area_fact'] ?? null) !== null && $in['subject_area_fact'] !== '' ? $in['subject_area_fact'] : null;
        $fact = $factPid !== null ? apr_comp_subject_fact($c, $factPid, $row['area_kind']) : null;
        $row['subject_area_fact_id'] = $fact ? (int)$fact['id'] : null;
        $now = apr_now();
        if ($isNew) {
            if ((int)apr_val('SELECT COUNT(*) FROM comp_sets WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]) >= APR_COMP_SET_MAX) apr_fail('comp_set_limit');
            $pid = apr_pid();
            apr_insert('comp_sets', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'created_by' => (int)$ctx['user']['id'], 'updated_by' => (int)$ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now] + $row);
            apr_case_audit($c, 'comp.set_add', 'comp_set', $pid, ['to' => array_diff_key($row, ['subject_area_fact_id' => 1]) + ['subject_area_fact' => $factPid]]);
        } else {
            $g = apr_comp_set_row($c, $in['set']);
            $pid = $g['pid'];
            if ($name === null) $row['name'] = $g['name'];          // עדכון בלי שם אינו דורס את השם
            $from = [];
            $to = [];
            foreach ($row as $f => $v) {
                $old = $f === 'subject_area_fact_id' ? ($g[$f] === null ? null : (int)$g[$f]) : $g[$f];
                if ($old !== $v) [$from[$f], $to[$f]] = [$old, $v];
            }
            // שינוי בסיס או סוג שטח משנה את היחידה של המסקנה (₪ מול ₪ למ"ר) — מסקנה ישנה אינה נשארת ביחידה אחרת
            if ($g['conclusion_value'] !== null && (isset($to['unit_basis']) || isset($to['area_kind']))) apr_fail('comp_set_concluded');
            $recheck = ($in['recheck'] ?? false) === true && $fact !== null && !isset($to['subject_area_fact_id']);
            if (!$from && !$recheck) apr_fail('nothing_to_change');
            apr_comp_set_update($ctx, $c, $g, $rev, $row);
            if ($from) apr_case_audit($c, 'comp.set_update', 'comp_set', $pid, ['from' => $from, 'to' => $to]);
            // "נבדק מחדש" — פעולה מפורשת של השמאי, עם רשומה ביומן. שמירה רגילה של ההגדרות אינה מורידה את הסימון (ביקורת 15, 🟡4)
            if ($recheck) apr_case_audit($c, 'comp.set_recheck', 'comp_set', $pid, ['subject_area_fact' => $fact['pid'], 'area' => apr_fact_num_out(apr_fact_norm($fact, apr_attr('area'))[1])]);
        }
        // הרישום ב-fact_uses משתנה רק כשהנתון הוחלף, או ב"נבדק מחדש": אחרת stale היה יורד בכל שמירה
        $oldFact = $isNew || $g['subject_area_fact_id'] === null ? null : (int)$g['subject_area_fact_id'];
        if ($isNew || $oldFact !== $row['subject_area_fact_id']) {
            apr_fact_unuse($c, 'calc', $pid);
            if ($fact) apr_fact_use($c, $fact['pid'], 'calc', $pid);
        } elseif (!empty($recheck)) {
            apr_fact_use($c, $fact['pid'], 'calc', $pid);
        }
        apr_case_touch($c);
        return ['set' => $pid, 'rev' => $rev + 1];
    });
}

/**
 * comp-set-conclude {set, rev, value | null, reason} — המסקנה של השמאי (§6.5). הערך מוקלד; המערכת אינה ממלאת אותו בשום פעולה,
 * ושמירה בלי נימוק נדחית (15-B6). value = null מוחק את המסקנה.
 */
function apr_comp_set_conclude(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comp_sets', $in['set'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $value = apr_comp_dec($in['value'] ?? null, 2, 11);
    $reason = $value === null ? apr_clean_text($in['reason'] ?? '', 500) : apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $value, $reason) {
        $g = apr_comp_set_row($c, $in['set'] ?? null);
        $old = $g['conclusion_value'] === null ? null : (int)$g['conclusion_value'];
        if ($old === $value && $g['conclusion_reason'] === ($value === null ? null : $reason)) apr_fail('nothing_to_change');
        apr_comp_set_update($ctx, $c, $g, $rev, $value === null ? ['conclusion_value' => null, 'conclusion_reason' => null, 'conclusion_by' => null, 'conclusion_at' => null]
            : ['conclusion_value' => $value, 'conclusion_reason' => $reason, 'conclusion_by' => (int)$ctx['user']['id'], 'conclusion_at' => apr_now()]);
        apr_case_audit($c, 'comp.set_conclude', 'comp_set', $g['pid'], ['from' => apr_comp_dec_out($old, 2), 'to' => apr_comp_dec_out($value, 2), 'unit_basis' => $g['unit_basis']], $reason);
        apr_case_touch($c);
        return ['set' => $g['pid'], 'rev' => $rev + 1];
    });
}

/** comp-set-delete {set, rev} — הקבוצה יוצאת מהרשימה; העסקאות וההתאמות נשארות. */
function apr_comp_set_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comp_sets', $in['set'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $g = apr_comp_set_row($c, $in['set'] ?? null);
        apr_comp_set_update($ctx, $c, $g, $rev, ['deleted_at' => apr_now()]);
        apr_fact_unuse($c, 'calc', $g['pid']);
        apr_case_audit($c, 'comp.set_delete', 'comp_set', $g['pid'], ['name' => $g['name']]);
        return ['deleted' => true];
    });
}

// ── מהמנוע לתשובה ─────────────────────────────────────────────

/**
 * סכום מדויק לתשובה: agorot — מעוגל לאגורה; nis — מעוגל לשקל (כך מוצגות תוצאות הביניים, §6.1); exact — השבר המדויק "מונה/מכנה"
 * באגורות, כשאינו שלם (ולא ארוך מדי לתשובה) — כדי שכל מספר יהיה ניתן לשחזור.
 */
function apr_comp_money(?array $q): ?array
{
    if ($q === null) return null;
    $exact = $q[1] === '1' || strlen($q[0]) + strlen($q[1]) > 120 ? null : $q[0] . '/' . $q[1];
    return ['agorot' => apr_q_round($q, 1), 'nis' => apr_n_floordiv(apr_q_round($q, 100), '100'), 'exact' => $exact];
}

function apr_comp_calc_out(array $calc): array
{
    return ['ok' => $calc['ok'], 'reason' => $calc['reason'], 'base' => apr_comp_money($calc['base']), 'adjusted' => apr_comp_money($calc['adjusted']), 'nonpositive' => $calc['nonpositive'],
        'steps' => array_map(fn($s) => ['pid' => $s['pid'], 'label' => $s['label'], 'kind' => $s['kind'], 'sign' => $s['sign'], 'basis' => $s['basis'],
            'value' => apr_comp_dec_out($s['value'], $s['kind'] === 'amount' ? 2 : 6), 'delta' => apr_comp_money($s['delta']), 'running' => apr_comp_money($s['running'])], $calc['steps'])];
}

/** שורת עסקה כפי שהמנוע צריך אותה — שלמים בלבד. */
function apr_comp_calc_input(array $k): array
{
    return ['price' => $k['price'] === null ? null : (int)$k['price'], 'currency' => $k['currency'], 'area' => $k['area'] === null ? null : (int)$k['area'], 'area_kind' => $k['area_kind']];
}

/**
 * החישוב של קבוצה על העסקאות שנבחרו: שורה לכל עסקה + הסיכום. הכול מחושב מחדש בכל קריאה — אין תוצאה שמורה שיכולה להתיישן (§6.6).
 */
function apr_comp_set_calc(array $c, array $g, int $smallUnder): array
{
    $rows = apr_rows(APR_COMP_SELECT . " WHERE k.case_id = ? AND k.deleted_at IS NULL AND k.status = 'selected' ORDER BY k.deal_date IS NULL, k.deal_date, k.id", [$c['id']]);
    $items = [];
    $out = [];
    foreach ($rows as $k) {
        $calc = apr_calc_comp(apr_comp_calc_input($k), apr_comp_adjs((int)$k['id']), $g);
        $items[] = ['pid' => $k['pid'], 'calc' => $calc, 'weight' => $k['weight'] === null ? null : (int)$k['weight'], 'deal_date' => $k['deal_date'],
            'missing' => count(apr_comp_missing($k)), 'dup' => $k['dup_of'] !== null];
        $out[$k['pid']] = ['comp' => apr_comp_out($k), 'calc' => apr_comp_calc_out($calc)];
    }
    $s = apr_calc_summary($items, $g, $smallUnder);
    $shares = [];
    foreach ($s['weights'] as $w) $shares[$w['pid']] = apr_q_decimal(apr_q_mul($w['share'], apr_q(100)), 2);
    foreach ($out as $pid => &$r) $r += ['share' => $shares[$pid] ?? null, 'outlier' => in_array($pid, $s['outliers'], true)];
    unset($r);
    foreach (['min', 'max', 'mean', 'median', 'weighted', 'result'] as $f) $s[$f] = apr_comp_money($s[$f]);
    unset($s['weights']);
    return ['rows' => array_values($out), 'summary' => $s + ['small_under' => $smallUnder]];
}

/** השטח הנישום של קבוצה, לתצוגה: הנתון, האם השתנה מאז (stale), ו"מסקנה למ"ר × שטח" כחישוב עזר (§6.5). */
function apr_comp_set_subject(array $ctx, array $c, array $g): ?array
{
    if ($g['subject_area_fact_id'] === null) return null;
    $f = apr_row(APR_FACT_SELECT . ' WHERE f.id = ? AND f.case_id = ?', [$g['subject_area_fact_id'], $c['id']]);
    if (!$f) return null;
    $attr = apr_attr('area');
    $use = array_values(array_filter(apr_fact_uses_of($c, 'calc', $g['pid']), fn($u) => $u['fact'] === $f['pid']))[0] ?? null;
    $usable = $f['deleted_at'] === null && $f['state'] !== 'rejected' && apr_fact_has_value($f) && $f['area_kind'] === $g['area_kind'];
    $area = $usable ? apr_fact_norm($f, $attr)[1] : null;
    return ['fact' => $f['deleted_at'] === null ? apr_fact_out($ctx, $c, $f, $attr) : null, 'usable' => $usable, 'stale' => (bool)($use['stale'] ?? false),
        'area' => apr_fact_num_out($area), 'area_kind' => $g['area_kind'],
        'total' => $usable && $g['conclusion_value'] !== null && $g['unit_basis'] === 'per_sqm' ? apr_comp_money(apr_calc_subject_total((int)$g['conclusion_value'], (int)$area)) : null];
}

// ── קריאה ─────────────────────────────────────────────────────

function apr_comp_case_head(array $c): array
{
    return ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title']];
}

/** לכרטיס התיק: כמה עסקאות, וכמה מהן נבחרו. */
function apr_case_comps_summary(array $c): array
{
    $r = apr_row("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'selected'), 0) AS selected FROM comps WHERE case_id = ? AND deleted_at IS NULL", [$c['id']]);
    return ['total' => (int)$r['total'], 'selected' => (int)$r['selected']];
}

/**
 * case-comps {case} — "עסקאות וחישובים" של תיק: החיפושים, העסקאות (כולן; המסך מסנן לפי מצב), הקבוצות עם הסיכום של כל אחת,
 * ברירות המחדל מהפרופיל, המקורות שאפשר לבחור, ונתוני השטח של הנכס הנישום.
 */
function apr_case_comps(array $ctx, array $q): array
{
    $c = apr_comp_case($ctx, $q['case'] ?? null);
    $def = apr_comp_defaults($c);
    $rows = apr_rows(APR_COMP_SELECT . ' WHERE k.case_id = ? AND k.deleted_at IS NULL ORDER BY k.id', [$c['id']]);
    $names = apr_comp_corrector_names($rows);
    $counts = array_column(apr_rows('SELECT search_id, COUNT(*) AS n FROM comps WHERE case_id = ? AND deleted_at IS NULL AND search_id IS NOT NULL GROUP BY search_id', [$c['id']]), 'n', 'search_id');
    $adjN = array_column(apr_rows('SELECT a.comp_id, COUNT(*) AS n FROM comp_adjustments a JOIN comps k ON k.id = a.comp_id WHERE k.case_id = ? AND a.deleted_at IS NULL GROUP BY a.comp_id', [$c['id']]), 'n', 'comp_id');
    $sets = [];
    foreach (apr_rows('SELECT g.*, ub.name AS updated_name, cb.name AS conclusion_name FROM comp_sets g LEFT JOIN users ub ON ub.id = g.updated_by LEFT JOIN users cb ON cb.id = g.conclusion_by
        WHERE g.case_id = ? AND g.deleted_at IS NULL ORDER BY g.id', [$c['id']]) as $g) {
        $sets[] = apr_comp_set_out($g) + ['summary' => apr_comp_set_calc($c, $g, $def['small_sample_under'])['summary'], 'subject' => apr_comp_set_subject($ctx, $c, $g)];
    }
    $attr = apr_attr('area');
    $areaFacts = array_values(array_filter(apr_rows(APR_FACT_SELECT . " WHERE f.case_id = ? AND f.deleted_at IS NULL AND f.attribute_key = 'area' AND f.state <> 'rejected' ORDER BY f.id", [$c['id']]),
        fn($f) => apr_fact_has_value($f) && $f['area_kind'] !== null && $f['area_kind'] !== 'unknown' && apr_fact_norm($f, $attr)[0] === 'area'));
    return ['case' => apr_comp_case_head($c), 'perms' => apr_comp_perms($ctx, $c), 'defaults' => $def,
        'lists' => ['statuses' => APR_COMP_STATUSES, 'precisions' => APR_COMP_PRECISIONS, 'area_kinds' => APR_AREA_KINDS, 'set_area_kinds' => APR_COMP_SET_AREA_KINDS, 'currencies' => APR_COMP_CURRENCIES,
            'methods' => APR_COMP_METHODS, 'roundings' => APR_COMP_ROUNDINGS, 'fields' => APR_COMP_FIELDS, 'import_max' => APR_COMP_IMPORT_MAX],
        'sources' => array_values(array_map(fn($s) => ['key' => $s['key'], 'name' => $s['name'], 'url' => $s['url']],
            array_filter(apr_sources_all(true), fn($s) => in_array('deals', json_decode((string)$s['info_kinds'], true) ?: [], true)))),
        'searches' => array_map(fn($s) => apr_comp_search_out($ctx, $c, $s, $counts), apr_rows('SELECT * FROM comp_searches WHERE case_id = ? ORDER BY id DESC', [$c['id']])),
        'comps' => array_map(fn($k) => apr_comp_out($k, false, $names) + ['adjustments' => (int)($adjN[(int)$k['id']] ?? 0)], $rows),
        'sets' => $sets,
        'subject_areas' => apr_can($c, 'facts') ? array_map(fn($f) => apr_fact_out($ctx, $c, $f, $attr), $areaFacts) : []];
}

/**
 * comp {comp} — עסקה אחת: פרטי המקור (raw) לצד הערכים בשימוש, התיקונים, "דומה / שונה", ההתאמות, והנוסחה שורה-שורה מול כל
 * קבוצה של התיק. הנוסחה מוצגת גם לעסקה שעוד לא נבחרה — כך רואים את התוצאה לפני שמחליטים.
 */
function apr_comp_view(array $ctx, array $q): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comps', $q['comp'] ?? null));
    $k = apr_comp_row($c, $q['comp'] ?? null);
    $adjs = apr_comp_adjs((int)$k['id']);
    $calcs = [];
    foreach (apr_rows('SELECT * FROM comp_sets WHERE case_id = ? AND deleted_at IS NULL ORDER BY id', [$c['id']]) as $g) {
        $calcs[] = ['set' => ['pid' => $g['pid'], 'name' => $g['name'], 'unit_basis' => $g['unit_basis'], 'area_kind' => $g['area_kind']], 'calc' => apr_comp_calc_out(apr_calc_comp(apr_comp_calc_input($k), $adjs, $g))];
    }
    $search = $k['search_id'] !== null ? apr_row('SELECT * FROM comp_searches WHERE id = ?', [$k['search_id']]) : null;
    return ['case' => apr_comp_case_head($c), 'perms' => apr_comp_perms($ctx, $c), 'comp' => apr_comp_out($k, true, apr_comp_corrector_names([$k])),
        'search' => $search ? apr_comp_search_out($ctx, $c, $search) : null, 'similar' => apr_comp_similar($k, apr_comp_subject($c)),
        'adjustments' => array_map('apr_comp_adj_out', $adjs), 'calcs' => $calcs, 'defaults' => apr_comp_defaults($c),
        'lists' => ['statuses' => APR_COMP_STATUSES, 'precisions' => APR_COMP_PRECISIONS, 'area_kinds' => APR_AREA_KINDS, 'currencies' => APR_COMP_CURRENCIES],
        'history' => array_map(fn($r) => ['at' => $r['at'], 'action' => $r['action'], 'by' => $r['name'], 'meta' => json_decode($r['meta'], true) ?: (object)[], 'reason' => $r['reason']],
            apr_rows("SELECT a.at, a.action, a.meta, a.reason, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.entity_type = 'comp' AND a.entity_pid = ? AND a.case_id = ? ORDER BY a.id", [$k['pid'], $c['id']]))];
}

/** comp-set {set} — קבוצה: טבלת העסקאות שנבחרו (בסיס, התאמות, מותאם, משקל), הסיכום, והמסקנה לצד הממוצע, החציון והטווח. */
function apr_comp_set_view(array $ctx, array $q): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('comp_sets', $q['set'] ?? null));
    $g = apr_comp_set_row($c, $q['set'] ?? null);
    $def = apr_comp_defaults($c);
    $attr = apr_attr('area');
    return ['case' => apr_comp_case_head($c), 'perms' => apr_comp_perms($ctx, $c), 'set' => apr_comp_set_out($g), 'subject' => apr_comp_set_subject($ctx, $c, $g)]
        + apr_comp_set_calc($c, $g, $def['small_sample_under'])
        + ['lists' => ['set_area_kinds' => APR_COMP_SET_AREA_KINDS, 'methods' => APR_COMP_METHODS, 'roundings' => APR_COMP_ROUNDINGS],
            'subject_areas' => !apr_can($c, 'facts') ? [] : array_map(fn($f) => apr_fact_out($ctx, $c, $f, $attr), array_values(array_filter(
                apr_rows(APR_FACT_SELECT . " WHERE f.case_id = ? AND f.deleted_at IS NULL AND f.attribute_key = 'area' AND f.state <> 'rejected' ORDER BY f.id", [$c['id']]),
                fn($f) => apr_fact_has_value($f) && $f['area_kind'] !== null && $f['area_kind'] !== 'unknown' && apr_fact_norm($f, $attr)[0] === 'area')))];
}

// ── מקטע "עסקאות" בפרופיל (§7) ────────────────────────────────

/** ברירות מחדל למסך — לא כללים. radius_m: null = לא נקבע. */
function apr_pc_comps_default(): array
{
    return ['default_criteria' => ['months_back' => 24, 'radius_m' => null, 'same_property_type' => true], 'unit_basis' => 'per_sqm', 'area_kind' => 'registered',
        'method' => 'mean', 'rounding' => '1000', 'small_sample_under' => 3];
}

function apr_pc_comps(mixed $v): array
{
    $d = apr_pc_comps_default();
    if ($v === null) return $d;
    if (!is_array($v) || (array_is_list($v) && $v !== [])) apr_fail('content_invalid');
    $cr = $v['default_criteria'] ?? [];
    if (!is_array($cr)) apr_fail('content_invalid');
    $int = function (mixed $x, int $min, int $max, ?int $default, bool $nullOk) {
        if ($x === null) return $nullOk ? null : $default;
        if (!is_int($x) || $x < $min || $x > $max) apr_fail('content_invalid');
        return $x;
    };
    if (array_key_exists('same_property_type', $cr) && !is_bool($cr['same_property_type'])) apr_fail('content_invalid');
    return ['default_criteria' => ['months_back' => $int($cr['months_back'] ?? null, 1, 240, 24, false), 'radius_m' => $int($cr['radius_m'] ?? null, 1, 100000, null, true),
            'same_property_type' => ($cr['same_property_type'] ?? true) !== false],
        'unit_basis' => apr_pc_enum($v['unit_basis'] ?? null, APR_COMP_BASES, $d['unit_basis']), 'area_kind' => apr_pc_enum($v['area_kind'] ?? null, APR_COMP_SET_AREA_KINDS, $d['area_kind']),
        'method' => apr_pc_enum($v['method'] ?? null, APR_COMP_METHODS, $d['method']),
        'rounding' => apr_pc_enum(is_int($v['rounding'] ?? null) ? (string)$v['rounding'] : ($v['rounding'] ?? null), APR_COMP_ROUNDINGS, $d['rounding']),
        'small_sample_under' => $int($v['small_sample_under'] ?? null, 1, 50, 3, false)];
}
