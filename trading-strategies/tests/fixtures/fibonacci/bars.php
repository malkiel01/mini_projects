<?php
// נרות בדיקה לפיבונאצ'י (spec-stage-a 22.4, 22.9 — F-1). קבועים: בלי אקראיות ובלי שעון.
//
// fib_bars($path, $t0): המחיר הולך בין נקודות המסלול, נר לכל יחידת מחיר. גוף = 1 (פתיחה = הסגירה הקודמת), פתיל 0.5 לכל
// צד — כך כל נר הוא בטווח 2 בדיוק, ו-ATR = 2 (סף האזור 0.5 × 2 = 1.0, כמו בדוגמה של 22.4).
// נקודה במסלול יכולה להיות מערך: [מחיר, 'body' => k] — הנר האחרון שמגיע אליה בגוף k (נר ארוך), ו-'wick' => מחיר — הפתיל
// של הנר האחרון מגיע עד המחיר הזה (קוץ — קריטריון 3).
function fib_bars(array $path, int $t0 = 1_700_000_000, int $tf = 3600): array
{
    $out = [];
    $t = $t0;
    $px = (float)(is_array($path[0]) ? $path[0][0] : $path[0]);
    foreach (array_slice($path, 1) as $pt) {
        $to = (float)(is_array($pt) ? $pt[0] : $pt);
        $body = (float)(is_array($pt) ? ($pt['body'] ?? 1) : 1);
        $d = $to > $px ? 1 : -1;
        $moves = [];
        $left = abs($to - $px) - $body;
        while ($left > 1e-9) { $moves[] = min(1.0, $left); $left -= 1; }
        $moves[] = $body;
        foreach ($moves as $k => $mv) {
            $o = $px; $c = $px + $d * $mv;
            $h = max($o, $c) + 0.5; $l = min($o, $c) - 0.5;
            if ($k === count($moves) - 1 && is_array($pt) && isset($pt['wick'])) {
                if ($d > 0) $h = (float)$pt['wick']; else $l = (float)$pt['wick'];
            }
            $out[] = ['t' => $t, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
            $t += $tf;
            $px = $c;
        }
    }
    return $out;
}
