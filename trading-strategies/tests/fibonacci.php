<?php
// בדיקות F-1 — שיטת פיבונאצ'י, הממצא והאותות (spec-stage-a 22.2–22.4, 22.6, 22.7; קריטריוני F-1 1–9 ו-12 בחלק הטהור, מספור 2.28).
// php trading-strategies/tests/fibonacci.php — טהור: בלי מסד, בלי רשת, בלי שעון ובלי אקראיות. הנרות — tests/fixtures/fibonacci.
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-fib-unused-' . getmypid() . '.sqlite');   // לא נפתח (tune = 0)
require_once dirname(__DIR__) . '/lib/analysis.php';
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
/** מחיר בעיגול לשתי ספרות — float של 200 − 0.382 × 80 אינו 169.44 בדיוק */
function r2($v) { return is_array($v) ? array_map('r2', $v) : round((float)$v, 2); }

$m = ts_method('fibonacci');
$P = $m->params();
eq([get_class($m), $m->meta()['name'], $m->meta()['levels'], $m->meta()['timeframes']], ['TsMethodFibonacci', "פיבונאצ'י", 'absolute', ['h1', 'h4', 'd1', 'w1']],
    'registered: "פיבונאצ\'י", absolute levels (6.2 ח), h1 and up (22.1)');
eq([$m->meta()['default_off'], ts_method_on_by_default($m->meta()), ts_method_on_by_default(ts_method('elliott-w3')->meta())], [true, false, true],
    'a new method starts OFF (22.1, 6.7) — Elliott stays on by default (18.1.2)');
$defaults = array_map(fn($d) => $d['default'], $m->meta()['params']);
eq([$defaults['lookback_bars'], $defaults['max_ranges'], $defaults['strong_atr'], $defaults['close_atr'], $defaults['zone_atr'], $defaults['zone_max_atr'],
    $defaults['min_score'], $defaults['stop_atr'], $defaults['min_rr'], $defaults['horizon_bars']], [250, 5, 2, 0.5, 0.5, 1, 3, 0.25, 2, 60], 'defaults = table 22.10');
eq([$defaults['pivot_atr_mult'], $defaults['atr_len']], [ts_method('elliott-w3')->meta()['params']['pivot_atr_mult']['default'], ts_method('elliott-w3')->meta()['params']['atr_len']['default']],
    'pivot_atr_mult and atr_len = the elliott-w3 defaults (22.2)');

$grid = fn(array $c, array $o = []) => ts_fib_grid_of($c, $m->params($o));
$ends = fn(array $side) => array_map(fn($r) => r2($r['end']), $side['ranges']);

// ── קריטריון 1: הדוגמה של 22.4 ────────────────────────────
// H = 200; שפלים שלא נמחקו 150 ו-120. הנר הארוך ביותר (גוף 3, 196→199) — הטווח שלו נפסל (רמות מעל הסגירה 185)
$EX = fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 185]);
$g = $grid($EX);
eq([r2($g['atr']), $g['close']], [2.04, 185.0], 'example: ATR ≈ 2 (threshold ≈ 1.0), last close 185');
eq([r2($g['support']['start']['price']), $ends($g['support'])], [200.0, [150.0, 120.0]], 'criterion 1: two ranges, 200→150 and 200→120');
eq(array_map(fn($r) => r2(array_column($r['levels'], 'price')), $g['support']['ranges']), [[180.9, 175.0, 169.1], [169.44, 160.0, 150.56]],
    'criterion 1: levels 180.90 / 175.00 / 169.10 and 169.44 / 160.00 / 150.56');
$z = $g['support']['zones'];
eq([count($z), r2($z[0]['lo']), r2($z[0]['hi']), $z[0]['score'], $z[0]['ranges']], [1, 169.1, 169.44, 2, 2], 'criterion 1: one zone [169.10, 169.44], score 2, from two ranges');
eq(r2(array_column($g['support']['singles'], 'price')), [150.56, 160.0, 175.0, 180.9], 'criterion 1: the other four levels are single (Z9)');
eq([array_map(fn($r) => r2($r['end']), $g['support']['dropped']), $g['support']['dropped'][0]['kinds']], [[196.0], ['longest']],
    'the longest candle is always a candidate (S15) — here its range has levels above the close and is dropped (S2)');
// Z1: שתי רמות מאותו טווח, או באותו יחס — לא אזור
$z1 = ts_fib_side([], [], $P, 2.0, 1);
eq($z1['zones'], [], 'no candles → no zone');

