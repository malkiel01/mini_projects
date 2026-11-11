<?php
/**
 * פרוסה 10 — נתונים, מקור לכל נתון, וסתירות (docs/spec/10-facts-conflicts.md).
 *
 * כללי היסוד, שכל פונקציה כאן שומרת עליהם:
 *  - "לא ידוע" אינו שורה ואינו אפס: מאפיין בלי נתון מוחזר null. מספר נשמר כשלם במאיות (בלי נקודה צפה), ומוחזר כמחרוזת עשרונית.
 *  - שום נתון לא נמחק ולא נדרס: תיקון שומר את המקורי ב-original_* (פעם אחת), דחייה ומחיקה רכה משאירות את השורה, והכרעה
 *    בסתירה אינה נוגעת בנתונים. המסד אוכף את זה בטריגרים (מיגרציה 009).
 *  - כל שינוי בנתון: UPDATE … WHERE rev = ? — אפס שורות ← 409 fact_changed, עם הנתון כפי שהוא בשרת.
 *  - כל כתיבה בטרנזקציה אחת (BEGIN IMMEDIATE) יחד עם הסנכרון של הסתירות ועם היומן.
 *  - מי שאינו רואה את קובץ המקור מקבל "מקור: מסמך" בלבד — apr_fact_source_out() הוא המקום היחיד שבונה מקור לתצוגה.
 *
 * הרשאות (§9): צפייה — view; הזנה, תיקון, "דורש בירור" — edit או documents; אימות, דחייה והכרעה — review_docs ושמאי בתיק.
 */

declare(strict_types=1);

const APR_AREA_KINDS = ['registered', 'built', 'gross', 'net', 'primary', 'other', 'unknown'];
const APR_FACT_SOURCES = ['document', 'visit', 'source_check', 'user'];      // 'ai' — רק מהחילוץ (פרוסה 12), לא מהזנה ידנית
const APR_FACT_USE_TYPES = ['calc', 'comp', 'report_section', 'alternative'];
const APR_ATTR_GROUPS = ['identity', 'features', 'rights', 'deal', 'renovation', 'rent'];
/** יחידות לכל סוג, עם מקדם ההמרה המדויק ליחידת הבסיס (מ"ר; ס"מ). מטבעות אינם מומרים זה לזה. */
const APR_UNITS = ['area' => ['sqm' => 1, 'dunam' => 1000], 'length' => ['cm' => 1, 'm' => 100], 'money' => ['ILS' => 1, 'USD' => 1, 'EUR' => 1], 'count' => [], 'none' => []];
const APR_FACT_NUM_MAX = 999_999_999_999;                                     // החלק השלם; במאיות — רחוק מגבול ה-int

const APR_FACT_SELECT = "SELECT f.*, eb.name AS entered_name, vb.name AS verified_name, cb.name AS corrected_name, ub.name AS updated_name,
        sf.pid AS file_pid, sf.original_name AS file_name, sf.deleted_at AS file_deleted,
        (SELECT rf.review_state FROM requirement_files rf WHERE rf.file_id = f.source_file_id ORDER BY rf.id DESC LIMIT 1) AS file_review
    FROM facts f LEFT JOIN users eb ON eb.id = f.entered_by LEFT JOIN users vb ON vb.id = f.verified_by LEFT JOIN users cb ON cb.id = f.corrected_by
        LEFT JOIN users ub ON ub.id = f.updated_by LEFT JOIN files sf ON sf.id = f.source_file_id";

/** 409 לעדכון עם rev ישן: "הנתון עודכן בידי <שם>", עם הנתון כפי שהוא בשרת — המסך מציג את שני הערכים (§5). */
class AprFactChanged extends AprError
{
    public function __construct(public readonly array $current, ?string $by)
    {
        parent::__construct('fact_changed', $by === null ? null : "הנתון עודכן בידי $by");
    }
}

// ── הקטלוג ────────────────────────────────────────────────────

function apr_attr(mixed $key, bool $activeOnly = false): array
{
    $a = is_string($key) ? apr_row('SELECT * FROM attributes WHERE key = ?', [$key]) : null;
    if (!$a || ($activeOnly && $a['status'] !== 'active')) apr_fail('attr_invalid');
    return $a;
}

function apr_attr_out(array $a): array
{
    return ['key' => $a['key'], 'label' => $a['label'], 'group' => $a['grp'], 'value_type' => $a['value_type'], 'unit_kind' => $a['unit_kind'],
        'has_area_kind' => (bool)$a['has_area_kind'], 'choices' => $a['choices'] === null ? null : (json_decode($a['choices'], true) ?: []), 'multi' => (bool)$a['multi'],
        'decimals' => (int)$a['decimals'], 'allow_negative' => (bool)$a['allow_negative'], 'status' => $a['status'], 'example' => (bool)$a['is_example'], 'sort' => (int)$a['sort']];
}

function apr_attrs_all(): array
{
    return apr_rows("SELECT * FROM attributes ORDER BY CASE grp WHEN 'identity' THEN 0 WHEN 'features' THEN 1 WHEN 'rights' THEN 2 WHEN 'deal' THEN 3 WHEN 'renovation' THEN 4 ELSE 5 END, sort, id");
}

function apr_sys_attributes(): array
{
    return ['groups' => APR_ATTR_GROUPS, 'units' => array_map('array_keys', APR_UNITS),
        'attributes' => array_map(fn($a) => apr_attr_out($a) + ['in_use' => (bool)apr_val('SELECT 1 FROM facts WHERE attribute_key = ? LIMIT 1', [$a['key']])], apr_attrs_all())];
}

/**
 * מנהל המערכת מוסיף ועורך מאפיין: key (חדש — רק ביצירה), label, group, value_type, unit_kind, has_area_kind, choices, multi,
 * decimals, allow_negative, status, sort, example. מאפיין אינו נמחק — רק מוסתר. מאפיין בשימוש: המבנה שלו (סוג, יחידה, סוג שטח,
 * רב-ערכי) נעול, ואפשרות שנבחרה בנתון אינה מוסרת מהרשימה.
 */
