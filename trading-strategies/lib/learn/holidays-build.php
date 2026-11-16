<?php
/**
 * בונה את lib/learn/holidays.json (spec-stage-a 23.5, L1): לוח חגים קבוע, 2010–2030, לכל שורה — תאריך, אזור, שם ומקור.
 * הקובץ עצמו הוא מה שהאפליקציה קוראת; הסקריפט רק מתעד איך נבנה, ומאפשר לבנות שוב. php lib/learn/holidays-build.php
 *
 * [שלנו] — מה נכלל, ומה לא:
 * - US (NYSE): כל ימי הסגירה הקבועים, עם כללי "נדחה" (שבת → שישי, ראשון → שני; 1.1 בשבת — לא נדחה), Juneteenth מ-2022,
 *   וסגירות מיוחדות (2012 סופה, 2018 ו-2025 הלוויות).
 * - UK (LSE): ימי הבנקים של אנגליה, עם הזזות (2012, 2020, 2022) והחגים המיוחדים (2011, 2022, 2023).
 * - EU (TARGET2 / Eurex): ששת ימי הסגירה של מערכת התשלומים של האיחוד.
 * - JP (JPX): החגים הקבועים, החגים "של יום שני", ימי השוויון (לפי הנוסחה המקובלת), 2–3.1 ו-31.12. **בלי ימי פיצוי ובלי
 *   ההזזות המיוחדות** (למשל אולימפיאדת 2020–2021).
 * - CN (SSE): 1.1, ראש השנה הסיני (היום עצמו, לפי טבלה), 1.5 ו-1.10. **בלי** ימי הגשר ובלי חגי הירח האחרים.
 * - IL (TASE): ראש השנה (2 ימים), יום כיפור, סוכות, שמיני עצרת, פסח (ראשון ושביעי), שבועות, יום העצמאות (עם ההקדמה
 *   והדחייה) — מהלוח העברי (ext-calendar).
 * - XMAS: 24.12–1.1 — "חופשות של כלל העולם". כל יום בטווח הוא שורה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;

$out = [];
$add = function (string $date, string $region, string $name, string $source) use (&$out): void {
    $out[] = ['date' => $date, 'region' => $region, 'name' => $name, 'source' => $source];
};
$ymd = fn(int $y, int $m, int $d) => sprintf('%04d-%02d-%02d', $y, $m, $d);
$dow = fn(string $date) => (int)gmdate('N', strtotime($date . ' UTC'));   // 1 = שני … 7 = ראשון
$shift = fn(string $date, int $days) => gmdate('Y-m-d', strtotime("$date UTC") + $days * 86400);
/** היום ה-n (1…) של יום בשבוע $w (1 = שני) בחודש; n = -1 — האחרון */
$nth = function (int $y, int $m, int $w, int $n) use ($ymd, $dow, $shift): string {
    if ($n > 0) {
        $d = $ymd($y, $m, 1);
        while ($dow($d) !== $w) $d = $shift($d, 1);
        return $shift($d, 7 * ($n - 1));
    }
    $d = gmdate('Y-m-t', strtotime($ymd($y, $m, 1) . ' UTC'));
    while ($dow($d) !== $w) $d = $shift($d, -1);
    return $d;
};
$easter = fn(int $y) => $shift($ymd($y, 3, 21), easter_days($y));
/** NYSE: שבת → שישי, ראשון → שני */
$observed = fn(string $d) => match ($dow($d)) { 6 => $shift($d, -1), 7 => $shift($d, 1), default => $d };
/** UK: שבת או ראשון → שני הבא (וכשיום ההחלפה תפוס — יום אחריו) */
$ukObs = function (string $d, array $taken = []) use ($dow, $shift): string {
    while ($dow($d) >= 6 || in_array($d, $taken, true)) $d = $shift($d, 1);
    return $d;
};

const SRC_NYSE = 'NYSE — לוח החגים וימי הסגירה (nyse.com/markets/hours-calendars)';
const SRC_LSE = 'LSE — ימי הבנקים של אנגליה ווילס (gov.uk/bank-holidays)';
const SRC_EU = 'ECB — ימי הסגירה של TARGET2 (ecb.europa.eu)';
const SRC_JPX = 'JPX — ימי הסגירה של הבורסה של טוקיו (jpx.co.jp); החוק לחגים הלאומיים; ימי השוויון — הנוסחה המקובלת';
const SRC_SSE = 'SSE — לוח המסחר של בורסת שנגחאי (sse.com.cn); ראש השנה הסיני — לפי הלוח הירחי';
const SRC_TASE = 'TASE — ימי הסגירה של הבורסה לניירות ערך בתל אביב (tase.co.il); תאריכים מהלוח העברי';
const SRC_XMAS = 'חג המולד וראש השנה האזרחית — 24.12 עד 1.1 [שלנו, 23.5]';

