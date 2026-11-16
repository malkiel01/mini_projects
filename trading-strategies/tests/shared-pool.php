<?php
// Twelve Data — מאגר משותף (spec-stage-a 21.3, ✅31): מפתח האפליקציה, הסדר כשחסר (אפליקציה → אישי → שיוך), 100 פניות ליום
// למשתמש, מה שבמאגר בלי מפתח, המיגרציה של המאגר (u:% / c:% → ''), ובחירת הספק לזוג כשיש מפתח אפליקציה.
// php trading-strategies/tests/shared-pool.php — בלי רשת: הספקים עונים מ-tests/fixtures/market.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-shared-pool-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DEV', true);
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
define('TS_SECRET_KEY_PATH', "$tmp.key");
putenv('TS_HTTP_FIXTURES=' . __DIR__ . '/fixtures/market');
putenv("TS_HTTP_LOG=$tmp.http.log");
register_shutdown_function(fn() => array_map('unlink', glob("$tmp*") ?: []));

require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/market.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
/** הפניות לספק מאז שורה $from: [url, key] */
function http(int $from = 0): array
{
    global $tmp;
    $out = [];
    foreach (array_slice(is_file("$tmp.http.log") ? file("$tmp.http.log") : [], $from) as $l) {
        $j = json_decode($l, true);
        $key = '';
        foreach ($j['headers'] as $hh) if (stripos($hh, 'Authorization: apikey ') === 0) $key = substr($hh, 22);
        $out[] = [$j['url'], $key];
    }
    return $out;
}
$httpN = fn() => is_file("$tmp.http.log") ? count(file("$tmp.http.log")) : 0;

$db = ts_db();
$db->exec("INSERT INTO users (platform_sub, display_name, created_at) VALUES ('1', 'A', 0), ('2', 'B', 0), ('3', 'C', 0)");
[$A, $B, $C] = [1, 2, 3];
$now = gmmktime(15, 30, 0, 11, 10, 2026);
$usage = function (int $credId, int $uid = 0) use ($db, $now) {
    $st = $db->prepare('SELECT calls FROM provider_usage WHERE key = ? AND user_id = ? AND day = ?');
    $st->execute(["cred:$credId", $uid, gmdate('Y-m-d')]);
    return (int)$st->fetchColumn();
};

// ── מאגר משותף לפי הספק ──
eq([ts_provider('twelvedata')->meta()['share'], ts_provider('finnhub')->meta()['share']], [true, false], 'twelvedata is shared (21.3); finnhub is not');
eq([ts_app_key_allowed('twelvedata'), ts_app_key_allowed('finnhub'), ts_app_key_allowed('binance')], [true, false, false], 'an app key only for a shared provider that needs a key');

// ── בלי שום מפתח (קריטריון 5) ──
eq([ts_access($A, 'twelvedata'), ts_pool_only($A, 'twelvedata'), ts_scope_for($A, 'twelvedata')], [null, true, ''], 'no key at all → no access, but the shared pool is readable');
try {
    ts_market_candles($A, 'twelvedata:AAPL', 'd1', $now - 10 * 86400, $now, $now);
    eq(true, false, 'criterion 5: an asset with no data and no key must not answer');
} catch (TsInvalid $e) {
    eq(str_contains($e->getMessage(), 'אין לכם חיבור'), true, 'criterion 5: an asset with no data and no key → no access (ש7)');
}

// ── מפתח האפליקציה: מסמנים (קריטריון 9) ──
$app = ts_cred_save(0, 'twelvedata', 'good-app-000001', 'אפליקציה', 'admin');
ts_app_key_set('twelvedata', $app);
eq(ts_app_keys_state(), ['twelvedata' => ['cred_id' => $app, 'user_limit' => 100]], 'the app key is marked; 100 a day per user by default');
$fin = ts_cred_save(0, 'finnhub', 'good-fin-000001', '', 'admin');
try { ts_app_key_set('twelvedata', $fin); eq(true, false, 'a key of another provider must be refused'); }
catch (TsInvalid) { eq(true, true, 'a key of another provider cannot be the app key'); }
try { ts_app_key_set('finnhub', $fin); eq(true, false, 'finnhub has no app key'); }
catch (TsInvalid) { eq(true, true, 'finnhub has no app key (personal-only)'); }

