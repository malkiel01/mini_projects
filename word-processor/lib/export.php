<?php
/**
 * הפלטים — כולם נגזרים בשרת מה-JSON של המנוע (embed.md, פרק 0): HTML נקי, טקסט, גוף OOXML לשילוב
 * ב-docx של המארחת, ו-docx שלם (עמוד, שוליים, כותרות, מספור עמודים, רשימות, טבלאות, תמונות).
 *
 * כללי Word שנבחרו כאן:
 * - פסקה מימין לשמאל = <w:bidi/>, ויישור/הזחה לוגיים (start/end) — לא left/right, שמשמעותם מתהפכת בפסקה כזו.
 * - <w:rtl/> רק על ריצה בעברית; מספרים ואנגלית בריצה בלי rtl (אחרת Word הופך תאריכים וטווחים —
 *   ראו org-budget-proposals/lib/export.php, שם נלמד הכלל).
 */

require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/footnote.php';

const EMU_PER_PX = 9525;
const TWIP_PER_MM = 56.6929;

/** משפחת גופנים ל-CSS: השם כמו ב-Word, ואחריו גופן רשת חופשי שמותקן אצלנו (vendor/fonts). */
function css_font(string $f): string
{
    $fallback = [
        'David' => "'David Libre', serif", 'Frank Ruehl' => "'Frank Ruhl Libre', serif", 'Narkisim' => "'Assistant', sans-serif",
        'Miriam' => "'Miriam Libre', sans-serif", 'Arial' => "'Heebo', sans-serif", 'Times New Roman' => "'Frank Ruhl Libre', serif",
        'Calibri' => "'Assistant', sans-serif", 'Tahoma' => "'Heebo', sans-serif",
    ][$f] ?? 'sans-serif';
    return "'" . str_replace("'", '', $f) . "', " . $fallback;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xml(string $s): string
{
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function pt_val($v): ?float
{
    if (is_numeric($v)) return (float)$v;
    if (is_string($v) && preg_match('/^(-?[\d.]+)\s*(pt|px)?$/', $v, $m)) return ($m[2] ?? '') === 'px' ? (float)$m[1] * 0.75 : (float)$m[1];
    return null;
}

function color_val($v): ?string
{
    if (!is_string($v)) return null;
    if (preg_match('/^#([0-9a-f]{6})$/i', $v, $m)) return strtoupper($m[1]);
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $v, $m)) return strtoupper($m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
    if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $v, $m)) return sprintf('%02X%02X%02X', min(255, $m[1]), min(255, $m[2]), min(255, $m[3]));
    return null;
}

/** ריווח שורות: '1.5' → ['auto', 1.5]; 'exact:14' → ['exact', 14 (pt)]. */
function line_val($v): ?array
{
    if (is_string($v) && preg_match('/^exact:([\d.]+)$/', $v, $m)) return ['exact', (float)$m[1]];
    if (is_numeric($v) && (float)$v > 0) return ['auto', (float)$v];
    return null;
}

const IMAGE_WRAPS = ['inline', 'square', 'tight', 'topBottom', 'behind', 'front'];

/**
 * גאומטריית תמונה (כמו imageBox ב-assets/js/extensions.js): w/h — התמונה המלאה, cw/ch — אחרי חיתוך,
 * bw/bh — אחרי סיבוב, בפיקסלים. $maxW — רוחב מרבי לחלק הנראה (מקטין את הכול באותו יחס).
 */
function image_geom(array $a, ?array $m, ?float $maxW = null): array
{
    $w = (float)(($a['width'] ?? 0) ?: ($m['width'] ?? 0) ?: 200);
    $h = !empty($a['height']) ? (float)$a['height'] : (!empty($m['width']) ? $w * $m['height'] / $m['width'] : $w * 0.75);
    $c = is_array($a['crop'] ?? null) ? $a['crop'] : [];
    $crop = [];
    foreach (['t', 'r', 'b', 'l'] as $k) $crop[$k] = max(0, min(45, (float)($c[$k] ?? 0)));
    $rot = ((int)($a['rotate'] ?? 0) % 360 + 360) % 360;
    $rot = in_array($rot, [0, 90, 180, 270], true) ? $rot : 0;
    $cw = $w * (1 - ($crop['l'] + $crop['r']) / 100);
    $ch = $h * (1 - ($crop['t'] + $crop['b']) / 100);
    if ($maxW && $cw > $maxW) { $k = $maxW / $cw; $w *= $k; $h *= $k; $cw *= $k; $ch *= $k; }
    $turned = $rot % 180 !== 0;
    $wrap = in_array($a['wrap'] ?? 'inline', IMAGE_WRAPS, true) ? ($a['wrap'] ?? 'inline') : 'inline';
    $dist = isset($a['dist']) && is_numeric($a['dist']) ? max(0, min(72, (float)$a['dist'])) : ($wrap === 'tight' ? 4.0 : (in_array($wrap, ['square', 'topBottom'], true) ? 9.0 : 0.0));
    return ['w' => $w, 'h' => $h, 'cw' => $cw, 'ch' => $ch, 'bw' => $turned ? $ch : $cw, 'bh' => $turned ? $cw : $ch,
        'crop' => $crop, 'rot' => $rot, 'wrap' => $wrap, 'dist' => $dist,
        'align' => in_array($a['align'] ?? '', ['right', 'center', 'left'], true) ? $a['align'] : 'right'];
}

// ======================================================================
// HTML
// ======================================================================

/** {html, css}. $absolute — כתובות תמונות מלאות (למארחת). */
function export_html(array $json, array $page, bool $absolute = true): array
{
    $json = with_media_src($json, $absolute);
    $body = '';
    foreach ($json['content'] ?? [] as $n) $body .= html_node($n);
    return ['html' => '<div class="wp-doc" dir="rtl" lang="he">' . $body . '</div>', 'css' => export_css($page)];
}

function export_css(array $page): string
{
    $f = $page['font'];
    $css = '.wp-doc{font-family:' . css_font($f['family']) . ';font-size:' . $f['size'] . 'pt;line-height:1.15;direction:rtl;color:#000}'
        . '.wp-doc p,.wp-doc h1,.wp-doc h2,.wp-doc h3,.wp-doc h4,.wp-doc h5,.wp-doc h6{margin:0}'
        . '.wp-doc table{border-collapse:collapse;width:100%;margin:4pt 0}'
        . '.wp-doc td,.wp-doc th{border:1px solid var(--wpt-bc,#888);padding:2pt 4pt;vertical-align:top}'
        // גבולות הטבלה (data-borders) — כמו editor.css: גבול כבוי שקוף, באותו רוחב
        . '.wp-doc table[data-borders]>tbody>tr>*{border-color:transparent}.wp-doc table[data-borders=inner]>tbody>tr>*{border-color:var(--wpt-bc,#888)}'
        . '.wp-doc table[data-borders=outer]{--wpt-edge:var(--wpt-bc,#888)}.wp-doc table[data-borders=inner]{--wpt-edge:transparent}'
        . '.wp-doc table:is([data-borders=outer],[data-borders=inner])>tbody>tr:first-child>*{border-top-color:var(--wpt-edge)}'
        . '.wp-doc table:is([data-borders=outer],[data-borders=inner])>tbody>tr:last-child>*{border-bottom-color:var(--wpt-edge)}'
        . '.wp-doc table:is([data-borders=outer],[data-borders=inner])>tbody>tr>:first-child{border-inline-start-color:var(--wpt-edge)}'
        . '.wp-doc table:is([data-borders=outer],[data-borders=inner])>tbody>tr>:last-child{border-inline-end-color:var(--wpt-edge)}'
        . '.wp-doc ul,.wp-doc ol{margin:0;padding-inline-start:24pt}'
        . '.wp-doc ol.hebrew{list-style-type:hebrew}.wp-doc ol.roman{list-style-type:lower-roman}'
        . '.wp-doc ol.multi,.wp-doc ol.multi ol{list-style:none}.wp-doc ol.multi>li,.wp-doc ol.multi ol>li{counter-increment:wpl;position:relative}'
        . '.wp-doc ol.multi>li::before,.wp-doc ol.multi ol>li::before{content:counters(wpl,".") ".";position:absolute;inset-inline-end:100%;margin-inline-end:6pt;white-space:nowrap}'
        . '.wp-doc p,.wp-doc h1,.wp-doc h2,.wp-doc h3,.wp-doc h4,.wp-doc h5,.wp-doc h6{position:relative}'
        . '.wp-doc .wp-img{display:inline-block;vertical-align:bottom;position:relative}'
        . '.wp-doc .wp-img-cap{display:block;text-align:center;font-size:9pt;font-style:italic;color:#333;padding-top:2pt}'
        . '.wp-doc .wrap-square,.wp-doc .wrap-tight{float:right;margin:0 0 var(--dist) var(--dist)}'
        . '.wp-doc .wrap-square.align-left,.wp-doc .wrap-tight.align-left{float:left;margin:0 var(--dist) var(--dist) 0}'
        . '.wp-doc .wrap-square.align-center,.wp-doc .wrap-tight.align-center,.wp-doc .wrap-topBottom{float:none;display:block;clear:both;margin:var(--dist) 0}'
        . '.wp-doc .wrap-square.align-center,.wp-doc .wrap-tight.align-center,.wp-doc .wrap-topBottom.align-center{margin-inline:auto}'
        . '.wp-doc .wrap-topBottom.align-left{margin-inline-start:auto}'
        . '.wp-doc .wrap-behind,.wp-doc .wrap-front{position:absolute}.wp-doc .wrap-behind{z-index:-1}.wp-doc .wrap-front{z-index:2}'
        . '.wp-doc .page-break{break-after:page;height:0}';
    // כותרות 7–9 — אין תגית HTML: פסקה עם מחלקה (html_node); סגנון משתמש — מחלקה wp-s-u<N>, מעל "רגיל"
    $sel = ['normal' => '.wp-doc p', 'title' => '.wp-doc .wp-title'];
    for ($l = 1; $l <= 9; $l++) $sel["h$l"] = $l <= 6 ? ".wp-doc h$l" : ".wp-doc .wp-h$l";
    foreach ($sel as $k => $s) $css .= $s . '{' . style_css($page['styles'][$k] ?? [], $k !== 'normal') . '}';
    foreach ($page['styles'] as $k => $st) {
        if (preg_match(USER_STYLE_RE, (string)$k)) $css .= ".wp-doc .wp-s-$k{" . style_css($st + ($page['styles']['normal'] ?? []), false) . '}';
    }
    $css .= '.wp-doc td>p,.wp-doc th>p{padding-bottom:0}';   // כמו בעורך
    if (!empty($page['heading_numbers'])) {
        // מספור כותרות — כמו headingNumbersCss בעורך
        $css .= '.wp-doc{counter-reset:wh1 wh2 wh3 wh4 wh5 wh6 wh7 wh8 wh9}';
        for ($l = 1; $l <= 9; $l++) {
            $sel = $l <= 6 ? ".wp-doc h$l" : ".wp-doc .wp-h$l";
            $below = implode(' ', array_map(fn($x) => "wh$x", range($l + 1, 9)));
            $path = implode(' "." ', array_map(fn($x) => "counter(wh$x)", range(1, $l)));
            $css .= "$sel{counter-increment:wh$l" . ($l < 9 ? ";counter-reset:$below" : '') . "}$sel::before{content:$path \"" . ($l === 1 ? '. ' : ' ') . '"}';
        }
    }
    return $css;
}

