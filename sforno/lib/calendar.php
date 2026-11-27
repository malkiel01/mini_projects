<?php
/**
 * הלוח העברי וזמני היום (אפיון 07) — **אותו אלגוריתם בדיוק כמו assets/js/calendar-core.js** (שם ההסבר המלא).
 * הבדיקה tests/k-calendar משווה בין השניים ומול intl. משמש את השרת: מתי תוכן של הלכה יומית "הגיע" (§4), התאריך
 * הלועזי בניהול, ובדיקת הגדרת שיטה — ובעתיד התזכורות (08).
 *
 *   מזהי חודש (ש6): 1 ניסן … 6 אלול, 7 תשרי … 11 שבט, 12 אדר א', 13 אדר ב'. בשנה פשוטה אדר = 13, ו-12 לא קיים.
 *   זמנים — ms מאז 1970 (UTC), כמו ב-JS. null = לא ניתן לחישוב, בלי שעה מומצאת.
 */

declare(strict_types=1);

const SF_CAL_EPOCH = -1373427;
const SF_CAL_DAY = 86400000;

function sf_cal_fl(float|int $x): int { return (int)floor($x); }
function sf_cal_mod(float|int $a, float|int $b): float|int { return $a - $b * floor($a / $b); }
/** כמו Math.round של JS (חצי — כלפי מעלה) */
function sf_cal_round(float $x): int { return (int)floor($x + 0.5); }

// ── גרגוריאני ↔ יום קבוע ─────────────────────────────────────
function sf_cal_gleap(int $y): bool { return sf_cal_mod($y, 4) == 0 && !in_array((int)sf_cal_mod($y, 400), [100, 200, 300], true); }

function sf_cal_fixed_from_greg(int $y, int $m, int $d): int
{
    return 365 * ($y - 1) + sf_cal_fl(($y - 1) / 4) - sf_cal_fl(($y - 1) / 100) + sf_cal_fl(($y - 1) / 400) + sf_cal_fl((367 * $m - 362) / 12)
        + ($m <= 2 ? 0 : (sf_cal_gleap($y) ? -1 : -2)) + $d;
}

function sf_cal_greg_from_fixed(int $f): array
{
    $d0 = $f - 1; $n400 = sf_cal_fl($d0 / 146097); $d1 = (int)sf_cal_mod($d0, 146097); $n100 = sf_cal_fl($d1 / 36524); $d2 = (int)sf_cal_mod($d1, 36524);
    $n4 = sf_cal_fl($d2 / 1461); $d3 = (int)sf_cal_mod($d2, 1461); $n1 = sf_cal_fl($d3 / 365);
    $y = 400 * $n400 + 100 * $n100 + 4 * $n4 + $n1;
    if (!($n100 === 4 || $n1 === 4)) $y++;
    $prior = $f - sf_cal_fixed_from_greg($y, 1, 1);
    $corr = $f < sf_cal_fixed_from_greg($y, 3, 1) ? 0 : (sf_cal_gleap($y) ? 1 : 2);
    $m = sf_cal_fl((12 * ($prior + $corr) + 373) / 367);
    return ['y' => $y, 'm' => $m, 'd' => $f - sf_cal_fixed_from_greg($y, $m, 1) + 1];
}

function sf_cal_weekday(int $f): int { return (int)sf_cal_mod($f, 7); }

// ── עברי ────────────────────────────────────────────────────
function sf_cal_hleap(int $y): bool { return sf_cal_mod(7 * $y + 1, 19) < 7; }

function sf_cal_elapsed(int $y): int
{
    $months = sf_cal_fl((235 * $y - 234) / 19);
    $parts = 12084 + 13753 * $months;
    $day = 29 * $months + sf_cal_fl($parts / 25920);
    return sf_cal_mod(3 * ($day + 1), 7) < 3 ? $day + 1 : $day;
}

function sf_cal_new_year(int $y): int
{
    static $memo = [];
    if (isset($memo[$y])) return $memo[$y];
    $a = sf_cal_elapsed($y - 1); $b = sf_cal_elapsed($y); $c = sf_cal_elapsed($y + 1);
    $delay = $c - $b === 356 ? 2 : ($b - $a === 382 ? 1 : 0);
    return $memo[$y] = SF_CAL_EPOCH + $b + $delay;
}

