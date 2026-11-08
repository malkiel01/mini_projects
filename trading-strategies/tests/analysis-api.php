<?php
// בדיקות API לפרופיל, לשיטות, לבדיקה לאחור ולניתוח (spec-stage-a 5–6, פרק 15: בידוד, מנהל, בדיקה לאחור, תקרות,
// שקלול, ניסוח). php trading-strategies/tests/analysis-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-analysis-api-' . getmypid();
$port = 21000 + getmypid() % 1000;
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log"] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
$jar = []; $tokens = []; $bodies = [];
function call(string $as, string $action, ?array $body = null, array $query = [], array $extra = []): array
{
    global $port, $jar, $tokens, $bodies;
    $h = ["X-Dev-As: $as", ...$extra];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    $bodies[] = $raw;
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob', 'carol', 'owner', 'dave'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO('sqlite:' . "$tmp.sqlite");
$cache = new PDO('sqlite:' . "$tmp.sqlite.cache");
$uidOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
$SAMPLE = 'sample-ma-cross';
$now = time();

// ── פרופיל (5) ───────────────────────────────────────────
$p = call('alice', 'profile')[1]['profile'];
eq([$p['horizon'], $p['risk_level'], $p['risk_pct'], $p['min_certainty'], $p['alert_channels'], $p['saved']], ['medium', 'medium', '1', 55, ['inbox'], false], 'profile defaults');
$good = ['horizon' => 'short', 'risk_level' => 'high', 'risk_pct' => '2', 'min_rr' => '1.5', 'min_certainty' => 60, 'preferred_methods' => [],
    'alert_channels' => ['push'], 'quiet_hours' => ['from' => '22:00', 'to' => '07:00'], 'max_alerts_per_day' => 5, 'user_id' => $uidOf('102')];
[$c, $r] = call('alice', 'profile_save', $good);
eq([$c, $r['profile']['horizon'], $r['profile']['alert_channels'], $r['profile']['quiet_hours']['from']], [200, 'short', ['inbox', 'push'], '22:00'], 'alice saves her profile (in-app inbox always on)');
eq(call('bob', 'profile')[1]['profile']['saved'], false, 'isolation: a user_id in the body is ignored — bob\'s profile untouched');
eq(call('bob', 'export', null, ['format' => 'json'])[1]['profile']['horizon'], 'medium', 'isolation: bob\'s export has his own profile');
eq(call('alice', 'export', null, ['format' => 'json'])[1]['profile']['risk_level'], 'high', 'alice\'s export includes her profile');
eq(call('alice', 'me')[1]['user']['default_risk_pct'], '2', 'risk per trade is one value: the stage-1 setting follows the profile');
call('alice', 'settings', ['base_currency' => 'USD', 'timezone' => 'Asia/Jerusalem', 'account_size' => '', 'default_risk_pct' => '0.75']);
eq(call('alice', 'profile')[1]['profile']['risk_pct'], '0.75', '… and the profile follows the stage-1 setting');
foreach ([['min_certainty' => 85], ['min_certainty' => 49], ['horizon' => 'forever'], ['risk_pct' => '101'], ['risk_pct' => 2.5],
    ['alert_channels' => ['email']], ['quiet_hours' => ['from' => '25:00', 'to' => '07:00']], ['max_alerts_per_day' => 0],
    ['preferred_methods' => [$SAMPLE]], ['preferred_methods' => ['nope']]] as $bad) {
    eq(call('alice', 'profile_save', $bad + $good)[0], 422, 'profile rejects ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
}
$tokens['alice_ok'] = $tokens['alice']; $tokens['alice'] = 'wrong';
eq(call('alice', 'profile_save', $good)[0], 403, 'profile_save without the right X-CSRF → 403');
$tokens['alice'] = $tokens['alice_ok'];

// ── מנהל: 403 למשתמש רגיל, גם בקריאה ישירה ──────────────
$mset = ['id' => $SAMPLE, 'enabled' => true, 'markets' => ['crypto', 'stocks'], 'timeframes' => ['m1', 'm5', 'h1', 'h4', 'd1'], 'params' => [], 'note' => 'לבדיקת המנוע'];
eq(call('alice', 'admin_methods')[0], 403, 'admin_methods → 403 for a regular user');
eq(call('alice', 'admin_method_save', $mset)[0], 403, 'admin_method_save → 403 for a regular user');
eq(call('alice', 'admin_costs_save', ['costs' => ['crypto' => ['fee' => '0', 'slip' => '0']]])[0], 403, 'admin_costs_save → 403 for a regular user');
$carol = $uidOf('103');
call('owner', 'admin_role_add', ['user_id' => $carol]);
eq(call('carol', 'admin_methods')[0], 200, 'additional admin → 200');
call('owner', 'admin_role_remove', ['user_id' => $carol]);
eq([call('carol', 'admin_methods')[0], call('carol', 'admin_method_save', $mset)[0]], [403, 403], 'revoked admin → 403 immediately');

// ── שיטות (6.7): חדשה = כבויה; דוגמה — רק למנהלים ─────────
$am = call('owner', 'admin_methods')[1];
eq([count($am['methods']), $am['methods'][0]['id'], $am['methods'][0]['enabled'], $am['methods'][0]['sample'], $am['methods'][0]['version'], $am['methods'][0]['alerts']],
    [1, $SAMPLE, false, true, 1, false], 'the code method is listed, OFF by default, sample, version 1, not for alerts');
eq(call('owner', 'methods')[1]['methods'], [], 'off → not offered even to the admin');
eq(call('owner', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $SAMPLE])[0], 404, 'off → analysis refuses it');
foreach ([['params' => ['fast' => 60]], ['params' => ['fast' => 1]], ['params' => ['fast' => 2.5]], ['params' => ['zzz' => 1]], ['markets' => ['options']], ['timeframes' => ['mn1']], ['timeframes' => []]] as $bad) {
    eq(call('owner', 'admin_method_save', $bad + $mset)[0], 422, 'admin_method_save rejects ' . json_encode($bad));
}
eq(call('owner', 'admin_method_save', $mset)[0], 200, 'the admin turns it on');
$vis = call('owner', 'methods')[1]['methods'];
eq([count($vis), $vis[0]['label'], $vis[0]['alerts']], [1, '🧪 שיטת דוגמה — לא מהספר', false], 'on → the admin sees it, with the label, not for alerts');
eq(call('alice', 'methods')[1]['methods'], [], 'on → still hidden from a regular user (sample)');
eq(call('alice', 'analysis', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $SAMPLE])[0], 404, 'a regular user cannot run it by id');
eq(call('alice', 'profile_save', ['preferred_methods' => [$SAMPLE]] + $good)[0], 422, 'a regular user cannot even prefer it');
eq(count(array_filter(call('owner', 'admin_state')[1]['log'], fn($l) => $l['action'] === 'method_save')), 1, 'method change logged in admin_log');

