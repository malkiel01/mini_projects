<?php
// פרוסה 16 — חלופות שווי וגישות שומה (docs/spec/16-valuation-methods.md §11): 16-A1 … 16-C5, מקרי הקצה של §10, ההרשאות, הבידוד, השיתוף, rev והמיגרציה.
$dir = sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
define('APR_DATA_DIR', $dir);
define('APR_DB_PATH', $dir . '/app.sqlite');
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
$GLOBALS['apr_time'] = 1794400000;      // שעון קפוא

// ═══ חלק א: מנוע הגיליון לבדו — בלי מסד ═════════════════════════

$M = fn(string $dec) => apr_val_dec6($dec);                                  // "8500" ← מיליוניות
$in = fn(string $key, ?string $v, string $unit = '', string $tu = 'none') => ['key' => $key, 'kind' => 'input', 'value' => $v === null ? null : apr_val_dec6($v), 'unit' => $unit, 'time_unit' => $tu, 'formula' => null];
$calc = function (string $key, array $f, array $above, string $tu = 'none') {
    [$canon, $err] = apr_sheet_formula($f, $above);
    if ($err !== null) throw new RuntimeException("formula $key: $err");
    return ['key' => $key, 'kind' => 'calc', 'value' => null, 'unit' => '', 'time_unit' => $tu, 'formula' => $canon];
};
$note = fn(string $key) => ['key' => $key, 'kind' => 'note', 'value' => null, 'unit' => '', 'time_unit' => 'none', 'formula' => null];
$dec = fn(?array $q) => $q === null ? null : apr_sheet_dec($q)['dec'];
$B1 = fn(?string $rent = '8500', ?string $cap = '6') => [
    $in('r1', $rent, '₪ לחודש', 'month'), $calc('r2', ['op' => 'mul', 'args' => ['r1', 12]], ['r1'], 'year'), $in('r3', '12000', '₪', 'year'),
    $calc('r4', ['op' => 'sub', 'args' => ['r2', 'r3']], ['r1', 'r2', 'r3'], 'year'), $in('r5', $cap, '%'),
    $calc('r6', ['op' => 'div', 'args' => ['r4', ['pct' => 'r5']]], ['r1', 'r2', 'r3', 'r4', 'r5'])];

// 16-B1: הדוגמה שבאפיון — בדיוק
$v = apr_sheet_eval($B1());
$res = apr_sheet_result($B1(), $v, 'none');
eq([$v['r2']['value'], $v['r4']['value'], $v['r6']['value'], $res['agorot'], $res['key'], $v['r6']['warnings'], $v['r4']['warnings']],
    [['102000', '1'], ['90000', '1'], ['1500000', '1'], '150000000', 'r6', [], []],
    '16-B1 8,500 × 12 = 102,000; − 12,000 = 90,000; ÷ 6% = 1,500,000 — שברים מדויקים עם מכנה 1, והתוצאה 150,000,000 אגורות');

// 16-B2: קלט ריק — כל מה שתלוי בו בלי תוצאה, עם סיבה ועם השורה החסרה; לא אפס
$v = apr_sheet_eval($B1(null));
$res = apr_sheet_result($B1(null), $v, 'none');
eq([$v['r1'], [$v['r2']['value'], $v['r2']['reason'], $v['r2']['ref']], [$v['r4']['value'], $v['r4']['reason'], $v['r4']['ref']], [$v['r6']['value'], $v['r6']['reason'], $v['r6']['ref']],
    [$res['agorot'], $res['reason'], $res['ref']], $v['r3']['value']],
    [['value' => null, 'reason' => 'empty', 'ref' => 'r1', 'warnings' => []], [null, 'missing', 'r1'], [null, 'missing', 'r1'], [null, 'missing', 'r1'], [null, 'missing', 'r1'], ['12000', '1']],
    '16-B2 קלט ריק ← r2, r4, r6 והתוצאה מחזירים null עם הסיבה missing והשורה r1 — לא 0; שורה שאינה תלויה בו מחושבת');
$zero = apr_sheet_eval($B1('0'));
eq([$zero['r2']['value'], $zero['r6']['value'], apr_sheet_result($B1('0'), $zero, 'none')['agorot']], [['0', '1'], ['-200000', '1'], '-20000000'],
    'אפס שהוזן הוא ערך (0 × 12 = 0; התוצאה ‎-200,000) — להבדיל מקלט ריק');

// 16-B3: חלוקה באפס
$v = apr_sheet_eval($B1('8500', '0'));
$direct = [$in('r1', '5'), $in('r2', '0'), $calc('r3', ['op' => 'div', 'args' => ['r1', 'r2']], ['r1', 'r2']), $calc('r4', ['op' => 'add', 'args' => ['r3', 1]], ['r1', 'r2', 'r3']),
    $calc('r5', ['op' => 'div', 'args' => ['r1', ['op' => 'sub', 'args' => ['r1', 'r1']]]], ['r1', 'r2', 'r3', 'r4'])];
$dv = apr_sheet_eval($direct);
eq([[$v['r6']['value'], $v['r6']['reason']], [$dv['r3']['value'], $dv['r3']['reason'], $dv['r3']['ref']], [$dv['r4']['value'], $dv['r4']['reason'], $dv['r4']['ref']], [$dv['r5']['value'], $dv['r5']['reason']],
    apr_sheet_result($direct, $dv, 'none')['agorot']],
    [[null, 'div_zero'], [null, 'div_zero', 'r2'], [null, 'blocked', 'r3'], [null, 'div_zero'], null],
    '16-B3 חלוקה באפס (ישירה, דרך pct של 0%, ובביטוי מקונן) ← null עם הסיבה div_zero; שורה שתלויה בה — blocked; אין תוצאה');

// 16-B4: נוסחה שמפנה לשורה שמתחתיה, לשורה שאינה קיימת, או עם op לא מוכר — נדחית
$f = fn(mixed $x, array $above = ['r1', 'r2']) => apr_sheet_formula($x, $above);
eq([$f(['op' => 'add', 'args' => ['r1', 'r3']])[1], $f(['op' => 'add', 'args' => ['r1', 'r9']])[1], $f(['op' => 'pow', 'args' => ['r1', 2]])[1], $f(['op' => 'eval', 'args' => ['r1', 'r2']])[1],
    $f(['op' => 'add', 'args' => ['r1', 'system("id")']])[1], $f(['op' => 'add', 'args' => ['r1', '1e5']])[1], $f('r1')[1], $f(12)[1], $f(['op' => 'sub', 'args' => ['r1']])[1], $f(['op' => 'div', 'args' => ['r1', 'r2', 'r1']])[1],
    $f(['op' => 'add', 'args' => ['r1', ['op' => 'mul', 'args' => ['r2', ['pct' => 'R1']]]]])[1], $f(['op' => 'sum', 'args' => ['r2', 'r1']])[1], $f(['op' => 'sum', 'args' => ['r1', 'r5']])[1], $f(['op' => 'add', 'args' => ['r1', 2], 'x' => 1])[1],
    $f(['op' => 'add', 'args' => ['r1', ['op' => 'add', 'args' => ['r1', ['op' => 'add', 'args' => ['r1', ['op' => 'add', 'args' => ['r1', ['op' => 'add', 'args' => ['r1', ['op' => 'add', 'args' => ['r1', 1]]]]]]]]]]]])[1],
    $f(['op' => 'add', 'args' => array_fill(0, 21, 'r1')])[1], $f(['op' => 'add', 'args' => ['r1', '1234567890123']])[1], $f(['op' => 'mul', 'args' => ['r1', 0.5]])[1]],
    ['sheet_ref', 'sheet_ref', 'sheet_op', 'sheet_op', 'sheet_formula', 'sheet_formula', 'sheet_formula', 'sheet_formula', 'sheet_formula', 'sheet_formula', 'sheet_ref', 'sheet_formula', 'sheet_ref', 'sheet_formula',
        'sheet_formula', 'sheet_formula', 'sheet_formula', 'sheet_formula'],
    '16-B4 הפניה לשורה שמתחת / שאינה קיימת ← sheet_ref; פעולה לא מוכרת (pow, eval) ← sheet_op; טקסט חופשי, מספר מדעי, נוסחה שאינה פעולה, מספר ארגומנטים שגוי, קינון עמוק מדי, מספר ארוך מדי ונקודה צפה ← sheet_formula');
eq([$f(['op' => 'div', 'args' => ['r1', ['pct' => 'r2']]]), $f(['op' => 'pct', 'args' => ['r2']])[0], $f(['op' => 'mul', 'args' => ['r1', '012.50', -3]])[0], $f(['op' => 'sum', 'args' => ['r1', 'r2']])[0]],
    [[['op' => 'div', 'args' => ['r1', ['pct' => 'r2']]], null], ['pct' => 'r2'], ['op' => 'mul', 'args' => ['r1', '12.5', '-3']], ['op' => 'sum', 'args' => ['r1', 'r2']]],
    'נוסחה תקינה נשמרת בצורה קנונית: מספר כמחרוזת עשרונית בלי אפסים מיותרים; pct בשתי הצורות — אותו מבנה');

// 16-B5: בדיקה סטטית — אין eval, אין נקודה צפה, אין מסד, שעון, רשת או טעינת קובץ במנוע הגיליון
$engine = (string)file_get_contents(__DIR__ . '/../lib/sheet_calc.php');
$floatish = [];
foreach (token_get_all($engine) as $t) {
    if (is_string($t)) { if ($t === '/') $floatish[] = '/'; continue; }
    if (in_array($t[0], [T_DNUMBER, T_DOUBLE_CAST, T_DIV_EQUAL, T_POW, T_POW_EQUAL, T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_EVAL], true)) $floatish[] = token_name($t[0]) . ' ' . $t[1];
    if ($t[0] === T_STRING && preg_match('/^(float|floatval|doubleval|round|floor|ceil|fdiv|fmod|intdiv|sqrt|pow|exp|log|number_format|is_float|bcadd|bcmul|bcdiv|microtime|time|rand|mt_rand|random_int|apr_db|apr_row|apr_rows|apr_val|apr_exec|apr_insert|apr_now|apr_time|curl_init|file_get_contents|create_function|assert|call_user_func|call_user_func_array)$/i', $t[1])) $floatish[] = $t[1];
}
// גם שכבת השרת של הפרוסה: אין eval, אין מספר עשרוני, אין חילוק, אין עיגול — המספרים עוברים רק במנוע
$server = [];
foreach (token_get_all((string)file_get_contents(__DIR__ . '/../lib/valuation.php')) as $t) {
    if (is_string($t)) { if ($t === '/' || $t === '%') $server[] = $t; continue; }
    if (in_array($t[0], [T_DNUMBER, T_DOUBLE_CAST, T_DIV_EQUAL, T_POW, T_EVAL], true)) $server[] = token_name($t[0]);
    if ($t[0] === T_STRING && preg_match('/^(floatval|doubleval|round|floor|ceil|fdiv|fmod|intdiv|number_format|array_sum|bcadd|bcmul|bcdiv|apr_nu_\w+)$/i', $t[1])) $server[] = $t[1];
}
$allLib = $engine . (string)file_get_contents(__DIR__ . '/../lib/valuation.php');
eq([$floatish, $server, preg_match('/\bai_run\b|apr_ai_|anthropic|openai/i', $allLib), str_contains($engine, 'apr_q_round(') && str_contains($engine, 'apr_q_div(')], [[], [], 0, true],
    '16-B5 במנוע הגיליון אין eval, אין /, אין מספר עשרוני, אין פונקציית float, אין מסד, שעון או טעינת קובץ; החשבון — רק בפונקציות של מנוע 15; גם ב-valuation.php אין חשבון ואין קריאה למודל');

// עיגול אחד, בסוף; שברים שאינם נגמרים; שליליים; מספרים ארוכים
$third = [$in('r1', '100'), $calc('r2', ['op' => 'div', 'args' => ['r1', 3]], ['r1']), $calc('r3', ['op' => 'mul', 'args' => ['r2', 3]], ['r1', 'r2'])];
$tv = apr_sheet_eval($third);
$half = fn(string $x, string $r) => apr_sheet_result([$in('r1', $x), $calc('r2', ['op' => 'add', 'args' => ['r1', 0]], ['r1'])], apr_sheet_eval([$in('r1', $x), $calc('r2', ['op' => 'add', 'args' => ['r1', 0]], ['r1'])]), $r)['agorot'];
eq([$tv['r2']['value'], apr_sheet_dec($tv['r2']['value']), $tv['r3']['value'], $half('2125000', '10000'), $half('2124999.99', '10000'), $half('0.005', 'none'), $half('0.004999', 'none'), $half('-0.005', 'none'), $half('-2125000', '10000'),
    $half('1500.5', '1'), $half('999999999999.999999', 'none')],
    [['100', '3'], ['dec' => '33.333333', 'exact' => false], ['100', '1'], '213000000', '212000000', '1', '0', '0', '-212000000', '150100', '100000000000000'],
    'שליש נשמר כשבר 100/3 (מוצג 33.333333 עם סימון "לא מדויק"), ו-×3 מחזיר 100 בדיוק; העיגול נעשה פעם אחת: חצי כלפי מעלה (2,125,000 ← 2,130,000; ‎-2,125,000 ← ‎-2,120,000; חצי אגורה ← אגורה)');
$big = [$in('r1', '999999999999.999999'), $calc('r2', ['op' => 'mul', 'args' => ['r1', 'r1', 'r1']], ['r1']), $calc('r3', ['op' => 'div', 'args' => ['r2', 'r1']], ['r1', 'r2']), $calc('r4', ['op' => 'sub', 'args' => ['r3', ['op' => 'mul', 'args' => ['r1', 'r1']]]], ['r1', 'r2', 'r3'])];
$bv = apr_sheet_eval($big);
eq([$bv['r2']['value'][0], $bv['r2']['value'][1], $bv['r4']['value'], $dec(apr_sheet_eval([$in('r1', '-7.5'), $calc('r2', ['op' => 'mul', 'args' => ['r1', '-2']], ['r1'])])['r2']['value'])],
    ['999999999999999997000000000000000002999999999999999999', '1000000000000000000', ['0', '1'], '15'],
    'מספרים ארוכים משלם של 64 סיביות נשארים מדויקים (x³ ÷ x − x² = 0 בדיוק); שלילי × שלילי');

// sum, שורת הערה, pct, אזהרות
$sumRows = [$in('r1', '100', '₪', 'month'), $note('r2'), $in('r3', '200', '₪', 'month'), $in('r4', '50', '₪', 'year'), $calc('r5', ['op' => 'sum', 'args' => ['r1', 'r4']], ['r1', 'r3', 'r4']),
    $calc('r6', ['op' => 'sum', 'args' => ['r1', 'r3']], ['r1', 'r3', 'r4', 'r5']), $in('r7', '150', '%'), $calc('r8', ['op' => 'mul', 'args' => ['r1', 'r7']], ['r1', 'r3', 'r4', 'r5', 'r6', 'r7']),
    $calc('r9', ['op' => 'mul', 'args' => ['r1', ['pct' => 'r7']]], ['r1', 'r3', 'r4', 'r5', 'r6', 'r7', 'r8']), $calc('r10', ['op' => 'sub', 'args' => ['r1', 'r4']], ['r1', 'r3', 'r4']), $calc('r11', ['op' => 'sum', 'args' => ['r3', 'r3']], ['r1', 'r3'])];
$sv = apr_sheet_eval($sumRows);
eq([$dec($sv['r5']['value']), $sv['r5']['warnings'], $dec($sv['r6']['value']), $sv['r6']['warnings'], $sv['r7']['warnings'], $dec($sv['r8']['value']), $sv['r8']['warnings'], $dec($sv['r9']['value']), $sv['r9']['warnings'], $sv['r10']['warnings'],
    isset($sv['r2']), $dec($sv['r11']['value'])],
    ['350', ['time_units'], '300', [], ['pct_over_100'], '15000', ['pct_unwrapped'], '150', [], ['time_units'], false, '200'],
    '§5 sum על טווח (שורת הערה אינה נספרת); חודש ושנה באותו חיבור / חיסור ← אזהרה, בלי המרה; §10.9 ערך מעל 100 ביחידת % ← אזהרה, לא חסימה; pct ממיר 150% ל-1.5; שורת % בכפל בלי pct ← אזהרה');
