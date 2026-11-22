<?php
/**
 * פרוסה 17 — הדוח: מה שאינו פעולת API (docs/spec/17-report.md §4–§6, §9). מקטע "דוח" בפרופיל, הבלוקים של פרק, בניית פרק מנתוני התיק
 * **בקוד** (לא בבינה), פתרון ההפניות (fact / data_table / image) לערכים של הרגע, ממצאי הבקרה, והניסוח בבינה דרך השער (ai_run).
 *
 * שלושה כללים שהקובץ שומר עליהם:
 *  · **המסך אינו מחשב, וגם הקובץ הזה לא.** כל מספר כספי מגיע כמחרוזת מהמנוע של 15 / 16 (apr_comp_set_calc, apr_approach_calc, apr_val_money).
 *  · **חסר אינו אפס.** נתון שאינו ידוע הוא בלוק note_missing — לעולם לא 0 (17-A4).
 *  · **הבינה אינה רואה שווי.** methods / comps / assume / conclusion אינם נשלחים למודל בשום תצורה (17-A7), והמודל מקבל רק את נתוני הפרק האחד.
 */

declare(strict_types=1);

const APR_REPORT_NO_AI = ['methods', 'comps', 'assume', 'conclusion'];
const APR_REPORT_TRIGGERS = ['visit_completed', 'manual'];
const APR_REPORT_TABLES = ['identifiers', 'documents', 'source_checks', 'comps', 'adjustments', 'alternatives', 'approaches', 'assumptions'];
const APR_REPORT_SRC_TYPES = ['case', 'fact', 'file', 'source_check', 'visit', 'comp'];
const APR_REPORT_MAX_BLOCKS = 200;
const APR_REPORT_MAX_SECTIONS = 30;
/** ממצאי הבקרה (§9): ברירת המחדל, והאם אפשר להוריד מ"חוסם" (ארבעת הראשונים — לא). */
const APR_REPORT_FINDINGS = [
    'no_approver' => ['block', true], 'mandatory_empty' => ['block', true], 'ai_unreviewed' => ['block', true], 'image_not_report' => ['block', true],
    'ai_number' => ['block', false], 'proposal_pending' => ['block', false],
    'req_open' => ['warn', false], 'conflict_open' => ['warn', false], 'fact_unverified' => ['warn', false], 'source_unchecked' => ['warn', false], 'task_open' => ['warn', false],
    'small_sample' => ['warn', false], 'comp_missing' => ['warn', false], 'alt_no_key_date' => ['warn', false], 'alt_no_assumptions' => ['warn', false], 'note_missing' => ['warn', false],
    'alt_no_conclusion' => ['warn', false], 'fact_changed' => ['warn', false], 'doc_gone' => ['warn', false],
];
const APR_REPORT_AI_WAITS = ['policy', 'case_no_ai', 'no_connection', 'quota', 'no_assignment', 'model_not_allowed', 'failed'];
const APR_REPORT_AI_MAX_OUTPUT = 4000;
const APR_REPORT_AI_SYSTEM = <<<'TXT'
אתה מנסח פרק אחד בטיוטת דוח שמאות מקרקעין, בעברית עניינית ורשמית.
הכללים:
1. נסח אך ורק על בסיס הנתונים שקיבלת בהודעה. אל תוסיף עובדה, מספר, תאריך או שם שאינם שם.
2. כשנתון חסר — כתוב "לא ידוע". אל תשלים ואל תעריך.
3. אין לכתוב שווי, מחיר מוערך, מסקנה, המלצה או הערכת כדאיות — בשום ניסוח.
4. החזר JSON בלבד, בלי טקסט לפניו או אחריו: {"blocks":[{"text":"…","src":"d1"}]}. כל בלוק הוא פסקה אחת, ו-src הוא המזהה של הנתון שעליו היא נשענת (אחד מהמזהים שקיבלת).
TXT;

// ── מקטע "דוח" בפרופיל (§4) ───────────────────────────────────

function apr_pc_report_default(): array
{
    $s = fn(string $key, string $title, bool $mandatory, string $build, bool $ai) => ['key' => $key, 'title' => $title, 'mandatory' => $mandatory, 'build' => $build, 'ai' => $ai];
    return ['trigger' => 'visit_completed', 'sections' => [
        $s('purpose', 'מטרת ההזמנה והמועד הקובע', true, 'data', false), $s('property', 'זיהוי הנכס והזכויות', true, 'data', true), $s('area_desc', 'סביבת הנכס ותיאורו', true, 'data', true),
        $s('visit', 'הביקור', true, 'data', true), $s('planning', 'המצב הרישומי והתכנוני', true, 'data', true), $s('documents', 'מסמכים והיתרים', false, 'data', false),
        $s('methods', 'שיטות השומה', true, 'data', false), $s('comps', 'עסקאות, התאמות וחישובים', true, 'data', false), $s('assume', 'הנחות ומגבלות', true, 'data', false),
        $s('conclusion', 'מסקנת השמאי', true, 'manual', false), $s('appendix', 'נספחים', false, 'data', false)],
        'findings' => array_map(fn($f) => $f[0], APR_REPORT_FINDINGS), 'include_excluded_comps' => false, 'include_unverified' => 'marked'];
}