function apr_sys_attribute_save(array $in): array
{
    $key = $in['key'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{1,39}\z/', $key)) apr_fail('attr_invalid');
    $type = apr_clean_choice($in['value_type'] ?? null, ['number', 'money', 'date', 'text', 'choice', 'bool']) ?? apr_fail('attr_invalid');
    $unit = apr_clean_choice($in['unit_kind'] ?? 'none', array_keys(APR_UNITS)) ?? 'none';
    $area = ($in['has_area_kind'] ?? false) === true;
    if (($type === 'money') !== ($unit === 'money') || ($unit !== 'none' && !in_array($type, ['number', 'money'], true)) || ($area && $unit !== 'area')) apr_fail('attr_invalid');
    $choices = null;
    if ($type === 'choice') {
        if (!is_array($in['choices'] ?? null) || !$in['choices'] || count($in['choices']) > 30) apr_fail('attr_invalid');
        $choices = [];
        foreach ($in['choices'] as $ch) {
            if (!is_array($ch) || !is_string($ch['key'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,39}\z/', $ch['key']) || isset($choices[$ch['key']])) apr_fail('attr_invalid');
            $choices[$ch['key']] = ['key' => $ch['key'], 'label' => apr_clean_name($ch['label'] ?? null, 60)];
        }
    }
    $dec = $in['decimals'] ?? 2;
    $sort = $in['sort'] ?? 0;
    if (!in_array($dec, [0, 1, 2], true) || !is_int($sort) || $sort < 0 || $sort > 100000) apr_fail('attr_invalid');
    $row = ['label' => apr_clean_name($in['label'] ?? null, 60), 'grp' => apr_clean_choice($in['group'] ?? null, APR_ATTR_GROUPS) ?? apr_fail('attr_invalid'),
        'value_type' => $type, 'unit_kind' => $unit, 'has_area_kind' => (int)$area, 'choices' => $choices === null ? null : json_encode(array_values($choices), JSON_UNESCAPED_UNICODE),
        'multi' => (int)(($in['multi'] ?? false) === true), 'decimals' => $dec, 'allow_negative' => (int)(($in['allow_negative'] ?? false) === true),
        'status' => apr_clean_choice($in['status'] ?? 'active', ['active', 'hidden']) ?? 'active', 'is_example' => (int)(($in['example'] ?? false) === true), 'sort' => $sort];
    return apr_tx(function () use ($key, $row, $choices, $in) {
        $old = apr_row('SELECT * FROM attributes WHERE key = ?', [$key]);
        if (!$old) {
            if (($in['create'] ?? false) !== true) apr_fail('not_found');
            apr_insert('attributes', ['key' => $key, 'created_at' => apr_now()] + $row);
        } else {
            if (($in['create'] ?? false) === true) apr_fail('attr_exists');
            if (apr_val('SELECT 1 FROM facts WHERE attribute_key = ? LIMIT 1', [$key])) {
                foreach (['value_type', 'unit_kind', 'has_area_kind', 'multi'] as $k) if ((string)$row[$k] !== (string)$old[$k]) apr_fail('attr_in_use');
                if ((int)$row['decimals'] < (int)$old['decimals'] || (int)$row['allow_negative'] < (int)$old['allow_negative']) apr_fail('attr_in_use');
                foreach (json_decode((string)$old['choices'], true) ?: [] as $ch) {
                    if (!isset($choices[$ch['key']]) && apr_val('SELECT 1 FROM facts WHERE attribute_key = ? AND value_text = ? LIMIT 1', [$key, $ch['key']])) apr_fail('attr_in_use');
                }
            }
            apr_exec('UPDATE attributes SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $old['id']]);
        }
        apr_audit($old ? 'attribute.update' : 'attribute.create', 'attribute', $key, ['fields' => array_keys($row)]);
        return ['attribute' => apr_attr_out(apr_attr($key))];
    });
}

// ── ערכים ─────────────────────────────────────────────────────

/** מספר מהקלט ← שלם במאיות, בלי לעבור דרך נקודה צפה. מקבל שלם, מחרוזת עשרונית, או מספר JSON שהייצוג הקצר שלו עשרוני פשוט. */
function apr_fact_num(mixed $v, array $attr): int
{
    if (is_int($v)) $s = (string)$v;
    elseif (is_float($v) && is_finite($v)) $s = (string)json_encode($v);
    elseif (is_string($v)) $s = trim($v);
    else apr_fail('fact_value');
    if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?\z/', $s, $m)) apr_fail('fact_value');
    $frac = rtrim($m[3] ?? '', '0');
    if (strlen($frac) > (int)$attr['decimals']) apr_fail('fact_value');
    $n = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
    if ($m[1] === '-' && $n !== 0) {
        if (!(int)$attr['allow_negative']) apr_fail('fact_value');
        $n = -$n;
    }
    return $n;
}

/** שלם במאיות ← מחרוזת עשרונית מדויקת ("78.5", "0", "-1"). null נשאר null — "לא ידוע" אינו אפס. */
function apr_fact_num_out(mixed $n): ?string
{
    if ($n === null) return null;
    $n = (int)$n;
    $s = intdiv(abs($n), 100) . (abs($n) % 100 ? '.' . rtrim(str_pad((string)(abs($n) % 100), 2, '0', STR_PAD_LEFT), '0') : '');
    return ($n < 0 ? '-' : '') . $s;
}

function apr_fact_has_value(array $f): bool
{
    return $f['value_num'] !== null || $f['value_text'] !== null || $f['value_date'] !== null;
}

/**
 * הערך מהקלט, לפי סוג המאפיין: [value_num, value_text, value_date, unit, area_kind]. ערך ריק (null / '') מותר רק כש-$emptyOk
 * ("דורש בירור") — ואז כל שדות הערך NULL. אפס במספר דורש zero_ok: "אפס, או לא ידוע?" (מקרה קצה 5).
 */
function apr_fact_value(array $attr, array $in, bool $emptyOk): array
{
    $v = $in['value'] ?? null;
    $empty = $v === null || $v === '';
    $kind = null;
    if ((int)$attr['has_area_kind']) {
        $kind = $in['area_kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, APR_AREA_KINDS, true)) apr_fail('area_kind_required');     // 10-B3: אפשר "לא ידוע", אי אפשר ריק
    }
    $unit = null;
    if (APR_UNITS[$attr['unit_kind']]) {
        $unit = $in['unit'] ?? null;
        if (!is_string($unit) || !isset(APR_UNITS[$attr['unit_kind']][$unit])) apr_fail('fact_unit');
    }
    if ($empty) {
        if (!$emptyOk) apr_fail('fact_no_value');
        return [null, null, null, $unit, $kind];
    }
    switch ($attr['value_type']) {
        case 'number':
        case 'money':
            $n = apr_fact_num($v, $attr);
            if ($n === 0 && ($in['zero_ok'] ?? false) !== true) apr_fail('fact_zero_confirm');
            return [$n, null, null, $unit, $kind];
        case 'bool':
            if (!is_bool($v)) apr_fail('fact_value');
            return [$v ? 100 : 0, null, null, null, null];
        case 'date':
            return [null, null, apr_clean_date($v), null, null];
        case 'choice':
            if (!is_string($v) || !in_array($v, array_column(json_decode((string)$attr['choices'], true) ?: [], 'key'), true)) apr_fail('fact_value');
            return [null, $v, null, null, null];
        default:
            return [null, apr_clean_text($v, 500) ?? apr_fail('fact_no_value'), null, null, null];
    }
}

/** הערך המנורמל להשוואה: [משפחת יחידות, ערך]. משפחה null — יחידה שאי אפשר להשוות (§6.1, מקרה קצה 10). */
function apr_fact_norm(array $f, array $attr): array
{
    switch ($attr['value_type']) {
        case 'number':
        case 'money':
            $units = APR_UNITS[$attr['unit_kind']];
            if (!$units) return ['', (int)$f['value_num']];
            $m = $units[(string)$f['unit']] ?? null;
            if ($m === null) return [null, null];
            return [$attr['unit_kind'] === 'money' ? (string)$f['unit'] : $attr['unit_kind'], (int)$f['value_num'] * $m];
        case 'date':
            return ['', (string)$f['value_date']];
        case 'bool':
            return ['', (int)$f['value_num']];
        default:
            return ['', mb_strtolower(trim((string)$f['value_text']))];
    }
}

// ── מקור ──────────────────────────────────────────────────────

/** מקור מהקלט: source {kind, file?, page?, locator?, date?, note?}. מסמך — קובץ של התיק הזה, שלא נמחק, ושמותר לי לראות. */
function apr_fact_source(array $ctx, array $c, mixed $src, ?string $fixedKind = null): array
{
    if (!is_array($src)) apr_fail('fact_source');
    $kind = $fixedKind ?? (apr_clean_choice($src['kind'] ?? null, APR_FACT_SOURCES) ?? apr_fail('fact_source'));
    $out = ['source_kind' => $kind, 'source_file_id' => null, 'source_page' => null, 'source_locator' => null, 'source_ref_pid' => null,
        'source_date' => apr_clean_date($src['date'] ?? null), 'source_note' => apr_clean_text($src['note'] ?? null, 200)];
    if ($kind === 'document' || ($kind === 'ai' && ($src['file'] ?? null) !== null)) {
        $f = apr_is_pid($src['file'] ?? null) ? apr_row('SELECT * FROM files WHERE pid = ? AND case_id = ? AND deleted_at IS NULL', [$src['file'], $c['id']]) : null;
        if (!$f || !apr_file_visible($ctx, $c, $f)) apr_fail('source_file_required');
        $page = $src['page'] ?? null;
        if ($page !== null && $page !== '') {
            if (!is_int($page) || $page < 1 || $page > ($f['pages'] !== null ? (int)$f['pages'] : 100000)) apr_fail('fact_source');
            $out['source_page'] = $page;
        }
        $out['source_file_id'] = (int)$f['id'];
        $out['source_locator'] = apr_clean_text($src['locator'] ?? null, 300);
    }
    // ביקור (13) ובדיקת מקור (09) עוד לא קיימים — אין ישות להצביע עליה, ולכן source_ref_pid נשאר ריק עד אז
    return $out;
}

/** המקור לתצוגה. מי שאין לו גישה לקובץ המקור מקבל kind בלבד — בלי מזהה, שם, עמוד, ציטוט, תאריך או מצב המסמך (§9, 10-C4). */
function apr_fact_source_out(array $ctx, array $c, array $f): array
{
    $src = ['kind' => $f['source_kind']];
    if ($f['source_file_id'] === null) return $src + ['date' => $f['source_date'], 'note' => $f['source_note']];
    if (!apr_file_visible($ctx, $c, ['id' => (int)$f['source_file_id'], 'pid' => $f['file_pid'], 'case_id' => $c['id']])) return $src + ['restricted' => true];
    $gone = $f['file_deleted'] !== null;
    return $src + ['file' => $gone ? null : $f['file_pid'], 'file_name' => $f['file_name'], 'page' => $f['source_page'] === null ? null : (int)$f['source_page'],
        'locator' => $f['source_locator'], 'date' => $f['source_date'], 'note' => $f['source_note'],
        'doc_state' => $gone ? 'deleted' : ($f['file_review'] === 'returned' ? 'returned' : null)];     // מקרי קצה 6, 7
}

function apr_fact_value_out(?int $num, ?string $text, ?string $date, ?string $unit, ?string $kind, array $attr): array
{
    return ['num' => $attr['value_type'] === 'bool' ? null : apr_fact_num_out($num), 'bool' => $attr['value_type'] === 'bool' && $num !== null ? $num !== 0 : null,
        'text' => $text, 'date' => $date, 'unit' => $unit, 'area_kind' => $kind];
}

function apr_fact_out(array $ctx, array $c, array $f, array $attr): array
{
    $int = fn($v) => $v === null ? null : (int)$v;
    return ['pid' => $f['pid'], 'attribute' => $f['attribute_key'], 'has_value' => apr_fact_has_value($f)]
        + apr_fact_value_out($int($f['value_num']), $f['value_text'], $f['value_date'], $f['unit'], $f['area_kind'], $attr)
        + ['estimated' => (bool)$f['estimated'], 'state' => $f['state'], 'method' => $f['method'], 'source' => apr_fact_source_out($ctx, $c, $f),
            'original' => $f['corrected_at'] === null ? null : apr_fact_value_out($int($f['original_num']), $f['original_text'], $f['original_date'], $f['original_unit'], $f['original_area_kind'], $attr),
            'corrected_by' => $f['corrected_name'], 'corrected_at' => $f['corrected_at'], 'correction_note' => $f['correction_note'],
            'entered_by' => $f['entered_name'], 'entered_at' => $f['entered_at'], 'verified_by' => $f['verified_name'], 'verified_at' => $f['verified_at'],
            'rejected_reason' => $f['rejected_reason'], 'note' => $f['note'], 'rev' => (int)$f['rev'], 'updated_by' => $f['updated_name'], 'updated_at' => $f['updated_at']];
}

// ── גישה ──────────────────────────────────────────────────────

/** מי רשאי להזין ולתקן: edit או documents בתיק (§9). */
function apr_fact_can_write(array $c): bool
{
    return !empty($c['perms']['edit']) || !empty($c['perms']['documents']);
}

/** מי רשאי לאמת, לדחות ולהכריע: review_docs בתיק + שמאי במשרד שמשתתף בתיק כאחראי או כשותף (§9, 10-B5). */
function apr_fact_can_judge(array $ctx, array $c): bool
{
    return !empty($c['perms']['review_docs']) && in_array($c['my_role'], ['responsible', 'partner'], true) && ($ctx['office']['role'] ?? '') === 'appraiser';
}

/**
 * התיק לפעולה על נתונים. $casePid מגיע מהבקשה, או נגזר ממזהה-בן (נתון / סתירה / קובץ) — תיק של משרד אחר, תיק שאינני משתתף בו
 * ומזהה שלא קיים: אותו 404. $need: view | write | judge. פעולה משנה על תיק סגור ← 409.
 */
function apr_fact_case(array $ctx, mixed $casePid, string $need = 'view'): array
{
    $c = apr_case_get($ctx, $casePid);
    if ($need === 'view') return $c;
    if ($need === 'write' ? !apr_fact_can_write($c) : !apr_fact_can_judge($ctx, $c)) apr_fail('forbidden');
    apr_case_writable($c);
    return $c;
}

function apr_fact_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row(APR_FACT_SELECT . ' WHERE f.pid = ? AND f.case_id = ? AND f.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_fact_rev(mixed $v): int
{
    if (!is_int($v) || $v < 1) apr_fail('rev_required');
    return $v;
}

/** העדכון היחיד של שורת נתון: rev + 1, רק אם rev לא השתנה. אפס שורות ← 409 עם הנתון שבשרת, ושום דבר לא נכתב (10-B2). */
function apr_fact_update(array $ctx, array $c, array $f, array $attr, int $rev, array $set): void
{
    $set += ['updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()];
    $n = apr_exec('UPDATE facts SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL',
        [...array_values($set), $f['id'], $rev]);
    if ($n === 1) return;
    $cur = apr_fact_row($c, $f['pid']);
    throw new AprFactChanged(apr_fact_out($ctx, $c, $cur, $attr), $cur['updated_name']);
}

// ── תלויות (§7) ───────────────────────────────────────────────

/** כל מי שנשען על הנתונים האלה מסומן "הנתון השתנה מאז השימוש". בתוך טרנזקציה. */
function apr_facts_stale(array $factIds): void
{
    foreach (array_unique($factIds) as $id) apr_exec('UPDATE fact_uses SET stale = 1, stale_at = ? WHERE fact_id = ? AND stale = 0', [apr_now(), $id]);
}

/**
 * ה-API לצרכנים (פרוסות 15–17): פריט (חישוב / עסקה / פרק דוח / חלופה) רושם שהוא נשען על נתון של התיק. רישום חוזר = "נבדק
 * מחדש" — הסימון stale יורד. נתון של תיק אחר, נתון שנמחק או שנדחה ← not_found / fact_state.
 */
function apr_fact_use(array $c, mixed $factPid, string $type, string $usedPid): void
{
    if (!in_array($type, APR_FACT_USE_TYPES, true) || !apr_is_pid($usedPid)) apr_fail('link_invalid');
    apr_tx(function () use ($c, $factPid, $type, $usedPid) {
        $f = apr_fact_row($c, $factPid);
        if ($f['state'] === 'rejected') apr_fail('fact_state');
        if (apr_exec('UPDATE fact_uses SET stale = 0, stale_at = NULL WHERE fact_id = ? AND used_in_type = ? AND used_in_pid = ?', [$f['id'], $type, $usedPid]) === 0) {
            apr_insert('fact_uses', ['fact_id' => $f['id'], 'used_in_type' => $type, 'used_in_pid' => $usedPid, 'created_at' => apr_now()]);
        }
    });
}

function apr_fact_unuse(array $c, string $type, string $usedPid): void
{
    apr_exec('DELETE FROM fact_uses WHERE used_in_type = ? AND used_in_pid = ? AND fact_id IN (SELECT id FROM facts WHERE case_id = ?)', [$type, $usedPid, $c['id']]);
}

/** הנתונים שפריט נשען עליהם, ומי מהם השתנה מאז. */
function apr_fact_uses_of(array $c, string $type, string $usedPid): array
{
    return array_map(fn($r) => ['fact' => $r['pid'], 'stale' => (bool)$r['stale'], 'stale_at' => $r['stale_at']],
        apr_rows('SELECT f.pid, u.stale, u.stale_at FROM fact_uses u JOIN facts f ON f.id = u.fact_id WHERE u.used_in_type = ? AND u.used_in_pid = ? AND f.case_id = ? ORDER BY u.id', [$type, $usedPid, $c['id']]));
}

// ── סתירות (§6) ───────────────────────────────────────────────

/** משימת בירור לאחראי על התיק, מקושרת לסתירה — אחת פתוחה לכל סתירה. מחזיר את מזהה המשימה. */
function apr_conflict_task(array $c, array $attr, string $conflictPid): int
{
    $key = 'conflict:' . $conflictPid;
    $open = apr_val("SELECT id FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $key]);
    if ($open) return (int)$open;
    $assignee = apr_val('SELECT c.responsible_user_id FROM cases c JOIN case_members m ON m.case_id = c.id AND m.user_id = c.responsible_user_id AND m.revoked_at IS NULL WHERE c.id = ?', [$c['id']]);
    return apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'clarification', 'title' => 'בירור סתירה בנתונים: ' . $attr['label'],
        'assignee_user_id' => $assignee ?: null, 'origin_key' => $key, 'link_type' => 'conflict', 'link_pid' => $conflictPid, 'created_by' => null, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
}

function apr_conflict_task_done(array $cf): void
{
    apr_exec("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')",
        [apr_now(), apr_now(), $cf['case_id'], 'conflict:' . $cf['pid']]);
}

function apr_conflict_reopen(array $c, array $attr, array $cf, array $factIds, bool $needsHuman, string $why): void
{
    $task = apr_conflict_task($c, $attr, $cf['pid']);
    apr_exec("UPDATE conflicts SET status = 'open', close_kind = NULL, close_reason = NULL, closed_by = NULL, closed_at = NULL, opened_count = opened_count + 1, task_id = ?,
        needs_human = ?, rev = rev + 1, updated_at = ? WHERE id = ?", [$task, (int)$needsHuman, apr_now(), $cf['id']]);
    apr_case_audit($c, 'conflict.reopen', 'conflict', $cf['pid'], ['attribute' => $attr['key'], 'why' => $why]);
    apr_facts_stale($factIds);
}

/**
 * מביא את הסתירות של מאפיין בתיק למצב שנגזר מהנתונים — אחרי כל שינוי, בתוך אותה טרנזקציה. $changed — נתון שערכו, יחידתו או סוג
 * השטח שלו השתנו (או שנוסף) בפעולה הזו.
 *
 * נתונים פעילים: לא נמחקו, לא נדחו, ויש להם ערך. משווים רק בתוך אותו סוג שטח ואותה משפחת יחידות (§6.1): שני ערכים שונים שם ←
 * סתירת ערך. סוג שטח "לא ידוע" או יחידות שאי אפשר להמיר, כשיש שני נתונים לפחות ← סתירה "אי אפשר להשוות" (scope '*').
 * UNIQUE (case, attribute, scope) + BEGIN IMMEDIATE: שתי בקשות מקבילות אינן יוצרות אותה סתירה פעמיים.
 * הפונקציה לעולם אינה נוגעת בשורות של facts.
 */
function apr_conflicts_sync(array $c, array $attr, ?int $changed = null): void
{
    $facts = (int)$attr['multi'] ? [] : apr_rows("SELECT * FROM facts WHERE case_id = ? AND attribute_key = ? AND deleted_at IS NULL AND state <> 'rejected'
        AND (value_num IS NOT NULL OR value_text IS NOT NULL OR value_date IS NOT NULL) ORDER BY id", [$c['id'], $attr['key']]);
    $classes = [];
    $families = [];
    $norm = [];
    $odd = false;
    foreach ($facts as $f) {
        $kind = (int)$attr['has_area_kind'] ? (string)$f['area_kind'] : '';
        [$family, $val] = apr_fact_norm($f, $attr);
        if ($kind === 'unknown' || $family === null) { $odd = true; continue; }
        $families[$kind][$family] = true;
        $classes["$kind|$family"]['v' . $val][] = (int)$f['id'];
        $norm[(int)$f['id']] = "$kind|$family|$val";
    }
    foreach ($families as $fs) if (count($fs) > 1) $odd = true;
    $want = [];
    if ($odd && count($facts) >= 2) $want['*'] = array_map(fn($f) => (int)$f['id'], $facts);
    foreach ($classes as $scope => $vals) if (count($vals) > 1) $want[$scope] = array_merge(...array_values($vals));

    $existing = array_column(apr_rows('SELECT * FROM conflicts WHERE case_id = ? AND attribute_key = ?', [$c['id'], $attr['key']]), null, 'scope');
    foreach ($want as $scope => $ids) {
        $cf = $existing[$scope] ?? null;
        if (!$cf) {
            $pid = apr_pid();
            $id = apr_insert('conflicts', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'attribute_key' => $attr['key'], 'scope' => $scope,
                'kind' => $scope === '*' ? 'incomparable' : 'value', 'task_id' => apr_conflict_task($c, $attr, $pid), 'created_at' => apr_now(), 'updated_at' => apr_now()]);
            foreach ($ids as $fid) apr_insert('conflict_facts', ['conflict_id' => $id, 'fact_id' => $fid, 'added_at' => apr_now()]);
            apr_case_audit($c, 'conflict.open', 'conflict', $pid, ['attribute' => $attr['key'], 'kind' => $scope === '*' ? 'incomparable' : 'value', 'facts' => count($ids)]);
            apr_facts_stale($ids);
            continue;
        }
        $have = array_map('intval', array_column(apr_rows('SELECT fact_id FROM conflict_facts WHERE conflict_id = ?', [$cf['id']]), 'fact_id'));
        $new = array_values(array_diff($ids, $have));
        foreach ($new as $fid) apr_insert('conflict_facts', ['conflict_id' => $cf['id'], 'fact_id' => $fid, 'added_at' => apr_now()]);
        if ($cf['status'] === 'open') {
            if ($new) apr_facts_stale($new);
            continue;
        }
        // סתירה סגורה נפתחת מחדש כשיש מידע חדש: נתון שהשתנה, נתון חדש ששונה ממה שהוכרע, סגירה אוטומטית שכבר אינה נכונה,
        // או הכרעה שאיבדה תוקף. ההכרעות הקודמות נשארות (10-A8).
        $chosen = array_map(fn($r) => $norm[(int)$r['chosen_fact_id']] ?? null,
            apr_rows('SELECT chosen_fact_id FROM conflict_resolutions WHERE conflict_id = ? AND voided_at IS NULL', [$cf['id']]));
        $differs = (bool)array_filter($new, fn($fid) => $cf['close_kind'] !== 'resolution' || !isset($norm[$fid]) || !in_array($norm[$fid], $chosen, true));
        $why = $differs ? 'new_fact' : (($changed !== null && in_array($changed, $ids, true)) ? 'fact_changed'
            : ($cf['close_kind'] === 'auto' ? 'again' : ($cf['close_kind'] === 'resolution' && !$chosen ? 'resolution_void' : null)));
        if ($why !== null) apr_conflict_reopen($c, $attr, $cf, $ids, false, $why);
    }
    // סתירה פתוחה שכבר אין לה על מה להישען (נתון נדחה, תוקן או נמחק) נסגרת לבד — אלא אם היא מחכה להכרעת אדם
    foreach ($existing as $scope => $cf) {
        if (isset($want[$scope]) || $cf['status'] !== 'open' || (int)$cf['needs_human']) continue;
        apr_exec("UPDATE conflicts SET status = 'resolved', close_kind = 'auto', closed_at = ?, rev = rev + 1, updated_at = ? WHERE id = ?", [apr_now(), apr_now(), $cf['id']]);
        apr_conflict_task_done($cf);
        apr_case_audit($c, 'conflict.auto_close', 'conflict', $cf['pid'], ['attribute' => $attr['key']]);
    }
}

/**
 * נתון שנבחר בהכרעה ונדחה, נמחק או שערכו תוקן: ההכרעה מסומנת "לא תקפה" (היא אינה נמחקת), וסתירה שנשארה בלי הכרעה תקפה נפתחת
 * מחדש ומחכה לאדם (מקרה קצה 8). בתוך טרנזקציה, לפני apr_conflicts_sync.
 */
function apr_fact_resolutions_void(array $c, array $attr, int $factId, string $reason): void
{
    foreach (apr_rows('SELECT r.id, r.conflict_id FROM conflict_resolutions r WHERE r.chosen_fact_id = ? AND r.voided_at IS NULL', [$factId]) as $r) {
        apr_exec('UPDATE conflict_resolutions SET voided_at = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL', [apr_now(), $reason, $r['id']]);
        $cf = apr_row('SELECT * FROM conflicts WHERE id = ?', [$r['conflict_id']]);
        if ($cf['status'] === 'resolved' && $cf['close_kind'] === 'resolution'
            && !apr_val('SELECT 1 FROM conflict_resolutions WHERE conflict_id = ? AND voided_at IS NULL', [$cf['id']])) {
            $ids = array_map('intval', array_column(apr_rows('SELECT fact_id FROM conflict_facts WHERE conflict_id = ?', [$cf['id']]), 'fact_id'));
            apr_conflict_reopen($c, $attr, $cf, $ids, true, 'resolution_void');
        }
    }
}

// ── הזנה, תיקון ומצב ──────────────────────────────────────────

/**
 * מוסיף נתון לתיק — המקום היחיד שיוצר שורה ב-facts (גם החילוץ של פרוסה 12 ייכנס מכאן). בתוך טרנזקציה: השורה, היומן והסתירות.
 * $row: attribute_key, state, method, entered_by, שדות הערך והמקור.
 */
function apr_fact_insert(array $c, array $attr, array $row): string
{
    $pid = apr_pid();
    $id = apr_insert('facts', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'attribute_key' => $attr['key'], 'entered_at' => apr_now(), 'updated_at' => apr_now()] + $row);
    apr_case_audit($c, 'fact.add', 'fact', $pid, ['attribute' => $attr['key'], 'state' => $row['state'], 'source' => $row['source_kind']]);
    apr_conflicts_sync($c, $attr, $id);
    apr_case_touch($c);
    return $pid;
}

/**
 * הזנה ידנית: case, attribute, value, unit?, area_kind?, estimated?, zero_ok?, source {kind, file?, page?, locator?, date?, note?},
 * note?, state? ('needs_clarification' — "חיפשנו ולא מצאנו": בלי ערך, עם הערה).
 */
function apr_fact_add(array $ctx, array $in): array
{
    $c = apr_fact_case($ctx, $in['case'] ?? null, 'write');
    $attr = apr_attr($in['attribute'] ?? null, true);
    $state = apr_clean_choice($in['state'] ?? 'manual', ['manual', 'needs_clarification']) ?? 'manual';
    [$num, $text, $date, $unit, $kind] = apr_fact_value($attr, $in, $state === 'needs_clarification');
    $note = apr_clean_text($in['note'] ?? null, 1000);
    if ($num === null && $text === null && $date === null && $note === null) apr_fail('fact_note_required');
    $src = apr_fact_source($ctx, $c, $in['source'] ?? null);
    return apr_tx(function () use ($ctx, $c, $attr, $state, $num, $text, $date, $unit, $kind, $note, $src, $in) {
        apr_case_still_open($c);
        $pid = apr_fact_insert($c, $attr, ['value_num' => $num, 'value_text' => $text, 'value_date' => $date, 'unit' => $unit, 'area_kind' => $kind,
            'estimated' => (int)(($in['estimated'] ?? false) === true), 'state' => $state, 'method' => 'manual', 'note' => $note,
            'entered_by' => (int)$ctx['user']['id'], 'updated_by' => (int)$ctx['user']['id']] + $src);
        return ['fact' => apr_fact_out($ctx, $c, apr_fact_row($c, $pid), $attr)];
    });
}

/** תיקון שורה אחת, בתוך טרנזקציה. מחזיר true אם משהו השתנה. $in: rev + מה שמשתנה (value, unit, area_kind, estimated, note, source, correction_note). */
function apr_fact_correct_tx(array $ctx, array $c, array $f, array $in): bool
{
    $attr = apr_attr($f['attribute_key']);
    $rev = apr_fact_rev($in['rev'] ?? null);
    if ($f['state'] === 'rejected') apr_fail('fact_state');
    $set = [];
    $touchesValue = array_key_exists('value', $in) || array_key_exists('unit', $in) || array_key_exists('area_kind', $in);
    if ($touchesValue) {
        // מה שלא נשלח — נשאר כפי שהוא; הערך עובר שוב את כל הבדיקות של המאפיין
        $cur = ['value' => match ($attr['value_type']) { 'number', 'money' => apr_fact_num_out($f['value_num']), 'bool' => $f['value_num'] === null ? null : (int)$f['value_num'] !== 0,
            'date' => $f['value_date'], default => $f['value_text'] }, 'unit' => $f['unit'], 'area_kind' => $f['area_kind'], 'zero_ok' => $f['value_num'] !== null && (int)$f['value_num'] === 0];
        [$num, $text, $date, $unit, $kind] = apr_fact_value($attr, array_intersect_key($in, array_flip(['value', 'unit', 'area_kind', 'zero_ok'])) + $cur, $f['state'] === 'needs_clarification');
        $new = ['value_num' => $num, 'value_text' => $text, 'value_date' => $date, 'unit' => $unit, 'area_kind' => $kind];
        foreach ($new as $k => $v) if (($v === null ? null : (string)$v) !== ($f[$k] === null ? null : (string)$f[$k])) $set[$k] = $v;
    }
    $valueChanged = (bool)$set;
    if (array_key_exists('estimated', $in) && (int)($in['estimated'] === true) !== (int)$f['estimated']) $set['estimated'] = (int)($in['estimated'] === true);
    if (array_key_exists('note', $in) && ($n = apr_clean_text($in['note'], 1000)) !== $f['note']) $set['note'] = $n;
    $sourceChanged = false;
    if (array_key_exists('source', $in)) {
        foreach (array_diff_key(apr_fact_source($ctx, $c, $in['source'], $f['source_kind']), ['source_kind' => 0]) as $k => $v) {
            if (($v === null ? null : (string)$v) !== ($f[$k] === null ? null : (string)$f[$k])) { $set[$k] = $v; $sourceChanged = true; }
        }
    }
    if (!$set) {
        if ((int)$f['rev'] !== $rev) apr_fact_update($ctx, $c, $f, $attr, $rev, ['updated_at' => apr_now()]);   // rev ישן ← 409 גם כשאין מה לשנות
        return false;
    }
    if ($valueChanged) {
        // ערך המקור נשמר פעם אחת — בתיקון הראשון; תיקון שני אינו דורס אותו (10-B1)
        if ($f['corrected_at'] === null) $set += ['original_num' => $f['value_num'], 'original_text' => $f['value_text'], 'original_date' => $f['value_date'],
            'original_unit' => $f['unit'], 'original_area_kind' => $f['area_kind']];
        $set += ['corrected_by' => (int)$ctx['user']['id'], 'corrected_at' => apr_now(), 'correction_note' => apr_clean_text($in['correction_note'] ?? null, 500)];
    }
    // נתון מאומת שהערך או המקור שלו השתנו כבר אינו "מאומת" — האימות היה על מה שהיה
    if ($f['state'] === 'verified' && ($valueChanged || $sourceChanged)) $set += ['state' => 'manual', 'verified_by' => null, 'verified_at' => null];
    apr_fact_update($ctx, $c, $f, $attr, $rev, $set);
    $show = fn($num, $text, $date, $unit, $kind) => array_filter(apr_fact_value_out($num === null ? null : (int)$num, $text, $date, $unit, $kind, $attr), fn($v) => $v !== null);
    apr_case_audit($c, 'fact.correct', 'fact', $f['pid'], ['attribute' => $attr['key'], 'fields' => array_values(array_diff(array_keys($set), ['updated_by', 'updated_at']))]
        + ($valueChanged ? ['from' => $show($f['value_num'], $f['value_text'], $f['value_date'], $f['unit'], $f['area_kind']),
            'to' => $show($set['value_num'] ?? $f['value_num'], $set['value_text'] ?? $f['value_text'], $set['value_date'] ?? $f['value_date'],
                array_key_exists('unit', $set) ? $set['unit'] : $f['unit'], array_key_exists('area_kind', $set) ? $set['area_kind'] : $f['area_kind'])] : []),
        $set['correction_note'] ?? null);
    if ($valueChanged) {
        apr_facts_stale([(int)$f['id']]);
        apr_fact_resolutions_void($c, $attr, (int)$f['id'], 'fact_changed');
        apr_conflicts_sync($c, $attr, (int)$f['id']);
    }
    return true;
}

function apr_fact_correct(array $ctx, array $in): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('facts', $in['fact'] ?? null), 'write');
    return apr_tx(function () use ($ctx, $c, $in) {
        apr_case_still_open($c);
        $f = apr_fact_row($c, $in['fact'] ?? null);
        if (apr_fact_correct_tx($ctx, $c, $f, $in)) apr_case_touch($c);
        return ['fact' => apr_fact_out($ctx, $c, apr_fact_row($c, $f['pid']), apr_attr($f['attribute_key']))];
    });
}

/**
 * שינוי מצב: fact, rev, do. verify / reject — שמאי בתיק (10-B5); clarify ("דורש בירור") / manual (חזרה מ"דורש בירור") — מי שמזין.
 * reject דורש reason; clarify דורש note. אימות ודחייה אינם נוגעים במסמך המקור (10-C2).
 */
function apr_fact_state(array $ctx, array $in): array
{
    $do = apr_clean_choice($in['do'] ?? null, ['verify', 'reject', 'clarify', 'manual']) ?? apr_fail('fact_state');
    $c = apr_fact_case($ctx, apr_doc_case_pid('facts', $in['fact'] ?? null), in_array($do, ['verify', 'reject'], true) ? 'judge' : 'write');
    $rev = apr_fact_rev($in['rev'] ?? null);
    $reason = $do === 'reject' ? apr_reason($in['reason'] ?? null) : null;
    $note = $do === 'clarify' ? (apr_clean_text($in['note'] ?? null, 1000) ?? apr_fail('fact_note_required')) : null;
    return apr_tx(function () use ($ctx, $c, $in, $do, $rev, $reason, $note) {
        apr_case_still_open($c);
        $f = apr_fact_row($c, $in['fact'] ?? null);
        $attr = apr_attr($f['attribute_key']);
        $uid = (int)$ctx['user']['id'];
        $from = match ($do) { 'verify' => ['proposed', 'manual', 'needs_clarification'], 'reject' => ['proposed', 'manual', 'needs_clarification', 'verified'],
            'clarify' => ['proposed', 'manual', 'verified'], 'manual' => ['needs_clarification'] };
        if ((int)$f['rev'] === $rev && !in_array($f['state'], $from, true)) apr_fail('fact_state');
        if (in_array($do, ['verify', 'manual'], true) && !apr_fact_has_value($f)) apr_fail('fact_no_value');
        if ($do === 'verify' && $f['source_kind'] === 'ai') {
            // 10-B4: נתון מ-AI מאומת רק מול מקור שאפשר לפתוח — קובץ קיים של התיק, או ישות מקור
            $openable = $f['source_ref_pid'] !== null || ($f['source_file_id'] !== null && $f['file_deleted'] === null);
            if (!$openable) apr_fail('fact_source_required');
        }
        apr_fact_update($ctx, $c, $f, $attr, $rev, match ($do) {
            'verify' => ['state' => 'verified', 'verified_by' => $uid, 'verified_at' => apr_now()],
            'reject' => ['state' => 'rejected', 'rejected_reason' => $reason, 'verified_by' => null, 'verified_at' => null],
            'clarify' => ['state' => 'needs_clarification', 'note' => $note, 'verified_by' => null, 'verified_at' => null],
            'manual' => ['state' => 'manual'],
        });
        apr_case_audit($c, 'fact.' . $do, 'fact', $f['pid'], ['attribute' => $attr['key'], 'from' => $f['state']], $reason ?? $note);
        if ($do === 'reject') {
            apr_facts_stale([(int)$f['id']]);
            apr_fact_resolutions_void($c, $attr, (int)$f['id'], 'fact_rejected');
            apr_conflicts_sync($c, $attr);
        }
        apr_case_touch($c);
        return ['fact' => apr_fact_out($ctx, $c, apr_fact_row($c, $f['pid']), $attr)];
    });
}

/**
 * מחיקה רכה, בנימוק (מקרה קצה 12). מי שהזין מוחק נתון שלו שעוד לא אומת; כל נתון אחר — שמאי בתיק. מי שנשען עליו מסומן stale.
 */
function apr_fact_delete(array $ctx, array $in): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('facts', $in['fact'] ?? null), 'view');
    $rev = apr_fact_rev($in['rev'] ?? null);
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $reason) {
        $f = apr_fact_row($c, $in['fact'] ?? null);
        $mine = (int)$f['entered_by'] === (int)$ctx['user']['id'] && in_array($f['state'], ['manual', 'needs_clarification'], true) && apr_fact_can_write($c);
        if (!$mine && !apr_fact_can_judge($ctx, $c)) apr_fail('forbidden');
        apr_case_writable($c);
        apr_case_still_open($c);
        $attr = apr_attr($f['attribute_key']);
        apr_fact_update($ctx, $c, $f, $attr, $rev, ['deleted_at' => apr_now(), 'deleted_by' => (int)$ctx['user']['id'], 'delete_reason' => $reason]);
        apr_case_audit($c, 'fact.delete', 'fact', $f['pid'], ['attribute' => $attr['key'], 'state' => $f['state']], $reason);
        apr_facts_stale([(int)$f['id']]);
        apr_fact_resolutions_void($c, $attr, (int)$f['id'], 'fact_deleted');
        apr_conflicts_sync($c, $attr);
        apr_case_touch($c);
        return [];
    });
}

/**
 * קובץ המקור נמחק (מקרה קצה 6): הנתונים נשארים, ומי שהיה "מאומת" חוזר ל"דורש בירור". נקרא מתוך הטרנזקציה של apr_file_delete.
 */
function apr_facts_on_file_delete(array $c, array $file): void
{
    foreach (apr_rows("SELECT id, pid, attribute_key FROM facts WHERE source_file_id = ? AND state = 'verified' AND deleted_at IS NULL", [$file['id']]) as $f) {
        apr_exec("UPDATE facts SET state = 'needs_clarification', verified_by = NULL, verified_at = NULL, rev = rev + 1, updated_by = ?, updated_at = ? WHERE id = ? AND state = 'verified'",
            [apr_actor(), apr_now(), $f['id']]);
        apr_case_audit($c, 'fact.source_deleted', 'fact', $f['pid'], ['attribute' => $f['attribute_key'], 'from' => 'verified']);
    }
}

// ── הכרעה (§6.3) ──────────────────────────────────────────────

function apr_conflict_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM conflicts WHERE pid = ? AND case_id = ?', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/**
 * הכרעה למטרה: conflict, rev, purpose, fact, reasoning. בלי מטרה או בלי נימוק ← נדחית (10-A6). הנתון שנבחר — חלק פעיל מהסתירה.
 * הכרעה קודמת לאותה מטרה מסומנת "הוחלפה". שום נתון אינו משתנה (10-A7).
 */
function apr_conflict_resolve(array $ctx, array $in): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('conflicts', $in['conflict'] ?? null), 'judge');
    $rev = apr_fact_rev($in['rev'] ?? null);
    $purpose = apr_clean_text($in['purpose'] ?? null, 120) ?? apr_fail('purpose_required');
    $reasoning = apr_clean_text($in['reasoning'] ?? null, 1000) ?? apr_fail('reasoning_required');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $purpose, $reasoning) {
        apr_case_still_open($c);
        $cf = apr_conflict_row($c, $in['conflict'] ?? null);
        $f = apr_is_pid($in['fact'] ?? null) ? apr_row("SELECT f.* FROM facts f JOIN conflict_facts cf ON cf.fact_id = f.id WHERE cf.conflict_id = ? AND f.pid = ? AND f.deleted_at IS NULL AND f.state <> 'rejected'",
            [$cf['id'], $in['fact']]) : null;
        if (!$f || !apr_fact_has_value($f)) apr_fail('conflict_fact');
        if ($cf['status'] === 'dismissed') apr_fail('conflict_state');
        $n = apr_exec("UPDATE conflicts SET status = 'resolved', close_kind = 'resolution', close_reason = NULL, closed_by = ?, closed_at = ?, needs_human = 0, rev = rev + 1, updated_at = ?
            WHERE id = ? AND rev = ?", [$ctx['user']['id'], apr_now(), apr_now(), $cf['id'], $rev]);
        if ($n !== 1) apr_fail('conflict_changed');
        apr_exec("UPDATE conflict_resolutions SET voided_at = ?, void_reason = 'superseded' WHERE conflict_id = ? AND purpose = ? AND voided_at IS NULL", [apr_now(), $cf['id'], $purpose]);
        $pid = apr_pid();
        apr_insert('conflict_resolutions', ['pid' => $pid, 'conflict_id' => $cf['id'], 'purpose' => $purpose, 'chosen_fact_id' => $f['id'], 'reasoning' => $reasoning,
            'resolved_by' => $ctx['user']['id'], 'resolved_at' => apr_now()]);
        apr_conflict_task_done($cf);
        apr_case_audit($c, 'conflict.resolve', 'conflict', $cf['pid'], ['attribute' => $cf['attribute_key'], 'purpose' => $purpose, 'fact' => $f['pid']], $reasoning);
        apr_case_touch($c);
        return ['resolution' => $pid, 'conflict' => apr_conflict_out($ctx, $c, apr_conflict_row($c, $cf['pid']))];
    });
}

