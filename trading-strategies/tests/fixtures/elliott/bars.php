<?php
/**
 * נרות סינתטיים לבדיקות אליוט, עם ATR קבוע = 1 (17.1.9 ט): כל נר h − l = 1, והמרכז זז בצעדים של עד 0.5 — כך TR = 1
 * בכל נר. נקודת מפנה: שפל P → נר עם l = P בדיוק; שיא P → h = P בדיוק. 20 נרות שטוחים בהתחלה (חימום ה-ATR).
 * $pts: [['L'|'H', P], …]; $tail — מרכז שאליו המחיר ממשיך אחרי הנקודה האחרונה (כדי לאשר אותה). $tf — אורך הנר.
 */
function ew_bars(float $start, array $pts, float $tail, int $tf = 3600): array
{
    $out = [];
    $prev = $start;
    $push = function (float $o, float $h, float $l, float $c) use (&$out, $tf) {
        $out[] = ['t' => count($out) * $tf, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
    };
    for ($i = 0; $i < 20; $i++) $push($start, $start + 0.5, $start - 0.5, $start);
    $walk = function (float $to, ?array $pivot) use (&$prev, $push) {
        $from = $prev;
        $k = max(1, (int)ceil(abs($to - $from) / 0.5 - 1e-12));
        for ($j = 1; $j <= $k; $j++) {
            $v = $j === $k ? $to : $from + ($to - $from) * $j / $k;
            if ($j === $k && $pivot) {
                [$type, $P] = $pivot;
                $type === 'L' ? $push($prev, $P + 1, $P, $v) : $push($prev, $P, $P - 1, $v);
            } else {
                $push($prev, $v + 0.5, $v - 0.5, $v);
            }
            $prev = $v;
        }
    };
    foreach ($pts as [$type, $P]) $walk($type === 'L' ? $P + 0.5 : $P - 0.5, [$type, $P]);
    if ($tail !== $prev) $walk($tail, null);
    return $out;
}
