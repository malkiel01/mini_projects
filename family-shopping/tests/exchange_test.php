<?php
/**
 * בדיקות לשער היציג ולהמרת קנייה במטבע זר
 * tests/exchange_test.php
 *
 * בלי רשת: ההורדה מבנק ישראל מוחלפת בפונקציה שמחזירה CSV קבוע
 * וסופרת כמה פעמים נקראה - כדי לבדוק גם את המטמון.
 *
 * הרצה: php tests/exchange_test.php
 */

require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/exchange.php';

$pass = 0;
$fail = 0;

function check($label, $condition, $detail = '') {
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function makeDb() {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE group_purchases (id INTEGER PRIMARY KEY, amount REAL,
        currency TEXT, original_amount REAL, exchange_rate REAL, rate_date TEXT, rate_source TEXT)");
    $pdo->exec("CREATE TABLE exchange_rates (currency TEXT, rate_date TEXT, published_date TEXT,
        rate REAL, fetched_at TEXT, PRIMARY KEY (currency, rate_date))");

    return $pdo;
}

// התשובה של בנק ישראל, בדיוק במבנה שלה (העמודות בקיצור)
$csv = "SERIES_CODE,FREQ,BASE_CURRENCY,COUNTER_CURRENCY,UNIT_MULT,TIME_PERIOD,OBS_VALUE\n"
     . "RER_USD_ILS,D,USD,ILS,0,2026-10-21,3.091\n"
     . "RER_USD_ILS,D,USD,ILS,0,2026-10-22,3.107\n"
     . "RER_USD_ILS,D,USD,ILS,0,2026-10-23,3.112\n"
     . "RER_USD_ILS,D,USD,ILS,0,2026-10-26,3.078\n";

$calls = 0;
$urls  = [];
$fetch = function ($url) use (&$calls, &$urls, $csv) {
    $calls++;
    $urls[] = $url;
    return $csv;
};

// ------------------------------------------------------------
echo "\nפענוח\n";
// ------------------------------------------------------------

$rates = parseBoiCsv($csv);
check('כל הימים', count($rates) === 4 && $rates['2026-10-23'] === 3.112);

$jpy = parseBoiCsv("SERIES_CODE,UNIT_MULT,TIME_PERIOD,OBS_VALUE\nRER_JPY_ILS,2,2026-10-26,1.9557\n");
check('ין מתפרסם ל-100 - מנורמל ליחידה', abs($jpy['2026-10-26'] - 0.019557) < 1e-9);

check('תשובה ריקה או שבורה - כלום', parseBoiCsv('') === [] && parseBoiCsv("<html>error</html>") === []);
check('ערך לא מספרי מדולג', parseBoiCsv("TIME_PERIOD,OBS_VALUE\n2026-10-26,NaN\n2026-10-27,3.1\n") === ['2026-10-27' => 3.1]);

// ------------------------------------------------------------
echo "\nשער ליום\n";
// ------------------------------------------------------------

$pdo = makeDb();
$r   = exchangeRate($pdo, 'USD', '2026-10-22', $fetch, '2026-10-27');
check('יום עסקים - השער שלו', $r === ['rate' => 3.107, 'date' => '2026-10-22']);
check('השאלה לבנק היא על שבועיים אחורה', strpos($urls[0], 'RER_USD_ILS?startperiod=2026-10-08&endperiod=2026-10-22') !== false,
    $urls[0]);

$r = exchangeRate($pdo, 'USD', '2026-10-24', $fetch, '2026-10-27');
check('שבת - השער של יום שישי', $r === ['rate' => 3.112, 'date' => '2026-10-23']);

$before = $calls;
$r = exchangeRate($pdo, 'USD', '2026-10-24', $fetch, '2026-10-27');
check('פעם שנייה - מהמטמון, בלי לפנות לבנק', $calls === $before && $r['date'] === '2026-10-23');

$r = exchangeRate($pdo, 'USD', '2030-01-01', $fetch, '2026-10-27');
check('יום עתידי נחשב להיום', $r['date'] === '2026-10-26');

// היום, לפני שהשער פורסם: נשמר השער של אתמול - ונבדק שוב רק אחרי שעה
$before = $calls;
exchangeRate($pdo, 'USD', '2026-10-27', $fetch, '2026-10-27');
check('היום - נבדק שוב רק אחרי שעה', $calls === $before);
$pdo->exec("UPDATE exchange_rates SET fetched_at = '2000-01-01 00:00:00' WHERE rate_date = '2026-10-27'");
exchangeRate($pdo, 'USD', '2026-10-27', $fetch, '2026-10-27');
check('אחרי שעה - שואלים שוב, אולי פורסם', $calls === $before + 1);

$down = function () { return null; };
$r = exchangeRate($pdo, 'USD', '2026-10-27', $down, '2026-10-27');
check('הבנק לא עונה - השער שבמטמון', $r !== null && $r['rate'] === 3.078);
check('הבנק לא עונה ואין מטמון - null', exchangeRate(makeDb(), 'USD', '2026-10-20', $down, '2026-10-27') === null);

check('שקל ומטבע לא מוכר - null', exchangeRate($pdo, 'ILS', '2026-10-20', $fetch) === null
    && exchangeRate($pdo, 'XYZ', '2026-10-20', $fetch) === null);

// ------------------------------------------------------------
echo "\nקנייה מהטופס\n";
// ------------------------------------------------------------

$m = purchaseMoneyFromInput($pdo, ['amount' => '120.456']);
check('שקלים - כמו שהם, בלי פרטי המרה', $m['ok'] && $m['amount'] === 120.46 && $m['fields']['currency'] === null);

$m = purchaseMoneyFromInput($pdo, ['amount' => '20', 'currency' => 'usd', 'rate_date' => '2026-10-22'], $fetch);
check('דולר - מומר, והפרטים נשמרים', $m['ok'] && $m['amount'] === 62.14
    && $m['fields']['currency'] === 'USD' && $m['fields']['rate_date'] === '2026-10-22'
    && $m['fields']['rate_source'] === 'boi', json_encode($m));

$m = purchaseMoneyFromInput($pdo, ['amount' => '20', 'currency' => 'USD', 'rate_date' => '2026-10-22',
    'rate_mode' => 'manual', 'rate' => '3.2'], $fetch);
check('שער ידני גובר', $m['ok'] && $m['amount'] === 64.0 && $m['fields']['rate_source'] === 'manual');

$m = purchaseMoneyFromInput($pdo, ['amount' => '20', 'currency' => 'USD', 'rate_date' => '2026-10-22',
    'rate' => '99'], $fetch);
check('שער שהדפדפן שלח בלי "ידני" - מתעלמים ממנו', $m['ok'] && $m['amount'] === 62.14);

$m = purchaseMoneyFromInput(makeDb(), ['amount' => '20', 'currency' => 'USD', 'rate_date' => '2026-10-20'], $down);
check('אין שער - הודעה שמציעה שער ידני', !$m['ok'] && strpos($m['message'], 'ידני') !== false);

$m = purchaseMoneyFromInput($pdo, ['amount' => '0', 'currency' => 'USD']);
check('סכום אפס נדחה', !$m['ok']);

$m = purchaseMoneyFromInput($pdo, ['amount' => '0.01', 'currency' => 'JPY', 'rate_mode' => 'manual', 'rate' => '0.0001']);
check('סכום שיוצא אפס בשקלים נדחה', !$m['ok']);

$old = new PDO('sqlite::memory:');
$old->exec("CREATE TABLE group_purchases (id INTEGER PRIMARY KEY, amount REAL)");
$m = purchaseMoneyFromInput($old, ['amount' => '20', 'currency' => 'USD']);
check('לפני מיגרציה 017 - מטבע זר נדחה בהסבר', !$m['ok'] && strpos($m['message'], 'עדכון') !== false);
$m = purchaseMoneyFromInput($old, ['amount' => '20', 'currency' => 'ILS']);
check('לפני מיגרציה 017 - שקלים עובדים', $m['ok'] && $m['amount'] === 20.0);

// ------------------------------------------------------------
echo "\nתצוגה\n";
// ------------------------------------------------------------

check('סכום עם סימן', formatMoney(12.5, 'USD') === '$12.50' && formatMoney(3, null) === '₪3.00');
check('שער: ארבע ספרות, ובין שש לשבר קטן', formatRate(3.11200000) === '3.112' && formatRate(0.019557) === '0.019557');

$label = purchaseConversionLabel(['currency' => 'USD', 'original_amount' => 100, 'exchange_rate' => 3.112,
    'rate_date' => '2026-10-23', 'rate_source' => 'boi']);
check('תיאור ההמרה', $label === '$100.00 · שער יציג 3.112 (23/10/2026)', $label);
check('ברשימה - בלי הסכום המקורי', purchaseConversionLabel(['currency' => 'EUR', 'original_amount' => 40,
    'exchange_rate' => 3.6, 'rate_date' => '2026-10-27', 'rate_source' => 'manual'], false) === 'שער ידני 3.6 · 27/10');
check('קנייה בשקלים - בלי תיאור', purchaseConversionLabel(['currency' => null, 'original_amount' => null]) === '');

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";
exit($fail > 0 ? 1 : 0);
