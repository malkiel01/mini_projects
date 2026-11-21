<?php
/**
 * פרוסה 15 — מנוע החישוב של עסקאות ההשוואה (docs/spec/15-comparables.md §6). קוד דטרמיניסטי בלבד: אין כאן מסד, אין שעון,
 * אין קריאה למודל שפה, ואין נקודה צפה — אותם קלטים ← אותה תוצאה, תמיד. tests/p-comps.test.php בודק את זה סטטית (15-A6, 15-C1).
 *
 * חשבון מדויק (§6.1). האפיון מזכיר bcmath; ההרחבה אינה מובטחת בשרת, ולכן המנוע נושא חשבון משלו:
 *   מספר שלם  — מחרוזת עשרונית בסימן ("-1250"), בכל אורך. apr_n_*.
 *   מספר רציונלי — [מונה, מכנה], מכנה חיובי. apr_q_*. כל שלבי הביניים נשמרים כשבר מדויק — לא "6 ספרות", אלא בלי שארית כלל.
 * עיגול נעשה פעם אחת, בסוף (apr_q_round), בשיטת "חצי כלפי מעלה": בדיוק חצי מתעגל לכיוון +∞ (2.5 ← 3; ‎-2.5 ← ‎-2).
 *
 * יחידות הקלט — כולן שלמים: מחיר וסכום התאמה באגורות; שטח במאיות מ"ר; אחוז ומשקל במיליוניות (5% = 5000000).
 * בקבוצה שמשווה למ"ר, הערכים הם אגורות למ"ר.
 */

declare(strict_types=1);

// ── שלמים בכל אורך ────────────────────────────────────────────

/** שלם או מחרוזת עשרונית ← הצורה הקנונית: בלי אפסים מובילים, ובלי "-0". */
function apr_n(int|string $v): string
{
    $s = (string)$v;
    if (preg_match('/^(-?)0*(\d+)\z/', $s, $m) !== 1) throw new InvalidArgumentException('apr_n: not an integer');
    return $m[2] === '0' ? '0' : $m[1] . $m[2];
}

/** השוואת גדלים (בלי סימן): -1 / 0 / 1. */
function apr_nu_cmp(string $a, string $b): int
{
    return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
}