// ── קריטריון 2: שפל שנמחק (S17, E3) ──────────────────────
// 160 בין 150 ל-200, ונר שירד ל-155 לפני H
$before = $grid(fib_bars([140, 120, 175, 150, 170, 160, 168, 155, 196, [199, 'body' => 3], 200, 185]));
eq([in_array(160.0, $ends($before['support']), true), $ends($before['support'])], [false, [155.0, 150.0, 120.0]],
    'criterion 2a: a candle down to 155 before H → 160 is not an end point (155 and 150 are)');
// אותו 160, והירידה ל-155 אחרי H; הסגירה האחרונה חזרה מעל כל הרמות (185 > 180.90)
$after = $grid(fib_bars([140, 120, 175, 150, 170, 160, 196, [199, 'body' => 3], 200, 155, 185]));
$top = max(array_merge(...array_map(fn($r) => array_column($r['levels'], 'price'), $after['support']['ranges'])));
eq([in_array(160.0, $ends($after['support']), true), $ends($after['support']), $after['close'] > $top, r2($after['support']['start']['price'])],
    [false, [150.0, 120.0], true, 200.0], 'criterion 2b: down to 155 after H, last close above all levels → 160 still rejected');
$intact = $grid(fib_bars([140, 120, 175, 150, 170, 160, 196, [199, 'body' => 3], 200, 185]));
eq(in_array(160.0, $ends($intact['support']), true), true, 'control: with no candle below 160, 160 is an end point');

// ── קריטריון 3: גוף ולא פתיל (S29) ────────────────────────
$wick = $grid(fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], [200, 'wick' => 210], 185]));
eq([r2($wick['support']['start']['price']), $ends($wick['support'])], [200.0, [150.0, 120.0]], 'criterion 3: a wick to 210 and a body to 200 → H = 200');

// ── קריטריון 4: כיוון (S1, E1) ונקודת התחלה אחת (S3, E4) ───────
$both = $grid(fib_bars([140, 100, 200, 160, 170]));
eq([r2($both['support']['start']['price']), r2($both['resistance']['start']['price'])], [200.0, 160.0], 'both sides: support from H = 200, resistance from L = 160');
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$windows = [$both, $g];
foreach (range(400, count($F), 61) as $end) $windows[] = $grid(array_slice($F, 0, $end));
// הסגירה ברגע הבנייה של כל צד (built_t) — הפסילה נבדקת מולה (הכרעה "ג")
$closeAt = array_column([...$F, ...$EX, ...fib_bars([140, 100, 200, 160, 170])], 'c', 't');
$dir = 0; $same = 0; $ratios = 0; $above = 0; $levelsN = 0; $math = 0; $bothSides = 0;
foreach ($windows as $w) {
    if (!$w) continue;
    if ($w['support']['ranges'] && $w['resistance']['ranges']) $bothSides++;
    foreach (['support' => 1, 'resistance' => -1] as $k => $d) {
        foreach ([...$w[$k]['ranges'], ...$w[$k]['dropped']] as $r) {
            if (!($d * ($r['start'] - $r['end']) > 0)) $dir++;                       // תמיכה יורדת מ-H; התנגדות עולה מ-L
            if ($r['start'] !== $w[$k]['start']['price']) $same++;                    // כולם מאותה נקודה
        }
        foreach ($w[$k]['ranges'] as $r) foreach ($r['levels'] as $l) {
            $levelsN++;
            if (!in_array($l['r'], [0.382, 0.5, 0.618], true)) $ratios++;             // קריטריון 7
            if (abs($l['price'] - ($r['start'] - $l['r'] * ($r['start'] - $r['end']))) > 1e-9) $math++;
            if ($d * ($l['price'] - $closeAt[$w[$k]['built_t']]) > 1e-9) $above++;   // קריטריון 5: בבנייה — תמיכה ≤ סגירה; התנגדות ≥ סגירה
        }
        foreach ([...$w[$k]['zones'], ...array_map(fn($l) => ['levels' => [$l]], $w[$k]['singles'])] as $zz) foreach ($zz['levels'] as $l) {
            if (!in_array($l['r'], [0.382, 0.5, 0.618], true)) $ratios++;
        }
    }
}
eq([$dir, $same, $bothSides > 0], [0, 0, true], 'criterion 4: every support range starts at H and goes down, every resistance range starts at L and goes up — all from one point (' . count($windows) . ' windows)');
eq([$above, $levelsN > 100], [0, true], "criterion 5: at build time no kept support level is above the close, no resistance level below it ($levelsN levels)");

