<?php
// ספירות אליוט על סט קבוע (spec-stage-a 18.7, S7, קריטריון 1). טהור — בלי מסד, בלי רשת.
// php trading-strategies/tests/elliott-counts.php
//
// הסט (tests/fixtures/elliott/sets.php) קבוע; הספירות הצפויות — tests/fixtures/elliott/counts.json. כל שינוי בספירה, בתרחיש
// או באותות של elliott-w3 על אותם נרות **מכשיל** את הבדיקה, עד שהקובץ הצפוי מתעדכן:
//   UPDATE_COUNTS=1 php trading-strategies/tests/elliott-counts.php
// וכך השינוי נראה ב-diff של ה-PR. שינוי כזה = "משנה ספירה: כן" בתיאור ה-PR, והבקרה לא ממזגת בלי אישור הבעלים (✅24).
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-ew-counts-unused-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite');   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/methods/elliott-w3.php';

$sets = require __DIR__ . '/fixtures/elliott/sets.php';
$file = __DIR__ . '/fixtures/elliott/counts.json';

/** מספר בדיוק קבוע — כך הבדלי עיגול של float לא נראים כשינוי, ושינוי אמיתי כן */
function r6(float $v): string
{
    $s = number_format($v, 6, '.', '');
    return $s === '-0.000000' ? '0.000000' : $s;
}

$m = new TsMethodElliottW3();
$p = $m->params() + ['tick' => 0.01];
$got = [];
foreach ($sets as $name => [$candles, $tf]) {
    // ברירות המחדל של השיטה, ועוד רגישות 2 (כמו בבדיקות הגרף) — כדי שיותר חלונות ייתנו ספירה
    $sum = fn(float $mult) => array_map(fn($c) => [
        'id' => $c['id'], 'dir' => $c['dir'], 'k' => $c['k'], 'score' => r6($c['score']), 'words' => $c['words'],
        'pivots' => array_map(fn($x) => $x['type'] . '@' . $x['t'] . ':' . r6((float)$x['v']), $c['pivots']),
        'next' => ['wave' => $c['next']['wave'], 'zone' => array_map('r6', $c['next']['zone']), 'invalidation' => r6((float)$c['next']['invalidation'])],
    ], ts_ew_overlay($candles, $tf, ['pivot_atr_mult' => $mult, 'atr_len' => (int)$p['atr_len']])['counts']);
    $counts = $sum((float)$p['pivot_atr_mult']);
    $sensitive = $sum(2.0);
    $signals = array_map(fn($s) => $s['t'] . ' ' . $s['side'] . ' ' . r6($s['entry']) . ' ' . r6($s['stop']) . ' ' . r6((float)$s['target']), $m->signals($candles, $p));
    $got[$name] = ['bars' => count($candles), 'counts' => $counts, 'counts_sensitive' => $sensitive, 'signals' => $signals];
}
$json = json_encode($got, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (getenv('UPDATE_COUNTS') === '1') {
    file_put_contents($file, $json);
    echo "elliott-counts: עודכן " . basename($file) . ' (' . count($got) . " סטים)\n";
    exit(0);
}
$want = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
if (!is_array($want)) { echo "✗ אין קובץ צפוי — UPDATE_COUNTS=1 כדי ליצור אותו\n"; exit(1); }

$fail = 0;
foreach (array_unique([...array_keys($want), ...array_keys($got)]) as $name) {
    if (!isset($got[$name])) { $fail++; echo "✗ $name: הסט נעלם מ-sets.php\n"; continue; }
    if (!isset($want[$name])) { $fail++; echo "✗ $name: סט חדש בלי ספירה צפויה\n"; continue; }
    foreach (['bars', 'counts', 'counts_sensitive', 'signals'] as $k) {
        if ($got[$name][$k] === $want[$name][$k]) continue;
        $fail++;
        echo "✗ $name — $k השתנה:\n   היה: " . json_encode($want[$name][$k], JSON_UNESCAPED_UNICODE) . "\n   עכשיו: " . json_encode($got[$name][$k], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
$total = count($got);
if ($fail) {
    echo "\nהספירה השתנתה על הסט הקבוע ($fail הבדלים). זה שינוי מהותי (18.7): \"משנה ספירה: כן\" בתיאור ה-PR, ועדכון הקובץ הצפוי:\n"
        . "  UPDATE_COUNTS=1 php trading-strategies/tests/elliott-counts.php\n";
    exit(1);
}
echo "elliott-counts: $total/$total סטים — הספירות זהות לקובץ הצפוי\n";
