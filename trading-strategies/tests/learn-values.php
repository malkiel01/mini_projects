<?php
// למידה, L5 (spec-stage-a 23.9): "איפה למקם" — ערך מועמד אחד בנוסחה קבועה, רווח סמך, בתוך הטווח בלבד ← הצעת S7, גם לפלח.
// php trading-strategies/tests/learn-values.php — מסד זמני; הנרות למדידה — סט קבוע בזיכרון (בלי רשת). המסך — tests/learn-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-learn-values-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
require __DIR__ . '/../lib/tune.php';
require __DIR__ . '/../lib/devtasks.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── קריטריון 1: 10 מנצחות עם MAE (ב-ATR) 0.1 … 1.0 → אחוזון 80 = 0.82 (אינטרפולציה לינארית) → בתוך 0–1 → הצעה ──
$def01 = ['label' => 'סטופ', 'min' => 0, 'max' => 1, 'int' => false];
$vals = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];
eq(round(ts_learn_quantile($vals, 0.8), 10), 0.82, 'criterion 1: the 80th percentile of 0.1…1.0 = 0.82 (linear interpolation)');
// השורות: כניסה 100, סטופ 98 (סיכון 2), ATR 1 → MAE ב-ATR = mae_r × 2
$winRows = array_map(fn($v) => ['win' => 1, 'mae_r' => $v / 2, 'mfe_r' => 1.0, 'entry' => '100', 'stop' => '98', 'atr' => '1', 'features' => null], $vals);
$loss = ['win' => 0, 'mae_r' => 1.0, 'mfe_r' => 0.1, 'entry' => '100', 'stop' => '98', 'atr' => '1', 'features' => null];
eq(ts_learn_stat_values('stop', [...$winRows, $loss, $loss]), $vals, 'the stop statistic — only the winners, MAE converted from R to ATR');
$c1 = ts_learn_candidate('stop', $vals, $def01);
eq([$c1['value'], $c1['n'], $c1['ci']], [0.82, 10, [0.5, 1.0]], 'criterion 1: candidate 0.82, N = 10, a 95% order-statistics interval');
eq(ts_learn_decide($def01, 0.25, $c1), 'propose', 'criterion 1: inside 0–1, the current (0.25) outside the interval → a proposal');

// ── קריטריון 2: המועמד 1.4 → ממצא "לבעלים", אין הצעה, ואין חיתוך ל-1 ──
$c2 = ts_learn_candidate('stop', array_map(fn($v) => $v + 0.6, $vals), $def01);
eq([$c2['value'], ts_learn_decide($def01, 0.25, $c2)], [1.42, 'out_of_range'], 'criterion 2: candidate 1.42 > 1 → out_of_range, and the value is not cut to 1');

// ── קריטריון 3: רווח הסמך כולל את הנוכחי → אין הצעה ──
eq(ts_learn_decide($def01, 0.7, $c1), 'ci_includes_current', 'criterion 3: the current (0.7) inside 0.5–1.0 → no proposal');
eq(ts_learn_decide($def01, 0.82, $c1), 'same', '… and the same value → nothing');
// ערכים מותרים בלבד (box_target): מעגלים לקרוב
eq(ts_learn_candidate('target', [0.55, 0.6, 0.6, 0.62, 0.7], ['min' => 0.5, 'max' => 1], [0.5, 0.618, 1])['value'], 0.618, 'allowed values only → the nearest (0.6 → 0.618)');

// ── קריטריון 8: יחסי פיבונאצ'י וכללי אליוט — אף פעם לא בהצעה (רק [שלנו]) ──
eq(array_keys(ts_learn_params(ts_method('sample-ma-cross'))), ['stop_atr', 'target_atr'], 'the sample method declares stop_atr and target_atr');
eq([ts_learn_params(ts_method('elliott-w3')), array_key_exists('w3_mult', ts_learn_params(ts_method('elliott-w3')))], [[], false], 'criterion 8: Elliott — nothing to learn (w3_mult is "by the book")');
foreach (['fibonacci', 'elliott-w3'] as $mid) {
    $mm = ts_method($mid);
    $bad = array_filter(array_keys(ts_learn_params($mm)), fn($k) => ($mm->meta()['params'][$k]['ours'] ?? null) !== true);
    eq($bad, [], "criterion 8: $mid — only parameters marked [ours]");
}

