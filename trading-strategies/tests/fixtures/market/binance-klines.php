<?php
// Binance מדומה: נרות מיושרים למסגרת בטווח שביקשו (עד limit), מחירים דטרמיניסטיים לפי הזמן.
return function (string $url, array $headers): array {
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
