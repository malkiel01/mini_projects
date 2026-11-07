<?php
// בדיקות API מקצה לקצה (אפיון פרק 7): הפרדה בין משתמשים, גרסאות, CSRF, ולידציה וייצוא.
// php trading-strategies/tests/api.php — מרים php -S עם מסד זמני ושער מדומה (tests/dev-prepend.php).
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$db = sys_get_temp_dir() . '/ts-api-test-' . getmypid() . '.sqlite';
$port = 18000 + getmypid() % 1000;
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, ['TS_DB_PATH' => $db] + getenv());
register_shutdown_function(function () use ($srv, $db) {
    proc_terminate($srv);
    foreach (glob($db . '*') as $f) @unlink($f);
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

$jar = [];   // עוגייה לכל משתמש
/** [קוד, גוף JSON] */
function call(string $as, string $action, ?array $body = null, array $query = [], ?string $csrf = 'auto', array $headers = []): array
{
    global $port, $jar, $tokens;
    $h = ["X-Dev-As: $as"];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) {
        if (!preg_grep('/^Content-Type:/i', $headers)) $h[] = 'Content-Type: application/json';
        if ($csrf === 'auto') $csrf = $tokens[$as] ?? '';
        if ($csrf !== null) $h[] = "X-CSRF: $csrf";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", [...$h, ...$headers]),
        'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '', 'ignore_errors' => true,
    ]]);
    $url = "http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query);
    $raw = file_get_contents($url, false, $ctx);
    $code = (int)explode(' ', $http_response_header[0])[1];
    foreach ($http_response_header as $line) {
        if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $m)) $jar[$as] = $m[1];
    }
    $json = json_decode((string)$raw, true);
    return [$code, $json ?? $raw];
}

$tokens = [];
foreach (['alice', 'bob'] as $u) {
    [$c, $me] = call($u, 'me');
    eq($c, 200, "$u me");
    $tokens[$u] = $me['csrf'] ?? '';
}
eq(call('alice', 'me')[1]['csrf'], $tokens['alice'], 'session kept by cookie (same csrf)');
eq(call('none', 'me')[0], 401, 'no platform identity → 401');
eq(call('none', 'strategies')[0], 401, 'no identity → no data');

// עוגייה של אליס עם הזהות של בוב — לא נותנת את ה-session של אליס
$saved = $jar['bob'];
$jar['bob'] = $jar['alice'];
[, $me] = call('bob', 'me');
eq($me['csrf'] !== $tokens['alice'], true, "alice's cookie under bob's identity → a new session");
$tokens['bob'] = $me['csrf'];

// ── CSRF ─────────────────────────────────────────────
$strategy = ['name' => 'פריצת טווח', 'markets' => ['stocks'], 'timeframe' => 'd1', 'style' => 'swing', 'status' => 'active',
    'tags' => ['פריצה'], 'thesis' => 'מומנטום אחרי דחיסה', 'setup' => ['טווח צר 5 ימים'],
    'entry_rules' => [['text' => 'סגירה מעל הטווח', 'required' => true], ['text' => 'נפח מעל הממוצע', 'required' => false]],
    'exit_rules' => ['stop' => 'מתחת לשפל הטווח'], 'risk' => ['risk_pct' => '1', 'max_daily_loss' => '3', 'max_open' => '4', 'min_rr' => '2']];
eq(call('alice', 'strategy_save', $strategy, [], null)[0], 403, 'write without X-CSRF → 403');
eq(call('alice', 'strategy_save', $strategy, [], 'wrong')[0], 403, 'write with wrong X-CSRF → 403');
eq(call('alice', 'strategy_save', null)[0], 405, 'write action by GET → 405');
eq(call('alice', 'strategy_save', $strategy, [], 'auto', ['Content-Type: text/plain'])[0] !== 200, true, 'non-JSON body refused');

// ── אסטרטגיה וגרסאות ─────────────────────────────────
[$c, $r] = call('alice', 'strategy_save', $strategy);
eq($c, 200, 'alice creates strategy');
$sid = $r['id'];
$v1 = $r['strategy']['current_version_id'];
eq($r['strategy']['versions'][0]['version_no'], 1, 'version 1');

// שינוי כללים בלי עסקאות — מתעדכן במקום
$strategy['id'] = $sid;
$strategy['setup'] = ['טווח צר 5–7 ימים'];
[, $r] = call('alice', 'strategy_save', $strategy);
eq([$r['new_version'], count($r['strategy']['versions']), $r['strategy']['current_version_id']], [false, 1, $v1], 'no trades → edit in place');