/** אימות המקטע בשמירת פרופיל. ai נכפה ל-false בארבעת הפרקים של ש-23 ובפרק שנכתב ידנית; ממצא "נעול" נשאר חוסם. */
function apr_pc_report(mixed $v): array
{
    $d = apr_pc_report_default();
    if ($v === null) return $d;
    if (!is_array($v) || (array_is_list($v) && $v !== [])) apr_fail('content_invalid');
    $sections = [];
    if (array_key_exists('sections', $v)) {
        if (!is_array($v['sections']) || !array_is_list($v['sections']) || count($v['sections']) > APR_REPORT_MAX_SECTIONS) apr_fail('content_invalid');
        foreach ($v['sections'] as $s) {
            if (!is_array($s)) apr_fail('content_invalid');
            $key = apr_pc_key($s['key'] ?? null);
            if (isset($sections[$key])) apr_fail('content_invalid');
            foreach (['mandatory', 'ai'] as $b) if (array_key_exists($b, $s) && !is_bool($s[$b])) apr_fail('content_invalid');
            $build = apr_pc_enum($s['build'] ?? null, ['data', 'manual'], 'data');
            $sections[$key] = ['key' => $key, 'title' => apr_pc_text($s['title'] ?? null, 120), 'mandatory' => ($s['mandatory'] ?? false) === true, 'build' => $build,
                'ai' => ($s['ai'] ?? false) === true && $build === 'data' && !in_array($key, APR_REPORT_NO_AI, true)];
        }
    } else {
        $sections = array_column($d['sections'], null, 'key');
    }
    $findings = $d['findings'];
    if (array_key_exists('findings', $v)) {
        if (!is_array($v['findings'])) apr_fail('content_invalid');
        foreach ($v['findings'] as $k => $level) {
            if (!isset(APR_REPORT_FINDINGS[$k]) || !in_array($level, ['block', 'warn'], true)) apr_fail('content_invalid');
            $findings[$k] = APR_REPORT_FINDINGS[$k][1] ? 'block' : $level;
        }
    }
    if (array_key_exists('include_excluded_comps', $v) && !is_bool($v['include_excluded_comps'])) apr_fail('content_invalid');
    return ['trigger' => apr_pc_enum($v['trigger'] ?? null, APR_REPORT_TRIGGERS, $d['trigger']), 'sections' => array_values($sections), 'findings' => $findings,
        'include_excluded_comps' => ($v['include_excluded_comps'] ?? false) === true, 'include_unverified' => apr_pc_enum($v['include_unverified'] ?? null, ['marked', 'omit'], 'marked')];
}

/** מקטע "דוח" של התיק — לפי גרסת הפרופיל **של התיק** (שינוי בפרופיל משפיע על תיקים חדשים בלבד, 17-A3). */
function apr_report_profile(array $c): array
{
    return apr_pc_report($c['profile_version_id'] === null ? null : (apr_case_version_content((int)$c['profile_version_id'])['report'] ?? null));
}

// ── בלוקים (§5) ───────────────────────────────────────────────

