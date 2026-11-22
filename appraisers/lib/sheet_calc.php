<?php
/**
 * פרוסה 16 — מנוע גיליון החישוב (docs/spec/16-valuation-methods.md §5, §6). קוד דטרמיניסטי בלבד: אין כאן מסד, אין שעון, אין רשת,
 * אין קריאה למודל שפה, אין eval ואין נקודה צפה. הקובץ אינו טוען דבר: **כל החשבון נעשה בפונקציות של מנוע 15** (lib/comp_calc.php —
 * apr_n_*, apr_q_*, והעיגול היחיד apr_q_round), ומי שטוען את הקובץ הזה טוען אותו קודם. tests/t-valuation.test.php בודק זאת סטטית (16-B5).
 *
 * גיליון = רשימת שורות לפי הסדר. שורת קלט נושאת ערך במיליוניות (שלם; NULL = ריק, ו"ריק" אינו אפס); שורת חישוב נושאת נוסחה — מבנה,
 * לא טקסט: מפתח של שורה ("r3"), מספר (מחרוזת עשרונית), {"pct": x}, או {"op": …, "args": […]}. שורה מפנה רק לשורות שמעליה — ולכן אין מעגלים.
 * המנוע אינו יודע מהי הכנסה, עלות או שיעור היוון: הוא רק מחשב את מה שהשמאי כתב.
 */

declare(strict_types=1);

const APR_SHEET_OPS = ['add', 'sub', 'mul', 'div', 'sum', 'pct'];
const APR_SHEET_MAX_ROWS = 100;
const APR_SHEET_MAX_ARGS = 20;
const APR_SHEET_MAX_DEPTH = 4;
/** צמתים בנוסחה אחת (פעולות + אופרנדים). נוסחה ארוכה מזה מתפצלת לכמה שורות — וכך גם גודל הנוסחה השמורה חסום. */
const APR_SHEET_MAX_NODES = 40;
/** מונה או מכנה ארוכים מזה ← "המספר ארוך מדי" במקום חישוב שאינו נגמר. */
const APR_SHEET_MAX_DIGITS = 80;
/** עד האורך הזה שבר נשאר כפי שיצא מהמנוע של 15 (צמצום זול בלבד); מעליו — צמצום מלא (אוקלידס על מחרוזות, יקר). */
const APR_SHEET_REDUCE_OVER = 40;
/**
 * תקציב הפעולות של גיליון אחד — מונה, לא שעון (המנוע נשאר טהור ודטרמיניסטי). כל צומת עולה APR_SHEET_NODE_COST; צמצום מלא עולה
 * (ספרות המונה × ספרות המכנה). כשהתקציב נגמר — השורה וכל מה שאחריה חוזרות too_long, במקום בקשה שאינה נגמרת (ביקורת 16, B1).
 * גיליון רגיל (מאה שורות של סכומים, מכפלות ואחוזים) צורך אחוזים בודדים ממנו.
 */
const APR_SHEET_BUDGET = 600000;
const APR_SHEET_NODE_COST = 30;
const APR_SHEET_SCALE = '1000000';      // ערכי הקלט במיליוניות

// ── הנוסחה: בדיקה וצורה קנונית ────────────────────────────────

function apr_sheet_is_key(mixed $v): bool
{
    return is_string($v) && preg_match('/^r[1-9]\d{0,5}\z/', $v) === 1;
}

/** מספר קבוע בנוסחה: שלם, או מחרוזת עשרונית (עד 12 ספרות ו-6 אחרי הנקודה). מחזיר את הצורה הקנונית, או null. */
function apr_sheet_const(mixed $v): ?string
{
    if (is_int($v)) $v = (string)$v;
    if (!is_string($v) || preg_match('/^(-?)0*(\d{1,12})(?:\.(\d{1,6}))?\z/', $v, $m) !== 1) return null;
    $frac = rtrim($m[3] ?? '', '0');
    $zero = $m[2] === '0' && $frac === '';
    return ($zero ? '' : $m[1]) . $m[2] . ($frac === '' ? '' : '.' . $frac);
}

