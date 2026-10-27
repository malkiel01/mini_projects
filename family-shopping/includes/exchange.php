<?php
/**
 * מטבעות זרים והשער היציג של בנק ישראל
 * includes/exchange.php
 *
 * קנייה בחו"ל נרשמת במטבע שבו שולמה, ומומרת לשקלים פעם אחת - בזמן
 * הרישום. החשבון כולו (מאזן, קיזוזים, סיכומים) נשאר בשקלים, ולכן אף
 * חישוב לא צריך לדעת שהיו כאן מטבעות בכלל.
 *
 * השער: היציג של בנק ישראל ליום הקנייה. ביום שלא פורסם בו שער (שבת,
 * חג) - האחרון שפורסם לפניו, כמו בכל חשבונית. אפשר גם להזין שער ידני
 * (למשל השער שחויב בפועל בכרטיס האשראי).
 *
 * המקור: ממשק ה-SDMX הציבורי של בנק ישראל, בלי מפתח. התשובות נשמרות
 * בטבלת exchange_rates, כך שכל מטבע ויום נשאלים פעם אחת.
 */

/** מטבע החשבון. קנייה בו אינה שומרת שום פרט המרה */
const BASE_CURRENCY = 'ILS';

/** כמה ימים אחורה לחפש שער שפורסם (חגים רצופים + סוף שבוע) */
const RATE_LOOKBACK_DAYS = 14;

/** תוך כמה זמן לבדוק שוב שער של היום, שאולי עוד לא פורסם */
const RATE_TODAY_RECHECK = 3600;

/**
 * המטבעות שבנק ישראל מפרסם להם שער יציג.
 *
 * @return array קוד => [שם, סימן]
 */
function supportedCurrencies() {
    return [
        'ILS' => ['שקל',            '₪'],
        'USD' => ['דולר אמריקאי',   '$'],
        'EUR' => ['אירו',           '€'],
        'GBP' => ['לירה שטרלינג',   '£'],
        'CHF' => ['פרנק שווייצרי',  'CHF '],
        'CAD' => ['דולר קנדי',      'C$'],
        'AUD' => ['דולר אוסטרלי',   'A$'],
        'JPY' => ['ין יפני',        '¥'],
        'SEK' => ['כתר שוודי',      'SEK '],
        'NOK' => ['כתר נורווגי',    'NOK '],
        'DKK' => ['כתר דני',        'DKK '],
        'ZAR' => ['ראנד',           'R '],
        'JOD' => ['דינר ירדני',     'JOD '],
        'EGP' => ['לירה מצרית',     'E£'],
    ];
}

/** האם זה קוד מטבע שאפשר לרשום בו קנייה */
function isSupportedCurrency($code) {
    return is_string($code) && isset(supportedCurrencies()[$code]);
}

/** סימן המטבע להצגה. לשקל - הסימן שמוגדר באפליקציה */
function currencySign($code) {
    if ($code === null || $code === '' || $code === BASE_CURRENCY) {
        return function_exists('currencySymbol') ? currencySymbol() : '₪';
    }

    return supportedCurrencies()[$code][1] ?? ($code . ' ');
}

/** סכום עם סימן המטבע שלו: "$12.50" */
function formatMoney($amount, $code = null) {
    return currencySign($code) . number_format((float)$amount, 2);
}

/**
 * שער להצגה: מספיק ספרות כדי שגם ין (0.0196) ייראה כמו שהוא.
 */
function formatRate($rate) {
    $rate     = (float)$rate;
    $decimals = $rate >= 1 ? 4 : 6;

    return rtrim(rtrim(number_format($rate, $decimals, '.', ''), '0'), '.');
}

/** האם ההמרה של הקנייה קיימת (מיגרציה 017) */
function currencyColumnsReady(PDO $pdo) {
    // לכל חיבור בנפרד: בבקשה יש אחד, אבל בבדיקות כמה מסדים זה לצד זה.
    // החיבור עצמו נשמר לצד התשובה - אחרת מזהה של חיבור שנסגר עובר
    // לחיבור חדש, וזה מקבל תשובה שאינה שלו.
    static $ready = [];
    $key = spl_object_id($pdo);

    if (isset($ready[$key]) && $ready[$key][0] === $pdo) {
        return $ready[$key][1];
    }

    // ישירות ולא דרך information_schema - עובד גם ב-SQLite של הבדיקות
    try {
        $pdo->query("SELECT currency, original_amount, exchange_rate, rate_date, rate_source
                     FROM group_purchases WHERE 1 = 0");
        $pdo->query("SELECT currency FROM exchange_rates WHERE 1 = 0");
        $ok = true;
    } catch (Exception $e) {
        $ok = false;
    }

    $ready[$key] = [$pdo, $ok];

    return $ok;
}

/** תאריך Y-m-d תקין, או null */
function validRateDate($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value) ? $value : null;
}

