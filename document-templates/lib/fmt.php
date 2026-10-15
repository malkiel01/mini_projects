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

// ── מספר (כמו "עיצוב תאים" באקסל) — אותו מנוע כמו ב-assets/js/fmt.js ──
const FMT_COLORS = ['אדום' => '#c00000', 'red' => '#c00000', 'כחול' => '#0000ff', 'blue' => '#0000ff', 'ירוק' => '#008000', 'green' => '#008000',
    'שחור' => '#000000', 'black' => '#000000', 'צהוב' => '#b8860b', 'yellow' => '#b8860b', 'סגול' => '#800080', 'magenta' => '#800080'];

/** מפצל ל-; מחוץ למירכאות ולסוגריים. */
function fmt_sections(string $p): array
{
    $ch = mb_str_split($p);
    $out = [''];
    $q = $b = false;
    for ($i = 0, $n = count($ch); $i < $n; $i++) {
        $c = $ch[$i];
        if ($c === '\\' && !$q) { $out[count($out) - 1] .= $c . ($ch[$i + 1] ?? ''); $i++; continue; }
        if ($c === '"') $q = !$q;
        elseif (!$q && $c === '[') $b = true;
        elseif (!$q && $c === ']') $b = false;
        if ($c === ';' && !$q && !$b) { $out[] = ''; continue; }
        $out[count($out) - 1] .= $c;
    }
    return $out;
}

/** [צבע] → [החלק בלי התגיות, הצבע]. */
function fmt_color_tag(string $sec): array
{
    $color = null;
    $sec = preg_replace_callback('/\[([^\]]*)\]/u', function ($m) use (&$color) {
        $k = mb_strtolower(trim($m[1]));
        if (isset(FMT_COLORS[$k])) $color = FMT_COLORS[$k];
        return '';
    }, $sec);
    return [$sec, $color];
}

/** מסכה: תווי תבנית במקומם, טקסט קבוע (מירכאות, \x) — \x01. מחזיר [מערך תווים של המסכה, מערך הטקסט]. */
function fmt_masked(string $sec): array
{
    $ch = mb_str_split($sec);
    $m = $lit = [];
    for ($i = 0, $n = count($ch); $i < $n; $i++) {
        $c = $ch[$i];
        if ($c === '"') {
            $j = $i + 1;
            while ($j < $n && $ch[$j] !== '"') { $m[] = "\x01"; $lit[] = $ch[$j]; $j++; }
            $i = $j; continue;
        }
        if ($c === '\\') { $m[] = "\x01"; $lit[] = $ch[$i + 1] ?? ''; $i++; continue; }
        $m[] = $c; $lit[] = $c;
    }
    return [$m, $lit];
}

/** חיפוש ביטוי במסכה — מחזיר [התאמות, אינדקס בתווים] או null. */
function fmt_find(string $re, array $m): ?array
{
    $str = implode('', $m);
    if (!preg_match($re . 'u', $str, $r, PREG_OFFSET_CAPTURE)) return null;
    $groups = array_map(fn($g) => is_array($g) ? $g[0] : $g, $r);
    return [$groups, mb_strlen(substr($str, 0, $r[0][1]))];
}

function fmt_lit(array $lit, int $from, ?int $to = null): string
{
    return implode('', array_slice($lit, $from, $to === null ? null : $to - $from));
}

/** מספר לפי ליבה (#,##0.00), בלי סימן. */
function fmt_plain(string $core, float $n): string
{
    [$intPart, $frac] = array_pad(explode('.', $core), 2, '');
    $minDec = substr_count($frac, '0');
    $maxDec = strlen($frac);
    [$i, $f] = array_pad(explode('.', number_format(abs($n), $maxDec, '.', '')), 2, '');
    $f = substr($f, 0, $minDec) . rtrim(substr($f, $minDec), '0');
    $i = str_pad($i, substr_count($intPart, '0'), '0', STR_PAD_LEFT);
    if (str_contains($intPart, ',')) $i = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $i);
    if (substr_count($intPart, '0') === 0 && $i === '0' && $f !== '') $i = '';
    return $i . ($f !== '' ? '.' . $f : '');
}

