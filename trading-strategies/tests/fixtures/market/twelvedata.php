<?php
// Twelve Data מדומה. מפתח תקין מתחיל ב-"good-". מפתח אחר — שגיאה שמחזירה את המפתח בהודעה (כמו ספקים אמיתיים
// שמחזירים את הבקשה), כדי לבדוק שהוא לא דולף אלינו הלאה.
return function (string $url, array $headers): array {
    $key = '';
    foreach ($headers as $h) if (stripos($h, 'Authorization: apikey ') === 0) $key = substr($h, 22);
    if (!str_starts_with($key, 'good-')) {
        return [200, json_encode(['code' => 401, 'status' => 'error', 'message' => "**apikey** parameter is incorrect: $key (request $url?apikey=$key)"])];
    }
    $path = (string)parse_url($url, PHP_URL_PATH);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    if ($path === '/api_usage') return [200, json_encode(['current_usage' => 1, 'plan_limit' => 8])];
    if ($path === '/symbol_search') return [200, json_encode(['data' => [['symbol' => 'AAPL', 'instrument_name' => 'Apple Inc', 'exchange' => 'NASDAQ',
        'exchange_timezone' => 'America/New_York', 'instrument_type' => 'Common Stock', 'currency' => 'USD']]])];
    if ($path === '/time_series') {
        $daily = in_array($q['interval'], ['1day', '1week', '1month'], true);
        $from = strtotime($q['start_date'] . ' UTC'); $to = strtotime($q['end_date'] . ' UTC');
        $vals = [];
        for ($t = $daily ? strtotime(gmdate('Y-m-d', $from) . ' 00:00 UTC') : $from - $from % 3600; $t <= $to; $t += $daily ? 86400 : 3600) {
            $vals[] = ['datetime' => $daily ? gmdate('Y-m-d', $t) : gmdate('Y-m-d H:i:s', $t), 'open' => '200.10', 'high' => '201.00', 'low' => '199.50', 'close' => '200.75', 'volume' => '1000'];
        }
        return [200, json_encode(['meta' => ['symbol' => $q['symbol'], 'exchange_timezone' => 'America/New_York'], 'values' => $vals, 'status' => 'ok'])];
    }
    return [404, '{}'];
};
