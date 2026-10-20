<?php
/**
 * הגדרות עמוד (embed.md, פרק 5): גודל, כיוון, שוליים (מ"מ), גופן וגדלים (נקודות), סגנונות,
 * מספור עמודים, כותרת עליונה ותחתונה. שדה שלא נשלח — ברירת המחדל.
 */

const PAGE_SIZES = ['A4' => [210, 297], 'Letter' => [215.9, 279.4]];
const PAGE_NUM_FORMATS = ['n', 'page_n', 'n_of_m', 'dash_n', 'hebrew'];
const STYLE_KEYS = ['title', 'h1', 'h2', 'h3', 'normal'];
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
            'normal' => ['before' => 0, 'after' => 6, 'line' => 1.15],
        ],
        'numbering' => ['show' => true, 'position' => 'bottom', 'align' => 'center', 'format' => 'n', 'start' => 1, 'first_page' => true],
        'header' => '', 'footer' => '',
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
    foreach (STYLE_KEYS as $s) {
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
    return $p;
}

function font_name(string $f): string
{
    $f = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $f));
    return mb_substr($f, 0, 60);
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
