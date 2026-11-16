<?php
// Binance מדומה: נרות מיושרים למסגרת בטווח שביקשו (עד limit), מחירים דטרמיניסטיים לפי הזמן.
// TS_KLINES_SHAPE=wave — גלים סביב 82,000 (כמו ביטקוין), שבהם אליוט מוצאת ספירות עם תוויות (F1, 18.1.2 קריטריון 8).
if (!function_exists('ts_fixture_klines_wave')) {
function ts_fixture_klines_wave(string $url): array
{
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $sec = ['1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1d' => 86400, '1w' => 604800][$q['interval']] ?? 3600;
    $from = intdiv((int)$q['startTime'], 1000); $to = intdiv((int)$q['endTime'], 1000);
    $t = (int)(ceil($from / $sec) * $sec);
    $px = fn(int $t) => 82000 + 5200 * sin($t / (86400 * 9.0)) + 1900 * sin($t / (86400 * 2.3) + 1) + 600 * sin($t / 31000.0 + 2);
    $out = [];
    for ($n = 0; $t <= $to && $n < (int)($q['limit'] ?? 1000); $t += $sec, $n++) {
        $o = $px($t); $c = $px($t + $sec);
        $h = max($o, $c) + 90; $l = min($o, $c) - 90;
        $out[] = [$t * 1000, sprintf('%.2f', $o), sprintf('%.2f', $h), sprintf('%.2f', $l), sprintf('%.2f', $c), '12.5', ($t + $sec) * 1000 - 1];
    }
    return [200, json_encode($out)];
}
}

return function (string $url, array $headers): array {
    // ספק איטי (tests/backtest-partial-api.php): השהיה לכל פנייה, במילישניות
    if ($ms = (int)getenv('TS_KLINES_DELAY_MS')) usleep($ms * 1000);
    // היסטוריה בפעימות (tests/history-api.php): קובץ דגל — כל עוד קיים, Binance מחזיר 429
    if (($f = getenv('TS_KLINES_429_FILE')) && is_file($f)) return [429, json_encode(['code' => -1003, 'msg' => 'Too many requests'])];
    // TS_KLINES_SINCE — הנכס "נסחר" רק מאז (unix): לפני כן אין נרות
    if ($since = (int)getenv('TS_KLINES_SINCE')) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q0);
        if (intdiv((int)$q0['startTime'], 1000) < $since) {
            $q0['startTime'] = $since * 1000;
            if ($q0['startTime'] > (int)$q0['endTime']) return [200, '[]'];
            $url = strtok($url, '?') . '?' . http_build_query($q0);
        }
    }
    if (getenv('TS_KLINES_SHAPE') === 'wave') return ts_fixture_klines_wave($url);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $sec = ['1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1d' => 86400, '1w' => 604800][$q['interval']] ?? 3600;
    $from = intdiv((int)$q['startTime'], 1000); $to = intdiv((int)$q['endTime'], 1000);
    $t = (int)(ceil($from / $sec) * $sec);
    $out = [];
    for ($n = 0; $t <= $to && $n < (int)($q['limit'] ?? 1000); $t += $sec, $n++) {
        $p = 100 + ($t / 3600 % 50);
        $out[] = [$t * 1000, "$p.10000000", ($p + 1) . ".00000000", ($p - 1) . ".00000000", "$p.50000000", "12.5", ($t + $sec) * 1000 - 1];
    }
    return [200, json_encode($out)];
};