// ראש השנה הסיני, 2010–2030 (היום הראשון של השנה הירחית)
const CNY = [2010 => '02-14', 2011 => '02-03', 2012 => '01-23', 2013 => '02-10', 2014 => '01-31', 2015 => '02-19', 2016 => '02-08',
    2017 => '01-28', 2018 => '02-16', 2019 => '02-05', 2020 => '01-25', 2021 => '02-12', 2022 => '02-01', 2023 => '01-22', 2024 => '02-10',
    2025 => '01-29', 2026 => '02-17', 2027 => '02-06', 2028 => '01-26', 2029 => '02-13', 2030 => '02-03'];

for ($y = 2010; $y <= 2030; $y++) {
    $gf = $shift($easter($y), -2);
    $em = $shift($easter($y), 1);

    // ── US (NYSE) ──
    if ($dow($ymd($y, 1, 1)) !== 6) $add($observed($ymd($y, 1, 1)), 'US', "New Year's Day", SRC_NYSE);
    $add($nth($y, 1, 1, 3), 'US', 'Martin Luther King Jr. Day', SRC_NYSE);
    $add($nth($y, 2, 1, 3), 'US', "Washington's Birthday", SRC_NYSE);
    $add($gf, 'US', 'Good Friday', SRC_NYSE);
    $add($nth($y, 5, 1, -1), 'US', 'Memorial Day', SRC_NYSE);
    if ($y >= 2022) $add($observed($ymd($y, 6, 19)), 'US', 'Juneteenth', SRC_NYSE);
    $add($observed($ymd($y, 7, 4)), 'US', 'Independence Day', SRC_NYSE);
    $add($nth($y, 9, 1, 1), 'US', 'Labor Day', SRC_NYSE);
    $add($nth($y, 11, 4, 4), 'US', 'Thanksgiving Day', SRC_NYSE);
    $add($observed($ymd($y, 12, 25)), 'US', 'Christmas Day', SRC_NYSE);

    // ── UK (LSE) ──
    $nyUk = $ukObs($ymd($y, 1, 1));
    $add($nyUk, 'UK', "New Year's Day", SRC_LSE);
    $add($gf, 'UK', 'Good Friday', SRC_LSE);
    $add($em, 'UK', 'Easter Monday', SRC_LSE);
    $add($y === 2020 ? '2020-05-08' : $nth($y, 5, 1, 1), 'UK', 'Early May bank holiday', SRC_LSE);
    $add(match ($y) { 2012 => '2012-06-04', 2022 => '2022-06-02', default => $nth($y, 5, 1, -1) }, 'UK', 'Spring bank holiday', SRC_LSE);
    $add($nth($y, 8, 1, -1), 'UK', 'Summer bank holiday', SRC_LSE);
    $xm = $ukObs($ymd($y, 12, 25));
    $add($xm, 'UK', 'Christmas Day', SRC_LSE);
    $add($ukObs($ymd($y, 12, 26), [$xm]), 'UK', 'Boxing Day', SRC_LSE);

    // ── EU (TARGET2) ──
    $add($ymd($y, 1, 1), 'EU', "New Year's Day", SRC_EU);
    $add($gf, 'EU', 'Good Friday', SRC_EU);
    $add($em, 'EU', 'Easter Monday', SRC_EU);
    $add($ymd($y, 5, 1), 'EU', 'Labour Day', SRC_EU);
    $add($ymd($y, 12, 25), 'EU', 'Christmas Day', SRC_EU);
    $add($ymd($y, 12, 26), 'EU', "St. Stephen's Day", SRC_EU);

    // ── JP (JPX) ──
    $k = $y - 1980;
    $vernal = (int)floor(20.8431 + 0.242194 * $k - floor($k / 4));
    $autumn = (int)floor(23.2488 + 0.242194 * $k - floor($k / 4));
    foreach ([[$ymd($y, 1, 1), "New Year's Day"], [$ymd($y, 1, 2), 'Market holiday'], [$ymd($y, 1, 3), 'Market holiday'],
        [$nth($y, 1, 1, 2), 'Coming of Age Day'], [$ymd($y, 2, 11), 'National Foundation Day'],
        [$ymd($y, 3, $vernal), 'Vernal Equinox Day'], [$ymd($y, 4, 29), 'Showa Day'], [$ymd($y, 5, 3), 'Constitution Memorial Day'],
        [$ymd($y, 5, 4), 'Greenery Day'], [$ymd($y, 5, 5), "Children's Day"], [$nth($y, 7, 1, 3), 'Marine Day'],
        [$nth($y, 9, 1, 3), 'Respect for the Aged Day'], [$ymd($y, 9, $autumn), 'Autumnal Equinox Day'],
        [$nth($y, 10, 1, 2), 'Sports Day'], [$ymd($y, 11, 3), 'Culture Day'], [$ymd($y, 11, 23), 'Labour Thanksgiving Day'],
        [$ymd($y, 12, 31), 'Market holiday']] as [$d, $n]) $add($d, 'JP', $n, SRC_JPX);
    if ($y >= 2016) $add($ymd($y, 8, 11), 'JP', 'Mountain Day', SRC_JPX);
    if ($y <= 2018) $add($ymd($y, 12, 23), 'JP', "Emperor's Birthday", SRC_JPX);
    if ($y >= 2020) $add($ymd($y, 2, 23), 'JP', "Emperor's Birthday", SRC_JPX);

    // ── CN (SSE) ──
    $add($ymd($y, 1, 1), 'CN', "New Year's Day", SRC_SSE);
    $add($y . '-' . CNY[$y], 'CN', 'Spring Festival', SRC_SSE);
    $add($ymd($y, 5, 1), 'CN', 'Labour Day', SRC_SSE);
    $add($ymd($y, 10, 1), 'CN', 'National Day', SRC_SSE);

    // ── IL (TASE) — הלוח העברי. חודשים ב-ext-calendar: 1 תשרי, 8 ניסן, 9 אייר, 10 סיוון ──
    $heb = fn(int $hm, int $hd, int $hy) => gmdate('Y-m-d', (int)strtotime(jdtogregorian(jewishtojd($hm, $hd, $hy)) . ' UTC'));
    $fall = $y + 3761;    // ראש השנה בסתיו של $y
    $spring = $y + 3760;  // פסח באביב של $y
    $add($heb(1, 1, $fall), 'IL', 'Rosh Hashanah', SRC_TASE);
    $add($heb(1, 2, $fall), 'IL', 'Rosh Hashanah (2)', SRC_TASE);
    $add($heb(1, 10, $fall), 'IL', 'Yom Kippur', SRC_TASE);
    $add($heb(1, 15, $fall), 'IL', 'Sukkot', SRC_TASE);
    $add($heb(1, 22, $fall), 'IL', 'Shemini Atzeret', SRC_TASE);
    $add($heb(8, 15, $spring), 'IL', 'Passover', SRC_TASE);
    $add($heb(8, 21, $spring), 'IL', 'Passover (7th day)', SRC_TASE);
    $add($heb(10, 6, $spring), 'IL', 'Shavuot', SRC_TASE);
    // יום העצמאות — ה' באייר; שישי או שבת → הקדמה לחמישי, שני → דחייה לשלישי
    $ind = $heb(9, 5, $spring);
    $ind = match ($dow($ind)) { 5 => $shift($ind, -1), 6 => $shift($ind, -2), 1 => $shift($ind, 1), default => $ind };
    $add($ind, 'IL', 'Independence Day', SRC_TASE);

    // ── XMAS — 24.12 עד 1.1 ──
    for ($d = 24; $d <= 31; $d++) $add($ymd($y, 12, $d), 'XMAS', 'Christmas–New Year', SRC_XMAS);
    $add($ymd($y, 1, 1), 'XMAS', 'Christmas–New Year', SRC_XMAS);
}