// ── הסט במסד: sample-ma-cross גרסה 1, backtest, קריפטו × שעה. 60 עסקאות: 40 מנצחות — MAE ב-ATR סביב 1.0 ──
$db = ts_db();
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, entry, stop, result, win, r,
    mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, created_at) VALUES ('backtest', 'sample-ma-cross', 1, 'h', 'binance', 'binance:BTCUSDT', ?, ?, ?, 'long',
    '100', '98', ?, ?, ?, ?, ?, '1', ?, 3, 1, 2, 10, '[]', 0)");
$t = 1_700_000_000;
$put = function (string $tf, string $market, bool $win, float $maeAtr, float $mfeAtr) use ($ins, &$t) {
    $t += 3600;
    $ins->execute([$tf, $t, $t + 1800, $win ? 'win' : 'loss', $win ? 1 : 0, $win ? 1.5 : -1.0, $maeAtr / 2, $mfeAtr / 2, $market]);
};
for ($i = 0; $i < 60; $i++) $put('h1', 'crypto', $i % 3 !== 2, 0.9 + ($i % 5) * 0.05, 3.0 + ($i % 3) * 0.1);
// ── קריטריון 5: הסטטיסטיקה — רק על 70% הראשונים בזמן. 30% האחרונים — MAE ענק, ולא משנים את המועמד ──
$V = ts_learn_values('sample-ma-cross', 1);
$stop = array_values(array_filter($V, fn($v) => $v['param'] === 'stop_atr' && $v['segment'] === ''))[0] ?? null;
eq([$stop['rows'] ?? null, $stop['in_sample'] ?? null, $stop['current'] ?? null, $stop['decision'] ?? null], [60, 42, 2.0, 'propose'], 'the stop: 60 trades, the statistic on the first 42 (70%), current 2 → a proposal');
for ($i = 0; $i < 18; $i++) $db->exec("UPDATE method_outcomes SET mae_r = 50 WHERE id = " . (60 - $i));   // ה-30% האחרונים
$stop5 = array_values(array_filter(ts_learn_values('sample-ma-cross', 1), fn($v) => $v['param'] === 'stop_atr' && $v['segment'] === ''))[0];
eq($stop5['candidate'], $stop['candidate'], 'criterion 5: changing the last 30% does not change the candidate');
eq(array_column(array_filter($V, fn($v) => $v['param'] === 'stop_atr'), 'segment'), ['', 'crypto|h1'], 'segments: all, and market × tf with N ≥ 30');

// ── הצעה: ts_learn_values_run על נרות קבועים (בלי רשת) → הצעת S7 מהלמידה, לכלל ולפלח ──
$C = [];
for ($i = 0; $i < 3000; $i++) { $p0 = 100 + 6 * sin($i / 40) + 2 * sin($i / 7); $C[] = ['t' => 1_690_000_000 + 3600 * $i, 'o' => sprintf('%.2f', $p0), 'h' => sprintf('%.2f', $p0 + 0.8),
    'l' => sprintf('%.2f', $p0 - 0.8), 'c' => sprintf('%.2f', $p0 + 0.3), 'v' => '1']; }
[$props] = ts_learn_values_run(0, 'sample-ma-cross', 1, 'backtest', 'binance:BTCUSDT', 'h1', $t, $C);
$P = ts_tune_list('sample-ma-cross');
$stopProps = array_values(array_filter($P, fn($x) => $x['param'] === 'stop_atr'));
eq([$props >= 2, array_column($stopProps, 'segment'), $stopProps[0]['origin'] ?? null, $stopProps[0]['status'] ?? null], [true, ['crypto|h1', ''], 'learning', 'open'],
    'proposals from learning — for all, and for the crypto × 1h segment');
