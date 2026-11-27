<?php
// פרוסה 15 — עסקאות השוואה ומנוע החישוב (docs/spec/15-comparables.md §11): 15-A1 … 15-C4, מקרי הקצה של §10, ההרשאות, הבידוד, הייבוא והמיגרציה.
$dir = sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
define('APR_DATA_DIR', $dir);
define('APR_DB_PATH', $dir . '/app.sqlite');
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
$GLOBALS['apr_time'] = 1794400000;      // שעון קפוא

// ═══ חלק א: המנוע לבדו — בלי מסד ═══════════════════════════════

$nis = fn(int $n) => $n * 100;                                   // ₪ ← אגורות
$pct = fn(string $p) => apr_comp_dec($p, 6, 4);                  // "5" ← 5000000
$adj = fn(string $label, string $kind, int $value, string $sign, string $basis = 'base', string $applies = 'both') =>
    ['pid' => null, 'label' => $label, 'kind' => $kind, 'value' => $value, 'sign' => $sign, 'basis' => $basis, 'applies_to' => $kind === 'amount' && $applies === 'both' ? 'total' : $applies];
$total = ['unit_basis' => 'total', 'area_kind' => null, 'method' => 'mean', 'rounding' => 'none'];
$sqm = ['unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none'];
$comp = fn(?int $price, ?int $area = null, string $kind = 'unknown', string $cur = 'ILS') => ['price' => $price, 'currency' => $cur, 'area' => $area, 'area_kind' => $kind];
$item = fn(string $pid, array $calc, ?int $weight = null, ?string $date = null) => ['pid' => $pid, 'calc' => $calc, 'weight' => $weight, 'deal_date' => $date, 'missing' => 0, 'dup' => false];
$ag = fn(?array $q) => $q === null ? null : apr_q_round($q, 1);   // שבר ← אגורות (מחרוזת)

// 15-A1: דוגמת האפיון 13.3
$a = apr_calc_comp($comp($nis(2000000)), [$adj('קומה', 'amount', $nis(100000), 'plus'), $adj('מצב', 'amount', $nis(30000), 'minus')], $total);
$b = apr_calc_comp($comp($nis(2200000)), [$adj('מצב', 'amount', $nis(50000), 'minus'), $adj('קומה', 'amount', $nis(20000), 'plus')], $total);
$s = apr_calc_summary([$item('a', $a), $item('b', $b)], $total);
eq([$ag($a['adjusted']), $ag($b['adjusted']), $ag($s['mean']), $s['rounded'], $a['adjusted'][1], count($a['steps']), $ag($a['steps'][0]['running']), $ag($a['steps'][1]['delta'])],
    ['207000000', '217000000', '212000000', '212000000', '1', 2, '210000000', '-3000000'], '15-A1 דוגמת האפיון: 2,070,000; 2,170,000; ממוצע 2,120,000 — בדיוק, כולל שלבי הביניים');

// 15-A2: ארבעת המקרים שבהם אין מחיר למ"ר — null וסיבה; ואחד שכן
$none = fn(array $c) => apr_calc_comp($c, [], $sqm);
eq([[$none($comp($nis(1000000)))['ok'], $none($comp($nis(1000000)))['adjusted'], $none($comp($nis(1000000)))['reason']],
    [$none($comp($nis(1000000), 0, 'registered'))['adjusted'], $none($comp($nis(1000000), 0, 'registered'))['reason']],
    [$none($comp($nis(1000000), 8000, 'unknown'))['adjusted'], $none($comp($nis(1000000), 8000, 'unknown'))['reason']],
    [$none($comp($nis(1000000), 8000, 'gross'))['adjusted'], $none($comp($nis(1000000), 8000, 'gross'))['reason']],
    $ag($none($comp($nis(1000000), 8000, 'registered'))['adjusted'])],
    [[false, null, 'area_missing'], [null, 'area_invalid'], [null, 'area_kind_unknown'], [null, 'area_kind_mismatch'], '1250000'],
    '15-A2 בלי שטח · שטח 0 · סוג שטח לא ידוע · סוג שטח שונה ← אין מחיר למ"ר (null) ויש סיבה; שטח 80 רשום ← 12,500 ₪ למ"ר');
$sk = apr_calc_summary([$item('x', $none($comp($nis(1000000)))), $item('y', $none($comp($nis(1000000), 8000, 'registered'))), $item('z', $none($comp($nis(900000), 10000, 'registered')))], $sqm);
eq([$sk['selected'], $sk['counted'], $sk['skipped'], $ag($sk['mean']), in_array(['code' => 'not_comparable', 'n' => 1], $sk['caveats'], true)], [3, 2, [['pid' => 'x', 'reason' => 'area_missing']], '1075000', true],
    '§10.1 עסקה בלי שטח בקבוצה למ"ר: מחוץ לסיכום, עם הסיבה; הממוצע — רק על מה שנכנס');
eq([apr_calc_comp($comp(null), [], $total)['reason'], apr_calc_comp($comp($nis(5), null, 'unknown', 'USD'), [], $total)['reason'], apr_calc_comp($comp(0), [], $total)['ok']], ['price_missing', 'currency', true],
    '§10.8 בלי מחיר, או מחיר במטבע אחר ← אינה נכנסת לחישוב; מחיר 0 שהוזן הוא מחיר (לא "חסר")');

// 15-A5: אחוז על base מול אחוז על running; הסדר משנה רק ב-running
$run = fn(array $adjs) => $ag(apr_calc_comp($comp($nis(1000000)), $adjs, $total)['adjusted']);
$p10b = $adj('א', 'percent', $pct('10'), 'plus', 'base');
$p20b = $adj('ב', 'percent', $pct('20'), 'minus', 'base');
$p10r = $adj('א', 'percent', $pct('10'), 'plus', 'running');
$p20r = $adj('ב', 'percent', $pct('20'), 'minus', 'running');
$amt = $adj('ג', 'amount', $nis(50000), 'plus');
eq([$run([$p10b, $p20b]), $run([$p20b, $p10b]), $run([$p10r, $p20r]), $run([$p20r, $p10r]), $run([$amt, $p10b]), $run([$p10b, $amt]), $run([$amt, $p10r]), $run([$p10r, $amt])],
    ['90000000', '90000000', '88000000', '88000000', '115000000', '115000000', '115500000', '115000000'],
    '15-A5 אחוז על הבסיס: 1,000,000 +10% −20% = 900,000 בכל סדר; על השוטף: 880,000; סכום ואז 10% על השוטף = 1,155,000, ובסדר הפוך 1,150,000');
eq([$run([$adj('הכול', 'percent', $pct('100'), 'minus')]), apr_calc_comp($comp($nis(1000000)), [$adj('הכול', 'percent', $pct('100'), 'minus')], $total)['nonpositive'],
    $run([$adj('יותר', 'percent', $pct('150'), 'minus')]), $run([$adj('שבר', 'percent', $pct('0.000001'), 'plus')])], ['0', true, '-50000000', '100000001'],
    '§10.6 התאמה של 100% במינוס מותרת חשבונית: תוצאה 0 (או שלילית) מסומנת nonpositive; אחוז של 0.000001 מדויק');
// התאמה בסכום שייכת לבסיס אחד: ₪ לעסקה אינו מתווסף למחיר למ"ר
$mixed = [$adj('קומה', 'amount', $nis(100000), 'plus', 'base', 'total'), $adj('מצב', 'amount', $nis(500), 'minus', 'base', 'per_sqm'), $adj('זמן', 'percent', $pct('2'), 'plus', 'base', 'both')];
eq([$ag(apr_calc_comp($comp($nis(2000000), 8000, 'registered'), $mixed, $total)['adjusted']), $ag(apr_calc_comp($comp($nis(2000000), 8000, 'registered'), $mixed, $sqm)['adjusted']),
    count(apr_calc_comp($comp($nis(2000000), 8000, 'registered'), $mixed, $sqm)['steps'])], ['214000000', '2500000', 2],
    'התאמה בסכום חלה רק על הבסיס שלה: בקבוצה כוללת 2,000,000 + 100,000 + 2%; בקבוצה למ"ר 25,000 − 500 + 2% = 25,000');

// 15-A6: 1,000 חישובים אקראיים מול חישוב ייחוס בשלמים רגילים — בלי סטייה של אגורה
mt_srand(15);
$bad = [];
for ($i = 0; $i < 1000 && count($bad) < 3; $i++) {
    $price = mt_rand(1, 99999999);                                 // עד 999,999.99 ₪
    $S = 100000000;                                                // הייחוס מחושב מוגדל פי 10^8: שני צעדי אחוז עם שתי ספרות אחרי הנקודה נשארים שלמים
    $ref = $price * $S;
    $adjs = [];
    $pctSteps = 0;
    for ($j = mt_rand(0, 5); $j > 0; $j--) {
        $sign = mt_rand(0, 1) ? 'plus' : 'minus';
        $kind = mt_rand(0, 1) && $pctSteps < 2 ? 'percent' : 'amount';
        if ($kind === 'amount') {
            $v = mt_rand(1, 9999999);
            $delta = $v * $S;
            $adjs[] = $adj("s$j", 'amount', $v, $sign);
        } else {
            $pctSteps++;
            $p = mt_rand(1, 9999);                                 // 0.01% … 99.99%
            $basis = mt_rand(0, 1) ? 'base' : 'running';
            $delta = intdiv(($basis === 'base' ? $price * $S : $ref), 10000) * $p;      // מתחלק בלי שארית: הייחוס כפולה של 10^4 בכל שלב
            $adjs[] = $adj("p$j", 'percent', $p * 10000, $sign, $basis);
        }
        $ref += $sign === 'plus' ? $delta : -$delta;
    }
    $want = (string)(intdiv($ref + intdiv($S, 2) + $S * 100000000, $S) - 100000000);      // חצי כלפי מעלה, גם לשלילי
    $got = apr_q_round(apr_calc_comp($comp($price), $adjs, $total)['adjusted'], 1);
    if ($got !== $want) $bad[] = [$price, $adjs, $got, $want];
}
eq($bad, [], '15-A6 1,000 חישובים אקראיים שווים לחישוב ייחוס בשלמים — בלי סטייה של אגורה');
// ממוצע, חציון ומשוקלל אקראיים מול שלמים: (Σ) ÷ n מעוגל פעם אחת
$bad = [];
for ($i = 0; $i < 300 && count($bad) < 3; $i++) {
    $n = mt_rand(2, 9);
    $vals = [];
    $items = [];
    $ws = [];
    for ($j = 0; $j < $n; $j++) {
        $vals[] = $v = mt_rand(1, 999999999);
        $ws[] = $w = mt_rand(0, 5000000);
        $items[] = $item("c$j", apr_calc_comp($comp($v), [], $total), $w);
    }
    if (array_sum($ws) === 0) continue;
    $sm = apr_calc_summary($items, ['method' => 'weighted'] + $total);
    sort($vals);
    $half = fn(int $num, int $den) => (string)intdiv(2 * $num + $den, 2 * $den);
    $med = $n % 2 ? (string)$vals[intdiv($n, 2)] : $half($vals[intdiv($n, 2) - 1] + $vals[intdiv($n, 2)], 2);
    $wsum = 0;
    foreach ($items as $it) $wsum += (int)$ag($it['calc']['adjusted']) * $it['weight'];
    $want = [$half(array_sum($vals), $n), $med, $half($wsum, array_sum($ws)), (string)$vals[0], (string)$vals[$n - 1]];
    $got = [$ag($sm['mean']), $ag($sm['median']), $ag($sm['weighted']), $ag($sm['min']), $ag($sm['max'])];
    if ($got !== $want) $bad[] = [$got, $want];
}
eq($bad, [], '15-A6 ממוצע, חציון, ממוצע משוקלל וטווח — 300 קבוצות אקראיות מול חשבון שלמים');

