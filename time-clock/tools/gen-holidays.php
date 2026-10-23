<?php
/**
 * מחולל לוח החגים היהודיים (ימי מנוחה לפי סעיף 18א לפקודת סדרי השלטון והמשפט, ויום העצמאות).
 * מחושב מהלוח העברי (הרחבת calendar של PHP) ונשמר כ-JSON — כך שהשרת לא צריך את ההרחבה.
 * הרצה: php time-clock/tools/gen-holidays.php > time-clock/lib/holidays/jewish.json
 *
 * חודשים ב-PHP: 1 תשרי … 6 אדר א׳, 7 אדר, 8 ניסן, 9 אייר, 10 סיוון.
 * יום העצמאות: ה' באייר; חל בשישי או בשבת — מוקדם לחמישי; חל בשני — נדחה לשלישי.
 */
$g = fn(int $m, int $d, int $y) => (function ($s) { [$mm, $dd, $yy] = explode('/', $s); return sprintf('%04d-%02d-%02d', $yy, $mm, $dd); })(jdtogregorian(jewishtojd($m, $d, $y)));
$out = [];
for ($y = 5786; $y <= 5797; $y++) {
    // ניסן–סיוון של שנה $y (אביב), ואז תשרי של שנה $y+1 (סתיו) — כך נשארים בסדר כרונולוגי
    $out[$g(8, 15, $y)] = 'פסח';
    $out[$g(8, 21, $y)] = 'שביעי של פסח';
    $atz = jewishtojd(9, 5, $y); $dow = jddayofweek($atz);   // 0 = ראשון
    if ($dow === 5) $atz -= 1; elseif ($dow === 6) $atz -= 2; elseif ($dow === 1) $atz += 1;
    [$mm, $dd, $yy] = explode('/', jdtogregorian($atz));
    $out[sprintf('%04d-%02d-%02d', $yy, $mm, $dd)] = 'יום העצמאות';
    $out[$g(10, 6, $y)] = 'שבועות';
    $n = $y + 1;
    $out[$g(1, 1, $n)] = 'ראש השנה';
    $out[$g(1, 2, $n)] = 'ראש השנה (ב׳)';
    $out[$g(1, 10, $n)] = 'יום כיפור';
    $out[$g(1, 15, $n)] = 'סוכות';
    $out[$g(1, 22, $n)] = 'שמיני עצרת';
}
ksort($out);
echo json_encode(['source' => 'מחושב מהלוח העברי (tools/gen-holidays.php). ימי מנוחה: סעיף 18א לפקודת סדרי השלטון והמשפט; יום העצמאות לפי כללי ההקדמה/הדחייה.',
                  'days' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