$emptyInRange = apr_sheet_eval([$in('r1', '100'), $in('r2', null), $calc('r3', ['op' => 'sum', 'args' => ['r1', 'r2']], ['r1', 'r2'])]);
eq([$emptyInRange['r3']['value'], $emptyInRange['r3']['reason'], $emptyInRange['r3']['ref']], [null, 'missing', 'r2'], 'sum על טווח שיש בו קלט ריק ← אין תוצאה (ריק אינו אפס)');

// §10.5: נוסחה שמפנה לשורה שנמחקה — "שבורה"; לגיליון אין תוצאה סופית, גם אם השורה האחרונה מחושבת
$broken = array_values(array_filter($B1(), fn($r) => $r['key'] !== 'r3'));
$broken[] = $calc('r7', ['op' => 'mul', 'args' => ['r1', 2]], ['r1']);
$bk = apr_sheet_eval($broken);
$bres = apr_sheet_result($broken, $bk, 'none');
eq([[$bk['r4']['value'], $bk['r4']['reason'], $bk['r4']['ref']], [$bk['r6']['reason'], $bk['r6']['ref']], $dec($bk['r7']['value']), [$bres['agorot'], $bres['reason']], apr_sheet_result([$in('r1', '5')], apr_sheet_eval([$in('r1', '5')]), 'none')['reason']],
    [[null, 'broken_ref', 'r3'], ['broken_ref', 'r3'], '17000', [null, 'broken'], 'no_calc'],
    '§10.5 נוסחה שמפנה לשורה שנמחקה ← broken_ref, וכך גם מי שתלוי בה; הגיליון אינו נותן תוצאה סופית אף שהשורה האחרונה חושבה; גיליון בלי שורת חישוב — אין תוצאה');

// ביצועים והגנה מפני מספרים שמתנפחים: 100 שורות של חילוקים "רעים", וריבוע חוזר
$rows = [$in('r1', '999999999999.999999'), $in('r2', '7.000003'), $in('r3', '0.000013')];
$above = ['r1', 'r2', 'r3'];
for ($i = 4; $i <= 100; $i++) {
    $a = 'r' . ($i - 1);
    $b = 'r' . ($i - 2);
    $rows[] = $calc("r$i", $i % 3 === 0 ? ['op' => 'add', 'args' => [['op' => 'div', 'args' => [$a, 7]], ['op' => 'div', 'args' => [$b, 3]]]] : ($i % 3 === 1 ? ['op' => 'div', 'args' => [$a, ['op' => 'add', 'args' => [$b, '0.000007']]]] : ['op' => 'mul', 'args' => [$a, $b, '1.000001']]), $above);
    $above[] = "r$i";
}
$t0 = hrtime(true);
$pv = apr_sheet_eval($rows);
$ms = intdiv(hrtime(true) - $t0, 1000000);
$sq = [$in('r1', '999999999999.999999')];
for ($i = 2; $i <= 12; $i++) $sq[] = $calc("r$i", ['op' => 'mul', 'args' => ['r' . ($i - 1), 'r' . ($i - 1)]], ['r' . ($i - 1)]);
$maxLen = max(array_map(fn($x) => $x['value'] === null ? 0 : max(strlen($x['value'][0]), strlen($x['value'][1])), $pv));
$t0 = hrtime(true);
$sqv = apr_sheet_eval($sq);
$ms2 = intdiv(hrtime(true) - $t0, 1000000);
$reasons = array_count_values(array_map(fn($x) => $x['reason'] ?? 'ok', $pv));
check('ביצועים: 100 שורות של חילוקים וחיבורים מקוננים, וריבוע חוזר 11 פעמים — פחות משנייה; מספר שעבר 80 ספרות ← too_long, לא חישוב שאינו נגמר',
    $ms < 1000 && $ms2 < 1000 && $sqv['r12']['reason'] !== null && $sqv['r4']['reason'] === 'too_long' && $sqv['r3']['value'] !== null && strlen($sqv['r3']['value'][0]) === 72 && ($reasons['ok'] ?? 0) >= 10
    && $maxLen > APR_SHEET_REDUCE_OVER && $maxLen <= APR_SHEET_MAX_DIGITS, [$ms, $ms2, $reasons, $sqv['r4']['reason'], $maxLen]);

// ביקורת 16, B1 — התרחיש של הסוקר: 60 קלטים "ראשוניים" בני 12 ספרות, מכפלות ומנות ארוכות, ושורה מקוננת ענקית. חוזר מהר, או too_long.
$big = [];
$bAbove = [];
for ($i = 1; $i <= 60; $i++) { $big[] = $in("r$i", (string)(999999999989 - 2 * $i * ($i + 30))); $bAbove[] = "r$i"; }
// חמש שורות של מנת מכפלות: 6 קלטים במונה, 6 במכנה — כ-72 ספרות בכל צד, בלי גורם משותף (מתחת לתקרה, ולכן יש להן תוצאה)
for ($i = 61; $i <= 65; $i++) {
    $o = ($i - 61) * 12;
    $big[] = $calc("r$i", ['op' => 'div', 'args' => [['op' => 'mul', 'args' => array_map(fn($k) => 'r' . ($o + $k), range(1, 6))], ['op' => 'mul', 'args' => array_map(fn($k) => 'r' . ($o + $k), range(7, 12))]]], $bAbove);
    $bAbove[] = "r$i";
}
// השורה של הסוקר (2.6KB): חיסור של שני סכומים של 20 מכפלות של 19 גורמים — נשמרה לפני שהייתה תקרת צמתים, ולכן נבנית כאן ישירות
$prod = ['op' => 'mul', 'args' => array_merge(['r65'], array_fill(0, 18, 'r64'))];
$monster = ['op' => 'sub', 'args' => [['op' => 'add', 'args' => array_fill(0, 20, $prod)], ['op' => 'add', 'args' => array_fill(0, 19, $prod)]]];
$raw = fn(string $key, array $f) => ['key' => $key, 'kind' => 'calc', 'value' => null, 'unit' => '', 'time_unit' => 'none', 'formula' => $f];
// שורה חוקית גם היום (39 צמתים): 13 מכפלות / מנות של השברים הארוכים — כל צעד בה מצריך צמצום מלא
$heavy = ['op' => 'add', 'args' => array_map(fn($k) => ['op' => $k % 2 ? 'mul' : 'div', 'args' => ['r' . (61 + $k % 5), 'r' . (61 + ($k + 1) % 5)]], range(1, 13))];
$rowsB = $big;
$rowsB[] = $raw('r66', $monster);
for ($i = 67; $i <= 100; $i++) $rowsB[] = $raw("r$i", $heavy);
$t0 = hrtime(true);
$bv = apr_sheet_eval($rowsB);
$msB = intdiv(hrtime(true) - $t0, 1000000);
$bReasons = array_count_values(array_map(fn($x) => $x['reason'] ?? 'ok', $bv));
check('B1 התרחיש של הסוקר (60 קלטים בני 12 ספרות, מנות של מכפלות, שורה מקוננת של 2.6KB ועוד 34 שורות כבדות) — חוזר בפחות משנייה: לכל שורה תוצאה או too_long',
    $msB < 1000 && $bv['r61']['value'] !== null && strlen($bv['r61']['value'][0]) > 60 && $bv['r66']['reason'] === 'too_long' && array_keys($bReasons) == ['ok', 'too_long'] && $bReasons['ok'] >= 65, [$msB, $bReasons]);
// תקציב הפעולות: גיליון של 35 שורות חוקיות וכבדות — הראשונות מחושבות, ומהשורה שבה התקציב נגמר הכול too_long. מונה, לא שעון: אותה תוצאה בכל ריצה
$rowsC = array_slice($big, 0, 60);
$cAbove = array_slice($bAbove, 0, 60);
$rowsC[] = $calc('r61', ['op' => 'div', 'args' => [['op' => 'mul', 'args' => ['r1', 'r2', 'r3', 'r4', 'r5']], ['op' => 'mul', 'args' => ['r7', 'r8', 'r9', 'r10', 'r11']]]], $cAbove);
$rowsC[] = $calc('r62', ['op' => 'add', 'args' => ['r61', 0]], [...$cAbove, 'r61']);
$cAbove = [...$cAbove, 'r61', 'r62'];
// 39 צמתים: סכום של 13 מכפלות של שבר בן 60 ספרות בקלט בן 12 — אותו מכנה, ולכן הסכום נשאר מתחת לתקרת הספרות; כל צעד מצריך צמצום מלא
$heavyC = ['op' => 'add', 'args' => array_map(fn($k) => ['op' => 'mul', 'args' => ['r61', 'r' . (20 + $k)]], range(1, 13))];
for ($i = 66; $i <= 100; $i++) $rowsC[] = $calc("r$i", $heavyC, $cAbove);
$t0 = hrtime(true);
$cv = apr_sheet_eval($rowsC);
$msC = intdiv(hrtime(true) - $t0, 1000000);
$cr = array_map(fn($i) => $cv["r$i"]['reason'] ?? 'ok', range(66, 100));
$firstLong = array_search('too_long', $cr, true);
check('B1 תקציב פעולות לגיליון: שורות כבדות מחושבות עד שהתקציב נגמר, ומשם — too_long לכל השאר (בלי "חורים"); פחות משנייה; ריצה שנייה נותנת בדיוק אותה תוצאה',
    $msC < 1000 && $cr[0] === 'ok' && $firstLong !== false && $firstLong >= 1 && array_unique(array_slice($cr, $firstLong)) === ['too_long'] && array_unique(array_slice($cr, 0, $firstLong)) === ['ok'] && $cv === apr_sheet_eval($rowsC),
    [$msC, $firstLong, array_count_values($cr)]);
// גיליון רגיל ומלא (100 שורות: קלטים, אחוזים, מכפלות וסכומי טווח) רחוק מאוד מהתקציב
$rowsD = [];
$dAbove = [];
for ($i = 1; $i <= 50; $i++) { $rowsD[] = $in("r$i", (string)(1000000 + $i * 1234) . '.5'); $dAbove[] = "r$i"; }
for ($i = 51; $i <= 100; $i++) {
    $rowsD[] = $calc("r$i", $i % 2 ? ['op' => 'sum', 'args' => ['r1', 'r' . ($i - 1)]] : ['op' => 'div', 'args' => [['op' => 'mul', 'args' => ['r' . ($i - 50), ['pct' => '7.5'], 12]], ['pct' => '6.25']]], $dAbove);
    $dAbove[] = "r$i";
}
$dv = apr_sheet_eval($rowsD);
check('B1 גיליון רגיל ומלא (100 שורות, סכומי טווח על כל מה שמעל, היוון) — כל השורות מחושבות; התקציב אינו נוגע בעבודה אמיתית', !array_filter($dv, fn($x) => $x['value'] === null), array_count_values(array_map(fn($x) => $x['reason'] ?? 'ok', $dv)));
// תקרת הצמתים: 40 עוברים, 41 נדחים; טווח סכום נספר כשלושה
$n40 = ['op' => 'add', 'args' => array_merge(array_fill(0, 18, 'r1'), [['op' => 'mul', 'args' => array_fill(0, 10, 'r2')], ['op' => 'mul', 'args' => array_fill(0, 9, 'r2')]])];
$n41 = ['op' => 'add', 'args' => array_merge(array_fill(0, 18, 'r1'), [['op' => 'mul', 'args' => array_fill(0, 10, 'r2')], ['op' => 'mul', 'args' => array_fill(0, 10, 'r2')]])];
$s40 = ['op' => 'add', 'args' => array_merge(array_fill(0, 12, ['op' => 'sum', 'args' => ['r1', 'r2']]), ['r1', 'r1', 'r2'])];
$s41 = ['op' => 'add', 'args' => array_merge(array_fill(0, 12, ['op' => 'sum', 'args' => ['r1', 'r2']]), ['r1', 'r1', 'r2', 'r2'])];
eq([$f($n40)[1], $f($n41)[1], $f($s40)[1], $f($s41)[1], $f($monster, ['r64', 'r65'])[1], strlen((string)json_encode($f($n40)[0])) < 600], [null, 'sheet_formula_long', null, 'sheet_formula_long', 'sheet_formula_long', true],
    'B1 תקרת צמתים לנוסחה: 40 צמתים נשמרים, 41 ← sheet_formula_long (גם כשהם טווחי סכום); הנוסחה של הסוקר נדחית בשמירה; נוסחה מרבית קצרה מ-600 בתים');

// 300 נוסחאות אקראיות מול חישוב ייחוס בלתי תלוי (שברים של שלמים רגילים, עם gcd)
mt_srand(16);
$gcd = function (int $a, int $b): int { $a = abs($a); $b = abs($b); while ($b) [$a, $b] = [$b, $a % $b]; return $a ?: 1; };
$norm = function (int $n, int $d) use ($gcd): array { if ($d < 0) [$n, $d] = [-$n, -$d]; $g = $gcd($n, $d); return [intdiv($n, $g), intdiv($d, $g)]; };
$bad = [];
for ($i = 0; $i < 300 && count($bad) < 3; $i++) {
    $vals = ['r1' => mt_rand(-999, 999), 'r2' => mt_rand(1, 999), 'r3' => mt_rand(1, 99)];
    $ops = ['add', 'sub', 'mul', 'div'];
    $a = ['r1', 'r2', 'r3'][mt_rand(0, 2)];
    $b = ['r2', 'r3'][mt_rand(0, 1)];
    $k = mt_rand(1, 50);
    [$o1, $o2] = [$ops[mt_rand(0, 3)], $ops[mt_rand(0, 3)]];
    $usePct = mt_rand(0, 1) === 1;
    $form = ['op' => $o1, 'args' => [$a, ['op' => $o2, 'args' => [$usePct ? ['pct' => $b] : $b, $k]]]];
    $ref = function (string $op, array $x, array $y) use ($norm): ?array {
        return match ($op) {
            'add' => $norm($x[0] * $y[1] + $y[0] * $x[1], $x[1] * $y[1]), 'sub' => $norm($x[0] * $y[1] - $y[0] * $x[1], $x[1] * $y[1]), 'mul' => $norm($x[0] * $y[0], $x[1] * $y[1]),
            default => $y[0] === 0 ? null : $norm($x[0] * $y[1], $x[1] * $y[0]),
        };
    };
    $inner = $ref($o2, $usePct ? $norm($vals[$b], 100) : [$vals[$b], 1], [$k, 1]);
    $want = $inner === null ? null : $ref($o1, [$vals[$a], 1], $inner);
    $rws = [$in('r1', (string)$vals['r1']), $in('r2', (string)$vals['r2']), $in('r3', (string)$vals['r3']), $calc('r4', $form, ['r1', 'r2', 'r3'])];
    $got = apr_sheet_eval($rws)['r4']['value'];
    $gotN = $got === null ? null : [(int)$got[0], (int)$got[1]];
    if ($gotN !== $want) $bad[] = [$form, $vals, $gotN, $want];
}
eq($bad, [], '300 נוסחאות אקראיות (ארבע הפעולות, pct, קינון, שליליים) זהות לחישוב ייחוס בלתי תלוי — כשבר מצומצם, לא בקירוב');

// ממוצע משוקלל של גישות — חישוב עזר (§6, §10.11)
$it = fn(string $pid, ?string $v, ?int $w) => ['pid' => $pid, 'value' => $v, 'weight' => $w];
$w1 = apr_sheet_weighted([$it('a', '150000000', $M('1')), $it('b', '180000000', $M('3'))]);
$w2 = apr_sheet_weighted([$it('a', '150000000', $M('25')), $it('b', '180000000', $M('75'))]);
$w3 = apr_sheet_weighted([$it('a', '100', $M('1')), $it('b', '100', $M('1')), $it('c', '101', $M('1'))]);
eq([apr_q_round($w1['value'], 1), $w1['value'] === $w2['value'], apr_q_decimal(apr_q_mul($w1['shares']['b'], apr_q(100)), 2), $w3['value'],
    apr_sheet_weighted([$it('a', '100', $M('1')), $it('b', null, $M('1'))]), apr_sheet_weighted([$it('a', '100', $M('1')), $it('b', '200', null)])['block'],
    apr_sheet_weighted([$it('a', '100', 0), $it('b', '200', 0)])['block'], apr_sheet_weighted([$it('a', '100', null), $it('b', '200', null)]), apr_sheet_weighted([$it('a', '100', $M('1'))])['value'],
    apr_q_round(apr_sheet_weighted([$it('a', '-100', $M('1')), $it('b', '300', $M('1'))])['value'], 1)],
    ['172500000', true, '75.00', ['301', '3'], ['block' => 'no_result', 'ref' => 'b', 'value' => null, 'shares' => []], 'weight_missing', 'weights_zero', ['value' => null, 'block' => null, 'ref' => null, 'shares' => []], null, '100'],
    '§6 ממוצע משוקלל: 1, 3 כמו 25, 75; שבר נשמר מדויק; §10.11 גישה בלי תוצאה ← הממוצע אינו מוצג, עם שם הגישה; גישה בלי משקל / סכום אפס ← נחסם; בלי משקולות או גישה אחת — אין מה לחשב');

