<?php
// למידה, L5 (spec-stage-a 23.9): "איפה למקם" — ערך מועמד אחד בנוסחה קבועה, רווח סמך, בתוך הטווח בלבד ← הצעת S7, גם לפלח.
// ועם תיקוני ביקורת L5: 🟡1 חיתוך אחד בזמן (t_cut), 🟡2 סטופ ויעד מושהים, 🟡3 N ≥ 30 על הערכים בפועל, 🟡5 הצעה של עורך לא נדרסת.
// php trading-strategies/tests/learn-values.php — מסד זמני; נרות למדידה — סט קבוע בזיכרון (בלי רשת). שיטת בדיקה: fixtures/methods/learning.php.
// ההחלה ו"↺ בטל" (קריטריונים 6–7, 🟡4) — tests/learn-values-api.php; המסך — tests/learn-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-learn-values-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
define('TS_DEV', true);
putenv('TS_TEST_METHODS=' . __DIR__ . '/fixtures/methods/learning.php');
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

// ── קריטריון 1: 10 מנצחות עם MAE (ב-ATR) 0.1 … 1.0 → אחוזון 80 = 0.82 (אינטרפולציה לינארית) ──
$def01 = ['label' => 'סטופ', 'min' => 0, 'max' => 1, 'int' => false];
$vals = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];
eq(round(ts_learn_quantile($vals, 0.8), 10), 0.82, 'criterion 1: the 80th percentile of 0.1…1.0 = 0.82 (linear interpolation)');
$winRows = array_map(fn($v) => ['win' => 1, 'mae_r' => $v / 2, 'mfe_r' => 1.0, 'entry' => '100', 'stop' => '98', 'atr' => '1', 'features' => null], $vals);
$loss = ['win' => 0, 'mae_r' => 1.0, 'mfe_r' => 0.1, 'entry' => '100', 'stop' => '98', 'atr' => '1', 'features' => null];
eq(ts_learn_stat_values('stop', [...$winRows, $loss, $loss]), $vals, 'the stop statistic — only the winners, MAE converted from R to ATR');
$c1 = ts_learn_candidate('stop', $vals, $def01, null, 1);
eq([$c1['value'], $c1['n'], $c1['ci']], [0.82, 10, [0.5, 1.0]], 'criterion 1 (the formula itself): candidate 0.82, N = 10, a 95% order-statistics interval');
eq(ts_learn_decide($def01, 0.25, $c1), 'propose', 'criterion 1: inside 0–1, the current (0.25) outside the interval → a proposal');
// 🟡3: הסף N ≥ 30 חל על הערכים שהסטטיסטיקה רצה עליהם בפועל — 10 → אין מועמד
eq([ts_learn_candidate('stop', $vals, $def01), ts_learn_candidate('zone', array_fill(0, 30, 0.4), $def01)['n'] ?? null], [null, 30],
    '🟡3: 10 values → no candidate; 30 → a candidate');

// ── קריטריון 2: מחוץ לטווח → ממצא, ואין חיתוך. קריטריון 3: רווח הסמך כולל את הנוכחי → אין הצעה ──
$c2 = ts_learn_candidate('stop', array_map(fn($v) => $v + 0.6, $vals), $def01, null, 1);
eq([$c2['value'], ts_learn_decide($def01, 0.25, $c2)], [1.42, 'out_of_range'], 'criterion 2: candidate 1.42 > 1 → out_of_range, and the value is not cut to 1');
eq([ts_learn_decide($def01, 0.7, $c1), ts_learn_decide($def01, 0.82, $c1)], ['ci_includes_current', 'same'], 'criterion 3: the current inside the interval → no proposal; the same value → nothing');
eq(ts_learn_candidate('target', [0.55, 0.6, 0.6, 0.62, 0.7], ['min' => 0.5, 'max' => 1], [0.5, 0.618, 1], 1)['value'], 0.618, 'allowed values only → the nearest (0.6 → 0.618)');

