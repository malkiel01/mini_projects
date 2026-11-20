<?php
/**
 * טופס הביקור — מקטע "ביקור" של הפרופיל (אפיון 13 §4): קבוצות, שדות, חובה, תנאי הצגה. כאן: המבנה, הניקוי בשמירה, בדיקות
 * הפרסום, טופס ברירת המחדל (שש הקבוצות שבאפיון 26.2, מסומן "דוגמה"), והערכת התנאים מול תשובות.
 *
 * תנאי (when): אותו מבנה של פרוסה 03 (all / any / {field, op, value}); השדות: answer.<מפתח שדה> · property_type · purpose.
 * אין כאן שום שדה של "עבירה" או "חוקי" — "אי התאמה אפשרית" הוא טקסט חופשי שיוצר משימת בדיקה (§8), ותו לא.
 */

declare(strict_types=1);

require_once __DIR__ . '/profile_content.php';

const APR_VISIT_FIELD_TYPES = ['text', 'longtext', 'number', 'area', 'length', 'choice', 'multichoice', 'yesno', 'date', 'photo'];
const APR_VISIT_OBTAINED = ['observed', 'reported', 'measured', 'estimated', 'not_verified'];
const APR_VISIT_LIMITS = ['groups' => 40, 'fields' => 300, 'options' => 40];
const APR_VISIT_PLACES = ['חזית', 'כניסה', 'סלון', 'מטבח', 'חדר', 'חדר רחצה', 'מרפסת', 'חניה', 'מחסן', 'גג', 'חצר', 'נוף', 'סביבה', 'ליקוי'];

function apr_visit_default_obtained(string $type): string
{
    return in_array($type, ['area', 'length'], true) ? 'measured' : 'observed';
}

/** תנאי הצגה של שדה או קבוצה בטופס. */
function apr_visit_condition(mixed $c, int $depth = 1): ?array
{
    if ($c === null) return null;
    if (!is_array($c) || array_is_list($c)) apr_fail('condition_invalid');
    $keys = array_keys($c);
    if ($keys === ['all'] || $keys === ['any']) {
        if ($depth > APR_COND_DEPTH) apr_fail('condition_invalid');
        $kids = $c[$keys[0]];
        if (!is_array($kids) || !array_is_list($kids) || count($kids) > 20) apr_fail('condition_invalid');
        return [$keys[0] => array_map(fn($k) => apr_visit_condition($k, $depth + 1) ?? apr_fail('condition_invalid'), $kids)];
    }
    sort($keys);
    if ($keys !== ['field', 'op', 'value']) apr_fail('condition_invalid');
    $field = $c['field'];
    if (!is_string($field) || !preg_match('/^(purpose|property_type|answer\.[a-z0-9_]{1,40})\z/', $field)) apr_fail('condition_invalid');
    if (!is_string($c['op']) || !in_array($c['op'], APR_COND_OPS, true)) apr_fail('condition_invalid');
    $v = $c['value'];
    if (in_array($c['op'], ['in', 'not_in'], true)) {
        if (!is_array($v) || !array_is_list($v) || count($v) > 30) apr_fail('condition_invalid');
        foreach ($v as $x) if (!is_string($x) || mb_strlen($x) > 80) apr_fail('condition_invalid');
    } elseif (!is_bool($v) && (!is_string($v) || mb_strlen($v) > 80)) {
        apr_fail('condition_invalid');
    }
    return ['field' => $field, 'op' => $c['op'], 'value' => $v];
}

