<?php
/**
 * מבנים מותאמים אישית לשדה — תאריך (לועזי, עברי, משולב) ומספר. אותו מנוע
 * בדיוק כמו assets/js/fmt.js (ה-PDF נבנה בדפדפן, ה-Word כאן), ואותם מקרי
 * בדיקה (tests/fmt-vectors.json). הלוח העברי — חישוב עצמאי, נבדק מול
 * jdtojewish של PHP על 250 שנה.
 */

declare(strict_types=1);

const FMT_MONTHS = ['ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'];
const FMT_DAYS = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
const FMT_HMONTHS = [1 => 'ניסן', 2 => 'אייר', 3 => 'סיוון', 4 => 'תמוז', 5 => 'אב', 6 => 'אלול', 7 => 'תשרי', 8 => 'חשוון',
    9 => 'כסלו', 10 => 'טבת', 11 => 'שבט', 12 => 'אדר', 13 => 'אדר ב׳'];
const FMT_TOKENS = ['hMMMM', 'hyyyy', 'hyy', 'hd', 'dddd', 'MMMM', 'yyyy', 'yy', 'dd', 'MM', 'd', 'M'];

function heb_leap(int $y): bool { return (7 * $y + 1) % 19 < 7; }

function heb_elapsed(int $y): int
{
    $m = 235 * intdiv($y - 1, 19) + 12 * (($y - 1) % 19) + intdiv(7 * (($y - 1) % 19) + 1, 19);
    $parts = 204 + 793 * ($m % 1080);
    $hours = 5 + 12 * $m + 793 * intdiv($m, 1080) + intdiv($parts, 1080);
    $day = 1 + 29 * $m + intdiv($hours, 24);
    $p = 1080 * ($hours % 24) + $parts % 1080;
    $alt = ($p >= 19440 || ($day % 7 === 2 && $p >= 9924 && !heb_leap($y)) || ($day % 7 === 1 && $p >= 16789 && heb_leap($y - 1))) ? $day + 1 : $day;
    return in_array($alt % 7, [0, 3, 5], true) ? $alt + 1 : $alt;
}

function heb_month_days(int $y, int $m): int
{
    $len = heb_elapsed($y + 1) - heb_elapsed($y);
    if (in_array($m, [2, 4, 6, 10, 13], true)) return 29;
    if ($m === 12 && !heb_leap($y)) return 29;
    if ($m === 8 && $len % 10 !== 5) return 29;
    if ($m === 9 && $len % 10 === 3) return 29;
    return 30;
}

/** ימים מ-1/1/1970 עד ראש השנה — לפי ראש השנה תשפ״ז = 12/9/2026. */
function heb_new_year(int $y): int
{
    static $epoch = null;
    $epoch ??= intdiv(gmmktime(0, 0, 0, 9, 12, 2026), 86400) - heb_elapsed(5787);
    return $epoch + heb_elapsed($y);
}

/** yyyy-mm-dd → ['y', 'm', 'd', 'leap'] עברי (חודשים מניסן = 1, תשרי = 7). */
function heb_date(string $iso): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $mm)) return null;
    $n = intdiv(gmmktime(0, 0, 0, (int)$mm[2], (int)$mm[3], (int)$mm[1]), 86400);
    $y = (int)$mm[1] + 3760;
    while (heb_new_year($y + 1) <= $n) $y++;
    while (heb_new_year($y) > $n) $y--;
    $left = $n - heb_new_year($y);
    $order = array_merge([7, 8, 9, 10, 11, 12], heb_leap($y) ? [13] : [], [1, 2, 3, 4, 5, 6]);
    foreach ($order as $m) {
        $len = heb_month_days($y, $m);
        if ($left < $len) return ['y' => $y, 'm' => $m, 'd' => $left + 1, 'leap' => heb_leap($y)];
        $left -= $len;
    }
    return null;
}

function gematria(int $n): string
{
    $L = [400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק', 90 => 'צ', 80 => 'פ', 70 => 'ע', 60 => 'ס', 50 => 'נ',
        40 => 'מ', 30 => 'ל', 20 => 'כ', 10 => 'י', 9 => 'ט', 8 => 'ח', 7 => 'ז', 6 => 'ו', 5 => 'ה', 4 => 'ד', 3 => 'ג', 2 => 'ב', 1 => 'א'];
    $s = '';
    $n %= 1000;
    while ($n >= 400) { $s .= 'ת'; $n -= 400; }
    if ($n % 100 === 15 || $n % 100 === 16) {
        $h = $n - $n % 100;
        if ($h) $s .= $L[$h];
        return gematria_marks($s . ($n % 100 === 15 ? 'טו' : 'טז'));
    }
    foreach ($L as $v => $ch) while ($n >= $v) { $s .= $ch; $n -= $v; }
    return gematria_marks($s);
}

