<?php
// בדיקות F-2 — פיבונאצ'י, התרחיש (spec-stage-a 22.5, 22.6 — יעד חלופי; קריטריוני F-2 1–10 לפי 2.31), והנתיב המהיר של הרשת.
// php trading-strategies/tests/fibonacci-f2.php — טהור: בלי מסד, בלי רשת, בלי שעון ובלי אקראיות. הנרות — tests/fixtures.
// קריטריון 11 (S7, הסט הקבוע) — tests/fibonacci-zones.php.
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-fib2-unused-' . getmypid() . '.sqlite');   // לא נפתח (tune = 0)
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/forecast.php';
require_once dirname(__DIR__) . '/lib/journal.php';
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
/** השוואה מדויקת בלי תלות בסדר המפתחות (== של PHP משווה null ל-0 כשווים) */
function canon($v) { if (!is_array($v)) return var_export($v, true); if (!array_is_list($v)) ksort($v); return '{' . implode(',', array_map(fn($k, $x) => $k . ':' . canon($x), array_keys($v), $v)) . '}'; }
function r2($v) { return is_array($v) ? array_map('r2', $v) : round((float)$v, 2); }
function r3($v) { return is_array($v) ? array_map('r3', $v) : round((float)$v, 3); }

$m = ts_method('fibonacci');
$P = $m->params();
$F = ts_bt_floats(json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true));
$grid = fn(array $c, array $o = []) => ts_fib_grid_of($c, $m->params($o));
$tp = fn(?array $abx) => $abx ? r2(array_column($abx['targets'], 'price')) : null;
$H = 3600;

eq([$m->meta()['version'], TS_FIB_PROJ], [2, [0.618, 1.0, 1.382, 1.5, 1.618]], 'version 2 (F-2 changes scores and signals); projection ratios = R5 only (22.5א)');

// ── קריטריון 1: A-B-X מטה ─────────────────────────────────
// הזיגזג: שפל 140, שיא 200, שפל 150, שיא 170 (מאושר בירידה ל-160) → שלוש האחרונות: A = 200, B = 150, X = 170
$D = fib_bars([140, 200, 150, 170, 160]);
$abx = $grid($D)['abx'];
eq([$abx['dir'] ?? null, r2([$abx['a']['p'] ?? 0, $abx['b']['p'] ?? 0, $abx['x']['p'] ?? 0])], ['down', [200.0, 150.0, 170.0]], 'criterion 1: the last three zigzag points, in body — A 200, B 150, X 170');
eq($tp($abx), [139.1, 120.0, 100.9, 95.0, 89.1], 'criterion 1: targets 139.10 / 120.00 / 100.90 / 95.00 / 89.10');
eq(array_column($abx['targets'], 'r'), [0.618, 1.0, 1.382, 1.5, 1.618], 'criterion 1: no 261.8 (R7), no page 102');
// מעלה — במראה: A = 200, B = 250, X = 230 → 230 + r × 50
$U = fib_bars([260, 200, 250, 230, 240]);
eq([$grid($U)['abx']['dir'] ?? null, $tp($grid($U)['abx'])], ['up', [260.9, 280.0, 299.1, 305.0, 310.9]], 'A-B-X up (mirror): X + r × (B − A)');

// ── קריטריון 2: A-B-X מחוק ────────────────────────────────
eq($grid(fib_bars([140, 200, 150, 205, 195]))['abx'], null, 'criterion 2: A 200, B 150, X 205 — X passed A → no A-B-X');
eq($grid(fib_bars([260, 200, 250, 195, 205]))['abx'], null, 'criterion 2 (mirror): X below A → no A-B-X');
eq(ts_fib_abx([['type' => 'H', 'p' => 200.0, 't' => 1, 'ct' => 2], ['type' => 'L', 'p' => 150.0, 't' => 3, 'ct' => 4]]), null, 'fewer than three points → no A-B-X');
// מתחלף רק כשנקודת מפנה חדשה מאושרת: עוד נרות בלי מפנה — אותם יעדים
eq($tp($grid([...$D, ...fib_bars([160, 162, 161], $D[count($D) - 1]['t'] + $H)])['abx']), $tp($abx), 'A-B-X stays until a new pivot is confirmed');

