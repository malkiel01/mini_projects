<?php
/**
 * תוכן של גרסת פרופיל (אפיון 03 §4, §8): אימות המבנה בשמירה, בדיקות לפני פרסום, תצוגת ניסיון, והשוואה בין גרסאות.
 * פונקציות טהורות — לא נוגעות במסד (חוץ מרשימת סוגי המסמך, שמועברת פנימה).
 *
 * תנאי הוא עץ של all / any עם עלים {field, op, value}. אין eval ואין ביטוי טקסטואלי: מה שאינו במבנה הזה נדחה בשמירה.
 * השמירה מאמתת **מבנה**; הפרסום מאמת **משמעות** (מפתח כפול, דגל שלא הוגדר, ערך שאינו ברשימה) — כדי שטיוטה תוכל
 * להישמר גם באמצע עבודה.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

require_once __DIR__ . '/contact_content.php';

const APR_PROFILE_SCHEMA = 1;
const APR_PURPOSES = ['purchase', 'collateral', 'renovation', 'construction', 'income', 'taxation', 'dispute', 'other'];
const APR_PROPERTY_TYPES = ['apartment', 'house', 'commercial', 'office', 'industrial', 'land', 'complex', 'other'];
const APR_PRIORITIES = ['high', 'normal', 'low'];
const APR_PARTY_KINDS = ['client', 'lawyer', 'bank', 'architect', 'surveyor', 'office', 'other'];
const APR_DUE_MODES = ['relative', 'manual', 'none'];
const APR_ASSIGNEES = ['responsible', 'creator', 'unassigned'];
const APR_COND_OPS = ['is', 'is_not', 'in', 'not_in'];
const APR_RULE_ACTIONS = ['add_requirement', 'add_task', 'alert'];
const APR_COND_DEPTH = 3;
const APR_PROFILE_LIMITS = ['requirements' => 300, 'tasks' => 100, 'rules' => 100, 'groups' => 50, 'flags' => 50];

function apr_profile_empty_content(): array
{
    return ['schema' => APR_PROFILE_SCHEMA, 'flags' => [], 'requirements' => ['groups' => [], 'items' => []], 'tasks' => ['items' => []], 'rules' => [],
        'contact' => apr_contact_defaults(),
        'sources' => ['items' => [], 'spend_needs_approval' => true]];     // "מקורות" (אפיון 09 §8) — lib/sources.php     // "קשר ותזכורות" (אפיון 08 §4) — lib/contact_content.php
}

// ── אימות המבנה בשמירה ───────────────────────────────────────

function apr_pc_key(mixed $v): string
{
    if (!is_string($v) || !preg_match('/^[a-z0-9_]{1,40}\z/', $v)) apr_fail('content_invalid');   // \z ולא $: $ מתאים גם לפני ירידת שורה אחרונה
    return $v;
}

function apr_pc_text(mixed $v, int $max): string
{
    if ($v === null) return '';
    if (!is_string($v)) apr_fail('content_invalid');
    $s = trim(preg_replace('/[ \t]+/u', ' ', $v) ?? '');
    if (mb_strlen($s) > $max) apr_fail('text_too_long');
    return $s;
}

function apr_pc_enum(mixed $v, array $allowed, string $default): string
{
    if ($v === null) return $default;
    if (!is_string($v) || !in_array($v, $allowed, true)) apr_fail('content_invalid');
    return $v;
}

function apr_pc_list(mixed $v, int $max, string $limitCode = 'content_too_large'): array
{
    if ($v === null) return [];
    if (!is_array($v) || !array_is_list($v)) apr_fail('content_invalid');
    if (count($v) > $max) apr_fail($limitCode);
    foreach ($v as $row) if (!is_array($row)) apr_fail('content_invalid');
    return $v;
}

function apr_pc_due(mixed $v): array
{
    if ($v === null) return ['mode' => 'none'];
    if (!is_array($v)) apr_fail('content_invalid');
    $mode = apr_pc_enum($v['mode'] ?? null, APR_DUE_MODES, 'none');
    if ($mode !== 'relative') return ['mode' => $mode];
    $days = $v['days'] ?? null;
    if (!is_int($days) || $days < 0 || $days > 365) apr_fail('content_invalid');
    return ['mode' => 'relative', 'days' => $days];
}

/**
 * תנאי: null (תמיד), או עץ. שדה לא מוכר, אופרטור לא מוכר, מבנה אחר, או עומק מעל 3 — condition_invalid.
 * דגל שלא הוגדר וערך שאינו ברשימה עוברים כאן (מבנה תקין), ונחסמים בפרסום.
 */