// קלט מספרי: בלי נקודה צפה, עם סימן
$bads = [];
foreach (['abc', '1e5', '1,000', '0x10', '1.2.3', '--1', '1234567890123', '1.1234567', ' ', true, [1]] as $x) { try { apr_val_dec6($x); $bads[] = $x; } catch (AprError $e) { if ($e->errorCode !== 'sheet_value') $bads[] = $e->errorCode; } }
eq([apr_val_dec6('8500'), apr_val_dec6('-0.15'), apr_val_dec6(-3), apr_val_dec6(0.1), apr_val_dec6(-2.5), apr_val_dec6(''), apr_val_dec6(null), apr_val_dec6('0'), apr_val_dec6('-0'), apr_val_dec6('999999999999.999999'), $bads,
    apr_val_fmt('1500000.5'), apr_val_fmt('-1234567'), apr_val_fmt('999'), apr_val_fmt('0.000001')],
    [8500000000, -150000, -3000000, 100000, -2500000, null, null, 0, 0, 999999999999999999, [' '], '1,500,000.5', '-1,234,567', '999', '0.000001'],
    'קלט עשרוני נקרא כמחרוזת, עם סימן, עד 12 ספרות ו-6 אחרי הנקודה; ריק ← null (לא אפס); טקסט, מספר מדעי, פסיקים וארוך מדי — נדחים; מפריד אלפים — לתצוגה בלבד');

// ═══ חלק ב: ה-API ═════════════════════════════════════════════

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית, שותפה בתיק
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד עם edit — אינה שמאית
$noa = ident('9', 'נועה', 'noa@example.com');      // מתמחה: צפייה בלבד
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיק
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
$noaPid = join_office($avi, $noa, 'noa@example.com', 'trainee');
join_office($avi, $gil, 'gil@example.com', 'appraiser');