function sf_cal_year_days(int $y): int { return sf_cal_new_year($y + 1) - sf_cal_new_year($y); }

function sf_cal_len_r(int $m, int $y): int
{
    $n = sf_cal_year_days($y);
    if (in_array($m, [2, 4, 6, 10, 13], true)) return 29;
    if ($m === 12 && !sf_cal_hleap($y)) return 29;
    if ($m === 8 && $n % 10 !== 5) return 29;
    if ($m === 9 && $n % 10 === 3) return 29;
    return 30;
}

function sf_cal_fixed_from_heb_r(int $y, int $m, int $d): int
{
    $f = sf_cal_new_year($y) + $d - 1;
    $last = sf_cal_hleap($y) ? 13 : 12;
    if ($m < 7) {
        for ($k = 7; $k <= $last; $k++) $f += sf_cal_len_r($k, $y);
        for ($k = 1; $k < $m; $k++) $f += sf_cal_len_r($k, $y);
    } else {
        for ($k = 7; $k < $m; $k++) $f += sf_cal_len_r($k, $y);
    }
    return $f;
}

function sf_cal_valid_month(mixed $m, int $y): bool
{
    return is_int($m) && $m >= 1 && $m <= 13 && !($m === 12 && !sf_cal_hleap($y));
}

function sf_cal_to_r(int $m, int $y): int { return !sf_cal_hleap($y) && $m === 13 ? 12 : $m; }
function sf_cal_from_r(int $m, int $y): int { return !sf_cal_hleap($y) && $m === 12 ? 13 : $m; }

/** אורך החודש (מזהה ש6); 0 — לא קיים בשנה הזו */
function sf_cal_month_len(int $y, int $m): int
{
    return sf_cal_valid_month($m, $y) ? sf_cal_len_r(sf_cal_to_r($m, $y), $y) : 0;
}

function sf_cal_heb_from_fixed(int $f): array
{
    $approx = sf_cal_fl(($f - SF_CAL_EPOCH) / (35975351 / 98496)) + 1;
    $y = $approx - 1;
    while (sf_cal_new_year($y + 1) <= $f) $y++;
    $m = $f < sf_cal_fixed_from_heb_r($y, 1, 1) ? 7 : 1;
    while ($f > sf_cal_fixed_from_heb_r($y, $m, sf_cal_len_r($m, $y))) $m++;
    return ['y' => $y, 'm' => sf_cal_from_r($m, $y), 'd' => $f - sf_cal_fixed_from_heb_r($y, $m, 1) + 1, 'leap' => sf_cal_hleap($y)];
}

/** null — התאריך לא קיים בשנה הזו */
function sf_cal_fixed_from_heb(int $y, int $m, int $d): ?int
{
    if (!sf_cal_valid_month($m, $y) || $d < 1 || $d > sf_cal_len_r(sf_cal_to_r($m, $y), $y)) return null;
    return sf_cal_fixed_from_heb_r($y, sf_cal_to_r($m, $y), $d);
}

function sf_cal_to_hebrew(int $y, int $m, int $d): array { return sf_cal_heb_from_fixed(sf_cal_fixed_from_greg($y, $m, $d)); }

function sf_cal_from_hebrew(int $y, int $m, int $d): ?array
{
    $f = sf_cal_fixed_from_heb($y, $m, $d);
    return $f === null ? null : sf_cal_greg_from_fixed($f);
}

const SF_CAL_MONTHS = [1 => 'ניסן', 2 => 'אייר', 3 => 'סיון', 4 => 'תמוז', 5 => 'אב', 6 => 'אלול', 7 => 'תשרי', 8 => 'חשון', 9 => 'כסלו',
    10 => 'טבת', 11 => 'שבט', 12 => "אדר א'", 13 => "אדר ב'"];
const SF_CAL_ORDER = [7, 8, 9, 10, 11, 12, 13, 1, 2, 3, 4, 5, 6];

function sf_cal_month_name(int $m, ?bool $leap): string
{
    return $m === 13 && $leap === false ? 'אדר' : (SF_CAL_MONTHS[$m] ?? '');
}

