<?php
// הסט הקבוע של נרות לבדיקת הספירות (spec-stage-a 18.7, S7). **לא משנים סט קיים** — רק מוסיפים חדש (ואז מעדכנים את
// counts.json באותו PR). name → [נרות float ממוינים, אורך הנר בשניות].
require_once __DIR__ . '/bars.php';
require_once dirname(__DIR__) . '/market/binance-klines.php';

$H = 3600;
$imp = [['L', 100], ['H', 120], ['L', 107.64], ['H', 142.47], ['L', 129.16]];
$sets = [
    // סינתטיים (ATR קבוע = 1) — אותם מקרים כמו ב-tests/elliott-overlay.php
    'impulse-to-w4' => [ew_bars(105.5, $imp, 133), $H],
    'impulse-complete' => [ew_bars(105.5, [...$imp, ['H', 149.16]], 145), $H],
    'down-2-waves' => [ew_bars(94.5, [['H', 100], ['L', 80], ['H', 90]], 86), $H],
    'rule1-broken' => [ew_bars(105.5, [['L', 100], ['H', 120], ['L', 99], ['H', 142.47], ['L', 129.16]], 133), $H],
    'rule5-overlap' => [ew_bars(105.5, [['L', 100], ['H', 120], ['L', 107.64], ['H', 142.47], ['L', 118]], 122), $H],
    'alternate-counts' => [ew_bars(105.5, [['L', 100], ['H', 120], ['L', 107.64], ['H', 142.47], ['L', 125.055]], 129), $H],
];

// נרות שעה אמיתיים מהסט של הבדיקה לאחור — חלונות קבועים
$F = ts_bt_floats(json_decode(file_get_contents(dirname(__DIR__) . '/backtest/candles-h1.json'), true));
foreach ([600, 1500, 2500, 3500, 4999] as $end) $sets["h1-real-0-$end"] = [array_slice($F, 0, $end + 1), $H];

// גלים סביב 82,000 (TS_KLINES_SHAPE=wave) — שעה וארבע שעות, טווח קבוע
foreach (['1h' => $H, '4h' => 4 * $H] as $iv => $sec) {
    $from = 1794094200 - 1000 * $sec;
    [, $body] = ts_fixture_klines_wave('https://x/api/v3/klines?' . http_build_query(['interval' => $iv,
        'startTime' => $from * 1000, 'endTime' => 1794094200 * 1000, 'limit' => 1000]));
    $rows = array_map(fn($k) => ['t' => intdiv($k[0], 1000), 'o' => $k[1], 'h' => $k[2], 'l' => $k[3], 'c' => $k[4]], json_decode($body, true));
    $sets["wave-$iv"] = [ts_bt_floats($rows), $sec];
}
return $sets;
