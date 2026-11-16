<?php
// למידה עצמית, L2 (spec-stage-a 23.4, קריטריונים L2 1–4): המקורות live ו-trade, והכיבוי (ל3).
// php trading-strategies/tests/learn-live.php — בתהליך, מסד זמני, בלי רשת (הנרות מוזרקים ל-ts_learn_live_cron דרך $load).
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-learn-live-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp*") ?: [] as $f) @unlink($f); });
require __DIR__ . '/../lib/analysis.php';
require_once __DIR__ . '/../lib/store.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── הסט: גל עם רעש קבוע — sample-ma-cross מוצאת בו הצלבות, ועסקאות שמגיעות לסטופ, ליעד ולסוף האופק ──
$H = 3600;
$T0 = 1735689600;   // 2025-01-01 00:00 UTC
$C = [];
mt_srand(7);
$prev = 100.0;
for ($i = 0; $i < 2400; $i++) {
    $mid = 100 + 12 * sin($i / 37) + 4 * sin($i / 11);
    $o = $prev;
    $c = $mid + (mt_rand(-100, 100) / 100);
    $hi = max($o, $c) + mt_rand(0, 80) / 100;
    $lo = min($o, $c) - mt_rand(0, 80) / 100;
    $C[] = ['t' => $T0 + $i * $H, 'o' => sprintf('%.2f', $o), 'h' => sprintf('%.2f', $hi), 'l' => sprintf('%.2f', $lo), 'c' => sprintf('%.2f', $c), 'v' => '1'];
    $prev = $c;
}
$ASSET = 'binance:BTCUSDT';
$M = ts_method('sample-ma-cross');
$meta = $M->meta();
$market = ts_asset_market($ASSET);
eq($market, 'crypto', 'the asset is crypto');
$cost = ts_cost_for($market);
$P = ts_learn_params_for($M, $ASSET, 'h1', ts_tick_decimals($C));
$HASH = ts_bt_hash($P, $cost);

// הבדיקה לאחור: עד נר K (כולל). המצב נשמר ב-backtests, כמו ש-ts_backtest שומר
$K = 1500;
$bt = ts_bt_run($M, $P, ts_bt_floats(array_slice($C, 0, $K + 1)), $cost, null, null, fn() => null);
$TO = $C[$K]['t'];
eq((int)$bt['last_t'], $TO, 'the backtest ends at candle K');
$db = ts_db();
$putBt = function (array $s, int $to, string $scope = '', ?string $hash = null, ?int $version = null, int $at = 1) use ($db, $meta, $ASSET, $HASH) {
    $res = ts_bt_result($s);
    $db->prepare("INSERT OR REPLACE INTO backtests (method_id, method_version, provider, asset, tf, params_hash, cred_scope, n, wins, expectancy_r, max_dd, from_t, to_t, state, computed_at)
                  VALUES (?, ?, 'binance', ?, 'h1', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$meta['id'], $version ?? $meta['version'], $ASSET, $hash ?? $HASH, $scope, $res['n'], $res['wins'], $res['expectancy_r'], $res['max_dd'],
            $res['from_t'], $to, json_encode($s), $at]);
};
$putBt($bt, $TO);

// המצופה: ריצה אחת של המנוע על כל הנרות. live = העסקאות שהאות שלהן אחרי הבדיקה (t_signal > to_t) ושהסתיימו
$all = [];
ts_bt_run($M, $P, ts_bt_floats($C), $cost, null, null, function (array $t) use (&$all) { $all[] = $t; });
$want = array_values(array_filter($all, fn($t) => $t['t_signal'] > $TO));
$wantR = array_map(fn($t) => [(int)$t['t_signal'], $t['result'], $t['r'] === null ? null : round($t['r'], 9)], $want);
eq([count($want) >= 5, count(array_unique(array_column($want, 'result'))) === 3], [true, true], 'the set has trades after the backtest, all three results (' . count($want) . ')');
eq(in_array('loss', array_column($want, 'result'), true), true, 'and at least one of them reaches the stop');

