<?php
// לשוניות במסך השוק (spec-stage-a 18.1.4, S1.2): ברירת מחדל, שמירה וסדר, הסרה והחזרה, לשונית משלי, מחיר מהמאגר בלבד,
// ובלי מפתח Twelve Data (ש7). php trading-strategies/tests/tabs-api.php — php -S עם שער מדומה וספקים מדומים. בלי רשת.
// הצד של הדפדפן (גרירה, combobox) — tests/market-tabs-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-tabs-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;   // "עכשיו" קבוע (X-Dev-Now) — אותם נרות מדומים בכל ריצה; חצי שעה אחרי שעה עגולה
/** [קוד, JSON מפוענח, הגוף כמו שהגיע] */
function call(string $as, string $action, ?array $body = null, array $query = [], ?int $now = null): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", 'X-Dev-Now: ' . ($now ?? $NOW)];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw, $raw];
}
function httpCalls(): int
{
    global $tmp;
    return count(array_filter(explode("\n", (string)@file_get_contents("$tmp.http.log"))));
}
foreach (['alice', 'bob'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];

// ── ברירת מחדל: מועדפים ראשונה, ואחריה כל המובנות; בפרופיל ──
[$c, $r] = call('alice', 'market_tabs');
eq([$c, array_column($r['tabs'], 'id'), $r['removed']], [200, ['favorites', 'crypto', 'fx', 'nasdaq100', 'sp500', 'indices', 'commodities'], []], 'default: ⭐ favorites first, then the built-in tabs');
eq($r['tabs'][0]['name'], '⭐ מועדפים', 'the first tab is "⭐ מועדפים"');
eq(array_column(call('alice', 'profile')[1]['profile']['market_tabs'], 'id')[0], 'favorites', 'market_tabs is in the profile (chapter 5)');

// ── קריטריון 2–3: קריפטו — 20 שורות בתווית של S1.1, בלי פנייה לספק; אין במאגר → null ("—") ──
$before = httpCalls();
[$c, $t] = call('alice', 'market_tab', null, ['id' => 'crypto']);
eq([$c, count($t['rows']), $t['needs']], [200, 20, null], 'criterion 2: the crypto tab has 20 rows and needs no key');
eq([$t['rows'][0]['label'], $t['rows'][0]['base']['name'], $t['rows'][0]['asset'], $t['rows'][0]['locked']], ['BTC ⇄ USDT', 'ביטקוין', 'binance:BTCUSDT', false], 'each row is an S1.1 label with its asset');
eq([$t['rows'][5]['price'], $t['rows'][5]['change_pct']], [null, null], 'criterion 3: nothing in the store → no price ("—")');
eq(httpCalls() - $before, 0, 'criterion 3: opening a tab sends no request to any provider');
foreach (['fx', 'nasdaq100', 'sp500', 'indices', 'commodities'] as $id) call('alice', 'market_tab', null, ['id' => $id]);
eq(httpCalls() - $before, 0, 'criterion 3: no request for any built-in tab');

// מחיר מהמאגר: אחרי שהגרף נטען, השורה מציגה את המחיר והשינוי — עדיין בלי פנייה נוספת
call('alice', 'market_candles', null, ['asset' => 'binance:BTCUSDT', 'tf' => 'h1', 'from' => $NOW - 3 * 86400, 'to' => $NOW]);
$mid = httpCalls();
$btc = call('alice', 'market_tab', null, ['id' => 'crypto'])[1]['rows'][0];
eq([is_string($btc['price']), is_string($btc['change_pct']), preg_match('/^-?\d+\.\d\d$/', (string)$btc['change_pct'])], [true, true, 1], 'with candles in the store: a price and a daily change (two decimals)');
eq(httpCalls() - $mid, 0, 'reading the price from the store — no provider request');

// ── ש7: בלי מפתח Twelve Data — הלשונית מוצגת, needs, שורות נעולות, בלי שגיאה ──
[$c, $sp] = call('alice', 'market_tab', null, ['id' => 'sp500']);
eq([$c, $sp['needs']['provider'] ?? null, count($sp['rows']) > 400, $sp['rows'][0]['locked'], $sp['rows'][0]['label']], [200, 'twelvedata', true, true, 'AAPL ⇄ USD'], 'ש7: no Twelve Data key → the tab shows, rows are locked, labels are there');
$ix = call('alice', 'market_tab', null, ['id' => 'indices'])[1];
eq(in_array('תל אביב 35', array_map(fn($r) => $r['base']['name'], $ix['rows']), true), true, 'indices include Tel Aviv 35');
eq(call('alice', 'pair_labels', null, ['assets' => 'twelvedata:XAU/USD'])[1]['labels']['twelvedata:XAU/USD']['name'], 'זהב ⇄ דולר', 'a commodity from the list gets a label without a search (chart title)');
// עם מפתח — לא נעולה
eq(call('bob', 'cred_save', ['provider' => 'twelvedata', 'secret' => 'good-key-1234'])[0], 200, 'bob connects Twelve Data');
$sp = call('bob', 'market_tab', null, ['id' => 'sp500'])[1];
eq([$sp['needs'], $sp['rows'][0]['locked']], [null, false], 'with a key: not locked');

// ── קריטריון 4: סדר נשמר; מועדפים לא זזה ולא נמחקת ──
$tabs = call('alice', 'market_tabs')[1]['tabs'];
$re = [$tabs[3], $tabs[1], $tabs[0], $tabs[2], $tabs[4], $tabs[5], $tabs[6]];   // מועדפים "באמצע"
[$c, $r] = call('alice', 'market_tabs_save', ['tabs' => $re]);
eq([$c, array_column($r['tabs'], 'id')], [200, ['favorites', 'nasdaq100', 'crypto', 'fx', 'sp500', 'indices', 'commodities']], 'criterion 4: the new order is saved, and favorites stays first');
eq(array_column(call('alice', 'market_tabs')[1]['tabs'], 'id')[1], 'nasdaq100', 'criterion 4: the order survives a reload');
$noFav = array_values(array_filter($r['tabs'], fn($t) => $t['id'] !== 'favorites'));
eq(array_column(call('alice', 'market_tabs_save', ['tabs' => $noFav])[1]['tabs'], 'id')[0], 'favorites', 'criterion 4: favorites cannot be removed');

// ── קריטריון 5: הסרה של S&P 500 → ב-removed → חוזרת ──
$without = array_values(array_filter($r['tabs'], fn($t) => $t['id'] !== 'sp500'));
$r = call('alice', 'market_tabs_save', ['tabs' => $without])[1];
eq([in_array('sp500', array_column($r['tabs'], 'id'), true), array_column($r['removed'], 'id')], [false, ['sp500']], 'criterion 5: S&P 500 removed — it is offered under "➕ לשונית"');
$r = call('alice', 'market_tabs_save', ['tabs' => [...$r['tabs'], ['id' => 'sp500', 'kind' => 'builtin']]])[1];
eq([end($r['tabs'])['id'], $r['removed']], ['sp500', []], 'criterion 5: added back');
// לשונית משלי עם 3 נכסים
$mine = ['id' => 'c1a2b', 'kind' => 'custom', 'name' => 'שלי', 'assets' => ['binance:BTCUSDT', 'ecb:USDILS', 'twelvedata:AAPL']];
[$c, $r] = call('alice', 'market_tabs_save', ['tabs' => [...$r['tabs'], $mine]]);
eq([$c, end($r['tabs'])['name'], end($r['tabs'])['assets']], [200, 'שלי', $mine['assets']], 'criterion 5: a custom tab with 3 assets is saved');
$cm = call('alice', 'market_tab', null, ['id' => 'c1a2b'])[1];
eq(array_column($cm['rows'], 'label'), ['BTC ⇄ USDT', 'USD ⇄ ILS', 'AAPL ⇄ USD'], 'criterion 5: and shown, each as an S1.1 label');
eq(array_column($cm['rows'], 'locked'), [false, false, true], 'a custom tab: an asset without a provider is locked (ש7), the rest open');
eq(call('bob', 'market_tab', null, ['id' => 'c1a2b'])[0], 404, 'another user does not see the custom tab');

// ── מגבלות וקלט ──
$bad = fn($tabs) => call('alice', 'market_tabs_save', ['tabs' => $tabs])[0];
$base = call('alice', 'market_tabs')[1]['tabs'];
eq([$bad([['id' => 'nope', 'kind' => 'builtin']]), $bad([$base[1], $base[1]]), $bad('x'),
    $bad([['id' => 'c1', 'kind' => 'custom', 'name' => '', 'assets' => []]]),
    $bad([['id' => 'c1', 'kind' => 'custom', 'name' => 'x', 'assets' => ['nope:1']]]),
    $bad([['id' => '../x', 'kind' => 'custom', 'name' => 'x', 'assets' => []]]),
    $bad([['id' => 'c1', 'kind' => 'custom', 'name' => 'x', 'assets' => array_map(fn($i) => "binance:X{$i}USDT", range(1, 101))]]),
], [422, 422, 422, 422, 422, 422, 422], 'unknown / duplicate / bad list / empty name / bad asset / bad id / more than 100 assets → 422');
$twelve = array_map(fn($i) => ['id' => "c$i", 'kind' => 'custom', 'name' => "ל$i", 'assets' => []], range(1, 12));
eq($bad($twelve), 422, 'more than 12 tabs (with favorites) → 422');
eq($bad(array_slice($twelve, 0, 11)), 200, '12 tabs with favorites — OK');
eq(call('alice', 'market_tabs_save', ['tabs' => []], [])[0], 200, 'an empty list leaves only favorites');
// נתונים חיים: עמודה פגומה במסד לא מפילה את המסך
$db = new PDO("sqlite:$tmp.sqlite");
$db->exec("UPDATE users SET market_tabs = '{bad' WHERE display_name = 'אליס' OR 1");
eq(array_column(call('alice', 'market_tabs')[1]['tabs'], 'id')[0], 'favorites', 'a broken saved value falls back to the default');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