/**
 * "אין סתירה": conflict, rev, reasoning, ואפשר fixes [{fact, rev, area_kind}] — תיקון סוג השטח באותה פעולה (§6.3).
 * התיקון הוא תיקון רגיל של נתון: המקורי נשמר, וה-rev נבדק.
 */
function apr_conflict_dismiss(array $ctx, array $in): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('conflicts', $in['conflict'] ?? null), 'judge');
    $rev = apr_fact_rev($in['rev'] ?? null);
    $reasoning = apr_clean_text($in['reasoning'] ?? null, 1000) ?? apr_fail('reasoning_required');
    $fixes = $in['fixes'] ?? [];
    if (!is_array($fixes) || count($fixes) > 20) apr_fail('content_invalid');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $reasoning, $fixes) {
        apr_case_still_open($c);
        $cf = apr_conflict_row($c, $in['conflict'] ?? null);
        if ((int)$cf['rev'] !== $rev) apr_fail('conflict_changed');
        if ($cf['status'] !== 'open') apr_fail('conflict_state');
        foreach ($fixes as $fx) {
            if (!is_array($fx)) apr_fail('content_invalid');
            $f = apr_fact_row($c, $fx['fact'] ?? null);
            if (!apr_val('SELECT 1 FROM conflict_facts WHERE conflict_id = ? AND fact_id = ?', [$cf['id'], $f['id']])) apr_fail('conflict_fact');
            apr_fact_correct_tx($ctx, $c, $f, ['rev' => $fx['rev'] ?? null, 'area_kind' => $fx['area_kind'] ?? null, 'correction_note' => $reasoning]);
        }
        // התיקון עשוי היה לסגור את הסתירה לבד; בכל מקרה היא נרשמת כ"אין סתירה", עם הנימוק של השמאי
        apr_exec("UPDATE conflicts SET status = 'dismissed', close_kind = 'dismissed', close_reason = ?, closed_by = ?, closed_at = ?, needs_human = 0, rev = rev + 1, updated_at = ? WHERE id = ?",
            [$reasoning, $ctx['user']['id'], apr_now(), apr_now(), $cf['id']]);
        apr_conflict_task_done($cf);
        apr_case_audit($c, 'conflict.dismiss', 'conflict', $cf['pid'], ['attribute' => $cf['attribute_key'], 'fixes' => count($fixes)], $reasoning);
        apr_case_touch($c);
        return ['conflict' => apr_conflict_out($ctx, $c, apr_conflict_row($c, $cf['pid']))];
    });
}