// בדיקה סטטית: אין נקודה צפה ואין מודל שפה במנוע (15-A6, 15-C1)
$engine = (string)file_get_contents(__DIR__ . '/../lib/comp_calc.php');
$floatish = [];
foreach (token_get_all($engine) as $t) {
    if (is_string($t)) { if ($t === '/') $floatish[] = '/'; continue; }
    if (in_array($t[0], [T_DNUMBER, T_DOUBLE_CAST, T_DIV_EQUAL, T_POW, T_POW_EQUAL, T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) $floatish[] = token_name($t[0]) . ' ' . $t[1];
    if ($t[0] === T_STRING && preg_match('/^(float|floatval|doubleval|round|floor|ceil|fdiv|fmod|sqrt|pow|exp|log|number_format|is_float|bcadd|bcmul|bcdiv|microtime|time|rand|mt_rand|random_int|apr_db|apr_row|apr_rows|apr_val|apr_exec|apr_now|apr_time|curl_init|file_get_contents)$/i', $t[1])) $floatish[] = $t[1];
}
$allLib = '';
foreach (['comp_calc.php', 'comps.php', 'comp_import.php'] as $f) $allLib .= (string)file_get_contents(__DIR__ . '/../lib/' . $f);
eq([$floatish, preg_match('/\bai_run\b|apr_ai_|anthropic|openai/i', $allLib)], [[], 0],
    '15-A6 / 15-C1 במנוע אין חילוק /, אין מספר עשרוני, אין פונקציית float, אין שעון, אין מסד ואין טעינת קובץ אחר; ובכל קובצי הפרוסה אין קריאה ל-ai_run');

// 15-A7: עיגול פעם אחת, בסוף — לא סכום של מעוגלים
$r1 = apr_calc_comp($comp($nis(1000400)), [], $total);
$r2 = apr_calc_comp($comp($nis(1000400)), [], $total);
$r3 = apr_calc_comp($comp($nis(1000400)), [], $total);
$sr = apr_calc_summary([$item('a', $r1), $item('b', $r2), $item('c', $r3)], ['rounding' => '1000'] + $total);
$third = apr_calc_summary([$item('a', apr_calc_comp($comp(100), [], $total)), $item('b', apr_calc_comp($comp(100), [], $total)), $item('c', apr_calc_comp($comp(101), [], $total))], $total);
$e14 = apr_calc_summary([$item('a', apr_calc_comp($comp($nis(2124998)), [], $total)), $item('b', apr_calc_comp($comp($nis(2125000)), [], $total))], ['rounding' => '10000'] + $total);
$tie = fn(int $x, string $r) => apr_calc_summary([$item('a', apr_calc_comp($comp($x), [], $total)), $item('b', apr_calc_comp($comp($x), [], $total))], ['rounding' => $r] + $total)['rounded'];
eq([$sr['rounded'], $sr['unrounded'], $third['mean'], $third['rounded'], [$e14['rounded'], $e14['unrounded']], $tie($nis(2125000), '10000'), $tie($nis(2500), '1000'), $tie(50, '1'), $tie(49, '1')],
    ['100000000', '100040000', ['301', '3'], '100', ['212000000', '212499900'], '213000000', '300000', '100', '0'],
    '15-A7 העיגול נעשה פעם אחת על התוצאה המדויקת (1,000,400 ← 1,000,000; שליש נשמר כשבר 301/3); §10.14 2,124,999 לעשרת אלפים ← 2,120,000 ומוצג גם הלא-מעוגל; חצי — כלפי מעלה');
eq([apr_q_round(apr_q(-5, 2), 1), apr_q_round(apr_q(5, 2), 1), apr_q_round(apr_q(-7, 2), 1), apr_q_decimal(apr_q(1, 3), 4), apr_q_decimal(apr_q(-1, 8), 2), apr_q_round(apr_q('123456789012345678901234567890', '1000000000000'), 1)],
    ['-2', '3', '-3', '0.3333', '-0.12', '123456789012345679'], 'חצי כלפי מעלה = לכיוון +∞ (‎-2.5 ← ‎-2; 2.5 ← 3); מספרים ארוכים משלם של 64 סיביות נשארים מדויקים');

// 15-A8: משקולות 1, 1, 2 שוות ל-25, 25, 50
$w3 = fn(array $ws) => apr_calc_summary([$item('a', apr_calc_comp($comp($nis(1000000)), [], $total), $ws[0]), $item('b', apr_calc_comp($comp($nis(1300001)), [], $total), $ws[1]), $item('c', apr_calc_comp($comp($nis(2000000)), [], $total), $ws[2])], ['method' => 'weighted'] + $total);
$wa = $w3([1000000, 1000000, 2000000]);
$wb = $w3([25000000, 25000000, 50000000]);
eq([$wa['weighted'] === $wb['weighted'], $wa['rounded'], $wb['rounded'], array_map(fn($w) => apr_q_decimal(apr_q_mul($w['share'], apr_q(100)), 2), $wa['weights']), $wa['weighted']],
    [true, '157500025', '157500025', ['25.00', '25.00', '50.00'], ['157500025', '1']], '15-A8 ממוצע משוקלל עם 1, 1, 2 שווה בדיוק לזה עם 25, 25, 50; המשקל היחסי מוצג');
$wz = $w3([0, 0, 0]);
$wm = $w3([1000000, null, 2000000]);
$w0 = $w3([0, 1000000, 1000000]);
$nw = apr_calc_summary([$item('a', apr_calc_comp($comp(100), [], $total)), $item('b', apr_calc_comp($comp(300), [], $total))], ['method' => 'weighted'] + $total);
eq([[$wz['weights_block'], $wz['weighted'], $wz['result'], $wz['rounded'], $wz['result_note']], [$wm['weights_block'], $wm['result'], $wm['result_note']], [$w0['weights_block'], $w0['rounded']], [$nw['weights_block'], $nw['result']], $ag($wz['mean'])],
    [['weights_zero', null, null, null, 'weights_zero'], ['weight_missing', null, 'weight_missing'], [null, '165000050'], ['weight_missing', null], '143333367'],
    '§10.13 משקל אפס לכולן ← נחסם בלי חלוקה באפס; עסקה בלי משקל כשלאחרות יש ← נחסם; משקל 0 לעסקה אחת מותר; הממוצע הרגיל מוצג גם כשהמשוקלל חסום');

// 🟡1: 15 עסקאות × 30 התאמות אחוז עם 6 ספרות אחרי הנקודה, למ"ר — המכנה אינו מתנפח, והתוצאה שווה לחישוב ייחוס שמצמצם כל שלב
$t0 = hrtime(true);
$heavy = [];
$denLen = 0;
for ($ci = 0; $ci < 15; $ci++) {
    $hAdj = [];
    for ($i = 0; $i < 30; $i++) $hAdj[] = $adj("a$i", 'percent', 1234567 + $i * 7 + $ci, $i % 3 ? 'plus' : 'minus', $i % 4 ? 'running' : 'base');
    $hc = apr_calc_comp($comp(123456789 + $ci, 7831 + $ci * 13, 'registered'), $hAdj, $sqm);
    foreach ($hc['steps'] as $st) { apr_q_round($st['delta'], 1); apr_q_round($st['running'], 100); }
    $denLen = max($denLen, strlen($hc['adjusted'][1]));
    $heavy[] = $item("c$ci", $hc, 1000000 + $ci);
}
$hs = apr_calc_summary($heavy, ['method' => 'weighted', 'rounding' => '100'] + $sqm);
$ms = intdiv(hrtime(true) - $t0, 1000000);
// ייחוס: אותו חישוב בצעד חיבור "תמים" (שוטף + שוטף·שיעור) — חייב לתת בדיוק אותו שבר
$naive = apr_q(12345678900, 7831);
$nb = $naive;
for ($i = 0; $i < 30; $i++) {
    $rate = apr_q(($i % 3 ? 1 : -1) * (1234567 + $i * 7), 100000000);
    $naive = apr_q_add($naive, apr_q_mul($i % 4 ? $naive : $nb, $rate));
}
eq([$ms < 3000, $denLen < 260, $hs['rounded'], apr_q_cmp($naive, $heavy[0]['calc']['adjusted']), strlen($hs['mean'][1]) < 400], [true, true, '1760000', 0, true],
    "🟡1 15 עסקאות × 30 התאמות אחוז (6 ספרות, למ\"ר) מחושבות בפחות מ-3 שניות ({$ms}ms), המכנה נשאר קצר, והתוצאה זהה לחישוב בצעדי חיבור");
// המחלק המשותף של מכנים ארוכים: תמיד מחלק את שניהם, ומוצא את החלק של 2 ו-5
$dA = apr_nu_mul('7831', '1' . str_repeat('0', 40));
$dB = apr_nu_mul(apr_nu_mul('7831', '1024'), '1' . str_repeat('0', 30));
$gAB = apr_nu_den_gcd($dA, $dB);
$sumAB = apr_q_add(['1', $dA], ['1', $dB]);
eq([apr_nu_divmod($dA, $gAB)[1], apr_nu_divmod($dB, $gAB)[1], $gAB === $dB, apr_nu_split25('7831000'), apr_nu_pow25(13, 2), apr_nu_divmod($dA, $sumAB[1])[1], strlen($sumAB[1]) <= strlen($dA), apr_q_cmp($sumAB, apr_q(9765626, $dA))],
    ['0', '0', true, [3, 3, '7831'], '204800', '0', true, 0], 'מחלק משותף של שני מכנים ארוכים נמצא דרך החזקות של 2 ו-5, והסכום יושב על המכנה המשותף הקטן');

// מקרי קצה של הסיכום: אפס עסקאות, עסקה אחת, מדגם קטן
$z = apr_calc_summary([], $total);
$one = apr_calc_summary([$item('a', $a, null, '2025-03-01')], $total);
$two = apr_calc_summary([$item('a', $a, null, '2025-03-01'), $item('b', $b, null, '2024-11-20')], $total);
$three = apr_calc_summary([$item('a', $a), $item('b', $b), $item('c', $a)], $total);
eq([[$z['selected'], $z['min'], $z['mean'], $z['median'], $z['rounded'], $z['result_note'], $z['caveats']],
    [$ag($one['min']), $ag($one['max']), $one['mean'], $one['median'], $one['rounded'], $one['result_note'], $one['caveats'], $one['date_from']],
    [$two['caveats'], $two['date_from'], $two['date_to'], $ag($two['median'])], $three['caveats']],
    [[0, null, null, null, null, 'none_selected', []], ['207000000', '207000000', null, null, null, 'need_two', [['code' => 'small_sample', 'n' => 1]], '2025-03-01'],
        [[['code' => 'small_sample', 'n' => 2]], '2024-11-20', '2025-03-01', '212000000'], []],
    '§10.5 אפס עסקאות ← הכול null, בלי אפסים; §10.4 עסקה אחת ← אין ממוצע ("נדרשות לפחות שתיים") ומדגם קטן; 15-B5 שתי עסקאות ← "מדגם קטן"; שלוש ← בלי');
eq([apr_calc_summary([$item('a', $a), $item('b', $b)], ['method' => 'range_only'] + $total)['result'], apr_calc_summary([$item('a', $a), $item('b', $b)], ['method' => 'manual'] + $total)['rounded'],
    $ag(apr_calc_summary([$item('a', $a), $item('b', $b), $item('c', $b)], ['method' => 'median'] + $total)['result'])], [null, null, '217000000'], 'טווח בלבד / ידני ← המנוע אינו מציע מספר; חציון של שלוש — האמצעית');
// חריגה (§4.4): פי 2 מהחציון — התרעה, והעסקה נשארת בחישוב
$o = apr_calc_summary([$item('a', apr_calc_comp($comp(100), [], $total)), $item('b', apr_calc_comp($comp(110), [], $total)), $item('c', apr_calc_comp($comp(220), [], $total)), $item('d', apr_calc_comp($comp(55), [], $total)), $item('e', apr_calc_comp($comp(219), [], $total))], $total);
eq([$o['outliers'], $o['counted'], in_array(['code' => 'outliers', 'n' => 2], $o['caveats'], true)], [['c', 'd'], 5, true], '§4.4 חריגה: פי 2 מהחציון ומעלה (או חצי ומטה) מסומנת — ונשארת בחישוב');
eq([$ag(apr_calc_subject_total(1250000, 8550)), apr_calc_subject_total(1234567, 7833)], ['106875000', ['9670363311', '100']], '§6.5 מסקנה למ"ר × שטח נישום: 12,500 × 85.5 = 1,068,750 — מדויק');
$thrown = [];
foreach ([fn() => apr_q(1, 0), fn() => apr_q_div(apr_q(1), apr_q(0)), fn() => apr_nu_divmod('5', '0')] as $f) { try { $f(); $thrown[] = 'no'; } catch (DivisionByZeroError) { $thrown[] = 'div0'; } }
eq($thrown, ['div0', 'div0', 'div0'], 'חלוקה באפס במנוע היא חריגה — לעולם לא INF, NAN או אפס שקט');

// ═══ חלק ב: ה-API ═════════════════════════════════════════════

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית, שותפה בתיק
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד: תקבל edit — ועדיין אינה שמאית
$noa = ident('9', 'נועה', 'noa@example.com');      // מתמחה עם edit: תפקיד "מבקר" בתיק
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיק
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
$noaPid = join_office($avi, $noa, 'noa@example.com', 'trainee');
join_office($avi, $gil, 'gil@example.com', 'appraiser');

function make_profile(string $as, ?array $comps = null): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $as)[1]['profile'];
    $it = ['key' => 'contract', 'group' => '', 'doc_type' => 'rights', 'title' => 'הסכם מכר', 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => [$it]], 'tasks' => ['items' => []], 'rules' => []] + ($comps !== null ? ['comps' => $comps] : []), 'revision' => $d['draft']['revision']], $as);
    if ($s[0] !== 200) return 'ERR:' . code($s);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => 'ראשון', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה ברחוב הרצל', 'purpose' => 'purchase', 'property_type' => 'apartment'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'email' => 'moshe@client.example', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
$P = make_profile($avi, ['default_criteria' => ['months_back' => 36, 'radius_m' => 500, 'same_property_type' => false], 'unit_basis' => 'total', 'area_kind' => 'built', 'method' => 'median', 'rounding' => '100', 'small_sample_under' => 4]);
$PB = make_profile($boaz);
$C = make_case($avi, $P);
$CB = make_case($boaz, $PB);
foreach ([[$danaPid, null], [$talPid, ['view' => true, 'edit' => true]], [$noaPid, ['view' => true, 'edit' => true]]] as [$u, $perms]) {
    call('POST', 'case-member-add', ['case' => $C, 'user' => $u], $avi);
    if ($perms) call('POST', 'case-member-update', ['case' => $C, 'user' => $u, 'perms' => $perms], $avi);
}
$add = fn(string $as, string $case, array $values, array $more = []) => call('POST', 'comp-add', ['case' => $case, 'values' => $values] + $more, $as);
$view = fn(string $as, string $comp) => call('GET', 'comp', [], $as, ['comp' => $comp]);
$list = fn(string $as, string $case) => call('GET', 'case-comps', [], $as, ['case' => $case]);
$krow = fn(string $pid) => apr_row('SELECT * FROM comps WHERE pid = ?', [$pid]);
$rev = fn(string $pid) => (int)apr_val('SELECT rev FROM comps WHERE pid = ?', [$pid]);
$grev = fn(string $pid) => (int)apr_val('SELECT rev FROM comp_sets WHERE pid = ?', [$pid]);
$status = fn(string $as, string $comp, string $st, ?string $reason = null) => call('POST', 'comp-status', ['comp' => $comp, 'rev' => $rev($comp), 'status' => $st] + ($reason !== null ? ['reason' => $reason] : []), $as);
$adjSave = fn(string $as, string $comp, array $a) => call('POST', 'comp-adj-save', ['comp' => $comp, 'rev' => $rev($comp)] + $a, $as);
$audit = fn(string $action, string $pid) => array_map(fn($r) => ['meta' => json_decode($r['meta'], true), 'reason' => $r['reason']], apr_rows('SELECT meta, reason FROM audit_log WHERE action = ? AND entity_pid = ? ORDER BY id', [$action, $pid]));