// ── קריטריון 4: אין חיפוש — בכל הצעה רק שני ערכים, הנוכחי והמועמד ──
eq(array_keys($stopProps[0]['results']), ['split_t', 'current', 'candidate'], 'criterion 4: only two values were run — the current and the candidate');
eq(array_keys($stopProps[0]['results']['current']), ['in', 'out'], '… in the sample and out of it (S7)');
eq([$stopProps[0]['stats']['stat'] ?? null, $stopProps[0]['stats']['n'] ?? null, str_contains($stopProps[0]['note'], 'רווח סמך')], ['stop', 28, true], 'the statistic, N and the interval are in the proposal');
// כל 30 שורות חדשות: ריצה שנייה בלי שורות חדשות → אין הצעה חדשה
eq([ts_learn_values_run(0, 'sample-ma-cross', 1, 'backtest', 'binance:BTCUSDT', 'h1', $t, $C), count(ts_tune_list('sample-ma-cross'))], [[0, 0], count($P)],
    'no new rows → no new proposal');

// ── קריטריון 6: "החל" על הצעה לפלח → קריפטו × שעה משתמש בערך של הפלח, והשאר בכללי. קריטריון 7: רק הראשי, ומעלה גרסה ──
$segProp = array_values(array_filter($stopProps, fn($x) => $x['segment'] === 'crypto|h1'))[0];
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '100', 'name' => 'בעל המערכת', 'email' => 'o@test.local', 'email_verified' => true, 'platform_admin' => false]);
$threw = false;
try { ts_tune_apply(1, $segProp['id']); } catch (TsForbidden) { $threw = true; }
eq($threw, true, 'criterion 7: without the chief editor → apply is refused');
// ── קריטריון 0 (✅37): מועמד מחוץ לטווח → פריט DevLoop, ממצא לבעלים; בלי הצעה ובלי חיתוך ──
// גרסה נפרדת (9) של אותה שיטה: 40 מנצחות עם MAE של ~14 ATR (הטווח של stop_atr — 0.5–10)
for ($i = 0; $i < 40; $i++) { $t += 3600; $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side,
    entry, stop, result, win, r, mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, created_at) VALUES ('backtest', 'sample-ma-cross', 9, 'h', 'finnhub',
    'finnhub:AAPL', 'd1', ?, ?, 'long', '100', '98', 'win', 1, 1.5, ?, 1.5, '1', 'stocks', 3, 1, 2, NULL, '[]', 0)")->execute([$t, $t + 1800, (13.8 + ($i % 4) * 0.1) / 2]); }
$propsBefore = count(ts_tune_list('sample-ma-cross'));
[$p0, $f0] = ts_learn_values_run(0, 'sample-ma-cross', 9, 'backtest', 'finnhub:AAPL', 'd1', $t);
$item = $db->query("SELECT * FROM dev_tasks WHERE area = 'learning' ORDER BY id DESC")->fetch();
eq([$p0, $f0, count(ts_tune_list('sample-ma-cross')) === $propsBefore], [0, 2, true], 'criterion 0: out of the range → 2 DevLoop items (all, and stocks × 1d), no proposal');
eq([$item['kind'], str_contains($item['title'], 'מחוץ לטווח'), str_contains($item['detail'], 'מעבר לטווח 0.5–10'), str_contains($item['detail'], 'אין חיתוך')],
    ['research', true, true, true], '… research; "the data points to … beyond the range 0.5–10", no cutting to the edge');
$val = array_values(array_filter(ts_learn_values('sample-ma-cross', 9), fn($v) => $v['param'] === 'stop_atr' && $v['segment'] === ''))[0];
eq([$val['decision'], $val['candidate']['value'] > 10], ['out_of_range', true], '… and the candidate itself stays above 10 (not cut)');
eq(ts_learn_values_run(0, 'sample-ma-cross', 9, 'backtest', 'finnhub:AAPL', 'd1', $t), [0, 0], 'again → nothing new');

// ההחלה עצמה (קריטריונים 6–7, עם עורך ראשי מהשער ועורך משני) — tests/learn-values-api.php

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
