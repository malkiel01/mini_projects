<?php
/**
 * אינדיקטורים במנוע — float (double), לא כסף (spec-stage-a עיקרון 6). אותן נוסחאות כמו בגרף (assets/js/market.js):
 * SMA, EMA (זרע = ממוצע פשוט של n הראשונים), RSI ו-ATR של Wilder. null — לפני שיש מספיק נרות.
 * פונקציות טהורות על מערכים. השיטות עצמן (lib/methods/) מחשבות בזרימה, נר אחרי נר, באותו סדר פעולות —
 * כך שהערך זהה, ובדיקה (tests/backtest.php) משווה בטולרנס 1e-9.
 */

declare(strict_types=1);

/** @param list<float> $v */
function ts_sma(array $v, int $n): array
{
    $out = [];
    $s = 0.0;
    foreach ($v as $i => $x) {
        $s += $x;
        if ($i >= $n) $s -= $v[$i - $n];
        $out[] = $i >= $n - 1 ? $s / $n : null;
    }
    return $out;
}

function ts_ema(array $v, int $n): array
{
    $out = [];
    $k = 2 / ($n + 1);
    $e = null;
    foreach ($v as $i => $x) {
        if ($i < $n - 1) { $out[] = null; continue; }
        $e = $e === null ? array_sum(array_slice($v, 0, $n)) / $n : $x * $k + $e * (1 - $k);
        $out[] = $e;
    }
    return $out;
}

/** RSI של Wilder */
function ts_rsi(array $v, int $n = 14): array
{
    $out = array_fill(0, count($v), null);
    if (count($v) <= $n) return $out;
    $g = 0.0; $l = 0.0;
    for ($i = 1; $i <= $n; $i++) {
        $d = $v[$i] - $v[$i - 1];
        if ($d > 0) $g += $d; else $l -= $d;
    }
    $g /= $n; $l /= $n;
    $out[$n] = $l == 0.0 ? 100.0 : 100 - 100 / (1 + $g / $l);
    for ($i = $n + 1; $i < count($v); $i++) {
        $d = $v[$i] - $v[$i - 1];
        $g = ($g * ($n - 1) + max($d, 0)) / $n;
        $l = ($l * ($n - 1) + max(-$d, 0)) / $n;
        $out[$i] = $l == 0.0 ? 100.0 : 100 - 100 / (1 + $g / $l);
    }
    return $out;
}

/** True Range של נר i */
function ts_tr(float $h, float $l, ?float $prevClose): float
{
    return $prevClose === null ? $h - $l : max($h - $l, abs($h - $prevClose), abs($l - $prevClose));
}

/** ATR של Wilder: ממוצע פשוט של n ה-TR הראשונים, ואחריו החלקה */
function ts_atr(array $hh, array $ll, array $cc, int $n = 14): array
{
    $out = array_fill(0, count($cc), null);
    if (count($cc) < $n) return $out;
    $sum = 0.0;
    $a = 0.0;
    foreach ($cc as $i => $c) {
        $tr = ts_tr($hh[$i], $ll[$i], $i ? $cc[$i - 1] : null);
        if ($i < $n) {
            $sum += $tr;
            if ($i === $n - 1) { $a = $sum / $n; $out[$i] = $a; }
        } else {
            $a = ($a * ($n - 1) + $tr) / $n;
            $out[$i] = $a;
        }
    }
    return $out;
}
