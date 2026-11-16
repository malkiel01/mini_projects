<?php
// למידה, L4 (spec-stage-a 23.8, 23.8א, ✅37): שינוי דפוס — פלח מול השאר, אחרון מול היסטוריה, p < 0.01 — וממצא חריג → DevLoop,
// בלי כפילות. php trading-strategies/tests/learn-findings.php — מסד זמני, בלי רשת. המסך — tests/learn-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-learn-find-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
require __DIR__ . '/../lib/analysis.php';
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
$near = fn($got, float $want, float $tol, string $what) => eq(abs($got - $want) <= $tol, true, "$what (≈ $want, got $got)");

// ── המבחנים עצמם, מול ערכים ידועים ──
$near(ts_learn_p_normal(1.959964), 0.05, 1e-6, 'normal: |z| = 1.96 → p = 0.05');
$near(ts_learn_p_normal(2.575829), 0.01, 1e-6, 'normal: |z| = 2.576 → p = 0.01');
$near(ts_learn_p_t(2.228139, 10), 0.05, 1e-5, 't: t = 2.228, df = 10 → p = 0.05');
$near(ts_learn_p_t(2.0, 30), 0.0546, 1e-4, 't: t = 2, df = 30 → p = 0.0546');
$near(ts_learn_test_prop(10, 40, 100, 200), 0.00376, 1e-4, 'two proportions: 10/40 vs 100/200');
eq(ts_learn_test_prop(18, 40, 100, 200) > 0.5, true, 'two proportions: 18/40 vs 100/200 — far from significant');
eq(ts_learn_test_prop(40, 40, 200, 200), 1.0, 'two proportions: everyone won on both sides → no difference');
eq(ts_learn_test_welch([30, 30.0, 30.0], [30, 30.0, 30.0]), 1.0, 'Welch: identical constants → p = 1');

// ── הסט: שיטה אחת, גרסה 1, backtest. r = +1 בהצלחה, −1 בהפסד ──
$db = ts_db();
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, result, win, r,
    market, month, quarter, weekday, hour, holidays, created_at) VALUES ('backtest', ?, 1, 'h', 'binance', 'binance:BTCUSDT', 'h1', ?, ?, 'long', ?, ?, ?,
    'crypto', 3, 1, ?, 10, '[]', 0)");
// העסקאות של שיטה נצברות ב-$pend, ו-$flush כותב אותן בסדר זמן מעורבב (זרע קבוע) — כך שאף קבוצה לא יושבת כולה בסוף, ו"30
// האחרונות" (מבחן 2) לא נבדקות בטעות. $late — עסקאות שנוספות אחר כך, עם זמן יציאה **מוקדם** (לא משנות את "האחרונות")
$t = 1_700_000_000; $early = 1_600_000_000;
$pend = [];
$add = function (string $m, int $weekday, int $count, int $wins) use (&$pend) {
    for ($i = 0; $i < $count; $i++) $pend[] = [$m, $weekday, $i < $wins];
};
$put = function (array $x, int $at) use ($ins) {
    [$m, $wd, $w] = $x;
    $ins->execute([$m, $at, $at + 1800, $w ? 'win' : 'loss', $w ? 1 : 0, $w ? 1.0 : -1.0, $wd]);
};
$flush = function (bool $shuffle = true) use (&$pend, &$t, $put) {
    if ($shuffle) { mt_srand(7); shuffle($pend); }
    foreach ($pend as $x) { $t += 3600; $put($x, $t); }
    $pend = [];
};
$late = function (string $m, int $weekday, int $count) use (&$early, $put) {
    for ($i = 0; $i < $count; $i++) { $early += 3600; $put([$m, $weekday, false], $early); }
};
// 200 בשאר — 50 בכל אחד מימים 1–4, חצי הצלחות
$base = function (string $m) use ($add) { foreach ([1, 2, 3, 4] as $d) $add($m, $d, 50, 25); };

// ── קריטריון 1: 40 בימי שישי (10 הצלחות, 25%) מול 200 (50%) → ממצא; 40 (18, 45%) מול 200 → אין ──
$base('m-fri'); $add('m-fri', 5, 40, 10); $flush();
$F = ts_learn_findings('m-fri', 1);
$fri = array_values(array_filter($F, fn($f) => $f['dim'] === 'weekday' && $f['value'] === '5'))[0] ?? null;
eq([count($F), $fri['n'] ?? null, $fri['win_pct'] ?? null, $fri['rest_n'] ?? null, $fri['rest_win_pct'] ?? null, ($fri['p'] ?? 1) < 0.01],
    [1, 40, 25.0, 200, 50.0, true], 'criterion 1: Fridays 25% (N = 40) vs 50% (N = 200) → one finding, p < 0.01');