// ── תצוגה ─────────────────────────────────────────────────────

function apr_conflict_out(array $ctx, array $c, array $cf): array
{
    $attr = apr_attr($cf['attribute_key']);
    $facts = apr_rows(APR_FACT_SELECT . ' JOIN conflict_facts cf ON cf.fact_id = f.id WHERE cf.conflict_id = ? ORDER BY f.id', [$cf['id']]);
    $pids = array_column($facts, 'pid', 'id');
    return ['pid' => $cf['pid'], 'attribute' => apr_attr_out($attr), 'kind' => $cf['kind'], 'status' => $cf['status'], 'close_kind' => $cf['close_kind'], 'close_reason' => $cf['close_reason'],
        'closed_by' => $cf['closed_by'] === null ? null : apr_val('SELECT name FROM users WHERE id = ?', [$cf['closed_by']]), 'closed_at' => $cf['closed_at'],
        'needs_human' => (bool)$cf['needs_human'], 'opened_count' => (int)$cf['opened_count'], 'rev' => (int)$cf['rev'], 'created_at' => $cf['created_at'],
        'task' => $cf['task_id'] === null ? null : apr_val('SELECT pid FROM tasks WHERE id = ?', [$cf['task_id']]),
        // נתון שנמחק נשאר ברשימה (בלי ערך) — כדי שההיסטוריה של הסתירה תישאר שלמה
        'facts' => array_map(fn($f) => apr_fact_out($ctx, $c, $f, $attr) + ['deleted' => $f['deleted_at'] !== null,
            'active' => $f['deleted_at'] === null && $f['state'] !== 'rejected' && apr_fact_has_value($f)], $facts),
        'resolutions' => array_map(fn($r) => ['pid' => $r['pid'], 'purpose' => $r['purpose'], 'fact' => $pids[$r['chosen_fact_id']] ?? null, 'reasoning' => $r['reasoning'],
            'by' => $r['by_name'], 'at' => $r['resolved_at'], 'valid' => $r['voided_at'] === null, 'void_reason' => $r['void_reason'], 'voided_at' => $r['voided_at']],
            apr_rows('SELECT r.*, u.name AS by_name FROM conflict_resolutions r JOIN users u ON u.id = r.resolved_by WHERE r.conflict_id = ? ORDER BY r.id', [$cf['id']]))];
}