/**
 * הורדה מבנק ישראל. מוחלפת בבדיקות.
 *
 * @return string|null גוף התשובה, או null בכישלון
 */
function boiFetch($url) {
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'family-shopping/1.0',
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($body !== false && $status === 200) ? $body : null;
}

/** כתובת סדרת השער היציג בטווח תאריכים */
function boiRatesUrl($code, $from, $to) {
    return 'https://edge.boi.gov.il/FusionEdgeServer/sdmx/v2/data/dataflow/BOI.STATISTICS/EXR/1.0/'
        . 'RER_' . $code . '_ILS?startperiod=' . $from . '&endperiod=' . $to . '&format=csv';
}

/**
 * פענוח ה-CSV של בנק ישראל.
 *
 * שער מתפרסם לפעמים ל-100 יחידות (ין) או ל-10 - UNIT_MULT אומר
 * בכמה אפסים. כאן הכול מנורמל לשקלים ליחידה אחת.
 *
 * @return array תאריך => שער ליחידה
 */
function parseBoiCsv($csv) {
    $lines = preg_split('/\r\n|\n|\r/', trim((string)$csv));
    if (count($lines) < 2) {
        return [];
    }

    $header = str_getcsv(array_shift($lines));
    $col    = array_flip($header);

    if (!isset($col['TIME_PERIOD'], $col['OBS_VALUE'])) {
        return [];
    }

    $rates = [];
    foreach ($lines as $line) {
        $row   = str_getcsv($line);
        $date  = validRateDate($row[$col['TIME_PERIOD']] ?? '');
        $value = $row[$col['OBS_VALUE']] ?? '';
        $mult  = isset($col['UNIT_MULT']) ? (int)($row[$col['UNIT_MULT']] ?? 0) : 0;

        if ($date === null || !is_numeric($value) || (float)$value <= 0) {
            continue;
        }

        $rates[$date] = (float)$value / pow(10, $mult);
    }

    ksort($rates);

    return $rates;
}

/**
 * השער היציג למטבע ביום מסוים.
 *
 * @param string        $code   קוד המטבע (לא שקל)
 * @param string        $date   Y-m-d. יום עתידי נחשב להיום
 * @param callable|null $fetch  להחלפה בבדיקות
 * @param string|null   $today  להחלפה בבדיקות
 * @return array|null ['rate' => שקלים ליחידה, 'date' => היום שבו פורסם] או null
 */
