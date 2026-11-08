<?php
// Frankfurter מדומה לטווח: שער לכל יום עסקים (בלי שבת-ראשון), דטרמיניסטי — בסיס הזוג + יום בחודש × 0.0001,
// כדי שהבדיקה תדע בדיוק איזה יום שימש. מטבע שה-ECB לא מכיר (BTC וכו') — בלי שערים.
return function (string $url, array $headers): array {
    preg_match('#/v1/(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})#', $url, $m);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $base = ['ILS' => ['USD' => 0.27], 'EUR' => ['USD' => 1.08, 'ILS' => 4.0], 'USD' => ['ILS' => 3.6, 'EUR' => 0.92], 'JPY' => ['USD' => 0.0066]];
    $from = $q['from'] ?? ''; $to = $q['to'] ?? '';
    $rates = [];
    if (isset($base[$from][$to])) {
        for ($t = strtotime("$m[1] UTC"); $t <= strtotime("$m[2] UTC"); $t += 86400) {
            if (in_array((int)gmdate('w', $t), [0, 6], true)) continue;
            $rates[gmdate('Y-m-d', $t)] = [$to => round($base[$from][$to] + (int)gmdate('j', $t) * 0.0001, 6)];
        }
    }
    return [200, json_encode(['amount' => 1.0, 'base' => $from, 'start_date' => $m[1], 'end_date' => $m[2], 'rates' => (object)$rates])];
};
