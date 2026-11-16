<?php
// למידה, L5b (spec-stage-a 23.9, 23.9א, 2.37): "איפה למקם" — השוואת שכנים. הנוכחי וצעד אחד לכל כיוון, כל אחד בהרצה מלאה על הנרות
// שלפני t_cut, לפי תוחלת R עם סף 0.05R; הצעה רק אם הנבחר טוב יותר גם מחוץ לדגימה. רץ ב-cron בלבד (ts_learn_cron), בתקציב 6.2(ה).
// קריטריוני L5 0–6, 8 (ההצעה לפלח), 10, ו-🟡5 (הצעה של עורך לא נדרסת). ההחלה (6, 8, 9) ו-7 (ההיסטוגרמות במסך) — learn-values-api.php,
// learn-ui.mjs. php trading-strategies/tests/learn-values.php — מסד זמני; הנרות — שני הסטים הקבועים (tests/fixtures/learn), בלי רשת.
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
require __DIR__ . '/fixtures/learn/sets.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
$vals = fn(array $c) => array_column($c, 'value');

// ── קריטריון 1: שלושה מועמדים בלבד, ובקצה — שניים. אין מועמד מחוץ לטווח ──
$fib = ['step' => 0.25, 'values' => null, 'def' => ['min' => 0, 'max' => 1, 'int' => false]];
eq($vals(ts_learn_neighbors($fib, 0.25)), [0.0, 0.25, 0.5], 'criterion 1: stop_atr = 0.25 (0–1, step 0.25) → 0, 0.25 and 0.5');
eq($vals(ts_learn_neighbors($fib, 1.0)), [0.75, 1.0], 'criterion 1: stop_atr = 1 → 0.75 and 1 only (nothing above the range)');
eq(array_column(ts_learn_neighbors($fib, 0.25), 'edge'), [true, false, false], '… 0 is the range edge; 0.5 is not');
eq($vals(ts_learn_neighbors(['step' => 0.1, 'values' => null, 'def' => ['min' => 0.1, 'max' => 2, 'int' => false]], 0.3)), [0.2, 0.3, 0.4],
    'zone_atr, step 0.1 — no floating-point noise (0.3 − 0.1 = 0.2)');
$box = ['step' => null, 'values' => [0.5, 0.618, 1.0], 'def' => ['min' => 0.5, 'max' => 1, 'int' => false]];
eq([$vals(ts_learn_neighbors($box, 1.0)), $vals(ts_learn_neighbors($box, 0.618))], [[0.618, 1.0], [0.5, 0.618, 1.0]], 'box_target — the next allowed value');

// ── קריטריון 10: יחסי פיבונאצ'י וכללי אליוט — אף פעם; w3_mult — רק ערכי הספר ──
$ew = ts_learn_params(ts_method('elliott-w3'));
eq([array_keys($ew), $ew['w3_mult']['values'], $ew['w3_mult']['step']], [['w3_mult'], [1.0, 1.618, 2.618], null], 'criterion 10: Elliott — w3_mult, only the book values (17.1.4)');
eq([$vals(ts_learn_neighbors($ew['w3_mult'], 1.618)), $vals(ts_learn_neighbors($ew['w3_mult'], 1.0))], [[1.0, 1.618, 2.618], [1.0, 1.618]],
    'criterion 10: from 1.618 — 1.0 and 2.618; from 1.0 — 1.618 only (never 1.25 or 2)');
eq(array_keys(ts_learn_params(ts_method('test-learn'))), ['zone_atr', 'stop_atr'], 'criterion 10: a "book" parameter declared with a step (book_ratio) — never learned');
foreach (['fibonacci', 'elliott-w3', 'sample-ma-cross'] as $mid) {
    $mm = ts_method($mid);
    eq(array_keys(array_filter(ts_learn_params($mm), fn($c, $k) => ($mm->meta()['params'][$k]['ours'] ?? null) !== true && empty($mm->meta()['sample']) && $c['values'] === null,
        ARRAY_FILTER_USE_BOTH)), [], "criterion 10: $mid — a book parameter only with a closed list of values");
}
eq(array_map(fn($c) => $c['step'], ts_learn_params(ts_method('sample-ma-cross'))), ['stop_atr' => 0.5, 'target_atr' => 0.5], 'the sample method — stop and target, step 0.5');

