<?php
// בדיקות F-3 — פיבונאצ'י, מידע בלבד (spec-stage-a 22.9, "קריטריוני קבלה — F-3", 2.31): S25 (מתרחב / מתכווץ / סימטרי) וואקום (Z10).
// php trading-strategies/tests/fibonacci-f3.php — טהור: בלי מסד, בלי רשת, בלי שעון ובלי אקראיות. הנרות — tests/fixtures.
// קריטריון 4 (בלי שינוי בספירה) — גם tests/fibonacci-zones.php (הסט הקבוע זהה).
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-fib3-unused-' . getmypid() . '.sqlite');   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/forecast.php';
require_once __DIR__ . '/fixtures/fibonacci/bars.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function r2($v) { return is_array($v) ? array_map('r2', $v) : round((float)$v, 2); }

$m = ts_method('fibonacci');
$P = $m->params();
$H = 3600;
$run = function (array $c, array $p) use ($m) { $s = $m->init($p); foreach ($c as $x) [$s] = $m->step($s, $x, $p); return $s; };

// ── קריטריון 1: S25 — הדוגמאות של האפיון (גוף K 100→110, ATR = 2, סף 1.0) ──
eq([ts_fib_s25_classify([106.0, 108.0, 110.0], 1.0), ts_fib_s25_classify([106.0, 104.0, 102.0], 1.0), ts_fib_s25_classify([106.0, 106.25, 106.2], 1.0)],
    ['expanding', 'contracting', 'symmetric'], 'criterion 1: the three examples — 106/108/110 expanding, 106/104/102 contracting, 106/106.25/106.2 symmetric');
eq([ts_fib_s25_classify([106.0, 108.0], 1.0), ts_fib_s25_classify([], 1.0)], ['unknown', 'unknown'], 'criterion 1: fewer than three ranges → unknown');
eq(ts_fib_s25_classify([106.0, 110.0, 108.0], 1.0), 'unknown', 'not monotonic and not within the threshold → unknown [ours]');
eq(ts_fib_s25_classify([106.0, 107.0, 108.0], 1.0), 'expanding', 'symmetric only when all are within the threshold of each other (106–108 = 2 > 1)');

// מנרות: שפלים לפני K — 98, 95, 90; שיאים אחריו — 114, 121, 130 (רגישות 1, כדי שהתנודות הקטנות יהיו נקודות מפנה)
$P1 = $m->params(['pivot_atr_mult' => 1]);
$EXP = fib_bars([105, 90, 100, 95, 101, 98, [110, 'body' => 10], 114, 108, 121, 115, 130, 120]);
$s = $run($EXP, $P1);
$mk = ts_fib_market($s['w'], $s['piv'], $s['g'], $P1);
$kIdx = array_search($mk['k_t'], array_column($EXP, 't'), true);
eq([$mk['side'], [$EXP[$kIdx]['o'], $EXP[$kIdx]['c']], $mk['ranges'], $mk['mids'], $mk['state']],
    ['support', [100.0, 110.0], [[98.0, 114.0], [95.0, 121.0], [90.0, 130.0]], [106.0, 108.0, 110.0], 'expanding'],
    'criterion 1 (candles): K = the longest body (100→110); nested ranges [98,114] [95,121] [90,130] → 106/108/110 → expanding');
$CON = fib_bars([105, 88, 100, 93, 101, 98, [110, 'body' => 10], 114, 110, 115, 111, 116, 110]);
$s2 = $run($CON, $P1);
$mk2 = ts_fib_market($s2['w'], $s2['piv'], $s2['g'], $P1);
eq([$mk2['ranges'], $mk2['mids'], $mk2['state']], [[[98.0, 114.0], [93.0, 115.0], [88.0, 116.0]], [106.0, 104.0, 102.0], 'contracting'],
    'criterion 1 (candles): [98,114] [93,115] [88,116] → 106/104/102 → contracting');
// התנגדות — במראה: אותם נרות × −1 (K − מחיר). כשאין צד תמיכה — מצד ההתנגדות; "מתרחב" נשאר "מתרחב"
$K = 400.0;
$mir = fn(array $c) => array_map(fn($x) => ['t' => $x['t'], 'o' => $K - $x['o'], 'h' => $K - $x['l'], 'l' => $K - $x['h'], 'c' => $K - $x['c']], $c);
$sm = $run($mir($EXP), $P1);
$gm = $sm['g'];
$gm['support']['start'] = null;
$mkm = ts_fib_market($sm['w'], $sm['piv'], $gm, $P1);
eq([$mkm['side'], $mkm['ranges'], $mkm['mids'], $mkm['state']], ['resistance', [[286.0, 302.0], [279.0, 305.0], [270.0, 310.0]], [294.0, 292.0, 290.0], 'expanding'],
    'criterion 1 (mirror): from the resistance side — the mirror of the example, still expanding');