function apr_nu_add(string $a, string $b): string
{
    if (strlen($a) <= 18 && strlen($b) <= 18) return (string)((int)$a + (int)$b);       // שניהם קטנים מ-10^18: הסכום בתוך שלם של 64 סיביות
    $out = '';
    $carry = 0;
    for ($i = strlen($a), $j = strlen($b); $i > 0 || $j > 0 || $carry > 0; $i -= 9, $j -= 9) {
        $x = $i > 0 ? (int)substr($a, max(0, $i - 9), min(9, $i)) : 0;
        $y = $j > 0 ? (int)substr($b, max(0, $j - 9), min(9, $j)) : 0;
        $s = $x + $y + $carry;
        $carry = intdiv($s, 1000000000);
        $out = str_pad((string)($s % 1000000000), 9, '0', STR_PAD_LEFT) . $out;
    }
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

/** a − b, כש-a ≥ b. */
function apr_nu_sub(string $a, string $b): string
{
    if (strlen($a) <= 18) return (string)((int)$a - (int)$b);
    $out = '';
    $borrow = 0;
    for ($i = strlen($a), $j = strlen($b); $i > 0; $i -= 9, $j -= 9) {
        $x = (int)substr($a, max(0, $i - 9), min(9, $i));
        $y = $j > 0 ? (int)substr($b, max(0, $j - 9), min(9, $j)) : 0;
        $d = $x - $y - $borrow;
        $borrow = $d < 0 ? 1 : 0;
        if ($d < 0) $d += 1000000000;
        $out = str_pad((string)$d, 9, '0', STR_PAD_LEFT) . $out;
    }
    if ($borrow !== 0) throw new LogicException('apr_nu_sub: a < b');
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

/** מחרוזת ספרות ← חוליות של 7 ספרות, מהפחותה. */
function apr_nu_limbs(string $a): array
{
    $pad = intdiv(strlen($a) + 6, 7) * 7;
    return array_map('intval', array_reverse(str_split(str_pad($a, $pad, '0', STR_PAD_LEFT), 7)));
}

function apr_nu_mul(string $a, string $b): string
{
    if ($a === '0' || $b === '0') return '0';
    if (strlen($a) + strlen($b) <= 18) return (string)((int)$a * (int)$b);              // המכפלה קטנה מ-10^18
    $x = apr_nu_limbs($a);
    $y = apr_nu_limbs($b);
    $res = array_fill(0, count($x) + count($y), 0);
    foreach ($x as $i => $xi) {
        $carry = 0;
        foreach ($y as $j => $yj) {
            $t = $res[$i + $j] + $xi * $yj + $carry;                                   // פחות מ-10^7 + 10^14 + 10^8
            $res[$i + $j] = $t % 10000000;
            $carry = intdiv($t, 10000000);
        }
        $res[$i + count($y)] = $carry;
    }
    $out = '';
    foreach ($res as $limb) $out = str_pad((string)$limb, 7, '0', STR_PAD_LEFT) . $out;
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

/** [מנה, שארית] של a ÷ b (בלי סימן). b אינו אפס — מי שקורא בודק לפני. */
function apr_nu_divmod(string $a, string $b): array
{
    if ($b === '0') throw new DivisionByZeroError('apr_nu_divmod');
    if (strlen($a) <= 18 && strlen($b) <= 18) return [(string)intdiv((int)$a, (int)$b), (string)((int)$a % (int)$b)];
    if (apr_nu_cmp($a, $b) < 0) return ['0', $a];
    $q = '';
    if (strlen($b) <= 17) {                                                            // מחלק קטן: חילוק ארוך בשלמים רגילים
        $bi = (int)$b;
        $r = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $r = $r * 10 + (int)$a[$i];                                                // r < b < 10^17
            $q .= (string)intdiv($r, $bi);
            $r %= $bi;
        }
        $q = ltrim($q, '0');
        return [$q === '' ? '0' : $q, (string)$r];
    }
    $r = '0';
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $r = $r === '0' ? $a[$i] : $r . $a[$i];
        $d = 0;
        while (apr_nu_cmp($r, $b) >= 0) {
            $r = apr_nu_sub($r, $b);
            $d++;
        }
        $q .= (string)$d;
    }
    $q = ltrim($q, '0');
    return [$q === '' ? '0' : $q, $r];
}

/** המחלק המשותף הגדול ביותר (בלי סימן). */
function apr_nu_gcd(string $a, string $b): string
{
    while ($b !== '0') [$a, $b] = [$b, apr_nu_divmod($a, $b)[1]];
    return $a;
}

function apr_n_neg(string $a): string
{
    return $a === '0' ? '0' : ($a[0] === '-' ? substr($a, 1) : '-' . $a);
}

function apr_n_abs(string $a): string
{
    return $a[0] === '-' ? substr($a, 1) : $a;
}

/** -1 / 0 / 1. */
function apr_n_sign(string $a): int
{
    return $a === '0' ? 0 : ($a[0] === '-' ? -1 : 1);
}

function apr_n_cmp(string $a, string $b): int
{
    $sa = apr_n_sign($a);
    $sb = apr_n_sign($b);
    if ($sa !== $sb) return $sa <=> $sb;
    $c = apr_nu_cmp(apr_n_abs($a), apr_n_abs($b));
    return $sa < 0 ? -$c : $c;
}

function apr_n_add(string $a, string $b): string
{
    $sa = apr_n_sign($a);
    $sb = apr_n_sign($b);
    if ($sa === 0) return $b;
    if ($sb === 0) return $a;
    $ua = apr_n_abs($a);
    $ub = apr_n_abs($b);
    if ($sa === $sb) return ($sa < 0 ? '-' : '') . apr_nu_add($ua, $ub);
    $c = apr_nu_cmp($ua, $ub);
    if ($c === 0) return '0';
    return $c > 0 ? ($sa < 0 ? '-' : '') . apr_nu_sub($ua, $ub) : ($sb < 0 ? '-' : '') . apr_nu_sub($ub, $ua);
}

function apr_n_sub(string $a, string $b): string
{
    return apr_n_add($a, apr_n_neg($b));
}

function apr_n_mul(string $a, string $b): string
{
    $m = apr_nu_mul(apr_n_abs($a), apr_n_abs($b));
    return $m !== '0' && apr_n_sign($a) !== apr_n_sign($b) ? '-' . $m : $m;
}

/** ⌊a ÷ b⌋ — עיגול כלפי מטה (לכיוון ‎-∞), b חיובי. */
function apr_n_floordiv(string $a, string $b): string
{
    if (apr_n_sign($b) <= 0) throw new DivisionByZeroError('apr_n_floordiv');
    [$q, $r] = apr_nu_divmod(apr_n_abs($a), $b);
    if (apr_n_sign($a) >= 0) return $q;
    return apr_n_neg($r === '0' ? $q : apr_nu_add($q, '1'));
}

// ── מספרים רציונליים ──────────────────────────────────────────

/** n ÷ d כשבר מדויק [מונה, מכנה], מכנה חיובי. d = 0 ← DivisionByZeroError (המנוע אינו מגיע לכך: שטח 0 נעצר קודם). */
function apr_q(int|string $n, int|string $d = 1): array
{
    $n = apr_n($n);
    $d = apr_n($d);
    if ($d === '0') throw new DivisionByZeroError('apr_q');
    if ($d[0] === '-') [$n, $d] = [apr_n_neg($n), substr($d, 1)];
    return apr_q_norm($n, $d);
}

/** צמצום — רק כשהוא זול (אחד הצדדים קצר). שבר שלא צומצם נשאר נכון; הוא רק ארוך יותר. */
function apr_q_norm(string $n, string $d): array
{
    if ($n === '0') return ['0', '1'];
    if ($d === '1') return [$n, $d];
    $un = apr_n_abs($n);
    if (strlen($d) > 17 && strlen($un) > 17) return [$n, $d];
    $g = apr_nu_gcd($un, $d);
    if ($g === '1') return [$n, $d];
    return [($n[0] === '-' ? '-' : '') . apr_nu_divmod($un, $g)[0], apr_nu_divmod($d, $g)[0]];
}

function apr_q_add(array $x, array $y): array
{
    if ($x[1] === $y[1]) return apr_q_norm(apr_n_add($x[0], $y[0]), $x[1]);
    // מכנה משותף קטן ככל האפשר, כשהחישוב זול: כך סכום של מאות עסקאות אינו מנפח את המכנה
    $g = strlen($x[1]) <= 17 || strlen($y[1]) <= 17 ? apr_nu_gcd($x[1], $y[1]) : '1';
    $dx = $g === '1' ? $x[1] : apr_nu_divmod($x[1], $g)[0];
    $dy = $g === '1' ? $y[1] : apr_nu_divmod($y[1], $g)[0];
    return apr_q_norm(apr_n_add(apr_n_mul($x[0], $dy), apr_n_mul($y[0], $dx)), apr_nu_mul($dx, $y[1]));
}

function apr_q_neg(array $x): array
{
    return [apr_n_neg($x[0]), $x[1]];
}

function apr_q_sub(array $x, array $y): array
{
    return apr_q_add($x, apr_q_neg($y));
}

function apr_q_mul(array $x, array $y): array
{
    // צמצום צולב לפני הכפל: (a/b)·(c/d) — a מול d, c מול b
    $a = apr_q_norm($x[0], $y[1]);
    $c = apr_q_norm($y[0], $x[1]);
    return apr_q_norm(apr_n_mul($a[0], $c[0]), apr_nu_mul($c[1], $a[1]));
}

/** x ÷ y. y = 0 ← DivisionByZeroError; מי שקורא בודק לפני (סכום משקולות אפס נחסם לפני החילוק). */
function apr_q_div(array $x, array $y): array
{
    if ($y[0] === '0') throw new DivisionByZeroError('apr_q_div');
    $inv = $y[0][0] === '-' ? [apr_n_neg($y[1]), substr($y[0], 1)] : [$y[1], $y[0]];
    return apr_q_mul($x, $inv);
}

function apr_q_cmp(array $x, array $y): int
{
    return apr_n_cmp(apr_n_mul($x[0], $y[1]), apr_n_mul($y[0], $x[1]));
}

function apr_q_sign(array $x): int
{
    return apr_n_sign($x[0]);
}

/**
 * העיגול היחיד במנוע: לכפולה הקרובה של $unit (שלם חיובי), חצי כלפי מעלה. מחזיר שלם (מחרוזת).
 * ⌊(2n + d·unit) ÷ (2·d·unit)⌋ · unit.
 */
function apr_q_round(array $x, int|string $unit = 1): string
{
    $u = apr_n($unit);
    if (apr_n_sign($u) <= 0) throw new InvalidArgumentException('apr_q_round: unit');
    $du = apr_nu_mul($x[1], $u);
    return apr_n_mul(apr_n_floordiv(apr_n_add(apr_n_mul('2', $x[0]), $du), apr_nu_mul('2', $du)), $u);
}

/** שבר ← מחרוזת עשרונית עם $decimals ספרות אחרי הנקודה (מעוגלת פעם אחת, חצי כלפי מעלה). לתצוגה בלבד. */
function apr_q_decimal(array $x, int $decimals): string
{
    $scale = '1' . str_repeat('0', $decimals);
    $n = apr_q_round(apr_q_mul($x, [$scale, '1']));
    $neg = $n[0] === '-';
    $u = str_pad(apr_n_abs($n), $decimals + 1, '0', STR_PAD_LEFT);
    $int = substr($u, 0, strlen($u) - $decimals);
    return ($neg ? '-' : '') . $int . ($decimals > 0 ? '.' . substr($u, -$decimals) : '');
}

// ── החישוב ────────────────────────────────────────────────────

/** יחידת העיגול באגורות, לפי comp_sets.rounding. none — לאגורה (כסף אינו נשמר בדיוק גבוה ממנה). */
const APR_CALC_ROUNDING = ['none' => 1, '1' => 100, '100' => 10000, '1000' => 100000, '10000' => 1000000];
/** למה אין לעסקה בסיס לחישוב. כל אחת מהן = "אין מספר" (§6.3), לעולם לא אפס. */
const APR_CALC_REASONS = ['price_missing', 'currency', 'area_missing', 'area_invalid', 'area_kind_unknown', 'area_kind_mismatch'];

/**
 * הבסיס של עסקה בקבוצה: המחיר (total), או המחיר למ"ר (per_sqm). מחזיר [שבר | null, סיבה | null].
 * $comp: price (אגורות | null), currency, area (מאיות | null), area_kind. $set: unit_basis, area_kind.
 * מחיר למ"ר מחושב רק אם השטח ידוע וגדול מאפס, סוגו ידוע, והוא זהה לסוג שנבחר בקבוצה (§6.3, 15-A2). אין המרה בין סוגי שטח.
 */
function apr_calc_base(array $comp, array $set): array
{
    if (($comp['price'] ?? null) === null) return [null, 'price_missing'];
    if (($comp['currency'] ?? 'ILS') !== 'ILS') return [null, 'currency'];
    if ($set['unit_basis'] === 'total') return [apr_q((int)$comp['price']), null];
    if (($comp['area'] ?? null) === null) return [null, 'area_missing'];
    if ((int)$comp['area'] <= 0) return [null, 'area_invalid'];
    $kind = (string)($comp['area_kind'] ?? 'unknown');
    if ($kind === 'unknown' || $kind === '') return [null, 'area_kind_unknown'];
    if ($kind !== (string)($set['area_kind'] ?? '')) return [null, 'area_kind_mismatch'];
    return [apr_q(apr_n_mul(apr_n((int)$comp['price']), '100'), (int)$comp['area']), null];
}

/**
 * עסקה אחת: הבסיס, ההתאמות לפי הסדר, והתוצאה המותאמת (§6.2). $adjs — כבר ממוינות לפי סדר החישוב; התאמה שאינה חלה על בסיס
 * הקבוצה (applies_to) מדולגת. מחזיר: ok, reason, base, steps[{pid, label, kind, sign, basis, value, delta, running}], adjusted,
 * nonpositive (תוצאה אפס או שלילית — אזהרה, לא חסימה; §10.6). כל הערכים — שברים מדויקים.
 */
function apr_calc_comp(array $comp, array $adjs, array $set): array
{
    [$base, $reason] = apr_calc_base($comp, $set);
    if ($base === null) return ['ok' => false, 'reason' => $reason, 'base' => null, 'steps' => [], 'adjusted' => null, 'nonpositive' => false];
    $running = $base;
    $steps = [];
    foreach ($adjs as $a) {
        if ($a['applies_to'] !== 'both' && $a['applies_to'] !== $set['unit_basis']) continue;
        if ($a['kind'] === 'amount') {
            $delta = apr_q((int)$a['value']);
        } else {
            // אחוז במיליוניות: ערך ÷ 10^6 אחוזים, ועוד ÷ 100 — יחד ÷ 10^8
            $delta = apr_q_mul($a['basis'] === 'running' ? $running : $base, apr_q((int)$a['value'], 100000000));
        }
        if ($a['sign'] === 'minus') $delta = apr_q_neg($delta);
        $running = apr_q_add($running, $delta);
        $steps[] = ['pid' => $a['pid'] ?? null, 'label' => $a['label'], 'kind' => $a['kind'], 'sign' => $a['sign'], 'basis' => $a['basis'], 'value' => (int)$a['value'],
            'delta' => $delta, 'running' => $running];
    }
    return ['ok' => true, 'reason' => null, 'base' => $base, 'steps' => $steps, 'adjusted' => $running, 'nonpositive' => apr_q_sign($running) <= 0];
}

/** החציון של רשימת שברים (לא ריקה): האמצעי, או ממוצע שני האמצעיים. */
function apr_calc_median(array $values): array
{
    usort($values, 'apr_q_cmp');
    $n = count($values);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $values[$mid] : apr_q_div(apr_q_add($values[$mid - 1], $values[$mid]), apr_q(2));
}

/**
 * הסיכום של קבוצה (§6.4). $items — העסקאות שנבחרו (status = selected), לכל אחת: pid, calc (התוצאה של apr_calc_comp),
 * weight (מיליוניות | null), deal_date (YYYY-MM-DD | null), missing (כמה שדות חסרים), dup (חשד לכפילות).
 * $set: unit_basis, method, rounding. $smallUnder — מתחת לכמה עסקאות "מדגם קטן" (ברירת המחדל 3, מהפרופיל).
 *
 * מחזיר: selected, counted, skipped[{pid, reason}], min, max, mean, median, weighted, weights[{pid, weight, share}], weights_block,
 * result (לפי method; null ב-range_only / manual, או כשאי אפשר), result_note, rounded (שלם, אחרי העיגול היחיד), unrounded
 * (שלם, לאגורה), rounding, date_from, date_to, outliers[pid], caveats[{code, n}]. ערכי הכסף — שברים מדויקים, חוץ מ-rounded / unrounded.
 * אפס עסקאות ← הכול null ("לא נבחרו עסקאות" — בלי אפסים). עסקה אחת ← אין ממוצע ואין חציון ("נדרשות לפחות שתיים").
 */
function apr_calc_summary(array $items, array $set, int $smallUnder = 3): array
{
    $in = [];
    $skipped = [];
    foreach ($items as $it) {
        if ($it['calc']['ok']) $in[] = $it;
        else $skipped[] = ['pid' => $it['pid'], 'reason' => $it['calc']['reason']];
    }
    $n = count($in);
    $out = ['selected' => count($items), 'counted' => $n, 'skipped' => $skipped, 'min' => null, 'max' => null, 'mean' => null, 'median' => null, 'weighted' => null,
        'weights' => [], 'weights_block' => null, 'result' => null, 'result_note' => null, 'rounded' => null, 'unrounded' => null, 'rounding' => $set['rounding'],
        'date_from' => null, 'date_to' => null, 'outliers' => [], 'caveats' => []];
    $caveat = function (string $code, int $count) use (&$out): void {
        if ($count > 0) $out['caveats'][] = ['code' => $code, 'n' => $count];
    };
    if (count($items) > 0 && $n < $smallUnder) $out['caveats'][] = ['code' => 'small_sample', 'n' => $n];
    $caveat('not_comparable', count($skipped));
    $caveat('missing_data', count(array_filter($items, fn($it) => (int)($it['missing'] ?? 0) > 0)));
    $caveat('dup_suspect', count(array_filter($items, fn($it) => !empty($it['dup']))));
    if ($n === 0) {
        if ($set['method'] !== 'range_only' && $set['method'] !== 'manual') $out['result_note'] = count($items) === 0 ? 'none_selected' : 'none_comparable';
        return $out;
    }
    $values = array_map(fn($it) => $it['calc']['adjusted'], $in);
    $sorted = $values;
    usort($sorted, 'apr_q_cmp');
    $out['min'] = $sorted[0];
    $out['max'] = $sorted[$n - 1];
    $dates = array_values(array_filter(array_map(fn($it) => $it['deal_date'] ?? null, $in), 'is_string'));
    sort($dates);
    if ($dates) [$out['date_from'], $out['date_to']] = [$dates[0], $dates[count($dates) - 1]];
    $caveat('nonpositive', count(array_filter($in, fn($it) => $it['calc']['nonpositive'])));
    if ($n >= 2) {
        $sum = apr_q(0);
        foreach ($values as $v) $sum = apr_q_add($sum, $v);
        $out['mean'] = apr_q_div($sum, apr_q($n));
        $out['median'] = apr_calc_median($values);
    }
    // חריגה (§4.4): בסיס שרחוק מהחציון של הקבוצה פי 2 ומעלה — התרעה בלבד, אינה מוציאה מהחישוב
    if ($n >= 3) {
        $m = apr_calc_median(array_map(fn($it) => $it['calc']['base'], $in));
        if (apr_q_sign($m) > 0) {
            $two = apr_q(2);
            foreach ($in as $it) {
                $b = $it['calc']['base'];
                if (apr_q_cmp($b, apr_q_mul($m, $two)) >= 0 || apr_q_cmp(apr_q_mul($b, $two), $m) <= 0) $out['outliers'][] = $it['pid'];
            }
        }
        $caveat('outliers', count($out['outliers']));
    }
    // משקולות (§6.4): הסכום אינו חייב להיות 100 — מנרמלים. עסקה בלי משקל כשלאחרות יש, או סכום אפס ← נחסם, עם הסבר
    $withWeight = array_filter($in, fn($it) => $it['weight'] !== null);
    if ($withWeight) {
        $total = '0';
        foreach ($withWeight as $it) $total = apr_n_add($total, apr_n((int)$it['weight']));
        if (count($withWeight) < $n) $out['weights_block'] = 'weight_missing';
        elseif ($total === '0') $out['weights_block'] = 'weights_zero';
        else {
            $acc = apr_q(0);
            foreach ($in as $it) {
                $w = apr_q((int)$it['weight']);
                $acc = apr_q_add($acc, apr_q_mul($it['calc']['adjusted'], $w));
                $out['weights'][] = ['pid' => $it['pid'], 'weight' => (int)$it['weight'], 'share' => apr_q_div($w, [$total, '1'])];
            }
            if ($n >= 2) $out['weighted'] = apr_q_div($acc, [$total, '1']);
        }
    } elseif ($set['method'] === 'weighted') {
        $out['weights_block'] = 'weight_missing';
    }
    switch ($set['method']) {
        case 'mean':
            $out['result'] = $out['mean'];
            break;
        case 'median':
            $out['result'] = $out['median'];
            break;
        case 'weighted':
            $out['result'] = $out['weights_block'] === null ? $out['weighted'] : null;
            break;
    }
    if ($out['result'] !== null) {
        $out['rounded'] = apr_q_round($out['result'], APR_CALC_ROUNDING[$set['rounding']]);      // העיגול היחיד — על התוצאה המדויקת (15-A7)
        $out['unrounded'] = apr_q_round($out['result'], 1);
    } elseif ($set['method'] !== 'range_only' && $set['method'] !== 'manual') {
        $out['result_note'] = $out['weights_block'] ?? ($n < 2 ? 'need_two' : null);
    }
    return $out;
}

/** מסקנה למ"ר × השטח הנישום — חישוב עזר (§6.5). $perSqm באגורות למ"ר, $area במאיות מ"ר. מחזיר שבר (אגורות). */
function apr_calc_subject_total(int $perSqm, int $area): array
{
    return apr_q(apr_n_mul(apr_n($perSqm), apr_n($area)), 100);
}
