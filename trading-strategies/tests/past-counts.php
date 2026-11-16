<?php
// H1b (spec-stage-a 18.1.8 סעיפים 2–3): ספירות אליוט שהושלמו בעבר (קריטריון 5) ואזור פיבונאצ'י לכל אות עבר (קריטריון 6).
// php trading-strategies/tests/past-counts.php — מסד זמני, בלי רשת.
if (PHP_SAPI !== 'cli') exit;
$tmp = sys_get_temp_dir() . '/ts-past-counts-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp*") ?: [] as $f) @unlink($f); });
require __DIR__ . '/../lib/history.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
const T0 = 1_700_000_000 - 1_700_000_000 % 3600;
/** נקודות מפנה מתוך מחירים: מתחילות בשפל (L), מתחלפות; t — כל 10 נרות, ct — 3 נרות אחרי */
function pivs(array $prices, string $first = 'L'): array
{
    $out = [];
    foreach (array_values($prices) as $i => $v) {
        $type = ($i % 2 === 0) === ($first === 'L') ? 'L' : 'H';
        $out[] = ['type' => $type, 't' => T0 + $i * 36000, 'v' => (float)$v, 'ct' => T0 + $i * 36000 + 3 * 3600];
    }
    return $out;
}
$prm = ts_ew_params([]);
// דחף עולה לפי הספר: גל 2 = 61.8% מגל 1, גל 3 = 1.618 × גל 1 (באחוזים), גל 4 = 38.2% מגל 3, גל 5 = גל 1
$imp = [100, 110, 103.82, 120.62, 114.20, 124.20];

// ── קריטריון 5: דחף שלם שהסתיים לפני 3 נקודות מפנה → ספירת עבר אחת ──
$P = pivs([...$imp, 117, 121, 115]);
$C = ts_ew_past_counts($P, 3600, $prm);
eq(count($C), 1, 'criterion 5: a complete impulse that ended 3 pivots ago → exactly one past count');
$c = $C[0] ?? [];
eq([$c['dir'] ?? null, array_column($c['points'] ?? [], 'price'), array_column($c['points'] ?? [], 'label'), $c['t'] ?? null, $c['ct'] ?? null],
    ['up', array_map('floatval', $imp), ['0', '1', '2', '3', '4', '5'], $P[5]['t'], $P[5]['ct']],
    '… its 6 points (0–5), the end of wave 5 and the candle that confirmed it');
eq([$c['score'] >= $prm['min_score'], $c['words']], [true, 'התאמה גבוהה'], '… above min_score, with the score in words');
eq(array_diff_key($c, array_flip(['dir', 'score', 'words', 't', 'ct', 'points'])), [], '… and nothing of the present: no target zone, no projection, no invalidation');

// הדחף שנגמר בנקודה האחרונה הוא ההווה (ts_ew_overlay) — לא עבר
eq(ts_ew_past_counts(pivs($imp), 3600, $prm), [], 'an impulse that ends at the last pivot is the present, not the past');
// דחף יורד — במראה
$down = array_map(fn($v) => round(20000 / $v, 4), $imp);
$Cd = ts_ew_past_counts(pivs([...$down, 190, 170], 'H'), 3600, $prm);
eq([count($Cd), $Cd[0]['dir'] ?? null], [1, 'down'], 'a falling impulse (mirrored) → one past count, down');
// מתחת ל-min_score → לא נבחרת (יחסים רחוקים, אבל בלי הפרת כלל)
$ugly = [100, 101, 100.9, 140, 139.9, 141];
eq(ts_ew_past_counts(pivs([...$ugly, 130, 135]), 3600, $prm), [], 'a valid impulse far from the ratios (score < min_score) → no past count');