eq(ts_fib_market([], [], null, $P1)['state'], 'unknown', 'no grid → unknown');

// ── קריטריון 2: בכרטיס — "השוק: …", מידע בלבד ─────────────
$a = $m->analyze($EXP, $P1);
eq([$a['info'] ?? null, in_array('market', array_column($a['conditions'], 'id'), true)],
    [[['id' => 'market', 'text' => 'השוק: מתרחב', 'value' => 'expanding']], false], 'criterion 2: "השוק: מתרחב" in the card — info, not a condition (not counted, not "check yourself")');
eq([$m->analyze($CON, $P1)['info'][0]['text'], $m->analyze(array_slice($EXP, 0, 20), $P1)['info'][0]['text']], ['השוק: מתכווץ', 'השוק: לא ידוע'],
    'criterion 2: contracting / unknown (not enough ranges)');

// ── קריטריון 3: ואקום ──────────────────────────────────────
$z = fn(float $lo, float $hi) => ['lo' => $lo, 'hi' => $hi, 'score' => 2, 'levels' => [['r' => 0.382, 'price' => $lo], ['r' => 0.5, 'price' => $hi]], 'ranges' => 2];
$side = fn(array $zones, array $singles = []) => ['start' => null, 'atr' => 2.0, 'zones' => $zones, 'singles' => array_map(fn($v) => ['r' => 0.5, 'price' => $v], $singles)];
$g = fn(array $sup, array $res = []) => ['atr' => 2.0, 'support' => $sup, 'resistance' => $res ?: $side([])];
// zone_max_atr = 1 → מרחק מינימלי 2.0
eq(ts_fib_vacuums($g($side([$z(100.0, 101.0), $z(103.0, 104.0)])), $P), [['side' => 'support', 'lo' => 101.0, 'hi' => 103.0]],
    'criterion 3: two adjacent zones on the same side, no level between, gap 2.0 ≥ zone_max_atr × ATR → vacuum');
eq(ts_fib_vacuums($g($side([$z(100.0, 101.0), $z(103.0, 104.0)], [102.0])), $P), [], 'criterion 3: a single level between them → no vacuum');
eq(ts_fib_vacuums($g($side([$z(100.0, 101.0), $z(103.0, 104.0)]), $side([], [102.0])), $P), [], 'criterion 3: a level of the other side between them → no vacuum [ours]');
eq(ts_fib_vacuums($g($side([$z(100.0, 101.0), $z(102.5, 104.0)])), $P), [], 'criterion 3: gap 1.5 < 2.0 → no vacuum');
eq(ts_fib_vacuums($g($side([$z(100.0, 101.0)]), $side([$z(103.0, 104.0)])), $P), [], 'criterion 3: zones on different sides → not a vacuum (same side only)');
eq(ts_fib_vacuums($g($side([$z(100.0, 101.0), $z(103.0, 104.0), $z(107.0, 108.0)])), $P), [['side' => 'support', 'lo' => 101.0, 'hi' => 103.0], ['side' => 'support', 'lo' => 104.0, 'hi' => 107.0]],
    'criterion 3: only between adjacent zones');
// בגרף — תווית בממצא (לא בתרחיש), באמצע הפער
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$found = null;
foreach (range(600, count($F), 97) as $end) {
    $c = array_slice($F, 0, $end);
    $vs = ts_fib_vacuums(ts_fib_grid_of($c, $P), $P);
    if ($vs) { $found = [$c, $vs]; break; }
}
eq($found !== null, true, 'a vacuum exists somewhere on the real h1 candles (for the chart check)');
if ($found) {
    [$c, $vs] = $found;
    [$fin, $fc] = ts_forecast_split(ts_overlay_check($m->overlay($c, $P, $H), $H));
    $labels = array_values(array_filter($fin['items'], fn($it) => $it['kind'] === 'label' && isset($it['vacuum'])));
    eq([count($labels), $labels[0]['text'] ?? null, r2($labels[0]['price'] ?? 0), $labels[0]['t'] ?? null],
        [count($vs), 'ואקום', r2(($vs[0]['lo'] + $vs[0]['hi']) / 2), $c[count($c) - 1]['t']], 'criterion 3 (chart): a "ואקום" label in the finding, mid-gap, at the last candle');
    eq(array_filter($fc['items'], fn($it) => isset($it['vacuum'])), [], 'the vacuum is not part of the scenario layer');
}

