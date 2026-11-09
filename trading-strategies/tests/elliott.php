<?php
// בדיקות מנוע אליוט ושיטת elliott-w3 (spec-stage-a 17.1.8, 17.1.9 ט מקרים 1–15, 6.2(ח)). טהור — בלי מסד, בלי רשת.
// php trading-strategies/tests/elliott.php
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-ew-unused-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite');   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/methods/elliott-w3.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function near($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want || ($got !== null && $want !== null && abs($got - $want) <= 1e-9 * max(1, abs($want)))) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

/**
 * נרות סינתטיים עם ATR קבוע = 1 (17.1.9 ט: "ATR קבוע = 1 לפשטות"): כל נר h − l = 1, והמרכז זז בצעדים של עד 0.5 —
 * כך TR = 1 בכל נר, וה-ATR של Wilder יוצא 1 בדיוק. נקודת מפנה: שפל P → נר עם l = P בדיוק; שיא P → h = P בדיוק.
 * $pts: [['L'|'H', P], …] ואחריהם $tail — מרכז שאליו המחיר ממשיך (כדי לאשר את הנקודה האחרונה). 20 נרות שטוחים בהתחלה.
 */
function ew_bars(float $start, array $pts, float $tail): array
{
    $out = [];
    $prev = $start;
    $push = function (float $o, float $h, float $l, float $c) use (&$out) {
        $out[] = ['t' => count($out) * 3600, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
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
/** הנרות עד (כולל) נר האות הראשון */
function upto_signal(TsMethod $m, array $C, array $P): array
{
    $sig = $m->signals($C, $P);
    if (!$sig) return [$C, null];
    $i = array_search($sig[0]['t'], array_column($C, 't'), true);
    return [array_slice($C, 0, $i + 1), $sig[0]];
}

$m = new TsMethodElliottW3();
$P = $m->params();
$zero = ['fee' => 0, 'slip' => 0];

// ── meta ─────────────────────────────────────────────────
$meta = $m->meta();
eq([$meta['id'], $meta['levels'], $meta['sample'], $meta['timeframes'], $meta['incremental']], ['elliott-w3', 'absolute', false, ['h1', 'h4', 'd1', 'w1'], true], 'meta: id, levels absolute (6.2(ח)), not a sample, h1 and up (✅18), incremental');
eq($P, ['pivot_atr_mult' => 3.0, 'atr_len' => 14, 'w2_min' => 0.382, 'w2_max' => 0.786, 'min_w1_atr' => 2.0, 'w3_mult' => 1.618, 'horizon_bars' => 60, 'warmup_bars' => 500], 'defaults (17.1.9 ח)');
eq(array_keys(array_filter($meta['params'], fn($d) => !$d['ours'])), ['w3_mult'], 'only w3_mult is "from the book"; all the rest are "ours" — parameters in a range, not constants');
eq([$meta['params']['pivot_atr_mult']['min'], $meta['params']['pivot_atr_mult']['max'], $meta['params']['w2_max']['min'], $meta['params']['w2_max']['max'], $meta['params']['horizon_bars']['max'], $meta['params']['warmup_bars']['max']], [1, 10, 0.618, 0.95, 500, 5000], 'parameter ranges (17.1.9 ח)');
eq([$m->validate(['w2_min' => 0.6, 'w2_max' => 0.6] + $P) !== null, $m->validate($P)], [true, null], 'validate: w2_max must be > w2_min');

// ── ATR בזרימה = ts_atr ──────────────────────────────────
$rows = json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true);
$F = ts_bt_floats($rows);
$ref = ts_atr(array_column($F, 'h'), array_column($F, 'l'), array_column($F, 'c'), 14);
$a = ts_ew_atr_init(); $bad = 0;
foreach ($F as $i => $c) { $a = ts_ew_atr_step($a, $c, 14); if ($a['v'] !== $ref[$i] && abs(($a['v'] ?? 0) - ($ref[$i] ?? 0)) > 1e-9) $bad++; }
eq($bad, 0, 'streaming ATR = ts_atr on every bar');
$C1 = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 110]], 113);
$a = ts_ew_atr_init(); foreach ($C1 as $c) $a = ts_ew_atr_step($a, $c, 14);
eq($a['v'], 1.0, 'the synthetic candles have ATR = 1 exactly');