function style_css(array $st, bool $heading): string
{
    $o = '';
    if (!empty($st['font'])) $o .= 'font-family:' . css_font($st['font']) . ';';
    if (isset($st['size'])) $o .= 'font-size:' . $st['size'] . 'pt;';
    $o .= 'font-weight:' . (!empty($st['bold']) ? '700' : '400') . ';';
    if (!empty($st['italic'])) $o .= 'font-style:italic;';
    if (!empty($st['underline'])) $o .= 'text-decoration:underline;';
    if (!empty($st['color'])) $o .= 'color:' . $st['color'] . ';';
    if (!empty($st['align'])) $o .= 'text-align:' . $st['align'] . ';';
    if (isset($st['before'])) $o .= 'padding-top:' . $st['before'] . 'pt;';
    if (isset($st['after'])) $o .= 'padding-bottom:' . $st['after'] . 'pt;';
    if (isset($st['line'])) $o .= 'line-height:' . $st['line'] . ';';
    return $o;
}

function block_style(array $a): string
{
    $s = '';
    if (!empty($a['textAlign'])) $s .= 'text-align:' . $a['textAlign'] . ';';
    if (($l = line_val($a['lineHeight'] ?? null))) $s .= 'line-height:' . ($l[0] === 'exact' ? $l[1] . 'pt' : $l[1]) . ';';
    if (isset($a['spaceBefore']) && is_numeric($a['spaceBefore'])) $s .= 'padding-top:' . (float)$a['spaceBefore'] . 'pt;';
    if (isset($a['spaceAfter']) && is_numeric($a['spaceAfter'])) $s .= 'padding-bottom:' . (float)$a['spaceAfter'] . 'pt;';
    if (!empty($a['indent'])) $s .= 'margin-inline-start:' . ((int)$a['indent'] * 36) . 'pt;';
    $s .= first_line_css($a['firstLine'] ?? null);
    if (!empty($a['letterSpacing']) && is_numeric($a['letterSpacing'])) $s .= 'letter-spacing:' . (float)$a['letterSpacing'] . 'pt;';
    // שבירת עמודים בהדפסה של ה-HTML (וגם מה ש-Word קורא כשמדביקים ממנו)
    if (!empty($a['keepNext'])) $s .= 'break-after:avoid;page-break-after:avoid;';
    if (!empty($a['keepLines'])) $s .= 'break-inside:avoid;page-break-inside:avoid;';
    if (!empty($a['breakBefore'])) $s .= 'break-before:page;page-break-before:always;';
    return $s;
}

/** הזחת שורה ראשונה (חיובי) / תלויה (שלילי) — כמו ParagraphFormat בעורך. */
function first_line_css($v): string
{
    if (!is_numeric($v) || !(float)$v) return '';
    $f = max(-200, min(200, round((float)$v, 1)));
    return 'text-indent:' . $f . 'pt;' . ($f < 0 ? 'padding-inline-start:' . -$f . 'pt;' : '');
}

function block_attrs(array $a, string $class = ''): string
{
    $o = '';
    if ($class !== '') $o .= ' class="' . $class . '"';
    if (in_array($a['dir'] ?? null, ['rtl', 'ltr'], true)) $o .= ' dir="' . $a['dir'] . '"';
    if (($s = block_style($a)) !== '') $o .= ' style="' . h($s) . '"';
    return $o;
}

function html_node(array $n): string
{
    $a = $n['attrs'] ?? [];
    $kids = fn() => implode('', array_map('html_node', $n['content'] ?? []));
    switch ($n['type'] ?? '') {
        case 'paragraph':
            $inner = $kids();
            if (($a['pstyle'] ?? '') === 'title') return '<p' . block_attrs($a, 'wp-title') . '>' . ($inner ?: '<br>') . '</p>';
            if (preg_match(USER_STYLE_RE, (string)($a['pstyle'] ?? ''))) return '<p' . block_attrs($a, 'wp-s-' . $a['pstyle']) . '>' . ($inner ?: '<br>') . '</p>';
            return '<p' . block_attrs($a) . '>' . ($inner ?: '<br>') . '</p>';
        case 'heading':
            $l = max(1, min(9, (int)($a['level'] ?? 1)));
            if ($l > 6) return '<p role="heading" aria-level="' . $l . '"' . block_attrs($a, "wp-h$l") . '>' . $kids() . '</p>';
            return "<h$l" . block_attrs($a) . '>' . $kids() . "</h$l>";
        case 'bulletList':
            return '<ul>' . $kids() . '</ul>';
        case 'orderedList':
            $t = $a['listType'] ?? 'decimal';
            $start = (int)($a['start'] ?? 1);
            return '<ol' . ($t !== 'decimal' ? ' class="' . h($t) . '"' : '') . ($start !== 1 ? ' start="' . $start . '"' : '') . ' style="counter-reset:wpl ' . ($start - 1) . '">' . $kids() . '</ol>';
        case 'listItem':
            return '<li>' . $kids() . '</li>';
        case 'table':
            $at = in_array($a['borders'] ?? null, ['outer', 'inner', 'none'], true) ? ' data-borders="' . $a['borders'] . '"' : '';
            $st = ($bc = color_val($a['borderColor'] ?? null)) ? "--wpt-bc:#$bc;" : '';
            if (is_numeric($a['width'] ?? null) && $a['width'] >= 10 && $a['width'] < 100) {
                $al = $a['align'] ?? 'right';
                $st .= 'width:' . (int)$a['width'] . '%;' . ($al === 'center' ? 'margin-left:auto;margin-right:auto;' : ($al === 'left' ? 'margin-right:auto;' : 'margin-left:auto;'));
            }
            return '<table' . $at . ($st ? ' style="' . $st . '"' : '') . '><tbody>' . $kids() . '</tbody></table>';
        case 'tableRow':
            return '<tr' . (is_numeric($a['height'] ?? null) && $a['height'] > 0 ? ' style="height:' . min(400, (float)$a['height']) . 'pt"' : '') . '>' . $kids() . '</tr>';
        case 'tableCell':
        case 'tableHeader':
            $tag = $n['type'] === 'tableHeader' ? 'th' : 'td';
            $at = '';
            if (($c = (int)($a['colspan'] ?? 1)) > 1) $at .= ' colspan="' . $c . '"';
            if (($r = (int)($a['rowspan'] ?? 1)) > 1) $at .= ' rowspan="' . $r . '"';
            $st = ($bg = color_val($a['background'] ?? null)) ? "background:#$bg;" : '';
            if (in_array($a['valign'] ?? null, ['middle', 'bottom'], true)) $st .= 'vertical-align:' . $a['valign'] . ';';
            if ($st) $at .= ' style="' . $st . '"';
            return "<$tag$at>" . $kids() . "</$tag>";
        case 'image':
            return html_image($a);
        case 'pageBreak':
            return '<div class="page-break"></div>';
        case 'hardBreak':
            return '<br>';
        case 'bookmark':
            return ($b = bookmark_name($a['name'] ?? '')) ? '<a id="' . h($b) . '"></a>' : '';
        case 'horizontalRule':
            return '<hr>';
        case 'text':
            return html_marks(h((string)($n['text'] ?? '')), $n['marks'] ?? []);
        default:
            return $kids();
    }
}