// ── קריטריון 8: רק [שלנו] ──
eq(array_keys(ts_learn_params(ts_method('test-learn'))), ['zone_atr', 'stop_atr'], 'criterion 8: book_ratio (ours: false) is never learned');
eq(ts_learn_params(ts_method('elliott-w3')), [], 'criterion 8: Elliott — nothing to learn (w3_mult is "by the book")');
foreach (['fibonacci', 'elliott-w3', 'sample-ma-cross'] as $mid) {
    $mm = ts_method($mid);
    eq(array_filter(array_keys(ts_learn_params($mm)), fn($k) => ($mm->meta()['params'][$k]['ours'] ?? null) !== true && empty($mm->meta()['sample'])), [], "criterion 8: $mid — only [ours]");
}

// ── הסט במסד: test-learn גרסה 1, קריפטו × שעה. 120 עסקאות: 2/3 מנצחות. רוחב האזור (features.zone_atr) במנצחות — סביב 1.0 ──
$db = ts_db();
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, entry, stop, result, win, r,
    mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, features, created_at) VALUES ('backtest', 'test-learn', ?, 'h', 'binance', 'binance:BTCUSDT', 'h1', ?, ?, 'long',
    '100', '98', ?, ?, ?, 0.4, 1.5, '1', 'crypto', 3, 1, 2, 10, '[]', ?, 0)");
$C = [];
for ($i = 0; $i < 3000; $i++) { $p0 = 100 + 6 * sin($i / 40) + 2 * sin($i / 7); $C[] = ['t' => 1_690_000_000 + 3600 * $i, 'o' => sprintf('%.2f', $p0), 'h' => sprintf('%.2f', $p0 + 0.8),
    'l' => sprintf('%.2f', $p0 - 0.8), 'c' => sprintf('%.2f', $p0 + 0.3), 'v' => '1']; }
$put = function (int $version, int $i, float $zone) use ($ins) {
    $t = 1_690_000_000 + 3600 * 20 * ($i + 1);   // בתוך טווח הנרות
    $w = $i % 3 !== 2;
    $ins->execute([$version, $t, $t + 1800, $w ? 'win' : 'loss', $w ? 1 : 0, $w ? 1.5 : -1.0, json_encode(['zone_atr' => $zone])]);
};
for ($i = 0; $i < 120; $i++) $put(1, $i, 0.95 + ($i % 5) * 0.025);
$V = ts_learn_values('test-learn', 1);
$z = array_values(array_filter($V, fn($v) => $v['param'] === 'zone_atr' && $v['segment'] === ''))[0] ?? null;
$s = array_values(array_filter($V, fn($v) => $v['param'] === 'stop_atr' && $v['segment'] === ''))[0] ?? null;
eq([$z['rows'] ?? null, $z['in_sample'] ?? null, $z['current'] ?? null, ($z['candidate']['n'] ?? 0) >= 30], [120, 84, 0.5, true],
    'zone_atr: 120 trades, the statistic on the first 84 (before t_cut), N ≥ 30 winners — shown');
eq([$z['decision'] ?? null, $s['decision'] ?? null], ['paused', 'paused'], '🟡2 / 2.37: every statistic is paused — zone width too (only shown)');
eq(array_values(array_unique(array_column(array_filter(ts_learn_values('sample-ma-cross', 1), fn($v) => true), 'decision'))), [], 'the sample method without rows — nothing');