function apr_fact_perms(array $ctx, array $c): array
{
    $open = $c['status'] !== 'closed';
    return ['write' => $open && apr_fact_can_write($c), 'judge' => $open && apr_fact_can_judge($ctx, $c)];
}

/**
 * "נתוני הנכס" של תיק: לכל מאפיין בקטלוג — הנתונים שנרשמו לו (כולל שנדחו; בלי שנמחקו), הערך המוסכם אם יש, והסתירות.
 * מאפיין בלי נתון: known = false, value = null, facts = [] — "לא ידוע", לא אפס ולא מחרוזת ריקה (10-A1).
 */
function apr_case_facts(array $ctx, array $q): array
{
    $c = apr_fact_case($ctx, $q['case'] ?? null);
    $byAttr = [];
    foreach (apr_rows(APR_FACT_SELECT . ' WHERE f.case_id = ? AND f.deleted_at IS NULL ORDER BY f.id', [$c['id']]) as $f) $byAttr[$f['attribute_key']][] = $f;
    $conf = [];
    foreach (apr_rows('SELECT pid, attribute_key, status, kind FROM conflicts WHERE case_id = ? ORDER BY id', [$c['id']]) as $cf) $conf[$cf['attribute_key']][] = ['pid' => $cf['pid'], 'status' => $cf['status'], 'kind' => $cf['kind']];
    $attrs = [];
    foreach (apr_attrs_all() as $a) {
        $rows = $byAttr[$a['key']] ?? [];
        if ($a['status'] !== 'active' && !$rows) continue;
        $active = array_values(array_filter($rows, fn($f) => $f['state'] !== 'rejected' && apr_fact_has_value($f)));
        // ערך מוסכם: יש נתון פעיל, וכל הנתונים הפעילים שקולים (אותו סוג שטח, אותה משפחת יחידות, אותו ערך). אחרת — null
        $agreed = null;
        if ($active && !(int)$a['multi']) {
            $keys = array_unique(array_map(fn($f) => json_encode([$f['area_kind'], apr_fact_norm($f, $a)]), $active));
            if (count($keys) === 1 && apr_fact_norm($active[0], $a)[0] !== null) {
                $best = array_values(array_filter($active, fn($f) => $f['state'] === 'verified'))[0] ?? $active[0];
                $agreed = apr_fact_value_out($best['value_num'] === null ? null : (int)$best['value_num'], $best['value_text'], $best['value_date'], $best['unit'], $best['area_kind'], $a)
                    + ['verified' => $best['state'] === 'verified', 'fact' => $best['pid']];
            }
        }
        $attrs[] = apr_attr_out($a) + ['known' => (bool)$active, 'value' => $agreed, 'facts' => array_map(fn($f) => apr_fact_out($ctx, $c, $f, $a), $rows), 'conflicts' => $conf[$a['key']] ?? []];
    }
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title']], 'perms' => apr_fact_perms($ctx, $c),
        'groups' => APR_ATTR_GROUPS, 'area_kinds' => APR_AREA_KINDS, 'units' => array_map('array_keys', APR_UNITS), 'sources' => APR_FACT_SOURCES, 'attributes' => $attrs,
        'conflicts_open' => (int)apr_val("SELECT COUNT(*) FROM conflicts WHERE case_id = ? AND status = 'open'", [$c['id']])];
}