/**
 * בודק נוסחה ומחזיר אותה בצורה קנונית: [נוסחה | null, קוד שגיאה | null]. $above — מפתחות השורות המספריות (קלט / חישוב) שמעל
 * השורה, לפי הסדר. הפניה לשורה שאינה שם (מתחת, נמחקה, הערה, או לא קיימת) ← sheet_ref; פעולה לא מוכרת ← sheet_op; מבנה אחר ← sheet_formula;
 * יותר מ-APR_SHEET_MAX_NODES צמתים ← sheet_formula_long ($nodes — מונה פנימי של הרקורסיה).
 */
function apr_sheet_formula(mixed $f, array $above, int $depth = 0, int &$nodes = 0): array
{
    if ($depth > APR_SHEET_MAX_DEPTH) return [null, 'sheet_formula'];
    if (++$nodes > APR_SHEET_MAX_NODES) return [null, 'sheet_formula_long'];
    if ($depth === 0 && !is_array($f)) return [null, 'sheet_formula'];      // נוסחה היא תמיד פעולה — לא מפתח ולא מספר לבדם
    if (apr_sheet_is_key($f)) return in_array($f, $above, true) ? [$f, null] : [null, 'sheet_ref'];
    if (is_int($f) || is_string($f)) {
        // כל מחרוזת שנראית כמו מפתח של שורה ואינה כזו — הפניה שגויה, לא מספר
        if (is_string($f) && preg_match('/^r/i', $f) === 1) return [null, 'sheet_ref'];
        $c = $depth === 0 ? null : apr_sheet_const($f);
        return $c === null ? [null, 'sheet_formula'] : [$c, null];
    }
    if (!is_array($f) || array_is_list($f)) return [null, 'sheet_formula'];
    if (array_keys($f) === ['pct']) $f = ['op' => 'pct', 'args' => [$f['pct']]];
    if (array_diff(array_keys($f), ['op', 'args']) || !is_string($f['op'] ?? null)) return [null, 'sheet_formula'];
    if (!in_array($f['op'], APR_SHEET_OPS, true)) return [null, 'sheet_op'];
    $args = $f['args'] ?? null;
    if (!is_array($args) || !array_is_list($args)) return [null, 'sheet_formula'];
    $n = count($args);
    $ok = match ($f['op']) {
        'pct' => $n === 1,
        'sub', 'div', 'sum' => $n === 2,
        default => $n >= 2 && $n <= APR_SHEET_MAX_ARGS,
    };
    if (!$ok) return [null, 'sheet_formula'];
    if ($f['op'] === 'sum') {
        // טווח: משורה עד שורה, שתיהן מעל, והראשונה אינה אחרי האחרונה
        foreach ($args as $a) if (!apr_sheet_is_key($a) || !in_array($a, $above, true)) return [null, apr_sheet_is_key($a) || (is_string($a) && preg_match('/^r/i', $a) === 1) ? 'sheet_ref' : 'sheet_formula'];
        if (array_search($args[0], $above, true) > array_search($args[1], $above, true)) return [null, 'sheet_formula'];
        $nodes += 2;
        return [['op' => 'sum', 'args' => $args], null];
    }
    $out = [];
    foreach ($args as $a) {
        [$c, $err] = apr_sheet_formula($a, $above, $depth + 1, $nodes);
        if ($err !== null) return [null, $err];
        $out[] = $c;
    }
    return [$f['op'] === 'pct' ? ['pct' => $out[0]] : ['op' => $f['op'], 'args' => $out], null];
}

/** כל המפתחות שנוסחה (קנונית) מפנה אליהם. */
function apr_sheet_refs(mixed $f): array
{
    if (is_string($f)) return apr_sheet_is_key($f) ? [$f] : [];
    if (!is_array($f)) return [];
    $out = [];
    foreach (isset($f['pct']) ? [$f['pct']] : ($f['args'] ?? []) as $a) $out = [...$out, ...apr_sheet_refs($a)];
    return array_values(array_unique($out));
}

// ── החשבון ────────────────────────────────────────────────────