// ── קריטריון 3: קופסת השוויון מעלה (הדוגמה של 22.4) ─────────
$EX = fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 185]);
$gx = $grid($EX);
$z = $gx['support']['zones'][0];
eq([r2($gx['support']['move']['price']), r2([$z['lo'], $z['hi']]), round($z['box']['mid'], 3), r2($z['box']['T'])], [120.0, [169.1, 169.44], 169.27, 218.54],
    'criterion 3: M0 = 120, zone [169.10, 169.44], middle 169.27 → T = 218.54 (upward, not 138.54 of 2.27)');
eq(r3(array_column($z['box']['levels'], 'price')), [188.091, 193.905, 199.719], 'criterion 3: intermediate targets 188.09 / 193.905 / 199.72');
$zr = $gx['resistance']['zones'];
$mirrorOk = true;
foreach ($zr as $q) if (abs($q['box']['T'] - (2 * ($q['lo'] + $q['hi']) / 2 - $gx['resistance']['move']['price'])) > 1e-9 || $q['box']['T'] > $q['lo']) $mirrorOk = false;
eq($mirrorOk, true, 'resistance (mirror): T = 2 × middle − M0\' — below the zone');

// ── קריטריון 4: גוף ולא פתיל ─────────────────────────────
// נר המפנה: פתיל עד 205, גוף עד 199. נר האישור נפתח ב-200 (גוף עד 200) וצולל → A = 200, והיעדים כמו בקריטריון 1
$W = fib_bars([140, [199, 'wick' => 205]]);
$tw = $W[count($W) - 1]['t'];
$W[] = ['t' => $tw + $H, 'o' => 200.0, 'h' => 200.5, 'l' => 198.5, 'c' => 198.0];
$W = [...$W, ...fib_bars([198, 150, 170, 160], $tw + 2 * $H)];
$aw = $grid($W)['abx'];
eq([r2($aw['a']['p'] ?? 0), $tp($aw)], [200.0, [139.1, 120.0, 100.9, 95.0, 89.1]], 'criterion 4: wick 205, body 200 in the confirmation candle → A = 200, targets as in criterion 1');

// ── קריטריון 5: Z6 ─────────────────────────────────────────
$zone = fn(float $lo, float $hi, int $score) => ['lo' => $lo, 'hi' => $hi, 'score' => $score, 'levels' => [], 'ranges' => 2];
$side = fn(?float $m0, array $zones) => ['start' => null, 'move' => $m0 === null ? null : ['price' => $m0, 't' => 0], 'zones' => $zones, 'singles' => [], 'ranges' => []];
$abx100 = fn(float $x, float $len) => ts_fib_abx([['type' => 'L', 'p' => 100.0, 't' => 1, 'ct' => 2], ['type' => 'H', 'p' => 100.0 + $len, 't' => 3, 'ct' => 4],
    ['type' => 'L', 'p' => $x, 't' => 5, 'ct' => 6]]);
// A-B-X: 150 + 1 × 20 = 170 → בתוך [169.9, 170.2]
$gz = ts_fib_project(['support' => $side(null, [$zone(169.9, 170.2, 2), $zone(160.0, 160.5, 3)]), 'resistance' => $side(null, [])], $abx100(150.0, 20.0));
eq(array_map(fn($q) => [$q['score'], $q['base'], $q['z6']], $gz['support']['zones']), [[3, 2, true], [3, 3, false]],
    'criterion 5: a 100% target inside a zone → score + 1; a zone without a target — the F-1 score');
// ההקרנה מחושבת מחדש בכל נר — לא נצברת (base נשמר)
$gz2 = ts_fib_project($gz, $abx100(150.0, 20.0));
eq(array_column($gz2['support']['zones'], 'score'), [3, 3], 'Z6 is not added twice when the grid is re-projected');
// T של קופסה של אזור אחד בתוך אזור אחר — גם Z6 [שלנו]; רק 100% (לא 61.8%)
$gb = ts_fib_project(['support' => $side(100.0, [$zone(110.0, 112.0, 2)]), 'resistance' => $side(null, [$zone(121.5, 122.5, 2), $zone(117.0, 117.5, 2)])], null);
eq([r2($gb['support']['zones'][0]['box']['T']), array_column($gb['resistance']['zones'], 'z6')], [122.0, [true, false]],
    'Z6 from a box: T 122 of the support zone falls in the resistance zone [121.5, 122.5] → +1; 61.8% of the box (117.4) — no');