// ── קריטריון 4: בלי שינוי בספירה ───────────────────────────
// S25 והואקום לא נוגעים ברשת: הרשת, האותות והציונים — אותם עם המידע ובלעדיו (המידע נגזר אחרי, לקריאה בלבד)
$sF = $run($F, $P);
$gBefore = json_encode($sF['g']);
ts_fib_market($sF['w'], $sF['piv'], $sF['g'], $P);
ts_fib_vacuums($sF['g'], $P);
$a5 = $m->analyze($F, $P);
eq([json_encode($sF['g']) === $gBefore, $a5['bias'], count($a5['conditions'])], [true, $m->analyze($F, $P)['bias'], 9],
    'criterion 4: computing S25 and the vacuum leaves the grid untouched; the card has the same 9 conditions');
$sig = $m->signals($F, $P);
eq(count($sig), 63, 'criterion 4: the same 63 default signals on the 5,000 h1 candles');

// ── השוואת שכנים (23.9, L5b): learn_params — שלושה פרמטרים, והשכנים בתוך הטווח ──
require_once dirname(__DIR__) . '/lib/learn.php';
$lp = ts_learn_params($m);
eq([array_keys($lp), $lp['stop_atr']['step'], $lp['zone_atr']['step'], $lp['box_target']['values']], [['stop_atr', 'box_target', 'zone_atr'], 0.25, 0.1, [0.5, 0.618, 1.0]],
    'L5b: learn_params — stop_atr (step 0.25), box_target (0.5 / 0.618 / 1), zone_atr (step 0.1)');
$nb = fn(string $k, float $v) => array_map(fn($x) => round($x['value'], 6), ts_learn_neighbors($lp[$k], $v));
eq([$nb('stop_atr', 0.25), $nb('stop_atr', 0.0), $nb('stop_atr', 1.0), $nb('zone_atr', 0.5), $nb('zone_atr', 0.1), $nb('zone_atr', 2.0), $nb('box_target', 1.0), $nb('box_target', 0.618), $nb('box_target', 0.5)],
    [[0.0, 0.25, 0.5], [0.0, 0.25], [0.75, 1.0], [0.4, 0.5, 0.6], [0.1, 0.2], [1.9, 2.0], [0.618, 1.0], [0.5, 0.618, 1.0], [0.5, 0.618]],
    'L5b: the neighbours stay in range — stop_atr 0–1, zone_atr 0.1–2; box_target only from its three values');
$allIn = true;
foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $v) foreach (ts_learn_neighbors($lp['stop_atr'], $v) as $x) if ($x['value'] < -1e-9 || $x['value'] > 1 + 1e-9) $allIn = false;
foreach (range(1, 20) as $i) foreach (ts_learn_neighbors($lp['zone_atr'], $i / 10) as $x) if ($x['value'] < 0.1 - 1e-9 || $x['value'] > 2 + 1e-9) $allIn = false;
eq($allIn, true, 'L5b: no neighbour outside 22.10 for any current value');

// ── T1 (18.1.6, קריטריון 3): tip לכל פריט שפיבונאצ'י מצייר ─────
$ovs = fn(array $c, array $o = []) => ts_forecast_split(ts_overlay_check($m->overlay($c, $m->params($o), $H), $H));   // ts_overlay_check — גם tip
$EX = fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 185]);
[$fx] = $ovs($EX);
$zt = array_values(array_filter($fx['items'], fn($it) => $it['kind'] === 'zone'))[0]['tip'] ?? [];
eq([$zt['title'] ?? null, str_contains($zt['explain'] ?? '', '61.8% של 200→150'), str_contains($zt['explain'] ?? '', '38.2% של 200→120'), str_starts_with($zt['explain'] ?? '', '2 רמות מ-2 טווחים')],
    ["אזור פיבונאצ'י · ציון 2", true, true, true], 'T1 criterion 3: a zone → "אזור פיבונאצ\'י · ציון 2", and its ratios (61.8% של 200→150, 38.2% של 200→120)');