// ── ניתוח ובדיקה לאחור (6.1–6.6) ─────────────────────────
$q = ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'methods' => $SAMPLE];
call('owner', 'settings', ['base_currency' => 'USD', 'timezone' => 'UTC', 'account_size' => '10000', 'default_risk_pct' => '1']);
$t0 = microtime(true);
[$c, $r] = call('owner', 'analysis', null, $q);
$card = $r['cards'][0] ?? [];
$bt = $card['backtest'] ?? [];
eq([$c, $card['status'] ?? null, $card['method']['label'] ?? null], [200, 'ok', '🧪 שיטת דוגמה — לא מהספר'], 'analysis card, with the sample label');
eq([$bt['status'] ?? null, $bt['mode'] ?? null, ($bt['n'] ?? 0) >= 30, is_int($card['certainty'] ?? null)], ['ok', 'full', true, true], 'first open: full backtest, N ≥ 30, certainty is a whole percentage');
eq($card['certainty'], (int)round(100 * (function ($w, $n) { $z = 1.959963984540054; $p = $w / $n; return ($p + $z * $z / (2 * $n) - $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n))) / (1 + $z * $z / $n); })($bt['wins'], $bt['n'])), 'certainty = Wilson lower bound of wins/N');
eq([count($card['conditions']), in_array($card['bias'], ['long', 'short', 'none'], true)], [4, true], 'conditions and bias');
eq(($bt['to_t'] ?? 0) + 3600 <= $now && ($bt['to_t'] ?? 0) + 7200 > $now, true, 'the backtest ends at the last CLOSED candle (not the open one)');
eq(($bt['from_t'] ?? 0) <= $now - 700 * 86400, true, 'h1 history reaches ~2 years back (fixed range, 6.2 ג)');
eq($r['disclaimer'], 'ניתוח אוטומטי לפי כללי שיטות מסחר וביצועי עבר. אינו ייעוץ השקעות ואינו מתחשב בנתונים האישיים שלך. ביצועי עבר אינם מבטיחים תוצאות בעתיד.', 'the fixed disclaimer (6.6)');
eq($card['horizon_text'], 'עד 50 נרות של שעה (~2 ימים)', 'horizon text (6.4)');
eq($card['max_horizon']['tf'] ?? null, 'h1', 'max horizon = longest timeframe with N ≥ 30 among stored backtests');
eq($cache->query('SELECT COUNT(*) FROM candles WHERE t < ' . ($now - 60 * 86400 - 3600))->fetchColumn(), 0, 'the long history is not kept in the cache after the run (only 60 days)');
$row = $db->query("SELECT * FROM backtests WHERE method_id = '$SAMPLE' AND tf = 'h1'")->fetch(PDO::FETCH_ASSOC);
eq([(int)$row['n'], $row['cred_scope'], (int)$row['method_version']], [$bt['n'], '', 1], 'the result is stored (shared scope for a keyless provider)');
eq(strlen($row['state']) < 20000, true, 'stored state is small (no candles in it)');
$forced = $card['levels'] ?? null;
if ($forced) {
    $dec = $r['decimals'];
    eq(array_map(fn($v) => $v === null || preg_match('/^-?\d+(\.\d{1,' . max(1, $dec) . '})?$/', $v) === 1, $forced), ['entry' => true, 'stop' => true, 'target' => true], 'levels are decimal strings rounded to the tick');
    eq(is_string($card['risk']['position_qty'] ?? null), true, 'position size from the profile risk — a decimal string');
} else {
    eq($card['bias'], 'none', 'no active signal → no levels');
}
$words = '/קנה|מכור|המלצ|כדאי/u';
eq(preg_match($words, json_encode($r, JSON_UNESCAPED_UNICODE)), 0, 'no "buy/sell/recommend" wording in the analysis (6.6)');