// בגרף: יעד בתוך אזור — מודגש; יעד לבד — דהוי
$gs = ts_fib_project(['support' => $side(null, [$zone(169.9, 170.2, 2)]), 'resistance' => $side(null, [])], $abx100(150.0, 20.0));
[$si] = ts_fib_scenario($gs, null, ['t' => 1_000_000, 'c' => 160.0], $H, 60);
$abxLines = array_values(array_filter($si, fn($it) => str_starts_with($it['text'] ?? '', 'A-B-X')));
eq(array_map(fn($it) => [$it['text'], !empty($it['bold']), !empty($it['single']), $it['style']], $abxLines), [
    ['A-B-X 61.8%', false, true, 'dashed'], ['A-B-X 100%', true, false, 'dashed'], ['A-B-X 138.2%', false, true, 'dashed'],
    ['A-B-X 150%', false, true, 'dashed'], ['A-B-X 161.8%', false, true, 'dashed']], 'criterion 5 (chart): the 100% target in a zone — bold; the others alone — faint (R8)');

// ── קריטריון 7: יעד חלופי ──────────────────────────────────
// אות לונג על [169.10, 169.44], בלי אזור התנגדות מעל → T של הקופסה של אותו אזור
$NT = fib_bars([120, 175, 150, [155, 'body' => 5], 200, 185]);
$NT[] = ['t' => $NT[count($NT) - 1]['t'] + $H, 'o' => 185.0, 'h' => 185.5, 'l' => 169.3, 'c' => 175.0];
$PS = $m->params(['lookback_bars' => 500]);
$nt = $m->signals($NT, $PS);
$gNT = $grid(array_slice($NT, 0, -1), ['lookback_bars' => 500]);
eq([count($nt), $nt[0]['side'] ?? null, r2($nt[0]['zone'] ?? []), $gNT['resistance']['zones'] ? 'zones' : 'none', r2($nt[0]['target'] ?? 0), $nt[0]['via'] ?? null],
    [1, 'long', [169.1, 169.44], 'none', 218.54, 'box'], 'criterion 7: long on a zone with no resistance zone above → target = T of the box of that zone');
eq(round($nt[0]['rr'], 6), round((218.54 - 175) / (175 - $nt[0]['stop']), 6), 'criterion 7: rr from T');
eq($m->signals($NT, $m->params(['lookback_bars' => 500, 'min_rr' => 5 + floor($nt[0]['rr'] - 4)])), [], 'criterion 7: rr < min_rr → no signal');
// T ≤ הכניסה → אין אות (ישירות על רשת: הקופסה של האזור מסתיימת מתחת לסגירה)
$gT = ['atr' => 2.0, 'close' => 180.0, 'support' => ['zones' => [['lo' => 169.1, 'hi' => 169.44, 'score' => 3, 'box' => ['T' => 174.0]]]], 'resistance' => ['zones' => []]];
eq(ts_fib_signal($gT, ['t' => 1, 'o' => 180.0, 'h' => 180.5, 'l' => 169.3, 'c' => 175.0], $P), null, 'criterion 7: T ≤ entry → no signal');
$gT['support']['zones'][0]['box']['T'] = 240.0;
eq(ts_fib_signal($gT, ['t' => 1, 'o' => 180.0, 'h' => 180.5, 'l' => 169.3, 'c' => 175.0], $P)['via'] ?? null, 'box', 'control: T above the entry → a signal');
// כשיש אזור התנגדות מעל — הוא היעד (M2), גם אם הקופסה רחוקה יותר
$gT['resistance']['zones'] = [['lo' => 199.66, 'hi' => 200.0, 'score' => 2]];
$s2 = ts_fib_signal($gT, ['t' => 1, 'o' => 180.0, 'h' => 180.5, 'l' => 169.3, 'c' => 175.0], $P);
eq([$s2['target'] ?? null, $s2['via'] ?? null], [199.66, 'zone'], 'a resistance zone above → it is the target (M2), not the box');