$st1 = array_values(array_filter($fx['items'], fn($it) => !empty($it['single'])))[0]['tip'] ?? [];
eq([str_starts_with($st1['title'] ?? '', 'רמה בודדת · '), str_starts_with($st1['explain'] ?? '', 'רמה בודדת, חלשה (R8): ')], [true, true], 'T1: a single level → "רמה בודדת, חלשה (R8)"');
$D = fib_bars([140, 200, 150, 170, 160]);
[, $fcD] = $ovs($D);
$l100 = array_values(array_filter($fcD['items'], fn($it) => ($it['text'] ?? '') === 'A-B-X 100%'))[0]['tip'] ?? [];
eq([$l100['title'] ?? null, $l100['invalidation'] ?? null], ['הקרנה A-B-X, 100%', ['price' => 170.0, 'side' => 'above', 'rule' => 'סגירה מעל X של A-B-X']],
    'T1: an A-B-X line → "הקרנה A-B-X, 100%", invalidated above X (170) — the scenario goes down');
$NT = fib_bars([120, 175, 150, [155, 'body' => 5], 200, 185]);
$NT[] = ['t' => $NT[count($NT) - 1]['t'] + $H, 'o' => 185.0, 'h' => 185.5, 'l' => 169.3, 'c' => 175.0];
[$fN, $fcN] = $ovs($NT, ['lookback_bars' => 500]);
$byKind = [];
foreach ($fN['items'] as $it) $byKind[$it['kind'] . ($it['role'] ?? '')] = $it['tip'] ?? null;
$stopP = array_values(array_filter($fN['items'], fn($it) => ($it['role'] ?? '') === 'stop'))[0]['price'];
eq([$byKind['marker']['title'] ?? null, $byKind['marker']['invalidation'] ?? null, $byKind['hlinetarget']['title'] ?? null, str_contains($byKind['hlinetarget']['explain'] ?? '', 'קופסת השוויון')],
    ['אות לונג', ['price' => $stopP, 'side' => 'below', 'rule' => 'הסטופ של האות'], 'רמת היציאה ברווח', true],
    'T1: the signal — marker, stop and exit level, invalidated at the stop (below, for a long); the exit level from the box');
$inv = array_values(array_filter($fcN['items'], fn($it) => ($it['role'] ?? '') === 'invalidation'))[0]['tip'] ?? [];
eq([$inv['title'] ?? null, $inv['invalidation']['price'] ?? null], ['רמת ביטול', $stopP], 'T1: no A-B-X in the signal direction → the scenario is invalidated at the stop');
// כל פריט — עם tip וכותרת, בכל ההרצות; וניסוח 6.6 (18.1.6 קריטריון 12)
$all = [];
foreach ([[$EX, []], [$D, []], [$NT, ['lookback_bars' => 500]], [fib_bars([140, 200, 150, 205, 195]), []]] as [$c, $o]) {
    [$a1, $a2] = $ovs($c, $o);
    $all = [...$all, ...$a1['items'], ...$a2['items']];
}
foreach (range(1500, count($F), 700) as $end) {
    [$a1, $a2] = $ovs(array_slice($F, 0, $end));
    $all = [...$all, ...$a1['items'], ...$a2['items']];
}
$noTip = array_filter($all, fn($it) => !is_string($it['tip']['title'] ?? null) || ($it['tip']['title'] ?? '') === '');
$kinds = array_values(array_unique(array_map(fn($it) => $it['kind'] . (isset($it['box']) ? ':box' : '') . (isset($it['vacuum']) ? ':vacuum' : ''), $all)));
sort($kinds);
echo "  סוגים: " . implode(", ", $kinds) . "\n";
eq([count($all) > 50, count($noTip)], [true, 0], 'T1 criterion 3: every item Fibonacci draws has a tip with a title (' . count($all) . ' items: ' . implode(', ', $kinds) . ')');
eq(preg_match('/יעד|קנה|מכור|המלצ/u', json_encode(array_column($all, 'tip'), JSON_UNESCAPED_UNICODE)), 0, 'T1 criterion 12 (6.6): no "target/buy/sell/recommend" in any tip');
eq(max(array_map(fn($it) => mb_strlen($it['tip']['explain'] ?? ''), $all)) <= 200, true, 'T1: every explanation fits the overlay text limit (200)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
