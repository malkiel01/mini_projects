<?php
// בדיקות אליוט — ציון, ספירות חלופיות, הקרנה ורמת ביטול → overlay (spec-stage-a 17.1.3–17.1.8, 17.2.3; PR 4א/2).
// טהור — בלי מסד, בלי רשת. php trading-strategies/tests/elliott-overlay.php
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-ewo-unused-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite');   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/elliott.php';
require_once __DIR__ . '/fixtures/elliott/bars.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function near($got, $want, string $what, float $tol = 1e-9): void
{
    global $fail, $n;
    $n++;
    if ($got === $want || ($got !== null && $want !== null && abs($got - $want) <= $tol * max(1, abs($want)))) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
$H = 3600;

// ── פרמטרים (17.1.6) ─────────────────────────────────────
$P = ts_ew_params();
eq($P, ['pivot_mode' => 'atr', 'pivot_atr_mult' => 3.0, 'atr_len' => 14, 'max_counts' => 3, 'fib_tolerance' => 0.05, 'forecast_bars' => 30,
    'allow_diagonal' => true, 'overlap_tolerance' => 0.0, 'allow_running_flat' => false, 'min_score' => 0.5], 'defaults (17.1.6)');
$bad = 0;
foreach ([['pivot_atr_mult' => 0.5], ['max_counts' => 9], ['fib_tolerance' => 0.5], ['forecast_bars' => 1000], ['overlap_tolerance' => 0.3],
          ['min_score' => 0.4], ['allow_diagonal' => 1], ['pivot_mode' => 'percent'], ['nope' => 1], ['max_counts' => '3']] as $x) {
    try { ts_ew_params($x); } catch (InvalidArgumentException) { $bad++; }
}
eq($bad, 10, 'parameters outside their range, of the wrong type, or unknown → rejected (each of 10)');
eq(ts_ew_params(['max_counts' => 5, 'min_score' => 0.95, 'overlap_tolerance' => 0.25])['max_counts'], 5, 'parameters at the edge of the range are accepted');

// ── ציון: כפולות במשקל גבוה מתיקונים (17.1.4); במילים (✅16) ──
$r = fn(float $fit, float $w) => ['fit' => $fit, 'weight' => $w];
near(ts_ew_score([$r(1, TS_EW_W_MULTIPLE), $r(0, TS_EW_W_RETRACE)]), 2 / 3, 'a count matching only a multiple scores higher …');
near(ts_ew_score([$r(0, TS_EW_W_MULTIPLE), $r(1, TS_EW_W_RETRACE)]), 1 / 3, '… than one matching only a retracement');
eq(TS_EW_W_MULTIPLE > TS_EW_W_RETRACE, true, 'multiples weigh more than retracements');
eq([ts_ew_score_words(0.75), ts_ew_score_words(0.7499), ts_ew_score_words(0.5), ts_ew_score_words(0.4999)], ['התאמה גבוהה', 'התאמה בינונית', 'התאמה בינונית', null], 'score in words: ≥ 0.75 high, 0.5–0.75 medium, below — nothing');
[$f, $i] = ts_ew_fit(1.6, [1.0, 1.618, 2.618], 0.05);
eq([$f, $i], [1.0, 1.618], 'fit: within the tolerance of the nearest ratio → 1');
[$f] = ts_ew_fit(1.618 * 1.10, [1.618], 0.05);
near($f, 0.5, 'fit: 10% off with tolerance 5% → 0.5 (linear down to 0 at 15%)');
eq(ts_ew_fit(1.618 * 1.2, [1.618], 0.05)[0], 0.0, 'fit: 20% off → 0');
// גל 5 מורחב: 1.618 × (1→3), מסוף גל 4 (אומת, #1342)
$ext = ts_ew_relations([100, 120, 110, 150, 140, 140 + 1.618 * 50], 5, 0.05);
$w5 = $ext[array_search('w5', array_column($ext, 'id'))];
eq([$w5['ideal'], $w5['fit'], $w5['text']], [1.618, 1.0, 'גל 5 = 1.618 × המרחק 1→3'], 'extended wave 5 = 1.618 × (1→3) measured from the end of wave 4');
$eqw = ts_ew_relations([100, 120, 110, 150, 140, 160], 5, 0.05);
eq($eqw[array_search('w5', array_column($eqw, 'id'))]['text'], 'גל 5 שווה לגל 1', 'non-extended wave 5 equal to wave 1');
eq(array_column(ts_ew_relations([100, 120, 110], 2, 0.05), 'id'), ['w2'], 'a 2-wave count has only the wave-2 relation');

// ── דחף עד גל 4 → ספירה אחת, ציון גבוה, ויעד גל 5 לפי יחס (17.1.8) ──
$imp = [['L', 100], ['H', 120], ['L', 107.64], ['H', 142.47], ['L', 129.16]];
$C4 = ew_bars(105.5, $imp, 133);
$ov = ts_ew_overlay($C4, $H);
$c = $ov['counts'];
eq([count($c), $c[0]['dir'] ?? null, $c[0]['k'] ?? null, $c[0]['words'] ?? null], [1, 'up', 4, 'התאמה גבוהה'], 'impulse through wave 4 → exactly one count, up, 4 waves, "high fit"');
eq(array_map(fn($x) => $x['v'], $c[0]['pivots']), [100.0, 120.0, 107.64, 142.47, 129.16], 'the count runs through the pivots 0–4');
$nx = $c[0]['next'];
near($nx['zone'][0], 129.16 + 20, 'wave 5 zone: from wave 5 = wave 1 (149.16) …');
near($nx['zone'][1], 129.16 + 0.618 * 42.47, '… to 0.618 × (1→3) from the end of wave 4 (155.41)');
eq([$nx['wave'], $nx['invalidation']], ['5', 120.0], 'next wave 5; invalidation = the end of wave 1 (rule 5)');
eq(str_contains($nx['rule'], 'לפי הספר'), true, 'the zone is shown next to the rule it comes from (6.6)');
// עם overlap_tolerance — רמת הביטול זזה לפי הסבולת
near(ts_ew_overlay($C4, $H, ['overlap_tolerance' => 0.1])['counts'][0]['next']['invalidation'], 118.0, 'invalidation with overlap_tolerance 0.1 = 120 − 10% of wave 1');

// ── דחף מושלם → ספירה אחת, ציון גבוה, הבא — תיקון אל טווח גל 4 ──
$C5 = ew_bars(105.5, [...$imp, ['H', 149.16]], 145);
$c5 = ts_ew_overlay($C5, $H)['counts'];
eq([count($c5), $c5[0]['k'] ?? null, $c5[0]['words'] ?? null, $c5[0]['next']['wave'] ?? null], [1, 5, 'התאמה גבוהה', 'A-B-C'], 'complete impulse → one 5-wave count, "high fit", next: correction');
eq([$c5[0]['next']['zone'], $c5[0]['next']['invalidation']], [[129.16, 142.47], 149.16], 'correction zone = wave 4 range; invalidation = the end of wave 5');
$w = array_column($c5[0]['relations'], 'fit', 'id');
eq([$w['w2'], $w['w3'], $w['w4'], $w['w5']], [1.0, 1.0, 1.0, 1.0], 'the synthetic impulse matches the book ratios (wave 2 61.8%, wave 3 1.618×, wave 4 38.2%, wave 5 = wave 1)');
// גל 3 ו-2 בדחף יורד (מראה)
$Cd = ew_bars(94.5, [['H', 100], ['L', 80]], 88);
$cd = ts_ew_overlay($Cd, $H)['counts'];
eq(count($cd), 0, 'waves 0–1 only → no count (need wave 2)');
$Cd = ew_bars(94.5, [['H', 100], ['L', 80], ['H', 90]], 86);
$cd = ts_ew_overlay($Cd, $H)['counts'];
eq([$cd[0]['dir'] ?? null, $cd[0]['k'] ?? null, $cd[0]['next']['wave'] ?? null, $cd[0]['next']['invalidation'] ?? null], ['down', 2, '3', 100.0], 'down impulse, 2 waves → next wave 3, invalidation at the start of wave 1 (rule 1)');
near($cd[0]['next']['center'], 90 * (1 - 1.618 * 0.2), 'wave 3 down: 1.618 × wave 1 in percent, from the end of wave 2');

// ── כללים קשיחים פוסלים ספירה (17.1.8) ──
$Cb = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 99], ['H', 142.47], ['L', 129.16]], 133);
eq(array_filter(ts_ew_overlay($Cb, $H)['counts'], fn($x) => $x['pivots'][0]['v'] == 100.0), [], 'rule 1: wave 2 below the start of wave 1 → no count from that start');
$Co = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 107.64], ['H', 142.47], ['L', 118]], 122);
eq(array_filter(ts_ew_overlay($Co, $H)['counts'], fn($x) => $x['k'] === 4), [], 'rule 5: wave 4 into wave 1 → no 4-wave count');
eq(count(array_filter(ts_ew_overlay($Co, $H, ['overlap_tolerance' => 0.25])['counts'], fn($x) => $x['k'] === 4)), 1, 'rule 5 with overlap_tolerance 0.25 (10% penetration) → the 4-wave count is back');

