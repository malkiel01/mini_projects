<?php
// הסט הקבוע לשכלול של פיבונאצ'י (spec-stage-a 22.1, S7; קריטריון 11 של F-1). **לא משנים סט קיים** — רק מוסיפים חדש
// (ואז מעדכנים את zones.json באותו PR). name → [נרות float ממוינים, אורך הנר בשניות, פרמטרים מעבר לברירות המחדל].
require_once __DIR__ . '/bars.php';
require_once dirname(__DIR__) . '/market/binance-klines.php';
require_once dirname(__DIR__, 3) . '/lib/backtest.php';

$H = 3600;
$sig = fib_bars([280, 230, 250, 120, 175, 150, [155, 'body' => 5], 200, 185]);
$sig[] = ['t' => $sig[count($sig) - 1]['t'] + $H, 'o' => 185.0, 'h' => 185.5, 'l' => 169.3, 'c' => 175.0];
$sets = [
    // הדוגמאות של קריטריונים 1–4 ושל האות (tests/fibonacci.php)
    'example-22.4' => [fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], 200, 185]), $H, []],
    'deleted-before-h' => [fib_bars([140, 120, 175, 150, 170, 160, 168, 155, 196, [199, 'body' => 3], 200, 185]), $H, []],
    'deleted-after-h' => [fib_bars([140, 120, 175, 150, 170, 160, 196, [199, 'body' => 3], 200, 155, 185]), $H, []],
    'wick-210' => [fib_bars([140, 120, 175, 150, 196, [199, 'body' => 3], [200, 'wick' => 210], 185]), $H, []],
    'both-sides' => [fib_bars([140, 100, 200, 160, 170]), $H, []],
    'long-signal' => [$sig, $H, ['lookback_bars' => 500]],
];

// נרות שעה אמיתיים מהסט של הבדיקה לאחור — חלונות קבועים
$F = ts_bt_floats(json_decode(file_get_contents(dirname(__DIR__) . '/backtest/candles-h1.json'), true));
foreach ([600, 1500, 2500, 3500, 4999] as $end) $sets["h1-real-0-$end"] = [array_slice($F, 0, $end + 1), $H, []];

// גלים סביב 82,000 (TS_KLINES_SHAPE=wave) — שעה וארבע שעות, טווח קבוע
foreach (['1h' => $H, '4h' => 4 * $H] as $iv => $sec) {
    $from = 1794094200 - 1000 * $sec;
    [, $body] = ts_fixture_klines_wave('https://x/api/v3/klines?' . http_build_query(['interval' => $iv,
        'startTime' => $from * 1000, 'endTime' => 1794094200 * 1000, 'limit' => 1000]));
    $rows = array_map(fn($k) => ['t' => intdiv($k[0], 1000), 'o' => $k[1], 'h' => $k[2], 'l' => $k[3], 'c' => $k[4]], json_decode($body, true));
    $sets["wave-$iv"] = [ts_bt_floats($rows), $sec, []];
}
return $sets;