/** מנקה את מקטע "ביקור". null (פרופיל שנוצר לפני הפרוסה, או שהמקטע לא נשלח) ← טופס ברירת המחדל. */
function apr_pc_visit(mixed $v): array
{
    if ($v === null) return apr_visit_default_form();
    if (!is_array($v) || (array_is_list($v) && $v !== [])) apr_fail('content_invalid');
    $out = ['groups' => [], 'fields' => [], 'completion' => ['block_on_required' => true], 'example' => ($v['example'] ?? false) === true];
    foreach (apr_pc_list($v['groups'] ?? null, APR_VISIT_LIMITS['groups']) as $g) {
        $min = $g['min_rows'] ?? 0;
        if (!is_int($min) || $min < 0 || $min > 50) apr_fail('content_invalid');
        $out['groups'][] = ['key' => apr_pc_key($g['key'] ?? null), 'title' => apr_pc_text($g['title'] ?? '', 120), 'sort' => is_int($g['sort'] ?? null) ? $g['sort'] : 0,
            'repeat' => ($g['repeat'] ?? false) === true, 'min_rows' => ($g['repeat'] ?? false) === true ? $min : 0, 'when' => apr_visit_condition($g['when'] ?? null)];
    }
    foreach (apr_pc_list($v['fields'] ?? null, APR_VISIT_LIMITS['fields']) as $f) {
        $type = apr_pc_enum($f['type'] ?? '', APR_VISIT_FIELD_TYPES, '');
        if ($type === '') apr_fail('content_invalid');
        $opts = [];
        if (in_array($type, ['choice', 'multichoice'], true)) {
            foreach (apr_pc_list($f['options'] ?? null, APR_VISIT_LIMITS['options']) as $o) $opts[] = ['key' => apr_pc_key($o['key'] ?? null), 'label' => apr_pc_text($o['label'] ?? '', 80)];
        }
        $maps = $f['maps_to'] ?? null;
        if ($maps !== null && $maps !== '' && (!is_string($maps) || !preg_match('/^[a-z][a-z0-9_]{1,39}\z/', $maps))) apr_fail('content_invalid');
        $min = $f['min'] ?? 1;
        if (!is_int($min) || $min < 1 || $min > 20) apr_fail('content_invalid');
        $out['fields'][] = ['key' => apr_pc_key($f['key'] ?? null), 'group' => apr_pc_key($f['group'] ?? null), 'label' => apr_pc_text($f['label'] ?? '', 160), 'hint' => apr_pc_text($f['hint'] ?? '', 300),
            'type' => $type, 'required' => ($f['required'] ?? false) === true, 'when' => apr_visit_condition($f['when'] ?? null), 'sort' => is_int($f['sort'] ?? null) ? $f['sort'] : 0,
            'maps_to' => $maps === '' ? null : $maps, 'obtained' => apr_pc_enum($f['obtained'] ?? null, APR_VISIT_OBTAINED, apr_visit_default_obtained($type)),
            'creates_task' => in_array($type, ['text', 'longtext'], true) && ($f['creates_task'] ?? false) === true]
            + ($opts || in_array($type, ['choice', 'multichoice'], true) ? ['options' => $opts] : []) + ($type === 'photo' ? ['min' => $min] : []);
    }
    $comp = $v['completion'] ?? [];
    if (!is_array($comp) || (array_key_exists('block_on_required', $comp) && !is_bool($comp['block_on_required']))) apr_fail('content_invalid');
    $out['completion']['block_on_required'] = ($comp['block_on_required'] ?? true) !== false;
    return $out;
}

/** הקבוצות והשדות לפי סדר ההצגה: קבוצה לפי sort, ובתוכה שדה לפי sort. */
function apr_visit_form_ordered(array $form): array
{
    $groups = $form['groups'];
    usort($groups, fn($a, $b) => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]);
    $pos = array_flip(array_column($groups, 'key'));
    $fields = array_values(array_filter($form['fields'], fn($f) => isset($pos[$f['group']])));
    usort($fields, fn($a, $b) => [$pos[$a['group']], $a['sort'], $a['key']] <=> [$pos[$b['group']], $b['sort'], $b['key']]);
    return [$groups, $fields];
}

/** האם שדה בטופס יכול להציע ערך למאפיין הזה (§8). null — מתאים; אחרת — מה לא מתאים. */
function apr_visit_maps_problem(array $f, array $attr): ?string
{
    $ok = match ($f['type']) {
        'area' => $attr['value_type'] === 'number' && $attr['unit_kind'] === 'area',
        'length' => $attr['value_type'] === 'number' && $attr['unit_kind'] === 'length',
        'number' => $attr['value_type'] === 'number' && in_array($attr['unit_kind'], ['none', 'count'], true),
        'text', 'longtext' => $attr['value_type'] === 'text',
        'yesno' => $attr['value_type'] === 'bool',
        'date' => $attr['value_type'] === 'date',
        'choice' => $attr['value_type'] === 'choice',
        default => false,
    };
    if (!$ok) return 'type';
    if ($f['type'] === 'choice' && array_diff(array_column($f['options'] ?? [], 'key'), array_column(json_decode((string)$attr['choices'], true) ?: [], 'key'))) return 'choices';
    return null;
}