/**
 * שבר אחרי צעד חשבון: קצר — נשאר כפי שיצא מהמנוע של 15 (שמצמצם כשזול); ארוך מ-APR_SHEET_REDUCE_OVER — מצומצם עד הסוף, והעלות
 * יורדת מהתקציב. null כשהמונה או המכנה ארוכים מדי גם אחרי הצמצום. שבר שאינו מצומצם שווה בדיוק לשבר המצומצם — ההשוואה, העיגול
 * והתצוגה אינם תלויים בצורה.
 */
function apr_sheet_q(array $x, int &$budget): ?array
{
    [$n, $d] = $x;
    if ($n === '0') return ['0', '1'];
    $un = apr_n_abs($n);
    if ($d !== '1' && (strlen($un) > APR_SHEET_REDUCE_OVER || strlen($d) > APR_SHEET_REDUCE_OVER)) {
        $budget -= strlen($un) * strlen($d);
        $g = apr_nu_gcd($un, $d);
        if ($g !== '1') [$un, $d] = [apr_nu_divmod($un, $g)[0], apr_nu_divmod($d, $g)[0]];
    }
    if (strlen($un) > APR_SHEET_MAX_DIGITS || strlen($d) > APR_SHEET_MAX_DIGITS) return null;
    return [($n[0] === '-' ? '-' : '') . $un, $d];
}

/** מחרוזת עשרונית קנונית ("-0.15") ← שבר מדויק. */
function apr_sheet_const_q(string $s): array
{
    $neg = $s[0] === '-';
    $parts = explode('.', $neg ? substr($s, 1) : $s);
    $frac = $parts[1] ?? '';
    return apr_q(($neg ? '-' : '') . $parts[0] . $frac, '1' . str_repeat('0', strlen($frac)));
}

/**
 * מחשב את כל השורות. $rows — לפי הסדר: key, kind (input | calc | note), value (מיליוניות | null), unit, time_unit, formula (קנונית | null).
 * מחזיר [key => ['value' => שבר | null, 'reason' => קוד | null, 'ref' => המפתח של השורה שבגללה אין תוצאה | null, 'warnings' => [קודים]]]
 * לשורות קלט וחישוב. הסיבות: empty (קלט ריק) · missing (תלויה בקלט ריק) · div_zero · broken_ref (מפנה לשורה שאינה קיימת מעליה) ·
 * blocked (תלויה בשורה שאין לה תוצאה) · too_long. **קלט ריק אינו אפס** — כל מה שתלוי בו נשאר בלי תוצאה (16-B2).
 * too_long — גם כשתקציב הפעולות של הגיליון נגמר (APR_SHEET_BUDGET): השורה שבה נגמר וכל שורות החישוב שאחריה.
 * אזהרות (לא חוסמות): pct_over_100 · time_units (חודש ושנה באותו חיבור / חיסור) · pct_unwrapped (שורת % בכפל או בחילוק בלי pct).
 */
function apr_sheet_eval(array $rows): array
{
    $out = [];
    $meta = [];
    $order = [];
    $budget = APR_SHEET_BUDGET;
    foreach ($rows as $r) {
        $key = (string)$r['key'];
        if ($r['kind'] === 'note') continue;
        $meta[$key] = ['unit' => (string)($r['unit'] ?? ''), 'time_unit' => (string)($r['time_unit'] ?? 'none')];
        if ($r['kind'] === 'input') {
            if ($r['value'] === null) {
                $out[$key] = ['value' => null, 'reason' => 'empty', 'ref' => $key, 'warnings' => []];
            } else {
                $v = apr_q(apr_n((int)$r['value']), APR_SHEET_SCALE);
                $over = $meta[$key]['unit'] === '%' && apr_q_cmp($v, apr_q(100)) > 0;
                $out[$key] = ['value' => $v, 'reason' => null, 'ref' => null, 'warnings' => $over ? ['pct_over_100'] : []];
            }
        } else {
            $warn = [];
            // נוסחה שבורה היא עניין של מבנה, וקודמת לכל סיבה אחרת: הפניה לשורה שאינה קיימת מעל, או לשורה שהנוסחה שלה שבורה (§10.5)
            $brokenAt = null;
            foreach (apr_sheet_refs($r['formula']) as $k) {
                if (!isset($out[$k])) { $brokenAt = $k; break; }
                if ($out[$k]['reason'] === 'broken_ref') { $brokenAt = $out[$k]['ref']; break; }
            }
            [$v, $reason, $ref] = $brokenAt !== null ? [null, 'broken_ref', $brokenAt] : apr_sheet_node($r['formula'], $out, $meta, $order, $warn, $budget);
            $out[$key] = ['value' => $v, 'reason' => $reason, 'ref' => $ref, 'warnings' => array_values(array_unique($warn))];
        }
        $order[] = $key;
    }
    return $out;
}