// ברירות המחדל מהפרופיל (§7), והמקטע עצמו
$l0 = $list($avi, $C)[1];
eq([$l0['defaults'], $l0['comps'], $l0['sets'], $l0['searches'], $l0['perms']],
    [['default_criteria' => ['months_back' => 36, 'radius_m' => 500, 'same_property_type' => false], 'unit_basis' => 'total', 'area_kind' => 'built', 'method' => 'median', 'rounding' => '100', 'small_sample_under' => 4], [], [], [], ['write' => true, 'judge' => true]],
    '§7 מקטע "עסקאות" בפרופיל: ברירות המחדל מגיעות לתיק; תיק חדש — בלי עסקאות, בלי קבוצות ובלי אפסים');
eq([apr_pc_comps(null), apr_pc_comps(['rounding' => 1000, 'default_criteria' => ['radius_m' => null]])['rounding'], make_profile($avi, ['method' => 'guess']), make_profile($avi, ['small_sample_under' => 0]), make_profile($avi, ['default_criteria' => ['months_back' => '24']]),
    call('GET', 'case-comps', [], $boaz, ['case' => $CB])[1]['defaults']['default_criteria']['radius_m']],
    [apr_pc_comps_default(), '1000', 'ERR:content_invalid', 'ERR:content_invalid', 'ERR:content_invalid', null], '§7 פרופיל בלי המקטע מקבל את ברירות המחדל (radius_m: null = לא נקבע); ערך שאינו ברשימה נדחה בשמירה');

// חיפוש (§4.1)
$dealsSrc = apr_val("SELECT key FROM sources WHERE status = 'active' AND info_kinds LIKE '%deals%' ORDER BY id LIMIT 1");
$S1 = call('POST', 'comp-search-add', ['case' => $C, 'source' => $dealsSrc, 'criteria' => ['gush' => '6158', 'date_from' => '2024-01-01', 'date_to' => '2025-06-30', 'area_min' => '60', 'area_max' => '100.5', 'rooms_min' => '3'], 'result_count' => 0, 'limitations' => 'המקור אינו מציג קומה'], $avi);
$S2 = call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'מאגר פנימי של המשרד'], $tal);
$sOut = array_column($list($avi, $C)[1]['searches'], null, 'pid');
eq([$S1[0], $sOut[$S1[1]['search']]['result_count'], $sOut[$S1[1]['search']]['criteria'], $sOut[$S1[1]['search']]['source']['key'], $sOut[$S1[1]['search']]['by'], $sOut[$S1[1]['search']]['limitations'],
    array_key_exists('result_count', $sOut[$S2[1]['search']]), $sOut[$S2[1]['search']]['result_count'], $sOut[$S2[1]['search']]['source'], $sOut[$S2[1]['search']]['by'],
    code(call('POST', 'comp-search-add', ['case' => $C], $avi)), code(call('POST', 'comp-search-add', ['case' => $C, 'source' => 'no_such_source'], $avi)),
    code(call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'x', 'criteria' => ['hack' => 1]], $avi)), code(call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'x', 'result_count' => -1], $avi))],
    [200, 0, ['gush' => '6158', 'date_from' => '2024-01-01', 'date_to' => '2025-06-30', 'area_min' => '60', 'area_max' => '100.5', 'rooms_min' => '3'], $dealsSrc, 'אבי', 'המקור אינו מציג קומה',
        true, null, null, 'טל', 'comp_search_source', 'not_found', 'comp_field', 'comp_value'],
    '§4.1 חיפוש נרשם עם המקור, הקריטריונים, מי, וכמה נמצא: 0 תוצאות הוא 0; "לא נרשם" הוא null — לא אפס; בלי מקור נדחה');
$S1 = $S1[1]['search'];
$S2 = $S2[1]['search'];
$su = call('POST', 'comp-search-update', ['search' => $S2, 'rev' => 1, 'result_count' => 14], $tal);
eq([$su[0], (int)apr_val('SELECT result_count FROM comp_searches WHERE pid = ?', [$S2]), array_key_exists('from', $audit('comp.search_update', $S2)[0]['meta']) ? $audit('comp.search_update', $S2)[0]['meta']['from'] : 'x', $audit('comp.search_update', $S2)[0]['meta']['to'] ?? null,
    code(call('POST', 'comp-search-update', ['search' => $S2, 'rev' => 2, 'result_count' => 14], $tal)), code(call('POST', 'comp-search-update', ['search' => $S2, 'rev' => 1, 'result_count' => 15], $tal)),
    code(call('POST', 'comp-search-update', ['search' => $S2, 'result_count' => 15], $tal)), (int)apr_val('SELECT result_count FROM comp_searches WHERE pid = ?', [$S2])],
    [200, 14, null, 14, 'nothing_to_change', 'comp_changed', 'rev_required', 14], 'עדכון של מספר התוצאות נרשם ביומן עם הערך הקודם והחדש; ⚪ עדכון חיפוש דורש rev — rev ישן נדחה, ושום דבר לא נכתב');

// הזנה ידנית (§4.2): שדה שלא הוזן נשאר null — 15-B2
$K1 = $add($tal, $C, ['deal_date' => '2025-03-01', 'price' => '2000000', 'address_text' => 'הרצל 10', 'locality' => 'תל אביב', 'location_precision' => 'exact', 'distance_m' => 120, 'area' => '80', 'area_kind' => 'registered', 'floor' => 2], ['search' => $S1]);
$K2 = $add($tal, $C, ['deal_date' => '2025-01-15', 'price' => '2200000.50', 'gush' => '6158', 'helka' => '30', 'location_precision' => 'block', 'distance_m' => 300, 'address_text' => 'אזור הרצל', 'area' => '88', 'area_kind' => 'gross']);
$K3 = $add($avi, $C, ['price' => 1500000]);
[$K1, $K2, $K3] = [$K1[1]['comp'], $K2[1]['comp'], $K3[1]['comp']];
$o1 = $view($avi, $K1)[1]['comp'];
$o2 = $view($avi, $K2)[1]['comp'];
$o3 = $view($avi, $K3)[1]['comp'];
eq([$o1['values']['price'], $o1['values']['area'], $o1['values']['floor'], $o1['values']['rooms'], $o1['values']['build_year'], $o1['values']['condition'], $o1['status'], array_key_exists('weight', $o1) ? 'per-comp weight' : null, $o1['search'],
    $o2['values']['price'], $o3['values']['deal_date'], $o3['values']['area'], $o3['values']['area_kind'], $o3['values']['currency'], $o1['missing'], $o3['missing'], $krow($K3)['area'], $krow($K3)['floor']],
    ['2000000', '80', 2, null, null, null, 'candidate', null, $S1, '2200000.5', null, null, 'unknown', 'ILS', ['property_type', 'rooms', 'floors_total', 'build_year', 'condition'],
        ['deal_date', 'address_text', 'locality', 'property_type', 'area', 'rooms', 'floor', 'floors_total', 'build_year', 'condition'], null, null],
    '15-B2 שדה שלא הוזן נשמר NULL ומוחזר null (לא 0, לא ""); רשימת השדות החסרים מפורשת; עסקה חדשה היא "מועמדת", בלי משקל');
// 15-B3 / 15-D3: מרחק וכתובת רק במיקום מדויק
eq([$o1['values']['distance_m'], $o1['values']['address'], $o1['values']['approx_location'], $o2['values']['distance_m'], $o2['values']['address'], $o2['values']['approx_location'], $krow($K2)['distance_m'], array_key_exists('address_text', $o2['values'])],
    [120, 'הרצל 10', null, null, null, 'אזור הרצל', null, false], '15-B3 distance_m הוא null כשהמיקום אינו exact / street — גם במסד; מיקום מקורב אינו מוחזר ככתובת');
$raises = function (string $sql, array $args = []): string { try { apr_exec($sql, $args); return 'ok'; } catch (PDOException $e) { return preg_match('/comp\w*: .*$/', $e->getMessage(), $m) ? $m[0] : $e->getMessage(); } };
eq([$raises("UPDATE comps SET distance_m = 5, rev = rev + 1 WHERE pid = ?", [$K2]) !== 'ok', $raises('DELETE FROM comps WHERE pid = ?', [$K3]), $raises("UPDATE comps SET raw = '{}', rev = rev + 1 WHERE pid = ?", [$K1]), $raises('UPDATE comps SET price = 1 WHERE pid = ?', [$K1]),
    $raises("UPDATE comps SET status = 'excluded', rev = rev + 1 WHERE pid = ?", [$K1]) !== 'ok', $raises('DELETE FROM comp_searches WHERE pid = ?', [$S1])],
    [true, 'comps: no delete', 'comps: raw is immutable', 'comps: rev / identity', true, 'comp_searches: no delete'],
    'המסד עצמו אוכף: מרחק רק במיקום מדויק, עסקה אינה נמחקת, raw אינו משתנה, rev מתקדם באחד, החרגה בלי נימוק נדחית');
eq([code($add($avi, $C, [])), code($add($avi, $C, ['price' => '-5'])), code($add($avi, $C, ['price' => '12.345'])), code($add($avi, $C, ['price' => '1e6'])), code($add($avi, $C, ['area_kind' => 'huge', 'price' => 1])),
    code($add($avi, $C, ['price' => 1, 'bogus' => 2])), code($add($avi, $C, ['deal_date' => '2025-02-30'])), code($add($avi, $C, ['price' => 1.5])), code($add($avi, $C, ['price' => 1], ['search' => $S1 . 'x']))],
    ['comp_empty', 'comp_value', 'comp_value', 'comp_value', 'comp_value', 'comp_field', 'date_invalid', null, 'not_found'], 'קלט: עסקה ריקה, מחיר שלילי, שלוש ספרות אחרי הנקודה, כתיב מדעי, ערך שאינו ברשימה ושדה לא מוכר — נדחים');
apr_exec("UPDATE comps SET deleted_at = '2026-01-01', rev = rev + 1 WHERE case_id = (SELECT id FROM cases WHERE pid = ?) AND price = 150", [$C]);      // העסקה של 1.5 ₪ מהבדיקה למעלה

// 15-B1: תיקון אינו משנה את raw; corrected מחזיק את הערך הקודם
$raw0 = $krow($K1)['raw'];
$cr = call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['price' => '2050000', 'floor' => 3, 'rooms' => '3.5'], 'reason' => 'לפי הסכם המכר'], $tal);
$o1 = $view($avi, $K1)[1]['comp'];
$corr = (array)$o1['corrected'];
eq([$cr[0], $krow($K1)['raw'] === $raw0, $o1['raw']['entered']['price'], $o1['values']['price'], $corr['price'], $corr['floor']['from'], $corr['rooms']['from'], $corr['rooms']['to'],
    $audit('comp.correct', $K1)[0]['meta']['changes']['price'], $audit('comp.correct', $K1)[0]['reason'], $o1['corrected_fields']],
    [200, true, '2000000', '2050000', ['from' => '2000000', 'to' => '2050000', 'by' => 'טל', 'at' => apr_now(), 'reason' => 'לפי הסכם המכר'], 2, null, '3.5', ['from' => '2000000', 'to' => '2050000'], 'לפי הסכם המכר', ['price', 'floor', 'rooms']],
    '15-B1 תיקון שדה אינו משנה את raw; corrected מחזיק מה היה, מה נהיה, מי, מתי ולמה; והיומן — את הערך הקודם והחדש');
eq([code(call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['price' => '2050000'], 'reason' => 'x'], $tal)), code(call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['price' => '1']], $tal)),
    code(call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['raw' => '{}'], 'reason' => 'x'], $tal)), code(call('POST', 'comp-correct', ['comp' => $K1, 'changes' => ['price' => '1'], 'reason' => 'x'], $tal)),
    code(call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['status' => 'selected'], 'reason' => 'x'], $tal))],
    ['nothing_to_change', 'reason_required', 'comp_field', 'rev_required', 'comp_field'], 'תיקון בלי שינוי, בלי נימוק, בלי rev, או של שדה שאינו שדה ערך (raw, status) — נדחה');
call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['price' => '2000000', 'floor' => 2, 'rooms' => null], 'reason' => 'חזרה לערך המקור'], $tal);
call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['location_precision' => 'locality'], 'reason' => 'הכתובת אינה ודאית'], $tal);
eq([$krow($K1)['distance_m'], $view($avi, $K1)[1]['comp']['values']['address'], ((array)$view($avi, $K1)[1]['comp']['corrected'])['distance_m']['from']], [null, null, 120], '15-B3 מיקום שתוקן למקורב ← המרחק מתנקה, והכתובת מפסיקה להיות מוצגת ככתובת');
call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['location_precision' => 'exact', 'distance_m' => 120], 'reason' => 'אומת מול הנסח'], $tal);

// §10.12 שני משתמשים עורכים: rev
$stale = $rev($K1);
call('POST', 'comp-note', ['comp' => $K1, 'rev' => $stale, 'note' => 'הערה של דנה'], $dana);
$late = call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $stale, 'changes' => ['price' => '1'], 'reason' => 'x'], $tal);
eq([$late[0], code($late), $late[1]['error']['message'], $late[1]['error']['by'] ?? null, $late[1]['error']['current']['note'] ?? null, $krow($K1)['price']], [409, 'comp_changed', 'עודכן בידי דנה — רעננו ונסו שוב', 'דנה', 'הערה של דנה', 200000000],
    '§10.12 שמירה על rev ישן ← 409 "עודכן בידי <שם>" עם המצב שבשרת, ושום דבר לא נכתב');