[$c, $r2] = call('owner', 'analysis', null, $q);
eq([$r2['cards'][0]['backtest']['mode'], $r2['cards'][0]['backtest']['provider_calls'], $r2['cards'][0]['backtest']['n']], ['stored', 0, $bt['n']], 'reopened the same day → the stored result, no recomputation, no provider calls');
$later = $now + 86400 + 3600;
[$c, $r3] = call('owner', 'analysis', null, $q, ["X-Dev-Now: $later"]);
$b3 = $r3['cards'][0]['backtest'] ?? [];
eq([$c, $b3['mode'] ?? null, ($b3['to_t'] ?? 0) > $bt['to_t'], ($b3['from_t'] ?? null) === $bt['from_t']], [200, 'incremental', true, true], 'a day later → incremental: continues from the stored state, same start');
eq(($b3['bars'] ?? 0) - $bt['bars'], 25, 'incremental processed only the ~25 new closed hours');

// גרסה/פרמטרים השתנו → מלא
call('owner', 'admin_method_save', ['params' => ['horizon' => 40]] + $mset);
$b4 = call('owner', 'analysis', null, $q)[1]['cards'][0]['backtest'];
eq([$b4['mode'], $db->query("SELECT COUNT(*) FROM backtests WHERE method_id = '$SAMPLE' AND tf = 'h1'")->fetchColumn()], ['full', 1], 'params changed → full recompute, replacing the old row');
eq(call('owner', 'analysis', null, $q)[1]['cards'][0]['horizon_bars'], 40, 'the admin\'s parameter is used');
call('owner', 'admin_costs_save', ['costs' => ['crypto' => ['fee' => '0.2', 'slip' => '0.1']]]);
eq(call('owner', 'analysis', null, $q)[1]['cards'][0]['backtest']['mode'], 'full', 'fees changed by the admin → full recompute');
eq(call('owner', 'admin_costs_save', ['costs' => ['crypto' => ['fee' => '9', 'slip' => '0']]])[0], 422, 'fee above 5% refused');

// מסגרת שהמנהל לא התיר → כרטיס "לא מוגדרת", בלי אחוז
$u = call('owner', 'analysis', null, ['tf' => 'm15'] + $q)[1]['cards'][0];
eq([$u['status'], $u['certainty']], ['unsupported', null], 'timeframe not allowed by the admin → no analysis, no percentage');