function apr_pc_condition(mixed $c, int $depth = 1): ?array
{
    if ($c === null) return null;
    if (!is_array($c) || array_is_list($c)) apr_fail('condition_invalid');
    $keys = array_keys($c);
    if ($keys === ['all'] || $keys === ['any']) {
        if ($depth > APR_COND_DEPTH) apr_fail('condition_invalid');
        $kids = $c[$keys[0]];
        if (!is_array($kids) || !array_is_list($kids) || count($kids) > 20) apr_fail('condition_invalid');
        return [$keys[0] => array_map(fn($k) => apr_pc_condition($k, $depth + 1) ?? apr_fail('condition_invalid'), $kids)];
    }
    sort($keys);
    if ($keys !== ['field', 'op', 'value']) apr_fail('condition_invalid');
    $field = $c['field'];
    if (!is_string($field) || !preg_match('/^(purpose|property_type|flags\.[a-z0-9_]{1,40}|party\.(' . implode('|', APR_PARTY_KINDS) . '))\z/', $field)) apr_fail('condition_invalid');
    if (!is_string($c['op']) || !in_array($c['op'], APR_COND_OPS, true)) apr_fail('condition_invalid');
    $list = in_array($c['op'], ['in', 'not_in'], true);
    $bool = str_starts_with($field, 'flags.') || str_starts_with($field, 'party.');
    $v = $c['value'];
    if ($bool) {
        if ($list || !is_bool($v)) apr_fail('condition_invalid');
    } elseif ($list) {
        if (!is_array($v) || !array_is_list($v) || count($v) > 30) apr_fail('condition_invalid');
        foreach ($v as $x) if (!is_string($x) || mb_strlen($x) > 40) apr_fail('condition_invalid');
    } elseif (!is_string($v) || mb_strlen($v) > 40) {
        apr_fail('condition_invalid');
    }
    return ['field' => $field, 'op' => $c['op'], 'value' => $v];
}

function apr_pc_requirement(array $r): array
{
    return [
        'key' => apr_pc_key($r['key'] ?? null),
        'group' => ($r['group'] ?? '') === '' || ($r['group'] ?? null) === null ? '' : apr_pc_key($r['group']),
        'doc_type' => ($r['doc_type'] ?? '') === '' || ($r['doc_type'] ?? null) === null ? 'other' : apr_pc_key($r['doc_type']),
        'title' => apr_pc_text($r['title'] ?? '', 160),
        'explain_recipient' => apr_pc_text($r['explain_recipient'] ?? '', 600),
        'explain_internal' => apr_pc_text($r['explain_internal'] ?? '', 600),
        'priority' => apr_pc_enum($r['priority'] ?? null, APR_PRIORITIES, 'normal'),
        'mandatory' => ($r['mandatory'] ?? false) === true,
        'party_kind' => apr_pc_enum($r['party_kind'] ?? null, APR_PARTY_KINDS, 'client'),
        'due' => apr_pc_due($r['due'] ?? null),
        'needs_review' => ($r['needs_review'] ?? false) === true,
        'when' => apr_pc_condition($r['when'] ?? null),
        'sort' => is_int($r['sort'] ?? null) ? $r['sort'] : 0,
    ];
}

