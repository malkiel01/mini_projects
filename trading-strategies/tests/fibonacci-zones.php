<?php
// פיבונאצ'י על סט קבוע (spec-stage-a 22.1, S7; קריטריון 12 של F-1). טהור — בלי מסד, בלי רשת.
// php trading-strategies/tests/fibonacci-zones.php
//
// הסט (tests/fixtures/fibonacci/sets.php) קבוע; הצפוי — tests/fixtures/fibonacci/zones.json: נקודות ההתחלה, הטווחים,
// האזורים (גבולות וציון) והרמות הבודדות של שני הצדדים, ואותות השיטה (בברירות המחדל וברגישות 2). כל שינוי בטווחים,
// באזורים או באותות **מכשיל** את הבדיקה, עד שהקובץ הצפוי מתעדכן:
//   UPDATE_ZONES=1 php trading-strategies/tests/fibonacci-zones.php
// וכך השינוי נראה ב-diff של ה-PR — שינוי מהותי (S7, ✅24), כמו "משנה ספירה" באליוט.
// F-2 (22.5ג): גם A-B-X, T של כל קופסה, Z6 בציון, ואותות עם יעד חלופי (קופסה) — "משנה ספירה: כן — מתוכנן ב-22.5ג".
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-fib-zones-unused-' . getmypid() . '.sqlite');   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';

$sets = require __DIR__ . '/fixtures/fibonacci/sets.php';
$file = __DIR__ . '/fixtures/fibonacci/zones.json';

/** מספר בדיוק קבוע — הבדלי עיגול של float לא נראים כשינוי, ושינוי אמיתי כן */
function r6(float $v): string
{
    $s = number_format($v, 6, '.', '');
    return $s === '-0.000000' ? '0.000000' : $s;
}

$m = ts_method('fibonacci');
$got = [];
foreach ($sets as $name => [$candles, $tf, $over]) {
    $p = $m->params($over);
    $g = ts_fib_grid_of($candles, $p);
    $side = fn(array $s) => [
        'start' => $s['start'] ? r6($s['start']['price']) . '@' . $s['start']['t'] : null,
        'ranges' => array_map(fn($r) => r6($r['end']) . '@' . $r['end_t'] . ' ' . implode('+', $r['kinds']), $s['ranges']),
        'dropped' => array_map(fn($r) => r6($r['end']) . '@' . $r['end_t'], $s['dropped']),
        // F-2: הציון כולל Z6 (מסומן), ו-T של קופסת השוויון (22.5ב)
        'zones' => array_map(fn($z) => r6($z['lo']) . '–' . r6($z['hi']) . ' ציון ' . $z['score'] . ($z['z6'] ? ' (Z6)' : '') . ' (' . count($z['levels']) . '/' . $z['ranges'] . ')'
            . ($z['box'] ? ' T ' . r6($z['box']['T']) : ''), $s['zones']),
        'singles' => array_map(fn($l) => r6($l['price']), $s['singles']),
    ];
    $sig = fn(array $pp) => array_map(fn($s) => $s['t'] . ' ' . $s['side'] . ' ' . r6($s['entry']) . ' ' . r6($s['stop']) . ' ' . r6($s['target']) . ' ציון ' . $s['score']
        . ($s['via'] === 'box' ? ' יעד: קופסה' : ''), $m->signals($candles, $pp));
    $abx = $g['abx'] ?? null;
    $got[$name] = ['bars' => count($candles), 'support' => $g ? $side($g['support']) : null, 'resistance' => $g ? $side($g['resistance']) : null,
        'abx' => $abx ? $abx['dir'] . ' ' . implode(' / ', array_map(fn($x) => r6($x['p']), [$abx['a'], $abx['b'], $abx['x']])) . ' → '
            . implode(' / ', array_map(fn($t) => r6($t['price']), $abx['targets'])) : null,
        'signals' => $sig($p), 'signals_sensitive' => $sig($m->params($over + ['pivot_atr_mult' => 2, 'min_score' => 2, 'min_rr' => 1]))];
}
$json = json_encode($got, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (getenv('UPDATE_ZONES') === '1') {
    file_put_contents($file, $json);
    echo 'fibonacci-zones: עודכן ' . basename($file) . ' (' . count($got) . " סטים)\n";
    exit(0);
}
$want = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
if (!is_array($want)) { echo "✗ אין קובץ צפוי — UPDATE_ZONES=1 כדי ליצור אותו\n"; exit(1); }

$fail = 0;
foreach (array_unique([...array_keys($want), ...array_keys($got)]) as $name) {
    if (!isset($got[$name])) { $fail++; echo "✗ $name: הסט נעלם מ-sets.php\n"; continue; }
    if (!isset($want[$name])) { $fail++; echo "✗ $name: סט חדש בלי צפוי\n"; continue; }
    foreach (['bars', 'support', 'resistance', 'abx', 'signals', 'signals_sensitive'] as $k) {
        if ($got[$name][$k] === $want[$name][$k]) continue;
        $fail++;
        echo "✗ $name — $k השתנה:\n   היה: " . json_encode($want[$name][$k], JSON_UNESCAPED_UNICODE) . "\n   עכשיו: " . json_encode($got[$name][$k], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
$total = count($got);
if ($fail) {
    echo "\nהטווחים / האזורים / האותות השתנו על הסט הקבוע ($fail הבדלים). זה שינוי מהותי (S7): לציין בתיאור ה-PR, ולעדכן:\n"
        . "  UPDATE_ZONES=1 php trading-strategies/tests/fibonacci-zones.php\n";
    exit(1);
}
echo "fibonacci-zones: $total/$total סטים — זהים לקובץ הצפוי\n";