// $load: הנרות הסגורים מ-from עד now — כמו ts_bt_history
$calls = 0;
$load = function (int $now) use ($C, $H, &$calls) {
    return function (string $asset, string $tf, int $from) use ($C, $H, $now, &$calls): array {
        $calls++;
        return array_values(array_filter($C, fn($c) => $c['t'] >= $from && $c['t'] + $H <= $now));
    };
};
$liveRows = fn() => array_map(fn($r) => [(int)$r['t_signal'], $r['result'], $r['r'] === null ? null : round((float)$r['r'], 9)],
    $db->query("SELECT t_signal, result, r FROM method_outcomes WHERE source = 'live' AND method_id = 'sample-ma-cross' ORDER BY t_signal, id")->fetchAll());
$used = [];

// ── קריטריון 1: בשתי ריצות (באמצע, ואז עד הסוף) — אותן עסקאות ואותו R כמו הריצה האחת ──
$mid = $C[1950]['t'] + $H;
$s1 = ts_learn_live_cron($mid, $used, null, microtime(true), $load($mid));
eq([$s1['checked'], $s1['advanced'], $s1['errors']], [1, 1, 0], 'run 1 (middle): one backtest advanced');
$mid1 = array_values(array_filter($all, fn($t) => $t['t_signal'] > $TO && $t['t_exit'] <= $C[1950]['t']));
eq(count($liveRows()), count($mid1), 'run 1: only the trades that exited by then');
$end = end($C)['t'] + $H;
$s2 = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq($s2['advanced'], 1, 'run 2 (the end): continues from the saved state');
eq($liveRows(), $wantR, 'criterion 1: live rows = the engine on all candles — same trades, same R');
$loss = array_values(array_filter($liveRows(), fn($r) => $r[1] === 'loss'));
eq(count($loss) > 0 && $loss[0][2] < 0, true, 'criterion 1: a signal that hit the stop → one live row, R from the engine (' . ($loss[0][2] ?? '-') . ')');
$row = $db->query("SELECT * FROM method_outcomes WHERE source = 'live' LIMIT 1")->fetch();
eq([$row['params_hash'], $row['cred_scope'], $row['user_id'], (int)$row['trade_id'], $row['mae_r'] !== null, $row['market']], [$HASH, '', null, 0, true, 'crypto'],
    'a live row: same hash as the backtest, shared scope, no user, MAE/MFE kept');
