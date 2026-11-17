<?php
// H1c (spec-stage-a 18.1.8 סעיף 6, קריטריון 12): מנה ישנה — רק פריטים לפני תחילת הטווח הקודם, וזהים לחישוב על כל הנרות שבגרף.
// php trading-strategies/tests/past-batch.php — מסד זמני, בלי רשת.
if (PHP_SAPI !== 'cli') exit;
$tmp = sys_get_temp_dir() . '/ts-past-batch-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
function waves(int $count, int $per = 97): array
{
    $out = []; $prev = 100.0; $t0 = 1_700_000_000 - 1_700_000_000 % 3600;
    for ($i = 0; $i < $count; $i++) {
        $c = 100 + 8 * sin(2 * M_PI * $i / $per) + 3 * sin(2 * M_PI * $i / 23) + 0.6 * sin($i * 1.7);
        $f = fn(float $v) => number_format($v, 2, '.', '');
        $out[] = ['t' => $t0 + $i * 3600, 'o' => $f($prev), 'h' => $f(max($prev, $c) + 0.4), 'l' => $f(min($prev, $c) - 0.4), 'c' => $f($c), 'v' => '1'];
        $prev = $c;
    }
    return $out;
}
$A = 'binance:BTCUSDT';
$all = waves(8000);
$from = 2001; $b = 3000;                     // המנה: [2001, 3000); הטווח הקודם מתחיל ב-3000
$before = $all[$b]['t'];
$chart = array_slice($all, $from);            // כל הנרות שבגרף אחרי שהמנה נכנסה
foreach (['sample-ma-cross', 'fibonacci', 'elliott-w3'] as $id) {
    $m = ts_method($id);
    $p = $m->params([]);
    $full = ts_history_signals($m, $p, $A, $chart, 3600, $before);
    $tail = $m->horizon($p) + TS_HISTORY_TAIL;
    $short = ts_history_signals($m, $p, $A, array_slice($all, $from, $b - $from + $tail), 3600, $before);
    $tag = "$id: {$full['n']} signals, " . count($full['counts']) . ' counts';
    eq(array_unique(array_map(fn($x) => $x['t'] < $before, [...$full['items'], ...$full['counts']])) ?: [true], [true], "criterion 12 ($tag): only items before the previous range");
    eq([$short['n'], $short['items'], $short['counts']], [$full['n'], $full['items'], $full['counts']],
        "$id: [batch, before + horizon + tail] = all the candles in the chart — the same signals, results and counts");
    eq([$full['bars'], $full['from'], $full['to']], [$b - $from, $all[$from]['t'], $all[$b - 1]['t']], "$id: bars / from / to — the batch only");
    if ($id !== 'elliott-w3') eq($full['n'] > 0, true, "$id: the batch has past signals");
}
// ספירות עבר במנה — עד 5, בלי נקודה משותפת, ורק לפני before
$m = ts_method('elliott-w3');
$H = ts_history_signals($m, $m->params(['pivot_atr_mult' => 1.5]), $A, $chart, 3600, $before);
$pts = array_merge([], ...array_map(fn($c) => array_column($c['points'], 't'), $H['counts']));
eq([count($H['counts']) >= 1, count($H['counts']) <= 5, count($pts) === count(array_unique($pts)), max($pts ?: [0]) < $before], [true, true, true, true],
    'Elliott in a batch: ' . count($H['counts']) . ' past counts, at most 5, no shared pivot, all before the previous range');
// בלי before — כמו H1a (בלי שינוי)
$m = ts_method('sample-ma-cross');
eq(ts_history_signals($m, $m->params([]), $A, $chart, 3600)['n'] >= ts_history_signals($m, $m->params([]), $A, $chart, 3600, $before)['n'], true, 'without before — the whole range (H1a unchanged)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