function sf_cal_gem(int $n): string
{
    $ones = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
    $tens = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
    $hund = ['', 'ק', 'ר', 'ש', 'ת', 'תק', 'תר', 'תש', 'תת', 'תתק'];
    $n = (int)sf_cal_mod($n, 1000);
    $s = $hund[intdiv($n, 100)];
    $r = $n % 100;
    $s .= $r === 15 ? 'טו' : ($r === 16 ? 'טז' : $tens[intdiv($r, 10)] . $ones[$r % 10]);
    if ($s === '') return '';
    return mb_strlen($s) === 1 ? $s . "'" : mb_substr($s, 0, -1) . '"' . mb_substr($s, -1);
}

function sf_cal_heb_text(array $h): string
{
    return sf_cal_gem($h['d']) . ' ' . sf_cal_month_name($h['m'], $h['leap']) . ' ' . sf_cal_gem($h['y']);
}

/** "היום" בשרת — התאריך האזרחי לפי UTC (§4: מרווח של יום אחד לאזורי הזמן — sf_halacha_*) */
function sf_cal_server_fixed(): int
{
    $t = sf_time();
    return sf_cal_fixed_from_greg((int)gmdate('Y', $t), (int)gmdate('n', $t), (int)gmdate('j', $t));
}

// ── אזור זמן ────────────────────────────────────────────────
function sf_cal_tz(string $tz): DateTimeZone
{
    static $memo = [];
    return $memo[$tz] ??= new DateTimeZone($tz);
}

function sf_cal_valid_tz(mixed $tz): bool
{
    return is_string($tz) && in_array($tz, DateTimeZone::listIdentifiers(), true);
}

function sf_cal_dt(int $ms): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . sf_cal_fl($ms / 1000)));
}

/** ההפרש מ-UTC בדקות */
function sf_cal_tz_offset(string $tz, int $ms): int
{
    return intdiv(sf_cal_tz($tz)->getOffset(sf_cal_dt($ms)), 60);
}

function sf_cal_local_parts(string $tz, int $ms): array
{
    $d = sf_cal_dt($ms)->setTimezone(sf_cal_tz($tz));
    return ['y' => (int)$d->format('Y'), 'm' => (int)$d->format('n'), 'd' => (int)$d->format('j'), 'hh' => (int)$d->format('G'), 'mm' => (int)$d->format('i'), 'ss' => (int)$d->format('s')];
}

function sf_cal_zoned_to_utc(string $tz, int $y, int $m, int $d, int $hh, int $mm): int
{
    $wall = (sf_cal_fixed_from_greg($y, $m, $d) - 719163) * SF_CAL_DAY + ($hh * 60 + $mm) * 60000;   // 719163 = 1.1.1970
    $t = $wall - sf_cal_tz_offset($tz, $wall) * 60000;
    return $wall - sf_cal_tz_offset($tz, $t) * 60000;
}

// ── השמש (NOAA) ─────────────────────────────────────────────
function sf_cal_solar(float $ms): array
{
    $rad = fn($x) => $x * M_PI / 180;
    $deg = fn($x) => $x * 180 / M_PI;
    $T = ($ms / SF_CAL_DAY + 2440587.5 - 2451545) / 36525;
    $L0 = sf_cal_mod(280.46646 + $T * (36000.76983 + $T * 0.0003032), 360);
    $M = 357.52911 + $T * (35999.05029 - 0.0001537 * $T);
    $e = 0.016708634 - $T * (0.000042037 + 0.0000001267 * $T);
    $C = sin($rad($M)) * (1.914602 - $T * (0.004817 + 0.000014 * $T)) + sin($rad(2 * $M)) * (0.019993 - 0.000101 * $T) + sin($rad(3 * $M)) * 0.000289;
    $om = 125.04 - 1934.136 * $T;
    $lam = $L0 + $C - 0.00569 - 0.00478 * sin($rad($om));
    $eps = 23 + (26 + (21.448 - $T * (46.815 + $T * (0.00059 - $T * 0.001813))) / 60) / 60 + 0.00256 * cos($rad($om));
    $decl = $deg(asin(sin($rad($eps)) * sin($rad($lam))));
    $yy = tan($rad($eps / 2)) ** 2;
    $eq = 4 * $deg($yy * sin(2 * $rad($L0)) - 2 * $e * sin($rad($M)) + 4 * $e * $yy * sin($rad($M)) * cos(2 * $rad($L0))
        - 0.5 * $yy * $yy * sin(4 * $rad($L0)) - 1.25 * $e * $e * sin(2 * $rad($M)));
    return ['decl' => $decl, 'eq' => $eq];
}

