<?php
// שני הסטים הקבועים של L5b (spec-stage-a 23.9, קריטריון 2) — לשיטת test-neighbors (tests/fixtures/methods/learning.php).
// מחזור של 20 נרות שעה, מחיר בסיס 100, "ATR" = 5. נר 0 — האות (סגירה 100); נר 1 — הכניסה (פתיחה 100) ואז ירידה; נר 2 — עלייה
// ל-111 (מעל היעד, 2 × ATR); נרות 3–19 — שטוחים על 100.
//   widen  — הירידה בנר 1 עד 94.5 (1.1 × ATR): **רעש** שפוגע בסטופ 1.0 (הפסד), והמחיר ממשיך ליעד. סטופ 1.25 שורד → רחב טוב יותר.
//   narrow — הירידה רק עד 98.5 (0.3 × ATR): כל הסטופים שורדים, וסטופ צר נותן יותר R על אותו יעד → צר טוב יותר.
// ts_learn_fx_set($kinds, $t0, $cycles): $kinds — סט אחד לכל המחזורים, או callable (מספר מחזור → סט), למשל החלפה ב-t_cut.
// $t0 — מתחלק ב-72000 (20 שעות), כך שהאות של השיטה נופל על נר 0 של כל מחזור.

function ts_learn_fx_set(string|callable $kind, int $t0, int $cycles): array
{
    $out = [];
    $f = fn(float $v) => number_format($v, 2, '.', '');
    $bar = function (int $t, float $o, float $h, float $l, float $c) use (&$out, $f) { $out[] = ['t' => $t, 'o' => $f($o), 'h' => $f($h), 'l' => $f($l), 'c' => $f($c), 'v' => '1']; };
    for ($k = 0; $k < $cycles; $k++) {
        $set = is_callable($kind) ? $kind($k) : $kind;
        $low = $set === 'widen' ? 94.5 : 98.5;
        $t = $t0 + $k * 72000;
        $bar($t, 100, 100, 100, 100);
        $bar($t + 3600, 100, 100.5, $low, 99);
        $bar($t + 7200, 99, 111, 99, 100);
        for ($i = 3; $i < 20; $i++) $bar($t + $i * 3600, 100, 100, 100, 100);
    }
    return $out;
}