/** נתון אחד: הערך, ההיסטוריה המלאה שלו מיומן הפעולות, מי נשען עליו, והסתירות שהוא חלק מהן. */
function apr_fact_view(array $ctx, array $q): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('facts', $q['fact'] ?? null));
    $f = apr_fact_row($c, $q['fact'] ?? null);
    $attr = apr_attr($f['attribute_key']);
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status']], 'perms' => apr_fact_perms($ctx, $c), 'attribute' => apr_attr_out($attr),
        'fact' => apr_fact_out($ctx, $c, $f, $attr),
        'history' => array_map(fn($r) => ['at' => $r['at'], 'action' => $r['action'], 'by' => $r['name'], 'meta' => json_decode($r['meta'], true) ?: (object)[], 'reason' => $r['reason']],
            apr_rows("SELECT a.at, a.action, a.meta, a.reason, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.entity_type = 'fact' AND a.entity_pid = ? AND a.case_id = ? ORDER BY a.id", [$f['pid'], $c['id']])),
        'uses' => array_map(fn($u) => ['type' => $u['used_in_type'], 'pid' => $u['used_in_pid'], 'stale' => (bool)$u['stale'], 'stale_at' => $u['stale_at']],
            apr_rows('SELECT * FROM fact_uses WHERE fact_id = ? ORDER BY id', [$f['id']])),
        'conflicts' => array_map(fn($r) => ['pid' => $r['pid'], 'status' => $r['status'], 'kind' => $r['kind']],
            apr_rows('SELECT c.pid, c.status, c.kind FROM conflicts c JOIN conflict_facts cf ON cf.conflict_id = c.id WHERE cf.fact_id = ? ORDER BY c.id', [$f['id']]))];
}