function sf_cal_transit(float $lon, float $t0): float
{
    $n = $t0;
    for ($i = 0; $i < 2; $i++) {
        $day0 = floor($n / SF_CAL_DAY) * SF_CAL_DAY;
        $n = $day0 + (720 - 4 * $lon - sf_cal_solar($n)['eq']) * 60000;
        while ($n - $t0 > SF_CAL_DAY / 2) $n -= SF_CAL_DAY;
        while ($t0 - $n > SF_CAL_DAY / 2) $n += SF_CAL_DAY;
    }
    return $n;
}

function sf_cal_sun_at(float $lat, float $lon, float $t0, float $depth, string $dir): ?int
{
    $rad = fn($x) => $x * M_PI / 180;
    $t = sf_cal_transit($lon, $t0);
    for ($i = 0; $i < 3; $i++) {
        $decl = sf_cal_solar($t)['decl'];
        $cosH = (cos($rad(90 + $depth)) - sin($rad($lat)) * sin($rad($decl))) / (cos($rad($lat)) * cos($rad($decl)));
        if (!($cosH >= -1 && $cosH <= 1)) return null;
        $H = acos($cosH) * 180 / M_PI;
        $nn = sf_cal_transit($lon, $t);
        $t = $nn + ($dir === 'am' ? -1 : 1) * $H * 4 * 60000;
    }
    return sf_cal_round($t);
}

