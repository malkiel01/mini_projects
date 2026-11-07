<?php
// Finnhub מדומה: מפתח תקין מתחיל ב-"good-"; נרות של מניה — שלושה, t בשניות UTC.
return function (string $url, array $headers): array {
    $key = '';
    foreach ($headers as $h) if (stripos($h, 'X-Finnhub-Token: ') === 0) $key = substr($h, 17);
    if (!str_starts_with($key, 'good-')) return [401, json_encode(['error' => "Invalid API key $key"])];
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (str_ends_with($path, '/quote')) return [200, json_encode(['c' => 187.25, 't' => 1772460000])];
    if (str_ends_with($path, '/candle')) return [200, json_encode(['s' => 'ok', 't' => [1772460000, 1772546400], 'o' => [1.08345, 1.0841], 'h' => [1.085, 1.0852],
        'l' => [1.0821, 1.083], 'c' => [1.0841, 1.08499], 'v' => [100, 120]])];
    return [200, json_encode(['count' => 1, 'result' => [['symbol' => 'AAPL', 'description' => 'APPLE INC']]])];
};