/** בדיקות הפרסום של מקטע "ביקור" (§4): [errors, warnings] — כל שורה [code, message, ref]. */
function apr_pc_visit_checks(array $form, array $attrs): array
{
    $err = $warn = [];
    $groups = array_column($form['groups'], null, 'key');
    if (count($groups) !== count($form['groups'])) $err[] = ['duplicate_key', 'שתי קבוצות בטופס הביקור עם אותו מפתח', 'visit'];
    [, $ordered] = apr_visit_form_ordered($form);
    $pos = [];
    foreach ($ordered as $i => $f) $pos[$f['key']] ??= $i;
    $seen = [];
    foreach ($form['fields'] as $f) {
        $ref = 'visit:' . $f['key'];
        $name = '"' . ($f['label'] !== '' ? $f['label'] : $f['key']) . '"';
        if (isset($seen[$f['key']])) $err[] = ['duplicate_key', "שני שדות בטופס הביקור עם אותו מפתח ({$f['key']})", $ref];
        $seen[$f['key']] = $f;
        if ($f['label'] === '') $err[] = ['no_title', 'שדה בטופס הביקור בלי כותרת', $ref];
        if (!isset($groups[$f['group']])) { $err[] = ['unknown_group', "השדה $name שייך לקבוצה שלא קיימת", $ref]; continue; }
        if (in_array($f['type'], ['choice', 'multichoice'], true) && !($f['options'] ?? [])) $err[] = ['visit_no_options', "לשדה $name אין אפשרויות לבחירה", $ref];
        if (count(array_unique(array_column($f['options'] ?? [], 'key'))) !== count($f['options'] ?? [])) $err[] = ['duplicate_key', "בשדה $name שתי אפשרויות עם אותו מפתח", $ref];
        if ($f['maps_to'] !== null) {
            $a = $attrs[$f['maps_to']] ?? null;
            if (!$a) $err[] = ['visit_maps_unknown', "השדה $name מוצע למאפיין שלא קיים בקטלוג ({$f['maps_to']})", $ref];
            elseif ($groups[$f['group']]['repeat']) $err[] = ['visit_maps_repeat', "השדה $name נמצא בקבוצה חוזרת — שדה כזה אינו יכול להציע ערך לנתוני התיק", $ref];
            elseif (($why = apr_visit_maps_problem($f, $a)) !== null) $err[] = ['visit_maps_type', "השדה $name אינו מתאים למאפיין \"{$a['label']}\" (" . ($why === 'choices' ? 'האפשרויות שונות' : 'סוג הערך שונה') . ')', $ref];
        } elseif ($f['type'] === 'area') $warn[] = ['visit_area_unmapped', "שדה השטח $name אינו מוצע לאף מאפיין — הוא לא יגיע לנתוני התיק", $ref];
    }
    // תנאי שמפנה לשדה שלא קיים, או לשדה שמופיע אחריו; תשובה בקבוצה חוזרת נראית רק מתוך אותה קבוצה
    $leaves = function (string $ownGroup, ?int $ownPos, ?array $when, string $ref, string $name) use (&$err, $seen, $pos, $groups) {
        foreach (apr_pc_leaves($when) as $leaf) {
            if (!str_starts_with($leaf['field'], 'answer.')) continue;
            $k = substr($leaf['field'], 7);
            $t = $seen[$k] ?? null;
            if (!$t || !isset($pos[$k])) { $err[] = ['visit_when_unknown', "התנאי של $name מפנה לשדה שלא קיים ($k)", $ref]; continue; }
            if ($t['type'] === 'photo') $err[] = ['visit_when_unknown', "התנאי של $name מפנה לשדה תמונה ($k)", $ref];
            if ($ownPos !== null ? $pos[$k] >= $ownPos : false) $err[] = ['visit_when_order', "התנאי של $name מפנה לשדה שמופיע אחריו ($k)", $ref];
            if (($groups[$t['group']]['repeat'] ?? false) && $t['group'] !== $ownGroup) $err[] = ['visit_when_repeat', "התנאי של $name מפנה לשדה בקבוצה חוזרת אחרת ($k)", $ref];
        }
    };
    foreach ($form['fields'] as $f) $leaves($f['group'], $pos[$f['key']] ?? null, $f['when'], 'visit:' . $f['key'], 'השדה "' . ($f['label'] ?: $f['key']) . '"');
    foreach ($form['groups'] as $g) {
        $first = min(array_merge([PHP_INT_MAX], array_map(fn($f) => $pos[$f['key']] ?? PHP_INT_MAX, array_filter($form['fields'], fn($f) => $f['group'] === $g['key']))));
        $leaves('', $first === PHP_INT_MAX ? null : $first, $g['when'], 'visit-group:' . $g['key'], 'הקבוצה "' . ($g['title'] ?: $g['key']) . '"');
    }
    if (!$form['fields']) $warn[] = ['visit_empty', 'בטופס הביקור אין אף שדה', 'visit'];
    return [$err, $warn];
}

