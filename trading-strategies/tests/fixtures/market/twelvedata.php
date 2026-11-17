<?php
// Twelve Data מדומה. מפתח תקין מתחיל ב-"good-". מפתח אחר — שגיאה שמחזירה את המפתח בהודעה (כמו ספקים אמיתיים
// שמחזירים את הבקשה), כדי לבדוק שהוא לא דולף אלינו הלאה.
return function (string $url, array $headers): array {
    $key = '';
    foreach ($headers as $h) if (stripos($h, 'Authorization: apikey ') === 0) $key = substr($h, 22);
    if (!str_starts_with($key, 'good-')) {
        return [200, json_encode(['code' => 401, 'status' => 'error', 'message' => "**apikey** parameter is incorrect: $key (request $url?apikey=$key)"])];
    }
    // מפתח "good-rate…" — הספק מגביל את הקצב (429 בגוף, כמו Twelve Data) — לבדיקה של 21.3 🟡3
    if (str_starts_with($key, 'good-rate')) return [200, json_encode(['code' => 429, 'status' => 'error', 'message' => 'You have run out of API credits for the current minute.'])];
    $path = (string)parse_url($url, PHP_URL_PATH);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    if ($path === '/api_usage') return [200, json_encode(['current_usage' => 1, 'plan_limit' => 8])];
    if ($path === '/symbol_search') return [200, json_encode(['data' => [['symbol' => 'AAPL', 'instrument_name' => 'Apple Inc', 'exchange' => 'NASDAQ',
        'exchange_timezone' => 'America/New_York', 'instrument_type' => 'Common Stock', 'currency' => 'USD']]])];
    // תקלה בצד הספק (לא תקרה) — כשהקובץ שב-TS_TD_FAIL_FILE קיים (tests/empty-chart-api.php)
    // תוכן הקובץ — זמן: רק בקשות שנגמרות לפניו נכשלות (החלק הישן מ-60 יום בלבד); ריק — הכול
    if ($path === '/time_series' && ($ff = getenv('TS_TD_FAIL_FILE')) && is_file($ff)
        && (trim((string)file_get_contents($ff)) === '' || strtotime($q['end_date'] . ' UTC') < (int)file_get_contents($ff))) return [200, json_encode(['code' => 500, 'status' => 'error', 'message' => 'Internal error, please try again later.'])];
    if ($path === '/time_series') {
        $daily = in_array($q['interval'], ['1day', '1week', '1month'], true);
        $from = strtotime($q['start_date'] . ' UTC'); $to = strtotime($q['end_date'] . ' UTC');
        // סימבול שלא בתוכנית — 403 כמו Twelve Data ("… is available exclusively with Grow or Venture plans")
        if (str_starts_with((string)$q['symbol'], 'PLAN')) return [200, json_encode(['code' => 403, 'status' => 'error', 'message' => "/time_series: **symbol** {$q['symbol']} is available exclusively with Grow or Venture plans."])];
        $vals = [];
        for ($t = $daily ? strtotime(gmdate('Y-m-d', $from) . ' 00:00 UTC') : $from - $from % 3600; $t <= $to; $t += $daily ? 86400 : 3600) {
            if (!$daily && $t < $from) continue;   // כמו הספק: רק נרות שנפתחו בטווח
            // מט"ח וסחורות (XAG/USD, EUR/USD): סגור בסוף שבוע — משישי 21:00 עד ראשון 22:00 UTC
            $dow = (int)gmdate('w', $t); $hr = (int)gmdate('G', $t);
            if (str_contains((string)$q['symbol'], '/') && ($dow === 6 || ($dow === 5 && $hr >= 21) || ($dow === 0 && $hr < 22))) continue;
            $vals[] = ['datetime' => $daily ? gmdate('Y-m-d', $t) : gmdate('Y-m-d H:i:s', $t), 'open' => '200.10', 'high' => '201.00', 'low' => '199.50', 'close' => '200.75', 'volume' => '1000'];
        }
        // טווח בלי נרות — Twelve Data מחזיר 400, לא רשימה ריקה
        if (!$vals) return [200, json_encode(['code' => 400, 'status' => 'error', 'message' => 'No data is available on the specified dates. Try setting different start/end dates.'])];
        return [200, json_encode(['meta' => ['symbol' => $q['symbol'], 'exchange_timezone' => 'America/New_York'], 'values' => $vals, 'status' => 'ok'])];
    }
    return [404, '{}'];
};