// ── קריטריון 8: אותות בברירות המחדל ───────────────────────
$sig = $m->signals($F, $P);
$by = [];
foreach ($sig as $s) { $k = $s['side'] . '/' . $s['via'] . ($s['z6'] ? '/z6' : ''); $by[$k] = ($by[$k] ?? 0) + 1; }
ksort($by);
echo '  ספירה (נרשמת ב-PR): אותות בברירות המחדל על ' . count($F) . ' נרות שעה — ' . count($sig) . ' (' . json_encode($by) . ")\n";
eq(count($sig) > 0, true, 'criterion 8: more than 0 signals in the defaults on the 5,000 h1 candles (' . count($sig) . ')');
$bad = array_filter($sig, fn($s) => $s['side'] === 'long' ? !($s['stop'] < $s['zone'][0] && $s['target'] > $s['entry']) : !($s['stop'] > $s['zone'][1] && $s['target'] < $s['entry']));
eq([count($bad), count(array_filter($sig, fn($s) => $s['rr'] < $P['min_rr'] - 1e-9 || $s['score'] < $P['min_score']))], [0, 0],
    'every default signal: stop beyond the zone, target beyond the entry, rr ≥ min_rr, score ≥ min_score');

// ── קריטריון 6: בלי הצצה לעתיד, גם עם Z6 ──────────────────
// האות והציון ב-i — אותם על [0..i] ועל כל הסט. ריצה אחת עם רשת שמורה בנקודות הבדיקה
$idx = array_flip(array_column($F, 't'));
$byT = array_column($sig, null, 't');
$check = array_unique(array_merge(array_map(fn($s) => $idx[$s['t']], array_slice($sig, 0, 25)), range(400, count($F) - 1, 450)));
sort($check);
$at = []; $st = $m->init($P);
foreach ($F as $i => $c) { [$st] = $m->step($st, $c, $P); if (in_array($i, $check, true)) $at[$i] = $st['g']; }
$peek = 0; $score = 0;
foreach ($check as $i) {
    $part = $m->signals(array_slice($F, 0, $i + 1), $P);
    $lastPart = $part && end($part)['t'] === $F[$i]['t'] ? end($part) : null;
    if (($byT[$F[$i]['t']] ?? null) != $lastPart) $peek++;
    $gi = $grid(array_slice($F, 0, $i + 1));
    foreach (['support', 'resistance'] as $k) if (array_column($gi[$k]['zones'], 'score') !== array_column($at[$i][$k]['zones'], 'score')) $score++;
}
eq([$peek, $score, count($check) > 10], [0, 0, true], 'criterion 6: the signal and the zone scores (with Z6) at i are the same on [0..i] and on the whole set (' . count($check) . ' points)');
$cost = ['fee' => 0.001, 'slip' => 0.0005];
$whole = ts_bt_run($m, $P, $F, $cost);
$half = json_decode(json_encode(ts_bt_run($m, $P, array_slice($F, 0, 2500), $cost)), true);
eq(json_encode(ts_bt_run($m, $P, $F, $cost, $half)), json_encode($whole), 'incremental (split at 2,500, state through JSON) = full, in the defaults');

// ── קריטריון 9: הממצא עם תרחיש ובלעדיו ─────────────────────
$gF = $grid($F);
[$fin, $fc] = ts_forecast_split(ts_overlay_check($m->overlay($F, $P, $H), $H));
$fz = array_values(array_filter($fin['items'], fn($it) => $it['kind'] === 'zone'));
$gzs = [...$gF['support']['zones'], ...$gF['resistance']['zones']];
eq(array_map(fn($it) => [r3($it['p1']), r3($it['p2']), $it['score']], $fz), array_map(fn($q) => [r3($q['lo']), r3($q['hi']), $q['score']], $gzs),
    'criterion 9: the finding zones = the grid zones — bounds and scores (with Z6), whatever the scenario');
eq([count($fc['items']) > 0, array_filter($fin['items'], fn($it) => ts_forecast_item($it, $fin['as_of'])), array_filter($fc['items'], fn($it) => !ts_forecast_item($it, $fc['as_of']))],
    [true, [], []], 'criterion 9: the scenario is only after the last closed candle (dashed / transparent), and nothing of it is in the finding');

// ── 22.5ד: כיוון התרחיש ───────────────────────────────────
$items = fn(array $c, array $p = []) => ts_forecast_split(ts_overlay_check($m->overlay($c, $m->params($p), $H), $H))[1];
$fD = $items($D);
eq([$fD['scenarios'][0]['label'] ?? null, count(array_filter($fD['items'], fn($it) => str_starts_with($it['text'] ?? '', 'A-B-X')))],
    ['לפי כללי השיטה — מטה (A-B-X), עד 60 נרות', 5], '22.5ד: no signal → the A-B-X direction, its five targets');