function apr_pc_task(array $t): array
{
    return [
        'key' => apr_pc_key($t['key'] ?? null),
        'title' => apr_pc_text($t['title'] ?? '', 160),
        'assignee' => apr_pc_enum($t['assignee'] ?? null, APR_ASSIGNEES, 'responsible'),
        'due' => apr_pc_due($t['due'] ?? null),
        'when' => apr_pc_condition($t['when'] ?? null),
        'sort' => is_int($t['sort'] ?? null) ? $t['sort'] : 0,
    ];
}

/** מנקה ומאמת תוכן שהגיע מהדפדפן. מחזיר את התוכן המנורמל, או זורק content_invalid / condition_invalid / content_too_large. */
function apr_profile_content_clean(mixed $c): array
{
    if (!is_array($c) || array_is_list($c) && $c !== []) apr_fail('content_invalid');
    $out = apr_profile_empty_content();
    foreach (apr_pc_list($c['flags'] ?? null, APR_PROFILE_LIMITS['flags']) as $f) {
        $out['flags'][] = ['key' => apr_pc_key($f['key'] ?? null), 'title' => apr_pc_text($f['title'] ?? '', 120), 'question' => apr_pc_text($f['question'] ?? '', 300)];
    }
    $req = $c['requirements'] ?? [];
    if (!is_array($req)) apr_fail('content_invalid');
    foreach (apr_pc_list($req['groups'] ?? null, APR_PROFILE_LIMITS['groups']) as $g) {
        $out['requirements']['groups'][] = ['key' => apr_pc_key($g['key'] ?? null), 'title' => apr_pc_text($g['title'] ?? '', 120), 'sort' => is_int($g['sort'] ?? null) ? $g['sort'] : 0];
    }
    foreach (apr_pc_list($req['items'] ?? null, APR_PROFILE_LIMITS['requirements']) as $r) $out['requirements']['items'][] = apr_pc_requirement($r);
    $tasks = $c['tasks'] ?? [];
    if (!is_array($tasks)) apr_fail('content_invalid');
    foreach (apr_pc_list($tasks['items'] ?? null, APR_PROFILE_LIMITS['tasks']) as $t) $out['tasks']['items'][] = apr_pc_task($t);
    foreach (apr_pc_list($c['rules'] ?? null, APR_PROFILE_LIMITS['rules']) as $rule) {
        $then = [];
        foreach (apr_pc_list($rule['then'] ?? null, 20) as $a) {
            $do = apr_pc_enum($a['do'] ?? '', APR_RULE_ACTIONS, '');
            if ($do === 'alert') $then[] = ['do' => 'alert', 'text' => apr_pc_text($a['text'] ?? '', 300)];
            elseif (!is_array($a['item'] ?? null)) apr_fail('content_invalid');
            // פריט שכלל מוסיף אינו נושא תנאי משלו — התנאי הוא של הכלל
            elseif (($a['item']['when'] ?? null) !== null) apr_fail('condition_invalid');
            else $then[] = ['do' => $do, 'item' => $do === 'add_requirement' ? apr_pc_requirement($a['item']) : apr_pc_task($a['item'])];
        }
        $out['rules'][] = ['key' => apr_pc_key($rule['key'] ?? null), 'title' => apr_pc_text($rule['title'] ?? '', 160),
            'when' => apr_pc_condition($rule['when'] ?? null), 'then' => $then];
    }
    $out['contact'] = apr_contact_clean($c['contact'] ?? null);
    $out['sources'] = apr_pc_sources($c['sources'] ?? null);
    return $out;
}

// ── בדיקות לפני פרסום (§8) ───────────────────────────────────

/** כל העלים של תנאי. */
function apr_pc_leaves(?array $c): array
{
    if ($c === null) return [];
    if (isset($c['field'])) return [$c];
    return array_merge([], ...array_map('apr_pc_leaves', $c['all'] ?? $c['any'] ?? []));
}

