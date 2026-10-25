<?php
/**
 * רשימת ציור → PDF: הציור בלבד, בלי שבירת שורות — למי שמעמד בעצמו (העימוד, creative-suite/docs/requests/
 * layout-studio-pdf.md). אותו כותב של עורך התמלילים (writer.php, font.php), והגופנים — subset.
 *
 * יחידות: נקודות (pt). y — מלמעלה למטה, מהפינה העליונה של העמוד (TrimBox). צבע: "#rrggbb".
 *
 *   { "title": "…", "pages": [ { "size": [w, h], "bleed": 9, "ops": [
 *       { "op": "glyphs", "font": "media/….ttf", "size": 12, "color": "#000000", "x": 0, "y": 0,
 *         "glyphs": [[gid, dx, dy, adv], …], "text": "…" },
 *       { "op": "image", "src": "media/….png", "box": [x, y, w, h], "clip": [x, y, w, h] },
 *       { "op": "rect", "box": [x, y, w, h], "fill": "#…", "stroke": "#…", "width": 1 },
 *       { "op": "line", "from": [x, y], "to": [x, y], "color": "#…", "width": 1 } ] } ] }
 *
 * glyphs: נקודת ההתחלה (x, y) היא קו הבסיס. כל גליף — מזהה בגופן, היסט (dx ימינה, dy למעלה — כמו HarfBuzz) והתקדמות,
 * כולם בנקודות בגודל הנתון; הגליפים בסדר חזותי (משמאל לימין). text — הטקסט הלוגי (ActualText: חיפוש והעתקה).
 * font / src — מפתח שה-$resolve מתרגם ל-bytes (למשל נתיב ב-media/ של ה-‎.mbe). כל בעיה — InvalidArgumentException.
 */

require_once __DIR__ . '/writer.php';