// ── 22.3(7) לפי הכרעת הבקרה ("ג"): הפסילה רק בבנייה; הרשת נעולה כל עוד H לא השתנה ──
// (א) המחיר יורד אל האזור — האזור נשאר (בלי נעילה, ב-175 רמת ה-180.90 הייתה מעל הסגירה, והאזור היה נעלם)
$down = $grid(fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 170]));
eq([$down['close'], $ends($down['support']), r2([$down['support']['zones'][0]['lo'] ?? 0, $down['support']['zones'][0]['hi'] ?? 0]), $down['support']['zones'][0]['score'] ?? null],
    [170.0, [150.0, 120.0], [169.1, 169.44], 2], 'lock: price falls to 170 — the zone [169.10, 169.44] stays, though 180.90 and 175 are now above the close');
eq($down['support']['built_t'] < $down['t'], true, '… the side was built earlier (when H was set), not on this candle');
// (ב) קוץ כניעה (E2): בזמן הבנייה כל רמות הטווח כבר מעל הסגירה — נפסל, ונשאר פסול
$build = array_search($down['support']['built_t'], array_column(fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 170]), 't'), true);
$atBuild = fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 170])[$build]['c'];
eq([array_map(fn($r) => r2($r['end']), $down['support']['dropped']), $atBuild < 200 - 0.382 * 4], [[196.0], true],
    'E2: the 200→196 range (levels 198.47 / 198 / 197.53) was above the close at build (' . $atBuild . ') → dropped, and stays dropped');
// (ג) S17 אחרי הבנייה: גוף מתחת לנקודת הסיום של טווח — הטווח יוצא מהרשת הנעולה
$broke = $grid(fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 149, 160]));
eq([r2($broke['support']['start']['price']), $ends($broke['support'])], [200.0, [120.0]], 'lock + S17: a body below 150 after the build removes 200→150; 200→120 stays');
// H חדש → בנייה מחדש
$newH = $grid(fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 185, 210, 195]));
eq([r2($newH['support']['start']['price']), $newH['support']['built_t'] > $g['support']['built_t']], [210.0, true], 'a new H → the side is rebuilt');
// grid_of (מ-lookback_bars + 1 הנרות האחרונים) = הרשת של השיטה שרצה מנר 0
$same = 0;
foreach ([900, 2600, 4999] as $end) {
    $st = $m->init($P);
    foreach (array_slice($F, 0, $end) as $c) [$st] = $m->step($st, $c, $P);
    if (json_encode($st['g']) === json_encode($grid(array_slice($F, 0, $end)))) $same++;
}
eq($same, 3, 'ts_fib_grid_of = the grid of the method run from bar 0 (3 windows)');
// ספירת האותות על 5,000 נרות השעה — נרשמת ב-PR
$cnt = fn(array $o) => count($m->signals($F, $m->params($o)));
$counts = [$cnt([]), $cnt(['min_score' => 2, 'min_rr' => 1]), $cnt(['pivot_atr_mult' => 2, 'min_score' => 2, 'min_rr' => 1])];
// בברירות המחדל: כמה פעמים נר נגע באזור תמיכה בציון ≥ 3 ונסגר מעליו, ולכמה מהן אין אזור התנגדות מעל (יעד — 22.6, לפני F-2)
$st = $m->init($P); $touch = 0; $noTarget = 0;
foreach ($F as $c) {
    foreach ($st['g']['support']['zones'] ?? [] as $zz) if ($zz['score'] >= $P['min_score'] && $c['l'] <= $zz['hi'] && $c['c'] > $zz['hi']) {
        $touch++;
        if (!array_filter($st['g']['resistance']['zones'], fn($r) => $r['lo'] > $c['c'])) $noTarget++;
    }
    [$st] = $m->step($st, $c, $P);
}
echo "  ספירה (נרשמת ב-PR): נגיעות באזור בציון ≥ 3 בברירות המחדל — $touch, מהן בלי יעד — $noTarget\n";
eq($touch > 0, true, "defaults: zones survive the approach — $touch touches of a zone with score ≥ 3 ($noTarget of them with no resistance zone above)");
echo '  ספירה (נרשמת ב-PR): אותות — ' . implode(' / ', $counts) . "\n";
eq([$counts[1] > 0, $counts[2] > 0], [true, true], 'signals on 5,000 h1 candles after the lock: defaults ' . $counts[0] . ', min_score 2 + min_rr 1 → ' . $counts[1] . ', + sensitivity 2 → ' . $counts[2]);
eq([$ratios, $math], [0, 0], 'criterion 7: only 38.2 / 50 / 61.8 — in ranges, zones and singles; level = start − r × (start − end)');