/** צומת אחד בנוסחה ← [שבר | null, סיבה | null, מפתח | null]. */
function apr_sheet_node(mixed $f, array $vals, array $meta, array $order, array &$warn, int &$budget): array
{
    $budget -= APR_SHEET_NODE_COST;
    if ($budget < 0) return [null, 'too_long', null];
    if (is_string($f)) {
        if (!apr_sheet_is_key($f)) return [apr_sheet_const_q($f), null, null];
        if (!isset($vals[$f])) return [null, 'broken_ref', $f];
        $r = $vals[$f];
        if ($r['value'] !== null) return [$r['value'], null, null];
        // הסיבה נשמרת עד השורש: "לא ניתן לחשב: <שורה> חסרה" מצביע על הקלט הריק עצמו
        return match ($r['reason']) {
            'empty', 'missing' => [null, 'missing', $r['ref']],
            'broken_ref' => [null, 'broken_ref', $r['ref']],
            default => [null, 'blocked', $f],
        };
    }
    if (!is_array($f)) return [null, 'broken_ref', null];
    if (isset($f['pct'])) {
        [$v, $reason, $ref] = apr_sheet_node($f['pct'], $vals, $meta, $order, $warn, $budget);
        if ($v === null) return [null, $reason, $ref];
        return apr_sheet_done(apr_q_div($v, apr_q(100)), $budget);
    }
    $op = $f['op'] ?? '';
    $args = $f['args'] ?? [];
    if ($op === 'sum') {
        $from = array_search($args[0] ?? '', $order, true);
        $to = array_search($args[1] ?? '', $order, true);
        if ($from === false) return [null, 'broken_ref', $args[0] ?? null];
        if ($to === false) return [null, 'broken_ref', $args[1] ?? null];
        $args = array_slice($order, min($from, $to), abs($to - $from) + 1);
        $op = 'add';
        if (count($args) === 1) $args[] = '0';
    }
    // יחידות זמן שונות באותו חיבור / חיסור — אזהרה; אין המרה אוטומטית (§5)
    $refs = array_values(array_filter($args, 'apr_sheet_is_key'));
    if ($op === 'add' || $op === 'sub') {
        $units = array_unique(array_filter(array_map(fn($k) => $meta[$k]['time_unit'] ?? 'none', $refs), fn($u) => $u !== 'none'));
        if (count($units) > 1) $warn[] = 'time_units';
    } else {
        foreach ($refs as $k) if (($meta[$k]['unit'] ?? '') === '%') $warn[] = 'pct_unwrapped';
    }
    $acc = null;
    foreach ($args as $i => $a) {
        [$v, $reason, $ref] = apr_sheet_node($a, $vals, $meta, $order, $warn, $budget);
        if ($v === null) return [null, $reason, $ref];
        if ($i === 0) { $acc = $v; continue; }
        if ($op === 'div' && $v[0] === '0') return [null, 'div_zero', apr_sheet_is_key($a) ? $a : null];
        $acc = match ($op) {
            'add' => apr_q_add($acc, $v),
            'sub' => apr_q_sub($acc, $v),
            'mul' => apr_q_mul($acc, $v),
            'div' => apr_q_div($acc, $v),
            default => null,
        };
        if ($acc === null) return [null, 'broken_ref', null];
        $acc = apr_sheet_q($acc, $budget);
        if ($acc === null || $budget < 0) return [null, 'too_long', null];
    }
    return [$acc, null, null];
}

function apr_sheet_done(array $q, int &$budget): array
{
    $q = apr_sheet_q($q, $budget);
    return $q === null || $budget < 0 ? [null, 'too_long', null] : [$q, null, null];
}