$base('m-ok'); $add('m-ok', 5, 40, 18); $flush();
eq(ts_learn_findings('m-ok', 1), [], 'criterion 1: 45% vs 50% → no finding');

// ── קריטריון 2: 30 האחרונות 20% מול 50% לפניהן (N = 200) → ממצא ──
$add('m-recent', 1, 200, 100); $flush(); $add('m-recent', 1, 30, 6); $flush(false);
$R = ts_learn_findings('m-recent', 1);
eq([count($R), $R[0]['kind'] ?? null, $R[0]['n'] ?? null, $R[0]['win_pct'] ?? null, $R[0]['rest_n'] ?? null], [1, 'recent', 30, 20.0, 200],
    'criterion 2: the last 30 at 20% vs 50% before (N = 200) → a finding');

// ── קריטריון 3: פלח עם N < 30 → לא נבדק ──
$base('m-small'); $add('m-small', 5, 29, 0); $flush();
eq(array_values(array_filter(ts_learn_findings('m-small', 1), fn($f) => $f['kind'] === 'segment')), [], 'criterion 3: a segment of 29 (even 0%) → not tested');

// ── קריטריון 4: "כלל חדש" (יום בשבוע — אין פרמטר) → לבעלים, ואין הצעה ──
eq([$fri['scope'] ?? null, (int)$db->query('SELECT COUNT(*) FROM method_proposals')->fetchColumn()], ['owner', 0], 'criterion 4: a weekday finding — "for the owner", and no proposal');
eq(ts_learn_segment_text('weekday', '5') . ' · ' . ts_learn_segment_text('market_tf', 'forex|h1'), 'ימי שישי · מט"ח · שעה', 'segments in words');

// ── קריטריון 5 (✅37): ממצא חריג → פריט DevLoop אחד, research / learning ──
$items = fn() => (int)$db->query("SELECT COUNT(*) FROM dev_tasks WHERE area = 'learning'")->fetchColumn();
$comments = fn(string $code) => (int)$db->query("SELECT COUNT(*) FROM dev_comments WHERE code = " . $db->quote($code))->fetchColumn();
$before = [ts_method_settings('sample-ma-cross'), $db->query("SELECT COUNT(*), SUM(r) FROM method_outcomes")->fetch(PDO::FETCH_NUM)];
eq(ts_learn_findings_sync('m-fri', 1, 'backtest', $t), [1, 0], 'criterion 5: a finding → one DevLoop item');
$it = $db->query("SELECT * FROM dev_tasks WHERE area = 'learning'")->fetch();
$code = $it['code'];
eq([$it['kind'], $it['area'], $it['status'], $it['title']], ['research', 'learning', 'open', 'ממצא למידה: m-fri · ימי שישי · פלח שונה מהשאר'],
    'criterion 5: kind research, area learning, the title "ממצא למידה: <method> · <segment> · <what>"');
eq([str_contains($it['detail'], 'גרסה 1'), str_contains($it['detail'], 'N = 40'), str_contains($it['detail'], '25% הצלחה'), str_contains($it['detail'], 'p '),
    str_contains($it['detail'], '#/admin'), str_contains($it['detail'], 'להכרעת הבעלים')], [true, true, true, true, true, true],
    'criterion 5: the details — version, N, the win rate, p, a link to the tab, and "for the owner"');
eq((int)$db->query("SELECT COUNT(*) FROM learning_findings WHERE code = " . $db->quote($code))->fetchColumn(), 1, 'the code of the item is saved (learning_findings)');

// ── קריטריון 6: בלי כפילות, ובלי רעש (ביקורת L4 🟡1) ──
eq([ts_learn_findings_sync('m-fri', 1, 'backtest', $t), $items(), $comments($code)], [[0, 0], 1, 0], 'criterion 6: the same finding, the same numbers → nothing new');
$quiet = [];
for ($i = 0; $i < 5; $i++) { $late('m-fri', 5, 1); $quiet[] = ts_learn_findings_sync('m-fri', 1, 'backtest', $t); }
eq([$quiet, $items(), $comments($code)], [array_fill(0, 5, [0, 0]), 1, 0], '🟡1: 5 runs with one new trade each → 0 comments (the numbers change, but fewer than 30 trades)');
$late('m-fri', 5, 25);   // 30 מאז הדיווח האחרון
eq([ts_learn_findings_sync('m-fri', 1, 'backtest', $t), $items(), $comments($code)], [[0, 1], 1, 1], 'criterion 6: after 30 new trades → 0 new items, one comment in the existing one');
eq(str_contains((string)$db->query("SELECT body FROM dev_comments WHERE code = " . $db->quote($code) . " ORDER BY id DESC")->fetchColumn(), 'אחרי 30 עסקאות חדשות'), true, '… which says after how many trades');
devloop_task_status($code, 'closed', 'user:1');
$late('m-fri', 5, 10);
eq([ts_learn_findings_sync('m-fri', 1, 'backtest', $t), $comments($code), devloop_task_get($code)['status']], [[0, 0], 1, 'closed'],
    'a closed item — the finding again after 10 new trades → nothing (the developers decided)');