// ── פרופיל → סף ודאות ────────────────────────────────────
call('owner', 'profile_save', ['min_certainty' => 80, 'preferred_methods' => [$SAMPLE]] + $good);
$c5 = call('owner', 'analysis', null, $q)[1];
eq($c5['cards'][0]['below_threshold'], $c5['cards'][0]['certainty'] < 80, '"below your threshold" follows the profile');
eq([$c5['blend']['total'], $c5['blend']['included'][0]['id'] ?? null], [1, $SAMPLE], 'blend over the methods with certainty');

// ── ספק (א): התוצאה רק של בעל המפתח; N < 30 → בלי אחוז ─────
call('owner', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Owner5551234']);
call('owner', 'admin_role_add', ['user_id' => $carol]);
call('carol', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-Carol7779876']);
$qa = ['asset' => 'twelvedata:AAPL', 'tf' => 'h1', 'methods' => $SAMPLE];
$ow = call('owner', 'analysis', null, $qa)[1]['cards'][0];
eq([$ow['backtest']['status'], $ow['certainty'], $ow['backtest']['win_pct'], $ow['backtest']['mode']], ['insufficient', null, null, 'full'], 'flat prices → N < 30 → "not enough data", no percentage');
$ca = call('carol', 'analysis', null, $qa)[1]['cards'][0];
eq($ca['backtest']['mode'], 'full', 'carol (own key) does not get the owner\'s personal result — her own run');
$scopes = $db->query("SELECT cred_scope FROM backtests WHERE asset = 'twelvedata:AAPL' ORDER BY cred_scope")->fetchAll(PDO::FETCH_COLUMN);
eq($scopes, ['u:' . $uidOf('103'), 'u:' . $uidOf('100')], 'two personal results, one per key owner (cred_scope)');
call('owner', 'admin_role_remove', ['user_id' => $carol]);
eq(call('carol', 'analysis', null, $qa)[0], 404, 'carol no longer admin → the sample method is gone for her');

// ── תקציב (6.2 ה): חסר → "תושלם מאוחר יותר", בלי אחוז ─────
$credId = (int)$db->query("SELECT id FROM provider_creds WHERE owner = 'user' AND user_id = " . $uidOf('100') . " AND provider_id = 'twelvedata'")->fetchColumn();
$used = (int)$db->query("SELECT calls FROM provider_usage WHERE key = 'cred:$credId' AND user_id = 0 AND day = '" . gmdate('Y-m-d') . "'")->fetchColumn();
call('owner', 'admin_provider_save', ['provider' => 'twelvedata', 'enabled' => true, 'per_day' => (string)($used + 2)]);
$bl = call('owner', 'analysis', null, ['asset' => 'twelvedata:MSFT'] + $qa)[1]['cards'][0];
eq([$bl['backtest']['status'], $bl['certainty']], ['later', null], 'not enough provider budget → "will complete later", no percentage');
eq(str_contains($bl['backtest']['message'], 'מאוחר יותר'), true, 'with the message');
call('owner', 'admin_provider_save', ['provider' => 'twelvedata', 'enabled' => true, 'per_day' => '']);

// ── תקרה של 100,000 נרות ─────────────────────────────────
$t1 = microtime(true);
[$c, $rm] = call('owner', 'analysis', null, ['tf' => 'm1'] + $q);
$bm = $rm['cards'][0]['backtest'] ?? [];
eq([$c, $bm['status'] ?? null, ($bm['bars'] ?? 0) <= 100000, ($bm['bars'] ?? 0) > 99000], [200, 'ok', true, true], sprintf('m1 (90 days = 129,600) → capped at the last 100,000 candles (%.1f s)', microtime(true) - $t1));
eq($cache->query('SELECT COUNT(*) FROM candles WHERE t < ' . ($now - 60 * 86400 - 60))->fetchColumn(), 0, 'and the history older than 60 days is not kept');

// ── תקרת 30 לדקה: הניתוח נספר ────────────────────────────
if ((int)date('s') > 45) sleep(61 - (int)date('s'));
$codes = [];
for ($i = 0; $i < 31; $i++) $codes[] = call('dave', 'analysis', null, $q)[0];
eq(array_count_values($codes), [404 => 30, 429 => 1], 'analysis counts in the 30-per-minute data limit');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
