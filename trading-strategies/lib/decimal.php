<?php
/**
 * מספרים עשרוניים מדויקים כמחרוזות — בלי float (אפיון 3.3).
 *
 * בשרת אין bcmath ואין gmp, ולכן החשבון כאן ידני: מספר = סימן + ספרות שלמות (בלי נקודה) + קנה מידה
 * (כמה ספרות אחרי הנקודה). חיבור, חיסור וכפל — מדויקים לגמרי; חילוק — לקנה מידה שמבקשים, עיגול
 * חצי-למעלה (מהאפס והלאה). המספרים כאן קצרים (עשרות ספרות), ולכן האלגוריתמים הפשוטים מספיקים.
 *
 * צורה קנונית: "-12.5", "0", "0.00012345" — בלי אפסים מיותרים בסוף, בלי "+", בלי "-0".
 */

declare(strict_types=1);

const TS_DEC_MAX_INT = 18;    // ספרות לפני הנקודה בקלט
const TS_DEC_MAX_SCALE = 10;  // ספרות אחרי הנקודה בקלט (קריפטו — 8, מט"ח — 5)

/** קלט מהמשתמש → צורה קנונית, או null אם אינו מספר תקין. מקבל גם פסיק עשרוני. */
function dec_parse(mixed $v, int $maxScale = TS_DEC_MAX_SCALE): ?string
{
    if (is_int($v)) $v = (string)$v;
    if (!is_string($v)) return null;               // float נדחה בכוונה — הוא כבר איבד דיוק
    $v = str_replace([',', ' ', "\u{2212}"], ['.', '', '-'], trim($v));
    $frac = $maxScale > 0 ? '(?:\.\d{1,' . $maxScale . '})?' : '';   // {1,0} אינו כמת תקין — מספר שלם בלי חלק עשרוני
    if (!preg_match('/^-?\d{1,' . TS_DEC_MAX_INT . '}' . $frac . '$/', $v)) return null;
    return dec_norm($v);
}

/** [סימן (1/-1), ספרות, קנה מידה] */
function dec_split(string $a): array
{
    $neg = str_starts_with($a, '-');
    if ($neg) $a = substr($a, 1);
    $dot = strpos($a, '.');
    $int = $dot === false ? $a : substr($a, 0, $dot);
    $frac = $dot === false ? '' : substr($a, $dot + 1);
    $digits = ltrim($int . $frac, '0');
    return [$neg ? -1 : 1, $digits === '' ? '0' : $digits, strlen($frac)];
}

function dec_join(int $sign, string $digits, int $scale): string
{
    $digits = ltrim($digits, '0');
    if ($digits === '') return '0';
    if ($scale > 0) {
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $int = substr($digits, 0, -$scale);
        $frac = rtrim(substr($digits, -$scale), '0');
        $s = $frac === '' ? $int : "$int.$frac";
    } else {
        $s = $digits;
    }
    return ($sign < 0 ? '-' : '') . $s;
}

function dec_norm(string $a): string
{
    [$s, $d, $k] = dec_split($a);
    return dec_join($s, $d, $k);
}

// ── מספרים שלמים חיוביים כמחרוזות ───────────────────────

function big_cmp(string $a, string $b): int
{
    $a = ltrim($a, '0'); $b = ltrim($b, '0');
    if (strlen($a) !== strlen($b)) return strlen($a) <=> strlen($b);
    return strcmp($a, $b) <=> 0;
}

function big_add(string $a, string $b): string
{
    $out = ''; $carry = 0;
    for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
        $s = ($i >= 0 ? (int)$a[$i] : 0) + ($j >= 0 ? (int)$b[$j] : 0) + $carry;
        $out .= (string)($s % 10);
        $carry = intdiv($s, 10);
    }
    return ltrim(strrev($out), '0') ?: '0';
}

/** a − b, כש-a ≥ b */
function big_sub(string $a, string $b): string
{
    $out = ''; $borrow = 0;
    for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
        $s = (int)$a[$i] - ($j >= 0 ? (int)$b[$j] : 0) - $borrow;
        $borrow = $s < 0 ? 1 : 0;
        $out .= (string)($s + 10 * $borrow);
    }
    return ltrim(strrev($out), '0') ?: '0';
}

function big_mul(string $a, string $b): string
{
    if ($a === '0' || $b === '0') return '0';
    $la = strlen($a); $lb = strlen($b);
    $r = array_fill(0, $la + $lb, 0);
    for ($i = $la - 1; $i >= 0; $i--) {
        for ($j = $lb - 1; $j >= 0; $j--) {
            $p = (int)$a[$i] * (int)$b[$j] + $r[$i + $j + 1];
            $r[$i + $j + 1] = $p % 10;
            $r[$i + $j] += intdiv($p, 10);
        }
    }
    return ltrim(implode('', $r), '0') ?: '0';
}