// §5 מצבים; 15-A4 החרגה בלי נימוק נדחית
eq([code($status($avi, $K1, 'excluded')), code($status($avi, $K1, 'pending', '  ')), code($status($avi, $K1, 'approved')), $status($avi, $K1, 'selected')[0], $status($dana, $K2, 'selected', 'דומה מאוד')[0],
    $status($avi, $K3, 'excluded', 'עסקה בין קרובים')[0], $krow($K3)['status_reason'], $audit('comp.status', $K3)[0], code($status($avi, $K3, 'excluded', 'עסקה בין קרובים')), $status($avi, $K3, 'pending', 'חסר שטח')[0], $status($avi, $K3, 'candidate')[0], $krow($K3)['status_reason']],
    ['reason_required', 'reason_required', 'comp_status', 200, 200, 200, 'עסקה בין קרובים', ['meta' => ['from' => 'candidate', 'to' => 'excluded'], 'reason' => 'עסקה בין קרובים'], 'nothing_to_change', 200, 200, null],
    '15-A4 החרגה או המתנה לבירור בלי נימוק ← נדחית; בחירה — נימוק רשות; כל מעבר ביומן עם הקודם והחדש; עסקה שהוחרגה נשארת');

// 15-A4: התאמה בלי נימוק נדחית; ושאר הקלט
$okAdj = ['label' => 'קומה', 'kind' => 'amount', 'value' => '100000', 'sign' => 'plus', 'applies_to' => 'total', 'reason' => 'קומה גבוהה יותר'];
eq([code($adjSave($avi, $K1, ['reason' => ''] + $okAdj)), code($adjSave($avi, $K1, array_diff_key($okAdj, ['reason' => 1]))), code($adjSave($avi, $K1, ['label' => ' '] + $okAdj)), code($adjSave($avi, $K1, ['value' => '-5'] + $okAdj)),
    code($adjSave($avi, $K1, ['value' => '0'] + $okAdj)), code($adjSave($avi, $K1, ['kind' => 'ratio'] + $okAdj)), code($adjSave($avi, $K1, ['sign' => 'times'] + $okAdj)), code($adjSave($avi, $K1, array_diff_key($okAdj, ['applies_to' => 1]))),
    code($adjSave($avi, $K1, ['applies_to' => 'both'] + $okAdj)), code($adjSave($avi, $K1, ['kind' => 'percent', 'value' => '1000.5'] + $okAdj)), code($adjSave($avi, $K1, ['kind' => 'percent', 'value' => '5', 'basis' => 'median'] + $okAdj)),
    (int)apr_val('SELECT COUNT(*) FROM comp_adjustments'), $raises("INSERT INTO comp_adjustments (pid, comp_id, sort, label, kind, value, sign, basis, applies_to, reason, created_at, updated_at) VALUES ('x', 1, 1, 'a', 'amount', 5, 'plus', 'base', 'total', '', 'n', 'n')") !== 'ok'],
    ['reason_required', 'reason_required', 'comp_adj_label', 'comp_adj_value', 'comp_adj_value', 'comp_adj_kind', 'comp_adj_kind', 'comp_adj_applies', 'comp_adj_applies', 'comp_adj_value', 'comp_adj_kind', 0, true],
    '15-A4 התאמה בלי נימוק ← נדחית (גם במסד); ערך שלילי או אפס, סוג או סימן לא מוכרים, וסכום בלי בסיס — נדחים; שום דבר לא נכתב');

// קבוצה, התאמות, והחישוב דרך ה-API — דוגמת האפיון (15-A1), ואז שינוי התאמה (15-A3)
$G = call('POST', 'comp-set-save', ['case' => $C, 'name' => 'כללי', 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => 'none'], $avi);
eq([$G[0], code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'per_sqm', 'method' => 'mean', 'rounding' => '1000'], $avi)), code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'total', 'method' => 'ai', 'rounding' => 'none'], $avi)),
    code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => '50'], $avi))], [200, 'comp_set_area_kind', 'comp_set_field', 'comp_set_field'], 'קבוצה: למ"ר חייבת סוג שטח; שיטה ועיגול — רק מהרשימה');
$G = $G[1]['set'];
$setView = fn(string $as, string $g) => call('GET', 'comp-set', [], $as, ['set' => $g]);
call('POST', 'comp-correct', ['comp' => $K2, 'rev' => $rev($K2), 'changes' => ['price' => '2200000'], 'reason' => 'עיגול שגוי במקור'], $avi);
$A1 = $adjSave($avi, $K1, $okAdj)[1]['adj'];
$A2 = $adjSave($avi, $K1, ['label' => 'מצב', 'kind' => 'amount', 'value' => '30000', 'sign' => 'minus', 'applies_to' => 'total', 'reason' => 'משופצת פחות'])[1]['adj'];
$adjSave($dana, $K2, ['label' => 'מצב', 'kind' => 'amount', 'value' => '50000', 'sign' => 'minus', 'applies_to' => 'total', 'reason' => 'דורשת שיפוץ']);
$adjSave($dana, $K2, ['label' => 'קומה', 'kind' => 'amount', 'value' => '20000', 'sign' => 'plus', 'applies_to' => 'total', 'reason' => 'קומה נמוכה יותר']);
$sv = $setView($avi, $G)[1];
$byComp = array_column($sv['rows'], null, null);
$rowOf = fn(array $sv, string $k) => array_values(array_filter($sv['rows'], fn($r) => $r['comp']['pid'] === $k))[0] ?? null;
eq([$rowOf($sv, $K1)['calc']['adjusted'], $rowOf($sv, $K2)['calc']['adjusted']['nis'], $sv['summary']['mean'], $sv['summary']['rounded'], $sv['summary']['selected'], $sv['summary']['counted'],
    array_map(fn($s) => [$s['label'], $s['sign'], $s['value'], $s['delta']['nis'], $s['running']['nis']], $rowOf($sv, $K1)['calc']['steps']), $rowOf($sv, $K1)['calc']['base']['nis'], $sv['summary']['caveats'][0], $sv['summary']['small_under'], $sv['set']['conclusion']],
    [['agorot' => '207000000', 'nis' => '2070000', 'exact' => null], '2170000', ['agorot' => '212000000', 'nis' => '2120000', 'exact' => null], '212000000', 2, 2,
        [['קומה', 'plus', '100000', '100000', '2100000'], ['מצב', 'minus', '30000', '-30000', '2070000']], '2000000', ['code' => 'small_sample', 'n' => 2], 4, null],
    '15-A1 דרך ה-API: 2,070,000; 2,170,000; ממוצע 2,120,000; הנוסחה שורה-שורה; 15-B5 שתי עסקאות ← "מדגם קטן" (הסף מהפרופיל); המסקנה ריקה');
$up = $adjSave($avi, $K1, ['adj' => $A1, 'label' => 'קומה', 'kind' => 'amount', 'value' => '150000', 'sign' => 'plus', 'applies_to' => 'total', 'reason' => 'אחרי בדיקה חוזרת']);
$sv2 = $setView($avi, $G)[1];
$au = $audit('comp.adj_update', $K1)[0];
eq([$up[0], $rowOf($sv2, $K1)['calc']['adjusted']['nis'], $sv2['summary']['mean']['nis'], $sv2['summary']['rounded'], $au['meta']['from']['value'], $au['meta']['to']['value'], $au['reason'], $audit('comp.adj_add', $K1)[0]['meta']['to']['value'],
    code($adjSave($avi, $K1, ['adj' => $A1, 'label' => 'קומה', 'kind' => 'amount', 'value' => '150000', 'sign' => 'plus', 'applies_to' => 'total', 'reason' => 'אחרי בדיקה חוזרת']))],
    [200, '2120000', '2145000', '214500000', '100000', '150000', 'אחרי בדיקה חוזרת', '100000', 'nothing_to_change'], '15-A3 שינוי התאמה ← התוצאה המותאמת והסיכום מתעדכנים מיד, והשינוי ביומן עם הערך הקודם והחדש');
// סדר: 15-A5 דרך ה-API — אחוז על השוטף
$A3 = $adjSave($avi, $K1, ['label' => 'זמן', 'kind' => 'percent', 'value' => '10', 'sign' => 'plus', 'basis' => 'running', 'reason' => 'עליית מחירים'])[1]['adj'];
$before = $rowOf($setView($avi, $G)[1], $K1)['calc']['adjusted']['nis'];
$ord = call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A3, $A1, $A2]], $avi);
$after = $rowOf($setView($avi, $G)[1], $K1)['calc'];
eq([$before, $ord[0], $after['adjusted']['nis'], array_column($after['steps'], 'label'), $audit('comp.adj_order', $K1)[0]['meta'], code(call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A3, $A1]], $avi)),
    code(call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A3, $A1, $A1]], $avi)), code(call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A3, $A1, $A2]], $avi))],
    ['2332000', 200, '2320000', ['זמן', 'קומה', 'מצב'], ['from' => [$A1, $A2, $A3], 'to' => [$A3, $A1, $A2]], 'comp_adj_order', 'comp_adj_order', 'nothing_to_change'],
    '§10.7 / 15-A5 שינוי סדר משנה את התוצאה כשיש אחוז על השוטף (2,332,000 ← 2,320,000), והסדר ביומן; רשימה חלקית או כפולה נדחית');
$del = call('POST', 'comp-adj-delete', ['comp' => $K1, 'rev' => $rev($K1), 'adj' => $A3], $avi);
call('POST', 'comp-adj-save', ['comp' => $K1, 'rev' => $rev($K1), 'adj' => $A1] + $okAdj, $avi);
eq([$del[0], $audit('comp.adj_delete', $K1)[0]['meta']['from']['label'], $setView($avi, $G)[1]['summary']['rounded'], code(call('POST', 'comp-adj-delete', ['comp' => $K1, 'rev' => $rev($K1), 'adj' => $A3], $avi)), count($view($avi, $K1)[1]['adjustments']),
    array_column($view($avi, $K1)[1]['history'], 'action') === array_column(apr_rows("SELECT action FROM audit_log WHERE entity_type = 'comp' AND entity_pid = ? ORDER BY id", [$K1]), 'action')],
    [200, 'זמן', '212000000', 'not_found', 2, true], 'התאמה שנמחקה יוצאת מהחישוב, והערך שהיה נרשם ביומן; ההיסטוריה של העסקה מוחזרת במלואה');

// משקולות — 15-A8 דרך ה-API, ו-§10.13
$wSet = fn(string $as, string $k, mixed $w) => call('POST', 'comp-weight', ['set' => $G, 'comp' => $k, 'rev' => $grev($G), 'weight' => $w], $as);      // 16: המשקל הוא של העסקה בקבוצה
call('POST', 'comp-set-save', ['set' => $G, 'rev' => $grev($G), 'name' => 'כללי', 'unit_basis' => 'total', 'method' => 'weighted', 'rounding' => 'none'], $avi);
$blocked0 = $setView($avi, $G)[1]['summary'];
$wSet($avi, $K1, '1');
$blocked1 = $setView($avi, $G)[1]['summary'];
$wSet($avi, $K2, '3');
$w13 = $setView($avi, $G)[1];
$wSet($avi, $K1, '25');
$wSet($avi, $K2, '75');
$w2575 = $setView($avi, $G)[1];
eq([[$blocked0['weights_block'], $blocked0['result'], $blocked0['mean']['nis']], [$blocked1['weights_block'], $blocked1['rounded']], $w13['summary']['rounded'], $w2575['summary']['rounded'], $rowOf($w13, $K2)['share'], $rowOf($w2575, $K1)['share'],
    code($wSet($avi, $K1, '-1')), code($wSet($avi, $K1, 'abc')), $wSet($avi, $K1, '0')[0], $setView($avi, $G)[1]['summary']['rounded'], $wSet($avi, $K2, 0)[0], $setView($avi, $G)[1]['summary']['weights_block'], $audit('comp.weight', $K1)[0]['meta']],
    [['weight_missing', null, '2120000'], ['weight_missing', null], '214500000', '214500000', '75.00', '25.00', 'comp_weight', 'comp_weight', 200, '217000000', 200, 'weights_zero', ['set' => $G, 'from' => null, 'to' => '1']],
    '15-A8 משקולות 1, 3 נותנות אותה תוצאה כמו 25, 75; בלי משקל לאחת ← נחסם, והממוצע הרגיל עדיין מוצג; §10.13 משקל שלילי נדחה, ואפס לכולן נחסם');
$wSet($avi, $K1, null);
$wSet($avi, $K2, null);
call('POST', 'comp-set-save', ['set' => $G, 'rev' => $grev($G), 'name' => 'כללי', 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => '10000'], $avi);

// 15-B6: המסקנה אינה מתמלאת לבד; בלי נימוק נדחית; 15-D4 היא נפרדת מהממוצע
$afterAll = apr_row('SELECT conclusion_value, conclusion_reason FROM comp_sets WHERE pid = ?', [$G]);
$conc = fn(string $as, mixed $v, ?string $reason = null) => call('POST', 'comp-set-conclude', ['set' => $G, 'rev' => $grev($G), 'value' => $v] + ($reason !== null ? ['reason' => $reason] : []), $as);
eq([$afterAll, code($conc($avi, '2100000')), code($conc($avi, '2100000', ' ')), code($conc($avi, '-1', 'x')), $conc($avi, '2100000', 'קרוב לעסקה א, הדומה יותר')[0]],
    [['conclusion_value' => null, 'conclusion_reason' => null], 'reason_required', 'reason_required', 'comp_value', 200], '15-B6 אחרי כל הפעולות (עסקאות, התאמות, משקולות, שיטה) המסקנה עדיין ריקה; שמירתה בלי נימוק נדחית');
