<?php
// בדיקות המנוע (spec-stage-a 6.1–6.5, פרק 15: בדיקה לאחור, שקלול, אינדיקטורים, כסף). טהור — בלי מסד, בלי רשת.
// php trading-strategies/tests/backtest.php
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-bt-unused-' . getmypid() . '.sqlite');   // לא נפתח; רק כדי שלא ייגע ב-data/
require_once dirname(__DIR__) . '/lib/analysis.php';

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

// ── אינדיקטורים מול ערכים ידועים (1e-9) — אותם ערכים כמו ב-indicators-js.mjs (חושבו בנפרד, Wilder) ──
$c = [44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42, 45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28, 46.00, 46.03, 46.41, 46.22, 45.64];
foreach ([null, null, 2, 3, 4] as $i => $w) near(ts_sma([1.0, 2.0, 3.0, 4.0, 5.0], 3)[$i], $w === null ? null : (float)$w, "sma[$i]");
foreach ([null, null, null, null, 44.104, 44.346000000000004, 44.59733333333334, 44.87155555555556] as $i => $w) near(ts_ema($c, 5)[$i], $w, "ema5[$i]");
$r = ts_rsi($c, 14);
near($r[13], null, 'rsi before period');
near($r[14], 70.46413502109705, 'rsi[14] (Wilder)');
near($r[19], 57.91502067008556, 'rsi[19] (Wilder smoothing)');
$a = ts_atr(array_map(fn($x) => $x + 0.5, $c), array_map(fn($x) => $x - 0.4, $c), $c, 5);
foreach ([null, null, null, null, 0.9719999999999984, 0.9775999999999987, 0.9620799999999987, 0.9496639999999987] as $i => $w) near($a[$i], $w, "atr5[$i]");

// ── הסט הקבוע ────────────────────────────────────────────
$rows = json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true);
$C = ts_bt_floats($rows);
$N = count($C);
$m = ts_method('sample-ma-cross');
$P = $m->params();
eq($P, ['fast' => 20, 'slow' => 50, 'atr' => 14, 'stop_atr' => 2.0, 'target_atr' => 3.0, 'horizon' => 50], 'sample-ma-cross defaults (פרק 10)');
eq([$m->meta()['sample'], $m->meta()['alerts'], $m->meta()['label']], [true, false, '🧪 שיטת דוגמה — לא מהספר'], 'sample: true, not in alerts, with the label');

// השיטה בזרימה = האינדיקטורים במערכים
$cl = array_column($C, 'c');
$sma20 = ts_sma($cl, 20); $sma50 = ts_sma($cl, 50);
$atr14 = ts_atr(array_column($C, 'h'), array_column($C, 'l'), $cl, 14);
$s = $m->init($P);
$bad = 0;
foreach ($C as $i => $cc) {
    [$s] = $m->step($s, $cc, $P);
    foreach ([[$s['f'], $sma20[$i]], [$s['s'], $sma50[$i]], [$s['a']['v'], $atr14[$i]]] as [$g, $w]) {
        if (!($g === $w || ($g !== null && $w !== null && abs($g - $w) <= 1e-9 * max(1, abs($w))))) $bad++;
    }
}
eq($bad, 0, 'streaming SMA20/SMA50/ATR14 inside the method = array indicators (1e-9), on every bar');

