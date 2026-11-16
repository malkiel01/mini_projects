<?php
// H1 (spec-stage-a 18.1.8): אותות עבר — אותו מנוע של הבדיקה לאחור על הנרות שבגרף. קריטריונים 2 (= המנוע), 3 (בלי הצצה), 4 (פתוח),
// 8 (תקרה 50 מתוך N), 10 (רק תצוגה — בלי כתיבה) ו-11 (ביצועים). php trading-strategies/tests/past-signals.php — מסד זמני, בלי רשת.
if (PHP_SAPI !== 'cli') exit;
$tmp = sys_get_temp_dir() . '/ts-past-signals-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
/** נרות שעה: גל סינוס (מחזור $per נרות) סביב 100 — חציות ממוצעים קבועות. מחרוזות, כמו מהמאגר */
function waves(int $count, int $per = 120, int $t0 = 1_700_000_000 - 1_700_000_000 % 3600): array
{
    $out = [];
    $prev = 100.0;
    for ($i = 0; $i < $count; $i++) {
        $c = 100 + 8 * sin(2 * M_PI * $i / $per) + 0.6 * sin($i * 1.7);
        $o = $prev;
        $f = fn(float $v) => number_format($v, 2, '.', '');
        $out[] = ['t' => $t0 + $i * 3600, 'o' => $f($o), 'h' => $f(max($o, $c) + 0.4), 'l' => $f(min($o, $c) - 0.4), 'c' => $f($c), 'v' => '1'];
        $prev = $c;
    }
    return $out;
}
$m = ts_method('sample-ma-cross');
$p = $m->params([]);
$A = 'binance:BTCUSDT';
$rows = waves(3000);

// ── קריטריון 2: = המנוע על אותם נרות ──
$H = ts_history_signals($m, $p, $A, $rows);
$eng = [];
$s = ts_bt_run($m, ts_params_for_asset($m, $p, ts_tick_decimals($rows)), ts_bt_floats($rows), ts_cost_for(ts_asset_market($A)), null, null,
    function (array $t) use (&$eng) { if ($t['result'] !== 'skipped_gap') $eng[] = $t; });
$r = ts_bt_result($s);
$closed = array_values(array_filter($H['items'], fn($x) => $x['result'] !== 'open'));
eq([$H['n'] - (count($H['items']) - count($closed)), $H['n'] > 30], [$r['n'], true], "criterion 2: the number of closed past signals = the engine's N ({$r['n']})");
$tail = array_slice($eng, -count($closed));
eq(array_map(fn($x) => [$x['t'], $x['side'], $x['entry'], $x['stop'], $x['result'], $x['r'], $x['t_exit']], $closed),
    array_map(fn($t) => [(int)$t['t_signal'], $t['side'], (float)$t['entry'], (float)$t['stop'], $t['result'], round((float)$t['r'], 4), (int)$t['t_exit']], $tail),
    'criterion 2: each signal — the same time, side, entry, stop, result, R and exit time as the engine');
eq(array_unique(array_column($closed, 'result')) == array_values(array_intersect(['win', 'loss', 'horizon'], array_unique(array_column($closed, 'result')))) , true, 'results are win / loss / horizon');
eq(count(array_filter($closed, fn($x) => $x['rule'] !== [])) === count($closed) && in_array(true, array_column($closed[0]['rule'], 'met'), true), true,
    'every signal carries the rule of the method at its candle (the conditions of its side)');
eq(array_unique(array_merge(...array_map(fn($x) => array_map(fn($c) => str_contains($c['text'], $x['side'] === 'long' ? 'מעל' : 'מתחת') || str_contains($c['text'], $x['side'] === 'long' ? 'מעלה' : 'מטה'), $x['rule']), $closed))), [true],
    '… only the conditions of the signal\'s side');