/** תמונה ב-HTML: מסגרת בגודל שעל הדף, חלון חיתוך מסובב, והתמונה המלאה בתוכו; כיתוב מתחת. */
function html_image(array $a): string
{
    if (empty($a['src'])) return '';
    $m = !empty($a['media']) ? media_row((string)$a['media']) : null;
    $g = image_geom($a, $m);
    $px = fn($v) => round($v, 1) . 'px';
    $outer = 'width:' . $px($g['bw']) . ';--dist:' . $g['dist'] . 'pt;';
    if (in_array($g['wrap'], ['behind', 'front'], true)) $outer .= 'left:' . (int)($a['x'] ?? 0) . 'px;top:' . (int)($a['y'] ?? 0) . 'px;';
    $img = '<img src="' . h($a['src']) . '" alt="' . h((string)($a['alt'] ?? '')) . '" style="display:block;max-width:none;width:' . $px($g['w']) . ';height:' . $px($g['h'])
        . ';margin-left:' . $px(-$g['w'] * $g['crop']['l'] / 100) . ';margin-top:' . $px(-$g['h'] * $g['crop']['t'] / 100) . '">';
    $box = '<span style="position:absolute;left:50%;top:50%;width:' . $px($g['cw']) . ';height:' . $px($g['ch']) . ';overflow:hidden;transform:translate(-50%,-50%) rotate(' . $g['rot'] . 'deg)">' . $img . '</span>';
    $frame = '<span style="display:block;position:relative;width:' . $px($g['bw']) . ';height:' . $px($g['bh']) . '">' . $box . '</span>';
    $cap = !empty($a['caption']) ? '<span class="wp-img-cap">' . h((string)$a['caption']) . '</span>' : '';
    return '<span class="wp-img wrap-' . $g['wrap'] . ' align-' . $g['align'] . '" style="' . $outer . '">' . $frame . $cap . '</span>';
}

/** כתובת קישור בטוחה (כמו safeHref ב-assets/js/extensions.js): http(s), mailto, tel, "#סימניה"; אחרת null. */
function safe_href($v): ?string
{
    $s = trim((string)$v);
    if ($s === '' || preg_match('/[\x00-\x1f]/', $s)) return null;
    if ($s[0] === '#') return strlen($s) > 1 ? $s : null;
    if (preg_match('~^(https?://|mailto:|tel:)~i', $s)) return $s;
    if (preg_match('/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/', $s)) return 'mailto:' . $s;
    if (preg_match('~^(www\.)?[\w-]+(\.[\w-]+)*\.[a-z]{2,}([/?#:]\S*)?$~i', $s)) return 'https://' . $s;
    return null;
}

/** הקישור שעל ריצת טקסט (או null). */
function link_href(array $marks): ?string
{
    foreach ($marks as $m) if (($m['type'] ?? '') === 'link') return safe_href($m['attrs']['href'] ?? null);
    return null;
}

/** שם סימניה תקין (כמו BOOKMARK_RE ב-extensions.js) — או null. */
function bookmark_name($v): ?string
{
    $s = (string)$v;
    return preg_match('/^[\p{L}_][\p{L}\p{N}_]{0,39}$/u', $s) ? $s : null;
}

function html_marks(string $s, array $marks): string
{
    $style = '';
    foreach ($marks as $m) {
        $a = $m['attrs'] ?? [];
        switch ($m['type'] ?? '') {
            case 'bold': $s = "<strong>$s</strong>"; break;
            case 'italic': $s = "<em>$s</em>"; break;
            case 'underline':
                $ul = $a['ul'] ?? null;
                $s = in_array($ul, UNDERLINES, true) ? '<u style="' . ($ul === 'thick' ? 'text-decoration-thickness:0.12em' : 'text-decoration-style:' . $ul) . '">' . $s . '</u>' : "<u>$s</u>";
                break;
            case 'strike': $s = "<s>$s</s>"; break;
            case 'subscript': $s = "<sub>$s</sub>"; break;
            case 'superscript': $s = "<sup>$s</sup>"; break;
            case 'highlight': $s = '<mark style="background:#' . (color_val($a['color'] ?? null) ?? 'FFFF00') . '">' . $s . '</mark>'; break;
            case 'link':
                if (($href = safe_href($a['href'] ?? null))) $s = '<a href="' . h($href) . '">' . $s . '</a>';
                break;
            case 'textStyle':
                if (($c = color_val($a['color'] ?? null))) $style .= "color:#$c;";
                if (!empty($a['fontFamily'])) $style .= 'font-family:' . css_font(font_name((string)$a['fontFamily'])) . ';';
                if (($z = pt_val($a['fontSize'] ?? null))) $style .= "font-size:{$z}pt;";
                if (($b = color_val($a['backgroundColor'] ?? null))) $style .= "background:#$b;";
                if (($ls = pt_val($a['letterSpacing'] ?? null)) !== null && $ls != 0) $style .= "letter-spacing:{$ls}pt;";
                break;
        }
    }
    return $style !== '' ? '<span style="' . h($style) . '">' . $s . '</span>' : $s;
}

// ======================================================================
// טקסט
// ======================================================================

/** פסקאות בשורות, תבליט כ-"- ", ממוספר כ-"1. ", כותרת בלי סימון, תאי טבלה מופרדים בטאב. */
function export_text(array $json): string
{
    $lines = [];
    text_blocks($json['content'] ?? [], $lines, 0);
    return rtrim(implode("\n", $lines));
}

function text_blocks(array $nodes, array &$lines, int $depth): void
{
    foreach ($nodes as $n) {
        $t = $n['type'] ?? '';
        if ($t === 'bulletList' || $t === 'orderedList') {
            $i = (int)($n['attrs']['start'] ?? 1);
            foreach ($n['content'] ?? [] as $li) {
                $marker = $t === 'bulletList' ? '- ' : list_marker($i++, $n['attrs']['listType'] ?? 'decimal') . ' ';
                $first = true;
                foreach ($li['content'] ?? [] as $child) {
                    if (in_array($child['type'] ?? '', ['bulletList', 'orderedList'], true)) { text_blocks([$child], $lines, $depth + 1); continue; }
                    $lines[] = str_repeat('  ', $depth) . ($first ? $marker : '  ') . inline_text($child);
                    $first = false;
                }
            }
        } elseif ($t === 'table') {
            foreach ($n['content'] ?? [] as $row) {
                $cells = [];
                foreach ($row['content'] ?? [] as $cell) {
                    $parts = [];
                    foreach ($cell['content'] ?? [] as $p) $parts[] = inline_text($p);
                    $cells[] = implode(' ', array_filter($parts, 'strlen'));
                }
                $lines[] = implode("\t", $cells);
            }
        } elseif ($t === 'pageBreak' || $t === 'horizontalRule') {
            $lines[] = '';
        } else {
            $lines[] = inline_text($n);
        }
    }
}

function inline_text(array $n): string
{
    if (($n['type'] ?? '') === 'text') return (string)($n['text'] ?? '');
    if (($n['type'] ?? '') === 'hardBreak') return "\n";
    $s = '';
    foreach ($n['content'] ?? [] as $c) {
        if (in_array($c['type'] ?? '', ['paragraph', 'heading'], true) && $s !== '') $s .= "\n";
        $s .= inline_text($c);
    }
    return $s;
}

function list_marker(int $i, string $type): string
{
    return match ($type) {
        'hebrew' => hebrew_letters($i) . '.',
        'roman' => strtolower(roman($i)) . '.',
        default => $i . '.',
    };
}

function roman(int $n): string
{
    $out = '';
    foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $r => $v) {
        while ($n >= $v) { $out .= $r; $n -= $v; }
    }
    return $out;
}

// ======================================================================
// OOXML
// ======================================================================

/**
 * בונה WordprocessingML. $prefix — קידומת לסגנונות, למספורים ולקשרים, כדי שגוף שמשולב ב-docx של
 * המארחת לא יתנגש בשלה ('' ב-docx שלם; 'wp' בגוף לשילוב).
 */
final class Ooxml
{
    public array $media = [];        // [{name, type, bytes, rid}]
    public array $links = [];        // כתובת חיצונית => rid (קשר hyperlink ב-rels)
    private array $lastOrdered = []; // "רמה:סוג" => numId של הרשימה הממוספרת האחרונה (להמשך מספור)
    private ?int $headingNum = null; // numId של מספור הכותרות (מקושר לסגנונות Heading1–9)
    private int $bookmarkId = 0;     // w:id של סימניות; בגוף לשילוב — מ-900000, כדי לא להתנגש בסימניות של המארחת
    public array $nums = [];         // [numId => ['abstract' => id]]
    public array $abstracts = [];    // [abstractId => ['type' => ..]]
    private int $docPr = 1;
    private int $numBase;
    public float $contentTw;         // רוחב אזור הטקסט ב-twips (לטבלאות ולתמונות) — של המקטע הנוכחי
    public array $cur;               // הגדרות העמוד של המקטע הנוכחי (מתחלפות בכל מעבר מקטע)
    public $sectFn = null;           // fn(array $page, int $index): string — <w:sectPr> של מקטע שמסתיים (docx שלם בלבד)
    private int $sectIndex = 0;
    public array $footnotes = [];    // [id => צמתי text עם עיצוב] — word/footnotes.xml (docx שלם); בשילוב — בסוגריים