// סגירות מיוחדות
foreach ([['2012-10-29', 'US', 'Hurricane Sandy', SRC_NYSE], ['2012-10-30', 'US', 'Hurricane Sandy', SRC_NYSE],
    ['2018-12-05', 'US', 'National Day of Mourning (George H. W. Bush)', SRC_NYSE],
    ['2025-01-09', 'US', 'National Day of Mourning (Jimmy Carter)', SRC_NYSE],
    ['2011-04-29', 'UK', 'Royal Wedding', SRC_LSE], ['2012-06-05', 'UK', 'Diamond Jubilee', SRC_LSE],
    ['2022-06-03', 'UK', 'Platinum Jubilee', SRC_LSE], ['2022-09-19', 'UK', 'State Funeral of Queen Elizabeth II', SRC_LSE],
    ['2023-05-08', 'UK', 'Coronation of King Charles III', SRC_LSE]] as [$d, $r, $n, $s]) $add($d, $r, $n, $s);

usort($out, fn($a, $b) => [$a['date'], $a['region'], $a['name']] <=> [$b['date'], $b['region'], $b['name']]);
$out = array_values(array_unique(array_map('json_encode', $out)));
$out = array_map(fn($j) => json_decode($j, true), $out);
$json = "[\n" . implode(",\n", array_map(fn($r) => '  ' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $out)) . "\n]\n";
file_put_contents(__DIR__ . '/holidays.json', $json);
echo count($out) . " שורות, " . $out[0]['date'] . ' – ' . end($out)['date'] . "\n";