function apr_report_hash(mixed $v): string
{
    return hash('sha256', (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function apr_report_src(array $c, mixed $s): ?array
{
    if ($s === null) return null;
    if (!is_array($s) || !in_array($s['type'] ?? null, APR_REPORT_SRC_TYPES, true) || !apr_is_pid($s['pid'] ?? null)) apr_fail('report_block');
    $table = ['case' => 'cases', 'fact' => 'facts', 'file' => 'files', 'source_check' => 'source_checks', 'visit' => 'visits', 'comp' => 'comps'][$s['type']];
    $col = $table === 'cases' ? 'id' : 'case_id';
    if (!apr_val("SELECT 1 FROM $table WHERE pid = ? AND $col = ?", [$s['pid'], $c['id']])) apr_fail('report_block');     // מקור של תיק אחר אינו מקור
    $page = $s['page'] ?? null;
    if ($page !== null && (!is_int($page) || $page < 1 || $page > 100000)) apr_fail('report_block');
    return ['type' => $s['type'], 'pid' => $s['pid']] + ($page !== null && $s['type'] === 'file' ? ['page' => $page] : []);
}

/**
 * בלוקים שהגיעו מהמשתמש ← בלוקים שמורים. רשימת שדות סגורה לכל סוג; טקסט נשמר כטקסט (אין HTML — התצוגה והיצוא מציגים טקסט נקי).
 * $prev — התוכן השמור: סימון "מספר שלא נמצא בנתונים" נשאר על פסקה שהטקסט שלה לא השתנה (אי אפשר לנקות אותו בשמירה חוזרת).
 */
function apr_report_blocks_in(array $c, mixed $in, array $prev = []): array
{
    if (!is_array($in) || !array_is_list($in) || count($in) > APR_REPORT_MAX_BLOCKS) apr_fail('report_block');
    $flagged = [];
    foreach ($prev as $b) if (($b['type'] ?? '') === 'p' && !empty($b['num_unknown'])) $flagged[$b['text']] = $b['num_unknown'];
    $text = fn(mixed $v, int $max) => apr_clean_text($v, $max) ?? apr_fail('report_block');
    $out = [];
    foreach ($in as $b) {
        if (!is_array($b)) apr_fail('report_block');
        $src = apr_report_src($c, $b['src'] ?? null);
        $o = match ($b['type'] ?? null) {
            'p' => ['type' => 'p', 'text' => $text($b['text'] ?? null, 5000)],
            'h' => ['type' => 'h', 'text' => $text($b['text'] ?? null, 200)],
            'note_missing' => ['type' => 'note_missing', 'what' => $text($b['what'] ?? null, 200)],
            'list' => ['type' => 'list', 'items' => is_array($b['items'] ?? null) && array_is_list($b['items']) && $b['items'] && count($b['items']) <= 100 ? array_map(fn($x) => $text($x, 1000), $b['items']) : apr_fail('report_block')],
            'table' => ['type' => 'table', 'rows' => apr_report_table_in($b['rows'] ?? null)],
            'data_table' => ['type' => 'data_table', 'table' => in_array($b['table'] ?? null, APR_REPORT_TABLES, true) ? $b['table'] : apr_fail('report_block')],
            'fact' => ['type' => 'fact', 'attr' => apr_attr($b['attr'] ?? null)['key']],
            'image' => ['type' => 'image', 'photo' => apr_is_pid($b['photo'] ?? null) && apr_val('SELECT 1 FROM visit_photos p JOIN visits v ON v.id = p.visit_id WHERE p.pid = ? AND v.case_id = ?', [$b['photo'], $c['id']])
                ? $b['photo'] : apr_fail('report_block')] + (($cap = apr_clean_text($b['caption'] ?? null, 300)) !== null ? ['caption' => $cap] : []),
            default => apr_fail('report_block'),
        };
        if ($o['type'] === 'p' && isset($flagged[$o['text']])) $o['num_unknown'] = $flagged[$o['text']];
        if ($o['type'] === 'p' && ($b['ai'] ?? false) === true && in_array($o['text'], array_column(array_filter($prev, fn($x) => !empty($x['ai'])), 'text'), true)) $o['ai'] = true;
        $out[] = $o + ($src !== null ? ['src' => $src] : []);
    }
    return $out;
}

function apr_report_table_in(mixed $rows): array
{
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > 100) apr_fail('report_block');
    $w = null;
    foreach ($rows as &$r) {
        if (!is_array($r) || !array_is_list($r) || !$r || count($r) > 12 || ($w ??= count($r)) !== count($r)) apr_fail('report_block');
        $r = array_map(fn($x) => (string)(apr_clean_text($x, 500) ?? ''), $r);
    }
    return $rows;
}

// ── הנתונים של התיק, בלי הקשר של משתמש ─────────────────────────

/**
 * הערך המוסכם של כל מאפיין — אותו כלל כמו במסך הנתונים (12): יש נתון פעיל (לא נדחה, עם ערך), וכל הפעילים שקולים. מחזיר
 * [key => ['attr', 'fact' (השורה), 'value' (apr_fact_value_out), 'verified']] רק למאפיינים שיש להם ערך מוסכם.
 */
function apr_report_facts(array $c): array
{
    $by = [];
    foreach (apr_rows(APR_FACT_SELECT . ' WHERE f.case_id = ? AND f.deleted_at IS NULL ORDER BY f.id', [$c['id']]) as $f) $by[$f['attribute_key']][] = $f;
    $out = [];
    foreach (apr_attrs_all() as $a) {
        $active = array_values(array_filter($by[$a['key']] ?? [], fn($f) => $f['state'] !== 'rejected' && apr_fact_has_value($f)));
        if (!$active || (int)$a['multi']) continue;
        $keys = array_unique(array_map(fn($f) => json_encode([$f['area_kind'], apr_fact_norm($f, $a)]), $active));
        if (count($keys) !== 1 || apr_fact_norm($active[0], $a)[0] === null) continue;
        $best = array_values(array_filter($active, fn($f) => $f['state'] === 'verified'))[0] ?? $active[0];
        $val = apr_fact_value_out($best['value_num'] === null ? null : (int)$best['value_num'], $best['value_text'], $best['value_date'], $best['unit'], $best['area_kind'], $a);
        $val['text'] = $val['bool'] !== null ? ($val['bool'] ? 'כן' : 'לא') : ($val['num'] ?? $val['text'] ?? $val['date']);
        $out[$a['key']] = ['attr' => $a, 'fact' => $best, 'value' => $val, 'verified' => $best['state'] === 'verified'];
    }
    return $out;
}

/** מה שמזהה ערך לצורך "הערך השתנה מאז העריכה": הערך, היחידה וסוג השטח — כמחרוזת אחת. */
function apr_report_fact_stamp(?array $val): ?string
{
    return $val === null ? null : implode(' ', array_filter([$val['text'], $val['unit'], $val['area_kind']], fn($x) => $x !== null && $x !== ''));
}

/** טבלה שנבנית מהתיק: ['cols' => [כותרות], 'rows' => [[מחרוזות]], 'refs' => [מקור לכל שורה | null]]. כל מספר — מחרוזת מהמנוע. */
function apr_report_table(array $ctx, array $c, string $table, array $tpl): array
{
    $nis = fn(?array $m) => $m === null ? '—' : $m['nis'];
    $t = ['cols' => [], 'rows' => [], 'refs' => []];
    $add = function (array $row, ?array $ref = null) use (&$t) { $t['rows'][] = array_map(fn($x) => $x === null || $x === '' ? '—' : (string)$x, $row); $t['refs'][] = $ref; };
    switch ($table) {
        case 'identifiers':
            $t['cols'] = ['סוג', 'פרטים'];
            $kinds = ['address' => 'כתובת', 'parcel' => 'גוש / חלקה', 'apartment' => 'דירה', 'building_file' => 'תיק בניין', 'other' => 'אחר'];
            foreach (apr_rows('SELECT * FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL ORDER BY id', [$c['id']]) as $i) {
                $parts = array_filter([$i['street'] !== null ? trim($i['street'] . ' ' . $i['house_no']) : null, $i['apartment_no'] !== null ? 'דירה ' . $i['apartment_no'] : null, $i['locality'],
                    $i['gush'] !== null ? 'גוש ' . $i['gush'] : null, $i['helka'] !== null ? 'חלקה ' . $i['helka'] : null, $i['tat_helka'] !== null ? 'תת-חלקה ' . $i['tat_helka'] : null, $i['value_text']]);
                $add([$kinds[$i['kind']] ?? $i['kind'], implode(', ', $parts)]);
            }
            break;
        case 'documents':
            $t['cols'] = ['מסמך', 'מצב'];
            foreach (apr_rows("SELECT * FROM requirements WHERE case_id = ? AND status IN ('approved', 'received') ORDER BY sort, id", [$c['id']]) as $r) $add([$r['title'], $r['status'] === 'approved' ? 'אושר' : 'התקבל — טרם אושר']);
            break;
        case 'source_checks':
            $t['cols'] = ['מקור', 'מצב', 'מועד', 'ממצא'];
            $st = ['found' => 'נמצא', 'partial' => 'נמצא חלקית', 'not_found' => 'לא נמצא', 'unavailable' => 'המקור לא היה זמין', 'verified' => 'אומת'];
            foreach (apr_rows('SELECT k.*, s.name FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.case_id = ? AND k.status IN (' . implode(',', array_fill(0, count($st), '?')) . ') ORDER BY k.id', [$c['id'], ...array_keys($st)]) as $k) {
                $add([$k['name'], $st[$k['status']], $k['performed_at'] === null ? null : substr($k['performed_at'], 0, 10), $k['result_summary']], ['type' => 'source_check', 'pid' => $k['pid']]);
            }
            break;
        case 'comps':
        case 'adjustments':
            $small = apr_pc_comps($c['profile_version_id'] === null ? null : (apr_case_version_content((int)$c['profile_version_id'])['comps'] ?? null))['small_sample_under'];
            $t['cols'] = $table === 'comps' ? ['קבוצה', 'מועד העסקה', 'מיקום', 'מחיר (₪)', 'שטח', 'ערך מותאם (₪)'] : ['קבוצה', 'עסקה', 'התאמה', 'ערך', 'השפעה (₪)', 'נימוק'];
            foreach (apr_rows('SELECT * FROM comp_sets WHERE case_id = ? AND deleted_at IS NULL ORDER BY id', [$c['id']]) as $g) {
                foreach (apr_comp_set_calc($c, $g, $small)['rows'] as $r) {
                    $v = $r['comp']['values'];
                    $where = $v['address'] ?? ($v['approx_location'] !== null ? $v['approx_location'] . ' (מיקום משוער)' : null);
                    $ref = ['type' => 'comp', 'pid' => $r['comp']['pid']];
                    if ($table === 'comps') { $add([$g['name'], $v['deal_date'], $where, $v['price'], $v['area'], $r['calc']['ok'] ? $nis($r['calc']['adjusted']) : null], $ref); continue; }
                    foreach ($r['calc']['steps'] as $s) $add([$g['name'], $where ?? $v['deal_date'], $s['label'], ($s['sign'] < 0 ? '-' : '') . $s['value'] . ($s['kind'] === 'percent' ? '%' : ''), $nis($s['delta']),
                        apr_val('SELECT reason FROM comp_adjustments WHERE pid = ?', [$s['pid']])], $ref);
                }
            }
            if ($table === 'comps' && !empty($tpl['include_excluded_comps'])) {
                foreach (apr_rows("SELECT * FROM comps WHERE case_id = ? AND deleted_at IS NULL AND status = 'excluded' ORDER BY id", [$c['id']]) as $k) {
                    $add(['לא נכללה: ' . ($k['status_reason'] ?? ''), $k['deal_date'], in_array($k['location_precision'], APR_COMP_PRECISE, true) ? $k['address_text'] : null, apr_comp_dec_out($k['price'], 2), apr_comp_dec_out($k['area'], 2), null], ['type' => 'comp', 'pid' => $k['pid']]);
                }
            }
            break;
        case 'alternatives':
            $t['cols'] = ['חלופה', 'המועד הקובע', 'תיאור המצב', 'מסקנת השמאי (₪)'];
            foreach (apr_rows('SELECT * FROM alternatives WHERE case_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$c['id']]) as $a) $add([$a['name'], $a['key_date'], $a['state_note'], $a['conclusion_value'] === null ? null : apr_val_money($a['conclusion_value'])['nis']]);
            break;
        case 'approaches':
            $t['cols'] = ['חלופה', 'גישה', 'נימוק', 'תוצאה (₪)', 'משקל'];
            foreach (apr_rows('SELECT p.*, a.name AS alt_name FROM approaches p JOIN alternatives a ON a.id = p.alternative_id WHERE p.case_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL ORDER BY a.sort, a.id, p.sort, p.id', [$c['id']]) as $p) {
                [$ag] = apr_approach_calc($ctx, $c, $p);
                $add([$p['alt_name'], $p['title'] ?: (APR_APPROACH_TITLES[$p['kind']] ?? $p['kind']), $p['reasoning'], $ag === null ? null : apr_val_money($ag)['nis'], apr_comp_dec_out($p['weight'], 6)]);
            }
            break;
        case 'assumptions':
            $t['cols'] = ['חלופה', 'סוג', 'נוסח', 'על מה נשענת'];
            $kinds = ['assumption' => 'הנחה', 'limitation' => 'מגבלה', 'condition' => 'תנאי'];
            foreach (apr_rows('SELECT s.*, a.name AS alt_name FROM assumptions s LEFT JOIN alternatives a ON a.id = s.alternative_id WHERE s.case_id = ? AND s.deleted_at IS NULL AND (a.id IS NULL OR a.deleted_at IS NULL) ORDER BY a.sort IS NULL DESC, a.sort, s.sort, s.id', [$c['id']]) as $s) {
                $add([$s['alt_name'] ?? 'כל התיק', $kinds[$s['kind']] ?? $s['kind'], $s['text'], $s['basis']]);
            }
            break;
    }
    return $t;
}

function apr_report_table_empty(array $c, string $table, array $tpl): bool
{
    return match ($table) {
        'identifiers' => !apr_val('SELECT 1 FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL', [$c['id']]),
        'documents' => !apr_val("SELECT 1 FROM requirements WHERE case_id = ? AND status IN ('approved', 'received')", [$c['id']]),
        'source_checks' => !apr_val("SELECT 1 FROM source_checks WHERE case_id = ? AND status IN ('found', 'partial', 'not_found', 'unavailable', 'verified')", [$c['id']]),
        'comps' => !apr_val("SELECT 1 FROM comps k JOIN comp_set_members m ON m.comp_id = k.id AND m.active = 1 JOIN comp_sets g ON g.id = m.set_id AND g.deleted_at IS NULL WHERE k.case_id = ? AND k.deleted_at IS NULL AND k.status = 'selected'", [$c['id']]),
        'adjustments' => !apr_val("SELECT 1 FROM comp_adjustments j JOIN comps k ON k.id = j.comp_id WHERE k.case_id = ? AND k.deleted_at IS NULL AND k.status = 'selected' AND j.deleted_at IS NULL", [$c['id']]),
        'alternatives' => !apr_val('SELECT 1 FROM alternatives WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]),
        'approaches' => !apr_val('SELECT 1 FROM approaches p JOIN alternatives a ON a.id = p.alternative_id WHERE p.case_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL', [$c['id']]),
        'assumptions' => !apr_val('SELECT 1 FROM assumptions WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]),
        default => true,
    };
}

// ── בניית פרק מהנתונים (§6.2) — בקוד, לא בבינה ───────────────────

const APR_REPORT_ATTR_GROUPS = ['property' => ['identity', 'rights'], 'area_desc' => ['features', 'renovation', 'rent', 'deal']];
const APR_REPORT_GROUP_LABELS = ['identity' => 'זיהוי הנכס', 'rights' => 'הזכויות בנכס', 'features' => 'תיאור הנכס', 'renovation' => 'שיפוץ', 'rent' => 'שכירות', 'deal' => 'פרטי העסקה'];
const APR_REPORT_SECTION_TABLES = ['property' => ['identifiers'], 'planning' => ['source_checks'], 'documents' => ['documents'], 'methods' => ['alternatives', 'approaches'],
    'comps' => ['comps', 'adjustments'], 'assume' => ['assumptions'], 'appendix' => ['documents', 'source_checks']];
const APR_REPORT_TABLE_LABELS = ['identifiers' => 'מזהי הנכס', 'documents' => 'מסמכים שהתקבלו', 'source_checks' => 'בדיקות במקורות מידע', 'comps' => 'עסקאות השוואה שנבחרו', 'adjustments' => 'התאמות לעסקאות',
    'alternatives' => 'חלופות שווי', 'approaches' => 'גישות שומה', 'assumptions' => 'הנחות ומגבלות'];
const APR_REPORT_PURPOSES = ['purchase' => 'רכישה', 'collateral' => 'בטוחה לאשראי', 'renovation' => 'שיפוץ', 'construction' => 'בנייה', 'income' => 'נכס מניב', 'taxation' => 'מיסוי', 'dispute' => 'מחלוקת / פירוק שיתוף', 'other' => 'אחר'];

/**
 * התוכן של פרק build = data, מנתוני התיק כפי שהם עכשיו: ['blocks' => […], 'sources' => [{type, pid, label}]]. דטרמיניסטי — אותם נתונים
 * נותנים אותם בלוקים, ולכן hash של התוצאה מזהה "הגיע מידע חדש". מה שחסר נכנס כ-note_missing; **אין 0 במקום ערך חסר**.
 */
function apr_report_build(array $c, string $key, array $tpl): array
{
    $blocks = [];
    $sources = [];
    $missing = function (string $what) use (&$blocks) { $blocks[] = ['type' => 'note_missing', 'what' => $what]; };
    $caseSrc = ['type' => 'case', 'pid' => $c['pid']];
    if ($key === 'purpose') {
        $purpose = $c['purpose'] === null ? null : (APR_REPORT_PURPOSES[$c['purpose']] ?? $c['purpose']) . ($c['purpose_note'] !== null && $c['purpose_note'] !== '' ? ' — ' . $c['purpose_note'] : '');
        $purpose !== null ? $blocks[] = ['type' => 'p', 'text' => 'מטרת השומה: ' . $purpose, 'src' => $caseSrc] : $missing('מטרת השומה');
        $c['key_date'] !== null ? $blocks[] = ['type' => 'p', 'text' => 'המועד הקובע לשומה: ' . $c['key_date'], 'src' => $caseSrc] : $missing('המועד הקובע');
        $orderer = apr_val('SELECT k.name FROM case_parties p JOIN contacts k ON k.id = p.contact_id WHERE p.case_id = ? AND p.removed_at IS NULL AND p.is_orderer = 1 ORDER BY p.id LIMIT 1', [$c['id']]);
        $orderer ? $blocks[] = ['type' => 'p', 'text' => 'מזמין השומה: ' . $orderer, 'src' => $caseSrc] : $missing('מזמין השומה');
    }
    if ($key === 'visit') {
        $visits = apr_rows("SELECT v.*, u.name AS visitor FROM visits v LEFT JOIN users u ON u.id = v.visitor_user_id WHERE v.case_id = ? AND v.status = 'completed' ORDER BY v.completed_at, v.id", [$c['id']]);
        foreach ($visits as $v) {
            $blocks[] = ['type' => 'p', 'text' => 'ביקור בנכס נערך ביום ' . substr((string)$v['completed_at'], 0, 10) . ($v['visitor'] ? ', בידי ' . $v['visitor'] : '') . '.', 'src' => ['type' => 'visit', 'pid' => $v['pid']]];
            $sources[] = ['type' => 'visit', 'pid' => $v['pid'], 'label' => 'ביקור ' . (int)$v['seq']];
        }
        if (!$visits) $missing('לא נערך ביקור בנכס');
        // תמונות: רק מה שסומן לדוח, עם הרשאת שימוש "דוח" (usage = report) — ושלא נמחק
        foreach (apr_rows("SELECT p.pid, p.caption FROM visit_photos p JOIN visits v ON v.id = p.visit_id WHERE v.case_id = ? AND v.status = 'completed' AND p.deleted_at IS NULL AND p.usage = 'report' AND p.for_report = 1 ORDER BY v.id, p.sort, p.id", [$c['id']]) as $p) {
            $blocks[] = ['type' => 'image', 'photo' => $p['pid']] + ($p['caption'] !== null && $p['caption'] !== '' ? ['caption' => $p['caption']] : []);
        }
    }
    if (isset(APR_REPORT_ATTR_GROUPS[$key])) {
        $facts = apr_report_facts($c);
        foreach (APR_REPORT_ATTR_GROUPS[$key] as $grp) {
            $in = array_filter($facts, fn($f) => $f['attr']['grp'] === $grp && ($f['verified'] || ($tpl['include_unverified'] ?? 'marked') === 'marked'));
            if (!$in) { if (in_array($grp, ['identity', 'rights', 'features'], true)) $missing(APR_REPORT_GROUP_LABELS[$grp]); continue; }
            $blocks[] = ['type' => 'h', 'text' => APR_REPORT_GROUP_LABELS[$grp]];
            foreach ($in as $k => $f) {
                $blocks[] = ['type' => 'fact', 'attr' => $k];
                $sources[] = ['type' => 'fact', 'pid' => $f['fact']['pid'], 'label' => $f['attr']['label']];
            }
        }
    }
    foreach (APR_REPORT_SECTION_TABLES[$key] ?? [] as $table) {
        if (apr_report_table_empty($c, $table, $tpl)) { if ($key !== 'appendix' && $table !== 'adjustments') $missing(APR_REPORT_TABLE_LABELS[$table]); continue; }
        $blocks[] = ['type' => 'h', 'text' => APR_REPORT_TABLE_LABELS[$table]];
        $blocks[] = ['type' => 'data_table', 'table' => $table];
    }
    return ['blocks' => $blocks, 'sources' => $sources];
}

/**
 * מה שמזהה את **הנתונים** שפרק נשען עליהם: הבלוקים שנבנו + הערכים של הרגע (נתונים וטבלאות). שונה מהפעם הקודמת ← "הגיע מידע חדש" (§7).
 * $ctx נדרש רק לחישוב תוצאת גישה; ברקע (בלי משתמש) מועבר הקשר ריק — אין בו שום הכרעת הרשאה.
 */
function apr_report_data_hash(array $ctx, array $c, array $blocks, array $tpl): string
{
    return apr_report_hash(apr_report_resolve($ctx, $c, $blocks, $tpl, false));
}

// ── פתרון ההפניות (fact / data_table / image) לערכים של הרגע ─────

/**
 * בלוקים שמורים ← בלוקים להצגה: fact מקבל value (או null — "לא ידוע"), האם אומת, והאם השתנה מאז שהפרק נערך (was); data_table מקבל
 * cols / rows; image מקבל את מצב התמונה. $forView = false — רק מה שנכנס ל-hash של הנתונים (בלי קבצים ובלי סימוני עריכה).
 */
function apr_report_resolve(array $ctx, array $c, array $blocks, array $tpl, bool $forView = true): array
{
    $facts = null;
    foreach ($blocks as &$b) {
        if ($b['type'] === 'fact') {
            $facts ??= apr_report_facts($c);
            $f = $facts[$b['attr']] ?? null;
            $b['label'] = $f['attr']['label'] ?? (apr_row('SELECT label FROM attributes WHERE key = ?', [$b['attr']])['label'] ?? $b['attr']);
            $b['value'] = $f['value'] ?? null;
            $b['verified'] = $f['verified'] ?? null;
            $b['fact'] = $f['fact']['pid'] ?? null;
            $now = apr_report_fact_stamp($f['value'] ?? null);
            if (array_key_exists('was', $b)) $b['changed'] = $b['was'] !== $now;
            if (!$forView) unset($b['was'], $b['changed']);
        } elseif ($b['type'] === 'data_table') {
            $b += apr_report_table($ctx, $c, $b['table'], $tpl);
            $b['label'] = APR_REPORT_TABLE_LABELS[$b['table']];
        } elseif ($b['type'] === 'image') {
            $p = apr_row('SELECT p.usage, p.deleted_at, p.caption, f.pid AS file_pid, f.deleted_at AS file_gone FROM visit_photos p JOIN files f ON f.id = p.file_id WHERE p.pid = ?', [$b['photo']]);
            $b['state'] = !$p || $p['deleted_at'] !== null || $p['file_gone'] !== null ? 'removed' : ($p['usage'] !== 'report' ? 'not_report' : 'ok');
            if ($forView) $b['file'] = $b['state'] === 'ok' ? $p['file_pid'] : null;
        }
    }
    return $blocks;
}

/** האם יש בפרק תוכן של ממש (לא רק "לא ידוע"): לבדיקת "פרק חובה ריק". */
function apr_report_has_content(array $blocks): bool
{
    foreach ($blocks as $b) if ($b['type'] !== 'note_missing' && $b['type'] !== 'h') return true;
    return false;
}

// ── הבקרה (§9) ────────────────────────────────────────────────

/**
 * הממצאים של גרסה, מחושבים מהתיק ברגע הקריאה. $sections — השורות מהמסד (content מפוענח ב-'blocks', ופתור ב-'view').
 * כל ממצא: key (יציב — לפיו נשמר הנימוק), kind, level (block | warn, לפי הפרופיל של הגרסה), text, section (pid | null), link ({type, pid} | null).
 */
function apr_report_findings(array $c, array $version, array $sections): array
{
    $tpl = json_decode((string)$version['template'], true) ?: apr_pc_report_default();
    $levels = ($tpl['findings'] ?? []) + array_map(fn($f) => $f[0], APR_REPORT_FINDINGS);
    $out = [];
    $add = function (string $kind, string $id, string $text, ?string $section = null, ?array $link = null) use (&$out, $levels) {
        $out[] = ['key' => $kind . ':' . $id, 'kind' => $kind, 'level' => APR_REPORT_FINDINGS[$kind][1] ? 'block' : $levels[$kind], 'text' => $text, 'section' => $section, 'link' => $link];
    };
    // אין שמאי מורשה לאשר: משתתף פעיל בתיק עם approve_report, שהוא שמאי פעיל במשרד
    $approver = false;
    foreach (apr_rows("SELECT cm.perms FROM case_members cm JOIN memberships m ON m.user_id = cm.user_id AND m.office_id = ? AND m.deleted_at IS NULL AND m.status = 'active' AND m.role = 'appraiser'
        WHERE cm.case_id = ? AND cm.revoked_at IS NULL", [$c['office_id'], $c['id']]) as $m) {
        if (!empty(apr_case_perms_full(json_decode($m['perms'], true) ?: [])['approve_report'])) $approver = true;
    }
    if (!$approver) $add('no_approver', 'case', 'אין בתיק שמאי שמורשה לאשר דוח');
    $usedFacts = [];
    foreach ($sections as $s) {
        $blocks = $s['view'];
        if ((int)$s['mandatory'] === 1 && !apr_report_has_content($blocks)) $add('mandatory_empty', $s['key'], 'פרק חובה ריק: ' . $s['title'], $s['pid']);
        if ($s['origin'] === 'ai' && (int)$s['ai_reviewed'] === 0) $add('ai_unreviewed', $s['key'], 'טקסט שנכתב בבינה ולא נסקר: ' . $s['title'], $s['pid']);
        if ($s['pending'] ?? false) $add('proposal_pending', $s['key'], 'הצעת עדכון שלא הוכרעה: ' . $s['title'], $s['pid']);
        foreach ($blocks as $i => $b) {
            if ($b['type'] === 'image' && $b['state'] !== 'ok') $add('image_not_report', $s['key'] . ':' . $b['photo'], $b['state'] === 'removed' ? 'תמונה שבדוח הוסרה' : 'תמונה בדוח בלי הרשאת שימוש "דוח"', $s['pid']);
            if ($b['type'] === 'p' && !empty($b['num_unknown'])) $add('ai_number', $s['key'] . ':' . substr(apr_report_hash($b['text']), 0, 12), 'מספר שלא נמצא בנתונים: ' . implode(', ', $b['num_unknown']), $s['pid']);
            if ($b['type'] === 'note_missing') $add('note_missing', $s['key'] . ':' . substr(apr_report_hash($b['what']), 0, 12), 'לא ידוע: ' . $b['what'], $s['pid']);
            if ($b['type'] === 'fact') {
                if ($b['value'] === null) $add('note_missing', $s['key'] . ':' . $b['attr'], 'לא ידוע: ' . $b['label'], $s['pid']);
                elseif ($b['verified'] === false) $add('fact_unverified', $b['attr'], 'נתון בדוח שלא אומת: ' . $b['label'], $s['pid'], ['type' => 'fact', 'pid' => $b['fact']]);
                if (!empty($b['changed'])) $add('fact_changed', $s['key'] . ':' . $b['attr'], 'הערך השתנה מאז עריכת הפרק: ' . $b['label'] . ' (היה ' . ($b['was'] ?? 'לא ידוע') . ')', $s['pid']);
                if ($b['fact'] !== null) $usedFacts[$b['fact']] = $b['label'];
            }
        }
    }
    foreach (apr_rows("SELECT pid, title FROM requirements WHERE case_id = ? AND mandatory = 1 AND status NOT IN ('approved', 'not_relevant') ORDER BY sort, id", [$c['id']]) as $r) $add('req_open', $r['pid'], 'דרישת חובה שלא הושלמה: ' . $r['title'], null, ['type' => 'requirement', 'pid' => $r['pid']]);
    foreach (apr_rows("SELECT k.pid, a.label FROM conflicts k LEFT JOIN attributes a ON a.key = k.attribute_key WHERE k.case_id = ? AND k.status = 'open' ORDER BY k.id", [$c['id']]) as $k) $add('conflict_open', $k['pid'], 'סתירה פתוחה: ' . ($k['label'] ?? ''), null, ['type' => 'conflict', 'pid' => $k['pid']]);
    foreach (apr_rows("SELECT k.pid, k.status, s.name FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.case_id = ? AND k.status IN ('not_checked', 'planned', 'unavailable', 'not_found') ORDER BY k.id", [$c['id']]) as $k) {
        $add('source_unchecked', $k['pid'], ($k['status'] === 'unavailable' ? 'מקור שלא היה זמין: ' : ($k['status'] === 'not_found' ? 'לא נמצא במקור: ' : 'מקור שלא נבדק: ')) . $k['name'], null, ['type' => 'source_check', 'pid' => $k['pid']]);
    }
    foreach (apr_rows("SELECT pid, title FROM tasks WHERE case_id = ? AND status IN ('open', 'in_progress') ORDER BY id LIMIT 50", [$c['id']]) as $t) $add('task_open', $t['pid'], 'משימה פתוחה: ' . $t['title'], null, ['type' => 'task', 'pid' => $t['pid']]);
    $small = apr_pc_comps($c['profile_version_id'] === null ? null : (apr_case_version_content((int)$c['profile_version_id'])['comps'] ?? null))['small_sample_under'];
    foreach (apr_rows('SELECT * FROM comp_sets WHERE case_id = ? AND deleted_at IS NULL ORDER BY id', [$c['id']]) as $g) {
        $calc = apr_comp_set_calc($c, $g, $small);
        if ($calc['rows'] && count($calc['rows']) < $small) $add('small_sample', $g['pid'], 'מדגם קטן בקבוצה "' . $g['name'] . '": ' . count($calc['rows']) . ' עסקאות', null, ['type' => 'comp_set', 'pid' => $g['pid']]);
        foreach ($calc['rows'] as $r) if ($r['comp']['missing']) $add('comp_missing', $g['pid'] . ':' . $r['comp']['pid'], 'עסקה נבחרת עם נתונים חסרים, בקבוצה "' . $g['name'] . '"', null, ['type' => 'comp', 'pid' => $r['comp']['pid']]);
    }
    foreach (apr_rows('SELECT * FROM alternatives WHERE case_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$c['id']]) as $a) {
        $link = ['type' => 'alternative', 'pid' => $a['pid']];
        if ($a['key_date'] === null) $add('alt_no_key_date', $a['pid'], 'חלופה בלי מועד קובע: ' . $a['name'], null, $link);
        if ($a['state_kind'] === 'after_works' && !apr_val('SELECT 1 FROM assumptions s JOIN files f ON f.id = s.file_id WHERE s.alternative_id = ? AND s.deleted_at IS NULL AND f.deleted_at IS NULL', [$a['id']])) {
            $add('alt_no_assumptions', $a['pid'], 'חלופת "אחרי עבודות" בלי הנחות שמקושרות למסמך העבודות: ' . $a['name'], null, $link);
        }
        if ($a['conclusion_value'] === null) $add('alt_no_conclusion', $a['pid'], 'חלופה בלי מסקנה: ' . $a['name'], null, $link);
    }
    // מסמך שנתון בדוח נשען עליו נמחק, או הוחזר להשלמה
    foreach ($usedFacts as $factPid => $label) {
        $f = apr_row("SELECT f.source_file_id, fl.deleted_at AS gone, (SELECT rf.review_state FROM requirement_files rf WHERE rf.file_id = f.source_file_id ORDER BY rf.id DESC LIMIT 1) AS review
            FROM facts f LEFT JOIN files fl ON fl.id = f.source_file_id WHERE f.pid = ?", [$factPid]);
        if ($f && $f['source_file_id'] !== null && ($f['gone'] !== null || $f['review'] === 'returned')) $add('doc_gone', $factPid, ($f['gone'] !== null ? 'המסמך שהנתון נשען עליו נמחק: ' : 'המסמך שהנתון נשען עליו הוחזר להשלמה: ') . $label, null, ['type' => 'fact', 'pid' => $factPid]);
    }
    return $out;
}

// ── ניסוח בבינה (§6.2–§6.4) ───────────────────────────────────

/** כל המספרים שבטקסט, מנורמלים (בלי מפריד אלפים, בלי אפסים מיותרים אחרי הנקודה). תאריך 2026-09-01 נותן 2026, 9, 1 — בשני הצדדים. */
function apr_report_numbers(string $text): array
{
    preg_match_all('/\d+(?:,\d{3})*(?:\.\d+)?/', $text, $m);
    $out = [];
    foreach ($m[0] as $n) {
        $n = str_replace(',', '', $n);
        if (str_contains($n, '.')) $n = rtrim(rtrim($n, '0'), '.');
        $out[] = ltrim($n, '0') === '' || $n[0] === '.' ? ($n === '' || ltrim($n, '0') === '' ? '0' : '0' . ltrim($n, '0')) : ltrim($n, '0');
    }
    return array_values(array_unique($out));
}

/**
 * מה שנשלח למודל על פרק אחד: הכותרת, והנתונים של **הפרק הזה בלבד** כפריטים ממוספרים (d1, d2 …) — ערכים, יחידות, והמקור של כל אחד.
 * מחזיר ['items' => [id => ['text', 'src' | null]], 'message' => הטקסט שנשלח]. אין כאן מסמכים, פרקים אחרים, שמות או שווי.
 */
function apr_report_ai_input(array $ctx, array $c, array $section, array $tpl): array
{
    $built = apr_report_resolve($ctx, $c, apr_report_build($c, $section['key'], $tpl)['blocks'], $tpl);
    $items = [];
    $put = function (string $text, ?array $src) use (&$items) { $items['d' . (count($items) + 1)] = ['text' => $text, 'src' => $src]; };
    foreach ($built as $b) {
        if ($b['type'] === 'p') $put($b['text'], $b['src'] ?? null);
        if ($b['type'] === 'note_missing') $put('לא ידוע: ' . $b['what'], null);
        if ($b['type'] === 'fact') $put($b['label'] . ': ' . ($b['value'] === null ? 'לא ידוע' : apr_report_fact_stamp($b['value'])) . ($b['verified'] === false ? ' (לא אומת)' : ''), $b['fact'] === null ? null : ['type' => 'fact', 'pid' => $b['fact']]);
        if ($b['type'] === 'data_table') foreach ($b['rows'] as $i => $row) $put($b['label'] . ' — ' . implode(' · ', array_map(fn($col, $x) => "$col: $x", $b['cols'], $row)), $b['refs'][$i]);
    }
    $lines = array_map(fn($id, $it) => "$id. " . $it['text'], array_keys($items), $items);
    return ['items' => $items, 'message' => "כותרת הפרק: " . $section['title'] . "\n\nהנתונים:\n" . implode("\n", $lines)];
}

/**
 * תשובת המודל ← בלוקים. בלוק ש-src שלו אינו אחד מהמזהים שנשלחו — **נזרק** (17-D1). מספר בטקסט שאינו אחד מהמספרים שנשלחו ← הבלוק נשאר,
 * מסומן num_unknown, ועולה בבקרה כממצא חוסם. מחזיר null כשהתשובה אינה ה-JSON שביקשנו.
 */
function apr_report_ai_parse(string $text, array $items): ?array
{
    $s = trim($text);
    if (preg_match('/\A```(?:json)?\s*(\{.*\})\s*```\z/s', $s, $m) === 1) $s = $m[1];
    if ($s === '' || $s[0] !== '{' || strlen($s) > 100000) return null;
    try {
        $j = json_decode($s, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($j) || !is_array($j['blocks'] ?? null) || !array_is_list($j['blocks'])) return null;
    $known = [];
    foreach ($items as $it) foreach (apr_report_numbers($it['text']) as $n) $known[$n] = true;
    $out = [];
    $dropped = 0;
    foreach (array_slice($j['blocks'], 0, 60) as $b) {
        $t = is_array($b) && is_string($b['text'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $b['text'])) : '';
        $src = is_array($b) && is_string($b['src'] ?? null) ? $b['src'] : null;
        if ($t === '' || mb_strlen($t) > 5000 || $src === null || !isset($items[$src])) { $dropped++; continue; }
        $unknown = array_values(array_filter(apr_report_numbers($t), fn($n) => !isset($known[$n])));
        $out[] = ['type' => 'p', 'text' => $t, 'ai' => true] + ($items[$src]['src'] !== null ? ['src' => $items[$src]['src']] : []) + ($unknown ? ['num_unknown' => $unknown] : []);
    }
    return ['blocks' => $out, 'dropped' => $dropped];
}

/**
 * מנסח פרק אחד בבינה, דרך השער בלבד. מחזיר ['state' => done | wait | failed, 'reason' => קוד | null, 'blocks', 'run' (מזהה פנימי), 'dropped'].
 * $ctx = null ← ברקע (הטריגר): הבקשה נספרת על שם השמאי האחראי. **אין נפילה למסלול אחר, ואין ניסיון חוזר.**
 */
function apr_report_ai_section(?array $ctx, array $c, array $section, array $tpl, string $op): array
{
    if ((int)$section['ai_allowed'] !== 1 || in_array($section['key'], APR_REPORT_NO_AI, true)) return ['state' => 'failed', 'reason' => 'not_allowed', 'blocks' => [], 'run' => null, 'dropped' => 0];
    $in = apr_report_ai_input($ctx ?? ['user' => null, 'office' => null], $c, $section, $tpl);
    if (!$in['items']) return ['state' => 'failed', 'reason' => 'no_data', 'blocks' => [], 'run' => null, 'dropped' => 0];
    $r = ai_run(($ctx !== null ? ['ctx' => $ctx, 'case' => $c['pid']] : ['ctx' => null, 'office_id' => (int)$c['office_id'], 'case_id' => (int)$c['id']])
        + ['purpose' => 'report.draft', 'model' => null, 'system' => APR_REPORT_AI_SYSTEM, 'max_output' => APR_REPORT_AI_MAX_OUTPUT, 'op' => $op, 'content' => [['type' => 'text', 'text' => $in['message']]]]);
    $run = (int)apr_val('SELECT id FROM ai_runs WHERE pid = ?', [$r['run']]) ?: null;
    if ($r['status'] === 'blocked') return ['state' => 'wait', 'reason' => in_array($r['block_reason'], APR_REPORT_AI_WAITS, true) ? $r['block_reason'] : 'failed', 'blocks' => [], 'run' => $run, 'dropped' => 0];
    $parsed = $r['status'] === 'done' && is_string($r['text'] ?? null) && ($r['stop_reason'] ?? 'end_turn') === 'end_turn' ? apr_report_ai_parse($r['text'], $in['items']) : null;
    if ($parsed === null || !$parsed['blocks']) return ['state' => 'wait', 'reason' => 'failed', 'blocks' => [], 'run' => $run, 'dropped' => $parsed['dropped'] ?? 0];
    return ['state' => 'done', 'reason' => null, 'blocks' => $parsed['blocks'], 'run' => $run, 'dropped' => $parsed['dropped']];
}