// ── קריטריון 3: הסף — שכן טוב מהנוכחי בפחות מ-0.05R → אין הצעה ──
$c3 = fn(?float $lo, ?float $cur, ?float $hi) => [['value' => 1, 'current' => false, 'edge' => false, 'in' => $lo === null ? null : ['n' => 40, 'expectancy_r' => $lo]],
    ['value' => 2, 'current' => true, 'edge' => false, 'in' => $cur === null ? null : ['n' => 40, 'expectancy_r' => $cur]],
    ['value' => 3, 'current' => false, 'edge' => false, 'in' => $hi === null ? null : ['n' => 40, 'expectancy_r' => $hi]]];
eq([ts_learn_choose($c3(0.1, 0.10, 0.14)), ts_learn_choose($c3(0.1, 0.10, 0.15)), ts_learn_choose($c3(0.3, 0.10, 0.2)), ts_learn_choose($c3(0.12, 0.10, null))],
    [null, 2, 0, null], 'criterion 3: +0.04R → none; +0.05R → chosen; the better of the two; +0.02R → none');
eq(ts_learn_choose($c3(0.5, null, 0.5)), null, 'the current without trades in the sample → no choice');

// ── קריטריון 5: חיתוך אחד בזמן — נכס אחד ב-2024 בלבד, ואחד ב-2025 בלבד ──
$t24 = array_map(fn($i) => gmmktime(0, 0, 0, 1, 1, 2024) + $i * 4 * 86400, range(0, 69));
$t25 = array_map(fn($i) => gmmktime(0, 0, 0, 1, 1, 2025) + $i * 5 * 86400, range(0, 29));
$cut = ts_learn_cut([...$t25, ...$t24]);
eq([count(array_filter($t24, fn($t) => $t < $cut)), count(array_filter($t25, fn($t) => $t < $cut)), $cut > max($t24), $cut <= min($t25)], [70, 0, true, true],
    'criterion 5: one t_cut for both assets (70th percentile of t_signal) — every 2024 trade inside the sample, every 2025 trade out of it');
eq(ts_learn_cut([...$t24, ...$t25]), $cut, '… the same cut whatever the order of the rows (no trade in both parts)');

// ── הסט במסד: test-neighbors, גרסה 1. 100 עסקאות, t_signal כל 10 שעות לאורך 50 מחזורים (1,000 נרות) ──
$db = ts_db();
devloop_migrate();
$T0 = 1_699_992_000;   // מתחלק ב-72000 — האות בנר 0 של כל מחזור
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, cred_scope, t_signal, t_exit, side, entry, stop, result, win, r,
    mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, created_at) VALUES ('backtest', 'test-neighbors', ?, 'h', 'binance', ?, 'h1', ?, ?, ?, 'long',
    '100', '95', 'win', 1, 1.5, 0.3, 2.2, '5', ?, 3, 1, 2, 10, '[]', 0)");