// ── ספירות חלופיות, min_score, max_counts ──
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$seen = 0; $okOrder = true; $okMax = true; $okMin = true; $okPrim = true; $multi = 0;
foreach (range(400, 4999, 37) as $i) {
    $o = ts_ew_overlay(array_slice($F, 0, $i + 1), $H, ['pivot_atr_mult' => 2.0, 'max_counts' => 2]);
    $cs = $o['counts'];
    $seen += count($cs) > 0;
    if (count($cs) > 1) $multi++;
    if (count($cs) > 2) $okMax = false;
    for ($j = 1; $j < count($cs); $j++) if ([$cs[$j - 1]['score'], $cs[$j - 1]['k']] < [$cs[$j]['score'], $cs[$j]['k']]) $okOrder = false;
    foreach ($cs as $x) if ($x['score'] < 0.5 || $x['words'] === null) $okMin = false;
    foreach ($o['items'] as $it) if ($it['primary'] !== ($it['scenario'] === 'c1')) $okPrim = false;
}
eq($seen > 20, true, "the fixed set gives counts ($seen windows)");
// שתי ספירות שעוברות: גל 4 עמוק (50%) — הספירה של 4 גלים בינונית, והספירה החדשה של 2 גלים (מסוף גל 2) גבוהה
$Calt = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 107.64], ['H', 142.47], ['L', 125.055]], 129);
$ca = ts_ew_overlay($Calt, $H)['counts'];
eq(array_map(fn($x) => [$x['id'], $x['k'], $x['words']], $ca), [['c1', 2, 'התאמה גבוהה'], ['c2', 4, 'התאמה בינונית']], 'alternative counts: the higher score leads (c1), the other is an alternate (c2)');
eq(count(ts_ew_overlay($Calt, $H, ['max_counts' => 1])['counts']), 1, 'max_counts 1 → only the leading count');
eq([$okMax, $okOrder, $okMin, $okPrim], [true, true, true, true], 'at most max_counts; sorted by score; all ≥ min_score with words; only c1 is primary (alternates dimmed)');
$strict = ts_ew_overlay($F, $H, ['pivot_atr_mult' => 2.0, 'min_score' => 0.95])['counts'];
eq(count(array_filter($strict, fn($x) => $x['score'] < 0.95)), 0, 'min_score 0.95 → no count below it is shown');