/**
 * התוצאה של הגיליון: שורת החישוב האחרונה, בשקלים, **מעוגלת פעם אחת** ליחידת העיגול של הגיליון — באגורות (מחרוזת שלם).
 * $values — מה ש-apr_sheet_eval החזיר. אין שורת חישוב ← no_calc; נוסחה שבורה באחת השורות ← broken (הגיליון אינו נותן תוצאה סופית, §10.5);
 * אחרת הסיבה של השורה האחרונה. מחזיר ['key', 'agorot' | null, 'exact' (שבר, בשקלים) | null, 'reason', 'ref'].
 */
function apr_sheet_result(array $rows, array $values, string $rounding): array
{
    $last = null;
    foreach ($rows as $r) {
        if ($r['kind'] !== 'calc') continue;
        $last = (string)$r['key'];
        if (($values[$last]['reason'] ?? null) === 'broken_ref') return ['key' => $last, 'agorot' => null, 'exact' => null, 'reason' => 'broken', 'ref' => $last];
    }
    if ($last === null) return ['key' => null, 'agorot' => null, 'exact' => null, 'reason' => 'no_calc', 'ref' => null];
    $v = $values[$last];
    if ($v['value'] === null) return ['key' => $last, 'agorot' => null, 'exact' => null, 'reason' => $v['reason'], 'ref' => $v['ref']];
    return ['key' => $last, 'agorot' => apr_q_round(apr_q_mul($v['value'], apr_q(100)), APR_CALC_ROUNDING[$rounding]), 'exact' => $v['value'], 'reason' => null, 'ref' => null];
}

/**
 * ממוצע משוקלל של תוצאות הגישות — **חישוב עזר** (§6). $items: pid, value (אגורות, שלם או מחרוזת | null), weight (מיליוניות | null).
 * מחזיר ['value' => שבר (אגורות) | null, 'block' => null | no_result | weight_missing | weights_zero, 'ref' => pid | null, 'shares' => [pid => שבר]].
 * פחות משתי גישות, או שאף אחת אינה נושאת משקל ← אין מה לחשב (value null, block null). גישה אחת בלי תוצאה ← הממוצע אינו מוצג (§10.11).
 */
function apr_sheet_weighted(array $items): array
{
    $none = ['value' => null, 'block' => null, 'ref' => null, 'shares' => []];
    if (count($items) < 2 || !array_filter($items, fn($it) => $it['weight'] !== null)) return $none;
    $total = '0';
    foreach ($items as $it) {
        if ($it['weight'] === null) return ['block' => 'weight_missing', 'ref' => $it['pid']] + $none;
        if ($it['value'] === null) return ['block' => 'no_result', 'ref' => $it['pid']] + $none;
        $total = apr_n_add($total, apr_n((int)$it['weight']));
    }
    if ($total === '0') return ['block' => 'weights_zero'] + $none;
    $acc = apr_q(0);
    $shares = [];
    foreach ($items as $it) {
        $w = apr_q(apr_n((int)$it['weight']));
        $acc = apr_q_add($acc, apr_q_mul(apr_q(apr_n($it['value'])), $w));
        $shares[$it['pid']] = apr_q_div($w, [$total, '1']);
    }
    return ['value' => apr_q_div($acc, [$total, '1']), 'block' => null, 'ref' => null, 'shares' => $shares];
}

/**
 * שבר ← מחרוזת עשרונית לתצוגה: עד 6 ספרות אחרי הנקודה, בלי אפסים מיותרים. exact = false כשהערך המדויק אינו נגמר ב-6 ספרות —
 * אז המחרוזת מעוגלת (חצי כלפי מעלה), והמסך מסמן "≈". לתצוגה בלבד; החישוב ממשיך בשבר המדויק.
 */
function apr_sheet_dec(array $q): array
{
    $s = apr_q_decimal($q, 6);
    $exact = apr_q_cmp(apr_sheet_const_q($s), $q) === 0;
    if (str_contains($s, '.')) $s = rtrim(rtrim($s, '0'), '.');
    if ($s === '-0') $s = '0';
    return ['dec' => $s, 'exact' => $exact];
}