/** ערך של תשובה לצורך תנאי: כן/לא ← bool; בחירה ← מפתח; בחירה מרובה ← רשימה; כל השאר ← מחרוזת. null — לא מולא. */
function apr_visit_cond_value(?array $a, string $type): mixed
{
    if (!$a) return null;
    return match ($type) {
        'yesno' => $a['value_num'] === null ? null : (int)$a['value_num'] !== 0,
        'number', 'area', 'length' => apr_fact_num_out($a['value_num']),
        'date' => $a['value_date'],
        'multichoice' => $a['value_json'] === null ? null : (json_decode((string)$a['value_json'], true) ?: []),
        default => $a['value_text'],
    };
}

/** מעריך תנאי של הטופס. $in: ['answers' => [מפתח => ערך], 'property_type', 'purpose']. */
function apr_visit_eval(?array $c, array $in): bool
{
    if ($c === null) return true;
    if (isset($c['all'])) {
        foreach ($c['all'] as $k) if (!apr_visit_eval($k, $in)) return false;
        return true;
    }
    if (isset($c['any'])) {
        foreach ($c['any'] as $k) if (apr_visit_eval($k, $in)) return true;
        return false;
    }
    $actual = str_starts_with($c['field'], 'answer.') ? ($in['answers'][substr($c['field'], 7)] ?? null) : ($in[$c['field']] ?? null);
    $has = fn($v) => is_array($actual) ? in_array($v, $actual, true) : $actual === $v;
    return match ($c['op']) {
        'is' => $has($c['value']),
        'is_not' => !$has($c['value']),
        'in' => (bool)array_filter((array)$c['value'], $has),
        'not_in' => !array_filter((array)$c['value'], $has),
    };
}

function apr_visit_seed_field(string $key, string $group, string $label, string $type, int $sort, array $more = []): array
{
    return ['key' => $key, 'group' => $group, 'label' => $label, 'type' => $type, 'required' => false, 'when' => null, 'sort' => $sort] + $more;
}