// ── קריטריון 1: A (בלי מפתח) פותח מניה → מפתח האפליקציה, ונשמר ב-''. B שעה אחר כך → רק החסר ──
$a = ts_market_candles($A, 'twelvedata:AAPL', 'h1', $now - 5 * 86400, $now, $now);
eq([$a['source'], $a['provider_calls'], $usage($app), $usage($app, $A)], ['app', 1, 1, 1], 'criterion 1: A (no key) — fetched with the app key, counted for the key and for A');
eq(ts_cache()->query("SELECT DISTINCT cred_scope FROM candles WHERE provider = 'twelvedata'")->fetchAll(PDO::FETCH_COLUMN), [''], 'criterion 1: saved to the shared pool (\'\')');
$b = ts_market_candles($B, 'twelvedata:AAPL', 'h1', $now - 5 * 86400 + 3600, $now + 3600, $now + 3600);
eq([$b['provider_calls'], $b['provider_candles'] <= 2, count($b['candles'])], [1, true, count($a['candles'])], 'criterion 1: B an hour later — only what is missing (the hour that closed + the open one)');

// ── קריטריון 3: הסדר — מפתח האפליקציה לפני המפתח האישי; בתקרה → האישי ──
$own = ts_cred_save($C, 'twelvedata', 'good-carol-0001', 'שלי');
eq(array_column(ts_access_chain($C, 'twelvedata'), 'source'), ['app', 'own'], 'criterion 3: the order — app key, then the user\'s own key');
$h0 = $httpN();
$c1 = ts_market_candles($C, 'twelvedata:MSFT', 'h1', $now - 86400, $now, $now);
eq([$c1['source'], $usage($own), array_column(http($h0), 1)], ['app', 0, ['good-app-000001']], 'criterion 3: with both — the request goes with the app key (provider_usage)');
ts_app_key_set('twelvedata', $app, 2);   // התקרה למשתמש — 2 (המנהל הראשי משנה)
eq(ts_app_user_limit('twelvedata'), 2, 'the primary admin changes the per-user limit');
$db->prepare("UPDATE provider_usage SET calls = 2 WHERE key = ? AND user_id = ?")->execute(["cred:$app", $C]);
$h0 = $httpN();
$c2 = ts_market_candles($C, 'twelvedata:NVDA', 'h1', $now - 86400, $now, $now);
eq([$c2['source'], $c2['provider_calls'], $c2['notice'], $usage($own), array_column(http($h0), 1)], ['own', 1, null, 1, ['good-carol-0001']],
    'criterion 3: the app key at C\'s limit → C\'s own key, without a message');

// ── קריטריון 4: בלי מפתח, פנייה 101 → הודעת תקרה, והגרף מהמאגר ──
ts_app_key_set('twelvedata', $app, 100);
$db->prepare("INSERT INTO provider_usage (key, user_id, day, calls) VALUES (?, ?, ?, 100) ON CONFLICT(key, user_id, day) DO UPDATE SET calls = 100")
    ->execute(["cred:$app", $B, gmdate('Y-m-d')]);
$h0 = $httpN();
$b2 = ts_market_candles($B, 'twelvedata:AAPL', 'h1', $now - 5 * 86400, $now + 7200, $now + 7200);
eq([$b2['provider_calls'], count($b2['candles']) >= count($a['candles']), str_contains((string)$b2['notice'], '100 פניות'), str_contains((string)$b2['notice'], 'מוצג מה שבמאגר'), $httpN() - $h0],
    [0, true, true, true, 0], 'criterion 4: B at 100 → the 101st is refused with the cap message, and the chart is served from the pool');
try {
    ts_market_candles($B, 'twelvedata:TSLA', 'h1', $now - 86400, $now, $now);
    eq(true, false, 'criterion 4: no data and at the cap must throw');
} catch (TsCapError $e) {
    eq(str_contains($e->getMessage(), '100 פניות'), true, 'criterion 4: an asset with no data at all — the cap message (an error)');
}

// ── קריטריון 9: מחיקת מפתח האפליקציה → אין מפתח, ועוברים למפתח האישי ──
ts_admin_cred_delete($app);
eq([ts_app_cred('twelvedata'), ts_setting('app_key:twelvedata'), ts_access($C, 'twelvedata')['source'] ?? null, ts_access($B, 'twelvedata')], [null, null, 'own', null],
    'criterion 9: deleting the key → no app key; C goes on with her own key, B has none');