eq((int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE source = 'live' AND t_signal <= $TO")->fetchColumn(), 0, 'no live row for a signal before the backtest end');

// ריצה חוזרת בלי נר חדש — כלום; אותו now שוב — בלי כפילות
$before = count($liveRows());
$c0 = $calls;
$s3 = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq([$s3['checked'], count($liveRows()), $calls - $c0], [0, $before, 0], 'no new closed candle → not even a fetch, no rows');
$db->exec("UPDATE learn_live SET state = (SELECT state FROM backtests), last_t = $TO");   // כאילו המצב חזר אחורה
ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq(count($liveRows()), $before, 'running the same trades again → no duplicates (the unique key)');
$l = $db->query('SELECT * FROM learn_live')->fetch();
eq([(int)$l['base_t'], (int)$l['last_t'], (int)$l['method_version'], $l['params_hash']], [$TO, (int)end($C)['t'], (int)$meta['version'], $HASH], 'learn_live: base, last candle, version, hash');

// ── דילוגים: גרסה / פרמטרים אחרים, מפתח אישי, שיטה לא מצטברת; תקציב ──
$db->exec("DELETE FROM learn_live");
$putBt($bt, $TO, '', 'otherhash');
$cnt = count($liveRows());
$c1 = $calls;
$s = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq([$s['skipped'], $s['advanced'], count($liveRows())], [1, 0, $cnt], 'parameters changed since the backtest (hash) → skipped, nothing written');
// ביקורת L2 🟡1: ה-hash נבדק **לפני** המשיכה, והבדיקה הזו מושהית (at נשמר) — לא נמשכת שוב, ולא חוסמת את התור
eq($calls - $c1, 0, 'R2 🟡1: hash mismatch → no candles fetched');
$pk = $db->query('SELECT state, last_t, at FROM learn_live')->fetch();
eq([$pk['state'], (int)$pk['last_t'], (int)$pk['at']], ['', 0, $end], 'R2 🟡1: parked — empty state, at saved');
$c1 = $calls;
$s = ts_learn_live_cron($end + $H, $used, null, microtime(true), $load($end + $H));
eq([$s['skipped'], $s['checked'], $calls - $c1], [1, 0, 0], 'R2 🟡1: next run — skipped at once, no fetch, nothing checked');
$putBt($bt, $TO, '', null, null, $end + 10);   // הבדיקה לאחור חושבה מחדש — עם הפרמטרים של היום
$s = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq([$s['advanced'], count($liveRows())], [1, $cnt], 'R2 🟡1: the backtest recomputed → live resumes from it, no duplicates');
$db->exec("DELETE FROM learn_live");
$putBt($bt, $TO, '', null, (int)$meta['version'] + 5);
$s = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq([$s['skipped'], $s['advanced']], [1, 0], 'another method version → skipped');
$putBt($bt, $TO);
$db->exec("UPDATE backtests SET cred_scope = 'u:9'");
$s = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq([$s['private'], $s['advanced']], [1, 0], 'a backtest on a personal key → not run by the system (private)');
$db->exec("UPDATE backtests SET cred_scope = ''");
$s = ts_learn_live_cron($end, $used, null, microtime(true), function () { throw new TsBudget('x'); });
eq([$s['deferred'], (int)$db->query('SELECT COUNT(*) FROM learn_live')->fetchColumn()], [1, 0], 'no budget → deferred, no state saved');
$s = ts_learn_live_cron($end, $used, null, microtime(true), $load($end));
eq([$s['advanced'], count($liveRows())], [1, $cnt], 'next run → continues, still no duplicates');

// ── קריטריונים 2–3: trade ──────────────────────────────────────
foreach ([[1, '201', 'א'], [2, '202', 'ב']] as [$id, $sub, $nm]) {
    $db->prepare('INSERT INTO users (id, platform_sub, display_name, created_at, last_login_at) VALUES (?, ?, ?, 1, 1)')->execute([$id, $sub, $nm]);
}
$GLOBALS['ts_current'] = null;
$EV = (int)ts_method('elliott-w3')->meta()['version'];
$snap = ['asset' => $ASSET, 'tf' => 'h1', 'method_id' => 'elliott-w3', 'method_version' => $EV, 'as_of' => 1767225600];
$trade = fn(array $o) => $o + ['symbol' => 'BTCUSDT', 'market' => 'crypto', 'currency' => 'USDT', 'multiplier' => '1', 'direction' => 'long',
    'opened_at' => '2026-01-01T10:00', 'entry_price' => '100', 'quantity' => '2', 'entry_fees' => '1',
    'exits' => [['exited_at' => '2026-01-02T12:00', 'price' => '96', 'quantity' => '2', 'fees' => '1']]];
$trades = fn(int $uid) => $db->query("SELECT * FROM method_outcomes WHERE source = 'trade' AND user_id = $uid ORDER BY id")->fetchAll();
$tid = ts_trade_save(1, $trade(['planned_stop' => '98', 'planned_target' => '110', 'method_id' => 'elliott-w3', 'analysis_snapshot' => $snap]), 'UTC');
$r = $trades(1);
// סיכון 2 × 2 = 4; נטו (96−100)×2 − 2 = −10 → ‎−2.5R
eq(count($r), 1, 'criterion 2: a closed trade with method_id and planned_stop → one trade row');
eq([$r[0]['method_id'], (int)$r[0]['method_version'], $r[0]['asset'], $r[0]['tf'], $r[0]['result'], (float)$r[0]['r'], $r[0]['stop'], $r[0]['exit'], (int)$r[0]['trade_id'], (int)$r[0]['t_signal']],
    ['elliott-w3', $EV, $ASSET, 'h1', 'loss', -2.5, '98', '96', $tid, 1767225600], 'criterion 2: R by planned_stop, after fees; the snapshot gives asset, tf, version, signal time');
ts_trade_save(1, ['id' => $tid] + $trade(['planned_stop' => '98', 'exits' => [['exited_at' => '2026-01-02T12:00', 'price' => '120', 'quantity' => '2']]]), 'UTC');
eq([count($trades(1)), (float)$trades(1)[0]['r']], [1, -2.5], 'editing the trade later does not change or add the row (23.3)');
ts_trade_save(1, $trade(['method_id' => 'elliott-w3', 'analysis_snapshot' => $snap]), 'UTC');
eq(count($trades(1)), 1, 'criterion 2: no planned_stop → no row');
ts_trade_save(1, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => $snap, 'exits' => []]), 'UTC');
eq(count($trades(1)), 1, 'an open trade → no row (only finished trades)');
ts_trade_save(1, $trade(['planned_stop' => '98']), 'UTC');
eq(count($trades(1)), 1, 'no method → no row');
ts_trade_save(1, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3']), 'UTC');
eq(count($trades(1)), 1, 'a method without an analysis snapshot (no asset / tf) → no row');
$open = ts_trade_save(1, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => $snap, 'exits' => []]), 'UTC');
ts_trade_save(1, ['id' => $open] + $trade(['planned_stop' => '98']), 'UTC');
$t1 = $trades(1);
eq([count($t1), (int)end($t1)['trade_id']], [2, $open], 'a trade closed later (by an edit) → its row then');