// ── 🟡1 + קריטריון 5: חיתוך אחד בזמן. המועמד רק מעסקאות שיצאו לפני t_cut; 30% האחרונים לא משנים אותו ──
$tCut = $z['t_cut'];
eq((int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE method_id = 'test-learn' AND t_exit < $tCut")->fetchColumn(), 84, '🟡1: t_cut — exactly 84 trades exit before it');
$db->exec("UPDATE method_outcomes SET features = '{\"zone_atr\": 1.9}' WHERE method_id = 'test-learn' AND t_exit >= $tCut");
$z5 = array_values(array_filter(ts_learn_values('test-learn', 1), fn($v) => $v['param'] === 'zone_atr' && $v['segment'] === ''))[0];
eq($z5['candidate'], $z['candidate'], 'criterion 5: changing the trades after t_cut does not change the candidate');

// ── 2.37: אף סטטיסטיקה לא יוצרת הצעה או ממצא — גם כשהנתונים "מצביעים" בתוך הטווח, וגם מחוצה לו ──
$editor = $db->prepare("INSERT INTO method_proposals (method_id, method_version, param, current_value, candidate_value, asset, tf, split_t, results, note, created_by, created_at, segment, origin)
    VALUES ('test-learn', 1, 'zone_atr', '0.5', '0.7', 'binance:BTCUSDT', 'h1', 0, '{}', 'עורך', 5, 0, 'crypto|h1', 'admin')");
$editor->execute();
$edId = (int)$db->lastInsertId();
for ($i = 0; $i < 120; $i++) $put(9, $i, 2.9 + ($i % 4) * 0.05);   // גרסה 9: רוחב ~3 ATR — מחוץ לטווח 0.1–2
$items = fn() => (int)$db->query("SELECT COUNT(*) FROM dev_tasks WHERE area = 'learning'")->fetchColumn();
$props = fn() => (int)$db->query("SELECT COUNT(*) FROM method_proposals WHERE origin = 'learning'")->fetchColumn();
eq([ts_learn_values_run(0, 'test-learn', 1, 'backtest', 'binance:BTCUSDT', 'h1', 1_700_000_000, $C), ts_learn_values_run(0, 'test-learn', 9, 'backtest', 'binance:BTCUSDT', 'h1', 1_700_000_000, $C, true),
    $props(), $items()], [[0, 0], [0, 0], 0, 0], '2.37: no proposal and no DevLoop finding from any statistic (in range or out of it, with candles and with fetch)');
$val = array_values(array_filter(ts_learn_values('test-learn', 9), fn($v) => $v['param'] === 'zone_atr' && $v['segment'] === ''))[0];
eq([$val['decision'], $val['candidate']['value'] > 2], ['paused', true], '… the candidate is still shown (above 2, not cut) — paused');

// ── התשתית (נשארת ל-L5b): ההערכה עם t_cut, השמירה, ו"עורך לא נדרס" — ישירות ──
$m = ts_method('test-learn');
$ev = ts_tune_learned_eval($m, 'zone_atr', 'crypto|h1', 1.0, ['stat' => 'zone', 'raw' => 1.0, 'ci' => [0.9, 1.1], 'n' => 56], $tCut, 'binance:BTCUSDT', 'h1', $C, 1_700_000_000);
eq(array_keys($ev['results'] ?? []), ['split_t', 'current', 'candidate'], 'criterion 4: only two values are run — the current and the candidate');
eq([$ev['split_t'], $ev['results']['current']['in']['to_t'] < $tCut, $ev['results']['current']['out']['from_t'] >= $tCut, $ev['current'], $ev['candidate']],
    [$tCut, true, true, 0.5, 1.0], '🟡1: in the sample — candles before t_cut; out of it — from t_cut');
eq(ts_tune_learned_eval($m, 'zone_atr', '', 0.5, ['stat' => 'zone', 'raw' => 0.5, 'ci' => [0.4, 0.6], 'n' => 56], $tCut, 'binance:BTCUSDT', 'h1', $C, 1), null, 'the candidate = the current → nothing');
$first = ts_tune_learned_save($ev);
$second = ts_tune_learned_save($ev);
eq([ts_tune_get($first)['status'], ts_tune_get($second)['status'], ts_tune_get($second)['origin'], ts_tune_get($second)['segment']], ['stale', 'open', 'learning', 'crypto|h1'],
    'at most one open proposal from learning per parameter and segment — the previous one → stale');
eq(ts_tune_get($edId)['status'], 'open', '🟡5: an open proposal of an editor (same parameter and segment) is not made stale');

// בלי הנרות ובלי $fetch — לא מושכים, והסמן לא זז עד שתהיה מדידה (L5b)
for ($i = 0; $i < 120; $i++) $put(3, $i, 0.95 + ($i % 5) * 0.025);
eq(ts_learn_values_run(0, 'test-learn', 3, 'backtest', 'binance:BTCUSDT', 'h1', 1_700_000_000), [0, 0], 'without candles and without fetch → nothing');

// ההחלה עצמה (קריטריונים 6–7) ו"↺ בטל" (🟡4) — tests/learn-values-api.php

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