/** מה לא בסדר בתנאי מבחינת משמעות: דגל שלא הוגדר, או ערך שאינו ברשימה. מחזיר רשימת הסברים (ריקה = תקין). */
function apr_pc_condition_problems(?array $c, array $flagKeys): array
{
    $out = [];
    foreach (apr_pc_leaves($c) as $leaf) {
        $f = $leaf['field'];
        if (str_starts_with($f, 'flags.')) {
            if (!in_array(substr($f, 6), $flagKeys, true)) $out[] = 'הדגל "' . substr($f, 6) . '" לא מוגדר בפרופיל';
        } elseif ($f === 'purpose' || $f === 'property_type') {
            $allowed = $f === 'purpose' ? APR_PURPOSES : APR_PROPERTY_TYPES;
            foreach ((array)$leaf['value'] as $v) if (!in_array($v, $allowed, true)) $out[] = 'ערך שאינו ברשימה';
        }
    }
    return $out;
}

/**
 * הבדיקות לפני פרסום. מחזיר ['errors' => [...], 'warnings' => [...]]; כל שורה: code, message, ref (איפה).
 * errors חוסמות פרסום. $docTypes — המפתחות הקיימים של סוגי המסמך.
 */
function apr_profile_checks(array $content, array $docTypes): array
{
    $err = $warn = [];
    $add = function (array &$to, string $code, string $message, string $ref) { $to[] = ['code' => $code, 'message' => $message, 'ref' => $ref]; };
    $flagKeys = array_column($content['flags'], 'key');
    $groupKeys = array_column($content['requirements']['groups'], 'key');
    $items = $content['requirements']['items'];
    $tasks = $content['tasks']['items'];
    $rules = $content['rules'];

    // שני פריטים עם אותו key — בכל רשימה בנפרד
    foreach (['requirement' => array_column($items, 'key'), 'task' => array_column($tasks, 'key'), 'rule' => array_column($rules, 'key'),
        'group' => $groupKeys, 'flag' => $flagKeys] as $kind => $keys) {
        foreach (array_keys(array_filter(array_count_values($keys), fn($n) => $n > 1)) as $dup) $add($err, 'dup_key', "המפתח \"$dup\" מופיע פעמיים", "$kind:$dup");
    }
    $checkCond = function (?array $when, string $ref) use (&$err, $add, $flagKeys) {
        foreach (array_unique(apr_pc_condition_problems($when, $flagKeys)) as $p) $add($err, 'bad_condition', "תנאי לא תקין: $p", $ref);
    };
    $checkReq = function (array $r, string $ref) use (&$err, &$warn, $add, $groupKeys, $docTypes, $checkCond) {
        if ($r['title'] === '') $add($err, 'no_title', 'דרישה בלי כותרת', $ref);
        if ($r['group'] !== '' && !in_array($r['group'], $groupKeys, true)) $add($err, 'bad_group', 'הדרישה משויכת לקבוצה שלא קיימת', $ref);
        if (!in_array($r['doc_type'], $docTypes, true)) $add($err, 'bad_doc_type', 'סוג מסמך שאינו ברשימה', $ref);
        $checkCond($r['when'], $ref);
        if ($r['party_kind'] === 'other' && $r['explain_recipient'] === '') $add($warn, 'other_no_explain', 'הגורם "אחר" בלי הסבר לנמען', $ref);
        if ($r['mandatory'] && $r['due']['mode'] === 'none') $add($warn, 'mandatory_no_due', 'דרישת חובה בלי מועד', $ref);
    };
    foreach ($items as $r) $checkReq($r, 'requirement:' . $r['key']);
    foreach ($tasks as $t) {
        if ($t['title'] === '') $add($err, 'no_title', 'משימה בלי כותרת', 'task:' . $t['key']);
        $checkCond($t['when'], 'task:' . $t['key']);
    }
    $seen = [];
    $itemKeys = array_column($items, 'key');
    $taskKeys = array_column($tasks, 'key');
    // מפתח כפול גם בין פריטים שכללים מוסיפים (תיק שומר origin_key ומשווה לפיו) — לכל רשימה בנפרד
    foreach (['add_requirement' => 'requirement', 'add_task' => 'task'] as $do => $kind) {
        $keys = [];
        foreach ($rules as $rule) foreach ($rule['then'] as $a) if ($a['do'] === $do) $keys[] = $a['item']['key'];
        foreach (array_keys(array_filter(array_count_values($keys), fn($n) => $n > 1)) as $dup) $add($err, 'dup_key', "המפתח \"$dup\" מופיע פעמיים בפריטים שכללים מוסיפים", "$kind:$dup");
    }
    foreach ($rules as $rule) {
        $ref = 'rule:' . $rule['key'];
        $checkCond($rule['when'], $ref);
        $sig = json_encode($rule['when']);
        if (isset($seen[$sig])) $add($warn, 'same_condition', 'שני כללים עם אותו תנאי בדיוק', $ref);
        $seen[$sig] = true;
        foreach ($rule['then'] as $a) {
            if ($a['do'] === 'add_requirement') {
                // כלל שמוסיף דרישה עם key שכבר קיים ברשימה הקבועה
                if (in_array($a['item']['key'], $itemKeys, true)) $add($err, 'rule_key_exists', 'הכלל מוסיף דרישה עם מפתח שכבר קיים ברשימה ("' . $a['item']['key'] . '")', $ref);
                $checkReq($a['item'], $ref);
            } elseif ($a['do'] === 'add_task') {
                if (in_array($a['item']['key'], $taskKeys, true)) $add($err, 'rule_key_exists', 'הכלל מוסיף משימה עם מפתח שכבר קיים ברשימה ("' . $a['item']['key'] . '")', $ref);
                if ($a['item']['title'] === '') $add($err, 'no_title', 'משימה בלי כותרת', $ref);
            }
        }
    }
    // נוסח עם משתנה שאינו ברשימה הסגורה חוסם פרסום (08-D3)
    foreach (apr_contact_checks(apr_contact_clean($content['contact'] ?? null)) as [$code, $message, $ref]) $add($err, $code, $message, $ref);
    // מקור שהוסר או הוסתר בקטלוג — אזהרה בלבד (09, מקרה קצה 10)
    foreach (apr_pc_sources_checks($content['sources'] ?? []) as [$code, $message, $ref]) $add($warn, $code, $message, $ref);
    if (!$items && !$tasks) $add($warn, 'empty', 'בפרופיל אין אף דרישה ואף משימה', 'profile');
    return ['errors' => $err, 'warnings' => $warn];
}