$sc = $setView($tal, $G)[1];
eq([$sc['set']['conclusion']['value'], $sc['set']['conclusion']['reason'], $sc['set']['conclusion']['by'], $sc['summary']['rounded'], $sc['summary']['unrounded'], $sc['summary']['mean']['nis'], $sc['summary']['median']['nis'], $sc['summary']['min']['nis'], $sc['summary']['max']['nis'],
    $sc['summary']['date_from'], $sc['summary']['date_to'], $audit('comp.set_conclude', $G)[0]['meta'], code(call('POST', 'comp-set-save', ['set' => $G, 'rev' => $grev($G), 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none'], $avi)),
    $raises("UPDATE comp_sets SET conclusion_value = 5, conclusion_reason = NULL, rev = rev + 1 WHERE pid = ?", [$G]) !== 'ok'],
    ['2100000', 'קרוב לעסקה א, הדומה יותר', 'אבי', '212000000', '212000000', '2120000', '2120000', '2070000', '2170000', '2025-01-15', '2025-03-01', ['from' => null, 'to' => '2100000', 'unit_basis' => 'total'], 'comp_set_concluded', true],
    '15-D4 המסקנה (2,100,000) נשמרת בנפרד מהממוצע (2,120,000) ועם נימוק, מי ומתי; הטווח, החציון וטווח התאריכים לצידה; מסקנה אינה עוברת ליחידה אחרת בשקט; המסד דוחה מסקנה בלי נימוק');
// שינוי בעסקה אחרי המסקנה: המסקנה אינה משתנה, והחישוב כן (§6.6)
$adjSave($avi, $K2, ['label' => 'זמן', 'kind' => 'percent', 'value' => '1', 'sign' => 'plus', 'reason' => 'עליית מחירים']);
eq([apr_val('SELECT conclusion_value FROM comp_sets WHERE pid = ?', [$G]), $setView($avi, $G)[1]['summary']['unrounded']], [210000000, '213100000'], '§6.6 שינוי בעסקה אחרי המסקנה: החישוב מתעדכן, המסקנה של השמאי אינה נוגעת');
eq([$conc($avi, null)[0], apr_row('SELECT conclusion_value, conclusion_by FROM comp_sets WHERE pid = ?', [$G]), $audit('comp.set_conclude', $G)[1]['meta']['from']], [200, ['conclusion_value' => null, 'conclusion_by' => null], '2100000'], 'מחיקת מסקנה נרשמת ביומן עם הערך שהיה');

// קבוצה למ"ר דרך ה-API: 15-A2 ו"השטח הנישום" (§6.5, §6.6)
$K4 = $add($avi, $C, ['deal_date' => '2024-12-01', 'price' => '1800000', 'address_text' => 'בלי שטח 1', 'location_precision' => 'street'])[1]['comp'];
$K5 = $add($avi, $C, ['deal_date' => '2024-12-02', 'price' => '1800000', 'address_text' => 'שטח אפס 2', 'location_precision' => 'street', 'area' => '0', 'area_kind' => 'registered'])[1]['comp'];
$K6 = $add($avi, $C, ['deal_date' => '2024-12-03', 'price' => '1800000', 'address_text' => 'סוג לא ידוע 3', 'location_precision' => 'street', 'area' => '90'])[1]['comp'];
$K7 = $add($avi, $C, ['deal_date' => '2024-12-04', 'price' => '900', 'currency' => 'USD', 'address_text' => 'דולר 4', 'location_precision' => 'street', 'area' => '90', 'area_kind' => 'registered'])[1]['comp'];
foreach ([$K4, $K5, $K6, $K7] as $k) $status($avi, $k, 'selected');
$F = call('POST', 'fact-add', ['case' => $C, 'attribute' => 'area', 'value' => '85.5', 'unit' => 'sqm', 'area_kind' => 'registered', 'source' => ['kind' => 'user', 'note' => 'מהנסח']], $avi)[1]['fact']['pid'];
$FB = call('POST', 'fact-add', ['case' => $C, 'attribute' => 'area', 'value' => '97', 'unit' => 'sqm', 'area_kind' => 'built', 'source' => ['kind' => 'user', 'note' => 'מדידה']], $avi)[1]['fact']['pid'];
$FF = call('POST', 'fact-add', ['case' => $C, 'attribute' => 'floor', 'value' => '4', 'source' => ['kind' => 'user', 'note' => 'ביקור']], $avi)[1]['fact']['pid'];
$GS = call('POST', 'comp-set-save', ['case' => $C, 'name' => 'למ"ר', 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => '100', 'subject_area_fact' => $F], $avi);
eq([$GS[0], code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => '100', 'subject_area_fact' => $FB], $avi)),
    code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => '100', 'subject_area_fact' => $FF], $avi)),
    code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => '100', 'subject_area_fact' => apr_pid()], $avi))],
    [200, 'comp_subject', 'comp_subject', 'not_found'], 'השטח הנישום של קבוצה: רק נתון שטח של התיק מאותו סוג שטח — שטח בנוי, קומה, או נתון שלא קיים נדחים');
$GS = $GS[1]['set'];
$ss = $setView($avi, $GS)[1];
$reasons = array_column($ss['summary']['skipped'], 'reason', 'pid');
ksort($reasons);
$wantReasons = [$K2 => 'area_kind_mismatch', $K4 => 'area_missing', $K5 => 'area_invalid', $K6 => 'area_kind_unknown', $K7 => 'currency'];
ksort($wantReasons);
eq([$reasons, $rowOf($ss, $K4)['calc']['adjusted'], $rowOf($ss, $K5)['calc']['base'], $rowOf($ss, $K1)['calc']['base'], $ss['summary']['selected'], $ss['summary']['counted'], $ss['summary']['mean'], $ss['summary']['result_note'],
    in_array(['code' => 'not_comparable', 'n' => 5], $ss['summary']['caveats'], true), in_array(['code' => 'small_sample', 'n' => 1], $ss['summary']['caveats'], true)],
    [$wantReasons, null, null, ['agorot' => '2500000', 'nis' => '25000', 'exact' => null], 6, 1, null, 'need_two', true, true],
    '15-A2 דרך ה-API: בלי שטח · שטח 0 · סוג לא ידוע · סוג שונה ← אין מחיר למ"ר (null) ויש סיבה, והעסקה מחוץ לסיכום; §10.8 מטבע אחר אינו נכנס; נשארה עסקה אחת ← אין ממוצע');
eq([array_column($view($avi, $K1)[1]['calcs'], null, null)[1]['calc']['steps'], array_column($view($avi, $K4)[1]['calcs'], 'calc')[1]['reason'], count($view($avi, $K1)[1]['calcs'])], [[], 'area_missing', 2],
    'מסך העסקה מקבל את הנוסחה מול כל קבוצה: התאמות בסכום של "מחיר כולל" אינן חלות על המחיר למ"ר');
call('POST', 'comp-set-conclude', ['set' => $GS, 'rev' => $grev($GS), 'value' => '24800', 'reason' => 'לפי העסקה היחידה שניתנת להשוואה'], $avi);
$sub = $setView($avi, $GS)[1]['subject'];
eq([$sub['area'], $sub['area_kind'], $sub['total'], $sub['stale'], $sub['fact']['pid'], $sub['fact']['source']['note'] ?? null, apr_fact_uses_of(['id' => apr_val('SELECT id FROM cases WHERE pid = ?', [$C])], 'calc', $GS)],
    ['85.5', 'registered', ['agorot' => '212040000', 'nis' => '2120400', 'exact' => null], false, $F, 'מהנסח', [['fact' => $F, 'stale' => false, 'stale_at' => null]]],
    '§6.5 מסקנה למ"ר × שטח נישום = 24,800 × 85.5 = 2,120,400 — חישוב עזר, עם השטח, סוגו ומקורו; הקבוצה רשומה כמי שנשענת על הנתון');
call('POST', 'fact-correct', ['fact' => $F, 'rev' => (int)apr_val('SELECT rev FROM facts WHERE pid = ?', [$F]), 'value' => '86', 'unit' => 'sqm', 'area_kind' => 'registered', 'note' => 'תיקון'], $avi);
$sub2 = $setView($avi, $GS)[1]['subject'];
$gsBody = ['set' => $GS, 'name' => 'למ"ר', 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => '100', 'subject_area_fact' => $F];
$same = code(call('POST', 'comp-set-save', ['rev' => $grev($GS)] + $gsBody, $avi));
$plain = call('POST', 'comp-set-save', ['rev' => $grev($GS), 'rounding' => '1000'] + $gsBody, $avi);
$stillStale = $setView($avi, $GS)[1]['subject']['stale'];
$noName = call('POST', 'comp-set-save', ['rev' => $grev($GS)] + array_diff_key($gsBody, ['name' => 1]), $avi);
$nameKept = apr_val('SELECT name FROM comp_sets WHERE pid = ?', [$GS]);
$re = call('POST', 'comp-set-save', ['rev' => $grev($GS), 'recheck' => true] + $gsBody, $avi);
eq([$sub2['stale'], $sub2['area'], $sub2['total']['nis'], $same, $plain[0], $stillStale, $noName[0], $nameKept, $re[0], $setView($avi, $GS)[1]['subject']['stale'], $audit('comp.set_recheck', $GS)[0]['meta'] ?? null, count($audit('comp.set_recheck', $GS))],
    [true, '86', '2132800', 'nothing_to_change', 200, true, 200, 'למ"ר', 200, false, ['subject_area_fact' => $F, 'area' => '86'], 1],
    '§6.6 השטח הנישום השתנה ← הקבוצה מסומנת stale; 🟡4 שמירה רגילה של ההגדרות אינה מורידה את הסימון — רק "נבדק מחדש" מפורש, שנרשם ביומן; ⚪ עדכון בלי שם אינו דורס את השם');

// "דומה / שונה" (§4.3, 15-B2): רק שדות ששני הצדדים ידועים בהם
$sim = $view($avi, $K1)[1]['similar'];
$simRows = array_column($sim['rows'], null, 'field');
$unk = array_column($sim['unknown'], 'side', 'field');
eq([array_keys($simRows), $simRows['floor'], $simRows['area'], $unk, array_intersect(array_keys($simRows), array_keys($unk))],
    [['floor', 'area'], ['field' => 'floor', 'comp' => 2, 'subject' => '4', 'verdict' => 'differs'], ['field' => 'area', 'comp' => '80', 'subject' => '86', 'area_kind' => 'registered', 'verdict' => 'differs'],
        ['property_type' => 'both', 'floors_total' => 'both', 'rooms' => 'both', 'build_year' => 'both', 'condition' => 'comp'], []],
    '15-B2 "דומה / שונה" כולל רק שדות ששני הצדדים ידועים בהם (קומה 2 מול 4; שטח 80 מול 86, שניהם רשום); שדה חסר מופיע רק כ"לא ידוע"');
$sim2 = $view($avi, $K2)[1]['similar'];
eq([array_column($sim2['rows'], 'field'), array_column($sim2['unknown'], 'side', 'field')['area'], array_column($view($avi, $K4)[1]['similar']['unknown'], 'side', 'field')['area']], [[], 'area_kind', 'comp'],
    'שטח ברוטו בעסקה מול שטח רשום ובנוי בנכס ← אינו מושווה (אין המרה בין סוגי שטח); עסקה בלי שטח ← "לא ידוע בעסקה"');

// 15-B4 / §10.11: כפילות מסומנת, ושתי העסקאות קיימות
$D1 = $add($avi, $C, ['deal_date' => '2025-03-01', 'price' => '2000000', 'address_text' => ' הרצל  10 ', 'locality' => 'תל אביב'], ['search' => $S2]);
$D2 = $add($avi, $C, ['deal_date' => '2025-01-15', 'price' => '2200000', 'gush' => '6158', 'helka' => '30']);
$D3 = $add($avi, $C, ['deal_date' => '2025-01-15', 'price' => '2200000', 'gush' => '6158', 'helka' => '31']);
$D4 = $add($avi, $C, ['price' => '2000000', 'address_text' => 'הרצל 10']);
$total0 = (int)apr_val('SELECT COUNT(*) FROM comps WHERE case_id = (SELECT id FROM cases WHERE pid = ?) AND deleted_at IS NULL', [$C]);
eq([$D1[1]['dup_of'], $D2[1]['dup_of'], $D3[1]['dup_of'], $D4[1]['dup_of'], $view($avi, $D1[1]['comp'])[1]['comp']['dup_of'], $krow($K1)['deleted_at'], $krow($D1[1]['comp'])['status'], $total0],
    [$K1, $K2, null, null, $K1, null, 'candidate', 11], '15-B4 אותה עסקה משני חיפושים: dup_of מסומן (לפי כתובת, או לפי גוש וחלקה), ושתי העסקאות קיימות; חלקה אחרת או בלי תאריך — אין חשד');
$dl = call('POST', 'comp-delete', ['comp' => $K1, 'rev' => $rev($K1), 'reason' => 'x'], $tal);
$dd = call('POST', 'comp-delete', ['comp' => $D4[1]['comp'], 'rev' => $rev($D4[1]['comp']), 'reason' => 'הוזנה בטעות'], $tal);
eq([code($dl), code(call('POST', 'comp-delete', ['comp' => $D3[1]['comp'], 'rev' => $rev($D3[1]['comp'])], $tal)), $dd[0], code($view($avi, $D4[1]['comp'])), $krow($D4[1]['comp'])['delete_reason'],
    $raises('UPDATE comps SET note = ?, rev = rev + 1 WHERE pid = ?', ['x', $D4[1]['comp']])], ['forbidden', 'reason_required', 200, 'not_found', 'הוזנה בטעות', 'comps: frozen'],
    'מחיקה: רכה, עם נימוק; עסקה שנבחרה — רק מי שרשאי לבחור; עסקה שנמחקה אינה מוחזרת ואינה משתנה עוד');