/** @param callable(string):?string $resolve */
function pdf_draw(array $list, callable $resolve): string
{
    $w = new PdfWriter();
    $w->subset = true;
    $fonts = [];
    $bytes = function (string $key, string $what) use ($resolve): string {
        $b = $resolve($key);
        if (!is_string($b) || $b === '') throw new InvalidArgumentException("$what חסר: $key");
        return $b;
    };
    $num = function ($v, string $where): float {
        if (!is_int($v) && !is_float($v)) throw new InvalidArgumentException("מספר חסר: $where");
        return (float)$v;
    };
    $box = function ($v, string $where) use ($num): array {
        if (!is_array($v) || count($v) !== 4) throw new InvalidArgumentException("מלבן [x, y, w, h] חסר: $where");
        return array_map(fn($x) => $num($x, $where), array_values($v));
    };
    $color = function ($v, string $where): string {
        if (!is_string($v) || !preg_match('/^#[0-9a-f]{6}$/i', $v)) throw new InvalidArgumentException("צבע #rrggbb: $where");
        return implode(' ', array_map(fn($h) => PdfWriter::n(hexdec($h) / 255), str_split(substr($v, 1), 2)));
    };
    $n = [PdfWriter::class, 'n'];
    $pages = $list['pages'] ?? null;
    if (!is_array($pages) || !$pages) throw new InvalidArgumentException('אין עמודים');
    foreach (array_values($pages) as $pi => $page) {
        $at = 'עמוד ' . ($pi + 1);
        $size = $page['size'] ?? null;
        if (!is_array($size) || count($size) !== 2) throw new InvalidArgumentException("size [w, h]: $at");
        [$pw, $ph] = [$num($size[0], $at), $num($size[1], $at)];
        $b = isset($page['bleed']) ? max(0, $num($page['bleed'], $at)) : 0;
        if ($pw <= 0 || $ph <= 0) throw new InvalidArgumentException("size [w, h]: $at");
        $idx = $w->addPage($pw, $ph, $b);
        $H = $ph + 2 * $b;
        // מהקואורדינטות של הרשימה (y למטה, מ-TrimBox) לאלה של PDF (y למעלה, מ-MediaBox)
        $X = fn(float $x) => $x + $b;
        $Y = fn(float $y) => $H - ($y + $b);
        $c = '';
        foreach (array_values($page['ops'] ?? []) as $oi => $op) {
            $where = "$at, פעולה " . ($oi + 1);
            switch ($op['op'] ?? '') {
                case 'glyphs':
                    $key = (string)($op['font'] ?? '');
                    $f = $fonts[$key] ??= TtfFont::fromBytes($bytes($key, 'גופן'), pathinfo($key, PATHINFO_FILENAME));
                    $fname = $w->font($f);
                    $size = $num($op['size'] ?? null, $where);
                    $x = $num($op['x'] ?? null, $where);
                    $y = $num($op['y'] ?? null, $where);
                    $gl = $op['glyphs'] ?? null;
                    if (!is_array($gl)) throw new InvalidArgumentException("glyphs: $where");
                    $text = (string)($op['text'] ?? '');
                    $c .= ($text !== '' ? '/Span << /ActualText ' . PdfWriter::text($text) . " >> BDC\n" : '')
                        . 'BT ' . $color($op['color'] ?? '#000000', $where) . " rg $fname " . $n($size) . " Tf\n";
                    $pen = 0.0;
                    foreach ($gl as $g) {
                        if (!is_array($g) || count($g) !== 4 || !is_int($g[0]) || $g[0] < 0 || $g[0] >= $f->numGlyphs())
                            throw new InvalidArgumentException("גליף [gid, dx, dy, adv]: $where");
                        [$gid, $dx, $dy, $adv] = [$g[0], $num($g[1], $where), $num($g[2], $where), $num($g[3], $where)];
                        $c .= '1 0 0 1 ' . $n($X($x + $pen + $dx)) . ' ' . $n($Y($y) + $dy) . ' Tm <' . $f->encodeGid($gid) . "> Tj\n";
                        $pen += $adv;
                    }
                    $c .= "ET\n" . ($text !== '' ? "EMC\n" : '');
                    break;
                case 'image':
                    $im = $w->image($bytes((string)($op['src'] ?? ''), 'תמונה'));
                    if (!$im) throw new InvalidArgumentException("תמונה בפורמט שלא נתמך: $where");
                    [$x, $y, $bw, $bh] = $box($op['box'] ?? null, $where);
                    $c .= "q\n";
                    if (isset($op['clip'])) {
                        [$cx, $cy, $cw, $ch] = $box($op['clip'], $where);
                        $c .= $n($X($cx)) . ' ' . $n($Y($cy + $ch)) . ' ' . $n($cw) . ' ' . $n($ch) . " re W n\n";
                    }
                    $c .= $n($bw) . ' 0 0 ' . $n($bh) . ' ' . $n($X($x)) . ' ' . $n($Y($y + $bh)) . " cm {$im['name']} Do\nQ\n";
                    break;
                case 'rect':
                    [$x, $y, $bw, $bh] = $box($op['box'] ?? null, $where);
                    $fill = isset($op['fill']) ? $color($op['fill'], $where) : null;
                    $stroke = isset($op['stroke']) ? $color($op['stroke'], $where) : null;
                    if ($fill === null && $stroke === null) throw new InvalidArgumentException("rect בלי fill ובלי stroke: $where");
                    $c .= "q\n" . ($fill !== null ? "$fill rg\n" : '') . ($stroke !== null ? "$stroke RG " . $n($num($op['width'] ?? 1, $where)) . " w\n" : '')
                        . $n($X($x)) . ' ' . $n($Y($y + $bh)) . ' ' . $n($bw) . ' ' . $n($bh) . ' re ' . ($fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S')) . "\nQ\n";
                    break;
                case 'line':
                    $from = $op['from'] ?? null;
                    $to = $op['to'] ?? null;
                    if (!is_array($from) || count($from) !== 2 || !is_array($to) || count($to) !== 2) throw new InvalidArgumentException("from / to [x, y]: $where");
                    $c .= "q\n" . $color($op['color'] ?? '#000000', $where) . ' RG ' . $n($num($op['width'] ?? 1, $where)) . " w\n"
                        . $n($X($num($from[0], $where))) . ' ' . $n($Y($num($from[1], $where))) . ' m ' . $n($X($num($to[0], $where))) . ' ' . $n($Y($num($to[1], $where))) . " l S\nQ\n";
                    break;
                default:
                    throw new InvalidArgumentException('פעולה לא מוכרת "' . (is_string($op['op'] ?? null) ? $op['op'] : '') . "\": $where");
            }
        }
        $w->pages[$idx]['main'] = $c;
    }
    return $w->output((string)($list['title'] ?? ''));
}