// ── השיטות ──────────────────────────────────────────────────
/** אותן בדיקות כמו methodErrors ב-JS. */
function sf_cal_method_errors(mixed $def): array
{
    if (!is_array($def) || !is_array($def['zmanim'] ?? null) || !array_is_list($def['zmanim'])) return ['חסרה רשימת הזמנים'];
    $errs = [];
    $n = count($def['zmanim']);
    if ($n < 1 || $n > 40) $errs[] = 'בין 1 ל-40 זמנים';
    $num = fn($v, $lo, $hi) => (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= $lo && $v <= $hi;
    $keys = [];
    foreach ($def['zmanim'] as $z) if (is_array($z) && is_string($z['key'] ?? null)) $keys[$z['key']] = true;
    $seen = [];
    foreach ($def['zmanim'] as $i => $z) {
        $at = 'זמן ' . ($i + 1) . ': ';
        if (!is_array($z)) { $errs[] = $at . 'לא תקין'; continue; }
        $key = $z['key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}\z/', $key)) $errs[] = $at . 'מזהה באנגלית קטנה, ספרות וקו תחתון';
        elseif (isset($seen[$key])) $errs[] = $at . 'המזהה "' . $key . '" כבר קיים';
        $name = $z['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 60) $errs[] = $at . 'שם בין 1 ל-60 תווים';
        $ref = fn($k) => is_string($k) && $k !== $key && isset($keys[$k]);
        $type = $z['type'] ?? null;
        if ($type === 'angle') {
            if (!$num($z['deg'] ?? null, -5, 30)) $errs[] = $at . 'זווית בין ‎-5 ל-30 מעלות';
            if (!in_array($z['dir'] ?? null, ['am', 'pm'], true)) $errs[] = $at . 'בוקר או ערב';
        } elseif ($type === 'offset') {
            if (!$ref($z['base'] ?? null)) $errs[] = $at . 'זמן הבסיס לא קיים בשיטה';
            if (!$num($z['min'] ?? null, -300, 300)) $errs[] = $at . 'דקות בין ‎-300 ל-300';
        } elseif ($type === 'hours') {
            if (!$ref($z['from'] ?? null) || !$ref($z['to'] ?? null)) $errs[] = $at . 'תחילת היום וסופו לא קיימים בשיטה';
            if (!$num($z['hours'] ?? null, 0, 12)) $errs[] = $at . 'שעות זמניות בין 0 ל-12';
        } else $errs[] = $at . 'סוג לא מוכר';
        if (is_string($key)) $seen[$key] = true;
    }
    if (!is_string($def['day_end'] ?? null) || !isset($seen[$def['day_end']])) $errs[] = 'חסר הזמן שבו מתחלף היום (צאת הכוכבים)';
    if (!$errs && sf_cal_order($def) === null) $errs[] = 'יש זמנים שתלויים זה בזה במעגל';
    return $errs;
}

function sf_cal_deps(array $z): array
{
    return $z['type'] === 'offset' ? [$z['base']] : ($z['type'] === 'hours' ? [$z['from'], $z['to']] : []);
}

/** סדר החישוב (כמו order ב-JS). null — מעגל. */
function sf_cal_order(array $def): ?array
{
    $done = [];
    $out = [];
    $n = count($def['zmanim']);
    for ($pass = 0; $pass <= $n && count($out) < $n; $pass++) {
        foreach ($def['zmanim'] as $z) {
            if (isset($done[$z['key']])) continue;
            foreach (sf_cal_deps($z) as $k) if (!isset($done[$k])) continue 2;
            $done[$z['key']] = true;
            $out[] = $z;
        }
    }
    return count($out) === $n ? $out : null;
}

/** הגדרה תקינה ← רק השדות הידועים, בסדר קבוע (מה שנשמר ומה שיוצא לדפדפן). */
function sf_cal_method_clean(array $def): array
{
    $out = [];
    foreach ($def['zmanim'] as $z) {
        $c = ['key' => $z['key'], 'name' => trim($z['name']), 'type' => $z['type']];
        if ($z['type'] === 'angle') $c += ['deg' => $z['deg'] + 0, 'dir' => $z['dir']];
        if ($z['type'] === 'offset') $c += ['base' => $z['base'], 'min' => $z['min'] + 0];
        if ($z['type'] === 'hours') $c += ['from' => $z['from'], 'to' => $z['to'], 'hours' => $z['hours'] + 0];
        $out[] = $c;
    }
    return ['day_end' => $def['day_end'], 'zmanim' => $out];
}

/** זמני היום לתאריך אזרחי במיקום ['lat', 'lon', 'tz'] → [['key', 'name', 'ms' => int|null]] */
function sf_cal_zmanim(array $def, array $place, int $y, int $m, int $d): array
{
    $t0 = sf_cal_zoned_to_utc($place['tz'], $y, $m, $d, 12, 0);
    $got = [];
    foreach (sf_cal_order($def) ?? [] as $z) {
        $ms = null;
        if ($z['type'] === 'angle') $ms = sf_cal_sun_at((float)$place['lat'], (float)$place['lon'], (float)$t0, (float)$z['deg'], $z['dir']);
        elseif ($z['type'] === 'offset') $ms = ($got[$z['base']] ?? null) === null ? null : $got[$z['base']] + sf_cal_round($z['min'] * 60000);
        elseif ($z['type'] === 'hours') $ms = ($got[$z['from']] ?? null) === null || ($got[$z['to']] ?? null) === null ? null
            : $got[$z['from']] + sf_cal_round((($got[$z['to']] - $got[$z['from']]) * $z['hours']) / 12);
        $got[$z['key']] = $ms;
    }
    return array_map(fn($z) => ['key' => $z['key'], 'name' => $z['name'], 'ms' => $got[$z['key']] ?? null], $def['zmanim']);
}

/** היום העברי ברגע $nowMs — כמו today() ב-JS */
function sf_cal_today(int $nowMs, ?array $place, ?array $def, string $deviceTz = 'UTC'): array
{
    $tz = $place['tz'] ?? $deviceTz;
    $p = sf_cal_local_parts($tz, $nowMs);
    $f = sf_cal_fixed_from_greg($p['y'], $p['m'], $p['d']);
    $after = false; $approx = null; $end = null;
    if ($place && $def && is_array($def['zmanim'] ?? null)) {
        foreach (sf_cal_zmanim($def, $place, $p['y'], $p['m'], $p['d']) as $z) if ($z['key'] === $def['day_end']) $end = $z['ms'];
        if ($end === null) $approx = 'no_tzeit';
        elseif ($nowMs >= $end) { $f++; $after = true; }
    } else $approx = 'no_method';
    $heb = sf_cal_heb_from_fixed($f);
    return ['greg' => sf_cal_greg_from_fixed($f), 'civil' => ['y' => $p['y'], 'm' => $p['m'], 'd' => $p['d']], 'fixed' => $f, 'heb' => $heb,
        'weekday' => sf_cal_weekday($f), 'monthLen' => sf_cal_month_len($heb['y'], $heb['m']), 'after' => $after, 'approx' => $approx, 'end' => $end];
}