$late('m-fri', 5, 20);
eq([ts_learn_findings_sync('m-fri', 1, 'backtest', $t), $comments($code), devloop_task_get($code)['status']], [[0, 1], 2, 'open'],
    '… after 30 new trades → a comment, and the item is reopened');

// ממצא שהפסיק להיות מובהק — תגובה אחת, ולא שוב
$lateWin = function (string $m, int $weekday, int $count) use (&$early, $put) { for ($i = 0; $i < $count; $i++) { $early += 3600; $put([$m, $weekday, true], $early); } };
$lateWin('m-fri', 5, 120);
eq([ts_learn_findings('m-fri', 1), ts_learn_findings_sync('m-fri', 1, 'backtest', $t), $comments($code)], [[], [0, 1], 3], 'no longer significant → one comment "כבר לא מובהק"');
eq([ts_learn_findings_sync('m-fri', 1, 'backtest', $t), $comments($code), $items()], [[0, 0], 3, 1], '… and not again');

// ── 🟡2: תקרה — לכל היותר 3 פריטים חדשים בריצה, לפי p הקטן; והשאר — שורה אחת בפריט האחרון ──
foreach ([1 => 0, 2 => 40, 3 => 0, 4 => 40, 6 => 2] as $d => $w) $add('m-many', $d, 40, $w);
$add('m-many', 7, 200, 100); $flush();
$MF = ts_learn_findings('m-many', 1);
$itemsBefore = $items();
eq([count($MF) > 3, ts_learn_findings_sync('m-many', 1, 'backtest', $t)[0], $items() - $itemsBefore], [true, 3, 3], '🟡2: more than 3 findings → exactly 3 new items');
$codes = ts_learn_finding_codes('m-many');
eq(array_keys($codes), array_slice(array_column($MF, 'key'), 0, 3), '… the 3 with the smallest p');
$lastNew = $db->query("SELECT code FROM dev_tasks WHERE area = 'learning' ORDER BY id DESC")->fetchColumn();
eq(str_contains((string)$db->query("SELECT body FROM dev_comments WHERE code = " . $db->quote($lastNew))->fetchColumn(), 'ועוד ' . (count($MF) - 3) . ' ממצאים בלשונית'), true,
    '… and one line in the last of them: "ועוד N ממצאים בלשונית"');
eq(count(ts_learn_findings('m-many', 1)), count($MF), 'the tab still shows all of them');

// ── קריטריון 7: לא משנה לבד ──
eq([ts_method_settings('sample-ma-cross'), (int)$db->query("SELECT COUNT(*) FROM method_proposals")->fetchColumn()], [$before[0], 0],
    'criterion 7: the same parameters, no proposal — the finding changes nothing');

// ── CSV: נגד נוסחאות (ביקורת L3 ⚪) ──
eq([ts_learn_csv_cell('=HYPERLINK("x")'), ts_learn_csv_cell('+1+2'), ts_learn_csv_cell('@SUM(A1)'), ts_learn_csv_cell('-x'), ts_learn_csv_cell("\t=1")],
    ["'=HYPERLINK(\"x\")", "'+1+2", "'@SUM(A1)", "'-x", "'\t=1"], 'CSV: text that starts with = + - @ (or a tab) → an apostrophe first');
eq([ts_learn_csv_cell('-1.5'), ts_learn_csv_cell(-2), ts_learn_csv_cell('binance:BTCUSDT'), ts_learn_csv_cell(null)], ['-1.5', '-2', 'binance:BTCUSDT', ''],
    'CSV: a negative number stays a number; ordinary text as is');
$db->exec("UPDATE method_outcomes SET asset = '=cmd|calc' WHERE id = 1");
$csv = ts_learn_csv('m-fri', null, null, false);
eq([str_contains($csv, "'=cmd|calc"), str_contains($csv, ',=cmd'), str_contains($csv, ',-1,') || str_contains($csv, ',-1.0,') || str_contains($csv, ',-1')], [true, false, true],
    'CSV: the formula is neutralised in the export; R −1 stays a number');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
