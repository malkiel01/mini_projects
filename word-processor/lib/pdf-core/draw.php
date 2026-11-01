<?php
/**
 * רשימת ציור → PDF: הציור בלבד, בלי שבירת שורות — למי שמעמד בעצמו (העימוד, creative-suite/docs/requests/
 * layout-studio-pdf.md). אותו כותב של עורך התמלילים (writer.php, font.php), והגופנים — subset.
 * (pdf-core — עורכים ב-creative-suite/pdf-core/src ומריצים tools/sync.php)
 *
 * יחידות: נקודות (pt). y — מלמעלה למטה, מהפינה העליונה של העמוד (TrimBox). צבע: "#rrggbb".
 *
 *   { "title": "…", "pages": [ { "size": [w, h], "bleed": 9, "ops": [
 *       { "op": "glyphs", "font": "media/….ttf", "size": 12, "color": "#000000", "x": 0, "y": 0,
 *         "glyphs": [[gid, dx, dy, adv], …], "text": "…" },
 *       { "op": "image", "src": "media/….png", "box": [x, y, w, h], "clip": [x, y, w, h] },
 *       { "op": "rect", "box": [x, y, w, h], "fill": "#…", "stroke": "#…", "width": 1 },
 *       { "op": "line", "from": [x, y], "to": [x, y], "color": "#…", "width": 1 },
 *       { "op": "path", "d": "M10 10 L90 10 C95 10 100 15 100 20 Q 50 50 10 10 Z", "transform": [a, b, c, d, e, f],
 *         "fill": "#…" | null, "fillOpacity": 1, "fillRule": "nonzero" | "evenodd",
 *         "stroke": "#…" | null, "strokeOpacity": 1, "width": 1, "cap": "butt" | "round" | "square",
 *         "join": "miter" | "round" | "bevel", "miter": 4, "dash": [on, off, …] } ] } ] }
 *
 * path: d — פקודות מוחלטות בלבד, M L C Q Z (כמו בפורמט; Q הופך ל-C), זוגות נוספים אחרי פקודה — חזרה עליה (אחרי M — L).
 * transform — מטריצה של SVG על הנקודות, ביחידות הרשימה (y למטה); עובי הקו והמקפים עוברים בה כמו ב-SVG. לפחות fill או stroke.
 * image: גם "opacity" (0–1).
 *
 * שלב 2:
 *       { "op": "clip", "d": "M…", "fillRule": "nonzero" | "evenodd", "transform": [a, b, c, d, e, f] },
 *       … הפעולות שאחריו מוגבלות לנתיב, עד …
 *       { "op": "restore" }        — מקונן; clip שלא נסגר — נסגר בסוף העמוד
 * fill / stroke של path — גם מעבר צבע, בקואורדינטות של הנתיב (אחרי transform, כמו userSpaceOnUse ב-SVG):
 *       { "gradient": { "type": "linear", "x1": 0, "y1": 0, "x2": 100, "y2": 0,
 *                       "stops": [ { "offset": 0, "color": "#…" }, { "offset": 1, "color": "#…" } ] } }
 *       { "gradient": { "type": "radial", "cx": 50, "cy": 50, "r": 50, "fx": 50, "fy": 50, "stops": [ … ] } }
 * שקיפות בתוך העצירות — עוד לא (שגיאה); שקיפות לכל המעבר — fillOpacity / strokeOpacity.
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
    $unit = function ($v, string $where) use ($num): float {
        $x = $num($v, $where);
        if ($x < 0 || $x > 1) throw new InvalidArgumentException("ערך בין 0 ל-1: $where");
        return $x;
    };
    // מטריצת SVG על הרשימה (y למטה) → cm ב-PDF: p ↦ (x + bleed, H − bleed − y) אחרי transform
    $matrix = function ($t, string $where, float $b, float $H) use ($num): array {
        $t ??= [1, 0, 0, 1, 0, 0];
        if (!is_array($t) || count($t) !== 6) throw new InvalidArgumentException("transform [a, b, c, d, e, f]: $where");
        [$ta, $tb, $tc, $td, $te, $tf] = array_map(fn($v) => $num($v, $where), array_values($t));
        return [$ta, -$tb, $tc, -$td, $te + $b, $H - $b - $tf];
    };
    $inverse = function (array $m, string $where): array {
        [$a, $b, $c, $d, $e, $f] = $m;
        $det = $a * $d - $b * $c;
        if (abs($det) < 1e-12) throw new InvalidArgumentException("transform לא הפיך: $where");
        return [$d / $det, -$b / $det, -$c / $det, $a / $det, ($c * $f - $d * $e) / $det, ($b * $e - $a * $f) / $det];
    };
    // מעבר צבע → מילון Shading (2 — לינארי, 3 — רדיאלי); כמה עצירות — פונקציה מחוברת (type 3) של מקטעים לינאריים
    $shading = function ($g, string $where) use ($num, $color, $n): string {
        if (!is_array($g)) throw new InvalidArgumentException("gradient: $where");
        $type = $g['type'] ?? '';
        $stops = $g['stops'] ?? null;
        if (!is_array($stops) || !$stops) throw new InvalidArgumentException("gradient: stops חסרים: $where");
        $st = [];
        foreach (array_values($stops) as $k) {
            if (!is_array($k)) throw new InvalidArgumentException("gradient: עצירה { offset, color }: $where");
            if (isset($k['opacity']) && $num($k['opacity'], $where) < 1) throw new InvalidArgumentException("gradient: שקיפות בעצירות — עוד לא נתמכת (fillOpacity / strokeOpacity לכל המעבר): $where");
            $st[] = [max(0, min(1, $num($k['offset'] ?? null, $where))), $color($k['color'] ?? null, $where)];
        }
        usort($st, fn($x, $y) => $x[0] <=> $y[0]);
        if ($st[0][0] > 0) array_unshift($st, [0.0, $st[0][1]]);
        if (end($st)[0] < 1) $st[] = [1.0, end($st)[1]];
        $seg = fn($c0, $c1) => "<< /FunctionType 2 /Domain [0 1] /C0 [$c0] /C1 [$c1] /N 1 >>";
        if (count($st) === 2) $fn = $seg($st[0][1], $st[1][1]);
        else {
            $parts = []; $bounds = []; $enc = [];
            for ($i = 0; $i < count($st) - 1; $i++) { $parts[] = $seg($st[$i][1], $st[$i + 1][1]); $enc[] = '0 1'; if ($i) $bounds[] = $n($st[$i][0]); }
            $fn = '<< /FunctionType 3 /Domain [0 1] /Functions [' . implode(' ', $parts) . '] /Bounds [' . implode(' ', $bounds) . '] /Encode [' . implode(' ', $enc) . '] >>';
        }
        if ($type === 'linear') {
            $coords = [$g['x1'] ?? null, $g['y1'] ?? null, $g['x2'] ?? null, $g['y2'] ?? null];
            $stype = 2;
        } elseif ($type === 'radial') {
            $cx = $g['cx'] ?? null; $cy = $g['cy'] ?? null;
            $r = $num($g['r'] ?? null, $where);
            if ($r <= 0) throw new InvalidArgumentException("gradient: r חיובי: $where");
            $coords = [$g['fx'] ?? $cx, $g['fy'] ?? $cy, 0, $cx, $cy, $r];
            $stype = 3;
        } else throw new InvalidArgumentException("gradient: type — linear / radial: $where");
        return "<< /ShadingType $stype /ColorSpace /DeviceRGB /Coords [" . implode(' ', array_map(fn($v) => $n($num($v, $where)), $coords)) . "] /Function $fn /Extend [true true] >>";
    };
    // צבע או מעבר צבע → האופרטורים שקובעים אותו (rg / RG, או תבנית: /Pattern cs … scn)
    $paint = function ($v, bool $stroke, string $where, array $m) use ($color, $shading, $w): string {
        if (is_array($v) && isset($v['gradient'])) return '/Pattern ' . ($stroke ? 'CS ' : 'cs ') . $w->pattern($shading($v['gradient'], $where), $m) . ($stroke ? ' SCN' : ' scn');
        return $color($v, $where) . ($stroke ? ' RG' : ' rg');
    };
    // d של נתיב → אופרטורים של PDF (בקואורדינטות הרשימה; ה-cm של הפעולה מתרגם)
    $pathOps = function ($d, string $where) use ($n): string {
        if (!is_string($d) || trim($d) === '') throw new InvalidArgumentException("d חסר: $where");
        if (!preg_match_all('/[A-Za-z]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $d, $m) || preg_replace('/[A-Za-z]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?|[\s,]+/', '', $d) !== '')
            throw new InvalidArgumentException("d לא תקין: $where");
        $tok = $m[0];
        $need = ['M' => 2, 'L' => 2, 'C' => 6, 'Q' => 4, 'Z' => 0];
        $out = '';
        $cmd = null; $cx = $cy = $sx = $sy = 0.0; $open = false;
        for ($i = 0, $k = count($tok); $i < $k;) {
            if (ctype_alpha($tok[$i])) {
                $cmd = $tok[$i++];
                if (!isset($need[$cmd])) throw new InvalidArgumentException("פקודה \"$cmd\" — רק M L C Q Z מוחלטות: $where");
                if ($cmd === 'Z') { if ($open) $out .= "h\n"; $cx = $sx; $cy = $sy; continue; }
            } elseif ($cmd === null || $cmd === 'Z') throw new InvalidArgumentException("d חייב להתחיל בפקודה: $where");
            $a = [];
            for ($j = 0; $j < $need[$cmd]; $j++) {
                if ($i >= $k || ctype_alpha($tok[$i])) throw new InvalidArgumentException("חסרים מספרים אחרי $cmd: $where");
                $a[] = (float)$tok[$i++];
            }
            if ($cmd !== 'M' && !$open) throw new InvalidArgumentException("נתיב בלי M בהתחלה: $where");
            switch ($cmd) {
                case 'M': $out .= $n($a[0]) . ' ' . $n($a[1]) . " m\n"; [$cx, $cy, $sx, $sy] = [$a[0], $a[1], $a[0], $a[1]]; $open = true; $cmd = 'L'; break;
                case 'L': $out .= $n($a[0]) . ' ' . $n($a[1]) . " l\n"; [$cx, $cy] = $a; break;
                case 'C': $out .= implode(' ', array_map($n, $a)) . " c\n"; [$cx, $cy] = [$a[4], $a[5]]; break;
                case 'Q':   // ריבועית → קובייתית: נקודות הבקרה ב-2/3 מהקצוות אל נקודת הבקרה
                    [$qx, $qy, $x, $y] = $a;
                    $out .= implode(' ', array_map($n, [$cx + 2 / 3 * ($qx - $cx), $cy + 2 / 3 * ($qy - $cy), $x + 2 / 3 * ($qx - $x), $y + 2 / 3 * ($qy - $y), $x, $y])) . " c\n";
                    [$cx, $cy] = [$x, $y];
                    break;
            }
        }
        if (!$open) throw new InvalidArgumentException("d בלי נקודות: $where");
        return $out;
    };
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
        $depth = 0;   // clip פתוחים
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
                    if (isset($op['opacity']) && ($o = $unit($op['opacity'], $where)) < 1) $c .= $w->alpha($o, $o) . " gs\n";
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
                case 'path':
                    $hasFill = isset($op['fill']);
                    $hasStroke = isset($op['stroke']);
                    if (!$hasFill && !$hasStroke) throw new InvalidArgumentException("path בלי fill ובלי stroke: $where");
                    $m = $matrix($op['transform'] ?? null, $where, $b, $H);
                    $c .= "q\n" . implode(' ', array_map($n, $m)) . " cm\n";
                    $fo = isset($op['fillOpacity']) ? $unit($op['fillOpacity'], $where) : 1.0;
                    $so = isset($op['strokeOpacity']) ? $unit($op['strokeOpacity'], $where) : 1.0;
                    if (($hasFill && $fo < 1) || ($hasStroke && $so < 1)) $c .= $w->alpha($hasFill ? $fo : 1, $hasStroke ? $so : 1) . " gs\n";
                    if ($hasFill) $c .= $paint($op['fill'], false, $where, $m) . "\n";
                    if ($hasStroke) {
                        $caps = ['butt' => 0, 'round' => 1, 'square' => 2];
                        $joins = ['miter' => 0, 'round' => 1, 'bevel' => 2];
                        $cap = $op['cap'] ?? 'butt';
                        $join = $op['join'] ?? 'miter';
                        if (!isset($caps[$cap]) || !isset($joins[$join])) throw new InvalidArgumentException("cap / join: $where");
                        $c .= $paint($op['stroke'], true, $where, $m) . ' ' . $n($num($op['width'] ?? 1, $where)) . " w {$caps[$cap]} J {$joins[$join]} j " . $n(max(1, $num($op['miter'] ?? 4, $where))) . " M\n";
                        $dash = $op['dash'] ?? [];
                        if (!is_array($dash)) throw new InvalidArgumentException("dash [on, off, …]: $where");
                        if ($dash) $c .= '[' . implode(' ', array_map(fn($v) => $n(max(0, $num($v, $where))), array_values($dash))) . "] 0 d\n";
                    }
                    $rule = $op['fillRule'] ?? 'nonzero';
                    if ($rule !== 'nonzero' && $rule !== 'evenodd') throw new InvalidArgumentException("fillRule: nonzero / evenodd: $where");
                    $eo = $rule === 'evenodd' ? '*' : '';
                    $c .= $pathOps($op['d'] ?? null, $where) . ($hasFill && $hasStroke ? "B$eo" : ($hasFill ? "f$eo" : 'S')) . "\nQ\n";
                    break;
                case 'clip':
                    // q — נשאר פתוח עד restore. ה-cm של הנתיב מבוטל אחרי החיתוך (בהופכית), כך שהפעולות שאחריו בקואורדינטות הרגילות
                    $m = $matrix($op['transform'] ?? null, $where, $b, $H);
                    $rule = $op['fillRule'] ?? 'nonzero';
                    if ($rule !== 'nonzero' && $rule !== 'evenodd') throw new InvalidArgumentException("fillRule: nonzero / evenodd: $where");
                    $c .= "q\n" . implode(' ', array_map($n, $m)) . " cm\n" . $pathOps($op['d'] ?? null, $where) . ($rule === 'evenodd' ? 'W*' : 'W') . " n\n"
                        . implode(' ', array_map(fn($v) => ($t = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.')) === '-0' ? '0' : $t, $inverse($m, $where))) . " cm\n";
                    $depth++;
                    break;
                case 'restore':
                    if ($depth === 0) throw new InvalidArgumentException("restore בלי clip פתוח: $where");
                    $c .= "Q\n";
                    $depth--;
                    break;
                default:
                    throw new InvalidArgumentException('פעולה לא מוכרת "' . (is_string($op['op'] ?? null) ? $op['op'] : '') . "\": $where");
            }
        }
        $w->pages[$idx]['main'] = $c . str_repeat("Q\n", $depth);
    }
    return $w->output((string)($list['title'] ?? ''));
}