// ── תצוגת ניסיון (§7) ────────────────────────────────────────

/** מעריך תנאי מול קלט: ['purpose', 'property_type', 'flags' => [key => bool], 'parties' => [kind => bool]]. */
function apr_pc_eval(?array $c, array $input): bool
{
    if ($c === null) return true;
    if (isset($c['all'])) {
        foreach ($c['all'] as $k) if (!apr_pc_eval($k, $input)) return false;
        return true;
    }
    if (isset($c['any'])) {
        foreach ($c['any'] as $k) if (apr_pc_eval($k, $input)) return true;
        return false;
    }
    $f = $c['field'];
    if (str_starts_with($f, 'flags.')) $actual = ($input['flags'][substr($f, 6)] ?? false) === true;
    elseif (str_starts_with($f, 'party.')) $actual = ($input['parties'][substr($f, 6)] ?? false) === true;
    else $actual = $input[$f] ?? null;
    return match ($c['op']) {
        'is' => $actual === $c['value'],
        'is_not' => $actual !== $c['value'],
        'in' => in_array($actual, (array)$c['value'], true),
        'not_in' => !in_array($actual, (array)$c['value'], true),
    };
}

/**
 * מה ייווצר לתיק עם הקלט הזה: דרישות, משימות והתרעות. פריט שנוסף בכלל נושא 'rule' — המפתח והכותרת של הכלל.
 * חישוב בלבד: לא נוגע במסד.
 */