/** מסכה של ספרות: הספרות ממלאות מימין; עודף — בתחילה. */
function fmt_digit_mask(array $m, array $lit, string $raw): string
{
    $ph = count(array_keys($m, '0', true));
    $dg = preg_replace('/\D/', '', $raw);
    if (strlen($dg) < $ph) $dg = str_pad($dg, $ph, '0', STR_PAD_LEFT);
    $out = '';
    $k = strlen($dg);
    for ($i = count($m) - 1; $i >= 0; $i--) $out = ($m[$i] === '0' ? $dg[--$k] : $lit[$i]) . $out;
    return substr($dg, 0, $k) . $out;
}

function fmt_is_mask(array $m): bool
{
    $s = implode('', $m);
    return (bool)preg_match('/^[0\-\s()\/\x01]+$/u', $s) && (bool)preg_match('/0[^0]+0/u', $s);
}

/** חלק אחד של מבנה מספר. $sign — להוסיף מינוס (כשאין חלק נפרד לשלילי). */
function fmt_num_section(string $sec, float $n, bool $sign): string
{
    [$m, $lit] = fmt_masked($sec);
    $minus = $sign && $n < 0 ? '-' : '';
    if (fmt_is_mask($m)) return $minus . fmt_digit_mask($m, $lit, (string)(int)round(abs($n)));
    if ($r = fmt_find('/([#0]+)(?:\.([#0]+))?E([+-])(0+)/', $m)) { // מדעי
        [$g, $at] = $r;
        $a = abs($n);
        $dec = strlen($g[2] ?? '');
        $e = $a == 0 ? 0 : (int)floor(log10($a));
        $mant = $a == 0 ? 0.0 : $a / 10 ** $e;
        if ((float)number_format($mant, $dec, '.', '') >= 10) { $e++; $mant /= 10; }
        $body = number_format($mant, $dec, '.', '') . 'E' . ($e < 0 ? '-' : ($g[3] === '+' ? '+' : '')) . str_pad((string)abs($e), strlen($g[4]), '0', STR_PAD_LEFT);
        return $minus . fmt_lit($lit, 0, $at) . $body . fmt_lit($lit, $at + mb_strlen($g[0]));
    }
    if ($r = fmt_find('/(?:([#0]+)\s+)?(\?+)\/(\?+|\d+)/', $m)) { // שבר
        [$g, $at] = $r;
        $a = abs($n);
        $fixed = ctype_digit($g[3]) ? (int)$g[3] : 0;
        $hasWhole = ($g[1] ?? '') !== '';
        $whole = $hasWhole ? (int)floor($a) : 0;
        $x = $a - $whole;
        if ($fixed) { $nu = (int)round($x * $fixed); $de = $fixed; }
        else {
            $nu = 0; $de = 1; $err = INF;
            for ($d = 1, $max = 10 ** strlen($g[3]) - 1; $d <= $max; $d++) {
                $nn = (int)round($x * $d); $ee = abs($x - $nn / $d);
                if ($ee < $err - 1e-12) { $err = $ee; $nu = $nn; $de = $d; }
            }
        }
        $w = $whole;
        if ($nu === $de) { $w++; $nu = 0; }
        $fr = $nu ? "$nu/$de" : '';
        $body = $hasWhole ? (($w || $fr === '') ? (string)$w : '') . ($w && $fr !== '' ? ' ' : '') . $fr : ($fr !== '' ? $fr : '0');
        return $minus . fmt_lit($lit, 0, $at) . $body . fmt_lit($lit, $at + mb_strlen($g[0]));
    }
    $r = fmt_find('/[#0,]*[#0](?:\.[#0]+)?/', $m);
    if (!$r) return fmt_lit($lit, 0);
    [$g, $at] = $r;
    $pct = in_array('%', $m, true) ? 100 : 1;
    return $minus . fmt_lit($lit, 0, $at) . fmt_plain($g[0], $n * $pct) . fmt_lit($lit, $at + mb_strlen($g[0]));
}