/** [מנה, שארית] — חילוק ארוך */
function big_divmod(string $a, string $b): array
{
    if ($b === '0') throw new DivisionByZeroError('חילוק באפס');
    $q = ''; $rem = '0';
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $rem = ltrim($rem . $a[$i], '0') ?: '0';
        $d = 0;
        while (big_cmp($rem, $b) >= 0) { $rem = big_sub($rem, $b); $d++; }
        $q .= (string)$d;
    }
    return [ltrim($q, '0') ?: '0', $rem];
}

// ── החשבון העשרוני ──────────────────────────────────────

/** שני מספרים באותו קנה מידה: [סימן, ספרות] ×2 וקנה המידה */
function dec_align(string $a, string $b): array
{
    [$sa, $da, $ka] = dec_split($a);
    [$sb, $db, $kb] = dec_split($b);
    $k = max($ka, $kb);
    return [$sa, $da . str_repeat('0', $k - $ka), $sb, $db . str_repeat('0', $k - $kb), $k];
}

function dec_add(string $a, string $b): string
{
    [$sa, $da, $sb, $db, $k] = dec_align($a, $b);
    if ($sa === $sb) return dec_join($sa, big_add($da, $db), $k);
    $c = big_cmp($da, $db);
    if ($c === 0) return '0';
    return $c > 0 ? dec_join($sa, big_sub($da, $db), $k) : dec_join($sb, big_sub($db, $da), $k);
}

function dec_neg(string $a): string
{
    $a = dec_norm($a);
    return $a === '0' ? '0' : (str_starts_with($a, '-') ? substr($a, 1) : "-$a");
}

function dec_sub(string $a, string $b): string { return dec_add($a, dec_neg($b)); }

function dec_mul(string $a, string $b): string
{
    [$sa, $da, $ka] = dec_split($a);
    [$sb, $db, $kb] = dec_split($b);
    return dec_join($sa * $sb, big_mul($da, $db), $ka + $kb);
}

function dec_cmp(string $a, string $b): int
{
    $d = dec_sub($a, $b);
    return $d === '0' ? 0 : (str_starts_with($d, '-') ? -1 : 1);
}

function dec_sign(string $a): int { return dec_cmp($a, '0'); }
function dec_abs(string $a): string { return ltrim(dec_norm($a), '-'); }

/** a ÷ b, מעוגל ל-$scale ספרות (חצי-למעלה, הרחק מאפס) */
function dec_div(string $a, string $b, int $scale = 10): string
{
    [$sa, $da, $ka] = dec_split($a);
    [$sb, $db, $kb] = dec_split($b);
    if ($db === '0') throw new DivisionByZeroError('חילוק באפס');
    // a/b = (A·10^kb) / (B·10^ka); ספרה אחת נוספת לעיגול
    $num = $da . str_repeat('0', $kb + $scale + 1);
    $den = $db . str_repeat('0', $ka);
    [$q] = big_divmod($num, $den);
    $last = (int)substr($q, -1);
    $q = strlen($q) > 1 ? substr($q, 0, -1) : '0';
    if ($last >= 5) $q = big_add($q, '1');
    return dec_join($sa * $sb, $q, $scale);
}

/** עיגול ל-$scale ספרות (חצי-למעלה, הרחק מאפס) */
function dec_round(string $a, int $scale): string
{
    [$s, $d, $k] = dec_split($a);
    if ($k <= $scale) return dec_norm($a);
    $cut = $k - $scale;
    $d = str_pad($d, $cut + 1, '0', STR_PAD_LEFT);
    $keep = substr($d, 0, -$cut);
    if ((int)$d[strlen($d) - $cut] >= 5) $keep = big_add($keep, '1');
    return dec_join($s, $keep, $scale);
}

/** להצגה: בדיוק $scale ספרות אחרי הנקודה ("12.50") */
function dec_fixed(string $a, int $scale): string
{
    $r = dec_round($a, $scale);
    if ($scale === 0) return $r;
    [$s, $d, $k] = dec_split($r);
    $d .= str_repeat('0', $scale - $k);
    $d = str_pad($d, $scale + 1, '0', STR_PAD_LEFT);
    return ($s < 0 && $r !== '0' ? '-' : '') . substr($d, 0, -$scale) . '.' . substr($d, -$scale);
}

function dec_sum(array $xs): string
{
    $t = '0';
    foreach ($xs as $x) $t = dec_add($t, $x);
    return $t;
}