// ביקורת L2 🟡2: התמונה מהדפדפן — גרסה מעל הנוכחית → הנוכחית; זמן אות אחרי הכניסה או בעתיד → זמן הכניסה
$curV = (int)ts_method('elliott-w3')->meta()['version'];
$bad = ts_trade_save(1, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => ['method_version' => 99, 'as_of' => 1767225600 + 3 * 86400] + $snap]), 'UTC');
$rb = $db->query("SELECT method_version, t_signal FROM method_outcomes WHERE source = 'trade' AND trade_id = $bad")->fetch();
eq([(int)$rb['method_version'], (int)$rb['t_signal']], [$curV, (int)strtotime('2026-01-01T10:00Z')], 'R2 🟡2: version 99 → the current one; as_of after the entry → the entry time');
$bad = ts_trade_save(1, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => ['method_version' => 0, 'as_of' => 'x'] + $snap]), 'UTC');
$rb = $db->query("SELECT method_version, t_signal FROM method_outcomes WHERE source = 'trade' AND trade_id = $bad")->fetch();
eq([(int)$rb['method_version'], (int)$rb['t_signal']], [$curV, (int)strtotime('2026-01-01T10:00Z')], 'R2 🟡2: version 0 / as_of not a number → the current one and the entry time');
// ⚪3: בלי as_of, שינוי זמן הכניסה משנה את t_signal — ועדיין שורה אחת לעסקה
$noAs = $snap; unset($noAs['as_of']);
$tx = ts_trade_save(1, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => $noAs]), 'UTC');
ts_trade_save(1, ['id' => $tx] + $trade(['planned_stop' => '98', 'opened_at' => '2026-01-01T08:00']), 'UTC');
eq((int)$db->query("SELECT COUNT(*) FROM method_outcomes WHERE source = 'trade' AND trade_id = $tx")->fetchColumn(), 1, 'R2 ⚪3: editing opened_at → still one row for the trade');

// קריטריון 3: משתמש שכיבה
ts_learn_off_set(2, true);
ts_trade_save(2, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => $snap]), 'UTC');
eq(count($trades(2)), 0, 'criterion 3: a user who turned it off → no trade rows of his');
ts_learn_off_set(2, false);
ts_trade_save(2, $trade(['planned_stop' => '98', 'method_id' => 'elliott-w3', 'analysis_snapshot' => $snap]), 'UTC');
eq(count($trades(2)), 1, '… and on again → rows from then on');
$failed = false;
try { ts_learn_off_set(2, 'yes'); } catch (TsInvalid) { $failed = true; }
eq($failed, true, 'learn_off_set: only true / false');

// קריטריון 4 (בתהליך): user_id לא חוזר מ-ts_outcomes, ובייצוא — רק כשמבקשים (העורך הראשי). בדיקת ה-API — tests/learn-live-api.php
$o = ts_outcomes('elliott-w3', ['source' => 'trade']);
eq([count($o), array_key_exists('user_id', $o[0])], [6, false], 'criterion 4: ts_outcomes has no user_id');
$h0 = str_getcsv(explode("\n", ltrim(ts_learn_csv('elliott-w3', null, 'trade', false), "\u{FEFF}"))[0]);
$h1 = str_getcsv(explode("\n", ltrim(ts_learn_csv('elliott-w3', null, 'trade', true), "\u{FEFF}"))[0]);
eq([in_array('user_id', $h0, true), in_array('trade_id', $h0, true), in_array('user_id', $h1, true), in_array('trade_id', $h1, true)], [false, false, true, true],
    'criterion 4 / R2 ⚪4: CSV — user_id and trade_id only with $withUser');

echo $fail ? "$fail/$n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