// ── נקודות מפנה (17.1.9 א) ────────────────────────────────
$pv = ts_ew_pivots($C1);
eq(array_map(fn($p) => [$p['type'], $p['v']], $pv), [['H', 106.0], ['L', 100.0], ['H', 120.0], ['L', 110.0]], 'zigzag pivots: alternating H/L at the bar extremes');
$byT1 = array_column($C1, null, 't');
$p2 = $pv[3];
eq([$byT1[$p2['t']]['l'], $byT1[$p2['ct']]['h'], $byT1[$p2['ct'] - 3600]['h'] < 113.0], [110.0, 113.0, true], 'a low is confirmed on the first later bar with h ≥ L + 3 × ATR (113)');
eq($p2['ct'] > $p2['t'], true, 'confirmation bar is after the extreme bar (c > p)');
// עד האישור הקיצון מתעדכן: שיא גבוה יותר מחליף אותו
$up = ew_bars(100.5, [['H', 110], ['L', 108.5], ['H', 112]], 105);   // הירידה ל-108.5 קטנה מ-3 — לא מאשרת את 110
eq(array_map(fn($p) => [$p['type'], $p['v']], ts_ew_pivots($up)), [['L', 100.0], ['H', 112.0]], 'before confirmation the open extreme moves to a higher high (110 → 112)');

// ── הכללים הקשיחים (17.1.8) ──────────────────────────────
$v = fn(array $p, array $o = [], string $dir = 'up', bool $diag = false, array $ext = []) => ts_ew_impulse_violations(['dir' => $dir, 'p' => $p, 'diagonal' => $diag, 'extended' => $ext], $o);
$imp = [100, 120, 110, 150, 140, 160];
eq($v($imp), [], 'valid impulse: no rule violated');
eq($v([100, 80, 90, 50, 60, 40], [], 'down'), [], 'valid down impulse (mirror)');
eq($v([100, 120, 99, 150, 140, 160]), [1], 'rule 1: wave 2 below the start of wave 1 → disqualified');
eq($v([100, 120, 100, 150, 140, 160]), [1], 'rule 1: wave 2 = 100% of wave 1 → disqualified');
eq($v([100, 120, 110, 119, 115, 117]), [2, 5], 'rule 2: wave 3 does not pass the end of wave 1 → disqualified');
// כלל 3 — באחוזים, מול שני הגלים האחרים
eq($v([10, 20, 15, 27, 22, 40]), [3], 'rule 3: longer than wave 1 in points (12 > 10) but shortest in % (80% < 100%, < 82%) → disqualified');
eq($v([100, 80, 90, 71, 76, 70], [], 'down'), [], 'rule 3: shorter than wave 1 in points (19 < 20) but longer in % (21.1% > 20%) → not disqualified');
eq($v([100, 120, 110, 125, 121, 126]), [], 'rule 3: shorter than wave 1 in % (13.6% < 20%) but longer than wave 5 (4.1%) → not the shortest');
eq($v([100, 120, 110, 140, 110, 150]), [4, 5], 'rule 4: wave 4 retraces 100% of wave 3 → disqualified');
// כלל 5 — חפיפה, עם overlap_tolerance (שבר מטווח גל 1)
eq($v([100, 120, 110, 150, 118.4, 160], ['overlap_tolerance' => 0.1]), [], 'rule 5: tolerance 0.1, wave 4 penetrates 8% of wave 1 → not disqualified');
eq($v([100, 120, 110, 150, 117.6, 160], ['overlap_tolerance' => 0.1]), [5], 'rule 5: tolerance 0.1, penetration 12% → disqualified');
eq($v([100, 120, 110, 150, 118.4, 160]), [5], 'rule 5: default tolerance 0 → any overlap disqualifies');
eq($v([100, 120, 110, 150, 118.4, 160], [], 'up', true), [], 'rule 5: except in a diagonal');
eq($v([100, 120, 110, 150, 118.4, 160], ['allow_diagonal' => false], 'up', true), [5], 'rule 5: allow_diagonal off → the diagonal exception does not apply');
eq($v([100, 80, 90, 50, 81.6, 40], ['overlap_tolerance' => 0.1], 'down'), [], 'rule 5 (down): 8% → not disqualified');
eq($v([100, 80, 90, 50, 82.4, 40], ['overlap_tolerance' => 0.1], 'down'), [5], 'rule 5 (down): 12% → disqualified');
$thrown = false; try { $v($imp, ['overlap_tolerance' => 0.3]); } catch (InvalidArgumentException) { $thrown = true; }
eq($thrown, true, 'overlap_tolerance outside 0–0.25 → rejected');
eq($v($imp, [], 'up', false, [1, 3, 5]), [6], 'rule 6: 1, 3 and 5 all marked extended → disqualified');
eq($v($imp, [], 'up', false, [3]), [], 'rule 6: only wave 3 extended → fine');
eq($v([100, 120, 110]), [], 'partial count (waves 1–2): only the rules that already have their points');