// ── אין שתי ספירות שחולקות נקודה; עד 5; רק 40 האחרונות ──
// מגמה ארוכה: אותו דחף שוב ושוב (כל פעם מהשפל של גל 4, כך שכמה m רצופים נותנים ספירה תקפה)
$long = [];
$base = 100.0;
for ($r = 0; $r < 12; $r++) {
    foreach ($imp as $j => $v) if (!($r && $j === 0)) $long[] = round($base * $v / 100, 4);
    $long[] = round($base * 117 / 100, 4);   // תיקון
    $base = end($long) * 0.98;
    $long[] = round($base, 4);
}
$P = pivs($long);
$C = ts_ew_past_counts($P, 3600, $prm);
$used = array_merge(...array_map(fn($x) => array_column($x['points'], 't'), $C));
eq([count($C) >= 2, count($C) <= 5], [true, true], 'a long trend: ' . count($C) . ' past counts — at most 5');
eq(count($used), count(array_unique($used)), 'criterion 5: no two past counts share a pivot');
$tail = array_column(array_slice($P, -40), 't');
eq(array_diff($used, $tail), [], '… all within the last 40 pivots');
eq(array_column($C, 't'), array_values(array_unique(array_column($C, 't'))), '… in time order');
$ts = array_column($C, 't'); $sorted = $ts; sort($sorted);
eq($ts, $sorted, '… oldest first');
foreach ($C as $x) {
    $i = array_search($x['points'][0]['t'], array_column($P, 't'), true);
    eq(array_column($x['points'], 't'), array_column(array_slice($P, $i, 6), 't'), 'a past count = 6 consecutive pivots');
}

// ── דרך השיטה: אליוט מחזירה counts מנרות; שיטה אחרת — [] ──
/** נרות שעה בקו שבור בין המחירים, 30 נרות לגל */
function legs(array $prices, int $per = 30): array
{
    $out = []; $t = T0; $prev = $prices[0];
    for ($k = 1; $k < count($prices); $k++) for ($i = 1; $i <= $per; $i++) {
        $c = $prices[$k - 1] + ($prices[$k] - $prices[$k - 1]) * $i / $per;
        $out[] = ['t' => $t, 'o' => number_format($prev, 4, '.', ''), 'h' => number_format(max($prev, $c) + 0.05, 4, '.', ''),
            'l' => number_format(min($prev, $c) - 0.05, 4, '.', ''), 'c' => number_format($c, 4, '.', ''), 'v' => '1'];
        $prev = $c; $t += 3600;
    }
    return $out;
}
$rows = legs([108, ...$imp, 117, 121, 115, 119]);
$E = ts_method('elliott-w3');
$H = ts_history_signals($E, $E->params([]), 'binance:BTCUSDT', $rows, 3600);
eq(count($H['counts']), 1, 'Elliott on candles: the completed impulse → one past count in history.counts');
eq(array_map(fn($a, $b) => abs($a - $b) <= 0.2, array_column($H['counts'][0]['points'] ?? [], 'price'), $imp), array_fill(0, 6, true), '… on the impulse\'s turning points (± the wick)');
$S = ts_method('sample-ma-cross');
eq(ts_history_signals($S, $S->params([]), 'binance:BTCUSDT', $rows, 3600)['counts'], [], 'a method without counts → counts: []');

// ── קריטריון 6: פיבונאצ'י — לכל אות עבר אזור, מנר האות עד היציאה ──
function waves(int $count, int $per = 120): array
{
    $out = []; $prev = 100.0;
    for ($i = 0; $i < $count; $i++) {
        $c = 100 + 8 * sin(2 * M_PI * $i / $per) + 0.6 * sin($i * 1.7);
        $f = fn(float $v) => number_format($v, 2, '.', '');
        $out[] = ['t' => T0 + $i * 3600, 'o' => $f($prev), 'h' => $f(max($prev, $c) + 0.4), 'l' => $f(min($prev, $c) - 0.4), 'c' => $f($c), 'v' => '1'];
        $prev = $c;
    }
    return $out;
}
$F = ts_method('fibonacci');
$w = waves(3000, 97);
$HF = ts_history_signals($F, $F->params([]), 'binance:BTCUSDT', $w, 3600);
$z = array_filter($HF['items'], fn($x) => $x['entry'] !== null || $x['result'] !== 'open');
eq(count($z) > 3, true, 'fibonacci: past signals on the waves (' . count($z) . ')');
eq(count(array_filter($z, fn($x) => is_array($x['zone']) && $x['zone'][0] <= $x['zone'][1])), count($z), 'criterion 6: every fibonacci past signal carries its zone [low, high]');
eq(count(array_filter($z, fn($x) => $x['side'] === 'long' ? $x['stop'] < $x['zone'][0] : $x['stop'] > $x['zone'][1])), count($z), '… the zone the signal touched (the stop is beyond it)');
eq(array_unique(array_map(fn($x) => $x['zone'], ts_history_signals($S, $S->params([]), 'binance:BTCUSDT', $w, 3600)['items']), SORT_REGULAR), [null], 'a method without zones → zone: null');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