function make_profile(string $as): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $as)[1]['profile'];
    $it = ['key' => 'contract', 'group' => '', 'doc_type' => 'rights', 'title' => 'הסכם מכר', 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['flags' => [['key' => 'renovation_planned', 'title' => 'מתוכנן שיפוץ', 'question' => 'האם מתוכנן שיפוץ בנכס?']], 'requirements' => ['groups' => [], 'items' => [$it]], 'tasks' => ['items' => []], 'rules' => []], 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => 'ראשון', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile, bool $renovation, ?string $keyDate = '2026-09-01', bool $start = true): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה ברחוב הרצל', 'purpose' => 'purchase', 'property_type' => 'apartment', 'key_date' => $keyDate, 'flags' => ['renovation_planned' => $renovation]], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'email' => 'moshe@client.example', 'kind' => 'client', 'is_orderer' => true], $as);
    if ($start && ($s = call('POST', 'case-start', ['case' => $c], $as))[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
function pdf(string $mark): string
{
    return "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}
function up(string $as, string $case, string $name): string
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, pdf($name));
    $r = call('POST', 'file-upload', ['case' => $case, 'name' => $name], $as, [], ['env' => ['upload' => $tmp]]);
    if ($r[0] !== 200) throw new RuntimeException('upload: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $r[1]['file']['pid'] ?? $r[1]['file'];
}
$P = make_profile($avi);
$PB = make_profile($boaz);
$C = make_case($avi, $P, true);                    // עם הדגל "מתוכנן שיפוץ"
$C2 = make_case($avi, $P, false, null);            // בלי הדגל, ובלי מועד קובע
$CB = make_case($boaz, $PB, true);                 // משרד ב
$CD = make_case($avi, $P, true, '2026-01-01', false);     // טיוטה
foreach ([[$danaPid, null], [$talPid, ['view' => true, 'edit' => true]], [$noaPid, ['view' => true]]] as [$u, $perms]) {
    call('POST', 'case-member-add', ['case' => $C, 'user' => $u], $avi);
    if ($perms) call('POST', 'case-member-update', ['case' => $C, 'user' => $u, 'perms' => $perms], $avi);
}
$val = fn(string $as, string $case) => call('GET', 'case-valuation', [], $as, ['case' => $case]);
$alts = fn(string $case, string $as = null) => array_column($val($as ?? $GLOBALS['avi'], $case)[1]['alternatives'] ?? [], null, 'pid');
$GLOBALS['avi'] = $avi;
$rv = fn(string $table, string $pid) => (int)apr_val("SELECT rev FROM $table WHERE pid = ?", [$pid]);
$post = fn(string $action, array $body, string $as = null) => call('POST', $action, $body, $as ?? $GLOBALS['avi']);
$audit = fn(string $action, string $pid) => array_map(fn($r) => ['meta' => json_decode($r['meta'], true), 'reason' => $r['reason']], apr_rows('SELECT meta, reason FROM audit_log WHERE action = ? AND entity_pid = ? ORDER BY id', [$action, $pid]));
$raises = function (string $sql, array $args = []): bool { try { apr_exec($sql, $args); return false; } catch (PDOException) { return true; } };
$caseId = fn(string $pid) => (int)apr_val('SELECT id FROM cases WHERE pid = ?', [$pid]);

// 16-A2: תיק עם דגל שיפוץ נפתח עם שתי חלופות; בלי הדגל — אחת; טיוטה — עוד אין
$v1 = $val($avi, $C)[1];
$v2 = $val($avi, $C2)[1];
$a1 = $v1['alternatives'];
eq([array_map(fn($a) => [$a['name'], $a['state_kind'], $a['is_primary'], $a['key_date'], $a['conclusion'], $a['approaches'], $a['assumptions']], $a1),
    array_map(fn($a) => [$a['name'], $a['state_kind'], $a['is_primary'], $a['key_date']], $v2['alternatives']), $val($avi, $CD)[1]['alternatives'], $v1['flags'], $v2['flags'], $v1['perms']],
    [[['מצב קיים', 'existing', true, '2026-09-01', null, [], []], ['בהנחת השלמת השיפוץ', 'after_works', false, '2026-09-01', null, [], []]], [['מצב קיים', 'existing', true, null]], [],
        ['renovation_planned' => true], ['renovation_planned' => false], ['write' => true, 'judge' => true]],
    '16-A2 תיק עם הדגל "מתוכנן שיפוץ" נפתח עם שתי חלופות — existing (ראשית) ו-after_works — עם המועד הקובע של התיק; בלי הדגל — אחת; §10.2 בלי מועד קובע — null; טיוטה — בלי חלופות; המסקנה ריקה');
[$A1, $A2] = array_column($a1, 'pid');
$A3 = $v2['alternatives'][0]['pid'];

// 16-A1: לכל חלופה key_date והנחות משלה — שינוי באחת אינו משנה אחרת
$before = $alts($C)[$A1];
$s1 = $post('alt-save', ['alt' => $A2, 'rev' => $rv('alternatives', $A2), 'name' => 'בהנחת השלמת השיפוץ', 'key_date' => '2027-03-01', 'state_kind' => 'after_works', 'state_note' => 'לאחר שיפוץ מלא לפי התוכנית']);
$as1 = $post('assumption-save', ['alt' => $A2, 'text' => 'העבודות יושלמו לפי התוכנית', 'kind' => 'assumption', 'basis' => 'הצהרת המזמין', 'approval' => 'stated']);
$as0 = $post('assumption-save', ['case' => $C, 'text' => 'לא נבדקה חריגת בנייה', 'kind' => 'limitation']);
$after = $alts($C);
eq([$s1[0], $as1[0], $as0[0], $after[$A1] === $before, $after[$A2]['key_date'], $after[$A1]['key_date'], array_column($after[$A2]['assumptions'], 'text'), $after[$A1]['assumptions'],
    array_map(fn($s) => [$s['text'], $s['kind'], $s['approval']], $val($avi, $C)[1]['case_assumptions']), $after[$A2]['assumptions'][0]['approval'], $after[$A2]['state_note']],
    [200, 200, 200, true, '2027-03-01', '2026-09-01', ['העבודות יושלמו לפי התוכנית'], [], [['לא נבדקה חריגת בנייה', 'limitation', 'unverified']], 'stated', 'לאחר שיפוץ מלא לפי התוכנית'],
    '16-A1 שינוי המועד הקובע והוספת הנחה בחלופה אחת אינם משנים דבר בחלופה האחרת (התשובה שלה זהה לפני ואחרי); הנחה של התיק כולו נשמרת בנפרד');
$AS1 = $as1[1]['assumption'];

// 16-A3: חלופת after_works בלי הנחה שמקושרת למסמך עבודות ← "חסרות הנחות"
$F1 = up($avi, $C, 'works-plan.pdf');
$m0 = [$alts($C)[$A2]['missing_assumptions'], $alts($C)[$A1]['missing_assumptions']];
$post('assumption-save', ['assumption' => $AS1, 'rev' => $rv('assumptions', $AS1), 'text' => 'העבודות יושלמו לפי התוכנית', 'kind' => 'assumption', 'basis' => 'הצהרת המזמין', 'approval' => 'supported', 'file' => $F1]);
$m1 = $alts($C)[$A2];
$post('assumption-save', ['assumption' => $AS1, 'rev' => $rv('assumptions', $AS1), 'text' => 'העבודות יושלמו לפי התוכנית', 'kind' => 'assumption', 'approval' => 'supported']);
$m2 = $alts($C)[$A2]['missing_assumptions'];
$post('assumption-save', ['assumption' => $AS1, 'rev' => $rv('assumptions', $AS1), 'text' => 'העבודות יושלמו לפי התוכנית', 'kind' => 'assumption', 'approval' => 'supported', 'file' => $F1]);
// הנחה של התיק כולו עם מסמך אינה מספיקה לחלופה
$post('assumption-save', ['case' => $C, 'text' => 'תוכנית כללית', 'kind' => 'condition', 'file' => $F1]);
$cOnly = make_case($avi, $P, true);
$cOnlyAlt = array_values(array_filter($val($avi, $cOnly)[1]['alternatives'], fn($a) => $a['state_kind'] === 'after_works'))[0];
$fOnly = up($avi, $cOnly, 'x.pdf');
$post('assumption-save', ['case' => $cOnly, 'text' => 'תוכנית כללית', 'kind' => 'condition', 'file' => $fOnly]);
eq([$m0, $m1['missing_assumptions'], $m1['assumptions'][0]['file']['name'], $m1['assumptions'][0]['approval'], $m2, $alts($C)[$A2]['missing_assumptions'], $cOnlyAlt['missing_assumptions'], $alts($cOnly)[$cOnlyAlt['pid']]['missing_assumptions']],
    [[true, false], false, 'works-plan.pdf', 'supported', true, false, true, true],
    '16-A3 after_works בלי הנחה שמקושרת למסמך ← missing_assumptions; הנחה עם מסמך ועם מצב אישור ← יורד; ניתוק המסמך ← חוזר; חלופת existing אינה מסומנת; הנחה של התיק כולו אינה מספיקה');

// 16-A4: המסקנה אינה מתמלאת לבד, ושמירה בלי נימוק נדחית
$con = fn(string $alt, mixed $value, ?string $reason, string $as = null) => $post('alt-conclude', ['alt' => $alt, 'rev' => $rv('alternatives', $alt), 'value' => $value] + ($reason !== null ? ['reason' => $reason] : []), $as);
$noReason = [code($con($A1, '2100000', null)), code($con($A1, '2100000', '   ')), apr_val('SELECT conclusion_value FROM alternatives WHERE pid = ?', [$A1])];
$ok = $con($A1, '2100000.50', 'לפי גישת ההשוואה, בעיגול');
$c1 = $alts($C)[$A1]['conclusion'];
eq([$noReason, $ok[0], [$c1['value'], $c1['agorot'], $c1['reason'], $c1['by']], $alts($C)[$A2]['conclusion'], code($con($A1, '-5', 'x')), code($con($A1, 'abc', 'x')), code($con($A1, '2100000.50', 'לפי גישת ההשוואה, בעיגול')),
    $raises('UPDATE alternatives SET conclusion_value = 5, rev = rev + 1 WHERE pid = ?', [$A2]), $raises("UPDATE alternatives SET conclusion_value = 5, conclusion_reason = '', conclusion_by = 1, conclusion_at = 'x', rev = rev + 1 WHERE pid = ?", [$A2]),
    $audit('alt.conclude', $A1)[0]],
    [['reason_required', 'reason_required', null], 200, ['2100000.5', '210000050', 'לפי גישת ההשוואה, בעיגול', 'אבי'], null, 'comp_value', 'comp_value', 'nothing_to_change', true, true,
        ['meta' => ['from' => null, 'to' => '2100000.5'], 'reason' => 'לפי גישת ההשוואה, בעיגול']],
    '16-A4 מסקנה בלי נימוק נדחית ושום דבר לא נכתב; עם נימוק — נשמרת עם מי ומתי; החלופה האחרת נשארת ריקה; ה-CHECK במסד דוחה מסקנה בלי נימוק גם ב-SQL ישיר');

// §10.3, §10.4, §10.1: שם כפול; ראשית אחת בדיוק; מחיקה רכה
$new = $post('alt-save', ['case' => $C, 'name' => 'שימוש חלופי', 'state_kind' => 'hypothetical', 'state_note' => 'בהנחת שינוי ייעוד']);
$A4 = $new[1]['alt'];
$dup = [code($post('alt-save', ['case' => $C, 'name' => '  מצב   קיים ', 'state_kind' => 'other'])), code($post('alt-save', ['alt' => $A4, 'rev' => $rv('alternatives', $A4), 'name' => 'מצב קיים', 'state_kind' => 'hypothetical'])),
    code($post('alt-save', ['case' => $C, 'name' => '', 'state_kind' => 'other'])), code($post('alt-save', ['case' => $C, 'name' => 'x', 'state_kind' => 'nope'])), code($post('alt-save', ['case' => $C, 'name' => 'x', 'state_kind' => 'other', 'key_date' => '2026-02-30']))];
$prim = $post('alt-primary', ['alt' => $A4, 'rev' => $rv('alternatives', $A4)]);
$primaries = fn(string $case) => array_keys(array_filter($alts($case), fn($a) => $a['is_primary']));
$p1 = $primaries($C);
$delNoReason = code($post('alt-delete', ['alt' => $A4, 'rev' => $rv('alternatives', $A4)]));
$del = $post('alt-delete', ['alt' => $A4, 'rev' => $rv('alternatives', $A4), 'reason' => 'נוצרה בטעות']);
$row4 = apr_row('SELECT deleted_at, is_primary, delete_reason, name FROM alternatives WHERE pid = ?', [$A4]);
eq([$new[0], $dup, $prim[0], $p1, $delNoReason, $del[0], $del[1]['primary'], $primaries($C), isset($alts($C)[$A4]), $row4['deleted_at'] !== null, $row4['delete_reason'], $row4['name'],
    code($post('alt-delete', ['alt' => $A3, 'rev' => $rv('alternatives', $A3), 'reason' => 'x'])), code($post('alt-save', ['alt' => $A4, 'rev' => $rv('alternatives', $A4), 'name' => 'y', 'state_kind' => 'other'])),
    $raises('DELETE FROM alternatives WHERE pid = ?', [$A1]), $raises('UPDATE alternatives SET is_primary = 1, rev = rev + 1 WHERE pid = ?', [$A2]), $post('alt-save', ['case' => $C, 'name' => 'שימוש חלופי', 'state_kind' => 'other'])[0]],
    [200, ['alt_name_taken', 'alt_name_taken', 'name_required', 'alt_state', 'date_invalid'], 200, [$A4], 'reason_required', 200, $A1, [$A1], false, true, 'נוצרה בטעות', 'שימוש חלופי', 'alt_last', 'not_found', true, true, 200],
    '§10.3 שתי חלופות עם אותו שם (גם ברווחים שונים) — נחסם; §10.4 תמיד ראשית אחת: מחיקת הראשית מעבירה את הסימון לראשונה; §10.1 מחיקה רכה עם נימוק — השורה נשארת; החלופה היחידה אינה נמחקת; שם של חלופה שנמחקה פנוי');

// ── גיליון דרך ה-API ──
$sheetView = fn(string $sheet, string $as = null) => call('GET', 'sheet', [], $as ?? $GLOBALS['avi'], ['sheet' => $sheet]);
$rowSave = fn(string $sheet, array $row, string $as = null) => $post('sheet-row-save', ['sheet' => $sheet, 'rev' => $rv('sheets', $sheet)] + $row, $as);
$SH = $post('sheet-save', ['case' => $C, 'title' => 'היוון הכנסות', 'rounding' => 'none'])[1]['sheet'];
$k = [];
$k[] = $rowSave($SH, ['kind' => 'input', 'label' => 'דמי שכירות חודשיים', 'value' => '8500', 'unit' => '₪ לחודש', 'time_unit' => 'month', 'origin' => 'provided', 'source_note' => 'חוזה שכירות'])[1]['key'];
$k[] = $rowSave($SH, ['kind' => 'calc', 'label' => 'הכנסה שנתית', 'unit' => '₪', 'time_unit' => 'year', 'formula' => ['op' => 'mul', 'args' => ['r1', 12]]])[1]['key'];
$k[] = $rowSave($SH, ['kind' => 'input', 'label' => 'הוצאות שנתיות', 'value' => 12000, 'unit' => '₪', 'time_unit' => 'year', 'origin' => 'estimated'])[1]['key'];
$k[] = $rowSave($SH, ['kind' => 'calc', 'label' => 'הכנסה נטו', 'unit' => '₪', 'time_unit' => 'year', 'formula' => ['op' => 'sub', 'args' => ['r2', 'r3']]])[1]['key'];
$k[] = $rowSave($SH, ['kind' => 'input', 'label' => 'שיעור היוון', 'value' => '6', 'unit' => '%', 'origin' => 'assumed', 'reasoning' => 'שיקול דעת השמאי'])[1]['key'];
$k[] = $rowSave($SH, ['kind' => 'calc', 'label' => 'שווי בהיוון', 'unit' => '₪', 'formula' => ['op' => 'div', 'args' => ['r4', ['pct' => 'r5']]]])[1]['key'];
$sv = $sheetView($SH)[1];
$byKey = array_column($sv['rows'], null, 'key');
eq([$k, $byKey['r2']['result'], $byKey['r4']['result']['dec'], $byKey['r6']['result']['text'], $sv['result']['value'], $sv['result']['label'], $byKey['r2']['text'], $byKey['r6']['text'],
    [$byKey['r1']['origin'], $byKey['r3']['origin'], $byKey['r5']['origin']], $byKey['r1']['value'], $byKey['r1']['value_text'], $byKey['r1']['source_note']],
    [['r1', 'r2', 'r3', 'r4', 'r5', 'r6'], ['dec' => '102000', 'text' => '102,000', 'exact' => true], '90000', '1,500,000', ['agorot' => '150000000', 'nis' => '1500000', 'exact' => null], 'שווי בהיוון',
        ['words' => 'דמי שכירות חודשיים × 12', 'numbers' => '8,500 × 12'], ['words' => 'הכנסה נטו ÷ שיעור היוון%', 'numbers' => '90,000 ÷ 6%'], ['provided', 'estimated', 'assumed'], '8500', '8,500', 'חוזה שכירות'],
    '16-B1 דרך ה-API: r2 = 102,000, r4 = 90,000, r6 = 1,500,000 — בדיוק; 16-D2 כל שורת חישוב נושאת את הנוסחה במילים ובמספרים; 16-D3 לכל קלט — סופק / הוערך / הנחה');

// 16-B4 דרך ה-API: נדחה בשמירה, ושום דבר לא נכתב
$n0 = (int)apr_val('SELECT COUNT(*) FROM sheet_rows');
$r0 = $rv('sheets', $SH);
$rej = [code($rowSave($SH, ['kind' => 'calc', 'label' => 'x', 'formula' => ['op' => 'add', 'args' => ['r1', 'r7']]])), code($rowSave($SH, ['kind' => 'calc', 'label' => 'x', 'formula' => ['op' => 'add', 'args' => ['r1', 'r99']]])),
    code($rowSave($SH, ['kind' => 'calc', 'label' => 'x', 'formula' => ['op' => 'exp', 'args' => ['r1', 2]]])), code($rowSave($SH, ['key' => 'r2', 'kind' => 'calc', 'label' => 'הכנסה שנתית', 'formula' => ['op' => 'mul', 'args' => ['r3', 12]]])),
    code($rowSave($SH, ['key' => 'r2', 'kind' => 'calc', 'label' => 'הכנסה שנתית', 'formula' => ['op' => 'mul', 'args' => ['r2', 12]]])), code($rowSave($SH, ['kind' => 'calc', 'label' => 'x', 'formula' => 'r1 * 12'])),
    code($rowSave($SH, ['kind' => 'calc', 'label' => 'x'])), code($rowSave($SH, ['kind' => 'input', 'label' => 'x', 'value' => '5'])), code($rowSave($SH, ['kind' => 'input', 'label' => 'x', 'value' => 'abc', 'origin' => 'assumed'])),
    code($rowSave($SH, ['kind' => 'input', 'label' => '', 'value' => '5', 'origin' => 'assumed'])), code($rowSave($SH, ['key' => 'r1', 'kind' => 'calc', 'label' => 'x', 'formula' => ['op' => 'add', 'args' => [1, 2]]])),
    code($rowSave($SH, ['kind' => 'macro', 'label' => 'x']))];
eq([$rej, (int)apr_val('SELECT COUNT(*) FROM sheet_rows') === $n0, $rv('sheets', $SH) === $r0, json_decode(apr_val("SELECT formula FROM sheet_rows WHERE key = 'r2' AND sheet_id = (SELECT id FROM sheets WHERE pid = ?)", [$SH]), true)],
    [['sheet_ref', 'sheet_ref', 'sheet_op', 'sheet_ref', 'sheet_ref', 'sheet_formula', 'sheet_formula', 'sheet_origin', 'sheet_value', 'sheet_label', 'sheet_kind', 'sheet_kind'], true, true, ['op' => 'mul', 'args' => ['r1', '12']]],
    '16-B4 דרך ה-API: שורה חדשה שמפנה לשורה שעוד לא קיימת, שורה קיימת שמפנה לשורה שמתחתיה או לעצמה, op לא מוכר וטקסט חופשי — נדחים בשמירה; ערך בלי "סופק / הוערך / הנחה" נדחה; שום שורה לא נכתבה');

// 16-B2 דרך ה-API, ומקרי קצה §10.6, §10.5
$rowSave($SH, ['key' => 'r1', 'kind' => 'input', 'label' => 'דמי שכירות חודשיים', 'value' => '', 'unit' => '₪ לחודש', 'time_unit' => 'month']);
$e = $sheetView($SH)[1];
$eb = array_column($e['rows'], null, 'key');
$rowSave($SH, ['key' => 'r1', 'kind' => 'input', 'label' => 'דמי שכירות חודשיים', 'value' => '8500', 'unit' => '₪ לחודש', 'time_unit' => 'month', 'origin' => 'provided', 'source_note' => 'חוזה שכירות']);
$order = fn(array $o) => $post('sheet-row-order', ['sheet' => $SH, 'rev' => $rv('sheets', $SH), 'order' => $o]);
$ordBad = [code($order(['r2', 'r1', 'r3', 'r4', 'r5', 'r6'])), code($order(['r1', 'r2', 'r3', 'r4', 'r6', 'r5'])), code($order(['r1', 'r2', 'r3'])), code($order(['r1', 'r1', 'r3', 'r4', 'r5', 'r6'])), code($order(['r1', 'r2', 'r3', 'r4', 'r5', 'r9'])),
    code($order(['r1', 'r2', 'r3', 'r4', 'r5', 'r6']))];
$ordOk = $order(['r3', 'r5', 'r1', 'r2', 'r4', 'r6']);
eq([[$eb['r1']['value'], $eb['r2']['result'], $eb['r2']['reason'], $eb['r2']['ref_label'], $eb['r6']['reason'], $eb['r6']['ref']], [$e['result']['value'], $e['result']['reason'], $e['result']['ref_label']],
    $ordBad, $ordOk[0], array_column($sheetView($SH)[1]['rows'], 'key'), $sheetView($SH)[1]['result']['value']['agorot']],
    [[null, null, 'missing', 'דמי שכירות חודשיים', 'missing', 'r1'], [null, 'missing', 'דמי שכירות חודשיים'], ['sheet_ref_below', 'sheet_ref_below', 'sheet_order', 'sheet_order', 'sheet_order', 'nothing_to_change'], 200,
        ['r3', 'r5', 'r1', 'r2', 'r4', 'r6'], '150000000'],
    '16-B2 דרך ה-API: קלט ריק ← "לא ניתן לחשב: <שורה> חסרה", בלי אפס; §10.6 שורת חישוב אינה עולה מעל הקלט שלה (נחסם), סדר חוקי נשמר והתוצאה זהה');

// עיגול הגיליון — פעם אחת, בתוצאה
$post('sheet-save', ['sheet' => $SH, 'rev' => $rv('sheets', $SH), 'title' => 'היוון הכנסות', 'rounding' => '10000']);
$rowSave($SH, ['key' => 'r5', 'kind' => 'input', 'label' => 'שיעור היוון', 'value' => '6.35', 'unit' => '%', 'origin' => 'assumed']);
$rd = $sheetView($SH)[1];
eq([$rd['result']['value']['nis'], $rd['result']['exact']['exact'], $rd['result']['exact']['dec'], array_column($rd['rows'], null, 'key')['r6']['result']['text']], ['1420000', false, '1417322.834646', '1,417,322.834646'],
    'עיגול הגיליון חל פעם אחת, על התוצאה: 90,000 ÷ 6.35% = 1,417,322.83… ← 1,420,000; השורה עצמה מוצגת בלי עיגול, עם סימון שאינה מדויקת');
$rowSave($SH, ['key' => 'r5', 'kind' => 'input', 'label' => 'שיעור היוון', 'value' => '6', 'unit' => '%', 'origin' => 'assumed']);
$post('sheet-save', ['sheet' => $SH, 'rev' => $rv('sheets', $SH), 'title' => 'היוון הכנסות', 'rounding' => 'none']);

// 16-B6, §10.10: תבנית — מבנה ותיאורים בלבד
$tp = $post('sheet-template-save', ['sheet' => $SH, 'name' => 'היוון בסיסי', 'scope' => 'personal']);
$to = $post('sheet-template-save', ['sheet' => $SH, 'name' => 'היוון משרדי', 'scope' => 'office'], $dana);
$tRow = apr_row('SELECT * FROM sheet_templates WHERE pid = ?', [$tp[1]['template']]);
$tRows = json_decode($tRow['rows'], true);
$used = $post('sheet-template-use', ['case' => $C2, 'template' => $tp[1]['template']]);
$uRows = apr_rows('SELECT * FROM sheet_rows WHERE sheet_id = (SELECT id FROM sheets WHERE pid = ?) ORDER BY sort', [$used[1]['sheet']]);
$uView = $sheetView($used[1]['sheet'])[1];
$tl = fn(string $as) => array_column(call('GET', 'sheet-templates', [], $as)[1]['templates'] ?? [], 'scope', 'name');
eq([$tp[0], $to[0], count($tRows), array_values(array_unique(array_merge(...array_map('array_keys', $tRows)))), str_contains($tRow['rows'], '8500') || str_contains($tRow['rows'], '12000') || str_contains($tRow['rows'], 'חוזה שכירות') || str_contains($tRow['rows'], 'שיקול דעת'),
    $used[0], count($uRows), array_filter(array_column($uRows, 'value'), fn($x) => $x !== null), array_filter(array_merge(array_column($uRows, 'origin'), array_column($uRows, 'source_note'), array_column($uRows, 'reasoning'), array_column($uRows, 'fact_id'))),
    array_column($uRows, 'label'), [$uView['result']['value'], $uView['result']['reason']], array_column($uView['rows'], null, 'key')['r6']['formula'],
    $raises("INSERT INTO sheet_templates (pid, office_id, name, rounding, rows, created_at) VALUES ('aaaaaaaaaaaaaaaa', 1, 'x', 'none', '[{\"key\":\"r1\",\"value\":5}]', 'x')"),
    $tl($avi), $tl($dana), $tl($boaz), code($post('sheet-template-use', ['case' => $C, 'template' => $tp[1]['template']], $dana)), code($post('sheet-template-use', ['case' => $CB, 'template' => $to[1]['template']], $boaz)),
    code($post('sheet-template-delete', ['template' => $to[1]['template']], $tal)), code($post('sheet-template-delete', ['template' => $to[1]['template']], $boaz)), $post('sheet-template-delete', ['template' => $to[1]['template']], $avi)[0], code($post('sheet-template-delete', ['template' => $tp[1]['template']], $dana)),
    code($post('sheet-template-save', ['sheet' => $SH, 'name' => 'x', 'scope' => 'world']))],
    [200, 200, 6, ['key', 'kind', 'label', 'unit', 'time_unit', 'formula'], false, 200, 6, [], [], ['הוצאות שנתיות', 'שיעור היוון', 'דמי שכירות חודשיים', 'הכנסה שנתית', 'הכנסה נטו', 'שווי בהיוון'], [null, 'missing'], ['op' => 'div', 'args' => ['r4', ['pct' => 'r5']]],
        true, ['היוון בסיסי' => 'personal', 'היוון משרדי' => 'office'], ['היוון משרדי' => 'office'], [], 'not_found', 'not_found', 'forbidden', 'not_found', 200, 'not_found', 'sheet_template_scope'],
    '16-B6 תבנית אינה שומרת אף value (וגם לא מקור, נימוק או נתון) — רשימת שדות סגורה, ו-CHECK במסד; §10.10; גיליון מתבנית: אותן שורות ונוסחאות, כל הקלטים ריקים, ואין תוצאה; תבנית אישית נראית רק לבעליה, משרדית — למשרד, ומשרד אחר — 404');
$SH2 = $used[1]['sheet'];

// §10.5 דרך ה-API: מחיקת שורה שמפנים אליה
$SH3 = $post('sheet-template-use', ['case' => $C, 'template' => $tp[1]['template'], 'title' => 'עותק לשבירה'])[1]['sheet'];
$post('sheet-row-delete', ['sheet' => $SH3, 'rev' => $rv('sheets', $SH3), 'key' => 'r3']);
$bv = $sheetView($SH3)[1];
$bb = array_column($bv['rows'], null, 'key');
eq([isset($bb['r3']), $bb['r4']['reason'], $bb['r4']['ref'], $bb['r4']['text']['words'], $bv['result']['reason'], $bv['result']['value'], code($post('sheet-template-save', ['sheet' => $SH3, 'name' => 'שבור', 'scope' => 'personal'])),
    $rowSave($SH3, ['kind' => 'input', 'label' => 'חדש', 'value' => '1', 'origin' => 'assumed'])[1]['key'], code($post('sheet-row-delete', ['sheet' => $SH3, 'rev' => $rv('sheets', $SH3), 'key' => 'r3'])),
    $order(['r3', 'r5', 'r1', 'r2', 'r4', 'r6'])[1]['error']['code'] ?? null],
    [false, 'broken_ref', 'r3', 'הכנסה שנתית − שורה שנמחקה', 'broken', null, 'sheet_broken', 'r7', 'not_found', 'nothing_to_change'],
    '§10.5 נוסחה שמפנה לשורה שנמחקה ← "נוסחה שבורה", בלי תוצאה, והגיליון בלי תוצאה סופית; גיליון שבור אינו נשמר כתבנית; מפתח של שורה שנמחקה אינו חוזר (r7, לא r3)');

// ── גישות ומסקנה (§6) ──
$add = fn(string $case, array $values) => $post('comp-add', ['case' => $case, 'values' => $values])[1]['comp'];
$crev = fn(string $pid) => (int)apr_val('SELECT rev FROM comps WHERE pid = ?', [$pid]);
$status = fn(string $comp, string $st, ?string $reason = null) => $post('comp-status', ['comp' => $comp, 'rev' => $crev($comp), 'status' => $st] + ($reason !== null ? ['reason' => $reason] : []));
$K1 = $add($C, ['price' => '2000000', 'deal_date' => '2026-01-10', 'area' => '80', 'area_kind' => 'registered', 'condition' => 'משופץ']);
$K2 = $add($C, ['price' => '2200000', 'deal_date' => '2026-02-10', 'area' => '88', 'area_kind' => 'registered', 'condition' => 'טוב']);
$K3 = $add($C, ['price' => '1800000', 'deal_date' => '2026-03-10', 'area' => '75', 'area_kind' => 'registered']);        // מצב לא ידוע
$status($K1, 'selected');
$status($K2, 'selected');
$G1 = $post('comp-set-save', ['case' => $C, 'name' => 'מצב קיים', 'unit_basis' => 'total', 'method' => 'mean', 'rounding' => 'none'])[1]['set'];
$G2 = $post('comp-set-save', ['case' => $C, 'name' => 'למ"ר', 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none'])[1]['set'];
$setView = fn(string $g, string $as = null) => call('GET', 'comp-set', [], $as ?? $GLOBALS['avi'], ['set' => $g]);
$grev = fn(string $g) => $rv('comp_sets', $g);
$ap = fn(string $alt, array $body, string $as = null) => $post('approach-save', ['alt' => $alt] + $body, $as);
$apNoReason = code($ap($A1, ['kind' => 'comparison', 'comp_set' => $G1]));
$AP1 = $ap($A1, ['kind' => 'comparison', 'comp_set' => $G1, 'reasoning' => 'שוק פעיל עם עסקאות דומות'])[1]['approach'];
$AP2 = $ap($A1, ['kind' => 'income', 'sheet' => $SH, 'reasoning' => 'הנכס מושכר'])[1]['approach'];
$r0 = $alts($C)[$A1];
$apOf = fn(array $alt, string $pid) => array_column($alt['approaches'], null, 'pid')[$pid];
$post('comp-set-conclude', ['set' => $G1, 'rev' => $grev($G1), 'value' => '2100000', 'reason' => 'ממוצע']);
$r1 = $alts($C)[$A1];
eq([$apNoReason, [$apOf($r0, $AP1)['result']['value'], $apOf($r0, $AP1)['result']['note'], $apOf($r0, $AP1)['title']], [$apOf($r0, $AP2)['result']['value']['agorot'], $apOf($r0, $AP2)['result']['source'], $apOf($r0, $AP2)['sheet']['title']],
    $apOf($r1, $AP1)['result']['value']['nis'], $r1['weighted'], $r1['conclusion']['value'], $r0['conclusion']['value'],
    code($ap($A1, ['kind' => 'comparison', 'sheet' => $SH, 'comp_set' => 'aaaaaaaaaaaaaaaa', 'reasoning' => 'x'])), code($ap($A1, ['kind' => 'magic', 'reasoning' => 'x'])),
    code($post('approach-save', ['approach' => $AP1, 'rev' => $rv('approaches', $AP1), 'kind' => 'income', 'reasoning' => 'x']))],
    ['reason_required', [null, 'no_conclusion', 'גישת ההשוואה'], ['150000000', 'computed', 'היוון הכנסות'], '2100000', ['value' => null, 'block' => null, 'ref' => null], '2100000.5', '2100000.5', 'not_found', 'approach_kind', 'approach_kind'],
    '§6 גישה בלי נימוק נדחית; comparison ← המסקנה של קבוצת ההשוואה (בלי מסקנה — אין תוצאה, לא אפס); income ← השורה האחרונה של הגיליון; מסקנת החלופה אינה משתנה מעצמה; בלי משקולות אין ממוצע משוקלל');

// 16-A4 לגישה: תוצאה מוקלדת רק עם נימוק; אינה מתמלאת לבד
$res = fn(string $p, array $body, string $as = null) => $post('approach-result', ['approach' => $p, 'rev' => $rv('approaches', $p)] + $body, $as);
$AP3 = $ap($A1, ['kind' => 'cost', 'reasoning' => 'לבקרה בלבד'])[1]['approach'];
$e0 = $apOf($alts($C)[$A1], $AP3)['result'];
$enterBad = [code($res($AP3, ['source' => 'entered', 'value' => '1900000'])), code($res($AP3, ['source' => 'entered', 'reason' => 'x'])), code($res($AP1, ['source' => 'entered', 'value' => '5', 'reason' => 'x'])), code($res($AP3, ['source' => 'guess'])),
    apr_val('SELECT result_value FROM approaches WHERE pid = ?', [$AP3])];
$entered = $res($AP3, ['source' => 'entered', 'value' => '1900000', 'reason' => 'לפי תחשיב עלות חיצוני שצורף']);
$e1 = $apOf($alts($C)[$A1], $AP3)['result'];
eq([[$e0['value'], $e0['note'], $e0['source']], $enterBad, $entered[0], [$e1['value']['nis'], $e1['source'], $e1['reason'], $e1['by']],
    $raises("UPDATE approaches SET result_value = 5, result_source = 'entered', rev = rev + 1 WHERE pid = ?", [$AP2]), $raises('UPDATE approaches SET result_value = 5, rev = rev + 1 WHERE pid = ?', [$AP2]),
    $raises("UPDATE approaches SET result_source = 'entered', result_value = 5, result_reason = 'x', result_by = 1, result_at = 'x', rev = rev + 1 WHERE pid = ?", [$AP1]), apr_val('SELECT result_value FROM approaches WHERE pid = ?', [$AP2])],
    [[null, 'no_sheet', 'computed'], ['reason_required', 'approach_value', 'approach_source', 'approach_source', null], 200, ['1900000', 'entered', 'לפי תחשיב עלות חיצוני שצורף', 'אבי'], true, true, true, null],
    '16-A4 תוצאה של גישה אינה מתמלאת לבד (גישה בלי גיליון — אין תוצאה); ערך מוקלד בלי נימוק נדחה; גישת ההשוואה אינה מוקלדת; ה-CHECK במסד אוכף; תוצאה מחושבת אינה נשמרת בעמודה');

// משקולות לגישות, ו-§10.11
$wAp = fn(string $p, mixed $w) => $post('approach-save', ['approach' => $p, 'rev' => $rv('approaches', $p), 'kind' => apr_val('SELECT kind FROM approaches WHERE pid = ?', [$p]), 'reasoning' => 'נימוק ' . $p,
    'comp_set' => apr_val('SELECT g.pid FROM approaches p JOIN comp_sets g ON g.id = p.comp_set_id WHERE p.pid = ?', [$p]) ?: null, 'sheet' => apr_val('SELECT s.pid FROM approaches p JOIN sheets s ON s.id = p.sheet_id WHERE p.pid = ?', [$p]) ?: null, 'weight' => $w]);
$wAp($AP1, '70');
$wm = $alts($C)[$A1]['weighted'];
$wAp($AP2, '20');
$wAp($AP3, '10');
$w3 = $alts($C)[$A1];
$res($AP3, ['source' => 'computed']);
$wNo = $alts($C)[$A1];
eq([[$wm['value'], $wm['block'], $wm['ref']], [$w3['weighted']['value']['agorot'], $w3['weighted']['block']], [$apOf($w3, $AP1)['share'], $apOf($w3, $AP2)['share'], $apOf($w3, $AP3)['share']], [$wNo['weighted']['value'], $wNo['weighted']['block'], $wNo['weighted']['ref']],
    $w3['conclusion']['value'], $wNo['conclusion']['value'], code($wAp($AP1, '-1'))],
    [[null, 'weight_missing', 'גישת ההכנסה'], ['196000000', null], ['70.00', '20.00', '10.00'], [null, 'no_result', 'גישת העלות'], '2100000.5', '2100000.5', 'comp_weight'],
    '§6 הממוצע המשוקלל (2,100,000 × 70 + 1,500,000 × 20 + 1,900,000 × 10 = 1,960,000) מוצג כחישוב עזר ואינו נוגע במסקנה; §10.11 גישה אחת בלי תוצאה ← הממוצע אינו מוצג, עם שם הגישה; גישה בלי משקל — נחסם');
$res($AP3, ['source' => 'entered', 'value' => '1900000', 'reason' => 'לפי תחשיב עלות חיצוני שצורף']);

// ── שיוך עסקה ומשקל — לכל קבוצה בנפרד (הכרעת הבקרה ל-16) ──
$wSet = fn(string $g, string $comp, mixed $w, string $as = null) => $post('comp-weight', ['set' => $g, 'comp' => $comp, 'rev' => $grev($g), 'weight' => $w], $as);
$member = fn(string $g, string $comp, bool $on, ?string $reason = null, string $as = null) => $post('comp-set-member', ['set' => $g, 'comp' => $comp, 'rev' => $grev($g), 'member' => $on] + ($reason !== null ? ['reason' => $reason] : []), $as);
$pids = fn(array $view) => array_map(fn($r) => $r['comp']['pid'], $view['rows']);
$wOf = fn(string $g) => array_column(array_map(fn($r) => ['pid' => $r['comp']['pid'], 'w' => $r['weight']], $setView($g)[1]['rows']), 'w', 'pid');
$wSet($G1, $K1, '1');
$wSet($G1, $K2, '3');
$m1 = [$wOf($G1), $wOf($G2)];
$out = $member($G2, $K2, false, 'שטח חריג לקבוצה הזו');
$m2 = [$pids($setView($G1)[1]), $pids($setView($G2)[1]), array_column($setView($G2)[1]['others'], 'pid'), $setView($G1)[1]['summary']['counted'], $setView($G2)[1]['summary']['counted']];
$status($K3, 'selected');
$m3 = [$pids($setView($G1)[1]), $pids($setView($G2)[1])];
$status($K2, 'candidate');
$status($K2, 'selected');
$m4 = $pids($setView($G2)[1]);
$compView = call('GET', 'comp', [], $avi, ['comp' => $K2])[1];
eq([$m1, $out[0], $m2, $m3, $m4, array_map(fn($x) => [$x['set']['name'], $x['member'], $x['weight']], $compView['calcs']), array_key_exists('weight', $compView['comp']),
    code($wSet($G2, $K2, '5')), code($member($G2, $K2, false, 'שטח חריג לקבוצה הזו')), $member($G2, $K2, true)[0], $pids($setView($G2)[1]), code($member($G1, $add($C, ['price' => '5']), true)),
    code($post('comp-weight', ['set' => $G1, 'comp' => $K1, 'rev' => 1, 'weight' => '9'])), $wOf($G1)[$K1], array_column(array_column($alts($C), 'approaches')[0], 'result')[0]['value']['nis']],
    [[[$K1 => '1', $K2 => '3'], [$K1 => null, $K2 => null]], 200, [[$K1, $K2], [$K1], [$K2], 2, 1], [[$K1, $K2, $K3], [$K1, $K3]], [$K1, $K3], [['מצב קיים', true, '3'], ['למ"ר', false, null]], false,
        'comp_not_member', 'nothing_to_change', 200, [$K1, $K2, $K3], 'comp_not_selected', 'comp_changed', '1', '2100000'],
    '16 (הכרעת בקרה): משקל שנקבע בקבוצה אחת אינו קיים בקבוצה אחרת; הוצאה מקבוצה אחת אינה נוגעת באחרת ואינה משנה את מצב העסקה; עסקה שנבחרה מצטרפת לקבוצות, ומי שהוצאה במפורש אינה חוזרת לבד; rev ישן של הקבוצה ← 409');

// 27.4 (§4): עסקה שמצבה לא ידוע אינה נכנסת לקבוצה של "אחרי שיפוץ" בלי נימוק מפורש
$link = $ap($A2, ['kind' => 'comparison', 'comp_set' => $G2, 'reasoning' => 'עסקאות של דירות משופצות']);
$member($G2, $K3, false);
$link2 = $ap($A2, ['kind' => 'comparison', 'comp_set' => $G2, 'reasoning' => 'עסקאות של דירות משופצות']);
$AP4 = $link2[1]['approach'] ?? '';
$K4 = $add($C, ['price' => '2500000', 'deal_date' => '2026-04-01', 'area' => '90', 'area_kind' => 'registered']);       // מצב לא ידוע
$status($K4, 'selected');
$auto = [in_array($K4, $pids($setView($G1)[1]), true), in_array($K4, $pids($setView($G2)[1]), true), $setView($G2)[1]['after_works'], $setView($G1)[1]['after_works']];
$A5 = $post('alt-save', ['case' => $C, 'name' => 'חלופה נוספת', 'state_kind' => 'existing'])[1]['alt'];
$AP5 = $ap($A5, ['kind' => 'comparison', 'comp_set' => $G1, 'reasoning' => 'x'])[1]['approach'];
eq([code($link), $link2[0], $auto, code($member($G2, $K4, true)), code($member($G2, $K3, true)), $member($G2, $K4, true, 'נבדק בשטח: שופצה ב-2025')[0], $setView($G2)[1]['rows'][count($setView($G2)[1]['rows']) - 1]['member_reason'],
    $member($G2, $K1, false)[0], $member($G2, $K1, true)[0],
    code($post('alt-save', ['alt' => $A5, 'rev' => $rv('alternatives', $A5), 'name' => 'חלופה נוספת', 'state_kind' => 'after_works'])), apr_val('SELECT state_kind FROM alternatives WHERE pid = ?', [$A5])],
    ['alt_condition_unknown', 200, [true, false, true, false], 'comp_condition_unknown', 'comp_condition_unknown', 200, 'נבדק בשטח: שופצה ב-2025', 200, 200, 'alt_condition_unknown', 'existing'],
    '§4 / 27.4 קבוצה עם עסקה שמצבה לא ידוע אינה מקושרת לחלופת "אחרי שיפוץ"; עסקה חדשה שנבחרה אינה מצטרפת לבד לקבוצה כזו; שיוך של עסקה שמצבה לא ידוע — רק עם נימוק; עסקה שמצבה ידוע — בלי; חלופה אינה הופכת ל-after_works כשהקבוצה שלה אינה עומדת בכלל');
$post('approach-delete', ['approach' => $AP5, 'rev' => $rv('approaches', $AP5)]);
$post('alt-delete', ['alt' => $A5, 'rev' => $rv('alternatives', $A5), 'reason' => 'בדיקה']);

// גישת השוואה למ"ר: המסקנה × השטח הנישום — חישוב העזר של 15
$FA = $post('fact-add', ['case' => $C, 'attribute' => 'area', 'value' => '85.5', 'unit' => 'sqm', 'area_kind' => 'registered', 'source' => ['kind' => 'user', 'note' => 'מהנסח']])[1]['fact']['pid'];
$post('comp-set-conclude', ['set' => $G2, 'rev' => $grev($G2), 'value' => '25000.50', 'reason' => 'ממוצע למ"ר']);
$noArea = $apOf($alts($C)[$A2], $AP4)['result'];
$post('comp-set-save', ['set' => $G2, 'rev' => $grev($G2), 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none', 'subject_area_fact' => $FA]);
$withArea = $apOf($alts($C)[$A2], $AP4);
eq([[$noArea['value'], $noArea['note']], [$withArea['result']['value']['agorot'], $withArea['result']['value']['exact'], $withArea['per_sqm'], $withArea['subject_area']]], [[null, 'no_subject_area'], ['213754275', null, '25000.5', '85.5']],
    'גישת השוואה בקבוצה למ"ר: בלי שטח נישום — אין תוצאה; עם שטח — 25,000.50 × 85.5 = 2,137,542.75, מדויק');

// ── רכישה ושיפוץ (§7): 16-C1, 16-C2, 16-C3 ──
$snapValues = fn() => [apr_rows('SELECT pid, conclusion_value, conclusion_reason FROM alternatives ORDER BY id'), apr_rows('SELECT pid, result_value, result_source, weight FROM approaches ORDER BY id'),
    apr_rows('SELECT pid, conclusion_value FROM comp_sets ORDER BY id'), apr_rows('SELECT * FROM sheet_rows ORDER BY id'), apr_rows('SELECT set_id, comp_id, active, weight FROM comp_set_members ORDER BY set_id, comp_id'),
    array_map(fn($a) => [$a['conclusion'], array_map(fn($p) => $p['result']['value'], $a['approaches']), $a['weighted']], array_values($alts($C))), array_map(fn($s) => $s['result'], $val($avi, $C)[1]['sheets'])];
$s0 = $snapValues();
$ren = fn(array $body, string $as = null, string $case = null) => $post('renovation-add', ['case' => $case ?? $GLOBALS['C']] + $body, $as);
$GLOBALS['C'] = $C;
$FQ = up($avi, $C, 'quote.pdf');
$R1 = $ren(['kind' => 'contract_price', 'amount' => '2050000', 'item_date' => '2026-06-15', 'scope_note' => 'מחיר החוזה', 'file' => $F1]);
$R2 = $ren(['kind' => 'declared_budget', 'amount' => '300000', 'scope_note' => 'לפי הצהרת הרוכש'], $tal);
$R3 = $ren(['kind' => 'quote', 'amount' => '280000', 'provider' => 'קבלן א', 'item_date' => '2026-07-01', 'scope_note' => 'שיפוץ כללי', 'file' => $FQ]);
$R4 = $ren(['kind' => 'quote', 'provider' => 'קבלן ב', 'file' => $FQ]);                                  // §10.7: הצעה בלי סכום — רק מסמך
$R5 = $ren(['kind' => 'verified_cost', 'amount' => '95000.40', 'scope_note' => 'חשבוניות ששולמו']);
$R6 = $ren(['kind' => 'quote', 'amount' => '310000', 'provider' => 'קבלן א']);                          // §10.8: הצעה נוספת מאותו קבלן — שורה נפרדת
$staleBefore = [$alts($C)[$A2]['stale'], (int)apr_val("SELECT COUNT(*) FROM tasks WHERE origin_key LIKE 'alt-review:%'")];
$R7 = $ren(['kind' => 'quote', 'amount' => '295000', 'supersedes' => $R3[1]['item'], 'scope_note' => 'גרסה מעודכנת']);    // גרסה חדשה של הצעה 1
$s1 = $snapValues();
$rv1 = $val($avi, $C)[1]['renovation'];
$task = apr_row("SELECT * FROM tasks WHERE origin_key = ?", ['alt-review:' . $A2]);
$a2 = $alts($C)[$A2];
eq([$s1 === $s0, [$R1[0], $R2[0], $R3[0], $R4[0], $R5[0], $R6[0], $R7[0]], array_keys($rv1), array_map('count', $rv1)],
    [true, [200, 200, 200, 200, 200, 200, 200], ['contract_price', 'declared_budget', 'quote', 'verified_cost'], ['contract_price' => 1, 'declared_budget' => 1, 'quote' => 4, 'verified_cost' => 1]],
    '16-C1 יצירת כל פריטי הרכישה והשיפוץ אינה משנה שום conclusion_value, שום תוצאת גישה, שום שורת גיליון ושום משקל; 16-C3 ארבעת הסוגים נשמרים ומוחזרים כל אחד ברשימה משלו');
$q = array_column($rv1['quote'], null, 'pid');
eq([array_values(array_diff(array_keys($rv1['quote'][0]), ['pid', 'amount', 'item_date', 'provider', 'version_no', 'supersedes', 'superseded_by', 'scope_note', 'file', 'fact', 'entered_by', 'created_at', 'mine'])),
    array_filter(array_keys($val($avi, $C)[1]), fn($k) => preg_match('/total|(^|_)sum|budget|cost|combined|overall/', $k)), $rv1['contract_price'][0]['amount'], $rv1['contract_price'][0]['item_date'], $rv1['contract_price'][0]['file']['name'],
    $rv1['declared_budget'][0]['entered_by'], $rv1['verified_cost'][0]['amount']['agorot'], [$q[$R4[1]['item']]['amount'], $q[$R4[1]['item']]['file']['name']], [$q[$R6[1]['item']]['version_no'], $q[$R6[1]['item']]['supersedes']]],
    [[], [], ['value' => '2050000', 'agorot' => '205000000'], '2026-06-15', 'works-plan.pdf', 'טל', '9500040', [null, 'quote.pdf'], [1, null]],
    '16-C3 אין שדה מאוחד ואין סכום כולל; כל פריט נושא את מי שהזין ואת המסמך; §10.7 הצעה בלי סכום נשמרת עם amount = null ("לא ידוע"); §10.8 שתי הצעות מאותו קבלן — שתי שורות');
eq([$staleBefore, [$q[$R7[1]['item']]['version_no'], $q[$R7[1]['item']]['supersedes'], $q[$R7[1]['item']]['provider']], [$q[$R3[1]['item']]['superseded_by'], $q[$R3[1]['item']]['amount']['value']], $R7[1]['review'],
    [$a2['stale']['why'], $alts($C)[$A1]['stale']], [$task['kind'], $task['status'], $task['link_type'], $task['link_pid'], str_contains($task['title'], 'בהנחת השלמת השיפוץ'), (int)$task['assignee_user_id'] > 0],
    code($ren(['kind' => 'quote', 'amount' => '1', 'supersedes' => $R3[1]['item']])), code($ren(['kind' => 'declared_budget', 'amount' => '1', 'supersedes' => $R2[1]['item']])), code($ren(['kind' => 'quote', 'amount' => '1', 'supersedes' => $R2[1]['item']])), code($ren(['kind' => 'declared_budget', 'amount' => '1', 'supersedes' => $R6[1]['item']])),
    code($ren(['kind' => 'quote'])), code($ren(['kind' => 'gift', 'amount' => '1'])), code($ren(['kind' => 'quote', 'amount' => '-5'])),
    code($post('renovation-delete', ['item' => $R3[1]['item'], 'reason' => 'x'])), code($post('renovation-delete', ['item' => $R6[1]['item']])), code($post('renovation-delete', ['item' => $R1[1]['item'], 'reason' => 'x'], $tal)),
    $post('renovation-delete', ['item' => $R2[1]['item'], 'reason' => 'הוזן פעמיים'], $tal)[0], $raises('UPDATE renovation_items SET amount = 1 WHERE pid = ?', [$R1[1]['item']]), $raises('DELETE FROM renovation_items WHERE pid = ?', [$R1[1]['item']])],
    [[null, 0], [2, $R3[1]['item'], 'קבלן א'], [$R7[1]['item'], '280000'], 1, ['quote_replaced', null], ['system', 'open', 'alternative', $A2, true, true], 'renovation_superseded', 'renovation_kind', 'renovation_kind', 'renovation_kind', 'renovation_empty', 'renovation_kind', 'comp_value',
        'renovation_superseded', 'reason_required', 'forbidden', 200, true, true],
    '16-C2 הצעת מחיר חדשה שמחליפה קודמת ← משימת סקירה לאחראי, וחלופת after_works מסומנת stale; הקודמת עדיין קיימת (עם הסכום שלה); להצעה גרסה אחת שמחליפה; גרסאות — רק להצעת מחיר; פריט אינו נערך (טריגר) — רק נמחק רך, עם נימוק');
// הצעה נוספת שמחליפה — אותה משימה פתוחה, לא שנייה; "נבדק מחדש" מוריד את הסימון וסוגר את המשימה
$ren(['kind' => 'quote', 'amount' => '299000', 'supersedes' => $R7[1]['item']]);
$tasksOpen = (int)apr_val("SELECT COUNT(*) FROM tasks WHERE origin_key = ? AND status = 'open'", ['alt-review:' . $A2]);
$conBefore = apr_val('SELECT conclusion_value FROM alternatives WHERE pid = ?', [$A1]);
$rc = $post('alt-recheck', ['alt' => $A2, 'rev' => $rv('alternatives', $A2)]);
eq([$tasksOpen, $rc[0], $alts($C)[$A2]['stale'], apr_val("SELECT status FROM tasks WHERE origin_key = ?", ['alt-review:' . $A2]), code($post('alt-recheck', ['alt' => $A2, 'rev' => $rv('alternatives', $A2)])), count($audit('alt.recheck', $A2)),
    apr_val('SELECT conclusion_value FROM alternatives WHERE pid = ?', [$A1]) === $conBefore, count($val($avi, $C)[1]['renovation']['quote'])],
    [1, 200, null, 'done', 'nothing_to_change', 1, true, 5], 'גרסה נוספת — אותה משימה פתוחה; alt-recheck מוריד את הסימון, סוגר את המשימה ונרשם ביומן; כל הגרסאות נשארות ברשימה');

// שינוי בדגל השיפוץ אחרי הפתיחה (§7)
call('POST', 'case-update', ['case' => $C2, 'flags' => ['renovation_planned' => true]], $avi);
$f2 = $val($avi, $C2)[1];
call('POST', 'case-update', ['case' => $C, 'flags' => ['renovation_planned' => false]], $avi);
call('POST', 'case-update', ['case' => $CD, 'flags' => ['renovation_planned' => false]], $avi);
eq([array_map(fn($a) => [$a['name'], $a['state_kind'], $a['is_primary'], $a['missing_assumptions']], $f2['alternatives']), $f2['flags'], $alts($C)[$A2]['stale']['why'] ?? null,
    (int)apr_val("SELECT COUNT(*) FROM tasks WHERE origin_key = ? AND status = 'open'", ['alt-review:' . $A2]), (int)apr_val('SELECT COUNT(*) FROM alternatives WHERE case_id = ?', [$caseId($CD)])],
    [[['מצב קיים', 'existing', true, false], ['בהנחת השלמת השיפוץ', 'after_works', false, true]], ['renovation_planned' => true], 'flag_changed', 1, 0],
    '§7 הדגל "מתוכנן שיפוץ" נדלק אחרי הפתיחה ← נוצרת חלופת after_works; הדגל השתנה ← חלופת after_works מסומנת stale ונפתחת משימת סקירה; בטיוטה — דבר אינו נוצר');
call('POST', 'case-update', ['case' => $C, 'flags' => ['renovation_planned' => true]], $avi);
$post('alt-recheck', ['alt' => $A2, 'rev' => $rv('alternatives', $A2)]);

// 16-C4: fact שמקושר לשורת קלט השתנה ← הגיליון stale
$FR = $post('fact-add', ['case' => $C, 'attribute' => 'declared_budget', 'value' => '300000', 'unit' => 'ILS', 'source' => ['kind' => 'user', 'note' => 'הצהרה']])[1]['fact']['pid'] ?? null;
$linkRow = $rowSave($SH, ['key' => 'r1', 'kind' => 'input', 'label' => 'דמי שכירות חודשיים', 'value' => '8500', 'unit' => '₪ לחודש', 'time_unit' => 'month', 'origin' => 'provided', 'source_note' => 'חוזה שכירות', 'fact' => $FA]);
$st0 = [$sheetView($SH)[1]['sheet']['stale'], array_column($sheetView($SH)[1]['rows'], null, 'key')['r1']['fact']['pid'] ?? null];
call('POST', 'fact-correct', ['fact' => $FA, 'rev' => (int)apr_val('SELECT rev FROM facts WHERE pid = ?', [$FA]), 'value' => '86', 'unit' => 'sqm', 'area_kind' => 'registered', 'note' => 'תיקון'], $avi);
$st1 = [$sheetView($SH)[1]['sheet']['stale'], $apOf($alts($C)[$A1], $AP2)['sheet']['stale'], array_column($val($avi, $C)[1]['sheets'], 'stale', 'pid')[$SH], $sheetView($SH3)[1]['sheet']['stale']];
$rowSave($SH, ['key' => 'r3', 'kind' => 'input', 'label' => 'הוצאות שנתיות', 'value' => '12000', 'unit' => '₪', 'time_unit' => 'year', 'origin' => 'provided']);      // שמירה רגילה אינה מורידה את הסימון
$st2 = $sheetView($SH)[1]['sheet']['stale'];
$valueKept = array_column($sheetView($SH)[1]['rows'], null, 'key')['r1']['value'];
$rck = $post('sheet-recheck', ['sheet' => $SH, 'rev' => $rv('sheets', $SH)]);
$st3 = $sheetView($SH)[1]['sheet']['stale'];
// הנחה שמקושרת לנתון: החלופה מסומנת כשהנתון משתנה
$post('assumption-save', ['alt' => $A1, 'text' => 'השטח לפי הנסח', 'kind' => 'assumption', 'fact' => $FA]);
$fs0 = $alts($C)[$A1]['facts_stale'];
call('POST', 'fact-correct', ['fact' => $FA, 'rev' => (int)apr_val('SELECT rev FROM facts WHERE pid = ?', [$FA]), 'value' => '87', 'unit' => 'sqm', 'area_kind' => 'registered', 'note' => 'תיקון נוסף'], $avi);
$fs1 = [$alts($C)[$A1]['facts_stale'], $alts($C)[$A2]['facts_stale'], $sheetView($SH)[1]['sheet']['stale']];
$post('alt-recheck', ['alt' => $A1, 'rev' => $rv('alternatives', $A1)]);
$post('sheet-recheck', ['sheet' => $SH, 'rev' => $rv('sheets', $SH)]);
eq([$linkRow[0], $st0, $st1, $st2, $valueKept, $rck[0], $st3, count($audit('sheet.recheck', $SH)) >= 1, code($post('sheet-recheck', ['sheet' => $SH, 'rev' => $rv('sheets', $SH)])), $fs0, $fs1, $alts($C)[$A1]['facts_stale'],
    code($rowSave($SH, ['key' => 'r3', 'kind' => 'input', 'label' => 'הוצאות שנתיות', 'value' => '12000', 'origin' => 'provided', 'fact' => 'aaaaaaaaaaaaaaaa']))],
    [200, [false, $FA], [true, true, true, false], true, '8500', 200, false, true, 'nothing_to_change', false, [true, false, true], false, 'not_found'],
    '16-C4 נתון שמקושר לשורת קלט השתנה ← הגיליון stale (גם בגישה שנשענת עליו); הערך שהוזן אינו משתנה לבד; שמירה רגילה אינה מורידה את הסימון — רק "נבדק מחדש", שנרשם ביומן; גיליון אחר אינו מסומן');

// קובץ שאינו גלוי למבקש (apr_file_visible) אינו ניתן לקישור — לא בהנחה, לא בשורת קלט ולא בפריט שיפוץ
$GLOBALS['apr_file_visible'] = fn() => false;
$hidden = [code($post('assumption-save', ['alt' => $A1, 'text' => 'x', 'kind' => 'assumption', 'file' => $F1])), code($rowSave($SH, ['key' => 'r3', 'kind' => 'input', 'label' => 'הוצאות שנתיות', 'value' => '12000', 'origin' => 'provided', 'file' => $F1])),
    code($ren(['kind' => 'quote', 'file' => $F1])), $alts($C)[$A2]['assumptions'][0]['file']];
unset($GLOBALS['apr_file_visible']);
eq([$hidden, $alts($C)[$A2]['assumptions'][0]['file']['name']], [['not_found', 'not_found', 'not_found', null], 'works-plan.pdf'], 'קובץ שאינו גלוי למבקש אינו ניתן לקישור (404), ואינו מוחזר בתשובה — ההכרעה ב-apr_file_visible');

// ── rev ועריכה במקביל ──
$staleRev = $rv('alternatives', $A1);
$post('alt-save', ['alt' => $A1, 'rev' => $staleRev, 'name' => 'מצב קיים', 'key_date' => '2026-09-02', 'state_kind' => 'existing']);
$c409 = $post('alt-conclude', ['alt' => $A1, 'rev' => $staleRev, 'value' => '1', 'reason' => 'דריסה'], $dana);
$shRev = $rv('sheets', $SH);
$rowSave($SH, ['key' => 'r3', 'kind' => 'input', 'label' => 'הוצאות שנתיות', 'value' => '12000', 'unit' => '₪', 'time_unit' => 'year', 'origin' => 'estimated']);
$r409 = $post('sheet-row-save', ['sheet' => $SH, 'rev' => $shRev, 'key' => 'r5', 'kind' => 'input', 'label' => 'שיעור היוון', 'value' => '1', 'unit' => '%', 'origin' => 'assumed'], $dana);
$apRev = $rv('approaches', $AP3);
$wAp($AP3, '11');
$asRev = $rv('assumptions', $AS1);
$post('assumption-save', ['assumption' => $AS1, 'rev' => $asRev, 'text' => 'העבודות יושלמו לפי התוכנית המאושרת', 'kind' => 'assumption', 'approval' => 'supported', 'file' => $F1]);
eq([[$c409[0], code($c409), $c409[1]['error']['by'] ?? null, str_contains($c409[1]['error']['message'] ?? '', 'אבי')], apr_val('SELECT conclusion_value FROM alternatives WHERE pid = ?', [$A1]), [$r409[0], code($r409)],
    apr_val("SELECT value FROM sheet_rows WHERE key = 'r5' AND sheet_id = (SELECT id FROM sheets WHERE pid = ?)", [$SH]), code($post('approach-result', ['approach' => $AP3, 'rev' => $apRev, 'source' => 'computed'])),
    code($post('assumption-delete', ['assumption' => $AS1, 'rev' => $asRev])), code($post('alt-save', ['alt' => $A1, 'name' => 'x', 'state_kind' => 'existing'])), code($post('alt-save', ['alt' => $A1, 'rev' => '3', 'name' => 'x', 'state_kind' => 'existing'])),
    code($post('alt-primary', ['alt' => $A2, 'rev' => 1])), $primaries($C), $raises('UPDATE alternatives SET name = ? WHERE pid = ?', ['עקיפה', $A1]), $raises('UPDATE sheets SET title = ?, rev = rev + 2 WHERE pid = ?', ['עקיפה', $SH])],
    [[409, 'val_changed', 'אבי', true], 210000050, [409, 'val_changed'], 6000000, 'val_changed', 'val_changed', 'rev_required', 'rev_required', 'val_changed', [$A1], true, true],
    'rev: שמירה עם גרסה ישנה (חלופה, שורת גיליון, גישה, הנחה) ← 409 "עודכן בידי <שם>", ושום דבר לא נכתב; בלי rev — נדחה; הטריגר במסד דוחה עדכון שאינו מקדם את rev באחד');

// ── הרשאות (§9) ──
$n0 = [(int)apr_val('SELECT COUNT(*) FROM alternatives'), (int)apr_val('SELECT COUNT(*) FROM assumptions'), (int)apr_val('SELECT COUNT(*) FROM approaches'), (int)apr_val('SELECT COUNT(*) FROM sheets'), (int)apr_val('SELECT COUNT(*) FROM sheet_templates'),
    (int)apr_val("SELECT COUNT(*) FROM sheet_rows WHERE kind <> 'input'"), (int)apr_val('SELECT SUM(rev) FROM alternatives'), (int)apr_val('SELECT SUM(rev) FROM approaches'), (int)apr_val('SELECT SUM(rev) FROM comp_sets')];
$judgeOnly = fn(string $who) => [
    code($post('alt-save', ['case' => $C, 'name' => 'של ' . $who, 'state_kind' => 'other'], $who)), code($post('alt-save', ['alt' => $A1, 'rev' => $rv('alternatives', $A1), 'name' => 'x', 'state_kind' => 'other'], $who)),
    code($post('alt-primary', ['alt' => $A2, 'rev' => $rv('alternatives', $A2)], $who)), code($post('alt-delete', ['alt' => $A2, 'rev' => $rv('alternatives', $A2), 'reason' => 'x'], $who)),
    code($post('alt-conclude', ['alt' => $A2, 'rev' => $rv('alternatives', $A2), 'value' => '5', 'reason' => 'x'], $who)), code($post('alt-recheck', ['alt' => $A2, 'rev' => $rv('alternatives', $A2)], $who)),
    code($post('assumption-save', ['alt' => $A1, 'text' => 'x', 'kind' => 'assumption'], $who)), code($post('assumption-save', ['case' => $C, 'text' => 'x', 'kind' => 'assumption'], $who)),
    code($post('assumption-delete', ['assumption' => $AS1, 'rev' => $rv('assumptions', $AS1)], $who)), code($ap($A1, ['kind' => 'other', 'reasoning' => 'x'], $who)),
    code($res($AP3, ['source' => 'computed'], $who)), code($post('approach-delete', ['approach' => $AP3, 'rev' => $rv('approaches', $AP3)], $who)),
    code($post('sheet-save', ['case' => $C, 'title' => 'x', 'rounding' => 'none'], $who)), code($post('sheet-save', ['sheet' => $SH, 'rev' => $rv('sheets', $SH), 'title' => 'x', 'rounding' => 'none'], $who)),
    code($rowSave($SH, ['kind' => 'calc', 'label' => 'x', 'formula' => ['op' => 'add', 'args' => ['r1', 1]]], $who)), code($rowSave($SH, ['key' => 'r2', 'kind' => 'calc', 'label' => 'x', 'formula' => ['op' => 'mul', 'args' => ['r1', 1]]], $who)),
    code($rowSave($SH, ['kind' => 'note', 'label' => 'x'], $who)), code($post('sheet-row-delete', ['sheet' => $SH, 'rev' => $rv('sheets', $SH), 'key' => 'r1'], $who)),
    code($post('sheet-row-order', ['sheet' => $SH, 'rev' => $rv('sheets', $SH), 'order' => ['r5', 'r3', 'r1', 'r2', 'r4', 'r6']], $who)), code($post('sheet-delete', ['sheet' => $SH3, 'rev' => $rv('sheets', $SH3)], $who)),
    code($post('sheet-recheck', ['sheet' => $SH, 'rev' => $rv('sheets', $SH)], $who)), code($post('sheet-template-save', ['sheet' => $SH, 'name' => 'x', 'scope' => 'personal'], $who)),
    code($post('sheet-template-use', ['case' => $C, 'template' => $tp[1]['template']], $who)), code($wSet($G1, $K1, '9', $who)), code($member($G1, $K1, false, null, $who))];
$talDenied = $judgeOnly($tal);
$talInput = $rowSave($SH3, ['kind' => 'input', 'label' => 'קלט של טל', 'value' => '77', 'origin' => 'provided'], $tal);
$talEdit = $rowSave($SH3, ['key' => $talInput[1]['key'] ?? '', 'kind' => 'input', 'label' => 'קלט של טל', 'value' => '78', 'origin' => 'estimated'], $tal);
$noaDenied = array_merge($judgeOnly($noa), [code($ren(['kind' => 'quote', 'amount' => '1'], $noa)), code($rowSave($SH3, ['kind' => 'input', 'label' => 'x', 'value' => '1', 'origin' => 'assumed'], $noa)),
    code($post('renovation-delete', ['item' => $R1[1]['item'], 'reason' => 'x'], $noa))]);
$n1 = [(int)apr_val('SELECT COUNT(*) FROM alternatives'), (int)apr_val('SELECT COUNT(*) FROM assumptions'), (int)apr_val('SELECT COUNT(*) FROM approaches'), (int)apr_val('SELECT COUNT(*) FROM sheets'), (int)apr_val('SELECT COUNT(*) FROM sheet_templates'),
    (int)apr_val("SELECT COUNT(*) FROM sheet_rows WHERE kind <> 'input'"), (int)apr_val('SELECT SUM(rev) FROM alternatives'), (int)apr_val('SELECT SUM(rev) FROM approaches'), (int)apr_val('SELECT SUM(rev) FROM comp_sets')];
eq([array_unique($talDenied), count($talDenied), [$talInput[0], $talEdit[0]], array_unique($noaDenied), count($noaDenied), $n1 === $n0, $val($tal, $C)[1]['perms'], $val($noa, $C)[1]['perms'], $val($noa, $C)[0], $sheetView($SH, $noa)[0],
    $post('alt-conclude', ['alt' => $A2, 'rev' => $rv('alternatives', $A2), 'value' => '2500000', 'reason' => 'לפי גישת ההשוואה לאחר שיפוץ'], $dana)[0]],
    [['forbidden'], 25, [200, 200], ['forbidden'], 28, true, ['write' => true, 'judge' => false], ['write' => false, 'judge' => false], 200, 200, 200],
    '§9 עובדת משרד עם edit: מזינה פריטי שיפוץ ושורות קלט — ואינה נוגעת בחלופות, בהנחות, בגישות, בנוסחאות, במשקולות, במסקנות או בתבניות (25 פעולות, 403, ושום דבר לא נכתב); צפייה בלבד — רואה ואינה כותבת דבר; שמאית שותפה — מסיקה');

// 16-C5: בידוד — משרד אחר, ומי שאינו משתתף בתיק
$SHB = $post('sheet-save', ['case' => $CB, 'title' => 'של משרד ב', 'rounding' => 'none'], $boaz)[1]['sheet'];
$fake = 'zzzzzzzzzzzzzzzz';
$iso = fn(string $who, array $ids) => [
    call('GET', 'case-valuation', [], $who, ['case' => $ids['case']]), call('GET', 'sheet', [], $who, ['sheet' => $ids['sheet']]),
    $post('alt-save', ['case' => $ids['case'], 'name' => 'x', 'state_kind' => 'other'], $who), $post('alt-save', ['alt' => $ids['alt'], 'rev' => 1, 'name' => 'x', 'state_kind' => 'other'], $who), $post('alt-primary', ['alt' => $ids['alt'], 'rev' => 1], $who),
    $post('alt-delete', ['alt' => $ids['alt'], 'rev' => 1, 'reason' => 'x'], $who), $post('alt-conclude', ['alt' => $ids['alt'], 'rev' => 1, 'value' => '1', 'reason' => 'x'], $who), $post('alt-recheck', ['alt' => $ids['alt'], 'rev' => 1], $who),
    $post('assumption-save', ['case' => $ids['case'], 'text' => 'x', 'kind' => 'assumption'], $who), $post('assumption-save', ['alt' => $ids['alt'], 'text' => 'x', 'kind' => 'assumption'], $who),
    $post('assumption-save', ['assumption' => $ids['assumption'], 'rev' => 1, 'text' => 'x', 'kind' => 'assumption'], $who), $post('assumption-delete', ['assumption' => $ids['assumption'], 'rev' => 1], $who),
    $post('approach-save', ['alt' => $ids['alt'], 'kind' => 'other', 'reasoning' => 'x'], $who), $post('approach-save', ['approach' => $ids['approach'], 'rev' => 1, 'kind' => 'other', 'reasoning' => 'x'], $who),
    $post('approach-result', ['approach' => $ids['approach'], 'rev' => 1, 'source' => 'computed'], $who), $post('approach-delete', ['approach' => $ids['approach'], 'rev' => 1], $who),
    $post('sheet-save', ['case' => $ids['case'], 'title' => 'x', 'rounding' => 'none'], $who), $post('sheet-save', ['sheet' => $ids['sheet'], 'rev' => 1, 'title' => 'x', 'rounding' => 'none'], $who),
    $post('sheet-row-save', ['sheet' => $ids['sheet'], 'rev' => 1, 'kind' => 'input', 'label' => 'x'], $who), $post('sheet-row-delete', ['sheet' => $ids['sheet'], 'rev' => 1, 'key' => 'r1'], $who),
    $post('sheet-row-order', ['sheet' => $ids['sheet'], 'rev' => 1, 'order' => ['r1']], $who), $post('sheet-delete', ['sheet' => $ids['sheet'], 'rev' => 1], $who), $post('sheet-recheck', ['sheet' => $ids['sheet'], 'rev' => 1], $who),
    $post('sheet-template-save', ['sheet' => $ids['sheet'], 'name' => 'x', 'scope' => 'personal'], $who), $post('sheet-template-use', ['case' => $ids['case'], 'template' => $ids['template']], $who),
    $post('renovation-add', ['case' => $ids['case'], 'kind' => 'quote', 'amount' => '1'], $who), $post('renovation-delete', ['item' => $ids['item'], 'reason' => 'x'], $who),
    $post('comp-weight', ['set' => $ids['set'], 'comp' => $ids['comp'], 'rev' => 1, 'weight' => '1'], $who), $post('comp-set-member', ['set' => $ids['set'], 'comp' => $ids['comp'], 'rev' => 1, 'member' => false], $who)];
$real = ['case' => $C, 'alt' => $A1, 'assumption' => $AS1, 'approach' => $AP1, 'sheet' => $SH, 'template' => $tp[1]['template'], 'item' => $R1[1]['item'], 'set' => $G1, 'comp' => $K1];
$fakes = array_fill_keys(array_keys($real), $fake);
$snapAll = fn() => array_map(fn($t) => hash('sha256', json_encode(apr_rows("SELECT * FROM $t ORDER BY 1"))), ['alternatives', 'assumptions', 'approaches', 'sheets', 'sheet_rows', 'renovation_items', 'comp_set_members', 'sheet_templates']);
$h0 = $snapAll();
$isoB = $iso($boaz, $real);
$isoFake = $iso($boaz, $fakes);
$isoGil = $iso($gil, $real);
$isoGilFake = $iso($gil, $fakes);
// שילוב של מזהים: התיק שלי, והמזהה-הבן של משרד אחר
$cross = [code($post('approach-save', ['alt' => $A1, 'kind' => 'income', 'sheet' => $SHB, 'reasoning' => 'x'])), code($post('assumption-save', ['alt' => $A1, 'text' => 'x', 'kind' => 'assumption', 'file' => up($boaz, $CB, 'b.pdf')])),
    code($ren(['kind' => 'quote', 'amount' => '1', 'supersedes' => $R3[1]['item']], $boaz, $CB)), code($post('sheet-template-use', ['case' => $CB, 'template' => $tp[1]['template']], $boaz)),
    code($post('comp-weight', ['set' => $G1, 'comp' => $post('comp-add', ['case' => $C2, 'values' => ['price' => '9']])[1]['comp'], 'rev' => $grev($G1), 'weight' => '1'])),
    code($post('comp-set-member', ['set' => $G1, 'comp' => apr_val('SELECT pid FROM comps WHERE case_id = ?', [$caseId($C2)]), 'rev' => $grev($G1), 'member' => true]))];
eq([array_values(array_unique(array_map(fn($r) => $r[0] . ':' . code($r), $isoB))), json_encode($isoB) === json_encode($isoFake), array_values(array_unique(array_map(fn($r) => $r[0] . ':' . code($r), $isoGil))), json_encode($isoGil) === json_encode($isoGilFake),
    count($isoB), $snapAll() === $h0, $cross, call('GET', 'sheet-templates', [], $boaz)[1]['templates']],
    [['404:not_found'], true, ['404:not_found'], true, 29, true, ['not_found', 'not_found', 'not_found', 'not_found', 'not_found', 'not_found'], []],
    '16-C5 בידוד: חלופות, הנחות, גישות, גיליונות, תבניות, פריטי שיפוץ ושיוך לקבוצה של תיק ממשרד אחר ← 404, זהה בייט-לבייט למזהה שאינו קיים; כך גם לשמאי מאותו משרד שאינו משתתף בתיק; מזהה-בן של תיק אחר בתוך התיק שלי — 404; שום דבר לא נכתב');

// ── שיתוף (05): שותף ממשרד אחר ──
$share = function (array $p) use ($avi, $C) {
    $body = $p + ['kind' => 'cross_office', 'email' => 'boaz@example.com', 'perms' => [], 'days' => 0];
    $pv = call('POST', 'share-preview', ['case' => $C] + $body, $avi);
    if ($pv[0] !== 200) throw new RuntimeException('share-preview: ' . json_encode($pv[1], JSON_UNESCAPED_UNICODE));
    $s = call('POST', 'share-create', ['case' => $C, 'preview' => $pv[1]['preview']] + $body, $avi)[1]['share'];
    call('POST', 'share-respond', ['share' => $s, 'accept' => true], $GLOBALS['boaz']);
    return $s;
};
$GLOBALS['boaz'] = $boaz;
$unshare = fn(string $s) => call('POST', 'share-revoke', ['share' => $s], $avi);
$S1 = $share(['scope' => 'all']);                                                            // כל התיק, בלי see_internal
$noInternal = [$val($boaz, $C)[0], $sheetView($SH, $boaz)[0]];
$unshare($S1);
$S2 = $share(['scope' => 'items', 'items' => [['type' => 'comps']], 'perms' => ['see_internal' => true]]);    // רק "עסקאות וחישובים", עם see_internal
$pv = $val($boaz, $C);
$psh = $sheetView($SH, $boaz);
$pjson = json_encode([$pv[1], $psh[1]], JSON_UNESCAPED_UNICODE);
$pMut = $iso($boaz, $real);
$pa1 = array_column($pv[1]['alternatives'], null, 'pid')[$A1];
$pa2 = array_column($pv[1]['alternatives'], null, 'pid')[$A2];
$unshare($S2);
$S3 = $share(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => $F1]], 'perms' => ['see_internal' => true]]);     // שיתוף פריטים בלי "עסקאות וחישובים"
$noComps = [$val($boaz, $C)[0], $sheetView($SH, $boaz)[0]];
$unshare($S3);
$S4 = $share(['scope' => 'all', 'perms' => ['see_internal' => true]]);                                // כל התיק עם see_internal: רואה גם את נתוני התיק
$whole = $val($boaz, $C);
$wholeFile = array_column($whole[1]['alternatives'], null, 'pid')[$A2]['assumptions'][0]['file']['name'] ?? null;
$wholeRow = array_column($sheetView($SH, $boaz)[1]['rows'], null, 'key')['r1'];
$unshare($S4);
eq([$noInternal, [$pv[0], $psh[0]], $pv[1]['perms'], $pv[1]['renovation'], $pv[1]['pick'], array_filter(array_column($pa1['assumptions'], 'fact')), array_column($psh[1]['rows'], null, 'key')['r1']['fact'], array_column($psh[1]['rows'], null, 'key')['r1']['value'],
    str_contains($pjson, $FA) || str_contains($pjson, '2050000') || str_contains($pjson, 'קבלן א') || str_contains($pjson, 'works-plan.pdf') || str_contains($pjson, $F1), $pa1['conclusion']['value'], array_column($pa2['assumptions'], 'file'),
    array_values(array_unique(array_map(fn($r) => $r[0] . ':' . code($r), array_slice($pMut, 2)))), $noComps, [$whole[0], count($whole[1]['renovation']['quote']), $whole[1]['pick'], $wholeRow['fact']['pid'], $wholeFile], [$val($boaz, $C)[0], $sheetView($SH, $boaz)[0]]],
    [[404, 404], [200, 200], ['write' => false, 'judge' => false], null, ['facts' => [], 'files' => []], [], null, '8500', false, '2100000.5', [null], ['404:not_found'], [404, 404],
        [200, 5, ['facts' => [], 'files' => []], $FA, 'works-plan.pdf'], [404, 404]],
    '05 שותף ממשרד אחר: בלי see_internal — 404; עם "עסקאות וחישובים" ו-see_internal — רואה חלופות וגיליון, **בלי** פריטי רכישה ושיפוץ, בלי רשומת נתון ובלי קובץ שאינו בהיקף (B2); כל 27 הפעולות המשנות — 404; שיתוף כל התיק עם see_internal — רואה גם את נתוני התיק; אחרי ביטול — 404');

// ── תיק סגור ──
$hC = $snapAll();
call('POST', 'case-status', ['case' => $C, 'status' => 'closed', 'reason' => 'סיום'], $avi);
$closed = array_slice($iso($avi, $real), 2);
eq([array_values(array_unique(array_map('code', $closed))), count($closed), $snapAll() === $hC, $val($avi, $C)[0], $val($avi, $C)[1]['perms'], $sheetView($SH)[1]['result']['value']['agorot']],
    [['case_closed'], 27, true, 200, ['write' => false, 'judge' => false], '150000000'], 'תיק סגור: כל 27 הפעולות המשנות נחסמות (409) ושום שורה לא משתנה; הצפייה והחישוב נשארים');
call('POST', 'case-status', ['case' => $C, 'status' => 'collecting', 'reason' => 'פתיחה מחדש'], $avi);

// CSRF ושיטה
eq([code(call('POST', 'alt-conclude', ['alt' => $A1, 'rev' => $rv('alternatives', $A1), 'value' => '1', 'reason' => 'x'], $avi, [], ['csrf' => 'wrong'])), code(call('POST', 'renovation-add', ['case' => $C, 'kind' => 'quote', 'amount' => '1'], $avi, [], ['csrf' => ''])),
    code(call('GET', 'alt-conclude', [], $avi, ['alt' => $A1, 'rev' => 1, 'value' => '1', 'reason' => 'x'])), code(call('POST', 'case-valuation', ['case' => $C], $avi)), code(call('GET', 'sheet-row-save', [], $avi, ['sheet' => $SH])),
    array_values(array_diff(['case-valuation', 'sheet', 'sheet-templates'], APR_READS)), array_values(array_filter(array_keys(APR_ACCESS), fn($a) => preg_match('/^(alt-|assumption-|approach-|sheet-|renovation-)/', $a) && in_array($a, APR_READS, true) && $a !== 'sheet-templates')),
    array_values(array_unique(array_map(fn($a) => APR_ACCESS[$a], array_filter(array_keys(APR_ACCESS), fn($a) => preg_match('/^(alt-|assumption-|approach-|sheet-|renovation-)/', $a)))))],
    ['csrf', 'csrf', 'method', 'method', 'method', [], [], ['member']], 'כל פעולה משנה: POST עם X-CSRF; קריאה — GET בלבד; כל הפעולות המשנות של הפרוסה בכלל member (סגורות לשיתוף)');

// ── XSS: טקסט חופשי נשמר כפי שהוא, ומוחזר כ-JSON; המסך מציג רק דרך esc() ──
$x = '<img src=x onerror=alert(1)>"\'&';
$XA = $post('alt-save', ['case' => $C2, 'name' => $x, 'state_kind' => 'other', 'state_note' => $x])[1]['alt'];
$post('assumption-save', ['alt' => $XA, 'text' => $x, 'kind' => 'assumption', 'basis' => $x]);
$XS = $post('sheet-save', ['case' => $C2, 'title' => $x, 'rounding' => 'none'])[1]['sheet'];
$rowSave($XS, ['kind' => 'input', 'label' => $x, 'value' => '5', 'unit' => '<b>', 'origin' => 'assumed', 'source_note' => $x, 'reasoning' => $x]);
$rowSave($XS, ['kind' => 'calc', 'label' => $x, 'formula' => ['op' => 'mul', 'args' => ['r1', 2]], 'reasoning' => $x]);
$post('approach-save', ['alt' => $XA, 'kind' => 'other', 'title' => $x, 'sheet' => $XS, 'reasoning' => $x]);
$post('alt-conclude', ['alt' => $XA, 'rev' => $rv('alternatives', $XA), 'value' => '1', 'reason' => $x]);
$post('renovation-add', ['case' => $C2, 'kind' => 'quote', 'amount' => '1', 'provider' => $x, 'scope_note' => $x]);
$xv = $val($avi, $C2)[1];
$xa = array_column($xv['alternatives'], null, 'pid')[$XA];
$xs = $sheetView($XS)[1];
$js = (string)file_get_contents(__DIR__ . '/../assets/js/screens/valuation.js');
preg_match_all('/\$\{((?:[^{}]|\{[^{}]*\})*)\}/', $js, $tpl);
// ביטוי בתבנית שנוגע בשדה של משתמש חייב לעבור esc() (או פונקציית עזר w…() שמחזירה HTML שכבר עבר). קבוע של מחרוזות (W.x / K.x / C.x / T.x) אינו קלט
$unsafe = array_values(array_filter($tpl[1], fn($e) => preg_match('/\b(?!(?:W|K|C|T)\.)[A-Za-z_$][\w$]*\.(name|title|label|text|basis|reason|reasoning|state_note|unit|source_note|provider|scope_note|words|numbers|ref_label|by|entered_by|dec|value|value_text|nis|agorot|item_date|key_date|alt|approach)\b/', $e)
    && !preg_match('/\besc\(|\bfmt[A-Z]\w*\(|\bw[A-Z]\w*\(|\bk[A-Z]\w*\(/', $e) && !preg_match('/^\s*(W|K|C|T)(\.\w+)+\s*$/', $e)));
eq([$xa['name'], $xa['state_note'], $xa['assumptions'][0]['text'], $xa['assumptions'][0]['basis'], $xa['approaches'][0]['title'], $xa['approaches'][0]['reasoning'], $xa['conclusion']['reason'], $xs['sheet']['title'], $xs['rows'][0]['label'], $xs['rows'][0]['unit'],
    $xs['rows'][1]['text']['words'], $xv['renovation']['quote'][0]['provider'], $unsafe, str_contains($js, 'innerHTML') && !str_contains($js, 'esc('), strlen($js) > 3000, preg_match('/\beval\s*\(|new Function|parseFloat|Number\(|toFixed|Math\./', $js)],
    [$x, $x, $x, $x, $x, $x, $x, $x, $x, '<b>', "$x × 2", $x, [], false, true, 0],
    'XSS: שם, מצב מונח, הנחה, נימוק, כותרת, תיאור שורה, יחידה, נוסחה-במילים ונותן ההצעה נשמרים ומוחזרים כטקסט; במסך אין ערך כזה שנכנס לתבנית בלי esc(); והמסך אינו מחשב (אין parseFloat / Number / Math / eval)');

// יומן הפעולות: כל שינוי נרשם, עם הערך הקודם והחדש
$acts = array_column(apr_rows("SELECT DISTINCT action FROM audit_log WHERE case_id = ? AND (action LIKE 'alt.%' OR action LIKE 'assumption.%' OR action LIKE 'approach.%' OR action LIKE 'sheet.%' OR action LIKE 'renovation.%' OR action = 'comp.set_member') ORDER BY action", [$caseId($C)]), 'action');
eq([$acts, $audit('sheet.row_update', $SH)[0]['meta']['key'] ?? null, $audit('approach.result', $AP3)[0]], [['alt.add', 'alt.conclude', 'alt.delete', 'alt.primary', 'alt.recheck', 'alt.stale', 'alt.update', 'approach.add', 'approach.delete', 'approach.result', 'approach.update',
    'assumption.add', 'assumption.update', 'comp.set_member', 'renovation.add', 'renovation.delete', 'sheet.add', 'sheet.recheck', 'sheet.row_add', 'sheet.row_delete', 'sheet.row_order', 'sheet.row_update', 'sheet.template_save', 'sheet.update'],
    'r1', ['meta' => ['alt' => $A1, 'source_from' => 'computed', 'source' => 'entered', 'from' => null, 'to' => '1900000'], 'reason' => 'לפי תחשיב עלות חיצוני שצורף']],
    'כל שינוי בפרוסה נרשם ביומן הפעולות של התיק, עם הערך הקודם והחדש ועם הנימוק');

// מחיקת גיליון שגישה נשענת עליו; מחיקת גישה
eq([code($post('sheet-delete', ['sheet' => $SH, 'rev' => $rv('sheets', $SH)])), $post('sheet-delete', ['sheet' => $SH3, 'rev' => $rv('sheets', $SH3)])[0], $sheetView($SH3)[0], $post('approach-delete', ['approach' => $AP3, 'rev' => $rv('approaches', $AP3)])[0],
    count($alts($C)[$A1]['approaches']), $raises('DELETE FROM approaches WHERE pid = ?', [$AP3]), $raises('DELETE FROM sheets WHERE pid = ?', [$SH3]), $raises('DELETE FROM assumptions WHERE pid = ?', [$AS1]), $raises('DELETE FROM comp_set_members')],
    ['sheet_in_use', 200, 404, 200, 2, true, true, true, true], 'גיליון שגישה נשענת עליו אינו נמחק; גיליון, גישה והנחה נמחקים רך — שורה אינה נמחקת מהמסד (טריגרים)');

// ── מיגרציה 016 על מסד v15 מאוכלס ──
$m15 = sys_get_temp_dir() . '/apr-m15-' . bin2hex(random_bytes(5));
mkdir($m15, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 15) copy($mf, $m15 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v15 = new PDO("sqlite:$dir/v15.sqlite", null, null, $pdoOpt);
apr_migrate($v15, $m15);
$v15->exec('PRAGMA foreign_keys = OFF');
$v15->exec('ATTACH ' . $v15->quote("$dir/app.sqlite") . ' AS live');
$tables = array_column($v15->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($tables as $t) {
    try { $v15->exec("DELETE FROM main.\"$t\""); } catch (PDOException) { continue; }
    try { $v15->exec("INSERT INTO main.\"$t\" (" . t_cols($v15, $t) . ") SELECT " . t_cols($v15, $t) . " FROM live.\"$t\""); } catch (PDOException) { continue; }
}
$v15->exec('DETACH live');
// כמו מסד אמיתי של 0.15: משקל על העסקה עצמה (comps.weight), לעסקה שנבחרה ולעסקה מועמדת
$kSel = $v15->query("SELECT id FROM comps WHERE status = 'selected' AND deleted_at IS NULL ORDER BY id")->fetchAll();
$kCand = $v15->query("SELECT id FROM comps WHERE status = 'candidate' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$v15->exec('UPDATE comps SET weight = 2500000, rev = rev + 1 WHERE id = ' . (int)$kSel[0]['id']);
$v15->exec('UPDATE comps SET weight = 1000000, rev = rev + 1 WHERE id = ' . (int)$kCand);
$v15->exec('PRAGMA foreign_keys = ON');
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query('SELECT ' . t_cols($d, $t) . " FROM \"$t\" ORDER BY 1")->fetchAll()))], $tables);
$d15 = $dump($v15);
$rows15 = array_sum(array_map(fn($t) => (int)$v15->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn(), $tables));
$had = [apr_schema_version($v15), in_array('alternatives', $tables, true), in_array('comp_sets', $tables, true), (int)$v15->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() > 50];
$started = (int)$v15->query("SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND status <> 'draft'")->fetchColumn();
$withFlag = (int)$v15->query("SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND status <> 'draft' AND json_extract(flags, '$.renovation_planned') = 1")->fetchColumn();
$selPairs = (int)$v15->query("SELECT COUNT(*) FROM comp_sets g JOIN comps k ON k.case_id = g.case_id WHERE g.deleted_at IS NULL AND k.deleted_at IS NULL AND (k.status = 'selected' OR k.weight IS NOT NULL)")->fetchColumn();
apr_migrate($v15);
$fresh = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($fresh);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
$alts16 = $v15->query('SELECT case_id, name, state_kind, is_primary, key_date, pid FROM alternatives ORDER BY case_id, sort')->fetchAll();
$newCount = fn() => array_map(fn($t) => (int)$v15->query("SELECT COUNT(*) FROM $t")->fetchColumn(), ['alternatives', 'comp_set_members', 'assumptions', 'approaches', 'sheets', 'sheet_rows', 'sheet_templates', 'renovation_items']);
$n16 = $newCount();
apr_migrate($v15);                                                                                       // הרצה חוזרת: כבר בגרסה — דבר אינו משתנה
$again = $newCount() === $n16 && $dump($v15) === $d15;
$memW = $v15->query('SELECT comp_id, weight, active FROM comp_set_members WHERE comp_id IN (' . (int)$kSel[0]['id'] . ', ' . (int)$kCand . ') ORDER BY comp_id, set_id')->fetchAll();
eq([apr_schema_version($v15) === apr_schema_expected() && apr_schema_expected() >= 16, $had, $rows15 > 100, $dump($v15) === $d15, $shape($v15) === $shape($fresh), $started > 2 && $withFlag >= 1, count($alts16) === $started + $withFlag,
    count(array_filter($alts16, fn($a) => (int)$a['is_primary'] === 1)) === $started, count(array_filter($alts16, fn($a) => $a['state_kind'] === 'after_works')) === $withFlag, count(array_unique(array_column($alts16, 'pid'))) === count($alts16),
    !array_filter($alts16, fn($a) => !apr_is_pid($a['pid'])), $n16[1] === $selPairs && $selPairs > 2, array_values(array_unique(array_column($memW, 'weight'))) === [2500000, 1000000] || array_values(array_unique(array_column($memW, 'weight'))) === [1000000, 2500000],
    array_slice($n16, 2), $again, count($v15->query('PRAGMA foreign_key_check')->fetchAll()),
    (int)$v15->query("SELECT COUNT(*) FROM alternatives a JOIN cases c ON c.id = a.case_id WHERE a.key_date IS NOT c.key_date OR c.status = 'draft'")->fetchColumn(),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-9]_*.sql lib/migrations/01[0-5]_*.sql 2>/dev/null'))],
    [true, [15, false, true, true], true, true, true, true, true, true, true, true, true, true, true, [0, 0, 0, 0, 0, 0], true, 0, 0, ''],
    'מיגרציה 016 על מסד v15 מאוכלס: כל שורה בכל טבלה קיימת נשמרת כפי שהיא; המבנה זהה למסד חדש; לכל תיק שנפתח — חלופה ראשית "מצב קיים" עם המועד הקובע שלו, ועם הדגל — גם after_works; טיוטה — בלי; כל עסקה שנבחרה משויכת לכל קבוצה של התיק שלה עם המשקל שהיה לה; הרצה חוזרת אינה משנה דבר; 001–015 לא נגעו');

eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');
done();