// ── 22.3 (5, 6): נקודות קרובות, ותקרה ──────────────────────
$close = ts_fib_side(fib_cand([140, 120, 175, 150, 175, 150.4, 196, [199, 'body' => 3], 200, 185], $m->params()), fib_piv([140, 120, 175, 150, 175, 150.4, 196, [199, 'body' => 3], 200, 185], $m->params()), $m->params(), 2.0, 1);
eq(count(array_filter($ends($close), fn($e) => $e === 150.0 || $e === 150.4)), 1, '22.3(5): two end points 0.4 apart (< 0.5 × ATR) → one range');
$cap = $grid(fib_bars([140, 100, 175, 110, 175, 120, 175, 130, 175, 140, 175, 150, 175, 160, 196, [199, 'body' => 3], 200, 185]), ['max_ranges' => 2]);
eq(count($cap['support']['ranges']) + count($cap['support']['dropped']), 2, '22.3(6): at most max_ranges candidates');
eq(in_array('longest', array_merge(...array_column([...$cap['support']['ranges'], ...$cap['support']['dropped']], 'kinds')), true), true, '22.3(6): the longest candle stays in under the cap (S15)');

// ── פער (S14) ────────────────────────────────────────────
$gapC = fib_bars([140, 120, 160]);
$k = count($gapC);
foreach (fib_bars([165, 200, 185], $gapC[$k - 1]['t'] + 3600) as $c) $gapC[] = $c;   // 160 → 165: l = 164.5 > h = 160.5
$gg = $grid($gapC);
$gapR = array_values(array_filter($gg['support']['ranges'], fn($r) => in_array('gap', $r['kinds'], true)));
eq([count($gapR), r2($gapR[0]['end'] ?? null)], [1, 160.5], 'S14: a real gap (l[k+1] > h[k]) → a range ending at the gap\'s lower edge');
$hole = $gapC;
foreach ($hole as $i => &$c) if ($i >= $k) $c['t'] += 3 * 3600;   // אותו פער, מעל חור של 3 שעות (הפסקת מסחר)
unset($c);
eq(array_filter($grid($hole)['support']['ranges'], fn($r) => in_array('gap', $r['kinds'], true)), [], 'criterion 6 (S14 [ours]): intraday, a gap over a time hole (session break) does not count');
$daily = array_map(fn($c) => ['t' => 1_700_000_000 + intdiv($c['t'] - 1_700_000_000, 3600) * 86400] + $c, $hole);
eq(count(array_filter($grid($daily)['support']['ranges'], fn($r) => in_array('gap', $r['kinds'], true))), 1, 'S14: on a daily frame, a gap over a weekend counts');

// ── קריטריון 9 + 22.6: אות ────────────────────────────────
// התנגדות מעל: 280 ו-250 (לא נעברו) → אזור [199.66, 200]; תמיכה: 150 (נר חזק וארוך, +1) ו-120 → אזור [169.10, 169.44] בציון 3
$SIG = fib_bars([280, 230, 250, 120, 175, 150, [155, 'body' => 5], 200, 185]);
$lastT = $SIG[count($SIG) - 1]['t'];
$SIG[] = ['t' => $lastT + 3600, 'o' => 185.0, 'h' => 185.5, 'l' => 169.3, 'c' => 175.0];   // צולל לאזור ונסגר מעליו
$PS = $m->params(['lookback_bars' => 500]);
$sig = $m->signals($SIG, $PS);
eq(count($sig), 1, '22.6: exactly one signal');
$s0 = $sig[0];
eq([$s0['t'], $s0['side'], $s0['entry'], r2($s0['zone']), $s0['score'], r2($s0['target'])], [$lastT + 3600, 'long', 175.0, [169.1, 169.44], 3, 199.66],
    '22.6: long at the touch candle — zone score 3, entry = close, target = the bottom of the next resistance zone (M2)');