$trade = ['symbol' => 'aapl', 'market' => 'stocks', 'currency' => 'usd', 'direction' => 'long', 'strategy_version_id' => $v1,
    'opened_at' => '2026-03-02T16:30', 'entry_price' => '100', 'quantity' => '10', 'entry_fees' => '1', 'planned_stop' => '95',
    'planned_target' => '110', 'exits' => [['exited_at' => '2026-03-03T17:00', 'price' => '110', 'quantity' => '5', 'fees' => '0.5'],
        ['exited_at' => '2026-03-04T17:00', 'price' => '105', 'quantity' => '5', 'fees' => '0.5']],
    'checklist' => [true, false], 'followed_plan' => 'yes', 'mood' => 'calm', 'rating' => 4];
[$c, $r] = call('alice', 'trade_save', $trade);
eq($c, 200, 'alice saves trade');
$tid = $r['id'];
$t = $r['trade'];
eq([$t['symbol'], $t['currency'], $t['calc']['status'], $t['calc']['net'], $t['calc']['r'], $t['version_no']],
    ['AAPL', 'USD', 'closed', '73', '1.46', 1], 'trade computed on server');
eq(array_column($t['checklist'], 'met'), [true, false], 'checklist from version rules');
eq($t['checklist'][0]['text'], 'סגירה מעל הטווח', 'checklist carries rule text');
eq($t['opened_at'], '2026-03-02T16:30', 'local time round-trip (Asia/Jerusalem)');

// הערות בלבד — לא גרסה חדשה
$strategy['notes'] = 'לבדוק גם בקריפטו';
[, $r] = call('alice', 'strategy_save', $strategy);
eq([$r['new_version'], count($r['strategy']['versions'])], [false, 1], 'notes only → no new version');

// שינוי כלל כשיש עסקאות — גרסה 2, והעסקה נשארת על גרסה 1
$strategy['entry_rules'][] = ['text' => 'מעל ממוצע 50', 'required' => true];
[, $r] = call('alice', 'strategy_save', $strategy);
eq([$r['new_version'], count($r['strategy']['versions']), $r['strategy']['versions'][1]['version_no']], [true, 2, 2], 'rules changed with trades → v2');
$v2 = $r['strategy']['current_version_id'];
eq($v2 !== $v1, true, 'current version moved');
eq([$r['strategy']['versions'][0]['trades'], $r['strategy']['versions'][1]['trades']], [1, 0], 'trade stays on v1');
eq(count($r['strategy']['versions'][0]['entry_rules']), 2, 'v1 rules unchanged');
[, $r] = call('alice', 'trade', null, ['id' => $tid]);
eq([$r['trade']['strategy_version_id'], $r['trade']['version_no']], [$v1, 1], 'old trade still on v1');
// עוד שינוי — v2 עדיין בלי עסקאות, מתעדכנת במקום
$strategy['thesis'] = 'מומנטום אחרי דחיסה, בשוק עולה';
[, $r] = call('alice', 'strategy_save', $strategy);
eq([$r['new_version'], count($r['strategy']['versions'])], [false, 2], 'v2 without trades → in place');

[, $r] = call('alice', 'strategies');
eq([count($r['strategies']), $r['strategies'][0]['trades'], $r['strategies'][0]['stats']['win_rate'], $r['strategies'][0]['version_no']],
    [1, 1, '100', 2], 'list summary');
eq(call('alice', 'strategy_delete', ['id' => $sid])[0], 422, 'strategy with trades cannot be deleted');