/** מספר לפי מבנה → [טקסט, צבע]. */
function fmt_number_rich(string $pattern, string $raw): array
{
    $s = trim($raw);
    if ($s === '' || !is_numeric($s)) return [$s, null];
    $n = (float)$s;
    $secs = fmt_sections($pattern);
    $sec = $secs[0]; $sign = true; $neg = false;
    if ($n < 0 && count($secs) >= 2) { $sec = $secs[1]; $sign = false; $neg = true; }
    elseif ($n == 0 && count($secs) >= 3) { $sec = $secs[2]; }
    [$body, $color] = fmt_color_tag($sec);
    return [fmt_num_section($body, $neg ? abs($n) : $n, $sign), $color];
}

function fmt_number(string $pattern, string $raw): string
{
    return fmt_number_rich($pattern, $raw)[0];
}

/** טקסט לפי מבנה: @ — הערך עצמו; בלי @ ועם 0 — מסכה של ספרות (טלפון, ת"ז). */
function fmt_text_rich(string $pattern, string $raw): array
{
    $secs = fmt_sections($pattern);
    [$sec, $color] = fmt_color_tag($secs[count($secs) >= 4 ? 3 : 0]);
    [$m, $lit] = fmt_masked($sec);
    if (in_array('@', $m, true)) return [implode('', array_map(fn($c, $l) => $c === '@' ? $raw : $l, $m, $lit)), $color];
    if (in_array('0', $m, true) && preg_match('/\d/', $raw)) return [fmt_digit_mask($m, $lit, $raw), $color];
    return [$raw, $color];
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
    if (fmt_is_date($f)) return fmt_date(fmt_color_tag($f['fmt'])[0], $raw);
    if (fmt_is_number($f)) return fmt_number($f['fmt'], $raw);
    if (($f['type'] ?? '') === 'text') return fmt_text_rich($f['fmt'], $raw)[0];
    return null;
}

// ── מבנים שמורים (כמו "מותאם אישית" באקסל): קוד שהמשתמש כתב — נשמר לרשימה שלו, בכל התבניות ──
const FMT_KINDS = ['number', 'date', 'text'];

function fmt_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS fmt_codes (
            id         INTEGER PRIMARY KEY,
            owner_id   INTEGER NOT NULL REFERENCES users(id),
            kind       TEXT NOT NULL,
            code       TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (owner_id, kind, code)
        );
        SQL);
}

function fmt_codes(array $user, array $q): array
{
    $st = db()->prepare('SELECT id, kind, code FROM fmt_codes WHERE owner_id = ? ORDER BY id');
    $st->execute([$user['id']]);
    return ['ok' => true, 'codes' => array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'code' => $r['code']], $st->fetchAll())];
}

function fmt_code_save(array $user, array $b): array
{
    $kind = in_array($b['kind'] ?? '', FMT_KINDS, true) ? $b['kind'] : throw new ApiError('סוג לא מוכר', 422);
    $code = mb_substr(trim((string)($b['code'] ?? '')), 0, 80);
    if ($code === '') throw new ApiError('המבנה ריק', 422);
    $n = db()->prepare('SELECT COUNT(*) FROM fmt_codes WHERE owner_id = ?');
    $n->execute([$user['id']]);
    if ((int)$n->fetchColumn() >= 100) throw new ApiError('עד 100 מבנים שמורים — מחקו כמה', 409);
    db()->prepare('INSERT OR IGNORE INTO fmt_codes (owner_id, kind, code, created_at) VALUES (?, ?, ?, ?)')->execute([$user['id'], $kind, $code, now_iso()]);
    return ['ok' => true];
}

function fmt_code_delete(array $user, array $b): array
{
    $st = db()->prepare('DELETE FROM fmt_codes WHERE id = ? AND owner_id = ?');
    $st->execute([(int)($b['id'] ?? 0), $user['id']]);
    if (!$st->rowCount()) throw new ApiError('המבנה לא נמצא', 404);
    return ['ok' => true];
}

