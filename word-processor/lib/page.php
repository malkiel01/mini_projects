<?php
/**
 * הגדרות עמוד (embed.md, פרק 5): גודל, כיוון, שוליים (מ"מ), גופן וגדלים (נקודות), סגנונות,
 * מספור עמודים, כותרת עליונה ותחתונה. שדה שלא נשלח — ברירת המחדל.
 */

const PAGE_SIZES = ['A4' => [210, 297], 'Letter' => [215.9, 279.4]];
const PAGE_NUM_FORMATS = ['n', 'page_n', 'n_of_m', 'dash_n', 'hebrew'];
const STYLE_KEYS = ['title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8', 'h9', 'normal'];
/** סגנון שהמשתמש יצר: u1, u2… (עם name). עד USER_STYLES_MAX. */
const USER_STYLE_RE = '/^u[1-9]\d{0,2}$/';
const USER_STYLES_MAX = 60;
/** סוגי קו תחתון (underline.attrs.ul) — מעבר ל"רגיל". */
const UNDERLINES = ['double', 'dotted', 'dashed', 'wavy', 'thick'];
/** עצירות טאב: כל חצי אינץ' (36 נק'), כמו ברירת המחדל של Word ו-tab-size בעורך. */
const TAB_STOP = 36.0;
const FONTS = ['Arial', 'David', 'Frank Ruehl', 'Narkisim', 'Miriam', 'Times New Roman', 'Calibri', 'Tahoma',
    'David Libre', 'Frank Ruhl Libre', 'Miriam Libre', 'Heebo', 'Assistant', 'Rubik', 'Alef'];

function page_defaults(): array
{
    return [
        'size' => 'A4', 'orientation' => 'portrait',
        'margins' => ['top' => 25, 'bottom' => 25, 'right' => 25, 'left' => 25],
        'font' => ['family' => 'David', 'size' => 12],
        'styles' => [
            'title' => ['size' => 22, 'bold' => true, 'color' => '#1f3864', 'align' => 'center', 'before' => 0, 'after' => 12],
            'h1' => ['size' => 16, 'bold' => true, 'color' => '#1f3864', 'before' => 12, 'after' => 6],
            'h2' => ['size' => 14, 'bold' => true, 'color' => '#2f5496', 'before' => 10, 'after' => 4],
            'h3' => ['size' => 12, 'bold' => true, 'color' => '#2f5496', 'before' => 8, 'after' => 4],
            // 4–9 — כמו ב-Word: קטנות יותר, נטוי / צבע מבדילים
            'h4' => ['size' => 12, 'bold' => true, 'italic' => true, 'color' => '#2f5496', 'before' => 6, 'after' => 2],
            'h5' => ['size' => 12, 'color' => '#2f5496', 'before' => 6, 'after' => 2],
            'h6' => ['size' => 12, 'color' => '#1f3763', 'before' => 4, 'after' => 2],
            'h7' => ['size' => 12, 'italic' => true, 'color' => '#1f3763', 'before' => 4, 'after' => 2],
            'h8' => ['size' => 10.5, 'color' => '#272727', 'before' => 4, 'after' => 2],
            'h9' => ['size' => 10.5, 'italic' => true, 'color' => '#272727', 'before' => 4, 'after' => 2],
            'normal' => ['before' => 0, 'after' => 6, 'line' => 1.15],
        ],
        'numbering' => ['show' => true, 'position' => 'bottom', 'align' => 'center', 'format' => 'n', 'start' => 1, 'first_page' => true],
        'header' => '', 'footer' => '', 'heading_numbers' => false,
        // רקע הדף (כל המסמך): צבע עמוד, וסימן מים — טקסט באלכסון מאחורי הטקסט
        'page_color' => '', 'watermark' => '',
    ];
}

