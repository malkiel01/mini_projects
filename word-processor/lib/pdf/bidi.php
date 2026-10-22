<?php
/**
 * אלגוריתם הכיווניות של יוניקוד (UAX #9) — הגרסה המצומצמת שמסמך עברי צריך: אותיות עבריות (R), לטיניות (L),
 * ספרות (EN) והסימנים שמחברים אותן (ES / ET / CS), רווחים וניטרליים, וסימני ניקוד (NSM). בלי הטבעות מפורשות
 * (RLE/LRE) ובלי ערבית. כמו בדפדפן: "2026/01-04" ו-"250,000 ₪" נשארים שלמים בתוך משפט בעברית.
 */

const BIDI_MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<', '«' => '»', '»' => '«'];

function bidi_class(int $c): string
{
    if ($c >= 0x30 && $c <= 0x39) return 'EN';
    if ($c === 0x20 || $c === 0x09 || $c === 0xA0 || ($c >= 0x2000 && $c <= 0x200A)) return 'WS';
    if ($c === 0x0A || $c === 0x2028) return 'B';
    if ($c >= 0x0591 && $c <= 0x05C7 && !in_array($c, [0x05BE, 0x05C0, 0x05C3, 0x05C6], true)) return 'NSM';
    if (($c >= 0x0590 && $c <= 0x05FF) || ($c >= 0xFB1D && $c <= 0xFB4F)) return 'R';
    if ($c >= 0x0600 && $c <= 0x06FF) return 'R';
    if ($c === 0x2B || $c === 0x2D || $c === 0x2212) return 'ES';
    if (in_array($c, [0x23, 0x24, 0x25, 0xA2, 0xA3, 0xA5, 0xB0, 0x20AA, 0x20AC, 0x2030], true)) return 'ET';
    if (in_array($c, [0x2C, 0x2E, 0x2F, 0x3A, 0x60C], true)) return 'CS';
    if (($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A) || ($c >= 0xC0 && $c <= 0x24F && $c !== 0xD7 && $c !== 0xF7)) return 'L';
    if ($c === 0x200F) return 'R';
    if ($c === 0x200E) return 'L';
    if ($c < 0x80 || ($c >= 0x2000 && $c <= 0x2BFF) || ($c >= 0xA0 && $c <= 0xBF)) return 'ON';
    return 'L';   // שאר האותיות בעולם — משמאל לימין
}

/** רמות כיווניות לכל תו. $para — 0 (משמאל לימין) או 1 (מימין לשמאל). */
function bidi_levels(array $cps, int $para): array
{
    $n = count($cps);
    $t = array_map('bidi_class', $cps);
    $sos = $para ? 'R' : 'L';
    // W1: ניקוד מקבל את סוג התו שלפניו
    for ($i = 0; $i < $n; $i++) if ($t[$i] === 'NSM') $t[$i] = $i ? $t[$i - 1] : $sos;
    // W2 (בלי ערבית) — אין. W4: ES/CS בודד בין שתי ספרות → ספרה (CS בלבד בין ספרות; ES בין ספרות)
    for ($i = 1; $i < $n - 1; $i++) {
        if (($t[$i] === 'ES' || $t[$i] === 'CS') && $t[$i - 1] === 'EN' && $t[$i + 1] === 'EN') $t[$i] = 'EN';
    }
    // W5: רצף ET צמוד לספרות → ספרות
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i] !== 'ET') continue;
        $j = $i;
        while ($j < $n && $t[$j] === 'ET') $j++;
        if (($i > 0 && $t[$i - 1] === 'EN') || ($j < $n && $t[$j] === 'EN')) for ($k = $i; $k < $j; $k++) $t[$k] = 'EN';
        $i = $j - 1;
    }
    // W6: שאר המפרידים → ניטרלי
    for ($i = 0; $i < $n; $i++) if (in_array($t[$i], ['ES', 'ET', 'CS'], true)) $t[$i] = 'ON';
    // W7: ספרות אחרי טקסט שמאלי (או בתחילת פסקה משמאל לימין) → L
    $last = $sos;
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i] === 'L' || $t[$i] === 'R') $last = $t[$i];
        elseif ($t[$i] === 'EN' && $last === 'L') $t[$i] = 'L';
    }
    // N1/N2: רצף ניטרלי בין שני צדדים באותו כיוון — באותו כיוון; אחרת — כיוון הפסקה
    $dir = fn($x) => $x === 'L' ? 'L' : (($x === 'R' || $x === 'EN') ? 'R' : null);
    for ($i = 0; $i < $n; $i++) {
        if (!in_array($t[$i], ['WS', 'ON', 'B'], true)) continue;
        $j = $i;
        while ($j < $n && in_array($t[$j], ['WS', 'ON', 'B'], true)) $j++;
        $before = $i > 0 ? $dir($t[$i - 1]) : $sos;
        $after = $j < $n ? $dir($t[$j]) : $sos;
        $res = $before === $after ? $before : $sos;
        for ($k = $i; $k < $j; $k++) $t[$k] = $res;
        $i = $j - 1;
    }
    // I1/I2
    $levels = [];
    for ($i = 0; $i < $n; $i++) {
        $x = $t[$i];
        if ($para === 0) $levels[$i] = $x === 'R' ? 1 : ($x === 'EN' ? 2 : 0);
        else $levels[$i] = ($x === 'L' || $x === 'EN') ? 2 : 1;
    }
    return $levels;
}

/**
 * סדר התצוגה של שורה (L1 + L2): רווחים בסוף השורה — ברמת הפסקה; ואז היפוך רצפים מהרמה הגבוהה לנמוכה.
 * $levels — הרמות של תווי השורה בלבד. מחזיר אינדקסים בסדר משמאל לימין על הדף.
 */
function bidi_visual(array $levels, array $cps, int $para): array
{
    $n = count($levels);
    for ($i = $n - 1; $i >= 0 && bidi_class($cps[$i]) === 'WS'; $i--) $levels[$i] = $para;
    $order = range(0, max(0, $n - 1));
    if ($n === 0) return [];
    $max = max($levels);
    $minOdd = PHP_INT_MAX;
    foreach ($levels as $l) if ($l % 2 === 1 && $l < $minOdd) $minOdd = $l;
    if ($minOdd === PHP_INT_MAX) return $order;
    for ($lev = $max; $lev >= $minOdd; $lev--) {
        $i = 0;
        while ($i < $n) {
            if ($levels[$order[$i]] >= $lev) {
                $j = $i;
                while ($j < $n && $levels[$order[$j]] >= $lev) $j++;
                $seg = array_reverse(array_slice($order, $i, $j - $i));
                array_splice($order, $i, $j - $i, $seg);
                $i = $j;
            } else {
                $i++;
            }
        }
    }
    return $order;
}