$atr = ts_fib_grid_of(array_slice($SIG, 0, -1), $PS)['atr'];
eq([round($s0['stop'], 9), $s0['stop'] < $s0['zone'][0], round($s0['rr'], 6)], [round(169.1 - 0.25 * $atr, 9), true, round((199.66 - 175) / (175 - (169.1 - 0.25 * $atr)), 6)],
    'criterion 9 (M1): stop = zone bottom − stop_atr × ATR, below the zone, not inside it');
eq($m->signals($SIG, $m->params(['lookback_bars' => 500, 'min_rr' => 4])), [], 'criterion 9: rr 3.85 < min_rr 4 → no signal');
eq($m->signals($SIG, $m->params(['lookback_bars' => 500, 'min_score' => 4])), [], 'min_score 4 > zone score 3 → no signal');
$noTarget = fib_bars([120, 175, 150, [155, 'body' => 5], 200, 185]);
$noTarget[] = ['t' => $noTarget[count($noTarget) - 1]['t'] + 3600, 'o' => 185.0, 'h' => 185.5, 'l' => 169.3, 'c' => 175.0];
eq($m->signals($noTarget, $PS), [], 'no resistance zone above → no target → no signal (before F-2)');
// שורט — אותם נרות במראה (K − מחיר): אותו אות בכיוון ההפוך
$K = 400.0;
$MIR = array_map(fn($c) => ['t' => $c['t'], 'o' => $K - $c['o'], 'h' => $K - $c['l'], 'l' => $K - $c['h'], 'c' => $K - $c['c']], $SIG);
$sm = $m->signals($MIR, $PS);
eq([count($sm), $sm[0]['side'] ?? null, r2($sm[0]['entry'] ?? 0), r2($sm[0]['zone'] ?? []), r2($sm[0]['target'] ?? 0), round($sm[0]['stop'] ?? 0, 6), round($sm[0]['rr'] ?? 0, 6)],
    [1, 'short', r2($K - 175), [r2($K - 169.44), r2($K - 169.1)], r2($K - 199.66), round($K - $s0['stop'], 6), round($s0['rr'], 6)], 'short = the mirror of long (S1)');
eq($sm[0]['stop'] > $sm[0]['zone'][1], true, 'criterion 9 (short): stop above the zone');
// על הנרות האמיתיים, עם פרמטרים שנותנים אותות — כל אות עומד בכללים
$PR = $m->params(['pivot_atr_mult' => 2, 'min_score' => 2, 'min_rr' => 1]);
$F18 = array_slice($F, 0, 1800);
$real = $m->signals($F18, $PR);
$bad = array_filter($real, fn($s) => $s['side'] === 'long' ? !($s['stop'] < $s['zone'][0] && $s['target'] > $s['entry']) : !($s['stop'] > $s['zone'][1] && $s['target'] < $s['entry']));
$lowRr = array_filter($real, fn($s) => $s['rr'] < 1 - 1e-9 || abs($s['rr'] - abs($s['target'] - $s['entry']) / abs($s['entry'] - $s['stop'])) > 1e-9);
eq([count($real) >= 5, count($bad), count($lowRr)], [true, 0, 0], 'criterion 9 on real candles: every long stop < zone bottom (short: above the top), no rr < min_rr (' . count($real) . ' signals)');

// ── קריטריון 8: בלי הצצה לעתיד ─────────────────────────────
$idx = array_flip(array_column($F18, 't'));
$byT = array_column($real, null, 't');
$peek = 0; $runs = 0;
foreach (array_unique(array_merge(array_map(fn($s) => $idx[$s['t']], $real), range(300, 1799, 250))) as $i) {
    $part = $m->signals(array_slice($F18, 0, $i + 1), $PR);
    $lastPart = $part && end($part)['t'] === $F18[$i]['t'] ? end($part) : null;
    if (($byT[$F18[$i]['t']] ?? null) != $lastPart) $peek++;
    $runs++;
}
eq([$peek, $runs > 8], [0, true], "criterion 8: the signal at i is the same on [0..i] and on the whole set ($runs points)");
$full = $m->signals($SIG, $PS);
eq($m->signals(array_slice($SIG, 0, -1), $PS), [], 'criterion 8: without the touch candle there is no signal (the zone alone is not a signal)');
eq(end($full)['t'], $SIG[count($SIG) - 1]['t'], '… and with it, the signal is on that candle');
// מצטבר = מלא (המצב דרך JSON — כמו במסד)
$cost = ['fee' => 0.001, 'slip' => 0.0005];
$whole = ts_bt_run($m, $PR, $F18, $cost);
$half = json_decode(json_encode(ts_bt_run($m, $PR, array_slice($F18, 0, 900), $cost)), true);
eq(json_encode(ts_bt_run($m, $PR, $F18, $cost, $half)), json_encode($whole), 'incremental (split at 900, state through JSON) = full');