/** ממזג הגדרות שהגיעו (חלקיות) על בסיס, ומנקה כל ערך. לא זורק — ערך לא תקין נשאר כמו בבסיס. */
function page_normalize($in, ?array $base = null): array
{
    $p = $base ? page_normalize($base) : page_defaults();
    if (!is_array($in)) return $p;
    $num = fn($v, $min, $max) => is_numeric($v) ? max($min, min($max, round((float)$v, 2))) : null;

    if (isset($in['size']) && isset(PAGE_SIZES[$in['size']])) $p['size'] = $in['size'];
    if (in_array($in['orientation'] ?? null, ['portrait', 'landscape'], true)) $p['orientation'] = $in['orientation'];
    foreach (['top', 'bottom', 'right', 'left'] as $k) {
        if (($v = $num($in['margins'][$k] ?? null, 0, 80)) !== null) $p['margins'][$k] = $v;
    }
    if (is_array($in['font'] ?? null)) {
        if (is_string($in['font']['family'] ?? null) && ($f = font_name($in['font']['family'])) !== '') $p['font']['family'] = $f;
        if (($v = $num($in['font']['size'] ?? null, 6, 72)) !== null) $p['font']['size'] = $v;
    }
    // סגנונות של המשתמש: מפתח u<N> עם שם; null — מחיקה
    $keys = STYLE_KEYS;
    foreach (array_keys(is_array($in['styles'] ?? null) ? $in['styles'] : []) as $k) {
        if (!is_string($k) || !preg_match(USER_STYLE_RE, $k)) continue;
        if ($in['styles'][$k] === null) { unset($p['styles'][$k]); continue; }
        $name = is_string($in['styles'][$k]['name'] ?? null) ? style_name($in['styles'][$k]['name']) : ($p['styles'][$k]['name'] ?? '');
        if ($name === '' || (!isset($p['styles'][$k]) && count(array_filter(array_keys($p['styles']), fn($x) => preg_match(USER_STYLE_RE, (string)$x))) >= USER_STYLES_MAX)) continue;
        $p['styles'][$k] = ['name' => $name] + ($p['styles'][$k] ?? []);
        $keys[] = $k;
    }
    foreach ($keys as $s) {
        $src = $in['styles'][$s] ?? null;
        if (!is_array($src)) continue;
        $st = $p['styles'][$s];
        if (array_key_exists('font', $src)) {
            $f = is_string($src['font']) ? font_name($src['font']) : '';
            if ($f === '') unset($st['font']); else $st['font'] = $f;
        }
        foreach (['size' => [6, 96], 'before' => [0, 200], 'after' => [0, 200], 'line' => [0.5, 5]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $src)) {
                $v = $num($src[$k], $lo, $hi);
                if ($v === null) unset($st[$k]); else $st[$k] = $v;
            }
        }
        foreach (['bold', 'italic', 'underline'] as $k) {
            if (array_key_exists($k, $src)) $st[$k] = (bool)$src[$k];
        }
        if (array_key_exists('color', $src)) {
            if (is_string($src['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $src['color'])) $st['color'] = strtolower($src['color']);
            else unset($st['color']);
        }
        if (array_key_exists('align', $src)) {
            if (in_array($src['align'], ['right', 'center', 'left', 'justify'], true)) $st['align'] = $src['align'];
            else unset($st['align']);
        }
        $p['styles'][$s] = $st;
    }
    if (is_array($in['numbering'] ?? null)) {
        $n = $in['numbering'];
        if (isset($n['show'])) $p['numbering']['show'] = (bool)$n['show'];
        if (in_array($n['position'] ?? null, ['top', 'bottom'], true)) $p['numbering']['position'] = $n['position'];
        if (in_array($n['align'] ?? null, ['right', 'center', 'left'], true)) $p['numbering']['align'] = $n['align'];
        if (in_array($n['format'] ?? null, PAGE_NUM_FORMATS, true)) $p['numbering']['format'] = $n['format'];
        if (($v = $num($n['start'] ?? null, 0, 9999)) !== null) $p['numbering']['start'] = (int)$v;
        if (isset($n['first_page'])) $p['numbering']['first_page'] = (bool)$n['first_page'];
    }
    foreach (['header', 'footer'] as $k) {
        if (is_string($in[$k] ?? null)) $p[$k] = mb_substr(str_replace(["\r", "\0"], '', $in[$k]), 0, 500);
    }
    if (isset($in['heading_numbers'])) $p['heading_numbers'] = (bool)$in['heading_numbers'];
    if (array_key_exists('page_color', $in)) {
        $c = is_string($in['page_color']) ? strtolower(trim($in['page_color'])) : '';
        $p['page_color'] = preg_match('/^#[0-9a-f]{6}$/', $c) && $c !== '#ffffff' ? $c : '';
    }
    if (is_string($in['watermark'] ?? null)) $p['watermark'] = mb_substr(trim(preg_replace('/[\x00-\x1f]+/u', ' ', $in['watermark'])), 0, 40);
    return $p;
}

function font_name(string $f): string
{
    $f = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $f));
    return mb_substr($f, 0, 60);
}