$rows = function (int $count, int $version = 1, string $asset = 'binance:BTCUSDT', string $market = 'crypto', string $scope = '', int $from = 0) use ($ins, $T0) {
    for ($i = $from; $i < $from + $count; $i++) $ins->execute([$version, $asset, $scope, $T0 + $i * 36000, $T0 + $i * 36000 + 7200, $market]);
};
$reset = function () use ($db) {
    foreach (['method_outcomes', 'learning_values', 'method_proposals', 'learning_findings', 'methods'] as $t) $db->exec("DELETE FROM $t");
    ts_method_forget();
};
$setMethod = function (float $stop, int $tune = 0) use ($db) {
    $db->prepare("INSERT OR REPLACE INTO methods (id, enabled, markets, timeframes, params, note, tune, seg_params) VALUES ('test-neighbors', 1, '[\"crypto\",\"forex\"]', '[\"h1\"]', ?, '', ?, '{}')")
        ->execute([json_encode(['stop_atr' => $stop]), $tune]);
    ts_method_forget();
};
$used = [];
$cron = function ($set, ?array $budget = null, ?callable $load = null) use (&$used, $T0) {
    $load ??= fn(string $a, string $tf) => ts_learn_fx_set($set, $T0, 50);
    return ts_learn_cron(1_800_000_000, $used, $budget, microtime(true), $load);
};
$props = fn(string $status = 'open') => $db->query("SELECT segment, param, current_value, candidate_value, created_by, origin FROM method_proposals
    WHERE origin = 'learning' AND status = '$status' ORDER BY segment, id")->fetchAll(PDO::FETCH_NUM);
$lv = fn(string $seg = '') => $db->query("SELECT result, n_at, detail FROM learning_values WHERE method_id = 'test-neighbors' AND segment = '$seg' AND param = 'stop_atr'")->fetch();
$items = fn() => (int)$db->query("SELECT COUNT(*) FROM dev_tasks WHERE area = 'learning'")->fetchColumn();

// ── מתי: N ≥ 30 בכל אחד משני החלקים. 50 שורות → 15 מחוץ לדגימה → אין השוואה ──
$rows(50);
$V = ts_learn_values('test-neighbors', 1);
eq(array_map(fn($v) => [$v['segment'], $v['n_in'], $v['n_out'], $v['status']], $V), [['', 35, 15, 'not_enough'], ['crypto|h1', 35, 15, 'not_enough']],
    'N ≥ 30 in each part: 35 / 15 → not_enough (both the general and crypto × 1h)');
$s = $cron('widen');
eq([$s['evaluated'], $s['runs_in'], $props()], [0, 0, []], '… the cron does not compare');

// ── קריטריון 2 (הרחבה): הסטופ הנוכחי (1.0) נפגע ברעש, והמחיר ממשיך ליעד → ההצעה: להרחיב ל-1.25 ──
$rows(50, 1, 'binance:BTCUSDT', 'crypto', '', 50);
$V = ts_learn_values('test-neighbors', 1);
eq([$V[0]['rows'], $V[0]['n_in'], $V[0]['n_out'], $V[0]['status'], $V[0]['neighbors'], $V[0]['pairs']],
    [100, 70, 30, 'due', [0.75, 1.0, 1.25], [['asset' => 'binance:BTCUSDT', 'tf' => 'h1']]], '100 rows: 70 / 30 → due; neighbors 0.75, 1, 1.25');
$s = $cron('widen');
eq([$s['evaluated'], $s['proposals'], $s['runs_in'], $s['runs_out'], $s['deferred'], $s['errors']], [2, 2, 6, 4, 0, 0],
    'criterion 1: the general and crypto × 1h — 3 runs inside the sample and 2 outside, each (the run log)');
eq($s['log'], ['test-neighbors stop_atr: propose · בתוך 3, מחוץ 2', 'test-neighbors stop_atr@crypto|h1: propose · בתוך 3, מחוץ 2'], '… and in the run log line by line');
eq($props(), [['', 'stop_atr', '1', '1.25', 0, 'learning'], ['crypto|h1', 'stop_atr', '1', '1.25', 0, 'learning']],
    'criterion 2: a set where a wide stop is better → the proposal is to WIDEN (1 → 1.25), by the system');
$d = json_decode($lv()['detail'], true);
eq([$lv()['result'], (int)$lv()['n_at'], $d['n_in'], $d['n_out'], $d['chosen'], array_column($d['candidates'], 'value'), $d['candidates'][0]['out']],
    ['propose', 100, 70, 30, 2, [0.75, 1, 1.25], null], 'learning_values: the cursor, the detail of each neighbor (the one not chosen — not run out of the sample)');
eq([$d['candidates'][1]['in']['expectancy_r'] < 0, $d['candidates'][2]['in']['expectancy_r'] > $d['candidates'][1]['in']['expectancy_r'] + 0.05,
    $d['candidates'][2]['out']['expectancy_r'] > $d['candidates'][1]['out']['expectancy_r']], [true, true, true], '… 1.25 better inside the sample by ≥ 0.05R, and outside it too');
$p = ts_tune_get((int)$db->query("SELECT id FROM method_proposals WHERE segment = '' AND status = 'open'")->fetchColumn());
eq([array_keys($p['results']), $p['results']['split_t'], $p['results']['current']['in']['to_t'] < $p['split_t'], $p['results']['candidate']['out']['from_t'] >= $p['split_t'],
    $p['stats']['kind'], $p['stats']['runs_in'], $p['stats']['runs_out']], [['split_t', 'current', 'candidate'], $d['t_cut'], true, true, 'neighbors', 3, 2],
    'the proposal (S7): the current and the chosen — inside (candles before t_cut) and outside (from t_cut)');

// כל 30 שורות חדשות — לא לפני
$s = $cron('widen');
eq([$s['evaluated'], ts_learn_values('test-neighbors', 1)[0]['status']], [0, 'waiting'], 'right after — nothing due (waiting for 30 new rows)');

// ── קריטריון 2 (צמצום): סט שבו סטופ צר טוב יותר → ההצעה: לצמצם (1 → 0.75). והקודמת → stale ──
$rows(30, 1, 'binance:BTCUSDT', 'crypto', '', 100);
$s = $cron('narrow');
eq([$s['evaluated'], $s['proposals'], $props()], [2, 2, [['', 'stop_atr', '1', '0.75', 0, 'learning'], ['crypto|h1', 'stop_atr', '1', '0.75', 0, 'learning']]],
    'criterion 2: a set where a narrow stop is better → the proposal is to NARROW (1 → 0.75); no built-in direction');
eq(count($props('stale')), 2, 'at most one open proposal per parameter and segment — the previous one → stale');

// ── קריטריון 4: הנבחר טוב בתוך הדגימה וגרוע מחוצה לה → אין הצעה ──
$reset();
$rows(100);
$tc = ts_learn_values('test-neighbors', 1)[0]['t_cut'];
$s = $cron(fn(int $k) => $T0 + $k * 72000 < $tc ? 'widen' : 'narrow');
eq([$s['evaluated'], $s['proposals'], $s['runs_in'], $s['runs_out'], $lv()['result'], $props()], [2, 0, 6, 4, 'out_worse', []],
    'criterion 4: widen before t_cut, narrow after it → 1.25 chosen inside, worse outside → no proposal (3 + 2 runs)');

// ── קריטריון 0: קצה הטווח שנבחר, וגם טוב יותר מחוץ לדגימה → הצעה + פריט DevLoop (✅37) ──
$reset();
$setMethod(0.75);
$rows(100);
eq(ts_learn_values('test-neighbors', 1)[0]['neighbors'], [0.5, 0.75, 1.0], 'from 0.75 — 0.5 (the range edge), 0.75, 1');
$before = $items();
$s = $cron('narrow');
eq([$s['proposals'], $s['edges'], $lv()['result'], $props()[0]], [2, 2, 'edge', ['', 'stop_atr', '0.75', '0.5', 0, 'learning']], 'criterion 0: the edge (0.5) chosen and better outside → a proposal');
eq($items() - $before, 2, '… and a DevLoop item for each (the general and crypto × 1h)');
$it = $db->query("SELECT title, detail FROM dev_tasks WHERE area = 'learning' ORDER BY id LIMIT 1")->fetch();
eq([str_contains($it['title'], 'קצה הטווח'), str_contains($it['detail'], 'הנתונים מצביעים אל קצה הטווח — אולי מעבר לו'), str_contains($it['detail'], '0.75 → 0.5')],
    [true, true, true], '… "the data point to the range edge — maybe beyond it"');
$rows(30, 1, 'binance:BTCUSDT', 'crypto', '', 100);
$s = $cron('narrow');
eq([$s['proposals'], $items() - $before], [2, 2], 'the same edge again (30 new rows) — a new proposal, no second DevLoop item');

// ── קריטריון 1 (בקצה): הנוכחי הוא הקצה → שתי הרצות בתוך הדגימה. הנוכחי הכי טוב → אין הרצות מחוץ לה ──
$reset();
$setMethod(0.5);
$rows(100);
$s = $cron('narrow');
$d = json_decode($lv()['detail'], true);
eq([$s['runs_in'], $s['runs_out'], $lv()['result'], array_column($d['candidates'], 'value'), $props()], [4, 0, 'current_best', [0.5, 0.75], []],
    'criterion 1: at the edge — 2 runs inside each; the current is best → no run outside, no proposal');

// ── קריטריון 6: צעד אחד. אחרי "החל" של 1 → 0.75, ההצעה הבאה רק 0.5, 0.75 או 1 ──
$reset();
$rows(100);
$cron('narrow');
$pid = (int)$db->query("SELECT id FROM method_proposals WHERE segment = '' AND status = 'open'")->fetchColumn();
$setMethod(0.75, 1);   // כמו "החל" (ההחלה עצמה, רק לעורך הראשי — learn-values-api.php): הערך החדש, וגרסה + 1
eq([ts_method('test-neighbors')->meta()['version'], ts_tune_get($pid)['status']], [2, 'stale'], 'after the apply — version 2 (the open proposal of version 1 is stale)');
$rows(100, 2);
$V2 = ts_learn_values('test-neighbors', 2);
$s = $cron('narrow');
eq([$V2[0]['neighbors'], $props()[0][2], in_array((float)$props()[0][3], [0.5, 0.75, 1.0], true)], [[0.5, 0.75, 1.0], '0.75', true],
    'criterion 6: from 0.75 — only 0.5, 0.75 or 1 (one step, no jump to the edge of the range)');

// ── 🟡5: הצעה פתוחה של עורך (אותו פרמטר ופלח) לא נדרסת ──
$reset();
$db->exec("INSERT INTO method_proposals (method_id, method_version, param, current_value, candidate_value, asset, tf, split_t, results, note, created_by, created_at, segment, origin)
    VALUES ('test-neighbors', 1, 'stop_atr', '1', '1.5', 'binance:BTCUSDT', 'h1', 0, '{}', 'עורך', 5, 0, '', 'admin')");
$edId = (int)$db->lastInsertId();
$rows(100);
$cron('widen');
eq([ts_tune_get($edId)['status'], count($props())], ['open', 2], '🟡5: the editor\'s open proposal stays open next to the one from learning');

// ── הצעה לפלח (קריטריון 8): מט"ח × 1h עם N ≥ 30 בכל חלק → הצעה לפלח; קריפטו — אין מספיק בפלח שלו ──
$reset();
$rows(100, 1, 'twelvedata:EUR/USD', 'forex');
$rows(40, 1, 'binance:BTCUSDT', 'crypto', '', 100);
$V = ts_learn_values('test-neighbors', 1);
eq(array_map(fn($v) => [$v['segment'], $v['status']], $V), [['', 'due'], ['forex|h1', 'due'], ['crypto|h1', 'not_enough']],
    'criterion 8: forex × 1h with N ≥ 30 in each part — due; crypto × 1h (40 rows) — not enough');
$s = $cron('narrow');
eq(array_column($props(), 0), ['', 'forex|h1'], 'criterion 8: a proposal for the general and one for the forex × 1h segment');
$pf = ts_tune_get((int)$db->query("SELECT id FROM method_proposals WHERE segment = 'forex|h1' AND status = 'open'")->fetchColumn());
eq([$pf['asset'], $pf['tf'], $pf['stats']['pairs']], ['twelvedata:EUR/USD', 'h1', 1], '… run on its own pair only');

// ── התקציב (6.2(ה)) והמפתחות: חסר תקציב → נדחה, והסמן לא זז. מפתח אישי — לעולם לא בשם המערכת ──
$reset();
$rows(100);
$used = [];
$s = ts_learn_cron(1_800_000_000, $used, ['binance' => 0], microtime(true));
eq([$s['evaluated'], $s['deferred'], $lv(), $props()], [0, 2, false, []], 'no provider budget left in this run → deferred to the next run; nothing written');
$reset();
$rows(100, 1, 'binance:BTCUSDT', 'crypto', 'u:5');
$V = ts_learn_values('test-neighbors', 1);
eq([$V[0]['pairs'], $V[0]['private_pairs']], [[], 1], 'rows from a personal key — counted, never fetched by the system');
$s = $cron('narrow', null, function () { throw new LogicException('must not load'); });
eq([$s['evaluated'], $s['errors'], $lv()['result'], json_decode($lv()['detail'], true)['private_pairs']], [2, 0, 'no_pairs', 1], '… → "no_pairs", without loading anything');
$reset();
$rows(100);
$s = $cron('narrow', null, function () { throw new TsInvalid('אין חיבור'); });
eq([$s['evaluated'], $lv()['result']], [2, 'no_pairs'], 'no system access to the provider → the pair is not run ("no_pairs")');

// ── ההיסטוגרמות (23.10, קריטריון 7): לדיון בלבד — חתוכות בסטופ, לא מקור להצעה ──
$h = ts_learn_hist([['entry' => '100', 'stop' => '95', 'atr' => '5', 'mae_r' => 0.3, 'mfe_r' => 2.2], ['entry' => '100', 'stop' => '95', 'atr' => '5', 'mae_r' => 1.0, 'mfe_r' => 9],
    ['entry' => null, 'stop' => '95', 'atr' => null, 'mae_r' => null, 'mfe_r' => null]]);
eq([$h['n'], $h['mae'][1], $h['mae'][4], $h['mfe'][8], $h['mfe'][20], count($h['mae'])], [2, 1, 1, 1, 1, 21], 'the MAE / MFE histograms in ATR (0.25 bins, the last "5 and above")');

// בבקשה של משתמש (ts_backtest) — אין השוואה
eq(str_contains((string)file_get_contents(__DIR__ . '/../lib/analysis.php'), 'ts_learn_cron('), false, 'the comparison is never called from the user request (ts_backtest)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