// ספירה על נרות: דחף תקף ומושלם → ספירה אחת
$CI = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 110], ['H', 150], ['L', 140], ['H', 160]], 155);
$cnt = ts_ew_impulse_counts(ts_ew_pivots($CI));
eq([count($cnt), $cnt[0]['dir'] ?? null, array_column($cnt[0]['pivots'] ?? [], 'v')], [1, 'up', [100.0, 120.0, 110.0, 150.0, 140.0, 160.0]], 'valid complete impulse on candles → exactly one count');
$CB = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 99], ['H', 150], ['L', 140], ['H', 160]], 155);
eq(array_filter(ts_ew_impulse_counts(ts_ew_pivots($CB)), fn($c) => $c['pivots'][0]['v'] == 100.0), [], 'the same set with wave 2 below the start of wave 1 → no count from 100');

// בלי הצצה ודטרמיניזם לספירות — על הסט הקבוע
$Pv = ts_ew_pivots($F, 2.0);
$full = ts_ew_impulse_counts($Pv);
eq(json_encode(ts_ew_impulse_counts(ts_ew_pivots($F, 2.0))), json_encode($full), 'determinism: same candles and params → same counts, same order');
$peek = 0;
foreach ([700, 1500, 2600, 3333, 4100, 4999] as $i) {
    $part = ts_ew_impulse_counts(ts_ew_pivots(array_slice($F, 0, $i + 1), 2.0));
    $done = array_values(array_filter($full, fn($c) => end($c['pivots'])['ct'] <= $F[$i]['t']));
    if (json_encode($part) !== json_encode($done)) $peek++;
}
eq([$peek, count($full) > 3], [0, true], 'no look-ahead: counts on [0..i] = the completed counts of the full set at i');