/**
 * מספור כותרות: לכל כותרת במסמך (לפי הסדר) — attrs._hnum, אותה מחרוזת כמו המונים של העורך (headingNumbersCss):
 * כותרת 1 — "N. ", עמוקה יותר — "N.M " (רמה שדולגה — 0).
 */
function heading_numbers(array $json): array
{
    $c = array_fill(1, 9, 0);
    $walk = function (array $n) use (&$walk, &$c): array {
        if (($n['type'] ?? '') === 'heading') {
            $l = max(1, min(9, (int)($n['attrs']['level'] ?? 1)));
            $c[$l]++;
            for ($k = $l + 1; $k <= 9; $k++) $c[$k] = 0;
            $n['attrs']['_hnum'] = implode('.', array_slice($c, 0, $l)) . ($l === 1 ? '. ' : ' ');
        }
        if (is_array($n['content'] ?? null)) $n['content'] = array_map($walk, $n['content']);
        return $n;
    };
    return $walk($json);
}

/** גודל סימן המים (נק'): לפי האלכסון של העמוד ומספר התווים, בין 24 ל-120 — אותה נוסחה בעורך (watermarkSize). */
function watermark_size(string $text, float $wPt, float $hPt): float
{
    $n = max(1, mb_strlen($text));
    return round(max(24, min(120, sqrt($wPt * $wPt + $hPt * $hPt) * 0.6 / ($n * 0.6))), 1);
}

/** שם סגנון של משתמש: טקסט קצר בלי תווי בקרה. */
function style_name(string $s): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f<>]/u', '', $s)), 0, 40);
}

/** מפתח הסגנון של בלוק (paragraph / heading) — h1..h9, title, u<N> שקיים בהגדרות, או normal. */
function block_style_key(array $n, array $page): string
{
    if (($n['type'] ?? '') === 'heading') return 'h' . max(1, min(9, (int)($n['attrs']['level'] ?? 1)));
    $ps = $n['attrs']['pstyle'] ?? null;
    return is_string($ps) && ($ps === 'title' || (preg_match(USER_STYLE_RE, $ps) && isset($page['styles'][$ps]))) ? $ps : 'normal';
}

/** גודל העמוד במ"מ לפי הכיוון: [רוחב, גובה]. */
function page_mm(array $p): array
{
    [$w, $h] = PAGE_SIZES[$p['size']] ?? PAGE_SIZES['A4'];
    return $p['orientation'] === 'landscape' ? [$h, $w] : [$w, $h];
}

/** מספר עמוד בתבנית (גם בדפדפן: assets/js/pagination.js, pageLabel). */
function page_label(int $n, int $total, string $format): string
{
    return match ($format) {
        'page_n' => "עמ' $n",
        'n_of_m' => "$n מתוך $total",
        'dash_n' => "- $n -",
        'hebrew' => hebrew_letters($n),
        default => (string)$n,
    };
}

/** א, ב, … ט, י, יא … כמו "hebrew1" ב-Word (בלי גרשיים). */
/** מספר רומי קטן (i, ii, iii…) — מספור הערות הסיום, כמו romanLower בעורך. */
function roman_lower(int $n): string
{
    $out = '';
    foreach ([1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i'] as $v => $r) {
        while ($n >= $v) { $out .= $r; $n -= $v; }
    }
    return $out;
}

function hebrew_letters(int $n): string
{
    if ($n <= 0) return (string)$n;
    $out = '';
    foreach ([400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק'] as $v => $l) while ($n >= $v) { $out .= $l; $n -= $v; }
    if ($n === 15) return $out . 'טו';
    if ($n === 16) return $out . 'טז';
    $tens = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
    $ones = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
    return $out . $tens[intdiv($n, 10)] . $ones[$n % 10];
}