// ── החוזה (17.2.3) ───────────────────────────────────────
$o = ts_ew_overlay($C4, $H);
eq(array_values(array_unique(array_column($o['items'], 'kind'))), ['polyline', 'label', 'zone', 'hline'], 'item kinds from the contract (label, polyline, zone, hline)');
eq(array_column(array_filter($o['items'], fn($x) => $x['kind'] === 'label'), 'text'), ['1', '2', '3', '4'], 'wave labels 1–4 on the pivots');
$hl = array_values(array_filter($o['items'], fn($x) => $x['kind'] === 'hline'));
eq([count($hl), $hl[0]['role'], $hl[0]['price']], [1, 'invalidation', 120.0], 'one red invalidation line (role: invalidation)');
$lines = array_values(array_filter($o['items'], fn($x) => $x['kind'] === 'polyline'));
eq(array_column($lines, 'style'), ['solid', 'dashed'], 'the count line is solid, the projection dashed');
$sc = $o['projection']['scenarios'][0];
eq([array_keys($o), $o['projection']['bars'], array_keys($sc)], [['as_of', 'range', 'items', 'projection', 'counts', 'check'], 30, ['id', 'score', 'words', 'primary', 'label', 'points']], 'overlay {as_of, range, items, projection{bars, scenarios[{id, score, points, label}]}}');
eq([$o['as_of'], $o['range']], [end($C4)['t'], ['from' => 0, 'to' => end($C4)['t']]], 'as_of and range = the candles given');
// זמנים עתידיים: t + k·tf, ועד forecast_bars
$end = end($c[0]['pivots'])['t'];
$t2 = end($sc['points'])['t'];
$w1bars = intdiv($c[0]['pivots'][1]['t'] - $c[0]['pivots'][0]['t'], $H);
eq([($t2 - $end) % $H, ($t2 - $end) / $H, $t2 > $o['as_of']], [0, min(30, $w1bars), true], "future times aligned to the timeframe (t + k·tf); wave 5 ≈ wave 1 in time ($w1bars bars), capped at forecast_bars");
eq(($t2 - end($c[0]['pivots'])['t']) / $H <= 30 && (ts_ew_overlay($C4, $H, ['forecast_bars' => 5])['projection']['scenarios'][0]['points'][1]['t'] - $end) / $H === 5, true, 'projection length capped by forecast_bars');
$o4h = ts_ew_overlay(ew_bars(105.5, $imp, 133, 14400), 14400);
eq(($o4h['projection']['scenarios'][0]['points'][1]['t'] - end($o4h['counts'][0]['pivots'])['t']) % 14400, 0, 'aligned on 4h as well');
// הניסוח: בלי אחוז לציון (✅16), בלי "קנה/מכור/המלצה/יעד" (6.6)
$texts = implode(' | ', array_merge(array_column($o['projection']['scenarios'], 'label'), array_filter(array_column($o['items'], 'text'))));
eq([preg_match('/\d+(\.\d+)?\s*%/', implode(' ', array_column($o['projection']['scenarios'], 'label'))), preg_match('/קנה|מכור|המלצה|כדאי|\bיעד\b/u', $texts)], [0, 0], 'no percentage on the score, and none of the forbidden words (6.6)');
eq(count($o['check']) >= 4 && in_array('חילופיות בין גל 2 לגל 4 (חד מול צדדי)', $o['check'], true), true, 'guidelines not measured here are returned as "check yourself"');