// ── קריטריון 5: בלי שום מפתח — מה שבמאגר מוצג, וההודעה ──
$b3 = ts_market_candles($B, 'twelvedata:AAPL', 'h1', $now - 5 * 86400, $now + 4 * 3600, $now + 4 * 3600);
eq([$b3['source'], $b3['provider_calls'], count($b3['candles']) > 0, $b3['notice']], ['pool', 0, true, 'מוצג מה שבמאגר — אין מפתח זמין להשלמה'],
    'criterion 5: no key at all — an asset with data in the pool is shown, with the message');
eq(ts_providers_for($B)[array_search('twelvedata', array_column(ts_providers_for($B), 'id'))]['pool'], true, 'market_providers: pool → the chart opens');

// ── קריטריון 2 (המשך): השיוך (c:N) נכתב גם הוא ל-'' כשהמתג דלוק ──
$g = ts_cred_save(0, 'twelvedata', 'good-grant-0001', '', 'admin');
ts_grant_set($g, $B, 5);
ts_setting_set('shared_providers', '1');
eq([ts_access($B, 'twelvedata')['source'] ?? null, ts_access($B, 'twelvedata')['scope'] ?? null], ['shared', ''], 'switch on: a grant also reads and writes the shared pool');
ts_setting_set('shared_providers', '0');

// ── בחירת הספק לזוג: מפתח האפליקציה רק כשאין ספק אחר (ברירת המחדל, נשאל באפיון) ──
$app2 = ts_cred_save(0, 'twelvedata', 'good-app-000002', '', 'admin');
ts_app_key_set('twelvedata', $app2);
eq([ts_pair_asset($A, 'EUR/USD'), ts_pair_asset($C, 'EUR/USD')], ['ecb:EURUSD', 'twelvedata:EUR/USD'],
    'pairs: through the app key EUR/USD goes to ECB; with an own key — Twelve Data first, as before');
// קריטריון 10 (2.26): ביומי — ECB, 0 פניות במפתח האפליקציה; בשעה — ECB לא נותן → מפתח האפליקציה; BTC/USD → Binance
$u0 = $usage($app2);
$d = ts_market_candles($A, 'ecb:EURUSD', 'd1', $now - 10 * 86400, $now, $now);
eq([$d['provider'], $usage($app2)], ['ecb', $u0], 'criterion 10: EUR/USD daily → ECB, 0 calls with the app key');
$h = ts_market_candles($A, 'ecb:EURUSD', 'h1', $now - 86400, $now, $now);
eq([$h['asset'], $h['source'], $usage($app2) > $u0], ['twelvedata:EUR/USD', 'app', true], 'criterion 10: the same pair hourly → the app key (ECB is daily only)');
eq(ts_pair_tfs($A, 'ecb:EURUSD'), array_keys(TS_TF_SECONDS), 'criterion 10: the chart offers the intraday frames for EUR/USD');
require_once __DIR__ . '/../lib/prefill.php';
$pool = ts_prefill_pool(0, false);
$fxTd = array_values(array_filter($pool, fn($x) => str_starts_with($x, 'twelvedata:') && ts_pair_of_asset($x) !== null && count(ts_pairs_catalog()[ts_pair_of_asset($x)]['assets']) > 1));
eq([in_array('ecb:EURUSD', $pool, true), $fxTd], [true, []], 'criterion 10: the background queue takes these pairs from the free provider — none through the app key');
ts_app_key_set('twelvedata', null);
eq([ts_pair_tf_asset($A, 'ecb:EURUSD', 'd1'), ts_pair_tf_asset($B, 'ecb:EURUSD', 'h1'), ts_pair_tfs($B, 'ecb:EURUSD')], [null, null, ['d1']],
    '… daily stays ECB; with no way to Twelve Data (no app key, no own key) EUR/USD is daily-only');
ts_app_key_set('twelvedata', $app2);
eq(ts_pair_asset($A, 'BTC/USD'), ts_pairs_catalog()['BTC/USD']['assets'][1], 'criterion 10: BTC/USD → Binance');
ts_symbol_pref_set($A, 'twelvedata:EUR/USD');
eq(ts_pair_asset($A, 'EUR/USD'), 'twelvedata:EUR/USD', 'pairs: an explicit choice of the user wins');