// ── מימוש ייחוס, נפרד מהמנוע: האותות מהמערכים, ובדיקה לאחור בלולאה פשוטה ──
$refSig = [];
for ($i = 1; $i < $N; $i++) {
    if ($sma50[$i - 1] === null || $atr14[$i] === null) continue;
    $up = $sma20[$i - 1] <= $sma50[$i - 1] && $sma20[$i] > $sma50[$i];
    $dn = $sma20[$i - 1] >= $sma50[$i - 1] && $sma20[$i] < $sma50[$i];
    if ($up || $dn) $refSig[$i] = $up ? 1 : -1;
}
$sigs = $m->signals($C, $P);
eq(array_map(fn($x) => $x['t'], $sigs), array_map(fn($i) => $C[$i]['t'], array_keys($refSig)), 'signals at the same bars as the reference (array) implementation');
$refN = 0; $refW = 0; $refSkipped = 0; $busyUntil = -1;
foreach ($refSig as $i => $dir) {
    if ($i <= $busyUntil || $i + 1 >= $N) { if ($i <= $busyUntil) $refSkipped++; continue; }
    $e = $C[$i + 1]['o'];
    $risk = 2 * $atr14[$i]; $stop = $e - $dir * $risk; $tgt = $e + $dir * 3 * $atr14[$i];
    $done = false;
    for ($j = $i + 1; $j < $N && $j <= $i + 50; $j++) {
        $hs = $dir > 0 ? $C[$j]['l'] <= $stop : $C[$j]['h'] >= $stop;
        $ht = $dir > 0 ? $C[$j]['h'] >= $tgt : $C[$j]['l'] <= $tgt;
        if ($hs || $ht || $j === $i + 50) { $refN++; if (!$hs && $ht) $refW++; $busyUntil = $j - 1; $done = true; break; }
    }
    if (!$done) $busyUntil = PHP_INT_MAX;   // פתוחה בסוף הסט — לא נספרת
}
$st = ts_bt_run($m, $P, $C, ['fee' => 0, 'slip' => 0]);
$res = ts_bt_result($st);
eq([$res['n'], $res['wins']], [$refN, $refW], 'N and wins = the reference implementation (no costs)');

// הערכים הידועים מראש (חושבו פעם אחת והוקפאו — tests/fixtures/backtest/expected.json)
$exp = json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/expected.json'), true);
eq([$res['signals'], $res['n'], $res['wins'], $res['skipped']], [$exp['signals'], $exp['n'], $exp['wins'], $exp['skipped']], 'signals, N, wins, skipped — known in advance');
eq(ts_certainty($res['wins'], $res['n']), $exp['certainty'], 'Wilson certainty — known in advance');
near($res['expectancy_r'], $exp['expectancy_r'], 'expectancy in R — known in advance');
$stC = ts_bt_run($m, $P, $C, ['fee' => 0.001, 'slip' => 0.0005]);
eq(ts_bt_result($stC)['n'], $exp['n_costs'], 'with fee+slippage — N known in advance');
near(ts_bt_result($stC)['expectancy_r'], $exp['expectancy_r_costs'], 'with fee+slippage — expectancy known in advance (lower)');

// ── (א) בלי הצצה לעתיד: אות ב-i זהה בהרצה על [0..i] ועל כל הסט ──
$byT = array_column($sigs, null, 't');
$peek = 0;
foreach (array_merge(array_keys($refSig), range(55, $N - 1, 97)) as $i) {
    $part = $m->signals(array_slice($C, 0, $i + 1), $P);
    $lastPart = $part && end($part)['t'] === $C[$i]['t'] ? end($part) : null;
    if (($byT[$C[$i]['t']] ?? null) != $lastPart) $peek++;
    $an = $m->analyze(array_slice($C, 0, $i + 1), $P);
    if ($an['as_of'] !== $C[$i]['t']) $peek++;
}
eq($peek, 0, 'no look-ahead: the signal at i is the same on [0..i] and on the full set');

// ── מצטבר = מלא, על אותו סט (המצב עובר דרך JSON, כמו במסד) ──
$cost = ['fee' => 0.001, 'slip' => 0.0005];
$full = ts_bt_run($m, $P, $C, $cost);
foreach ([60, 1234, 2999] as $k) {
    $half = json_decode(json_encode(ts_bt_run($m, $P, array_slice($C, 0, $k), $cost)), true);
    $inc = ts_bt_run($m, $P, $C, $cost, $half);   // הנרות הישנים מדולגים לפי last_t
    eq(json_encode($inc), json_encode($full), "incremental (split at $k, state through JSON) = full — identical state");
}