// ── elliott-w3: מקרים 1–6, 13 ────────────────────────────
// 1. לונג תקף
$sig = $m->signals($C1, $P);
eq([count($sig), $sig[0]['side'] ?? null, $sig[0]['stop'] ?? null, $sig[0]['entry'] ?? null], [1, 'long', 100.0, 112.5], 'case 1: long signal at the confirmation bar; stop 100 (start of wave 1); entry (for calculation) = its close');
near($sig[0]['target'] ?? null, 145.596, 'case 1: target = 110 × (1 + 1.618 × 0.2) = 145.596');
eq($sig[0]['t'], $p2['ct'], 'case 1: the signal is on the bar that confirmed P2');
// 2. שורט תקף
$C2 = ew_bars(94.5, [['H', 100], ['L', 80], ['H', 90]], 87);
$sig2 = $m->signals($C2, $P);
eq([count($sig2), $sig2[0]['side'] ?? null, $sig2[0]['stop'] ?? null], [1, 'short', 100.0], 'case 2: short signal (mirror), stop 100');
near($sig2[0]['target'] ?? null, 60.876, 'case 2: target = 90 × (1 − 1.618 × 0.2) = 60.876');
// 3. כלל 1
eq(count($m->signals(ew_bars(105.5, [['L', 100], ['H', 120], ['L', 99]], 104), $P)), 0, 'case 3: P2 = 99 < P0 → no signal');
eq(count($m->signals(ew_bars(105.5, [['L', 100], ['H', 120], ['L', 100]], 104), $P)), 0, 'case 3: P2 = P0 (equal) → no signal');
// 4. עומק — טווח סגור
$depth = fn(float $p2v) => count($m->signals(ew_bars(105.5, [['L', 100], ['H', 120], ['L', $p2v]], $p2v + 4), $P));
eq([$depth(114.0), $depth(104.0), $depth(112.36), $depth(104.28)], [0, 0, 1, 1], 'case 4: r = 0.30 and 0.80 → no signal; r = 0.382 and 0.786 exactly → signal (closed range)');
// 5. גל 1 זניח (רגישות 1 × ATR, כדי שגל של 1.5 × ATR ייווצר)
$C5 = ew_bars(103.5, [['H', 104], ['L', 100], ['H', 101.5], ['L', 100.5]], 102);
$p5 = ['pivot_atr_mult' => 1.0] + $P;
eq(array_map(fn($p) => $p['v'], array_slice(ts_ew_pivots($C5, 1.0), -3)), [100.0, 101.5, 100.5], 'case 5 setup: P0 100 → P1 101.5 (1.5 × ATR) → P2 100.5');
eq(count($m->signals($C5, $p5)), 0, 'case 5: wave 1 = 1.5 × ATR with min_w1_atr = 2 → no signal');
eq(count($m->signals($C5, ['min_w1_atr' => 1.0] + $p5)), 1, 'case 5 (control): the same wave with min_w1_atr = 1 → a signal');
// 6. בלי הצצה
$ci = array_search($sig[0]['t'], array_column($C1, 't'), true);
$before = $m->signals(array_slice($C1, 0, $ci), $P);
$at = $m->signals(array_slice($C1, 0, $ci + 1), $P);
eq([count($before), $at, $sig], [0, $sig, $sig], 'case 6: on [0..c−1] no signal; on [0..c] and on the full set — the same signal at c');
// 13. יעד שורט לא אפשרי
$C13 = ew_bars(94.5, [['H', 100], ['L', 35], ['H', 70]], 66);
eq(array_map(fn($p) => $p['v'], array_slice(ts_ew_pivots($C13), -3)), [100.0, 35.0, 70.0], 'case 13 setup: P0 100 → P1 35 → P2 70 (r ≈ 0.538)');
eq(count($m->signals($C13, $P)), 0, 'case 13: short target 70 × (1 − 1.618 × 0.65) ≈ −3.62 ≤ tick → no signal');
$e13 = TsMethodElliottW3::evaluate(array_slice(ts_ew_pivots($C13), -3), $P);
eq([$e13['w1'], $e13['r1'], $e13['depth'], $e13['target_ok']], [true, true, true, false], 'case 13: only the target condition fails');
// יעד ≤ tick: היעד חיובי אבל קטן מה-tick
$e = TsMethodElliottW3::evaluate([['type' => 'H', 'v' => 100.0], ['type' => 'L', 'v' => 39.0], ['type' => 'H', 'v' => 70.0, 'catr' => 1.0]], $P);
eq([$e['target'] > 0, $e['ok'], TsMethodElliottW3::evaluate([['type' => 'H', 'v' => 100.0], ['type' => 'L', 'v' => 39.0], ['type' => 'H', 'v' => 70.0, 'catr' => 1.0]], ['tick' => 1.0] + $P)['ok']], [true, true, false], 'target ≤ tick → no signal (tick passed by the runner; without one — 0)');