// ── בלי הצצה לעתיד ודטרמיניזם (17.1.8) ──
$peek = 0;
$fullPiv = ts_ew_pivots($F, 2.0, 14);
foreach ([500, 1777, 3001, 4500] as $i) {
    $part = array_slice($F, 0, $i + 1);
    $o = ts_ew_overlay($part, $H, ['pivot_atr_mult' => 2.0]);
    $known = array_values(array_filter($fullPiv, fn($p) => $p['ct'] <= $F[$i]['t']));
    $want = ts_ew_counts($known, $H, ts_ew_params(['pivot_atr_mult' => 2.0]));
    if (json_encode($o['counts']) !== json_encode($want)) $peek++;
    foreach ($o['counts'] as $x) foreach ($x['pivots'] as $p) if ($p['ct'] > $F[$i]['t']) $peek++;
}
eq($peek, 0, 'no look-ahead: the counts at i come only from pivots confirmed by i — the same as the full run cut at i');
eq(json_encode(ts_ew_overlay($F, $H, ['pivot_atr_mult' => 2.0])), json_encode(ts_ew_overlay($F, $H, ['pivot_atr_mult' => 2.0])), 'determinism: the same overlay twice, same order');
$thrown = false; try { ts_ew_overlay($C4, 0); } catch (InvalidArgumentException) { $thrown = true; }
eq($thrown, true, 'timeframe 0 → rejected');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