// ── 22.7 הכרטיס ─────────────────────────────────────────
$a = $m->analyze($SIG, $PS);
$cond = [];
foreach ($a['conditions'] as $c) $cond[$c['id']] = $c;
eq(array_keys($cond), ['l-zone', 'l-touch', 'l-rr', 's-zone', 's-touch', 's-rr', 'manual-trunc', 'manual-pattern', 'manual-tf'], '22.7: the condition ids');
eq([$a['bias'], $cond['l-zone']['met'], $cond['l-touch']['met'], $cond['l-rr']['met'], $cond['l-rr']['value'], $cond['s-touch']['met']], ['long', true, true, true, 3.85, false],
    '22.7: with a live long signal — zone, touch and rr met; bias long');
eq([$cond['manual-trunc']['met'], $cond['manual-pattern']['met'], $cond['manual-tf']['met']], [null, null, null], '22.7: "check yourself" items are met: null');
eq([$cond['l-touch']['text'], $cond['l-rr']['text']], ['המחיר נגע באזור ונסגר מעליו', 'יחס סיכוי-סיכון ≥ 2'], '22.7: the texts');
eq([round($a['levels']['stop'], 9), $a['levels']['target'], $a['signal_ago']], [round($s0['stop'], 9), $s0['target'], 0], '22.7: levels of the live signal');
$a0 = $m->analyze($EX, $P);
$c0 = array_column($a0['conditions'], null, 'id');
eq([$a0['bias'], $c0['l-zone']['text'], $c0['l-zone']['met'], $c0['l-touch']['met']], ['none', 'אזור תמיכה: 2 רמות מ-2 טווחים (ציון 2)', false, false],
    '22.7: no signal — the best zone in words; score 2 < min_score 3 → not met');

// ── בגרף (קריטריון 10, צד השרת) ────────────────────────────
require_once dirname(__DIR__) . '/lib/forecast.php';
$ov = ts_overlay_check($m->overlay($EX, $P, 3600), 3600);
[$finding, $forecast] = ts_forecast_split($ov);
$zones = array_values(array_filter($finding['items'], fn($it) => $it['kind'] === 'zone'));
$singles = array_values(array_filter($finding['items'], fn($it) => !empty($it['single'])));
eq([count($zones), r2([$zones[0]['p1'], $zones[0]['p2']]), $zones[0]['extend'], $zones[0]['t2'], count($singles), $forecast['items']],
    [1, [169.1, 169.44], true, $EX[count($EX) - 1]['t'], 4, []], 'criterion 10 (server): the zone extends right, four single levels marked single, nothing in the scenario layer (F-1)');
eq([$zones[0]['t1'], $singles[0]['points'][0]['t'], $singles[0]['style']], [$g['support']['start']['t'], $g['support']['start']['t'], 'solid'], 'R3: lines start at the start point and run right — not backwards');
$ovS = ts_overlay_check($m->overlay($SIG, $PS, 3600), 3600);
eq(array_values(array_map(fn($it) => $it['kind'] . ($it['role'] ?? ''), array_filter($ovS['items'], fn($it) => in_array($it['kind'], ['marker', 'hline'], true)))),
    ['marker', 'hlinestop', 'hlinetarget'], 'a live signal → marker, stop and target on the chart');
eq(isset($m->overlay([], $P, 3600)['note']), true, 'no candles → empty overlay with a note');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);

/** חלון ונקודות מפנה לקריאה ישירה ל-ts_fib_side (בדיקת הדילוג על נקודות קרובות, עם ATR קבוע) */
function fib_cand(array $path, array $p): array
{
    $c = fib_bars($path);
    $a = ts_ew_atr_init(); $out = [];
    foreach ($c as $x) { $a = ts_ew_atr_step($a, $x, (int)$p['atr_len']); $out[] = $x + ['a' => $a['v']]; }
    return $out;
}
function fib_piv(array $path, array $p): array
{
    return array_map(fn($x) => ['type' => $x['type'], 't' => $x['t']], ts_ew_pivots(fib_bars($path), (float)$p['pivot_atr_mult'], (int)$p['atr_len']));
}