// ── כללי המנוע על שיטה מתוסרטת: אות בנרות שנבחרו מראש ──
final class TsScripted extends TsMethod
{
    public function __construct(private array $at) {}
    public function meta(): array { return ['id' => 'scripted', 'name' => 'x', 'source' => 'test', 'sample' => true, 'markets' => [], 'timeframes' => [], 'params' => [], 'horizon_bars' => 3, 'version' => 1, 'incremental' => true]; }
    public function init(array $p): array { return ['i' => 0]; }
    public function step(array $s, array $c, array $p): array
    {
        $sig = $this->at[$s['i']] ?? null;
        $s['i']++;
        return [$s, $sig ? ['side' => $sig[0], 'entry' => $c['c'], 'stop' => $c['c'] - ($sig[0] === 'long' ? 1 : -1) * $sig[1], 'target' => $sig[2] === null ? null : $c['c'] + ($sig[0] === 'long' ? 1 : -1) * $sig[2]] : null];
    }
    public function conditions(array $s, array $p): array { return ['bias' => 'none', 'conditions' => [], 'levels' => null]; }
}
$bar = fn(int $t, float $o, float $h, float $l, float $c) => ['t' => $t, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
$flat = fn(int $t, float $p) => $bar($t, $p, $p, $p, $p);

// (ב) כניסה בפתיחת i+1, הסטופ והיעד באותו מרחק מהכניסה בפועל
$sm = new TsScripted([0 => ['long', 5.0, 10.0]]);
$s1 = ts_bt_run($sm, [], [$flat(1, 100), $bar(2, 103, 104, 102, 103)], ['fee' => 0, 'slip' => 0]);
eq([$s1['pos']['entry'], $s1['pos']['stop'], $s1['pos']['target'], $s1['pos']['entry_t']], [103.0, 98.0, 113.0, 2], 'entry at the open of i+1 (103, not the signal close 100); stop/target shifted by the same distance');
$s1 = ts_bt_run($sm, [], [$flat(1, 100), $bar(2, 103, 104, 102, 103)], ['fee' => 0, 'slip' => 0.01]);
near($s1['pos']['entry'], 103 * 1.01, 'slippage is added to the entry (long — above the open)');
$s1 = ts_bt_run($sm, [], [$flat(1, 100)], ['fee' => 0, 'slip' => 0]);
eq([$s1['pos'], $s1['pend']['side'], ts_bt_result($s1)['open']], [null, 'long', true], 'signal on the last bar — waits for the next open, not counted');

// נר שנוגע ביעד וגם בסטופ → הפסד; נר הכניסה נבדק גם הוא
$s1 = ts_bt_run($sm, [], [$flat(1, 100), $bar(2, 100, 111, 94, 100)], ['fee' => 0, 'slip' => 0]);
eq([ts_bt_result($s1)['n'], ts_bt_result($s1)['wins']], [1, 0], 'a bar touching both target and stop = loss (and the entry bar itself is checked)');
$s1 = ts_bt_run($sm, [], [$flat(1, 100), $flat(2, 100), $bar(3, 100, 111, 99, 110)], ['fee' => 0, 'slip' => 0]);
eq([ts_bt_result($s1)['n'], ts_bt_result($s1)['wins']], [1, 1], 'target before stop, within the horizon = win');
near($s1['sum_r'], 2.0, 'win at target = +2R (target 10, stop 5)');
// פער מתחת לסטופ — יציאה בפתיחה
$s1 = ts_bt_run($sm, [], [$flat(1, 100), $flat(2, 100), $bar(3, 90, 91, 89, 90)], ['fee' => 0, 'slip' => 0]);
near($s1['sum_r'], -2.0, 'gap through the stop — exit at the open (90 → −2R), not at the stop level');
// יש יעד, ותום האופק בלי יעד ובלי סטופ — לא הצלחה גם ברווח
$s1 = ts_bt_run($sm, [], [$flat(1, 100), $flat(2, 100), $flat(3, 102), $flat(4, 103), $flat(5, 104)], ['fee' => 0, 'slip' => 0]);
eq([ts_bt_result($s1)['n'], ts_bt_result($s1)['wins']], [1, 0], 'with a target: the horizon ran out (bar 3 after entry) → not a success even in profit');
near($s1['sum_r'], 0.6, 'exit at the close of the last horizon bar (103 → +0.6R)');

// (ו) בלי יעד — יציאה בתום האופק; הצלחה = רווח נטו > 0 אחרי עמלה
$nt = new TsScripted([0 => ['long', 5.0, null]]);
$five = [$flat(1, 100), $flat(2, 100), $flat(3, 100.1), $flat(4, 100.2), $flat(5, 120)];
$s1 = ts_bt_run($nt, [], $five, ['fee' => 0, 'slip' => 0]);
eq([ts_bt_result($s1)['n'], ts_bt_result($s1)['wins'], $s1['last_t']], [1, 1, 5], 'no target: exit at the close of the last horizon bar (bar 4, not the 120 of bar 5) — net > 0 → success');
near($s1['sum_r'], 0.04, 'no target: R from the horizon close (100.2)');
$s1 = ts_bt_run($nt, [], $five, ['fee' => 0.002, 'slip' => 0]);
eq(ts_bt_result($s1)['wins'], 0, 'no target: the same move is a failure once fees make the net negative');
$s1 = ts_bt_run($nt, [], [$flat(1, 100), $flat(2, 100), $bar(3, 100, 100, 94, 95)], ['fee' => 0, 'slip' => 0]);
eq([ts_bt_result($s1)['n'], ts_bt_result($s1)['wins']], [1, 0], 'no target: the stop still ends it first');

// (ז) אותות חופפים — לא נפתח חדש כל עוד הקודם פתוח (גם ממתין לכניסה)
$ov = new TsScripted([0 => ['long', 5.0, 10.0], 1 => ['short', 5.0, 10.0], 2 => ['long', 5.0, 10.0], 4 => ['long', 5.0, 10.0]]);
$s1 = ts_bt_run($ov, [], [$flat(1, 100), $flat(2, 100), $flat(3, 100), $flat(4, 100), $flat(5, 100), $flat(6, 100), $flat(7, 100)], ['fee' => 0, 'slip' => 0]);
eq([ts_bt_result($s1)['signals'], ts_bt_result($s1)['skipped'], ts_bt_result($s1)['n']], [4, 2, 1], 'overlapping signals are not opened (2 skipped while the first was open)');
eq([$s1['pos']['sig_t'] ?? null, $s1['pos']['entry_t'] ?? null], [5, 6], 'after the first closed (horizon, bar 4) — the next signal (bar 5) opens at bar 6');

// תקרת זמן — 20 שניות: חריגה → TsBacktestTimeout, בלי תוצאה
$thrown = false;
try { ts_bt_run($m, $P, $C, $cost, null, microtime(true) - 1); } catch (TsBacktestTimeout) { $thrown = true; }
eq($thrown, true, 'time cap: a run past its deadline stops with "not completed"');
eq([TS_BT_MAX_SECONDS, TS_BT_MAX_CANDLES], [20, 100000], 'caps: 20 s and 100,000 candles (✅5)');
$t = microtime(true);
$big = [];
for ($i = 0; $i < TS_BT_MAX_CANDLES; $i++) { $x = 100 + 10 * sin($i / 300) + ($i % 7) * 0.1; $big[] = $bar($i * 60, $x, $x + 0.5, $x - 0.5, $x + 0.05); }
ts_bt_run($m, $P, $big, $cost, null, microtime(true) + TS_BT_MAX_SECONDS);
$sec = microtime(true) - $t;
eq($sec < TS_BT_MAX_SECONDS, true, sprintf('100,000 candles fit in the time cap (%.1f s)', $sec));

// ── ודאות (6.3) ──────────────────────────────────────────
eq((int)round(100 * ts_wilson_lower(6, 10)), 31, 'Wilson: 6 of 10 (60%) → 31%');
eq((int)round(100 * ts_wilson_lower(60, 100)), 50, 'Wilson: 60 of 100 → 50%');
eq([ts_certainty(20, 29), ts_certainty(18, 30) !== null], [null, true], 'N < 30 → no percentage; N = 30 → a percentage');
eq(ts_wilson_lower(0, 0), null, 'N = 0 → nothing');

// ── שקלול (6.5) ──────────────────────────────────────────
$b = ts_blend([['id' => 'a', 'bias' => 'long', 'certainty' => 60], ['id' => 'b', 'bias' => 'short', 'certainty' => 50], ['id' => 'c', 'bias' => 'long', 'certainty' => null]], ['a', 'b']);
near($b['score'], (0.6 * 1.0 - 0.5 * 0.8) / (0.6 + 0.4), 'blend: score = Σw·dir ÷ Σw, w = certainty × preference (1.0, 0.8)');
eq([$b['excluded'], $b['total'], $b['strong_disagreement']], [['c'], 2, true], 'blend: N<30 method excluded; long 60% and short 40% of the weight → strong disagreement');
$b = ts_blend([['id' => 'a', 'bias' => 'long', 'certainty' => 70], ['id' => 'b', 'bias' => 'long', 'certainty' => 50], ['id' => 'x', 'bias' => 'short', 'certainty' => 40]], ['b']);
// w: a 0.7×0.5=0.35, b 0.5×1.0=0.5, x 0.4×0.5=0.2 → (0.35+0.5−0.2)/1.05; short 0.2/1.05 = 19% < 30%
near($b['score'], 0.65 / 1.05, 'blend: not in the preferred list → 0.5');
eq([$b['strong_disagreement'], $b['counts']['long'], $b['lean']], [false, 2, 'long'], 'blend: short under 30% of the weight → no strong disagreement; "2 of 3 long"');
$b = ts_blend([['id' => 'a', 'bias' => 'none', 'certainty' => 60], ['id' => 'b', 'bias' => 'long', 'certainty' => null]], []);
eq([$b['score'], $b['strong_disagreement']], [0.0, false], 'blend: "none" counts in the weight with direction 0');
eq(ts_blend([['id' => 'b', 'bias' => 'long', 'certainty' => null]], [])['score'], null, 'blend: only methods without certainty → no score');
eq([ts_preference('a', ['a', 'b', 'c', 'd']), ts_preference('c', ['a', 'b', 'c', 'd']), ts_preference('z', ['a'])], [1.0, 0.6, 0.5], 'preference 1.0, 0.8, 0.6 …; not listed → 0.5');

// ── כסף (עיקרון 6): רמה מהמנוע → tick → מחרוזת עשרונית ──
eq(ts_level_str(100.123456, 2), '100.12', 'level rounded to the tick (2 decimals) as a decimal string');
eq(ts_level_str(0.000123456, 6), '0.000123', 'small prices keep their tick');
eq(ts_level_str(98.765, 0), '99', 'tick of 1');
eq(ts_tick_decimals([['o' => '67000.10', 'h' => '67010.25', 'l' => '66990', 'c' => '67001.5']]), 2, 'tick from the provider strings');
eq(is_string(ts_level_str(1.0 / 3, 4)) && dec_parse(ts_level_str(1.0 / 3, 4)) === '0.3333', true, 'the level passes as a decimal string into the decimal math');
// גודל פוזיציה בחשבון העשרוני של שלב 1: 10,000 × 1% ÷ |100.12 − 98.12|
eq(dec_div(dec_div(dec_mul('10000', '1'), '100', 10), dec_abs(dec_sub('100.12', '98.12')), 8), '50', 'position size from the rounded levels — decimal, exact');
eq(ts_horizon_text(50, 'h1'), 'עד 50 נרות של שעה (~2 ימים)', 'horizon text (6.4)');
eq(ts_horizon_text(20, 'd1'), 'עד 20 נרות של יום (~3 שבועות)', 'horizon text in weeks');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
