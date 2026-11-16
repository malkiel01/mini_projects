<?php
// למידה, L6 (spec-stage-a 23.7): ההסתברות בכרטיס — "ראשונית" (backtest) ו"בפועל מאז" (live), בלי לערבב; ו"החלף לבפועל" — הצעה
// לעורך הראשי כשרווחי הסמך לא חופפים, ב-cron. קריטריונים L6 2–3 ברמת הספרייה; הכרטיס וההחלה דרך ה-API — learn-card-api.php.
// php trading-strategies/tests/learn-card.php — מסד זמני, בלי רשת.
if (PHP_SAPI !== 'cli') exit;
$tmp = sys_get_temp_dir() . '/ts-learn-card-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
require __DIR__ . '/../lib/tune.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
$db = ts_db();
$M = 'sample-ma-cross';
$V = (int)ts_method($M)->meta()['version'];
$ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, cred_scope, t_signal, t_exit, side, result, win, r,
    market, month, quarter, weekday, hour, holidays, trade_id, created_at) VALUES (?, ?, ?, 'h', 'binance', ?, ?, ?, ?, ?, 'long', ?, ?, ?, ?, 1, 1, 1, 0, '[]', ?, 0)");
$t = 1_700_000_000;
/** $count שורות, $wins הראשונות הצלחה. $at — זמן אות קבוע לשורה הראשונה (לחפיפה), אחרת רץ */
$add = function (string $src, int $count, int $wins, string $market = 'crypto', string $tf = 'h1', array $o = []) use ($ins, &$t, $M, $V) {
    for ($i = 0; $i < $count; $i++) {
        $ts = isset($o['t0']) ? $o['t0'] + $i * 3600 : ($t += 3600);
        $w = $i < $wins;
        $ins->execute([$src, $o['method'] ?? $M, $o['version'] ?? $V, $o['asset'] ?? ($market === 'crypto' ? 'binance:BTCUSDT' : 'oanda:EURUSD'), $tf, $o['scope'] ?? '',
            $ts, $ts + 1800, $w ? 'win' : 'loss', $w ? 1 : 0, $w ? 1.0 : -1.0, $market, $src === 'trade' ? 1000 + $ts : 0]);
    }
};

// ── קריטריון 2: 29 → אין שורה שנייה; 30 → "בפועל מאז" ──
$add('backtest', 100, 70);   // "ראשונית" — לא נכנסת לשורה השנייה
$add('trade', 40, 40);       // עסקאות משתמשים — לא live
$add('live', 29, 5);
eq(ts_learn_live_line($M, $V, 'crypto', 'h1'), null, 'criterion 2: 29 live rows → no second line (backtest and trade rows are never counted as live)');
$add('live', 5, 0, 'crypto', 'h1', ['scope' => 'k1']);   // מפתח אישי — לא live של המאגר
eq(ts_learn_live_line($M, $V, 'crypto', 'h1'), null, '… live rows of a private key scope do not count either');
$add('live', 1, 0);
$L = ts_learn_live_line($M, $V, 'crypto', 'h1');
eq([$L['n'], $L['wins'], $L['scope'], $L['segment'], $L['certainty'], $L['switched']], [30, 5, 'segment', 'crypto|h1', ts_certainty(5, 30), false],
    'criterion 2: 30 live rows → "בפועל מאז" X% (N = 30), Wilson like 6.3 — in the chart\'s market × tf');
eq($L['ci'][0] < 5 / 30 && $L['ci'][1] > 5 / 30 && $L['ci'][0] === round(ts_wilson_lower(5, 30), 4), true, 'the confidence interval surrounds the rate; its lower bound is the certainty');

// ── ל2: N < 30 בשוק × מסגרת → השיטה וגרסתה בכל השווקים, "כללי — לא לשוק הזה" ──
$add('live', 10, 5, 'forex', 'h4');
$G = ts_learn_live_line($M, $V, 'forex', 'h4');
eq([$G['n'], $G['scope'], $G['segment']], [40, 'general', ''], 'ל2: forex × h4 has 10 → the method and its version in all markets (N = 40), scope general');
eq(ts_learn_live_line($M, $V, 'crypto', 'h1')['scope'], 'segment', '… while crypto × h1 (30) stays on its own segment');
eq([ts_learn_live_line($M, $V + 1, 'crypto', 'h1'), ts_learn_live_line('elliott-w3', (int)ts_method('elliott-w3')->meta()['version'], 'crypto', 'h1')], [null, null],
    'a new version starts from nothing; another method sees none of these rows (23.1)');