function apr_profile_try(array $content, array $input): array
{
    $input = ['purpose' => is_string($input['purpose'] ?? null) ? $input['purpose'] : null,
        'property_type' => is_string($input['property_type'] ?? null) ? $input['property_type'] : null,
        'flags' => is_array($input['flags'] ?? null) ? $input['flags'] : [], 'parties' => is_array($input['parties'] ?? null) ? $input['parties'] : []];
    $reqs = $tasks = $alerts = [];
    $view = fn(array $r, ?array $rule) => array_diff_key($r, ['when' => 0, 'explain_internal' => 0]) + ['rule' => $rule];
    foreach ($content['requirements']['items'] as $r) if (apr_pc_eval($r['when'], $input)) $reqs[] = $view($r, null);
    foreach ($content['tasks']['items'] as $t) if (apr_pc_eval($t['when'], $input)) $tasks[] = $view($t, null);
    foreach ($content['rules'] as $rule) {
        if (!apr_pc_eval($rule['when'], $input)) continue;
        $by = ['key' => $rule['key'], 'title' => $rule['title']];
        foreach ($rule['then'] as $a) {
            if ($a['do'] === 'add_requirement') $reqs[] = $view($a['item'], $by);
            elseif ($a['do'] === 'add_task') $tasks[] = $view($a['item'], $by);
            else $alerts[] = ['text' => $a['text'], 'rule' => $by];
        }
    }
    $groupSort = array_column($content['requirements']['groups'], 'sort', 'key');
    usort($reqs, fn($a, $b) => [$groupSort[$a['group']] ?? PHP_INT_MAX, $a['sort']] <=> [$groupSort[$b['group']] ?? PHP_INT_MAX, $b['sort']]);
    usort($tasks, fn($a, $b) => $a['sort'] <=> $b['sort']);
    return ['groups' => $content['requirements']['groups'], 'requirements' => $reqs, 'tasks' => $tasks, 'alerts' => $alerts];
}

// ── השוואה בין שתי גרסאות ────────────────────────────────────

/** מה נוסף, מה הוסר ומה השתנה בין $a (ישנה) ל-$b (חדשה): לכל מקטע — רשימות של {key, title}. */
function apr_profile_diff(array $a, array $b): array
{
    $sections = ['flags' => fn($c) => $c['flags'], 'groups' => fn($c) => $c['requirements']['groups'], 'requirements' => fn($c) => $c['requirements']['items'],
        'tasks' => fn($c) => $c['tasks']['items'], 'rules' => fn($c) => $c['rules'],
        // "מקורות" (09): המפתח הוא המקור, כדי ששינוי של mode ייראה בהשוואה
        'sources' => fn($c) => array_map(fn($i) => ['key' => $i['source'], 'title' => $i['source']] + $i, $c['sources']['items'] ?? [])];
    $out = [];
    foreach ($sections as $name => $get) {
        $old = array_column($get($a), null, 'key');
        $new = array_column($get($b), null, 'key');
        $row = fn(array $x) => ['key' => $x['key'], 'title' => $x['title'] ?? ''];
        $out[$name] = [
            'added' => array_values(array_map($row, array_diff_key($new, $old))),
            'removed' => array_values(array_map($row, array_diff_key($old, $new))),
            'changed' => array_values(array_map($row, array_filter(array_intersect_key($new, $old), fn($x) => $x != $old[$x['key']]))),
        ];
    }
    $out['total'] = array_sum(array_map(fn($s) => count($s['added']) + count($s['removed']) + count($s['changed']), $out));
    return $out;
}