    public function __construct(public array $page, public string $prefix = '')
    {
        if ($prefix !== '') $this->bookmarkId = 900000;
        $this->numBase = $prefix === '' ? 1 : 9001;
        $this->setSection($page);
    }

    private function setSection(array $p): void
    {
        $this->cur = $p;
        [$w] = page_mm($p);
        $this->contentTw = ($w - $p['margins']['left'] - $p['margins']['right']) * TWIP_PER_MM;
    }

    public function styleId(string $k): string
    {
        // h1..h9 → Heading1..9; סגנון משתמש u<N> → UserN (השם שלו — ב-w:name)
        $base = $k === 'normal' ? 'Normal' : ($k === 'title' ? 'Title' : ($k[0] === 'h' ? 'Heading' . substr($k, 1) : 'User' . substr($k, 1)));
        return $this->prefix === '' ? $base : $this->prefix . $base;
    }

    public function body(array $json): string
    {
        $out = '';
        foreach ($json['content'] ?? [] as $n) $out .= $this->block($n, []);
        return $out;
    }

    /** $list — ['num' => numId, 'lvl' => רמה] כשהבלוק בתוך פריט רשימה. */
    private function block(array $n, array $list, bool $firstInItem = true): string
    {
        $a = $n['attrs'] ?? [];
        switch ($n['type'] ?? '') {
            case 'paragraph':
            case 'heading':
                $style = block_style_key($n, $this->page);
                return $this->para($n, $style === 'normal' ? null : $style, $list && $firstInItem ? $list : [], (bool)$list);
            case 'bulletList':
            case 'orderedList':
                $type = $n['type'] === 'bulletList' ? 'bullet' : (in_array($a['listType'] ?? '', ['hebrew', 'roman', 'multi'], true) ? $a['listType'] : 'decimal');
                $lvl = $list ? min(8, $list['lvl'] + 1) : 0;
                $multi = !empty($list['multi']);
                if ($multi && $type !== 'bullet') {
                    $num = $list['num'];   // רב-רמתי: רשימה ממוספרת שבתוכו — הרמה הבאה של אותה הגדרה (1.1, 1.1.1)
                } elseif (!empty($a['cont']) && $type !== 'bullet' && isset($this->lastOrdered["$lvl:$type"])) {
                    $num = $this->lastOrdered["$lvl:$type"];   // "המשך מספור" — אותה הגדרה, Word ממשיך את המספרים לבד
                } else {
                    $num = $this->newNum($type, (int)($a['start'] ?? 1), $lvl);
                }
                if ($type !== 'bullet') $this->lastOrdered["$lvl:$type"] = $num;
                $out = '';
                foreach ($n['content'] ?? [] as $li) {
                    $first = true;
                    foreach ($li['content'] ?? [] as $child) {
                        $out .= $this->block($child, ['num' => $num, 'lvl' => $lvl, 'multi' => $multi || ($type === 'multi' && $n['type'] === 'orderedList')], $first);
                        if (!in_array($child['type'] ?? '', ['bulletList', 'orderedList'], true)) $first = false;
                    }
                }
                return $out;
            case 'table':
                return $this->table($n);
            case 'pageBreak':
                return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            case 'sectionBreak':
                // ב-Word מקטע מסתיים בפסקה שנושאת את ההגדרות שלו; המקטע הבא — בהגדרות שבצומת (שינויים בלבד)
                $next = page_normalize(is_array($a['page'] ?? null) ? $a['page'] : [], $this->cur);
                if (!$this->sectFn) { $this->setSection($next); return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'; }
                $xml = '<w:p><w:pPr><w:bidi/>' . ($this->sectFn)($this->cur, $this->sectIndex++) . '</w:pPr></w:p>';
                $this->setSection($next);
                return $xml;
            case 'horizontalRule':
                return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr><w:bidi/></w:pPr></w:p>';
            default:
                return '';
        }
    }

    private function para(array $n, ?string $style, array $num, bool $inList): string
    {
        $a = $n['attrs'] ?? [];
        $rtl = ($a['dir'] ?? 'rtl') !== 'ltr';
        $ppr = '';
        if ($style) $ppr .= '<w:pStyle w:val="' . $this->styleId($style) . '"/>';
        // שבירת עמודים — בסדר שהסכמה של Word דורשת (אחרי pStyle, לפני numPr)
        if (!empty($a['keepNext'])) $ppr .= '<w:keepNext/>';
        if (!empty($a['keepLines'])) $ppr .= '<w:keepLines/>';
        if (!empty($a['breakBefore'])) $ppr .= '<w:pageBreakBefore/>';
        if ($num) $ppr .= '<w:numPr><w:ilvl w:val="' . $num['lvl'] . '"/><w:numId w:val="' . $num['num'] . '"/></w:numPr>';
        if ($rtl) $ppr .= '<w:bidi/>';
        $sp = '';
        if (isset($a['spaceBefore']) && is_numeric($a['spaceBefore'])) $sp .= ' w:before="' . (int)round($a['spaceBefore'] * 20) . '"';
        if (isset($a['spaceAfter']) && is_numeric($a['spaceAfter'])) $sp .= ' w:after="' . (int)round($a['spaceAfter'] * 20) . '"';
        if (($l = line_val($a['lineHeight'] ?? null))) {
            $sp .= $l[0] === 'exact' ? ' w:line="' . (int)round($l[1] * 20) . '" w:lineRule="exact"' : ' w:line="' . (int)round($l[1] * 240) . '" w:lineRule="auto"';
        }
        if ($sp !== '') $ppr .= "<w:spacing$sp/>";
        // הזחה: start (בצעדים של 720) + שורה ראשונה (firstLine) / תלויה (hanging — Word מזיז את כל השאר, start כולל אותה)
        $fl = is_numeric($a['firstLine'] ?? null) ? (int)round(max(-200, min(200, (float)$a['firstLine'])) * 20) : 0;
        $start = (int)($a['indent'] ?? 0) * 720 + ($inList ? 720 : 0) + ($fl < 0 ? -$fl : 0);
        if ($start || $fl) $ppr .= '<w:ind w:start="' . $start . '"' . ($fl > 0 ? ' w:firstLine="' . $fl . '"' : ($fl < 0 ? ' w:hanging="' . -$fl . '"' : '')) . '/>';
        $jc = $this->jc($a['textAlign'] ?? null, $rtl);
        if ($jc) $ppr .= '<w:jc w:val="' . $jc . '"/>';
        $runs = '';
        $anchors = '';
        $captions = '';
        $openLink = null;
        foreach ($n['content'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'image') {
                if (!empty($c['attrs']['caption'])) {
                    // כיתוב: פסקה ממורכזת אחרי הפסקה של התמונה
                    $captions .= '<w:p><w:pPr><w:bidi/><w:spacing w:before="40" w:after="120"/><w:jc w:val="center"/></w:pPr>'
                        . $this->runs((string)$c['attrs']['caption'], [['type' => 'italic'], ['type' => 'textStyle', 'attrs' => ['fontSize' => '9pt', 'color' => '#333333']]], []) . '</w:p>';
                }
                if (($c['attrs']['wrap'] ?? 'inline') !== 'inline') { $anchors .= $this->image($c); continue; }
            }
            // קישור: ריצות רצופות עם אותה כתובת — בתוך w:hyperlink אחד
            $href = ($c['type'] ?? '') === 'text' ? link_href($c['marks'] ?? []) : null;
            if ($href !== $openLink) {
                if ($openLink !== null) $runs .= '</w:hyperlink>';
                if ($href !== null) $runs .= $this->hyperlink($href);
                $openLink = $href;
            }
            $runs .= $this->inline($c, $a);
        }
        if ($openLink !== null) $runs .= '</w:hyperlink>';
        return '<w:p>' . ($ppr !== '' ? "<w:pPr>$ppr</w:pPr>" : '') . $anchors . $runs . '</w:p>' . $captions;
    }

    /** יישור ויזואלי → ערך לוגי (start = ימין בפסקה מימין לשמאל). */
    private function jc(?string $align, bool $rtl): ?string
    {
        return match ($align) {
            'center' => 'center',
            'justify' => 'both',
            'right' => $rtl ? null : 'end',
            'left' => $rtl ? 'end' : null,
            default => null,
        };
    }

    private function inline(array $c, array $pa): string
    {
        switch ($c['type'] ?? '') {
            case 'text':
                return $this->runs((string)($c['text'] ?? ''), $c['marks'] ?? [], $pa);
            case 'hardBreak':
                return '<w:r><w:br/></w:r>';
            case 'bookmark':
                if (!($b = bookmark_name($c['attrs']['name'] ?? ''))) return '';
                $id = ++$this->bookmarkId;
                return '<w:bookmarkStart w:id="' . $id . '" w:name="' . xml($b) . '"/><w:bookmarkEnd w:id="' . $id . '"/>';
            case 'image':
                return $this->image($c);
            case 'footnote':
                $inline = fn_inline($c['attrs'] ?? []);
                if ($this->prefix !== '') {
                    // גוף לשילוב: אין לנו חלק הערות במסמך של המארחת — הטקסט בסוגריים, בכתב עילי (עם העיצוב שלו)
                    $sup = [['type' => 'superscript']];
                    $out = $this->runs(' (', $sup, $pa);
                    foreach ($inline as $t) $out .= $this->runs($t['text'], array_merge($t['marks'] ?? [], $sup), $pa);
                    return $out . $this->runs(')', $sup, $pa);
                }
                $id = count($this->footnotes) + 1;
                $this->footnotes[$id] = $inline;
                return '<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteReference w:id="' . $id . '"/></w:r>';
            default:
                return '';
        }
    }

    /** פתיחת w:hyperlink: "#שם" — לסימניה במסמך (w:anchor); אחרת — קשר חיצוני (rels). */
    private function hyperlink(string $href): string
    {
        if ($href[0] === '#') return '<w:hyperlink w:anchor="' . xml(substr($href, 1)) . '" w:history="1">';
        $this->links[$href] ??= ($this->prefix ?: 'r') . 'Link' . (count($this->links) + 1);
        return '<w:hyperlink r:id="' . $this->links[$href] . '" w:history="1">';
    }

    /** word/footnotes.xml: שני המפרידים שחובה (-1, 0) והערות המסמך. */
    public function footnotesXml(): string
    {
        $sep = fn(int $id, string $type, string $tag) => '<w:footnote w:type="' . $type . '" w:id="' . $id . '"><w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:' . $tag . '/></w:r></w:p></w:footnote>';
        $out = $sep(-1, 'separator', 'separator') . $sep(0, 'continuationSeparator', 'continuationSeparator');
        foreach ($this->footnotes as $id => $inline) {
            $out .= '<w:footnote w:id="' . $id . '"><w:p><w:pPr><w:pStyle w:val="FootnoteText"/><w:bidi/></w:pPr>'
                . '<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r>' . $this->runs(' ', [], []);
            foreach ($inline as $t) $out .= $this->runs($t['text'], $t['marks'] ?? [], []);
            $out .= '</w:p></w:footnote>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:footnotes ' . W_NS . '>' . $out . '</w:footnotes>';
    }

    /** טקסט לריצות: עברית עם <w:rtl/>, ומספרים/אנגלית בלי. */
    private function runs(string $text, array $marks, array $pa): string
    {
        $rpr = $this->rpr($marks, $pa);
        $out = '';
        $parts = preg_split('/([A-Za-z0-9](?:[A-Za-z0-9.,:\/\\\\\-+%_@&#= ]*[A-Za-z0-9%])?)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            $ltr = (bool)preg_match('/^[A-Za-z0-9]/', $p);
            $r = $rpr . ($ltr ? '' : '<w:rtl/>');
            foreach (preg_split('/(\t)/', $p, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $q) {
                $inner = $q === "\t" ? '<w:tab/>' : '<w:t xml:space="preserve">' . xml($q) . '</w:t>';
                $out .= '<w:r>' . ($r !== '' ? "<w:rPr>$r</w:rPr>" : '') . $inner . '</w:r>';
            }
        }
        return $out;
    }

    /** תכונות ריצה בסדר שהסכמה דורשת (rFonts, b, i, strike, color, spacing, sz, u, shd, vertAlign; rtl נוסף אחרי). */
    private function rpr(array $marks, array $pa): string
    {
        $m = [];
        foreach ($marks as $mk) $m[$mk['type'] ?? ''] = $mk['attrs'] ?? [];
        // קישור: הסגנון Hyperlink (כחול, קו תחתון) — כמו ב-Word, ובלי עיצוב ישיר שהיה חוזר בייבוא כצבע וקו תחתון
        $o = isset($m['link']) && link_href([['type' => 'link', 'attrs' => $m['link']]]) ? '<w:rStyle w:val="' . ($this->prefix . 'Hyperlink') . '"/>' : '';
        $ts = $m['textStyle'] ?? [];
        if (!empty($ts['fontFamily'])) {
            $f = xml(font_name((string)$ts['fontFamily']));
            $o .= "<w:rFonts w:ascii=\"$f\" w:hAnsi=\"$f\" w:cs=\"$f\"/>";
        }
        if (isset($m['bold'])) $o .= '<w:b/><w:bCs/>';
        if (isset($m['italic'])) $o .= '<w:i/><w:iCs/>';
        if (isset($m['strike'])) $o .= '<w:strike/>';
        if (($c = color_val($ts['color'] ?? null))) $o .= "<w:color w:val=\"$c\"/>";
        $ls = pt_val($ts['letterSpacing'] ?? null) ?? (is_numeric($pa['letterSpacing'] ?? null) ? (float)$pa['letterSpacing'] : null);
        if ($ls) $o .= '<w:spacing w:val="' . (int)round($ls * 20) . '"/>';
        if (($z = pt_val($ts['fontSize'] ?? null))) { $hz = (int)round($z * 2); $o .= "<w:sz w:val=\"$hz\"/><w:szCs w:val=\"$hz\"/>"; }
        if (isset($m['underline'])) $o .= '<w:u w:val="' . (['double' => 'double', 'dotted' => 'dotted', 'dashed' => 'dash', 'wavy' => 'wave', 'thick' => 'thick'][$m['underline']['ul'] ?? ''] ?? 'single') . '"/>';
        $bg = color_val($m['highlight']['color'] ?? null) ?? (isset($m['highlight']) ? 'FFFF00' : color_val($ts['backgroundColor'] ?? null));
        if ($bg) $o .= "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"$bg\"/>";
        if (isset($m['superscript'])) $o .= '<w:vertAlign w:val="superscript"/>';
        elseif (isset($m['subscript'])) $o .= '<w:vertAlign w:val="subscript"/>';
        return $o;
    }

    private function image(array $c): string
    {
        $a = $c['attrs'] ?? [];
        $m = !empty($a['media']) ? media_row((string)$a['media']) : null;
        if (!$m || !is_file(media_path($m['id'], $m['type']))) return '';
        $rid = $this->addMedia($m);
        $g = image_geom($a, $m, $this->contentTw / 15);   // twips → px (1px = 15 twips)
        $cx = (int)round($g['cw'] * EMU_PER_PX);
        $cy = (int)round($g['ch'] * EMU_PER_PX);
        $id = $this->docPr++;
        $src = '';
        if (array_sum($g['crop']) > 0) {
            $src = '<a:srcRect l="' . (int)round($g['crop']['l'] * 1000) . '" t="' . (int)round($g['crop']['t'] * 1000)
                . '" r="' . (int)round($g['crop']['r'] * 1000) . '" b="' . (int)round($g['crop']['b'] * 1000) . '"/>';
        }
        $graphic = '<wp:docPr id="' . $id . '" name="Picture ' . $id . '" descr="' . xml((string)($a['alt'] ?? '')) . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="Picture ' . $id . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/>' . $src . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm' . ($g['rot'] ? ' rot="' . ($g['rot'] * 60000) . '"' : '') . '><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic>';
        // סיבוב ברבע: התמונה תופסת על הדף את הממדים המוחלפים — effectExtent משלים את ההפרש
        $ee = '<wp:effectExtent l="0" t="0" r="0" b="0"/>';
        if ($g['rot'] % 180) {
            $dx = (int)max(0, round(($cy - $cx) / 2));
            $dy = (int)max(0, round(($cx - $cy) / 2));
            $ee = '<wp:effectExtent l="' . $dx . '" t="' . $dy . '" r="' . $dx . '" b="' . $dy . '"/>';
        }
        if ($g['wrap'] === 'inline') {
            return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>' . $ee
                . $graphic . '</wp:inline></w:drawing></w:r>';
        }
        $d = (int)round($g['dist'] * 12700);   // נקודות → EMU
        $abs = in_array($g['wrap'], ['behind', 'front'], true);
        $posH = $abs ? '<wp:positionH relativeFrom="column"><wp:posOffset>' . (int)round((float)($a['x'] ?? 0) * EMU_PER_PX) . '</wp:posOffset></wp:positionH>'
            : '<wp:positionH relativeFrom="column"><wp:align>' . $g['align'] . '</wp:align></wp:positionH>';
        $posV = '<wp:positionV relativeFrom="paragraph"><wp:posOffset>' . ($abs ? (int)round((float)($a['y'] ?? 0) * EMU_PER_PX) : 0) . '</wp:posOffset></wp:positionV>';
        $wrapXml = match ($g['wrap']) {
            'square' => '<wp:wrapSquare wrapText="bothSides"/>',
            'tight' => '<wp:wrapTight wrapText="bothSides"><wp:wrapPolygon edited="0"><wp:start x="0" y="0"/><wp:lineTo x="0" y="21600"/>'
                . '<wp:lineTo x="21600" y="21600"/><wp:lineTo x="21600" y="0"/><wp:lineTo x="0" y="0"/></wp:wrapPolygon></wp:wrapTight>',
            'topBottom' => '<wp:wrapTopAndBottom/>',
            default => '<wp:wrapNone/>',
        };
        $dist = $g['wrap'] === 'topBottom' ? 'distT="' . $d . '" distB="' . $d . '" distL="0" distR="0"' : 'distT="0" distB="0" distL="' . $d . '" distR="' . $d . '"';
        return '<w:r><w:drawing><wp:anchor ' . $dist . ' simplePos="0" relativeHeight="' . (251658240 + (max(-50, min(50, (int)($a['z'] ?? 0))) + 50) * 4096 + $id) . '" behindDoc="' . ($g['wrap'] === 'behind' ? 1 : 0)
            . '" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/>' . $posH . $posV
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>' . $ee . $wrapXml . $graphic . '</wp:anchor></w:drawing></w:r>';
    }

    private function addMedia(array $m): string
    {
        if (isset($this->media[$m['id']])) return $this->media[$m['id']]['rid'];
        $n = count($this->media) + 1;
        $ext = MEDIA_TYPES[$m['type']];
        $this->media[$m['id']] = ['name' => ($this->prefix ?: 'image') . "_$n.$ext", 'type' => $m['type'],
            'bytes' => file_get_contents(media_path($m['id'], $m['type'])), 'rid' => ($this->prefix ?: 'r') . "Img$n"];
        return $this->media[$m['id']]['rid'];
    }

    private function newNum(string $type, int $start, int $lvl): int
    {
        $abs = $this->numBase + count($this->abstracts);
        $this->abstracts[$abs] = ['type' => $type, 'start' => max(0, $start), 'lvl' => $lvl];
        $num = $this->numBase + count($this->nums);
        $this->nums[$num] = $abs;
        return $num;
    }

    private function table(array $t): string
    {
        $rows = $t['content'] ?? [];
        // רשת: כמה עמודות, ואיפה תאים ממוזגים אנכית ממשיכים (vMerge)
        $occupied = [];   // [row][col] => ['cont' => true, 'span' => n]
        $ncols = 0;
        $layout = [];
        foreach ($rows as $ri => $row) {
            $col = 0;
            $cells = [];
            foreach ($row['content'] ?? [] as $cell) {
                while (isset($occupied[$ri][$col])) { $cells[] = ['cont' => $occupied[$ri][$col]]; $col += $occupied[$ri][$col]; }
                $cs = max(1, (int)($cell['attrs']['colspan'] ?? 1));
                $rs = max(1, (int)($cell['attrs']['rowspan'] ?? 1));
                for ($k = 1; $k < $rs; $k++) $occupied[$ri + $k][$col] = $cs;
                $cells[] = ['cell' => $cell, 'col' => $col, 'span' => $cs, 'restart' => $rs > 1];
                $col += $cs;
            }
            while (isset($occupied[$ri][$col])) { $cells[] = ['cont' => $occupied[$ri][$col]]; $col += $occupied[$ri][$col]; }
            $ncols = max($ncols, $col);
            $layout[] = $cells;
        }
        if ($ncols === 0) return '';
        // רוחב עמודות: colwidth מהעורך (px), אחרת שווה
        $widths = array_fill(0, $ncols, 0);
        foreach ($layout as $cells) foreach ($cells as $c) {
            if (isset($c['cell']) && $c['span'] === 1 && !empty($c['cell']['attrs']['colwidth'][0])) $widths[$c['col']] = (float)$c['cell']['attrs']['colwidth'][0] * 15;
        }
        $ta = $t['attrs'] ?? [];
        $pct = is_numeric($ta['width'] ?? null) && $ta['width'] >= 10 && $ta['width'] < 100 ? (int)$ta['width'] : 100;
        $target = $this->contentTw * $pct / 100;   // הרוחב שהטבלה תופסת בשורה
        $known = array_sum($widths);
        $unknown = count(array_filter($widths, fn($w) => $w == 0));
        $rest = $unknown ? max(600, ($target - $known) / $unknown) : 0;
        foreach ($widths as $i => $w) if ($w == 0) $widths[$i] = $rest;
        $scale = array_sum($widths) > $target ? $target / array_sum($widths) : 1;
        $widths = array_map(fn($w) => (int)round($w * $scale), $widths);

        // גבולות: כולם / מסגרת / פנימיים / בלי, בצבע של הטבלה
        $mode = in_array($ta['borders'] ?? null, ['outer', 'inner', 'none'], true) ? $ta['borders'] : 'all';
        $bc = strtoupper(color_val($ta['borderColor'] ?? null) ?? '808080');
        $b = '';
        foreach (['top', 'start', 'bottom', 'end', 'insideH', 'insideV'] as $side) {
            $inside = str_starts_with($side, 'inside');
            $on = $mode === 'all' || ($mode === 'outer' && !$inside) || ($mode === 'inner' && $inside);
            $b .= $on ? "<w:$side w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"$bc\"/>" : "<w:$side w:val=\"nil\"/>";
        }
        // יישור: בטבלה מימין לשמאל (bidiVisual) start — ימין
        $jc = $pct < 100 ? match ($ta['align'] ?? 'right') { 'center' => '<w:jc w:val="center"/>', 'left' => '<w:jc w:val="end"/>', default => '' } : '';
        $out = '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/>' . $jc . '<w:tblBorders>' . $b . '</w:tblBorders>'
            . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:start w:w="80" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:end w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $w) $out .= '<w:gridCol w:w="' . $w . '"/>';
        $out .= '</w:tblGrid>';
        foreach ($layout as $ri => $cells) {
            $isHeader = ($rows[$ri]['content'][0]['type'] ?? '') === 'tableHeader';
            $rh = is_numeric($rows[$ri]['attrs']['height'] ?? null) && $rows[$ri]['attrs']['height'] > 0 ? '<w:trHeight w:val="' . (int)round(min(400, $rows[$ri]['attrs']['height']) * 20) . '" w:hRule="atLeast"/>' : '';
            $out .= '<w:tr>' . ($isHeader || $rh ? '<w:trPr>' . $rh . ($isHeader ? '<w:tblHeader/>' : '') . '</w:trPr>' : '');
            $col = 0;
            foreach ($cells as $c) {
                if (isset($c['cont'])) {
                    $span = $c['cont'];
                    $w = array_sum(array_slice($widths, $col, $span));
                    $out .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '') . '<w:vMerge/></w:tcPr><w:p/></w:tc>';
                    $col += $span;
                    continue;
                }
                $cell = $c['cell'];
                $w = array_sum(array_slice($widths, $c['col'], $c['span']));
                $pr = '<w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($c['span'] > 1 ? '<w:gridSpan w:val="' . $c['span'] . '"/>' : '')
                    . ($c['restart'] ? '<w:vMerge w:val="restart"/>' : '');
                if (($bg = color_val($cell['attrs']['background'] ?? null))) $pr .= "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"$bg\"/>";
                elseif ($cell['type'] === 'tableHeader') $pr .= '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>';
                if (in_array($cell['attrs']['valign'] ?? null, ['middle', 'bottom'], true)) $pr .= '<w:vAlign w:val="' . ($cell['attrs']['valign'] === 'middle' ? 'center' : 'bottom') . '"/>';
                $inner = '';
                foreach ($cell['content'] ?? [] as $p) {
                    // בתא — בלי רווח אחרי פסקה, כמו בעורך וב-PDF (אלא אם נקבע לפסקה במפורש)
                    if (($p['type'] ?? '') === 'paragraph' && !is_numeric($p['attrs']['spaceAfter'] ?? null)) $p['attrs']['spaceAfter'] = 0;
                    if ($cell['type'] === 'tableHeader' && ($p['type'] ?? '') === 'paragraph') {
                        $p['content'] = array_map(function ($t) {
                            if (($t['type'] ?? '') === 'text') $t['marks'] = array_merge($t['marks'] ?? [], [['type' => 'bold']]);
                            return $t;
                        }, $p['content'] ?? []);
                    }
                    $inner .= $this->block($p, []);
                }
                $out .= '<w:tc><w:tcPr>' . $pr . '</w:tcPr>' . ($inner !== '' ? $inner : '<w:p/>') . '</w:tc>';
                $col += $c['span'];
            }
            $out .= '</w:tr>';
        }
        return $out . '</w:tbl><w:p><w:pPr><w:spacing w:after="0"/></w:pPr></w:p>';
    }

    // ---------- סגנונות ומספור ----------

    /** <w:style> לכל סגנון (רגיל, כותרת מסמך, כותרות 1–3). */
    public function styles(): string
    {
        $out = '';
        $names = ['normal' => 'Normal', 'title' => 'Title'];
        for ($l = 1; $l <= 9; $l++) $names["h$l"] = "heading $l";
        foreach ($this->page['styles'] as $k => $st) if (preg_match(USER_STYLE_RE, (string)$k)) $names[$k] = style_name((string)($st['name'] ?? $k)) ?: $k;
        foreach ($names as $k => $name) {
            $st = $this->page['styles'][$k] ?? [];
            $sp = '';
            if (isset($st['before'])) $sp .= ' w:before="' . (int)round($st['before'] * 20) . '"';
            if (isset($st['after'])) $sp .= ' w:after="' . (int)round($st['after'] * 20) . '"';
            if (isset($st['line'])) $sp .= ' w:line="' . (int)round($st['line'] * 240) . '" w:lineRule="auto"';
            $user = (bool)preg_match(USER_STYLE_RE, $k);
            $ppr = ($k !== 'normal' && !$user ? '<w:keepNext/><w:keepLines/>' : '') . '<w:bidi/>' . ($sp ? "<w:spacing$sp/>" : '');
            // מספור כותרות: הסגנון מקושר להגדרת מספור אחת, ברמה של הכותרת
            if ($k[0] === 'h' && !$user && !empty($this->page['heading_numbers'])) {
                $this->headingNum ??= $this->newNum('headings', 1, 0);
                $ppr = '<w:keepNext/><w:keepLines/><w:numPr><w:ilvl w:val="' . ((int)substr($k, 1) - 1) . '"/><w:numId w:val="' . $this->headingNum . '"/></w:numPr>' . substr($ppr, strlen('<w:keepNext/><w:keepLines/>'));
            }
            if (!empty($st['align'])) $ppr .= '<w:jc w:val="' . ($this->jc($st['align'], true) ?? 'start') . '"/>';
            if ($k[0] === 'h') $ppr .= '<w:outlineLvl w:val="' . ((int)$k[1] - 1) . '"/>';
            $rpr = '';
            if (!empty($st['font'])) { $f = xml($st['font']); $rpr .= "<w:rFonts w:ascii=\"$f\" w:hAnsi=\"$f\" w:cs=\"$f\"/>"; }
            if ($k !== 'normal' && !$user) $rpr .= !empty($st['bold']) ? '<w:b/><w:bCs/>' : '<w:b w:val="0"/><w:bCs w:val="0"/>';
            elseif (!empty($st['bold'])) $rpr .= '<w:b/><w:bCs/>';
            if (!empty($st['italic'])) $rpr .= '<w:i/><w:iCs/>';
            if (!empty($st['color'])) $rpr .= '<w:color w:val="' . strtoupper(ltrim($st['color'], '#')) . '"/>';
            if (isset($st['size'])) { $hz = (int)round($st['size'] * 2); $rpr .= "<w:sz w:val=\"$hz\"/><w:szCs w:val=\"$hz\"/>"; }
            if (!empty($st['underline'])) $rpr .= '<w:u w:val="single"/>';
            $id = $this->styleId($k);
            $display = $this->prefix === '' ? $name : $this->prefix . ' ' . $name;
            $out .= '<w:style w:type="paragraph"' . ($k === 'normal' && $this->prefix === '' ? ' w:default="1"' : '') . ' w:styleId="' . $id . '">'
                . '<w:name w:val="' . xml($display) . '"/>' . ($k !== 'normal' ? '<w:basedOn w:val="' . $this->styleId('normal') . '"/><w:next w:val="' . $this->styleId('normal') . '"/>' : '')
                . '<w:qFormat/><w:pPr>' . $ppr . '</w:pPr>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '</w:style>';
        }
        $out .= '<w:style w:type="character" w:styleId="' . $this->prefix . 'Hyperlink"><w:name w:val="' . ($this->prefix === '' ? 'Hyperlink' : $this->prefix . ' Hyperlink') . '"/>'
            . '<w:uiPriority w:val="99"/><w:unhideWhenUsed/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>';
        return $out;
    }

    public function numberingParts(): array
    {
        $abs = '';
        $bullets = ['•', '◦', '▪', '•', '◦', '▪', '•', '◦', '▪'];
        $fmt = ['bullet' => 'bullet', 'decimal' => 'decimal', 'hebrew' => 'hebrew1', 'roman' => 'lowerRoman', 'multi' => 'decimal', 'headings' => 'decimal'];
        foreach ($this->abstracts as $id => $a) {
            $multi = in_array($a['type'], ['multi', 'headings'], true);
            $abs .= '<w:abstractNum w:abstractNumId="' . $id . '"><w:multiLevelType w:val="' . ($multi ? 'multilevel' : 'hybridMultilevel') . '"/>';
            for ($i = 0; $i < 9; $i++) {
                // רב-רמתי: "%1.%2." — כל הרמות שמעל; כותרות: "%1." ו-"%1.%2" ואחריו רווח (כמו בעורך)
                $path = implode('.', array_map(fn($k) => '%' . $k, range(1, $i + 1)));
                $text = match ($a['type']) { 'bullet' => $bullets[$i], 'multi' => $path . '.', 'headings' => $i === 0 ? '%1.' : $path, default => '%' . ($i + 1) . '.' };
                $start = $i === $a['lvl'] ? $a['start'] : 1;
                if ($a['type'] === 'headings') {
                    $abs .= '<w:lvl w:ilvl="' . $i . '"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:pStyle w:val="' . $this->styleId('h' . ($i + 1)) . '"/>'
                        . '<w:suff w:val="space"/><w:lvlText w:val="' . xml($text) . '"/><w:lvlJc w:val="start"/><w:pPr><w:bidi/><w:ind w:start="0" w:hanging="0"/></w:pPr></w:lvl>';
                    continue;
                }
                $abs .= '<w:lvl w:ilvl="' . $i . '"><w:start w:val="' . $start . '"/><w:numFmt w:val="' . $fmt[$a['type']] . '"/>'
                    . '<w:lvlText w:val="' . xml($text) . '"/><w:lvlJc w:val="start"/><w:pPr><w:bidi/><w:ind w:start="' . (360 + 360 * ($i + 1)) . '" w:hanging="360"/></w:pPr></w:lvl>';
            }
            $abs .= '</w:abstractNum>';
        }
        $num = '';
        foreach ($this->nums as $id => $absId) $num .= '<w:num w:numId="' . $id . '"><w:abstractNumId w:val="' . $absId . '"/></w:num>';
        return ['abstract' => $abs, 'num' => $num];
    }

    public function rels(): string
    {
        $out = '';
        foreach ($this->media as $m) {
            $out .= '<Relationship Id="' . $m['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $m['name'] . '"/>';
        }
        foreach ($this->links as $href => $rid) {
            $out .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . xml($href) . '" TargetMode="External"/>';
        }
        return $out;
    }
}

const W_NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
    . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
    . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';

/** גוף לשילוב ב-docx של המארחת (embed.md, פרק 3): {body, styles, numbering, media, rels}. */
function export_ooxml(array $json, array $page): array
{
    $o = new Ooxml($page, 'wp');
    $body = $o->body($json);
    $styles = $o->styles();               // לפני המספור: מספור הכותרות נוצר בסגנונות
    $parts = $o->numberingParts();
    return [
        'body' => $body,
        'styles' => $styles,
        'numbering' => $parts['abstract'] . $parts['num'],
        'numbering_parts' => $parts,
        'media' => array_values(array_map(fn($m) => ['name' => $m['name'], 'type' => $m['type'], 'rid' => $m['rid'], 'base64' => base64_encode($m['bytes'])], $o->media)),
        'rels' => $o->rels(),
        'namespaces' => W_NS,
    ];
}

/** docx שלם. מחזיר את הבתים. */
function export_docx(array $json, array $page, string $title = ''): string
{
    $o = new Ooxml($page, '');

    // סימן מים — כמו ש-Word עצמו שומר אותו: צורת WordArt (VML) בכותרת העליונה, ממורכזת בעמוד ומסובבת 315°
    $wm = $page['watermark'] ?? '';
    $wmXml = $wm === '' ? '' : (function () use ($wm, $page) {
        [$wmm, $hmm] = page_mm($page);
        $size = watermark_size($wm, $wmm * 72 / 25.4, $hmm * 72 / 25.4);
        $w = round(mb_strlen($wm) * $size * 0.6, 1);
        return '<w:p><w:pPr><w:bidi/></w:pPr><w:r><w:rPr><w:noProof/></w:rPr><w:pict>'
            . '<v:shapetype id="_x0000_t136" coordsize="21600,21600" o:spt="136" adj="10800" path="m@7,l@8,m@5,21600l@6,21600e"><v:formulas>'
            . implode('', array_map(fn($e) => '<v:f eqn="' . $e . '"/>', ['sum #0 0 10800', 'prod #0 2 1', 'sum 21600 0 @1', 'sum 0 0 @2', 'sum 21600 0 @3', 'if @0 @3 0',
                'if @0 21600 @1', 'if @0 0 @2', 'if @0 @4 21600', 'mid @5 @6', 'mid @8 @5', 'mid @7 @8', 'mid @6 @7', 'sum @6 0 @5']))
            . '</v:formulas><v:path textpathok="t" o:connecttype="custom" o:connectlocs="@9,0;@10,10800;@11,21600;@12,10800" o:connectangles="270,180,90,0"/>'
            . '<v:textpath on="t" fitshape="t"/><v:handles><v:h position="#0,bottomRight" xrange="6629,14971"/></v:handles><o:lock v:ext="edit" text="t" shapetype="t"/></v:shapetype>'
            . '<v:shape id="PowerPlusWaterMarkObject1" o:spid="_x0000_s2049" type="#_x0000_t136" style="position:absolute;margin-left:0;margin-top:0;width:' . $w . 'pt;height:' . $size . 'pt;'
            . 'rotation:315;z-index:-251657216;mso-position-horizontal:center;mso-position-horizontal-relative:page;mso-position-vertical:center;mso-position-vertical-relative:page"'
            . ' o:allowincell="f" fillcolor="#d0d0d0" stroked="f"><v:textpath style="font-family:&quot;' . xml($page['font']['family']) . '&quot;;font-size:1pt;font-weight:bold" string="' . xml($wm) . '"/></v:shape>'
            . '</w:pict></w:r></w:p>';
    })();
    // כותרת עליונה ותחתונה: טקסט חופשי + מספר העמוד (בצד שנבחר). עמוד ראשון בלי מספר — titlePg.
    $hf = function (string $text, bool $withNumber, string $tag, array $nb) use ($wmXml) {
        $paras = $tag === 'hdr' ? $wmXml : '';
        $jcNum = ['right' => null, 'center' => 'center', 'left' => 'end'][$nb['align']] ?? 'center';
        if ($text !== '') {
            foreach (preg_split('/\R/u', $text) as $line) {
                $paras .= '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:rtl/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t xml:space="preserve">' . xml($line) . '</w:t></w:r></w:p>';
            }
        }
        if ($withNumber) {
            $paras .= '<w:p><w:pPr><w:bidi/>' . ($jcNum ? '<w:jc w:val="' . $jcNum . '"/>' : '') . '</w:pPr>' . page_number_runs($nb['format']) . '</w:p>';
        }
        if ($paras === '') $paras = '<w:p><w:pPr><w:bidi/></w:pPr></w:p>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:' . $tag . ' ' . W_NS . ' xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
            . $paras . '</w:' . $tag . '>';
    };
    $files = [];
    $hfRels = '';
    $hfIds = [];   // חתימת כותרות → [refs] — מקטעים עם אותן כותרות חולקים את אותם קבצים
    /** הפניות לכותרות של מקטע (יוצר את הקבצים בפעם הראשונה). */
    $hfRefs = function (array $p, bool $first) use (&$files, &$hfRels, &$hfIds, $hf): string {
        $nb = $p['numbering'];
        $titlePg = $first && $nb['show'] && !$nb['first_page'];
        $key = md5(json_encode([$p['header'], $p['footer'], $nb['show'], $nb['position'], $nb['align'], $nb['format'], $titlePg]));
        if (!isset($hfIds[$key])) {
            $n = count($hfIds) + 1;
            $numTop = $nb['show'] && $nb['position'] === 'top';
            $numBottom = $nb['show'] && $nb['position'] === 'bottom';
            $files["word/header$n.xml"] = $hf($p['header'], $numTop, 'hdr', $nb);
            $files["word/footer$n.xml"] = $hf($p['footer'], $numBottom, 'ftr', $nb);
            $hfRels .= '<Relationship Id="rIdH' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header' . $n . '.xml"/>'
                . '<Relationship Id="rIdF' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer' . $n . '.xml"/>';
            $refs = '<w:headerReference w:type="default" r:id="rIdH' . $n . '"/><w:footerReference w:type="default" r:id="rIdF' . $n . '"/>';
            if ($titlePg) {
                $files["word/header{$n}f.xml"] = $hf($p['header'], false, 'hdr', $nb);
                $files["word/footer{$n}f.xml"] = $hf($p['footer'], false, 'ftr', $nb);
                $hfRels .= '<Relationship Id="rIdH' . $n . 'f" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header' . $n . 'f.xml"/>'
                    . '<Relationship Id="rIdF' . $n . 'f" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer' . $n . 'f.xml"/>';
                $refs .= '<w:headerReference w:type="first" r:id="rIdH' . $n . 'f"/><w:footerReference w:type="first" r:id="rIdF' . $n . 'f"/>';
            }
            $hfIds[$key] = $refs;
        }
        return $hfIds[$key] . ($titlePg ? '' : '');
    };
    /** <w:sectPr> של מקטע: גודל, כיוון, שוליים, כותרות; מספור העמודים מתחיל רק במקטע הראשון ונמשך אחריו. */
    $sectXml = function (array $p, int $index) use ($hfRefs, $page): string {
        [$wmm, $hmm] = page_mm($p);
        $tw = fn($mm) => (int)round($mm * TWIP_PER_MM);
        $mg = $p['margins'];
        $nb = $p['numbering'];
        $first = $index === 0;
        $titlePg = $first && $nb['show'] && !$nb['first_page'];
        $pgNum = '<w:pgNumType' . ($nb['format'] === 'hebrew' ? ' w:fmt="hebrew1"' : '') . ($first ? ' w:start="' . (int)$page['numbering']['start'] . '"' : '') . '/>';
        return '<w:sectPr>' . $hfRefs($p, $first) . ($first ? '' : '<w:type w:val="nextPage"/>')
            . '<w:pgSz w:w="' . $tw($wmm) . '" w:h="' . $tw($hmm) . '"' . ($p['orientation'] === 'landscape' ? ' w:orient="landscape"' : '') . '/>'
            . '<w:pgMar w:top="' . $tw($mg['top']) . '" w:right="' . $tw($mg['right']) . '" w:bottom="' . $tw($mg['bottom']) . '" w:left="' . $tw($mg['left'])
            . '" w:header="' . $tw(min(12.5, $mg['top'] / 2)) . '" w:footer="' . $tw(min(12.5, $mg['bottom'] / 2)) . '" w:gutter="0"/>'
            . $pgNum . ($titlePg ? '<w:titlePg/>' : '') . '<w:bidi/></w:sectPr>';
    };
    $o->sectFn = $sectXml;
    $body = $o->body($json);
    $sectCount = substr_count($body, '<w:sectPr>');
    $sect = $sectXml($o->cur, $sectCount);   // המקטע האחרון — בסוף הגוף
    if ($o->footnotes) $files['word/footnotes.xml'] = $o->footnotesXml();

    $pc = color_val($page['page_color'] ?? null);   // צבע עמוד — w:background, ו-displayBackgroundShape בהגדרות (אחרת Word לא מציג)
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . W_NS . '>' . ($pc ? '<w:background w:color="' . strtoupper($pc) . '"/>' : '')
        . '<w:body>' . $body . $sect . '</w:body></w:document>';
    $f = xml($page['font']['family']);
    $sz = (int)round($page['font']['size'] * 2);
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . W_NS . '>'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $f . '" w:hAnsi="' . $f . '" w:cs="' . $f . '" w:eastAsia="' . $f . '"/>'
        . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/><w:lang w:val="he-IL" w:eastAsia="en-US" w:bidi="he-IL"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:bidi/></w:pPr></w:pPrDefault></w:docDefaults>'
        . $o->styles()
        . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:bidiVisual/><w:tblInd w:w="0" w:type="dxa"/>'
        . '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:start w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:end w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
        // הערות שוליים: טקסט ההערה (10 נק') והסימן (עילי) — בשמות של Word, כדי שיתנהגו כמו הערות שנוצרו ב-Word
        . '<w:style w:type="paragraph" w:styleId="FootnoteText"><w:name w:val="footnote text"/><w:basedOn w:val="Normal"/><w:pPr><w:bidi/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
        . '<w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
        . '<w:style w:type="character" w:styleId="FootnoteReference"><w:name w:val="footnote reference"/><w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>'
        . '</w:styles>';
    $parts = $o->numberingParts();
    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering ' . W_NS . '>' . $parts['abstract'] . $parts['num'] . '</w:numbering>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings ' . W_NS . '>' . ($pc ? '<w:displayBackgroundShape/>' : '') . '<w:defaultTabStop w:val="720"/>'
        . '<w:characterSpacingControl w:val="doNotCompress"/>'
        . ($o->footnotes ? '<w:footnotePr><w:footnote w:id="-1"/><w:footnote w:id="0"/></w:footnotePr>' : '') . '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="gif" ContentType="image/gif"/><Default Extension="webp" ContentType="image/webp"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    foreach (array_keys($files) as $p) {
        $kind = str_contains($p, 'footnotes') ? 'footnotes' : (str_contains($p, 'header') ? 'header' : 'footer');
        $ct .= '<Override PartName="/' . $p . '" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.' . $kind . '+xml"/>';
    }
    $ct .= '</Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rIdN" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
        . '<Relationship Id="rIdT" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
        . $hfRels . ($o->footnotes ? '<Relationship Id="rIdFn" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>' : '')
        . $o->rels() . '</Relationships>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xml($title) . '</dc:title><dc:creator>עורך התמלילים</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';

    $tmp = tempnam(sys_get_temp_dir(), 'wpdocx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('docProps/core.xml', $core);
    $zip->addFromString('word/document.xml', $document);
    $zip->addFromString('word/styles.xml', $styles);
    $zip->addFromString('word/numbering.xml', $numbering);
    $zip->addFromString('word/settings.xml', $settings);
    $zip->addFromString('word/_rels/document.xml.rels', $docRels);
    foreach ($files as $p => $x) $zip->addFromString($p, $x);
    foreach ($o->media as $m) $zip->addFromString('word/media/' . $m['name'], $m['bytes']);
    $zip->close();
    $bytes = file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

/** שדה מספר עמוד בתבנית שנבחרה. */
function page_number_runs(string $format): string
{
    $t = fn($s) => '<w:r><w:rPr><w:rtl/></w:rPr><w:t xml:space="preserve">' . xml($s) . '</w:t></w:r>';
    $field = fn($name) => '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> ' . $name . ' </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>';
    return match ($format) {
        'page_n' => $t("עמ' ") . $field('PAGE'),
        'n_of_m' => $field('PAGE') . $t(' מתוך ') . $field('NUMPAGES'),
        'dash_n' => $t('- ') . $field('PAGE') . $t(' -'),
        default => $field('PAGE'),
    };
}