function exchangeRate(PDO $pdo, $code, $date, ?callable $fetch = null, $today = null) {
    if (!isSupportedCurrency($code) || $code === BASE_CURRENCY) {
        return null;
    }

    $today = $today ?? date('Y-m-d');
    $date  = validRateDate($date) ?? $today;
    if ($date > $today) {
        $date = $today;
    }

    $stmt = $pdo->prepare("
        SELECT published_date, rate, fetched_at FROM exchange_rates
        WHERE currency = ? AND rate_date = ?
    ");
    $stmt->execute([$code, $date]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    // יום שעבר - התשובה סופית. היום - ייתכן שהשער עוד לא פורסם,
    // אז מה שנשמר תקף רק אם הוא של היום עצמו, או שנבדק לא מזמן.
    $fresh = $cached && (
        $date < $today
        || $cached['published_date'] === $date
        || strtotime($cached['fetched_at']) > time() - RATE_TODAY_RECHECK
    );

    if ($fresh) {
        return ['rate' => (float)$cached['rate'], 'date' => $cached['published_date']];
    }

    $from = date('Y-m-d', strtotime($date . ' -' . RATE_LOOKBACK_DAYS . ' days'));
    $body = ($fetch ?? 'boiFetch')(boiRatesUrl($code, $from, $date));
    $all  = $body === null ? [] : array_filter(parseBoiCsv($body), function ($day) use ($date) {
        return $day <= $date;
    }, ARRAY_FILTER_USE_KEY);

    if (count($all) === 0) {
        // הבנק לא ענה - עדיף שער ישן מהמטמון מאשר כלום
        return $cached ? ['rate' => (float)$cached['rate'], 'date' => $cached['published_date']] : null;
    }

    $published = array_key_last($all);
    $rate      = $all[$published];

    $stmt = $pdo->prepare("
        REPLACE INTO exchange_rates (currency, rate_date, published_date, rate, fetched_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$code, $date, $published, $rate, date('Y-m-d H:i:s')]);

    return ['rate' => $rate, 'date' => $published];
}

/**
 * פרטי הכסף של קנייה, מתוך הטופס.
 *
 * בשקלים - הסכום כמו שהוא. במטבע זר - הסכום המקורי מוכפל בשער:
 * היציג של היום שנבחר (השרת מביא אותו בעצמו, לא סומך על הדפדפן),
 * או שער ידני שהמשתמש הזין.
 *
 * @return array ['ok' => true, 'amount', 'fields' => עמודות ההמרה]
 *               או ['ok' => false, 'message']
 */
function purchaseMoneyFromInput(PDO $pdo, array $input, ?callable $fetch = null) {
    $entered  = round(floatval($input['amount'] ?? 0), 2);
    $currency = strtoupper(trim((string)($input['currency'] ?? '')));
    $currency = $currency === '' ? BASE_CURRENCY : $currency;

    $none = [
        'currency' => null, 'original_amount' => null, 'exchange_rate' => null,
        'rate_date' => null, 'rate_source' => null,
    ];

    if ($entered <= 0) {
        return ['ok' => false, 'message' => 'סכום הקנייה חייב להיות חיובי'];
    }

    if ($currency === BASE_CURRENCY) {
        return ['ok' => true, 'amount' => $entered, 'fields' => $none];
    }

    if (!isSupportedCurrency($currency)) {
        return ['ok' => false, 'message' => 'מטבע לא נתמך'];
    }

    if (!currencyColumnsReady($pdo)) {
        return ['ok' => false, 'message' => 'קנייה במטבע זר תהיה זמינה אחרי עדכון מסד הנתונים'];
    }

    $today = date('Y-m-d');
    $date  = validRateDate($input['rate_date'] ?? '') ?? $today;
    if ($date > $today) {
        return ['ok' => false, 'message' => 'תאריך השער לא יכול להיות בעתיד'];
    }

    if (($input['rate_mode'] ?? 'boi') === 'manual') {
        $rate = floatval($input['rate'] ?? 0);
        if (!($rate > 0) || $rate > 100000) {
            return ['ok' => false, 'message' => 'השער הידני אינו תקין'];
        }
        $source = 'manual';
    } else {
        $found = exchangeRate($pdo, $currency, $date, $fetch);
        if ($found === null) {
            return ['ok' => false, 'message' => 'לא הצלחנו להביא את השער היציג מבנק ישראל. אפשר להזין שער ידני'];
        }
        $rate   = $found['rate'];
        $date   = $found['date'];
        $source = 'boi';
    }

    $amount = round($entered * $rate, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'הסכום בשקלים יוצא אפס - בדקו את הסכום ואת השער'];
    }

    return ['ok' => true, 'amount' => $amount, 'fields' => [
        'currency'        => $currency,
        'original_amount' => $entered,
        'exchange_rate'   => round($rate, 8),
        'rate_date'       => $date,
        'rate_source'     => $source,
    ]];
}

/**
 * תיאור ההמרה של קנייה: "$100.00 · שער יציג 3.078 (26/10/2026)".
 * ברשימה הסכום המקורי כבר מוצג ליד הסכום בשקלים - ושם בלעדיו.
 * קנייה בשקלים - מחרוזת ריקה.
 */
function purchaseConversionLabel(array $purchase, $withAmount = true) {
    $code = $purchase['currency'] ?? null;
    if ($code === null || $code === '' || $code === BASE_CURRENCY || $purchase['original_amount'] === null) {
        return '';
    }

    $kind = ($purchase['rate_source'] ?? '') === 'manual' ? 'שער ידני' : 'שער יציג';
    $time = !empty($purchase['rate_date']) ? strtotime($purchase['rate_date']) : null;

    // ברשימה השורה צרה - היום והחודש מספיקים
    if (!$withAmount) {
        return $kind . ' ' . formatRate($purchase['exchange_rate']) . ($time ? ' · ' . date('d/m', $time) : '');
    }

    return formatMoney($purchase['original_amount'], $code) . ' · ' . $kind . ' '
        . formatRate($purchase['exchange_rate']) . ($time ? ' (' . date('d/m/Y', $time) . ')' : '');
}
