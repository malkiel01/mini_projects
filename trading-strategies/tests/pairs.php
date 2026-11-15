<?php
// בדיקות S1 (spec-stage-a 18.1) — בחירת זוג בשם: קטלוג, זוג מחושב, זוג בלי ספק, תוויות בלי סימול.
// php trading-strategies/tests/pairs.php — בלי רשת: הספקים עונים מ-tests/fixtures/market.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-pairs-unit-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DEV', true);
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
define('TS_SECRET_KEY_PATH', "$tmp.key");
putenv('TS_HTTP_FIXTURES=' . __DIR__ . '/fixtures/market');
putenv("TS_HTTP_LOG=$tmp.http.log");
register_shutdown_function(fn() => array_map('unlink', glob("$tmp*") ?: []));

require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/market.php';
require __DIR__ . '/../lib/analysis.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function calls(): int { global $tmp; return is_file("$tmp.http.log") ? count(file("$tmp.http.log")) : 0; }

ts_db()->prepare("INSERT INTO users (platform_sub, display_name, created_at) VALUES ('1', 'A', 0), ('2', 'B', 0)")->execute();

// ── קריטריון 5: כל רשומה בקטלוג מצביעה ל-provider:symbol שקיים במתאם ──
$bad = [];
foreach (ts_pairs_catalog() as $key => $p) {
    foreach ($p['assets'] as $asset) {
        [$prov, $sym] = ts_asset_split($asset);
        $base = str_starts_with($sym, '1/') ? substr($sym, 2) : $sym;
        $ok = match ($prov) {
            // Binance ו-ECB: החיפוש של המתאם (על האינדקס / רשימת המטבעות) מחזיר בדיוק את הסימול
            'binance', 'ecb' => in_array($base, array_column(ts_provider($prov)->search($base, null), 'symbol'), true),
            // Twelve Data: הסימול בצורה של הספק (BTC/USD), והמתאם נותן את השוק של הזוג
            'twelvedata' => (bool)preg_match('#^[A-Z]{3,5}/[A-Z]{3}$#', $base)
                && in_array(ts_asset_market_of_pair($key), ts_provider($prov)->meta()['markets'], true),
            default => false,
        };
        if (!$ok) $bad[] = $asset;
    }
}
function ts_asset_market_of_pair(string $key): string { return TS_PAIR_SIDES[explode('/', $key)[0]][2] === 'crypto' ? 'crypto' : 'forex'; }
eq($bad, [], 'criterion 5: every catalog entry points to a provider:symbol the adapter has');
eq(count(ts_pairs_catalog()) >= 40, true, 'the catalog: common crypto vs dollar and tether, and main forex in both directions');

// ── "מה" ו"מול מה" (18.1): רק מה שיש לו ספק למשתמש; ביטקוין ראשון ──
$noKey = ['binance' => true, 'ecb' => true];        // בלי מפתח אישי — רק הספקים הפתוחים
$f = ts_pairs_for(1, $noKey);
eq([$f['default'], $f['bases'][0]['code'], $f['bases'][0]['name']], ['BTC', 'BTC', 'ביטקוין'], 'Bitcoin is first and the default (18.1)');
$btc = $f['bases'][0];
eq(array_map(fn($q) => [$q['name'], $q['label'], $q['asset']], $btc['quotes']),
    [['דולר', 'ביטקוין מול דולר', 'binance:BTCUSDC'], ['טתר', 'ביטקוין מול טתר', 'binance:BTCUSDT']], 'Bitcoin: vs dollar and vs tether, from Binance');
$usd = array_values(array_filter($f['bases'], fn($b) => $b['code'] === 'USD'))[0];
eq(in_array('שקל', array_column($usd['quotes'], 'name'), true), true, 'dollar: shekel is in "vs what"');
eq(in_array('ביטקוין', array_column($usd['quotes'], 'name'), true), false, '"vs what" lists only pairs that exist ("dollar vs bitcoin" is not one)');
// עם מפתח של Twelve Data — ביטקוין מול דולר אמיתי (USD), לא USDC
eq(ts_pair_asset(1, 'BTC/USD', ['binance' => true, 'ecb' => true, 'twelvedata' => true]), 'twelvedata:BTC/USD', 'with a Twelve Data key: real BTC/USD first');
// בלי שום ספק — הזוג לא מופיע ב"מול מה"
$none = ts_pairs_for(1, []);
eq(array_sum(array_map(fn($b) => count($b['quotes']), $none['bases'])), 0, 'no provider → no pair is offered');