// 🟡3: מי שרק מזין מוחק רק עסקה מועמדת בלי התאמות ובלי משקל
$E = [];
foreach (['excluded', 'pending', 'weight', 'adj', 'plain'] as $i => $what) {
    $E[$what] = $add($tal, $C, ['price' => (string)(700000 + $i), 'deal_date' => '2023-01-0' . ($i + 1)])[1]['comp'];
    if ($what === 'excluded' || $what === 'pending') $status($avi, $E[$what], $what, 'נימוק של השמאי');
    if ($what === 'weight') { $status($avi, $E[$what], 'selected'); $wSet($avi, $E[$what], '2'); $status($avi, $E[$what], 'candidate'); }      // משקל נקבע לעסקה שנבחרה, בקבוצה; אחר כך חזרה למועמדת
    if ($what === 'adj') $adjSave($avi, $E[$what], $okAdj);
}
$delAs = fn(string $as, string $k) => call('POST', 'comp-delete', ['comp' => $k, 'rev' => $rev($k), 'reason' => 'ניקוי'], $as);
eq([code($delAs($tal, $E['excluded'])), code($delAs($tal, $E['pending'])), code($delAs($tal, $E['weight'])), code($delAs($tal, $E['adj'])), $delAs($tal, $E['plain'])[0], $krow($E['excluded'])['deleted_at'], $krow($E['excluded'])['status_reason'],
    $delAs($dana, $E['excluded'])[0], $delAs($dana, $E['pending'])[0], $delAs($avi, $E['weight'])[0], $delAs($avi, $E['adj'])[0]],
    ['forbidden', 'forbidden', 'forbidden', 'forbidden', 200, null, 'נימוק של השמאי', 200, 200, 200, 200],
    '🟡3 מי שיש לו רק edit אינו מוחק עסקה שהוחרגה או ממתינה (הנימוק של השמאי נשאר), ולא מועמדת עם משקל או עם התאמה; שמאי בתיק — כן');

// ── ייבוא (§4.2, §10.9, §10.10) ──
$csv = "\xEF\xBB\xBFתאריך;מחיר;גוש;חלקה;כתובת;שטח;קומה;הערת המקור;עמודה שלא מופתה\n"
    . "03/04/2025;\"1,850,000 ₪\";6158;41;\"דיזנגוף 5; דירה 3\";78.400000000000006;-1;נמכרה מהר;סודי\n"
    . "2025-02-10;2,010,500.5;6158;42;אבן גבירול 9;שמונים;3;;\n"
    . "13/13/2025;1900000;6158;43;;90;2;;\n"
    . ";;;;;;;;רק עמודה שלא מופתה\n"
    . "15.01.25;2200000;6158;30;;88;;;\n";
$map = ['0' => 'deal_date', '1' => 'price', '2' => 'gush', '3' => 'helka', '4' => 'address_text', '5' => 'area', '6' => 'floor', '7' => 'extra'];
$imp = ['case' => $C, 'text' => $csv, 'name' => 'עסקאות.csv', 'mapping' => $map, 'defaults' => ['area_kind' => 'registered', 'location_precision' => 'street']];
$pv0 = call('POST', 'comp-import-preview', ['case' => $C, 'text' => $csv], $tal);
$pv = call('POST', 'comp-import-preview', $imp, $tal);
$nBefore = (int)apr_val('SELECT COUNT(*) FROM comps');
eq([$pv0[0], array_column($pv0[1]['columns'], 'header'), $pv0[1]['columns'][1]['samples'], $pv0[1]['total'], $pv0[1]['saved_mapping'], isset($pv0[1]['rows']), $pv[1]['total'], $pv[1]['warn_rows'], $pv[1]['empty_rows'],
    [$pv[1]['rows'][0]['values']['deal_date'], $pv[1]['rows'][0]['values']['price'], $pv[1]['rows'][0]['values']['area'], $pv[1]['rows'][0]['values']['floor'], $pv[1]['rows'][0]['values']['address_text'], (array)$pv[1]['rows'][0]['extra']],
    [$pv[1]['rows'][1]['values']['price'], $pv[1]['rows'][1]['values']['area'], $pv[1]['rows'][1]['values']['area_kind'], $pv[1]['rows'][1]['unparsed']], [$pv[1]['rows'][2]['values']['deal_date'], $pv[1]['rows'][2]['unparsed']], $nBefore],
    [200, ['תאריך', 'מחיר', 'גוש', 'חלקה', 'כתובת', 'שטח', 'קומה', 'הערת המקור', 'עמודה שלא מופתה'], ['1,850,000 ₪', '2,010,500.5', '1900000'], 5, null, false, 5, 2, 1,
        ['2025-04-03', '1850000', '78.4', -1, 'דיזנגוף 5; דירה 3', ['הערת המקור' => 'נמכרה מהר']], ['2010500.5', null, 'unknown', ['area']], [null, ['deal_date']], (int)apr_val('SELECT COUNT(*) FROM comps')],
    '15-D1 (חלק השרת) תצוגה מקדימה: עמודות ודוגמאות; אחרי מיפוי — השורות כפי שייובאו; §10.9 03/04/2025 = 3 באפריל (יום/חודש); תאריך או מספר שאי אפשר לפענח ← ריק, עם אזהרה; דבר לא נכתב');
eq([call('POST', 'comp-import-preview', ['date_order' => 'mdy'] + $imp, $tal)[1]['rows'][0]['values']['deal_date'], apr_comp_import_date('45291'), apr_comp_import_date('31/02/2025'), apr_comp_import_date('1/2/99'), apr_comp_import_date('2025-07-09T10:00:00'),
    apr_comp_import_num('1,234,567.895', 2), apr_comp_import_num('12,34', 2), apr_comp_import_num('1.5E6', 2), apr_comp_import_num(' 2 500 ', 0), apr_comp_import_num('-3', 0), apr_comp_import_num('-3', 0, true), apr_comp_import_num('0.005', 2)],
    ['2025-03-04', '2023-12-31', null, '1999-02-01', '2025-07-09', '1234567.9', null, null, '2500', null, '-3', '0.01'], '§10.9 סדר חודש/יום לפי בחירת המשתמש; מספר סידורי של Excel; תאריך בלתי אפשרי ← null; מספרים: מפרידי אלפים, עיגול בלי נקודה צפה, ובלי ניחוש');
eq([code(call('POST', 'comp-import', ['search' => $S2] + $imp, $tal)), code(call('POST', 'comp-import', ['search' => $S2, 'rights' => 'yes'] + $imp, $tal)), code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true, 'mapping' => ['7' => 'extra']] + $imp, $tal)),
    code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true, 'mapping' => ['0' => 'price', '1' => 'price']] + $imp, $tal)), code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true, 'mapping' => ['40' => 'price']] + $imp, $tal)),
    code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true, 'mapping' => ['0' => 'status']] + $imp, $tal)), code(call('POST', 'comp-import', ['search' => apr_pid(), 'rights' => true] + $imp, $tal)),
    code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true, 'text' => "\xFF\xFE\x00bad"] + $imp, $tal)), code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true, 'text' => "מחיר\n"] + $imp, $tal)), (int)apr_val('SELECT COUNT(*) FROM comps') - $nBefore],
    ['comp_import_rights', 'comp_import_rights', 'comp_import_mapping', 'comp_import_mapping', 'comp_import_mapping', 'comp_import_mapping', 'not_found', 'comp_import_format', 'comp_import_empty', 0],
    '§4.2 בלי אישור "יש לי זכות להשתמש בנתונים האלה" אין ייבוא; מיפוי חסר, כפול, מחוץ לטבלה או לשדה שאינו שדה ערך — נדחה; שום שורה לא נכתבה');
$do = call('POST', 'comp-import', ['search' => $S2, 'rights' => true] + $imp, $tal);
$imported = apr_rows("SELECT * FROM comps WHERE origin = 'import' ORDER BY id");
$raw1 = json_decode($imported[0]['raw'], true);
$sImp = array_column($list($avi, $C)[1]['searches'], null, 'pid')[$S2];
eq([$do[0], array_diff_key($do[1], ['ok' => 1]), count($imported), $raw1['row']['עמודה שלא מופתה'], $raw1['row']['מחיר'], $imported[0]['price'], $imported[0]['area'], $imported[0]['area_kind'], $imported[0]['location_precision'], json_decode($imported[0]['extra'], true), $imported[0]['status'],
    json_decode($imported[1]['raw'], true)['unparsed'], $imported[1]['area'], $imported[2]['deal_date'], $imported[3]['dup_of'] === $krow($K2)['id'], $sImp['import'], $sImp['rights_confirmed'], $sImp['comps'],
    apr_val('SELECT import_sha256 FROM comp_searches WHERE pid = ?', [$S2]) === hash('sha256', $csv), $audit('comp.import', $S2)[0]['meta'], code(call('POST', 'comp-import', ['search' => $S2, 'rights' => true] + $imp, $tal))],
    [200, ['imported' => 4, 'skipped' => 1, 'dups' => 1, 'unparsed_rows' => 2], 4, 'סודי', '1,850,000 ₪', 185000000, 7840, 'registered', 'street', ['הערת המקור' => 'נמכרה מהר'], 'candidate', ['area'], null, null, true,
        ['name' => 'עסקאות.csv', 'rows' => 4, 'file' => null], true, 5, true, ['rows' => 4, 'skipped' => 1, 'dups' => 1, 'unparsed_rows' => 2, 'kind' => 'text'], 'comp_import_done'],
    'ייבוא: כל שורה נשמרת ב-raw כפי שהגיעה (גם עמודה שלא מופתה); ערך שלא פוענח ← NULL; שורה ריקה מדולגת; כפילות מסומנת ואינה נמחקת; העסקאות "מועמדות" — המערכת אינה בוחרת; החיפוש נושא את המקור ואת אישור הזכות');
$pv2 = call('POST', 'comp-import-preview', ['case' => $C, 'text' => $csv], $tal)[1]['saved_mapping'];
eq([$pv2['mapping'], $pv2['date_order'], $pv2['defaults']['area_kind'], call('POST', 'comp-import-preview', ['case' => $CB, 'text' => $csv], $boaz)[1]['saved_mapping']], [$map, 'dmy', 'registered', null], '§4.2 המיפוי נשמר לפעם הבאה — למשרד הזה בלבד');
// הדבקה (טאבים), בלי כותרת; ותקרת 2,000 שורות
$S3 = call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'הדבקה'], $avi)[1]['search'];
$paste = "01/06/2024\t1500000\tרחוב א 1\n02/06/2024\t1600000\tרחוב ב 2\n";
$pp = call('POST', 'comp-import', ['case' => $C, 'search' => $S3, 'rights' => true, 'text' => $paste, 'has_header' => false, 'mapping' => ['0' => 'deal_date', '1' => 'price', '2' => 'address_text']], $avi);
$big = "מחיר\n" . str_repeat("1000\n", 2001);
$S4 = call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'קובץ גדול'], $avi)[1]['search'];
$ok2000 = call('POST', 'comp-import-preview', ['case' => $C, 'text' => "מחיר\n" . str_repeat("1000\n", 2000), 'mapping' => ['0' => 'price']], $avi);
eq([$pp[1]['imported'] ?? null, array_keys(json_decode(apr_val("SELECT raw FROM comps WHERE address_text = 'רחוב ב 2'"), true)['row']), code(call('POST', 'comp-import', ['case' => $C, 'search' => $S4, 'rights' => true, 'text' => $big, 'mapping' => ['0' => 'price']], $avi)),
    code(call('POST', 'comp-import-preview', ['case' => $C, 'text' => $big], $avi)) . '/' . code(call('POST', 'comp-import-preview', ['case' => $C, 'text' => str_repeat("1000\n", 2001), 'has_header' => false], $avi))
        . '/' . (call('POST', 'comp-import-preview', ['case' => $C, 'text' => str_repeat("1000\n", 2000), 'has_header' => false], $avi)[1]['total'] ?? '?'), $ok2000[1]['total'] ?? null, apr_val('SELECT import_sha256 FROM comp_searches WHERE pid = ?', [$S4])],
    [2, ['עמודה 1', 'עמודה 2', 'עמודה 3'], 'comp_import_too_many', 'comp_import_too_many/comp_import_too_many/2000', 2000, null], 'הדבקה מלוח (טאבים, בלי כותרת) — אותו מסלול; §10.10 יותר מ-2,000 שורות נדחה כולו (2,000 בדיוק — מותר)');

// 🟡2: שורות ריקות (גם ",") באמצע הקובץ אינן נספרות ואינן חותכות את הקובץ בשקט
$gap = "מחיר,כתובת\n" . str_repeat("1000,א\n", 1000) . str_repeat(",\n", 1300) . str_repeat("2000,ב\n", 500);
$gapBig = "מחיר,כתובת\n" . str_repeat("1000,א\n", 1000) . str_repeat(",\n", 1300) . str_repeat("2000,ב\n", 1001);
eq([call('POST', 'comp-import-preview', ['case' => $C, 'text' => $gap], $avi)[1]['total'] ?? null, code(call('POST', 'comp-import-preview', ['case' => $C, 'text' => $gapBig], $avi)),
    count(apr_comp_table_text("a,b\n" . str_repeat(" , \n\n", 3000) . "1,2\n"))], [1500, 'comp_import_too_many', 2],
    '🟡2 1,000 שורות + 1,300 שורות ריקות + 500 שורות ← כל 1,500 נקראות (לא 1,001); מעל 2,000 שורות אמיתיות — נדחה, לא נחתך');