// ── לא משווים סט לעצמו: עסקה שנכתבה גם כ-live וגם כ-backtest — לא בצד של הבדיקה לאחור ──
$both = 1_600_000_000;
$add('backtest', 3, 3, 'crypto', 'h1', ['t0' => $both]);
$add('live', 3, 3, 'crypto', 'h1', ['t0' => $both]);
$B = ts_learn_backtest_apart($M, $V, 'crypto|h1');
eq([$B['n'], $B['wins']], [100, 70], 'the backtest side excludes trades that are also live (same key, other source) — 103 rows, 3 shared → 100');
eq(ts_learn_live_line($M, $V, 'crypto', 'h1')['n'], 33, 'the live side keeps them (live only — never summed with backtest)');

// ── קריטריון 3: לא מתחלף לבד; רווחי סמך שלא חופפים → הצעה "החלף לבפועל" לעורך הראשי ──
$before = ts_learn_live_line($M, $V, 'crypto', 'h1');
$S = ts_learn_switch_sync(1_800_000_000);
$open = fn() => $db->query("SELECT segment, status, origin, created_by, param FROM method_proposals WHERE param = '@certainty' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$P = $open();
eq([array_column($P, 'segment'), array_column($P, 'status'), array_column($P, 'origin'), array_map('intval', array_column($P, 'created_by'))],
    [['crypto|h1', ''], ['open', 'open'], ['learning', 'learning'], [0, 0]],
    'criterion 3: backtest 70/100 vs live 8/33 in crypto × h1, and 70/100 vs 18/43 in general — the intervals do not overlap → one open proposal per level, from learning');
eq([$S['proposed'], $S['checked']], [2, 2], '… counted in the cron summary');
$row = array_values(array_filter(ts_tune_list($M), fn($x) => $x['segment'] === 'crypto|h1'))[0];
eq([$row['param'], $row['current'], $row['candidate'], $row['status'], $row['tf'], $row['results']['backtest']['n'], $row['results']['live']['n']],
    ['@certainty', 'backtest', 'live', 'open', 'h1', 100, 33], 'the proposal as the browser sees it: backtest → live, both sides with N');
eq(ts_learn_live_line($M, $V, 'crypto', 'h1'), $before, 'criterion 3: the proposal changes nothing by itself — the card line (and switched: false) is as before');
ts_learn_switch_sync(1_800_000_900);
eq(count($open()), 2, 'a second cron run → no duplicate proposal');

// ── חופפים שוב → ההצעה הפתוחה מסומנת stale ──
$add('live', 60, 55);   // live: 63/93 ≈ 68% בשוק × מסגרת, 73/103 בכללי — חופף ל-70%
$S = ts_learn_switch_sync(1_800_001_800);
eq([array_column($open(), 'status'), $S['stale'], $S['proposed']], [['stale', 'stale'], 2, 0], 'live caught up with the backtest — the intervals overlap → the open proposals turn stale, no new one');

// ── הוחלף → הכרטיס על live, רק ברמה שהוחלפה ──
$db->exec("UPDATE method_proposals SET status = 'applied' WHERE param = '@certainty' AND segment = 'crypto|h1'");
eq(ts_learn_live_line($M, $V, 'crypto', 'h1')['switched'], true, 'after "החל" (status applied) — switched: true for that version and level');
eq(ts_learn_live_line($M, $V, 'forex', 'h4')['switched'], false, '… only for that level: the general line of forex × h4 is not switched');
eq(ts_learn_live_line($M, $V + 1, 'crypto', 'h1'), null, 'a new version — nothing carried over: back to "ראשונית"');
$db->exec("INSERT INTO method_proposals (method_id, method_version, param, current_value, candidate_value, asset, tf, split_t, results, note, status, created_by, created_at, segment, origin)
    VALUES ('$M', $V, '@certainty', '\"backtest\"', '\"live\"', '', 'h1', 0, '{}', '', 'open', 0, 0, 'crypto|h1', 'learning')");
eq(ts_tune_list($M)[0]['status'], 'stale', 'an open proposal for a level that is already switched — stale (no double switch)');
$db->exec("DELETE FROM method_outcomes WHERE source = 'live' AND win = 1 AND t_signal > " . ($t - 60 * 3600));   // שוב לא חופפים
$k = count($open());
ts_learn_switch_sync(1_800_002_700);
eq(array_column(array_slice($open(), $k), 'segment'), [''], 'already switched in crypto × h1 → the cron does not propose it again (only the general level, apart again)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