// ── קריטריון 6: המיגרציה ──
$c = ts_cache();
$c->exec('DELETE FROM candles; DELETE FROM coverage; DELETE FROM tails; DELETE FROM search');
$ins = $c->prepare('INSERT INTO candles (provider, symbol, tf, t, o, h, l, c, v, fetched_at, cred_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
// u:5 — 3 נרות; u:6 — אחד מהם שוב (מאוחר יותר) ועוד אחד; c:9 — אחד; ב-'' כבר יש את t=100 (מוקדם יותר)
foreach ([['u:5', 100, '1', 10], ['u:5', 200, '2', 10], ['u:5', 300, '3', 10], ['u:6', 200, '2b', 20], ['u:6', 400, '4', 20], ['c:9', 500, '5', 5], ['', 100, '0', 1]] as [$sc, $t, $cl, $at]) {
    $ins->execute(['twelvedata', 'AAPL', 'd1', $t, $cl, $cl, $cl, $cl, '1', $at, $sc]);
}
foreach (['binance' => ['', 'u:5'], 'ecb' => [''], 'finnhub' => ['u:5', 'c:9']] as $p => $scopes) foreach ($scopes as $sc) $ins->execute([$p, 'X', 'd1', 100, '1', '1', '1', '1', '1', 1, $sc]);
foreach (['coverage' => "INSERT INTO coverage (provider, symbol, tf, cred_scope, from_t, to_t) VALUES (?, 'AAPL', 'd1', ?, 0, 1)",
    'tails' => "INSERT INTO tails (provider, symbol, tf, cred_scope, fetched_at) VALUES (?, 'AAPL', 'd1', ?, 1)",
    'search' => "INSERT INTO search (provider, cred_scope, q, result, fetched_at) VALUES (?, ?, 'aapl', '[]', 1)"] as $sql) {
    foreach ([['twelvedata', 'u:5'], ['twelvedata', 'c:9'], ['twelvedata', ''], ['finnhub', 'u:5']] as $r) $c->prepare($sql)->execute($r);
}
$others = fn() => $c->query("SELECT provider, cred_scope, COUNT(*) FROM candles WHERE provider != 'twelvedata' GROUP BY provider, cred_scope ORDER BY 1, 2")->fetchAll(PDO::FETCH_NUM);
$distinct = fn() => (int)$c->query("SELECT COUNT(*) FROM (SELECT DISTINCT symbol, tf, t FROM candles WHERE provider = 'twelvedata')")->fetchColumn();
$before = [$others(), $distinct()];
$c->beginTransaction(); ts_cache_pool_merge($c, 'twelvedata'); $c->commit();
$td = $c->query("SELECT t, c, cred_scope FROM candles WHERE provider = 'twelvedata' ORDER BY t")->fetchAll(PDO::FETCH_NUM);
eq($td, [[100, '1', ''], [200, '2b', ''], [300, '3', ''], [400, '4', ''], [500, '5', '']],
    'criterion 6: the candles moved to \'\' — the later fetched_at wins (t=100 from u:5, t=200 from u:6), none left in u:/c:');
eq([$others(), $distinct()], $before, 'criterion 6: binance, ecb and finnhub untouched; the number of distinct (symbol, tf, t) unchanged');
$left = fn($t) => $c->query("SELECT provider || '|' || cred_scope FROM $t ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
eq([$left('coverage'), $left('tails'), $left('search')], [['finnhub|u:5', 'twelvedata|'], ['finnhub|u:5', 'twelvedata|'], ['finnhub|u:5', 'twelvedata|']],
    'criterion 6: coverage, tails and search of the moved scopes are deleted (rebuilt on demand); finnhub\'s and the shared ones stay');
$snap = fn() => json_encode([$c->query('SELECT * FROM candles ORDER BY provider, cred_scope, t')->fetchAll(), $left('coverage'), $left('tails'), $left('search')]);
$s1 = $snap();
$c->beginTransaction(); ts_cache_pool_merge($c, 'twelvedata'); $c->commit();
eq($snap(), $s1, 'criterion 6: a second run changes nothing (idempotent)');

// המיגרציה רצה פעם אחת כשהמאגר נפתח (user_version 4)
eq((int)$c->query('PRAGMA user_version')->fetchColumn(), 4, 'cache.sqlite at version 4');
eq((int)$db->query('PRAGMA user_version')->fetchColumn() >= 16, true, 'app.sqlite at version 16 (prefill_state of twelvedata u:% removed)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