function gematria_marks(string $s): string
{
    $chars = mb_str_split($s);
    if (count($chars) === 1) return $s . '׳';
    $last = array_pop($chars);
    return implode('', $chars) . '״' . $last;
}

function fmt_date(string $pattern, string $iso): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return $iso;
    [$Y, $Mo, $D] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    $h = null;
    $heb = function () use (&$h, $m) { return $h ??= heb_date("$m[1]-$m[2]-$m[3]"); };
    $val = fn(string $t): string => match ($t) {
        'd' => (string)$D, 'dd' => $m[3], 'M' => (string)$Mo, 'MM' => $m[2], 'MMMM' => FMT_MONTHS[$Mo - 1],
        'yy' => substr($m[1], 2), 'yyyy' => $m[1],
        'dddd' => 'יום ' . FMT_DAYS[(int)gmdate('w', gmmktime(0, 0, 0, $Mo, $D, $Y))],
        'hd' => gematria($heb()['d']),
        'hMMMM' => $heb()['m'] === 12 && $heb()['leap'] ? 'אדר א׳' : FMT_HMONTHS[$heb()['m']],
        'hyy' => gematria($heb()['y']), 'hyyyy' => 'ה׳' . gematria($heb()['y']),
    };
    $out = '';
    $len = strlen($pattern);
    for ($i = 0; $i < $len;) {
        if ($pattern[$i] === "'") {
            $j = strpos($pattern, "'", $i + 1);
            $out .= $j === false ? substr($pattern, $i + 1) : substr($pattern, $i + 1, $j - $i - 1);
            $i = $j === false ? $len : $j + 1;
            continue;
        }
        foreach (FMT_TOKENS as $t) {
            if (substr_compare($pattern, $t, $i, strlen($t)) === 0) { $out .= $val($t); $i += strlen($t); continue 2; }
        }
        $out .= $pattern[$i++];   // בייטים — תווים עבריים עוברים כמו שהם
    }
    return $out;
}

function fmt_number(string $pattern, string $raw): string
{
    $s = trim($raw);
    if ($s === '' || !is_numeric($s)) return $s;
    if (!preg_match('/[#0,]*[#0](?:\.[#0]+)?/', $pattern, $core, PREG_OFFSET_CAPTURE)) return $s;
    [$c, $at] = $core[0];
    $n = (float)$s * (str_contains($pattern, '%') ? 100 : 1);
    [$intPart, $frac] = array_pad(explode('.', $c), 2, '');
    $minDec = substr_count($frac, '0');
    $maxDec = strlen($frac);
    // ספרות עשרוניות: חובה עד מספר האפסים, ואחריהן — רק מה שאינו אפס בסוף
    [$i, $f] = array_pad(explode('.', number_format(abs($n), $maxDec, '.', '')), 2, '');
    $f = substr($f, 0, $minDec) . rtrim(substr($f, $minDec), '0');
    $str = $i . ($f !== '' ? '.' . $f : '');
    [$i, $f] = array_pad(explode('.', $str), 2, '');
    $i = str_pad($i, substr_count($intPart, '0'), '0', STR_PAD_LEFT);
    if (str_contains($intPart, ',')) $i = number_format((float)$i, 0, '.', ',');
    if (substr_count($intPart, '0') === 0 && $i === '0' && $f !== '') $i = '';
    return ($n < 0 ? '-' : '') . substr($pattern, 0, $at) . $i . ($f !== '' ? '.' . $f : '') . substr($pattern, $at + strlen($c));
}

function fmt_is_date(array $f): bool
{
    return $f['type'] === 'date' || ($f['type'] === 'calc' && ($f['format'] ?? '') === 'date')
        || ($f['type'] === 'system' && in_array($f['source'] ?? '', ['issued', 'completed'], true));
}

function fmt_is_number(array $f): bool
{
    return $f['type'] === 'number' || ($f['type'] === 'calc' && ($f['format'] ?? '') !== 'date');
}

/** הערך להצגה לפי המבנה של השדה, או null — אין מבנה מותאם. */
function fmt_apply(array $f, string $raw): ?string
{
    if (($f['fmt'] ?? '') === '' || $raw === '') return null;
    if (fmt_is_date($f)) return fmt_date($f['fmt'], $raw);
    if (fmt_is_number($f)) return fmt_number($f['fmt'], $raw);
    return null;
}