function apr_case_conflicts(array $ctx, array $q): array
{
    $c = apr_fact_case($ctx, $q['case'] ?? null);
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title']], 'perms' => apr_fact_perms($ctx, $c),
        'conflicts' => array_map(fn($cf) => apr_conflict_out($ctx, $c, $cf), apr_rows("SELECT * FROM conflicts WHERE case_id = ? ORDER BY (status = 'open') DESC, id DESC", [$c['id']]))];
}

function apr_conflict_view(array $ctx, array $q): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('conflicts', $q['conflict'] ?? null));
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title']], 'perms' => apr_fact_perms($ctx, $c),
        'area_kinds' => APR_AREA_KINDS, 'conflict' => apr_conflict_out($ctx, $c, apr_conflict_row($c, $q['conflict'] ?? null))];
}

/** מסך הקובץ ← "נתונים": הנתונים שמקורם בקובץ, לפי עמוד. מי שאין לו גישה לקובץ — הקובץ "לא נמצא". */
function apr_file_facts(array $ctx, array $q): array
{
    $c = apr_fact_case($ctx, apr_doc_case_pid('files', $q['file'] ?? null));
    $file = apr_file_row($c, $q['file'] ?? null);
    if (!apr_file_visible($ctx, $c, $file)) apr_fail('not_found');
    $attrs = array_column(apr_attrs_all(), null, 'key');
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status']], 'perms' => apr_fact_perms($ctx, $c),
        'file' => ['pid' => $file['pid'], 'name' => $file['original_name'], 'mime' => $file['mime'], 'pages' => $file['pages'] === null ? null : (int)$file['pages'],
            'preview' => in_array($file['mime'], APR_FILE_PREVIEW, true)],
        'facts' => array_map(fn($f) => apr_fact_out($ctx, $c, $f, $attrs[$f['attribute_key']]) + ['label' => $attrs[$f['attribute_key']]['label']],
            apr_rows(APR_FACT_SELECT . ' WHERE f.source_file_id = ? AND f.case_id = ? AND f.deleted_at IS NULL ORDER BY (f.source_page IS NULL), f.source_page, f.id', [$file['id'], $c['id']]))];
}