// XLSX: דרך צינור הקליטה של 06, ונשמר כקובץ של התיק
function xlsx_bytes(array $rows): string
{
    $col = fn(int $i) => chr(65 + $i);
    $strings = [];
    $sheet = '';
    foreach ($rows as $r => $cells) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($cells as $i => $v) {
            if ($v === null) continue;
            if (is_int($v) || is_float($v)) $sheet .= '<c r="' . $col($i) . ($r + 1) . '"><v>' . $v . '</v></c>';
            else { $strings[] = $v; $sheet .= '<c r="' . $col($i) . ($r + 1) . '" t="s"><v>' . (count($strings) - 1) . '</v></c>'; }
        }
        $sheet .= '</row>';
    }
    $files = ['[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="a" sheetId="1"/></sheets></workbook>',
        'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . implode('', array_map(fn($s) => '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>', $strings)) . '</sst>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet . '</sheetData></worksheet>'];
    $out = '';
    $cd = '';
    foreach ($files as $name => $data) {
        $z = gzdeflate($data);
        $head = pack('vvvvvVVVvv', 20, 0, 8, 0, 0x21, crc32($data), strlen($z), strlen($data), strlen($name), 0);
        $cd .= "PK\x01\x02" . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, strlen($out)) . $name;
        $out .= "PK\x03\x04" . $head . $name . $z;
    }
    return $out . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($cd), strlen($out), 0);
}
$upload = function (string $as, string $case, string $bytes, string $name = 'deals.xlsx') {
    $t = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($t, $bytes);
    return call('POST', 'comp-import-upload', ['case' => $case, 'name' => $name, 'key' => 'k-' . bin2hex(random_bytes(6))], $as, [], ['env' => ['upload' => $t]]);
};
$xb = xlsx_bytes([['תאריך עסקה', 'תמורה', 'שטח', 'כתובת'], [45700, 1750000, 71.5, 'ביאליק 3'], ['2025-05-06', 1810000, null, 'ביאליק 7']]);
$xu = $upload($tal, $C, $xb);
$im = imagecreatetruecolor(4, 4);
ob_start(); imagepng($im); $png = (string)ob_get_clean();
$S5 = call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'קובץ אקסל'], $avi)[1]['search'];
$xp = call('POST', 'comp-import-preview', ['case' => $C, 'file' => $xu[1]['file'] ?? '', 'mapping' => ['0' => 'deal_date', '1' => 'price', '2' => 'area', '3' => 'address_text']], $tal);
$xi = call('POST', 'comp-import', ['case' => $C, 'search' => $S5, 'file' => $xu[1]['file'] ?? '', 'rights' => true, 'mapping' => ['0' => 'deal_date', '1' => 'price', '2' => 'area', '3' => 'address_text'], 'defaults' => ['area_kind' => 'built']], $tal);
$sX = array_column($list($avi, $C)[1]['searches'], null, 'pid')[$S5];
eq([$xu[0], apr_val('SELECT mime FROM files WHERE pid = ?', [$xu[1]['file'] ?? '']), array_column($xp[1]['columns'] ?? [], 'header'), [$xp[1]['rows'][0]['values']['deal_date'] ?? null, $xp[1]['rows'][0]['values']['price'] ?? null, $xp[1]['rows'][0]['values']['area'] ?? null],
    [$xp[1]['rows'][1]['values']['deal_date'] ?? null, $xp[1]['rows'][1]['values']['area']], $xi[1]['imported'] ?? null, $sX['import']['file'], $sX['import']['name'], code($upload($tal, $C, $png, 'a.png')), code($upload($tal, $CB, $xb)),
    code(call('POST', 'comp-import-preview', ['case' => $C, 'file' => apr_val("SELECT pid FROM files WHERE mime <> ? LIMIT 1", [APR_MIME_XLSX]) ?: apr_pid()], $tal))],
    [200, APR_MIME_XLSX, ['תאריך עסקה', 'תמורה', 'שטח', 'כתובת'], ['2025-02-12', '1750000', '71.5'], ['2025-05-06', null], 2, $xu[1]['file'] ?? null, 'deals.xlsx', 'comp_import_type', 'not_found', 'not_found'],
    'XLSX עובר את צינור הקליטה של 06 ונשמר כקובץ של התיק (file_id); תאריך כמספר סידורי מפוענח; תא ריק ← null; קובץ שאינו XLSX נדחה; העלאה לתיק של משרד אחר ← 404');
$GLOBALS['apr_file_visible'] = fn() => false;
$hidden = [code(call('POST', 'comp-import-preview', ['case' => $C, 'file' => $xu[1]['file']], $tal)), array_column($list($avi, $C)[1]['searches'], null, 'pid')[$S5]['import']['file']];
unset($GLOBALS['apr_file_visible']);
eq($hidden, ['not_found', null], 'קובץ ייבוא שאינני רשאי לראות (שיתוף ברמת פריט, פרוסה 05): אינו נקרא לייבוא, והמזהה שלו אינו מוחזר בחיפוש');
$xxe = str_replace('<sst ', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><sst ', xlsx_bytes([['a'], ['b']]));
// הקובץ עם ה-DOCTYPE נבנה מחדש כדי שה-CRC והאורכים יתאימו
$xxeFile = (function () { $t = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($t, '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><sst><si><t>&e;</t></si></sst>'); return $t; })();
$xxeErr = (function () use ($xxeFile) { try { apr_comp_xml((string)file_get_contents($xxeFile)); return 'parsed'; } catch (AprError $e) { return $e->errorCode; } finally { @unlink($xxeFile); } })();
$xmlTry = function (string $xml): string { try { apr_comp_xml($xml); return 'parsed'; } catch (AprError $e) { return $e->errorCode; } };
$u16 = "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><sst><si><t>&e;</t></si></sst>', 'UTF-16LE', 'UTF-8');
eq([$xxeErr, $xmlTry($u16), $xmlTry('<?xml version="1.0" encoding="ISO-8859-1"?><sst/>'), $xmlTry('<?xml version="1.0" encoding="utf-8"?><sst/>'), $xmlTry('<sst><si><t>שלום</t></si></sst>')],
    ['comp_xlsx', 'comp_xlsx', 'comp_xlsx', 'parsed', 'parsed'], 'XML עם DOCTYPE או ישות חיצונית אינו מפוענח כלל; ⚪ XML שאינו UTF-8 (UTF-16, או הכרזת קידוד אחרת) נדחה — הסינון אינו נעקף');

// ── הרשאות (§9) ──
// 15-C4: עובדת משרד עם edit (טל) ומתמחה עם edit (נועה) מזינות ומתקנות — ואינן בוחרות, מחריגות, מתאימות, משקללות או מסיקות
$T1 = $add($tal, $C, ['price' => '1000000', 'deal_date' => '2024-05-05'])[1]['comp'];
$deny = [];
foreach ([$tal, $noa] as $who) {
    $deny[] = [code($status($who, $T1, 'selected')), code($status($who, $T1, 'excluded', 'נימוק')), code($adjSave($who, $T1, $okAdj)), code(call('POST', 'comp-adj-delete', ['comp' => $K1, 'rev' => $rev($K1), 'adj' => $A1], $who)),
        code(call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A2, $A1]], $who)), code(call('POST', 'comp-weight', ['set' => $G, 'comp' => $T1, 'rev' => $grev($G), 'weight' => '1'], $who)),
        code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => 'none'], $who)), code(call('POST', 'comp-set-save', ['set' => $G, 'rev' => $grev($G), 'unit_basis' => 'total', 'method' => 'median', 'rounding' => 'none'], $who)),
        code(call('POST', 'comp-set-conclude', ['set' => $G, 'rev' => $grev($G), 'value' => '1', 'reason' => 'x'], $who)), code(call('POST', 'comp-set-delete', ['set' => $G, 'rev' => $grev($G)], $who)),
        call('POST', 'comp-note', ['comp' => $T1, 'rev' => $rev($T1), 'note' => 'הערה ' . bin2hex(random_bytes(2))], $who)[0], $list($who, $C)[1]['perms']];
}
eq([$deny[0], $deny[0] === $deny[1], $krow($T1)['status'], apr_val('SELECT method FROM comp_sets WHERE pid = ?', [$G]), (int)apr_val('SELECT COUNT(*) FROM comp_adjustments WHERE comp_id = ?', [$krow($T1)['id']])],
    [['forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 200, ['write' => true, 'judge' => false]], true, 'candidate', 'mean', 0],
    '15-C4 עובדת משרד ומתמחה עם edit: מזינות ומעירות — ואינן בוחרות, מחריגות, מתאימות, משנות סדר, משקללות, קובעות שיטה או מסקנה (403), ושום דבר לא נכתב');
// בלי edit: צפייה בלבד; שמאי שאינו בתיק ← 404
call('POST', 'case-member-update', ['case' => $C, 'user' => $talPid, 'perms' => ['view' => true, 'edit' => false]], $avi);
eq([$list($tal, $C)[0], $list($tal, $C)[1]['perms'], code($add($tal, $C, ['price' => 1])), code(call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'x'], $tal)), code(call('POST', 'comp-correct', ['comp' => $T1, 'rev' => $rev($T1), 'changes' => ['price' => '5'], 'reason' => 'x'], $tal)),
    code(call('POST', 'comp-import', ['search' => $S4, 'rights' => true] + $imp, $tal)), code(call('POST', 'comp-import-preview', $imp, $tal)), code($upload($tal, $C, $xb)), code(call('POST', 'comp-delete', ['comp' => $T1, 'rev' => $rev($T1), 'reason' => 'x'], $tal)),
    $view($tal, $K1)[0], $setView($tal, $G)[0], code($list($gil, $C)), code($view($gil, $K1)), code($setView($gil, $G)), code($add($gil, $C, ['price' => 1]))],
    [200, ['write' => false, 'judge' => false], 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 200, 200, 'not_found', 'not_found', 'not_found', 'not_found'],
    '§9 view בלי edit: צפייה בלבד — הזנה, חיפוש, תיקון, ייבוא, תצוגה מקדימה, העלאה ומחיקה נדחים; שמאי במשרד שאינו משתתף בתיק ← 404');
// שמאי שותף בלי edit אינו שופט; שמאי שתפקידו בתיק "עובד" אינו שופט
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['view' => true, 'edit' => false]], $avi);
$noEdit = code($status($dana, $T1, 'selected'));
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['view' => true, 'edit' => true], 'role' => 'staff'], $avi);
$asStaff = [apr_val('SELECT role FROM case_members WHERE case_id = (SELECT id FROM cases WHERE pid = ?) AND user_id = (SELECT id FROM users WHERE pid = ?) AND revoked_at IS NULL', [$C, $danaPid]), code($status($dana, $T1, 'selected'))];
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['view' => true, 'edit' => true], 'role' => 'partner'], $avi);
call('POST', 'case-member-update', ['case' => $C, 'user' => $noaPid, 'perms' => ['view' => true, 'edit' => true], 'role' => 'partner'], $avi);
$traineePartner = [apr_val('SELECT role FROM case_members WHERE case_id = (SELECT id FROM cases WHERE pid = ?) AND user_id = (SELECT id FROM users WHERE pid = ?) AND revoked_at IS NULL', [$C, $noaPid]), code($status($noa, $T1, 'selected')),
    apr_comp_can_judge(['office' => ['role' => 'trainee']], ['perms' => ['edit' => true], 'my_role' => 'partner']), apr_comp_can_judge(['office' => ['role' => 'appraiser']], ['perms' => ['edit' => true], 'my_role' => 'partner'])];
eq([$traineePartner[1], $traineePartner[2], $traineePartner[3]], ['forbidden', false, true], '15-C4 מי שאינו שמאי במשרד אינו בוחר — גם אם תפקידו בתיק "שותף" ויש לו edit');
eq([$noEdit, $asStaff[0] === 'staff' ? $asStaff[1] : 'forbidden', $status($dana, $T1, 'selected')[0]], ['forbidden', 'forbidden', 200], '§9 "שמאי בתיק" = edit + שמאי במשרד + אחראי או שותף: בלי edit, או בתפקיד אחר בתיק — אינו בוחר');