// ── משתמש B לא רואה ולא משנה את של A ────────────────
[$c, $r] = call('bob', 'strategies');
eq([$c, $r['strategies']], [200, []], 'bob sees no strategies');
eq(call('bob', 'trades')[1]['trades'], [], 'bob sees no trades');
eq(call('bob', 'instruments')[1]['instruments'], [], 'bob sees no instruments');
eq(call('bob', 'strategy', null, ['id' => $sid])[0], 404, 'bob GET alice strategy by id → 404');
eq(call('bob', 'trade', null, ['id' => $tid])[0], 404, 'bob GET alice trade by id → 404');
eq(call('bob', 'strategy_save', ['id' => $sid, 'name' => 'נגנב'] + $strategy)[0], 404, 'bob edit alice strategy → 404');
eq(call('bob', 'strategy_status', ['id' => $sid, 'status' => 'archived'])[0], 404, 'bob archive alice strategy → 404');
eq(call('bob', 'strategy_duplicate', ['id' => $sid])[0], 404, 'bob duplicate alice strategy → 404');
eq(call('bob', 'strategy_delete', ['id' => $sid])[0], 404, 'bob delete alice strategy → 404');
eq(call('bob', 'trade_save', ['id' => $tid] + $trade)[0], 404, 'bob edit alice trade → 404');
eq(call('bob', 'trade_delete', ['id' => $tid])[0], 404, 'bob delete alice trade → 404');
eq(call('bob', 'trade_save', ['strategy_version_id' => $v2] + $trade)[0], 404, 'bob attach trade to alice version → 404');
$aliceInst = call('alice', 'instruments')[1]['instruments'][0]['id'];
eq(call('bob', 'instrument_save', ['id' => $aliceInst, 'currency' => 'EUR', 'multiplier' => '9'])[0], 404, 'bob edit alice instrument → 404');
eq(call('bob', 'instrument_delete', ['id' => $aliceInst])[0], 404, 'bob delete alice instrument → 404');
eq(call('bob', 'trades', null, ['strategy_id' => (string)$sid])[1]['trades'], [], 'bob filter by alice strategy id → nothing');
[, $r] = call('alice', 'strategy', null, ['id' => $sid]);
eq([$r['strategy']['name'], $r['strategy']['status']], ['פריצת טווח', 'active'], 'alice strategy untouched');
eq(call('alice', 'trade', null, ['id' => $tid])[1]['trade']['calc']['net'], '73', 'alice trade untouched');
$bobExport = call('bob', 'export', null, ['format' => 'json'])[1];
eq([$bobExport['strategies'], $bobExport['trades']], [[], []], 'bob export has nothing of alice');

// בוב יוצר אצלו — נפרד לגמרי (אותו סמל, נכס נפרד)
[$c, $r] = call('bob', 'trade_save', ['strategy_version_id' => null, 'checklist' => []] + $trade);
eq([$c, $r['trade']['strategy_id'], $r['trade']['checklist']], [200, null, []], 'bob trade without strategy');
eq(count(call('alice', 'trades')[1]['trades']), 1, 'alice still sees only hers');

// ── ולידציה ──────────────────────────────────────────
$bad = fn(array $over) => call('alice', 'trade_save', $over + $trade);
eq($bad(['exits' => [['exited_at' => '2026-03-03T17:00', 'price' => '110', 'quantity' => '11']]])[0], 422, 'exit qty > entry qty');
eq($bad(['planned_stop' => '105'])[0], 422, 'long with stop above entry');
eq($bad(['planned_stop' => '100'])[0], 422, 'stop equal to entry');
eq($bad(['entry_price' => 100.5])[0], 422, 'float price refused');
eq($bad(['entry_price' => '1e3'])[0], 422, 'exponent refused');
eq($bad(['exits' => [['exited_at' => '2026-03-01T10:00', 'price' => '110', 'quantity' => '1']]])[0], 422, 'exit before entry');
eq($bad(['market' => 'futures', 'multiplier' => ''])[0], 422, 'futures require multiplier');
eq($bad(['market' => 'options'])[0], 422, 'options require option fields');
eq($bad(['rating' => 9])[0], 422, 'rating range');
eq($bad(['opened_at' => '2026-02-30T10:00'])[0], 422, 'invalid date');

// קריפטו בשמונה ספרות ואופציה עם כל השדות, דרך ה-API
[$c, $r] = call('alice', 'trade_save', ['symbol' => 'BTC/USDT', 'market' => 'crypto', 'currency' => 'USDT', 'direction' => 'long',
    'opened_at' => '2026-03-05T10:00', 'entry_price' => '60000.12345678', 'quantity' => '0.00123456', 'planned_stop' => '59000',
    'exits' => [['exited_at' => '2026-03-06T10:00', 'price' => '61000.87654321', 'quantity' => '0.00123456', 'fees' => '0.00000001']]]);
