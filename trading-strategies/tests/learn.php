<?php
// למידה עצמית, L1 (spec-stage-a 23.3–23.5): המנוע מחזיר עסקאות, התגיות, לוח החגים, והמסד method_outcomes.
// php trading-strategies/tests/learn.php — מסד זמני, בלי רשת. הצד של ה-API (בדיקה לאחור אמיתית) — tests/learn-api.php.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-learn-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp*") ?: [] as $f) @unlink($f); });
require __DIR__ . '/../lib/analysis.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function near($got, float $want, string $what, float $tol = 1e-9): void
{
    eq(is_float($got) || is_int($got) ? abs($got - $want) <= $tol : $got, true, "$what (≈ $want, got " . var_export($got, true) . ')');
}

/** שיטת בדיקה: אות לונג בכל נר שב-$at, סטופ 2 מתחת לסגירה, יעד 10 מעל; אופק 3 נרות. relative — כמו sample-ma-cross */
final class TsLearnProbe extends TsMethod
{
    public function __construct(private array $at = [], private int $h = 3) {}
    public function meta(): array
    {
        return ['id' => 'learn-probe', 'name' => 'probe', 'source' => 'test', 'markets' => ['crypto'], 'timeframes' => ['h1'],
            'params' => [], 'horizon_bars' => $this->h, 'warmup' => 0, 'version' => 1, 'incremental' => true,
            'learn' => ['k' => 'מספר האות']];
    }
    public function horizon(array $p): int { return $this->h; }
    public function init(array $p): array { return ['k' => 0]; }
    public function step(array $s, array $c, array $p): array
    {
        if (!in_array($c['t'], $this->at, true)) return [$s, null];
        $s['k']++;
        return [$s, ['side' => 'long', 'entry' => $c['c'], 'stop' => $c['c'] - 2, 'target' => $c['c'] + 10, 'features' => ['k' => $s['k']]]];
    }
    public function conditions(array $s, array $p): array { return ['bias' => 'none', 'conditions' => [], 'levels' => null]; }
}
$bar = fn(int $t, float $o, float $h, float $l, float $c) => ['t' => $t, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
$zero = ['fee' => 0, 'slip' => 0];
$collect = function (TsMethod $m, array $C, ?array $state = null) use ($zero): array {
    $out = [];
    $s = ts_bt_run($m, [], $C, $zero, $state, null, function (array $t) use (&$out) { $out[] = $t; });
    return [$out, $s];
};

// ── קריטריון 3: MAE / MFE — לונג, כניסה 100, סטופ 98 (1R = 2), שפל 99 ושיא 104 לפני היציאה ──
$T0 = 1798200000 - 1798200000 % 3600;
$C3 = [$bar($T0, 99, 100.2, 98.8, 100), $bar($T0 + 3600, 100, 101, 99.5, 100.5), $bar($T0 + 7200, 100.5, 104, 99, 103), $bar($T0 + 10800, 103, 103.5, 101, 102)];
[$tr] = $collect(new TsLearnProbe([$T0]), $C3);
eq(count($tr), 1, 'criterion 3: one trade');
$t = $tr[0] ?? [];
eq([$t['entry'] ?? null, $t['stop'] ?? null, $t['result'] ?? null, $t['t_signal'] ?? null, $t['t_entry'] ?? null, $t['t_exit'] ?? null],
    [100.0, 98.0, 'horizon', $T0, $T0 + 3600, $T0 + 10800], 'criterion 3: entry at the next open, stop 98, exit at the end of the horizon');
near($t['mae_r'] ?? null, 0.5, 'criterion 3: mae_r = (100 − 99) / 2');
near($t['mfe_r'] ?? null, 2.0, 'criterion 3: mfe_r = (104 − 100) / 2');
near($t['r'] ?? null, 1.0, 'criterion 3: r = (102 − 100) / 2');
eq($t['features'] ?? null, ['k' => 1], 'the features the method attached to the signal (meta().learn)');

// סטופ ויעד: result + win כמו בסיכום
$C4 = [$bar($T0, 99, 100.2, 98.8, 100), $bar($T0 + 3600, 100, 100.5, 97, 98), $bar($T0 + 7200, 98, 99, 97.5, 98.5), $bar($T0 + 10800, 98.5, 112, 98, 111),
    $bar($T0 + 14400, 111, 111.5, 110.5, 111)];
[$tr4, $s4] = $collect(new TsLearnProbe([$T0, $T0 + 7200]), $C4);
eq(array_map(fn($x) => [$x['result'], $x['win'], round($x['r'], 6)], $tr4), [['loss', false, -1.0], ['win', true, 5.0]], 'stop → loss (−1R), target → win (+5R)');
eq([count($tr4), count(array_filter($tr4, fn($x) => $x['win']))], [$s4['n'], $s4['wins']], 'the trades agree with the summary (N, wins)');
near(array_sum(array_column($tr4, 'r')), $s4['sum_r'], 'the trades agree with the summary (sum of R)');

// ── ATR בנר האות: ATR(14) של Wilder, כמו ts_atr ──
$C5 = [];
for ($i = 0; $i < 40; $i++) { $p0 = 100 + sin($i / 3) * 3; $C5[] = $bar($T0 + 3600 * $i, $p0, $p0 + 1 + ($i % 3) * 0.1, $p0 - 1, $p0 + 0.2); }
[$tr5] = $collect(new TsLearnProbe([$T0 + 3600 * 20]), $C5);
$atr = ts_atr(array_column($C5, 'h'), array_column($C5, 'l'), array_column($C5, 'c'), 14);
near($tr5[0]['atr'] ?? null, $atr[20], 'atr = ATR(14) of the signal bar (the same as ts_atr)');

// ── קריטריון 6: בלי הצצה לעתיד — על [0..i] רק העסקאות שנסגרו עד i ──
$sigAt = [];
for ($i = 2; $i < 38; $i += 5) $sigAt[] = $T0 + 3600 * $i;
$probe = new TsLearnProbe($sigAt);
[$all] = $collect($probe, $C5);
$ok = true;
for ($i = 0; $i < count($C5); $i++) {
    [$part] = $collect($probe, array_slice($C5, 0, $i + 1));
    $want = array_values(array_filter($all, fn($x) => $x['t_exit'] <= $C5[$i]['t']));
    if (json_encode($part) !== json_encode($want)) $ok = false;
}
eq([$ok, count($all) > 3], [true, true], 'criterion 6: on [0..i] — exactly the trades that closed by i; an open trade is not written');

// מצטבר: על [0..k] ואחריו (k..N], דרך JSON — אותן עסקאות כמו ריצה אחת
foreach ([7, 13, 22] as $k) {
    [$a, $sa] = $collect($probe, array_slice($C5, 0, $k));
    [$b] = $collect($probe, $C5, json_decode(json_encode($sa), true));
    eq(json_encode(array_merge($a, $b)), json_encode($all), "incremental (split at $k, state through JSON) = the trades of one run");
}
// בלי $onTrade — המצב בדיוק כמו קודם (בלי atr, בלי lo/hi)
$plain = ts_bt_run($probe, [], $C5, $zero);
eq([isset($plain['atr']), array_key_exists('lo', (array)$plain['pos'])], [false, false], 'without $onTrade — the state is exactly as before');

// ── קריטריון 4: תגיות ──
$xmas = gmmktime(14, 0, 0, 12, 25, 2026);
$tag = ts_learn_tags($xmas, 'h1', 'crypto');
eq([$tag['month'], $tag['quarter'], $tag['weekday'], $tag['hour']], [12, 4, 5, 14], 'criterion 4: 25.12.2026 14:00 UTC (Friday) on 1h → month 12, quarter 4, weekday 5, hour 14');
eq([in_array('XMAS', $tag['holidays'], true), in_array('US', $tag['holidays'], true)], [true, true], 'criterion 4: holidays include XMAS and US');
eq(ts_learn_tags($xmas, 'd1', 'crypto')['hour'], null, 'hour only in intraday timeframes');
eq(ts_learn_holiday_regions(gmmktime(10, 0, 0, 7, 2, 2026)), ['US'], '2.7.2026 (Thursday) — a trading day before the observed 4th of July (3.7) → US');
eq(ts_learn_holiday_regions(gmmktime(10, 0, 0, 3, 11, 2026)), [], 'an ordinary day → no holidays');
eq(ts_learn_holiday_regions(gmmktime(10, 0, 0, 1, 2, 2026)), ['CN', 'EU', 'JP', 'UK', 'US'], '2.1 — the day after New Year (XMAS only inside its range)');

// ── קריטריון 5: לוח החגים ──
$H = json_decode((string)file_get_contents(__DIR__ . '/../lib/learn/holidays.json'), true);
$years = array_unique(array_map(fn($h) => (int)substr($h['date'], 0, 4), $H));
sort($years);
eq([count($H) > 1000, array_filter($H, fn($h) => trim((string)($h['source'] ?? '')) === '' || !isset($h['region'], $h['name'], $h['date'])) === []], [true, true], 'criterion 5: every row has a date, region, name and source');
eq([$years[0], end($years), count($years)], [2010, 2030, 21], 'criterion 5: the years 2010–2030');
eq(array_values(array_unique(array_column($H, 'region'))) == ['CN', 'EU', 'JP', 'UK', 'US', 'XMAS', 'IL'] || count(array_diff(['US', 'EU', 'UK', 'JP', 'CN', 'IL', 'XMAS'], array_column($H, 'region'))) === 0, true, 'all seven regions');
$has = fn(string $d, string $r) => (bool)array_filter($H, fn($h) => $h['date'] === $d && $h['region'] === $r);
eq([$has('2026-09-12', 'IL'), $has('2026-09-21', 'IL'), $has('2026-04-03', 'US'), $has('2026-07-03', 'US'), $has('2026-02-17', 'CN')], [true, true, true, true, true],
    'sample dates: Rosh Hashana and Yom Kippur 2026, Good Friday, 4th of July (observed on Friday), Spring Festival');

// ── המסד: קריטריונים 2, 7, 8 ──
$ctx = ['source' => 'backtest', 'method_id' => 'learn-probe', 'method_version' => 1, 'params_hash' => 'h1', 'provider' => 'binance',
    'asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'cred_scope' => '', 'market' => 'crypto', 'decimals' => 2, 'now' => $T0];
eq(ts_outcomes_write($ctx, $all), count($all), 'the trades are written');
eq(ts_outcomes_write($ctx, $all), 0, 'criterion 2: the same trades again → 0 new rows');
eq(ts_outcomes_write($ctx, array_slice($all, 0, 2)), 0, 'criterion 2: … also a part of them');
$row = ts_outcomes('learn-probe')[0] ?? [];
eq([$row['entry'] ?? null, $row['market'] ?? null, is_array($row['holidays'] ?? null), $row['features'] ?? null, array_key_exists('user_id', $row)],
    [ts_learn_price($all[0]['entry'], 2), 'crypto', true, ['k' => 1], false], 'a row: prices as decimal strings, tags, features; no user_id');
$before = count(ts_outcomes('learn-probe'));
eq(ts_outcomes_write(['method_version' => 2] + $ctx, $all), count($all), 'criterion 7: a new version → new rows');
eq([count(ts_outcomes('learn-probe', ['version' => 1])), count(ts_outcomes('learn-probe'))], [$before, $before + count($all)], 'criterion 7: the old rows remain (count before and after)');
ts_outcomes_write(['method_id' => 'elliott-w3'] + $ctx, $all);
eq([count(ts_outcomes('fibonacci')), count(array_filter(ts_outcomes('learn-probe'), fn($r) => $r['method_id'] !== 'learn-probe'))], [0, 0],
    'criterion 8: each method separately — fibonacci has no rows of elliott, and the probe only its own');
try { ts_db()->exec("UPDATE method_outcomes SET result = 'nope'"); $bad = false; } catch (PDOException) { $bad = true; }
eq($bad, true, 'the table checks its values (result)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