// 15-C2: בידוד — מזהים של משרד א מול משתמש של משרד ב, ולהפך
$KB = $add($boaz, $CB, ['price' => '500000', 'deal_date' => '2025-01-01'])[1]['comp'];
$GB = call('POST', 'comp-set-save', ['case' => $CB, 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => 'none'], $boaz)[1]['set'];
$SB = call('POST', 'comp-search-add', ['case' => $CB, 'source_text' => 'מקור של ב'], $boaz)[1]['search'];
$FBz = call('POST', 'fact-add', ['case' => $CB, 'attribute' => 'area', 'value' => '70', 'unit' => 'sqm', 'area_kind' => 'registered', 'source' => ['kind' => 'user', 'note' => 'ב']], $boaz)[1]['fact']['pid'];
$snap = fn() => [hash('sha256', json_encode(apr_rows('SELECT * FROM comps ORDER BY id'))), hash('sha256', json_encode(apr_rows('SELECT * FROM comp_adjustments ORDER BY id'))), hash('sha256', json_encode(apr_rows('SELECT * FROM comp_sets ORDER BY id'))), hash('sha256', json_encode(apr_rows('SELECT * FROM comp_searches ORDER BY id')))];
$s0 = $snap();
$iso = [$list($boaz, $C), $view($boaz, $K1), $setView($boaz, $G), $add($boaz, $C, ['price' => 1]), call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'x'], $boaz), call('POST', 'comp-search-update', ['search' => $S1, 'result_count' => 9], $boaz),
    call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['price' => '1'], 'reason' => 'x'], $boaz), $status($boaz, $K1, 'excluded', 'x'), call('POST', 'comp-weight', ['comp' => $K1, 'rev' => $rev($K1), 'weight' => '9'], $boaz),
    call('POST', 'comp-note', ['comp' => $K1, 'rev' => $rev($K1), 'note' => 'x'], $boaz), call('POST', 'comp-delete', ['comp' => $K1, 'rev' => $rev($K1), 'reason' => 'x'], $boaz), $adjSave($boaz, $K1, $okAdj),
    call('POST', 'comp-adj-save', ['comp' => $KB, 'rev' => $rev($KB), 'adj' => $A1] + $okAdj, $boaz), call('POST', 'comp-adj-delete', ['comp' => $KB, 'rev' => $rev($KB), 'adj' => $A1], $boaz), call('POST', 'comp-adj-delete', ['comp' => $K1, 'rev' => $rev($K1), 'adj' => $A1], $boaz),
    call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A2, $A1]], $boaz), call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => 'none'], $boaz),
    call('POST', 'comp-set-save', ['set' => $G, 'rev' => $grev($G), 'unit_basis' => 'total', 'method' => 'median', 'rounding' => 'none'], $boaz), call('POST', 'comp-set-conclude', ['set' => $G, 'rev' => $grev($G), 'value' => '1', 'reason' => 'x'], $boaz),
    call('POST', 'comp-set-delete', ['set' => $G, 'rev' => $grev($G)], $boaz), call('POST', 'comp-import', ['search' => $S4, 'rights' => true] + $imp, $boaz), call('POST', 'comp-import', ['search' => $S4, 'rights' => true, 'case' => $CB] + $imp, $boaz),
    call('POST', 'comp-import-preview', $imp, $boaz), call('POST', 'comp-import-preview', ['case' => $CB, 'file' => $xu[1]['file']], $boaz), $add($boaz, $CB, ['price' => 1], ['search' => $S1]),
    call('POST', 'comp-set-save', ['case' => $CB, 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none', 'subject_area_fact' => $F], $boaz),
    $view($avi, $KB), $setView($avi, $GB), call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none', 'subject_area_fact' => $FBz], $avi),
    call('GET', 'comp', [], $avi, ['comp' => 'x']), call('GET', 'comp-set', [], $avi, ['set' => ['a']]), call('GET', 'case-comps', [], $avi, ['case' => null])];
eq([array_unique(array_map(fn($r) => $r[0] . ' ' . code($r) . ' ' . $r[1]['error']['message'], $iso)), count($iso), $snap() === $s0, str_contains(json_encode($list($boaz, $CB)[1], JSON_UNESCAPED_UNICODE), 'הרצל 10')],
    [['404 not_found לא נמצא'], 32, true, false], '15-C2 בידוד: עסקה, התאמה, קבוצה, חיפוש, קובץ ונתון של תיק ממשרד אחר — בכל פעולה אותו 404 "לא נמצא", ושום שורה לא השתנתה');
// 15-C3: שותף חיצוני בלי see_internal — הכלל (התפקיד עצמו ייבנה בפרוסה 05)
$ext = fn(array $perms) => ['my_role' => 'external', 'perms' => $perms + ['view' => true]];
eq([apr_comp_visible($ext([])), apr_comp_visible($ext(['see_internal' => false])), apr_comp_visible($ext(['see_internal' => true])), apr_comp_visible(['my_role' => 'staff', 'perms' => ['view' => true]]),
    (function () use ($ext) { $GLOBALS['t_c'] = $ext(['edit' => true]); try { return apr_comp_visible($GLOBALS['t_c']) ? 'visible' : 'hidden'; } finally { unset($GLOBALS['t_c']); } })(),
    preg_match_all('/apr_case_get\(/', (string)file_get_contents(__DIR__ . '/../lib/comps.php')), preg_match_all('/apr_case_get\(|apr_case_writable\(/', (string)file_get_contents(__DIR__ . '/../lib/comp_import.php'))],
    [false, false, true, true, 'hidden', 1, 0],
    '15-C3 שותף חיצוני בלי see_internal אינו רואה עסקאות וחישובים (apr_comp_visible); וכל פעולה בפרוסה עוברת דרך apr_comp_case — המקום היחיד שקורא ל-apr_case_get');

// CSRF, שיטה, ותיק סגור
eq([code(call('POST', 'comp-add', ['case' => $C, 'values' => ['price' => 1]], $avi, [], ['csrf' => 'wrong'])), code(call('POST', 'comp-set-conclude', ['set' => $G, 'rev' => $grev($G), 'value' => '1', 'reason' => 'x'], $avi, [], ['csrf' => ''])),
    code(call('GET', 'comp-add', [], $avi, ['case' => $C])), code(call('POST', 'case-comps', ['case' => $C], $avi)), code(call('POST', 'comp-import-preview', $imp, $avi, [], ['csrf' => 'wrong'])), code(call('GET', 'comp-status', [], $avi, ['comp' => $K1, 'status' => 'excluded']))],
    ['csrf', 'csrf', 'method', 'method', 'csrf', 'method'], 'כל פעולה משנה: POST עם X-CSRF; קריאה — GET בלבד');
$n0 = $snap();
call('POST', 'case-status', ['case' => $C, 'status' => 'closed', 'reason' => 'סיום'], $avi);
$closed = [code($add($avi, $C, ['price' => 1])), code(call('POST', 'comp-search-add', ['case' => $C, 'source_text' => 'x'], $avi)), code(call('POST', 'comp-search-update', ['search' => $S1, 'result_count' => 3], $avi)),
    code(call('POST', 'comp-correct', ['comp' => $K1, 'rev' => $rev($K1), 'changes' => ['price' => '1'], 'reason' => 'x'], $avi)), code($status($avi, $K3, 'selected')), code(call('POST', 'comp-weight', ['set' => $G, 'comp' => $K1, 'rev' => $grev($G), 'weight' => '1'], $avi)),
    code(call('POST', 'comp-note', ['comp' => $K1, 'rev' => $rev($K1), 'note' => 'x'], $avi)), code(call('POST', 'comp-delete', ['comp' => $K3, 'rev' => $rev($K3), 'reason' => 'x'], $avi)), code($adjSave($avi, $K1, $okAdj)),
    code(call('POST', 'comp-adj-delete', ['comp' => $K1, 'rev' => $rev($K1), 'adj' => $A1], $avi)), code(call('POST', 'comp-adj-order', ['comp' => $K1, 'rev' => $rev($K1), 'order' => [$A2, $A1]], $avi)),
    code(call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => 'none'], $avi)), code(call('POST', 'comp-set-conclude', ['set' => $G, 'rev' => $grev($G), 'value' => '1', 'reason' => 'x'], $avi)),
    code(call('POST', 'comp-set-delete', ['set' => $G, 'rev' => $grev($G)], $avi)), code(call('POST', 'comp-import', ['search' => $S4, 'rights' => true] + $imp, $avi)), code(call('POST', 'comp-import-preview', $imp, $avi)), code($upload($avi, $C, $xb, 'b.xlsx'))];
eq([array_unique($closed), count($closed), $snap() === $n0, $list($avi, $C)[0], $list($avi, $C)[1]['perms'], $setView($avi, $G)[1]['summary']['counted'] > 0], [['case_closed'], 17, true, 200, ['write' => false, 'judge' => false], true],
    'תיק סגור: כל 17 הפעולות המשנות נחסמות (409) ושום שורה לא משתנה; הצפייה והחישוב נשארים');
call('POST', 'case-status', ['case' => $C, 'status' => 'analysis', 'reason' => 'נפתח מחדש'], $avi);

// XSS: טקסט של משתמש נשמר ומוחזר כמו שהוא — המסך הוא שמנטרל (esc); אין HTML בתשובה שהשרת מרכיב
$xss = '<img src=x onerror=alert(1)>';
$XK = $add($avi, $C, ['price' => '1', 'address_text' => $xss, 'location_precision' => 'exact', 'condition' => $xss], ['note' => $xss])[1]['comp'];
$adjSave($avi, $XK, ['label' => $xss, 'reason' => $xss] + $okAdj);
$xo = $view($avi, $XK)[1];
$js = (string)file_get_contents(__DIR__ . '/../assets/js/screens/comps.js');
eq([$xo['comp']['values']['address'], $xo['comp']['note'], $xo['adjustments'][0]['label'], $xo['adjustments'][0]['reason'], preg_match('/\.innerHTML\s*=\s*[^`\'"]*\b(d|x|k|r|s|g|a)\.[a-z_.]+\s*;/', $js), preg_match('/\beval\(|document\.write|new Function\(/', $js)],
    [$xss, $xss, $xss, $xss, 0, 0], 'טקסט של משתמש חוזר מהשרת בלי שינוי ובלי HTML; במסך אין innerHTML שמקבל שדה גולמי, ואין eval');
// כל ערך שמגיע מהשרת ונכנס לתבנית במסך עובר דרך esc(): אין ${…} שמתחיל בשדה של עסקה בלי esc / מספר / מפתח מהרשימה
preg_match_all('/\$\{\s*((?:k|c|v|a|s|g|r|x\.comp|x\.set)\.(?:values\.)?(?:note|status_reason|label|reason|address|approx_location|locality|property_type|condition|attachments|source_ref|gush|helka|tat_helka|name|source_text|limitations|by|created_by|updated_by))\s*\}/', $js, $rawUse);
eq($rawUse[1], [], 'במסך אין שדה טקסט של משתמש שנכנס לתבנית בלי esc()');
// 🟡5: בנוסחה הסימן של כל שלב נגזר מהשינוי שהשרת חישב (delta), לא מהסימן שהוזן — s.sign אינו משמש בתצוגת הנוסחה
$neg = apr_calc_comp($comp($nis(1000000)), [$adj('א', 'percent', $pct('150'), 'minus', 'base'), $adj('ב', 'percent', $pct('10'), 'plus', 'running')], $total);
eq([preg_match_all("/\\bs\\.sign\\b/", $js), preg_match_all('/String\(s\.delta\.agorot\)\.startsWith\(\'-\'\)/', $js), apr_comp_calc_out($neg)['steps'][1]['delta']['agorot'], apr_comp_calc_out($neg)['steps'][1]['sign'], apr_comp_calc_out($neg)['adjusted']['nis']],
    [0, 1, '-5000000', 'plus', '-550000'], '🟡5 1,000,000 −150% מהבסיס, +10% מהשוטף: השינוי השני הוא −50,000 (אף שהוזן "תוספת") — והמסך גוזר את הסימן מה-delta');

// שמות גלובליים: כל קובצי המסכים נטענים לאותו מרחב. const / let / class שמוגדר גם בקובץ אחר הוא שגיאת תחביר שמפילה את הקובץ השני כולו
// (נתפס בדפדפן: kStatusChip). שתי הצהרות function באותו שם אינן שגיאה — האחרונה מנצחת — ולכן אינן נבדקות כאן.
$globals = [];
foreach (array_merge(glob(__DIR__ . '/../assets/js/*.js'), glob(__DIR__ . '/../assets/js/screens/*.js')) as $jsf) {
    preg_match_all('/^(const|let|var|function|async function|class)\s+([A-Za-z_$][\w$]*)/m', (string)file_get_contents($jsf), $mm, PREG_SET_ORDER);
    foreach ($mm as $m) $globals[$m[2]][] = [basename($jsf), in_array($m[1], ['const', 'let', 'class'], true)];
}
eq(array_keys(array_filter($globals, fn($d) => count(array_unique(array_column($d, 0))) > 1 && in_array(true, array_column($d, 1), true))), [], 'אין const / let / class גלובלי שמוגדר גם בקובץ JavaScript אחר');

// ── מיגרציה 012 על מסד v11 מאוכלס ──
$m11 = sys_get_temp_dir() . '/apr-m11-' . bin2hex(random_bytes(5));
mkdir($m11, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 11) copy($mf, $m11 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v11 = new PDO("sqlite:$dir/v11.sqlite", null, null, $pdoOpt);
apr_migrate($v11, $m11);
$v11->exec('PRAGMA foreign_keys = OFF');
$v11->exec('ATTACH ' . $v11->quote("$dir/app.sqlite") . ' AS live');
$tables = array_column($v11->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($tables as $t) {
    try { $v11->exec("DELETE FROM main.\"$t\""); } catch (PDOException) { continue; }      // טבלה ששורותיה אינן נמחקות (sources, visits …) — נשארת כפי שנזרעה
    try { $v11->exec("INSERT INTO main.\"$t\" (" . t_cols($v11, $t) . ") SELECT " . t_cols($v11, $t) . " FROM live.\"$t\""); } catch (PDOException) { continue; }
}
$v11->exec('DETACH live');
$v11->exec('PRAGMA foreign_keys = ON');
// העמודות כפי שהן לפני המיגרציה: מיגרציה מאוחרת שמוסיפה עמודה (014 ל-facts) אינה משנה את מה שהיה בשורה
$colsBefore = array_combine($tables, array_map(fn($t) => t_cols($v11, $t), $tables));
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query("SELECT {$colsBefore[$t]} FROM \"$t\" ORDER BY 1")->fetchAll()))], $tables);
$d11 = $dump($v11);
$rows11 = array_sum(array_map(fn($t) => (int)$v11->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn(), $tables));
$had = [apr_schema_version($v11), in_array('comps', $tables, true), (int)$v11->query('SELECT COUNT(*) FROM facts')->fetchColumn() >= 3, (int)$v11->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() > 50];
apr_migrate($v11);
$freshDb = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($freshDb);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
$newTables = array_column($v11->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'comp%' ORDER BY name")->fetchAll(), 'name');
eq([apr_schema_version($v11) >= 12, $had, $rows11 > 100, $dump($v11) === $d11, $shape($v11) === $shape($freshDb), $newTables, (int)$v11->query('SELECT COUNT(*) FROM comps')->fetchColumn(), count($v11->query('PRAGMA foreign_key_check')->fetchAll()),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-9]_*.sql lib/migrations/01[01]_*.sql 2>/dev/null'))],
    [true, [11, false, true, true], true, true, true, ['comp_adjustments', 'comp_import_maps', 'comp_searches', 'comp_set_members', 'comp_sets', 'comps'], 0, 0, ''],
    'מיגרציה 012 על מסד v11 מאוכלס: כל שורה בכל טבלה קיימת נשמרת כפי שהיא, המבנה זהה למסד חדש, אין עסקאות; 001–011 לא נגעו');

eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');
done();