eq([$c, $r['trade']['calc']['gross'], $r['trade']['calc']['net']], [200, '1.2354897303830208', '1.2354897203830208'], 'crypto via API, exact (Python Decimal agrees)');
[$c, $r] = call('alice', 'trade_save', ['symbol' => 'SPY 2026-12-18 500C', 'market' => 'options', 'currency' => 'USD', 'direction' => 'long',
    'option' => ['type' => 'call', 'strike' => '500', 'expiry' => '2026-12-18', 'underlying' => 'spy'],
    'opened_at' => '2026-03-05T10:00', 'entry_price' => '2.35', 'quantity' => '3', 'entry_fees' => '1.95', 'planned_stop' => '1.35',
    'exits' => [['exited_at' => '2026-03-06T10:00', 'price' => '3.10', 'quantity' => '3', 'fees' => '1.95']]]);
eq([$c, $r['trade']['multiplier'], $r['trade']['option']['underlying'], $r['trade']['calc']['r']], [200, '100', 'SPY', '0.74'], 'option via API');
$optInst = $r['trade']['instrument_id'];
// עריכת המכפיל ב"נכסים שלי" — לא משנה עסקה שכבר נרשמה
call('alice', 'instrument_save', ['id' => $optInst, 'currency' => 'USD', 'multiplier' => '10',
    'option' => ['type' => 'call', 'strike' => '500', 'expiry' => '2026-12-18', 'underlying' => 'SPY']]);
eq(call('alice', 'trade', null, ['id' => $r['id']])[1]['trade']['calc']['net'], '221.1', 'instrument edit keeps old trade P/L');
eq(call('alice', 'instrument_delete', ['id' => $optInst])[0], 422, 'instrument with trades cannot be deleted');

// ── סטטיסטיקה וייצוא ─────────────────────────────────
[$c, $r] = call('alice', 'stats');
eq([$c, array_column($r['by_currency'], 'currency')], [200, ['USD', 'USDT']], 'stats per currency');
[, $r] = call('alice', 'stats', null, ['strategy_id' => (string)$sid, 'version_id' => (string)$v1]);
eq([$r['by_currency'][0]['count'], $r['by_currency'][0]['expectancy_r']], [1, '1.46'], 'stats by version');
eq(call('alice', 'stats', null, ['version_id' => (string)$v2])[1]['by_currency'], [], 'v2 has no trades');
eq(count(call('alice', 'trades', null, ['from' => '2026-03-05', 'to' => '2026-03-05'])[1]['trades']), 2, 'journal date filter');
eq(count(call('alice', 'trades', null, ['result' => 'win', 'market' => 'crypto'])[1]['trades']), 1, 'journal result+market filter');
[$c, $csv] = call('alice', 'export', null, ['format' => 'csv']);
eq([$c, substr_count($csv, "\n")], [200, 4], 'csv: header + 3 trades');
eq(str_starts_with($csv, "\u{FEFF}id,"), true, 'csv BOM for Excel');

// כתיבת טקסט עם נוסחה — מנוטרלת ב-CSV
call('alice', 'trade_save', ['id' => $tid, 'went_well' => '=HYPERLINK("http://x")'] + $trade);
eq(str_contains(call('alice', 'export', null, ['format' => 'csv'])[1], "\"'=HYPERLINK"), true, 'csv formula neutralized');

// שכפול וארכיון
[$c, $r] = call('alice', 'strategy_duplicate', ['id' => $sid]);
$dup = call('alice', 'strategy', null, ['id' => $r['id']])[1]['strategy'];
eq([$dup['name'], $dup['status'], count($dup['versions']), count($dup['versions'][0]['entry_rules'])],
    ['פריצת טווח (עותק)', 'draft', 1, 3], 'duplicate = current version as v1');
eq(call('alice', 'strategy_delete', ['id' => $r['id']])[0], 200, 'strategy without trades can be deleted');
eq(call('alice', 'strategy_status', ['id' => $sid, 'status' => 'archived'])[0], 200, 'archive');

// הגדרות
eq(call('alice', 'settings', ['base_currency' => 'ils', 'timezone' => 'Europe/London', 'account_size' => '50000', 'default_risk_pct' => '0.5'])[0], 200, 'settings');
eq(call('alice', 'trade', null, ['id' => $tid])[1]['trade']['opened_at'], '2026-03-02T14:30', 'times follow new timezone');
eq(call('alice', 'settings', ['base_currency' => 'ILS', 'timezone' => 'Mars/Base', 'default_risk_pct' => '1'])[0], 422, 'bad timezone');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