// ── קריטריון 3: בלי הצצה לעתיד — [0..i] מול הכול: אותם אותות עד i ──
$i = 1800;
$Hi = ts_history_signals($m, $p, $A, array_slice($rows, 0, $i + 1));
$ti = $rows[$i]['t'];
$sig = fn($items) => array_map(fn($x) => [$x['t'], $x['side'], $x['stop']], array_values(array_filter($items, fn($x) => $x['t'] <= $ti && $x['result'] !== 'open')));
$Hall = ts_history_signals($m, $p, $A, $rows);
// התקרה (50 האחרונים) — משווים את החיתוך של שני הטווחים
$common = array_values(array_intersect(array_column($sig($Hi['items']), 0), array_column($sig($Hall['items']), 0)));
eq(count($common) > 0, true, 'criterion 3: the two runs share past signals up to i');
$byT = fn($items) => array_column(array_map(fn($x) => [$x['t'], [$x['side'], $x['entry'], $x['stop'], $x['result'], $x['r']]], array_filter($items, fn($x) => in_array($x['t'], $common, true))), 1, 0);
eq($byT($Hi['items']), $byT($Hall['items']), 'criterion 3: a signal closed by candle i is identical with and without the candles after i');

// ── קריטריון 4: אות פתוח — בנר האחרון, בלי R ──
$cut = null;
foreach ($eng as $t) if ($t['t_exit'] - $t['t_signal'] > 5 * 3600) { $cut = $t; break; }
$k = array_search($cut['t_signal'] + 2 * 3600, array_column($rows, 't'), true);
$Ho = ts_history_signals($m, $p, $A, array_slice($rows, 0, $k + 1));
$last = end($Ho['items']);
eq([$last['t'], $last['result'], $last['r'], $last['t_exit'], $last['exit'], is_float($last['entry'])], [(int)$cut['t_signal'], 'open', null, null, null, true],
    'criterion 4: a signal whose horizon has not ended → open, entry known, no final R');
$Hp = ts_history_signals($m, $p, $A, array_slice($rows, 0, array_search($cut['t_signal'], array_column($rows, 't'), true) + 1));
$lp = end($Hp['items']);
eq([$lp['t'], $lp['result'], $lp['entry'], $lp['r']], [(int)$cut['t_signal'], 'open', null, null], '… a signal on the last candle (enters at the next open) → open, no entry yet');

// ── קריטריון 8: תקרה — 50 האחרונים מתוך N ──
$big = waves(5000, 100);
$Hb = ts_history_signals($m, $p, $A, $big);
eq([$Hb['n'] > 50, count($Hb['items'])], [true, 50], "criterion 8: {$Hb['n']} past signals → 50 shown, n = {$Hb['n']}");
$allT = [];
ts_bt_run($m, ts_params_for_asset($m, $p, ts_tick_decimals($big)), ts_bt_floats($big), ts_cost_for('crypto'), null, null, function ($t) use (&$allT) { if ($t['result'] !== 'skipped_gap') $allT[] = (int)$t['t_signal']; });
eq(array_slice(array_column($Hb['items'], 't'), 0, 49), array_slice($allT, -49), '… the latest ones (in order)');
eq([$Hb['from'], $Hb['to']], [$big[0]['t'], end($big)['t']], 'the range of the candles it ran on');

// ── קריטריון 10: רק תצוגה — 0 שורות ──
$db = ts_db();
$cnt = fn() => [(int)$db->query('SELECT COUNT(*) FROM method_outcomes')->fetchColumn(), (int)$db->query('SELECT COUNT(*) FROM method_journal')->fetchColumn()];
$before = $cnt();
ts_history_signals($m, $p, $A, $big);
eq($cnt(), $before, 'criterion 10: no rows in method_outcomes or method_journal');

// ── קריטריון 11: ביצועים — 5,000 נרות ≤ 300ms, 20,000 ≤ 1.2 ש' (שיטת הדוגמה ואליוט) ──
foreach (['sample-ma-cross', 'elliott-w3'] as $id) {
    $mm = ts_method($id);
    $pp = $mm->params([]);
    foreach ([[5000, 0.3], [20000, 1.2]] as [$cnt5, $lim]) {
        $rr = waves($cnt5, 97);
        $t0 = microtime(true);
        ts_history_signals($mm, $pp, $A, $rr);
        $dt = microtime(true) - $t0;
        eq($dt <= $lim, true, sprintf('criterion 11: %s on %s candles — %.0fms (≤ %dms)', $id, number_format($cnt5), 1000 * $dt, 1000 * $lim));
    }
}
eq(ts_history_signals($m, $p, $A, []), ['n' => 0, 'bars' => 0, 'from' => null, 'to' => null, 'items' => []], 'no candles → nothing');
eq($Hb['bars'], 5000, 'bars — how many closed candles it ran on ("N אותות ב-M הנרות האחרונים")');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