$fN = $items($NT, ['lookback_bars' => 500]);
eq([$fN['scenarios'][0]['label'] ?? null, count(array_filter($fN['items'], fn($it) => ($it['box'] ?? null) === 'support' && empty($it['single'])))],
    ['לפי כללי השיטה — מעלה, עד 60 נרות', 1], '22.5ד: a live long signal → the scenario goes up, with the box of the signal zone');
$E = fib_bars([140, 200, 150, 205, 195]);
$fE = $items($E);
eq([$fE['scenarios'], array_filter($fE['items'], fn($it) => empty($it['single']))], [[], []], '22.5ד: no A-B-X and no signal → only faint boxes, no scenario');

// ── קריטריון 10: יומן ──────────────────────────────────────
$rows = fn(array $c) => array_map(fn($x) => ['t' => $x['t'], 'o' => (string)$x['o'], 'h' => (string)$x['h'], 'l' => (string)$x['l'], 'c' => (string)$x['c'], 'v' => '0'], $c);
[$jD] = ts_journal_said(ts_overlay_compute($m, $P, 'h1', $rows($D)), 'h1');
$lastD = $D[count($D) - 1]['t'];
eq([$jD['scenario']['invalidation'] ?? null, $jD['scenario']['until'] ?? null, $jD['scenario']['zone'] ?? null, $jD['scenario']['text'] ?? null],
    ['170', $lastD + 60 * $H, ['lo' => '120', 'hi' => '139.1'], 'בין יעד 61.8% ליעד 100% של A-B-X'],
    'criterion 10: X (170) as the invalidation level, horizon_bars, and — no zone with a target — the band 61.8%–100% of A-B-X');
[$jE] = ts_journal_said(ts_overlay_compute($m, $P, 'h1', $rows($E)), 'h1');
eq($jE['scenario'], null, 'criterion 10: no A-B-X and no signal → "no scenario"');
// אזור חפיפה בכיוון שבתוכו יעד → הוא האזור ביומן
$gJ = ts_fib_project(['support' => $side(null, []), 'resistance' => $side(null, [$zone(119.5, 120.5, 2), $zone(130.0, 131.0, 2)])], ts_fib_abx([
    ['type' => 'H', 'p' => 200.0, 't' => 1, 'ct' => 2], ['type' => 'L', 'p' => 150.0, 't' => 3, 'ct' => 4], ['type' => 'H', 'p' => 170.0, 't' => 5, 'ct' => 6]]));
[$jI] = ts_fib_scenario($gJ, null, ['t' => 1_000_000, 'c' => 160.0], $H, 60);
$prim = array_values(array_filter($jI, fn($it) => !empty($it['primary'])));
eq(array_map(fn($it) => $it['kind'] === 'zone' ? [$it['p1'], $it['p2'], $it['text']] : [$it['role'], $it['price']], $prim),
    [[119.5, 120.5, 'אזור חפיפה שבתוכו יעד'], ['invalidation', 170.0]],
    '22.5ו: the journal zone — the nearest zone in the direction that holds a target (120 in 119.5–120.5; 130–131 is nearer but holds none)');

// ── ביצועים: הנתיב המהיר = החישוב המלא, בכל נר ──────────────
// אותה זרימה פעמיים: פעם עם הנתיב המהיר (ts_fib_grid), ופעם בלי (הרשת הקודמת בלי pl → תמיד ts_fib_side)
$diff = 0; $cnt = 0;
foreach ([[], ['pivot_atr_mult' => 2, 'min_score' => 2, 'min_rr' => 1], ['lookback_bars' => 1000, 'max_ranges' => 6], ['lookback_bars' => 100, 'close_atr' => 0.1]] as $o) {
    $p = $m->params($o);
    $a = ts_fib_init(); $slow = null;
    foreach ($F as $c) {
        $prev = $a['g'];
        $a = ts_fib_advance($a, $c, $p);
        if ($a['g'] === null) continue;
        $ref = $slow;
        if ($ref !== null) unset($ref['pl']);
        $slow = ts_fib_grid($a['w'], $a['piv'], $p, $ref, $a['k']);
        $cnt++;
        if (canon($a['g']) !== canon($slow)) $diff++;
    }
}
eq([$diff, $cnt > 19000], [0, true], "performance: the fast path = the full computation on every candle ($cnt candles, 4 parameter sets)");

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