// ── 7–9: המנוע עם רמות מוחלטות (6.2(ח)), עמלה 0 והחלקה 0 ──
[$pre, $s1] = upto_signal($m, $C1, $P);
$last = end($pre);
$next = fn(float $o, float $h, float $l, float $c) => ['t' => $last['t'] + 3600, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
$r7 = ts_bt_result(ts_bt_run($m, $P, [...$pre, $next(99.5, 100, 99, 99.5)], $zero));
eq([$r7['signals'], $r7['skipped_gap'], $r7['skipped'], $r7['n'], $r7['open']], [1, 1, 0, 0, false], 'case 7: open of c+1 = 99.5 < P0 → not opened, skipped_gap +1 (skipped unchanged)');
$r8 = ts_bt_result(ts_bt_run($m, $P, [...$pre, $next(146, 147, 145.5, 146)], $zero));
eq([$r8['signals'], $r8['skipped_gap'], $r8['skipped'], $r8['n']], [1, 1, 0, 0], 'case 8: open of c+1 = 146 ≥ target → not opened, skipped_gap +1');
// 9. אות בסגירה 112, פתיחת c+1 = 113
$C9 = ew_bars(105.5, [['L', 100], ['H', 120], ['L', 110]], 112);
$t9 = end($C9)['t'];
$C9[] = ['t' => $t9 + 3600, 'o' => 112.0, 'h' => 113.0, 'l' => 111.9, 'c' => 112.0];
$s9 = $m->signals($C9, $P);
eq([count($s9), $s9[0]['entry'] ?? null, $s9[0]['t'] ?? null], [1, 112.0, $t9 + 3600], 'case 9 setup: signal with close 112');
$C9[] = ['t' => $t9 + 7200, 'o' => 113.0, 'h' => 113.5, 'l' => 112.5, 'c' => 113.0];
$st9 = ts_bt_run($m, $P, $C9, $zero);
eq([$st9['pos']['entry'] ?? null, $st9['pos']['stop'] ?? null, $st9['pos']['risk'] ?? null], [113.0, 100.0, 13.0], 'case 9: entry 113 → actual stop 100 (not 101), R = 113 − 100 = 13');
near($st9['pos']['target'] ?? null, 145.596, 'case 9: actual target 145.596 (not 146.596)');
eq([$st9['pend'], array_key_exists('stop_d', $st9['pos'])], [null, false], 'absolute: the open position keeps absolute prices, not distances');
// החלקה — הכניסה זזה, הרמות לא
$st9s = ts_bt_run($m, $P, $C9, ['fee' => 0, 'slip' => 0.01]);
eq([$st9s['pos']['stop'], round($st9s['pos']['entry'], 9), round($st9s['pos']['risk'], 9)], [100.0, 114.13, 14.13], 'absolute with slippage: the entry moves (113 × 1.01), the stop stays 100');

// ── 10. sample-ma-cross אחרי 6.2(ח): אותן תוצאות בדיוק ──
// ה-hash של המצב המלא (JSON) — חושב על המנוע **לפני** השינוי (main, 54468e6/6b5f0c4), והוקפא כאן
$sm = ts_method('sample-ma-cross');
$sP = $sm->params();
$h0 = md5(json_encode(ts_bt_run($sm, $sP, $F, $zero)));
$h1 = md5(json_encode(ts_bt_run($sm, $sP, $F, ['fee' => 0.001, 'slip' => 0.0005])));
eq([$h0, $h1], ['88b6184767bd7b38a260d6213c608b47', 'f9d2458760fdedfc1d50ed83e07cb41b'], 'case 10: sample-ma-cross — the full engine state is byte-identical to before 6.2(ח)');
$ss = ts_bt_run($sm, $sP, $F, $zero);
eq([array_key_exists('skipped_gap', $ss), ts_bt_result($ss)['skipped_gap'], array_key_exists('stop_d', $ss['pos'] ?? ['stop_d' => 1]) || $ss['pos'] === null], [false, 0, true], 'case 10: a relative method has no skipped_gap in its state (old incremental state stays valid); result reports 0');
// מצב מצטבר ישן (בלי skipped_gap) ממשיך כמו קודם
$old = json_decode(json_encode(ts_bt_run($sm, $sP, array_slice($F, 0, 2500), $zero)), true);
eq(md5(json_encode(ts_bt_run($sm, $sP, $F, $zero, $old))), $h0, 'case 10: an old incremental state of sample-ma-cross continues to the same result');

// ── 11. אופק ─────────────────────────────────────────────
$flat11 = $pre;
for ($k = 1; $k <= 61; $k++) $flat11[] = ['t' => $last['t'] + 3600 * $k, 'o' => $k === 1 ? 112.5 : 113.0, 'h' => 113.5, 'l' => 112.5, 'c' => $k === 60 ? 113.25 : 113.0];
$st11 = ts_bt_run($m, $P, $flat11, $zero);
$r11 = ts_bt_result($st11);
eq([$r11['n'], $r11['wins'], $st11['pos']], [1, 0, null], 'case 11: neither target nor stop in 60 bars → closed at the horizon; with a target, the horizon is not a success (6.2)');
near($st11['sum_r'], (113.25 - 112.5) / 12.5, 'case 11: exit at the close of the 60th bar (113.25), R from the actual entry');
$m60 = $m->conditions(array_reduce(array_slice($flat11, 0, count($pre) + 59), fn($s, $c) => $m->step($s, $c, $P)[0], $m->init($P)), $P);
$m61 = $m->conditions(array_reduce(array_slice($flat11, 0, count($pre) + 60), fn($s, $c) => $m->step($s, $c, $P)[0], $m->init($P)), $P);
eq([$m60['bias'], $m61['bias']], ['long', 'none'], 'card: bias long while the signal is in force; none once horizon_bars passed');

// ── 12. דטרמיניזם ומצטבר = מלא — על הסט הקבוע ──
foreach ([3.0, 2.0] as $mult) {
    $pp = ['pivot_atr_mult' => $mult] + $P;
    $cost = ['fee' => 0.001, 'slip' => 0.0005];
    $full = ts_bt_run($m, $pp, $F, $cost);
    eq(json_encode(ts_bt_run($m, $pp, $F, $cost)), json_encode($full), "case 12 (mult $mult): determinism — the same state twice");
    foreach ([600, 2222, 4321] as $k) {
        $half = json_decode(json_encode(ts_bt_run($m, $pp, array_slice($F, 0, $k), $cost)), true);
        eq(json_encode(ts_bt_run($m, $pp, $F, $cost, $half)), json_encode($full), "case 12 (mult $mult): incremental (split at $k, through JSON) = full");
    }
    $res = ts_bt_result($full);
    eq($res['signals'] > 10 && $res['n'] > 5, true, "case 12 (mult $mult): the fixed set gives real signals ({$res['signals']} signals, N {$res['n']}, gap {$res['skipped_gap']}, overlap {$res['skipped']})");
}
// בלי הצצה על הסט הקבוע: אות ב-i זהה על [0..i] ועל כל הסט
$pp = ['pivot_atr_mult' => 2.0] + $P;
$all = $m->signals($F, $pp);
$byT = array_column($all, null, 't');
$peek = 0;
$ts = array_column($F, 't');
foreach (array_merge(array_slice(array_column($all, 't'), 0, 25), [$ts[999], $ts[3001]]) as $t) {
    $i = array_search($t, $ts, true);
    $part = $m->signals(array_slice($F, 0, $i + 1), $pp);
    $lp = $part && end($part)['t'] === $t ? end($part) : null;
    if (($byT[$t] ?? null) != $lp) $peek++;
}
eq($peek, 0, 'no look-ahead on the fixed set: the signal at i is the same on [0..i] and on the full set');

// ── 14. נקודת התחלה (ו1) ──────────────────────────────────
$stream = function (array $C, array $p) use ($m) {
    $s = $m->init($p); $piv = []; $sig = [];
    foreach ($C as $c) {
        $n0 = count($s['piv']); $last0 = $n0 ? end($s['piv']) : null;
        [$s, $x] = $m->step($s, $c, $p);
        if ($s['piv'] && end($s['piv']) !== $last0) $piv[] = end($s['piv']);
        if ($x) $sig[] = ['t' => $c['t']] + $x;
    }
    return [$piv, $sig];
};
[$pivA, $sigA] = $stream($F, $P);
foreach ([1000, 2500] as $c0) {
    $K = $P['warmup_bars'];
    [$pivB, $sigB] = $stream(array_slice($F, $c0 - $K), $P);
    $key = fn($p) => $p['type'] . '@' . $p['t'];
    $common = null;
    foreach ($pivB as $j => $p) { $i = array_search($key($p), array_map($key, $pivA), true); if ($i !== false) { $common = [$i, $j]; break; } }
    eq($common !== null, true, "case 14 (from bar $c0 − $K): the two runs share a confirmed pivot");
    [$i, $j] = $common ?? [0, 0];
    $from = $pivA[$i + 2]['ct'] ?? PHP_INT_MAX;   // אות צריך שלוש נקודות — כולן אחרי הנקודה המשותפת
    $sa = array_values(array_filter($sigA, fn($s) => $s['t'] >= $from));
    $sb = array_values(array_filter($sigB, fn($s) => $s['t'] >= $from));
    $core = fn(array $ps) => array_map(fn($p) => [$p['type'], $p['t'], $p['v'], $p['ct']], $ps);
    eq([$core(array_slice($pivB, $j)) === $core(array_slice($pivA, $i, count($pivB) - $j)), $sb, count($sa) > 0], [true, $sa, true], "case 14 (from bar $c0 − $K): same pivots and same signals from the first common pivot");
}

// ── 15. עיגול (עיקרון 6) ─────────────────────────────────
// ts_level_str (הקיים, כמו לכל שיטה) מחזיר מחרוזת עשרונית מנורמלת — "145.6"; בתצוגה לפי ה-tick — "145.60"
$t15 = ts_level_str($sig[0]['target'], 2);
eq([is_string($t15), dec_cmp($t15, '145.60'), dec_fixed($t15, 2)], [true, 0, '145.60'], 'case 15: target 145.596 with tick 0.01 → the decimal string 145.60 (shown "145.60") for calculator and journal');
eq(dec_fixed(ts_level_str($sig2[0]['target'], 2), 2), '60.88', 'case 15: short target 60.876 → "60.88"');

// ── הכרטיס (ו, ז) ────────────────────────────────────────
$an = $m->analyze($C1, $P);
$ids = array_column($an['conditions'], 'met', 'id');
eq([$an['bias'], $ids['l-w1'], $ids['l-r1'], $ids['l-depth'], $ids['l-confirm'], $ids['s-confirm']], ['long', true, true, true, true, false], 'card at the signal: bias long, all long conditions met');
eq([$an['levels']['stop'], $an['as_of'], $an['horizon_bars']], [100.0, end($C1)['t'], 60], 'card: levels from the signal, as_of, horizon');
near(array_column($an['conditions'], 'value', 'id')['l-depth'], 0.5, 'card: l-depth value = r');
eq(array_values(array_map(fn($c) => $c['met'], array_filter($an['conditions'], fn($c) => str_starts_with($c['id'], 'manual-')))), [null, null, null, null], '"check yourself" conditions have met: null — not checked by the computer');
// גל 2 עוד פתוח
$open = ew_bars(105.5, [['L', 100], ['H', 120]], 111);
$ids = array_column($m->analyze($open, $P)['conditions'], 'met', 'id');
eq([$ids['l-w1'], $ids['l-r1'], $ids['l-depth'], $ids['l-confirm'], $m->analyze($open, $P)['bias']], [true, true, true, false, 'none'], 'card while wave 2 is open: w1, r1 and depth from the current low; not confirmed → bias none');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