/** טופס ברירת המחדל — שש הקבוצות שבאפיון 26.2. דוגמה בלבד: הבעלים ימסור טופס אמיתי (אפיון 13 §13). */
function apr_visit_default_form(): array
{
    static $form = null;
    if ($form !== null) return $form;
    $f = 'apr_visit_seed_field';
    $yes = ['field' => 'answer.has_elevator', 'op' => 'is', 'value' => true];
    $cond = [['key' => 'good', 'label' => 'טוב'], ['key' => 'fair', 'label' => 'בינוני'], ['key' => 'poor', 'label' => 'טעון שיפוץ']];
    $raw = ['example' => true, 'completion' => ['block_on_required' => true],
        'groups' => [
            ['key' => 'ident', 'title' => 'זיהוי וביקור', 'sort' => 1], ['key' => 'surroundings', 'title' => 'סביבה ובניין', 'sort' => 2],
            ['key' => 'interior', 'title' => 'פנים הנכס', 'sort' => 3], ['key' => 'rooms', 'title' => 'חדרים', 'sort' => 4, 'repeat' => true],
            ['key' => 'areas', 'title' => 'שטחים והצמדות', 'sort' => 5], ['key' => 'findings', 'title' => 'ממצאים ומגבלות', 'sort' => 6],
            ['key' => 'photos', 'title' => 'תצלומים', 'sort' => 7]],
        'fields' => [
            $f('visit_date', 'ident', 'תאריך הביקור', 'date', 1, ['required' => true]),
            $f('address_seen', 'ident', 'הכתובת כפי שזוהתה במקום', 'text', 2, ['required' => true]),
            $f('access', 'ident', 'דרך הגישה לנכס וזיהויו', 'text', 3, ['hint' => 'איך זוהה הנכס: שלט, מספר דירה, מי פתח']),
            $f('env_uses', 'surroundings', 'שימושי הסביבה', 'longtext', 1, ['hint' => 'תיאור עובדתי של מה שנצפה']),
            $f('building_type', 'surroundings', 'סוג הבניין', 'text', 2),
            $f('building_floors', 'surroundings', 'מספר הקומות בבניין', 'number', 3, ['maps_to' => 'floors_total']),
            $f('building_state', 'surroundings', 'מצב הבניין', 'choice', 4, ['options' => $cond]),
            $f('has_elevator', 'surroundings', 'יש מעלית', 'yesno', 5),
            $f('elevator_count', 'surroundings', 'מספר המעליות', 'number', 6, ['when' => $yes]),
            $f('building_opinion', 'surroundings', 'הערכה מקצועית על הבניין והסביבה', 'longtext', 7, ['hint' => 'הערכה — בנפרד מהתיאור העובדתי', 'obtained' => 'estimated']),
            $f('floor', 'interior', 'קומה', 'number', 1, ['maps_to' => 'floor']),
            $f('layout', 'interior', 'חלוקה פנימית', 'longtext', 2),
            $f('maintenance', 'interior', 'מצב תחזוקה', 'choice', 3, ['options' => $cond]),
            $f('actual_use', 'interior', 'שימוש בפועל', 'text', 4, ['maps_to' => 'actual_use']),
            $f('room_name', 'rooms', 'החדר', 'text', 1, ['required' => true]),
            $f('room_length', 'rooms', 'אורך', 'length', 2),
            $f('room_width', 'rooms', 'רוחב', 'length', 3),
            $f('room_note', 'rooms', 'מצב והערות', 'text', 4),
            $f('area_measured', 'areas', 'שטח שנמדד או נמסר', 'area', 1, ['maps_to' => 'area']),
            $f('parking', 'areas', 'חניה', 'text', 2),
            $f('storage', 'areas', 'מחסן', 'text', 3),
            $f('balcony_area', 'areas', 'שטח המרפסת', 'area', 4, ['maps_to' => 'attachment_area']),
            $f('not_checked', 'findings', 'חלקים שלא נבדקו', 'longtext', 1, ['hint' => 'מה לא נראה או לא נמדד, ולמה']),
            $f('possible_mismatch', 'findings', 'אי התאמה אפשרית למסמכים', 'longtext', 2, ['creates_task' => true, 'hint' => 'מה נראה שונה מהמסמכים. נפתחת משימת בדיקה — אין כאן קביעה']),
            $f('remarks', 'findings', 'הערות', 'longtext', 3),
            $f('front_photo', 'photos', 'חזית הבניין', 'photo', 1, ['required' => true, 'min' => 1]),
            $f('entrance_photo', 'photos', 'כניסה', 'photo', 2),
            $f('rooms_photo', 'photos', 'חדרים', 'photo', 3),
            $f('view_photo', 'photos', 'נוף', 'photo', 4),
            $f('defects_photo', 'photos', 'ליקויים', 'photo', 5)]];
    return $form = apr_pc_visit($raw);
}