// ── קריטריון 3: זוג בלי ספק — הודעה, לא שגיאה, ולא נשלחת בקשה לספק ──
$before = calls();
$p = ts_pair_parse('ביטקוין מול ין');
eq([$p['pair'], $p['known'], $p['label']], ['BTC/JPY', false, 'ביטקוין מול ין יפני'], 'criterion 3: "Bitcoin vs Yen" is recognized, and there is no such pair');
eq(ts_pair_asset(1, 'BTC/JPY'), null, 'criterion 3: no asset for it');
eq(calls() - $before, 0, 'criterion 3: no request to any provider');
eq(ts_pair_parse('bitcoin vs dollar')['pair'], 'BTC/USD', 'search in English');
eq(ts_pair_parse('דולר מול שקל')['pair'], 'USD/ILS', 'search in Hebrew');
eq(ts_pair_parse('usd/ils')['pair'], 'USD/ILS', 'search by codes');
eq(ts_pair_parse('קריפטו מול דולר'), null, '"crypto" is not an asset (18.1) — not recognized');

// ── קריטריון 2: "דולר מול שקל" ו"שקל מול דולר" — שני זוגות, ההופכי ──
eq([ts_pair_asset(1, 'USD/ILS', $noKey), ts_pair_asset(1, 'ILS/USD', $noKey)], ['ecb:USDILS', 'ecb:ILSUSD'], 'criterion 2: two pairs; ECB gives both directions');
// כשלספק רק כיוון אחד — ההפוך מחושב ומסומן
$built = ts_pairs_build(['USD/ILS' => ['ecb:USDILS'], 'BTC/USDT' => ['binance:BTCUSDT']]);
eq([$built['ILS/USD'] ?? null, isset($built['USDT/BTC'])], [['assets' => ['ecb:1/USDILS'], 'computed' => true], false],
    'criterion 2: one direction only → the reverse is computed (1/x) and marked; only between currencies');
$now = gmmktime(12, 0, 0, 3, 5, 2026);
$a = ts_market_candles(1, 'ecb:USDILS', 'd1', gmmktime(0, 0, 0, 3, 2, 2026), gmmktime(0, 0, 0, 3, 4, 2026), $now);
$before = calls();
$b = ts_market_candles(1, 'ecb:1/USDILS', 'd1', gmmktime(0, 0, 0, 3, 2, 2026), gmmktime(0, 0, 0, 3, 4, 2026), $now);
eq(calls() - $before, 0, 'the computed pair uses the same pool (no new request)');
eq(array_map(fn($r) => $r['c'], $a['candles']), ['3.6512', '3.6408', '3.65'], 'the original pair');
eq(array_map(fn($r) => $r['c'], $b['candles']), ['0.273882559', '0.274664909', '0.273972603'], 'criterion 2: the computed chart is 1/x of the first');
eq([$b['computed'], $b['stream'], $b['asset']], [true, null, 'ecb:1/USDILS'], 'computed: marked, no live stream (it would be the other direction)');
$hl = ts_pair_invert_candles([['t' => 0, 'o' => '2', 'h' => '4', 'l' => '1', 'c' => '2', 'v' => '5']])[0];
eq([$hl['h'], $hl['l']], ['1', '0.25'], 'computed: high = 1/low, low = 1/high');

// ── קריטריון 1: תווית בלי סימול ──
$lb = ts_pair_label('binance:BTCUSDT');
eq($lb['label'], 'ביטקוין מול טתר', 'label from the catalog');
eq(str_contains(ts_pair_label('ecb:USDILS')['label'], 'USDILS'), false, 'criterion 1: the label has no symbol');
// מניה מהחיפוש — שם החברה מול המטבע
ts_cred_save(1, 'twelvedata', 'good-alice-0001', 'א');
ts_market_search(1, 'AAPL');
eq(ts_pair_label('twelvedata:AAPL')['label'], 'Apple Inc מול דולר', 'a stock from the search: company name vs currency');
$r = ts_market_search(1, 'AAPL')['results'][0];
eq([$r['label'], $r['computed']], ['Apple Inc מול דולר', false], 'search results carry the label');
$c = ts_market_candles(1, 'binance:BTCUSDT', 'h1', time() - 3 * 3600, time());
eq($c['label'], 'ביטקוין מול טתר', 'market_candles carries the label (chart header)');
ts_watchlist_add(1, 'binance:BTCUSDT');
eq(ts_watchlist(1)[0]['label'], 'ביטקוין מול טתר', 'the watchlist carries the label');
eq(ts_asset_market('twelvedata:BTC/USD'), 'crypto', 'a catalog pair without search details: market from the first side');

// ── קריטריון 4: הסימול נגיש למנהל (מסך המפתח) ──
$adm = array_values(array_filter(ts_pairs_admin(), fn($x) => $x['pair'] === 'BTC/USDT'))[0];
eq([$adm['label'], $adm['assets']], ['ביטקוין מול טתר', ['binance:BTCUSDT']], 'criterion 4: the admin catalog shows the symbols');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
