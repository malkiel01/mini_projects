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
require_once __DIR__ . '/caption.php';
require_once __DIR__ . '/track.php';

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

/**
 * עצירות טאב של פסקה (attrs.tabs, כמו ב-Word): [{pos (נק' מהשוליים, בצד ההתחלה), align: start|center|end|decimal,
 * leader: none|dot|hyphen|underscore}], ממוינות, בלי כפולות. ערך לא תקין — לא נכנס. כמו tabsOf ב-assets/js/tabs.js.
 */
function tabs_val($v): array
{
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $t) {
        if (!is_array($t) || !is_numeric($t['pos'] ?? null)) continue;
        $pos = round((float)$t['pos'], 1);
        if ($pos <= 0 || $pos > 1584) continue;
        $out[(string)$pos] = ['pos' => $pos,
            'align' => in_array($t['align'] ?? '', ['center', 'end', 'decimal'], true) ? $t['align'] : 'start',
            'leader' => in_array($t['leader'] ?? '', ['dot', 'hyphen', 'underscore'], true) ? $t['leader'] : 'none'];
    }
    ksort($out, SORT_NUMERIC);
    return array_slice(array_values($out), 0, 20);
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
/**
 * מעבר מקטע תמיד ברמה העליונה (כמו בעורך — extensions.js, wpSectionTop): מעבר שנמצא בתוך רשימה / טבלה יוצא החוצה,
 * מיד אחרי הבלוק העליון שהכיל אותו. בלי זה העימוד והרוחב מתפצלים (ביקורת 04, R01).
 */
function sections_top(array $json): array
{
    $pull = function (array $n, array &$out) use (&$pull): array {
        if (!is_array($n['content'] ?? null)) return $n;
        $kids = [];
        foreach ($n['content'] as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'sectionBreak') { $out[] = $c; continue; }
            $kids[] = is_array($c) ? $pull($c, $out) : $c;
        }
        $n['content'] = $kids;
        return $n;
    };
    $top = [];
    foreach ($json['content'] ?? [] as $n) {
        if (!is_array($n) || ($n['type'] ?? '') === 'sectionBreak') { $top[] = $n; continue; }
        $out = [];
        $top[] = $pull($n, $out);
        array_push($top, ...$out);
    }
    if (isset($json['content'])) $json['content'] = $top;
    return $json;
}

function export_html(array $json, array $page, bool $absolute = true): array
{
    $json = sections_top($json);
    [$json, $foot, $end] = fn_numbered(track_visual(seq_flatten(with_media_src($json, $absolute))));
    $body = '';
    foreach ($json['content'] ?? [] as $n) $body .= html_node($n);
    // אין עמודים ב-HTML: הערות השוליים בסוף, ואחריהן הערות הסיום (כמו "שמירה כדף אינטרנט" ב-Word)
    foreach (['wp-footnotes' => $foot, 'wp-endnotes' => $end] as $cls => $list) {
        if (!$list) continue;
        $body .= '<section class="' . $cls . '" aria-label="' . ($cls === 'wp-footnotes' ? 'הערות שוליים' : 'הערות סיום') . '"><hr>';
        foreach ($list as [$num, $a]) {
            $id = ($cls === 'wp-footnotes' ? 'f' : 'e') . $num;
            $body .= '<p class="wp-fn" id="wp-fn-' . $id . '"><a class="wp-fn-n" href="#wp-fnr-' . $id . '">' . $num . '</a> '
                . implode('', array_map('html_node', fn_inline($a))) . '</p>';
        }
        $body .= '</section>';
    }
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
        . '.wp-doc .page-break{break-after:page;height:0}'
        . '.wp-doc .wp-fn-ref a,.wp-doc .wp-fn-n{text-decoration:none;color:inherit}.wp-doc .wp-fn-n{vertical-align:super;font-size:smaller}'
        . '.wp-doc .wp-fn{font-size:10pt}.wp-doc :is(.wp-footnotes,.wp-endnotes)>hr{width:33%;margin-inline:0 auto;border:0;border-top:1px solid #000}';
    // כותרות 7–9 — אין תגית HTML: פסקה עם מחלקה (html_node); סגנון משתמש — מחלקה wp-s-u<N>, מעל "רגיל"
    $sel = ['normal' => '.wp-doc p', 'title' => '.wp-doc .wp-title', 'caption' => '.wp-doc .wp-caption'];
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
            if (in_array($a['pstyle'] ?? '', ['title', 'caption'], true)) return '<p' . block_attrs($a, 'wp-' . $a['pstyle']) . '>' . ($inner ?: '<br>') . '</p>';
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
        case 'columnBreak':
            return '<div class="column-break" style="break-before:column"></div>';
        case 'toc':   // תוכן עניינים: השורות השמורות, כל אחת קישור לכותרת (אם יש לה עוגן) — בלי עוגנים: טקסט ומספר
            $rows = '';
            foreach (is_array($a['entries'] ?? null) ? $a['entries'] : [] as $e) {
                $rows .= '<li class="toc-l' . max(0, min(9, (int)($e['l'] ?? 1))) . '"><span>' . h((string)($e['t'] ?? '')) . '</span> <span class="toc-p">' . h((string)($e['p'] ?? '')) . '</span></li>';
            }
            return '<nav class="toc" aria-label="' . h(toc_title($a)) . '"><p class="toc-title"><b>' . h(toc_title($a)) . '</b></p><ul>' . $rows . '</ul></nav>';
        case 'hardBreak':
            return '<br>';
        case 'footnote':   // הסימן — קישור להערה שבסוף (export_html)
            $id = (!empty($a['end']) ? 'e' : 'f') . ($a['_n'] ?? '');
            return '<sup class="wp-fn-ref"><a href="#wp-fn-' . h($id) . '" id="wp-fnr-' . h($id) . '">' . h((string)($a['_n'] ?? '')) . '</a></sup>';
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
    [$json, $foot, $end] = fn_numbered(seq_flatten(track_final($json)));
    text_blocks($json['content'] ?? [], $lines, 0);
    // ההערות בסוף, אחרי קו — כל אחת בשורה עם המספר שלה בסוגריים, כמו הסימן בטקסט
    foreach ([$foot, $end] as $list) {
        if (!$list) continue;
        $lines[] = '';
        $lines[] = '____________';
        foreach ($list as [$num, $a]) $lines[] = '[' . $num . '] ' . implode('', array_map(fn($t) => $t['text'], fn_inline($a)));
    }
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
        } elseif ($t === 'pageBreak' || $t === 'columnBreak' || $t === 'horizontalRule') {
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
    if (($n['type'] ?? '') === 'footnote') return '[' . ($n['attrs']['_n'] ?? '') . ']';   // export_text
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
    private array $paraIds = [];     // מזהי פסקה שכבר נכתבו כסימנייה (_mbe_p<id>) — שם סימנייה ב-Word ייחודי
    public array $nums = [];         // [numId => ['abstract' => id]]
    public array $abstracts = [];    // [abstractId => ['type' => ..]]
    private int $docPr = 1;
    private int $numBase;
    public float $contentTw;         // רוחב אזור הטקסט ב-twips (לטבלאות ולתמונות) — של המקטע הנוכחי
    public array $cur;               // הגדרות העמוד של המקטע הנוכחי (מתחלפות בכל מעבר מקטע)
    public $sectFn = null;           // fn(array $page, int $index): string — <w:sectPr> של מקטע שמסתיים (docx שלם בלבד)
    private int $sectIndex = 0;
    public array $endnotes = [];     // [id => צמתי text עם עיצוב] — word/endnotes.xml
    private array $seqNums = [];     // כיתובים: [id => [label, n]] (seq_numbers) — לשדות REF
    private array $seqList = [];     // המספר של כל seq לפי הסדר (seq_text_list) — התוצאה השמורה של שדה SEQ
    private int $seqAt = 0;
    // הערות (docx שלם): השרשורים מ-attrs.comments; לכל הערה — הצומת הראשון והאחרון שלה (לפי הסדר במסמך)
    private array $cmData = [];
    private array $cmFirst = [];
    private array $cmLast = [];
    private int $inlineAt = 0;
    public array $comments = [];     // [מספר ב-Word => ['id' => ..., 'parent' => מספר | null, 'c' => נתונים]] — word/comments.xml
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
        $base = $k === 'normal' ? 'Normal' : ($k === 'title' ? 'Title' : ($k === 'caption' ? 'Caption' : ($k[0] === 'h' ? 'Heading' . substr($k, 1) : 'User' . substr($k, 1))));
        return $this->prefix === '' ? $base : $this->prefix . $base;
    }

    /** פסקאות של כותרת עשירה — באותו עיצוב כמו בגוף; התמונות נרשמות כרגיל (הקשרים שלהן — hf_rels). */
    public function hfBlocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $n) $out .= $this->block($n, []);
        return $out;
    }

    public function body(array $json): string
    {
        $this->seqNums = seq_numbers($json);
        $this->seqList = seq_text_list($json);
        $this->seqAt = 0;
        $this->commentRanges($json);
        $out = '';
        foreach ($json['content'] ?? [] as $n) $out .= $this->block($n, []);
        return $out;
    }

    /** הטווח של כל הערה: האינדקס של הצומת הראשון והאחרון שלה מבין כל הצמתים בתוך פסקאות (באותו סדר כמו para()). */
    private function commentRanges(array $json): void
    {
        $this->cmData = $this->prefix === '' && is_array($json['attrs']['comments'] ?? null) ? $json['attrs']['comments'] : [];
        $this->cmFirst = $this->cmLast = [];
        $this->inlineAt = 0;
        if (!$this->cmData) return;
        $i = 0;
        $walk = function (array $nodes) use (&$walk, &$i) {
            foreach ($nodes as $n) {
                if (!is_array($n)) continue;
                if (in_array($n['type'] ?? '', ['paragraph', 'heading'], true)) {
                    foreach ($n['content'] ?? [] as $c) {
                        foreach ($c['marks'] ?? [] as $m) {
                            $id = (string)($m['attrs']['id'] ?? '');
                            if (($m['type'] ?? '') !== 'comment' || !is_array($this->cmData[$id] ?? null)) continue;
                            $this->cmFirst[$id] ??= $i;
                            $this->cmLast[$id] = $i;
                        }
                        $i++;
                    }
                } elseif (is_array($n['content'] ?? null)) $walk($n['content']);
            }
        };
        $walk($json['content'] ?? []);
    }

    /** תחילת הטווח של ההערות שמתחילות בצומת $k — ההערה והתשובות שלה (כל אחת הערה משלה ב-Word, באותו טווח). */
    private function commentStarts(int $k): string
    {
        $out = '';
        foreach ($this->cmFirst as $id => $f) {
            if ($f !== $k) continue;
            $c = $this->cmData[$id];
            $n = count($this->comments);
            $this->comments[$n] = ['id' => $id, 'parent' => null, 'c' => $c];
            $out .= '<w:commentRangeStart w:id="' . $n . '"/>';
            foreach (is_array($c['replies'] ?? null) ? $c['replies'] : [] as $r) {
                if (!is_array($r)) continue;
                $m = count($this->comments);
                $this->comments[$m] = ['id' => $id, 'parent' => $n, 'c' => $r];
                $out .= '<w:commentRangeStart w:id="' . $m . '"/>';
            }
        }
        return $out;
    }

    private function commentEnds(int $k): string
    {
        $out = '';
        foreach ($this->cmLast as $id => $l) {
            if ($l !== $k) continue;
            foreach ($this->comments as $n => $x) {
                if ($x['id'] !== $id) continue;
                $out .= '<w:commentRangeEnd w:id="' . $n . '"/><w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:commentReference w:id="' . $n . '"/></w:r>';
            }
        }
        return $out;
    }

    /**
     * word/comments.xml ו-commentsExtended.xml (Word 2013+): לכל הערה פסקה עם w14:paraId; התשובות — paraIdParent,
     * ו"נפתר" — w15:done.
     */
    public function commentsXml(): array
    {
        $pid = fn(int $n) => sprintf('%08X', 0x10000000 + $n);
        $xml = '';
        $ex = '';
        foreach ($this->comments as $n => $x) {
            $c = $x['c'];
            $date = preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)($c['date'] ?? '')) ? $c['date'] : gmdate('Y-m-d\TH:i:s\Z');
            $author = mb_substr(trim((string)($c['author'] ?? '')), 0, 120);
            $lines = preg_split('/\R/u', (string)($c['text'] ?? ''));
            $xml .= '<w:comment w:id="' . $n . '" w:author="' . xml($author) . '" w:date="' . $date . '" w:initials="' . xml(mb_substr($author, 0, 1)) . '">';
            foreach ($lines as $i => $line) {
                $xml .= '<w:p' . ($i === count($lines) - 1 ? ' w14:paraId="' . $pid($n) . '" w14:textId="77777777"' : '') . '><w:pPr><w:pStyle w:val="CommentText"/><w:bidi/></w:pPr>'
                    . ($i === 0 ? '<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:annotationRef/></w:r>' : '')
                    . '<w:r><w:rPr><w:rtl/></w:rPr><w:t xml:space="preserve">' . xml($line) . '</w:t></w:r></w:p>';
            }
            $xml .= '</w:comment>';
            $done = $x['parent'] === null ? !empty($c['done']) : !empty($this->comments[$x['parent']]['c']['done']);
            $ex .= '<w15:commentEx w15:paraId="' . $pid($n) . '"' . ($x['parent'] !== null ? ' w15:paraIdParent="' . $pid($x['parent']) . '"' : '') . ' w15:done="' . ($done ? 1 : 0) . '"/>';
        }
        $ns = W_NS . ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml"'
            . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="w14 w15"';
        return [
            'word/comments.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:comments ' . $ns . '>' . $xml . '</w:comments>',
            'word/commentsExtended.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w15:commentsEx ' . $ns . '>' . $ex . '</w15:commentsEx>',
        ];
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
            case 'columnBreak':
                return '<w:p><w:r><w:br w:type="column"/></w:r></w:p>';
            case 'toc':
                return $this->toc($a);
            case 'sectionBreak':
                // ב-Word מקטע מסתיים בפסקה שנושאת את ההגדרות שלו; המקטע הבא — בהגדרות שבצומת (שינויים בלבד)
                $next = page_section($a['page'] ?? null, $this->cur);
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

    /** מאפייני פסקה (תוכן w:pPr): סגנון, שבירה, מספור, עצירות, כיוון, ריווח, הזחה, יישור — בסדר של הסכמה של Word. */
    private function pprXml(array $a, ?string $style, array $num, bool $inList): string
    {
        $rtl = ($a['dir'] ?? 'rtl') !== 'ltr';
        $ppr = '';
        if ($style) $ppr .= '<w:pStyle w:val="' . $this->styleId($style) . '"/>';
        // שבירת עמודים — בסדר שהסכמה של Word דורשת (אחרי pStyle, לפני numPr)
        if (!empty($a['keepNext'])) $ppr .= '<w:keepNext/>';
        if (!empty($a['keepLines'])) $ppr .= '<w:keepLines/>';
        if (!empty($a['breakBefore'])) $ppr .= '<w:pageBreakBefore/>';
        if ($num) $ppr .= '<w:numPr><w:ilvl w:val="' . $num['lvl'] . '"/><w:numId w:val="' . $num['num'] . '"/></w:numPr>';
        // עצירות טאב: בפסקה מימין לשמאל Word הופך את left / right (left = יישור לסוף — כמו בתוכן העניינים שלמטה)
        if ($tabs = tabs_val($a['tabs'] ?? null)) {
            $val = fn($al) => match ($al) { 'start' => $rtl ? 'right' : 'left', 'end' => $rtl ? 'left' : 'right', default => $al };
            $ppr .= '<w:tabs>' . implode('', array_map(fn($t) => '<w:tab w:val="' . $val($t['align']) . '"' . ($t['leader'] !== 'none' ? ' w:leader="' . $t['leader'] . '"' : '')
                . ' w:pos="' . (int)round($t['pos'] * 20) . '"/>', $tabs)) . '</w:tabs>';
        }
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
        return $ppr;
    }

    private function para(array $n, ?string $style, array $num, bool $inList): string
    {
        $a = $n['attrs'] ?? [];
        $ppr = $this->pprXml($a, $style, $num, $inList);
        // שינוי עיצוב פסקה במעקב: w:pPrChange — האחרון ב-pPr, עם הפסקה כפי שהייתה (Word מציג "עוצב" ומאפשר לקבל / לדחות)
        $old = $a['pchg']['old'] ?? null;
        if (is_array($old) && in_array($old['type'] ?? '', ['paragraph', 'heading'], true) && $this->prefix === '') {
            $oa = is_array($old['attrs'] ?? null) ? $old['attrs'] : [];
            $os = block_style_key(['type' => $old['type'], 'attrs' => $oa], $this->page);
            $date = preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)($a['pchg']['date'] ?? '')) ? ' w:date="' . $a['pchg']['date'] . '"' : '';
            $ppr .= '<w:pPrChange w:id="' . (++$this->bookmarkId) . '" w:author="' . xml(mb_substr((string)($a['pchg']['author'] ?? ''), 0, 120)) . '"' . $date . '><w:pPr>'
                . $this->pprXml($oa, $os === 'normal' ? null : $os, $num, $inList) . '</w:pPr></w:pPrChange>';
        }
        $runs = '';
        $anchors = '';
        $captions = '';
        $openLink = null;
        foreach ($n['content'] ?? [] as $c) {
            // הערות: תחילת טווח לפני הצומת, וסוף + סימן ההערה אחריו (מחוץ לקישור)
            $k = $this->inlineAt++;
            if ($this->cmFirst && ($cs = $this->commentStarts($k)) !== '') {
                if ($openLink !== null) { $runs .= '</w:hyperlink>'; $openLink = null; }
                $runs .= $cs;
            }
            $ends = function () use ($k, &$runs, &$openLink) {
                if (!$this->cmLast || ($ce = $this->commentEnds($k)) === '') return;
                if ($openLink !== null) { $runs .= '</w:hyperlink>'; $openLink = null; }
                $runs .= $ce;
            };
            if (($c['type'] ?? '') === 'image') {
                if (!empty($c['attrs']['caption'])) {
                    // כיתוב: פסקה ממורכזת אחרי הפסקה של התמונה
                    $captions .= '<w:p><w:pPr><w:bidi/><w:spacing w:before="40" w:after="120"/><w:jc w:val="center"/></w:pPr>'
                        . $this->runs((string)$c['attrs']['caption'], [['type' => 'italic'], ['type' => 'textStyle', 'attrs' => ['fontSize' => '9pt', 'color' => '#333333']]], []) . '</w:p>';
                }
                if (($c['attrs']['wrap'] ?? 'inline') !== 'inline') { $anchors .= $this->image($c); $ends(); continue; }
            }
            // קישור: ריצות רצופות עם אותה כתובת — בתוך w:hyperlink אחד
            $href = ($c['type'] ?? '') === 'text' ? link_href($c['marks'] ?? []) : null;
            if ($href !== $openLink) {
                if ($openLink !== null) $runs .= '</w:hyperlink>';
                if ($href !== null) $runs .= $this->hyperlink($href);
                $openLink = $href;
            }
            $runs .= $this->inline($c, $a);
            $ends();
        }
        if ($openLink !== null) $runs .= '</w:hyperlink>';
        // מזהה פסקה של חבילת היצירה: סימנייה נסתרת _mbe_p<id> סביב הפסקה (mbe-format.md סעיף 7) — כך פסקה שחזרה מ-Word
        // מזוהה. לא בגוף לשילוב (prefix) — שם זו פסקה של המארחת
        $pid = $a['id'] ?? null;
        if ($this->prefix === '' && is_string($pid) && preg_match(PARA_ID_RE, $pid) && !isset($this->paraIds[$pid])) {
            $this->paraIds[$pid] = true;
            $bid = ++$this->bookmarkId;
            $runs = '<w:bookmarkStart w:id="' . $bid . '" w:name="' . MBE_PARA_BOOKMARK . $pid . '"/>' . $runs . '<w:bookmarkEnd w:id="' . $bid . '"/>';
        }
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
                $out = $this->runs((string)($c['text'] ?? ''), $c['marks'] ?? [], $pa);
                // מעקב שינויים: w:ins / w:del (בטקסט שנמחק — w:delText), עם מחבר ותאריך — Word מציג ומאפשר לקבל / לדחות
                foreach ($c['marks'] ?? [] as $m) {
                    $t = $m['type'] ?? '';
                    if (($t !== 'ins' && $t !== 'del') || $this->prefix !== '') continue;
                    $date = preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)($m['attrs']['date'] ?? '')) ? ' w:date="' . $m['attrs']['date'] . '"' : '';
                    if ($t === 'del') $out = str_replace(['<w:t ', '<w:t>', '</w:t>'], ['<w:delText ', '<w:delText>', '</w:delText>'], $out);
                    return '<w:' . $t . ' w:id="' . (++$this->bookmarkId) . '" w:author="' . xml(mb_substr((string)($m['attrs']['author'] ?? ''), 0, 120)) . '"' . $date . '>' . $out . '</w:' . $t . '>';
                }
                return $out;
            case 'hardBreak':
                return '<w:r><w:br/></w:r>';
            case 'bookmark':
                if (!($b = bookmark_name($c['attrs']['name'] ?? ''))) return '';
                $id = ++$this->bookmarkId;
                return '<w:bookmarkStart w:id="' . $id . '" w:name="' . xml($b) . '"/><w:bookmarkEnd w:id="' . $id . '"/>';
            case 'image':
                return $this->image($c);
            case 'seq':   // כיתוב: שדה SEQ בתוך סימניה נסתרת _Ref<id> — אליה מפנות ההפניות הצולבות (כמו ב-Word)
                $label = seq_label($c['attrs']['label'] ?? '');
                $fld = $this->field('SEQ ' . $label . ' \\* ARABIC', $this->seqList[$this->seqAt++] ?? '1', $c['marks'] ?? [], $pa);
                if (($sid = seq_id($c['attrs']['id'] ?? '')) === null) return $fld;
                $id = ++$this->bookmarkId;
                return '<w:bookmarkStart w:id="' . $id . '" w:name="_Ref' . $this->prefix . $sid . '"/>' . $fld . '<w:bookmarkEnd w:id="' . $id . '"/>';
            case 'xref':   // הפניה צולבת: שדה REF למספר; "איור 2" — התווית כטקסט ואחריה השדה, כך שעדכון ב-Word שומר על שניהם
                $a = $c['attrs'] ?? [];
                $t = $this->seqNums[seq_id($a['target'] ?? '') ?? ''] ?? null;
                if (!$t) return $this->runs(XREF_MISSING, $c['marks'] ?? [], $pa);
                return (($a['show'] ?? 'full') === 'num' ? '' : $this->runs($t['label'] . ' ', $c['marks'] ?? [], $pa))
                    . $this->field('REF _Ref' . $this->prefix . seq_id($a['target']) . ' \\h', (string)$t['n'], $c['marks'] ?? [], $pa);
            case 'footnote':
                $inline = fn_inline($c['attrs'] ?? []);
                if ($this->prefix !== '') {
                    // גוף לשילוב: אין לנו חלק הערות במסמך של המארחת — הטקסט בסוגריים, בכתב עילי (עם העיצוב שלו)
                    $sup = [['type' => 'superscript']];
                    $out = $this->runs(' (', $sup, $pa);
                    foreach ($inline as $t) $out .= $this->runs($t['text'], array_merge($t['marks'] ?? [], $sup), $pa);
                    return $out . $this->runs(')', $sup, $pa);
                }
                if (!empty($c['attrs']['end'])) {   // הערת סיום — word/endnotes.xml (Word ממספר i, ii…)
                    $id = count($this->endnotes) + 1;
                    $this->endnotes[$id] = $inline;
                    return '<w:r><w:rPr><w:rStyle w:val="EndnoteReference"/></w:rPr><w:endnoteReference w:id="' . $id . '"/></w:r>';
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

    /** שדה מורכב (begin / instrText / separate / התוצאה השמורה / end), באותו עיצוב כמו הטקסט. */
    private function field(string $instr, string $result, array $marks, array $pa): string
    {
        $ch = fn($t) => '<w:r><w:fldChar w:fldCharType="' . $t . '"/></w:r>';
        return $ch('begin') . '<w:r><w:instrText xml:space="preserve"> ' . xml($instr) . ' </w:instrText></w:r>' . $ch('separate')
            . $this->runs($result, $marks, $pa) . $ch('end');
    }

    /** word/footnotes.xml (או endnotes.xml — end): שני המפרידים שחובה (-1, 0) והערות המסמך. */
    public function footnotesXml(bool $end = false): string
    {
        [$tag, $style, $ref, $list] = $end ? ['endnote', 'Endnote', 'endnoteRef', $this->endnotes] : ['footnote', 'Footnote', 'footnoteRef', $this->footnotes];
        $sep = fn(int $id, string $type, string $t) => '<w:' . $tag . ' w:type="' . $type . '" w:id="' . $id . '"><w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:' . $t . '/></w:r></w:p></w:' . $tag . '>';
        $out = $sep(-1, 'separator', 'separator') . $sep(0, 'continuationSeparator', 'continuationSeparator');
        foreach ($list as $id => $inline) {
            $out .= '<w:' . $tag . ' w:id="' . $id . '"><w:p><w:pPr><w:pStyle w:val="' . $style . 'Text"/><w:bidi/></w:pPr>'
                . '<w:r><w:rPr><w:rStyle w:val="' . $style . 'Reference"/></w:rPr><w:' . $ref . '/></w:r>' . $this->runs(' ', [], []);
            foreach ($inline as $t) $out .= $this->runs($t['text'], $t['marks'] ?? [], []);
            $out .= '</w:p></w:' . $tag . '>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:' . $tag . 's ' . W_NS . '>' . $out . '</w:' . $tag . 's>';
    }

    /** טקסט לריצות: עברית עם <w:rtl/>, ומספרים/אנגלית בלי. */
    private function runs(string $text, array $marks, array $pa): string
    {
        $rpr = $this->rpr($marks, $pa);
        // שינוי עיצוב במעקב: w:rPrChange — האחרון ב-rPr, עם העיצוב הקודם (Word מציג "עוצב" ומאפשר לקבל / לדחות)
        $chg = '';
        foreach ($marks as $m) {
            if (($m['type'] ?? '') !== 'fmt' || $this->prefix !== '') continue;
            $date = preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)($m['attrs']['date'] ?? '')) ? ' w:date="' . $m['attrs']['date'] . '"' : '';
            $chg = '<w:rPrChange w:id="' . (++$this->bookmarkId) . '" w:author="' . xml(mb_substr((string)($m['attrs']['author'] ?? ''), 0, 120)) . '"' . $date . '><w:rPr>'
                . $this->rpr(is_array($m['attrs']['old'] ?? null) ? $m['attrs']['old'] : [], $pa) . '</w:rPr></w:rPrChange>';
        }
        $out = '';
        $parts = preg_split('/([A-Za-z0-9](?:[A-Za-z0-9.,:\/\\\\\-+%_@&#= ]*[A-Za-z0-9%])?)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            $ltr = (bool)preg_match('/^[A-Za-z0-9]/', $p);
            $r = $rpr . ($ltr ? '' : '<w:rtl/>') . $chg;
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
        // מעוגנת לעמוד: שני הצירים יחסית לעמוד (relativeFrom="page"), כמו בעורך
        $pg = $abs && ($a['anchor'] ?? null) === 'page';
        $posH = $abs ? '<wp:positionH relativeFrom="' . ($pg ? 'page' : 'column') . '"><wp:posOffset>' . (int)round((float)($a['x'] ?? 0) * EMU_PER_PX) . '</wp:posOffset></wp:positionH>'
            : '<wp:positionH relativeFrom="column"><wp:align>' . $g['align'] . '</wp:align></wp:positionH>';
        $posV = '<wp:positionV relativeFrom="' . ($pg ? 'page' : 'paragraph') . '"><wp:posOffset>' . ($abs ? (int)round((float)($a['y'] ?? 0) * EMU_PER_PX) : 0) . '</wp:posOffset></wp:positionV>';
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

    /**
     * תוכן עניינים — כמו ש-Word שומר אותו: sdt (Table of Contents) עם שדה TOC, והשורות השמורות כתוצאת השדה:
     * טקסט, טאב עם נקודות לקצה השורה, ומספר העמוד. ב-Word אפשר "לעדכן שדה".
     * רשימת איורים (seq): השדה TOC \c "<תווית>" — הכיתובים עם התווית (באותו sdt, כדי שהייבוא יזהה אותה).
     */
    private function toc(array $a): string
    {
        $levels = max(1, min(9, (int)($a['levels'] ?? 3)));
        $entries = is_array($a['entries'] ?? null) ? $a['entries'] : [];
        $tab = '<w:tabs><w:tab w:val="left" w:leader="dot" w:pos="' . (int)$this->contentTw . '"/></w:tabs>';
        $run = fn(string $t, bool $b = false) => '<w:r><w:rPr>' . ($b ? '<w:b/><w:bCs/>' : '') . '<w:rtl/></w:rPr><w:t xml:space="preserve">' . xml($t) . '</w:t></w:r>';
        $out = '<w:sdt><w:sdtPr><w:docPartObj><w:docPartGallery w:val="Table of Contents"/><w:docPartUnique/></w:docPartObj></w:sdtPr><w:sdtContent>'
            . '<w:p><w:pPr><w:bidi/><w:spacing w:after="120"/></w:pPr>' . str_replace('<w:rtl/>', '<w:rtl/><w:sz w:val="' . (int)round($this->page['font']['size'] * 2.5) . '"/>', $run(toc_title($a), true)) . '</w:p>';
        $begin = '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> ' . (empty($a['seq']) ? 'TOC \o "1-' . $levels . '" \h \z \u' : 'TOC \h \z \c "' . xml(seq_label($a['seq'])) . '"') . ' </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>';
        if (!$entries) $out .= '<w:p><w:pPr><w:bidi/></w:pPr>' . $begin . $run(toc_empty($a)) . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
        foreach ($entries as $k => $e) {
            $l = max(0, min(9, (int)($e['l'] ?? 1)));
            $ind = min(3, max(0, $l - 1)) * 280;
            $out .= '<w:p><w:pPr>' . $tab . '<w:bidi/><w:spacing w:after="0"/>' . ($ind ? '<w:ind w:start="' . $ind . '"/>' : '') . '</w:pPr>'
                . ($k === 0 ? $begin : '') . $run((string)($e['t'] ?? ''), $l === 1) . '<w:r><w:tab/></w:r>' . $run((string)($e['p'] ?? ''))
                . ($k === count($entries) - 1 ? '<w:r><w:fldChar w:fldCharType="end"/></w:r>' : '') . '</w:p>';
        }
        return $out . '</w:sdtContent></w:sdt>';
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
        $names = ['normal' => 'Normal', 'title' => 'Title', 'caption' => 'caption'];
        for ($l = 1; $l <= 9; $l++) $names["h$l"] = "heading $l";
        foreach ($this->page['styles'] as $k => $st) if (preg_match(USER_STYLE_RE, (string)$k)) $names[$k] = style_name((string)($st['name'] ?? $k)) ?: $k;
        foreach ($names as $k => $name) {
            $st = $this->page['styles'][$k] ?? [];
            $sp = '';
            if (isset($st['before'])) $sp .= ' w:before="' . (int)round($st['before'] * 20) . '"';
            if (isset($st['after'])) $sp .= ' w:after="' . (int)round($st['after'] * 20) . '"';
            if (isset($st['line'])) $sp .= ' w:line="' . (int)round($st['line'] * 240) . '" w:lineRule="auto"';
            $user = (bool)preg_match(USER_STYLE_RE, $k);
            $ppr = ($k !== 'normal' && $k !== 'caption' && !$user ? '<w:keepNext/><w:keepLines/>' : '') . '<w:bidi/>' . ($sp ? "<w:spacing$sp/>" : '');
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
    $body = $o->body(track_final(sections_top($json)));   // במסמך של המארחת — הטקסט אחרי קבלת השינויים (בלי טקסט מחוק)
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
    $hf = function (string|array $text, bool $withNumber, string $tag, array $nb) use ($wmXml, $o) {
        $paras = $tag === 'hdr' ? $wmXml : '';
        $jcNum = ['right' => null, 'center' => 'center', 'left' => 'end'][$nb['align']] ?? 'center';
        if (is_array($text)) {
            // עשירה: הפסקאות כמו בגוף (עיצוב, יישור, תמונה)
            $paras .= $o->hfBlocks($text);
        } elseif ($text !== '') {
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
    $evenOdd = !empty($page['even_odd']);
    $hfRefs = function (array $p, bool $first) use (&$files, &$hfRels, &$hfIds, $hf, $evenOdd): string {
        $nb = $p['numbering'];
        // עמוד ראשון שונה (titlePg): במקטע עם first_diff — הכותרות לעמוד הראשון (המספר — כמו בשאר העמודים, חוץ מהעמוד
        // הראשון של המסמך כשהמספור לא מוצג בו); בלי first_diff — רק כדי להסתיר את המספר בעמוד הראשון של המסמך
        $fd = !empty($p['first_diff']);
        $noFirstNum = $first && $nb['show'] && !$nb['first_page'];
        $titlePg = $noFirstNum || $fd;
        $key = md5(json_encode([$p['header'], $p['footer'], $nb['show'], $nb['position'], $nb['align'], $nb['format'], $titlePg,
            $evenOdd ? [$p['header_even'], $p['footer_even']] : null, $fd ? [$p['header_first'], $p['footer_first'], $noFirstNum] : null]));
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
                $files["word/header{$n}f.xml"] = $hf($fd ? $p['header_first'] : $p['header'], $fd && !$noFirstNum && $numTop, 'hdr', $nb);
                $files["word/footer{$n}f.xml"] = $hf($fd ? $p['footer_first'] : $p['footer'], $fd && !$noFirstNum && $numBottom, 'ftr', $nb);
                $hfRels .= '<Relationship Id="rIdH' . $n . 'f" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header' . $n . 'f.xml"/>'
                    . '<Relationship Id="rIdF' . $n . 'f" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer' . $n . 'f.xml"/>';
                $refs .= '<w:headerReference w:type="first" r:id="rIdH' . $n . 'f"/><w:footerReference w:type="first" r:id="rIdF' . $n . 'f"/>';
            }
            // זוגי / אי-זוגי שונים (evenAndOddHeaders ב-settings): כותרות לעמוד זוגי, עם המספור באותו מקום
            if ($evenOdd) {
                $files["word/header{$n}e.xml"] = $hf($p['header_even'], $numTop, 'hdr', $nb);
                $files["word/footer{$n}e.xml"] = $hf($p['footer_even'], $numBottom, 'ftr', $nb);
                $hfRels .= '<Relationship Id="rIdH' . $n . 'e" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header' . $n . 'e.xml"/>'
                    . '<Relationship Id="rIdF' . $n . 'e" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer' . $n . 'e.xml"/>';
                $refs .= '<w:headerReference w:type="even" r:id="rIdH' . $n . 'e"/><w:footerReference w:type="even" r:id="rIdF' . $n . 'e"/>';
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
        $titlePg = ($first && $nb['show'] && !$nb['first_page']) || !empty($p['first_diff']);
        // התחלת המספור: במקטע הראשון — של המסמך; במקטע עם num_restart — מחדש
        $start = $first ? (int)$page['numbering']['start'] : (!empty($p['num_restart']) ? (int)$p['num_start'] : null);
        $pgNum = '<w:pgNumType' . ($nb['format'] === 'hebrew' ? ' w:fmt="hebrew1"' : '') . ($start !== null ? ' w:start="' . $start . '"' : '') . '/>';
        return '<w:sectPr>' . $hfRefs($p, $first) . ($first ? '' : '<w:type w:val="nextPage"/>')
            . '<w:pgSz w:w="' . $tw($wmm) . '" w:h="' . $tw($hmm) . '"' . ($p['orientation'] === 'landscape' ? ' w:orient="landscape"' : '') . '/>'
            . '<w:pgMar w:top="' . $tw($mg['top']) . '" w:right="' . $tw($mg['right']) . '" w:bottom="' . $tw($mg['bottom']) . '" w:left="' . $tw($mg['left'])
            . '" w:header="' . $tw(min(12.5, $mg['top'] / 2)) . '" w:footer="' . $tw(min(12.5, $mg['bottom'] / 2)) . '" w:gutter="0"/>'
            . $pgNum . (($c = $p['columns'] ?? null) && $c['n'] > 1 ? '<w:cols w:num="' . $c['n'] . '" w:space="' . $tw($c['gap']) . '"' . ($c['line'] ? ' w:sep="1"' : '') . '/>' : '')
            . ($titlePg ? '<w:titlePg/>' : '') . '<w:bidi/></w:sectPr>';
    };
    $o->sectFn = $sectXml;
    $body = $o->body(sections_top($json));
    $sectCount = substr_count($body, '<w:sectPr>');
    $sect = $sectXml($o->cur, $sectCount);   // המקטע האחרון — בסוף הגוף
    if ($o->footnotes) $files['word/footnotes.xml'] = $o->footnotesXml();
    if ($o->endnotes) $files['word/endnotes.xml'] = $o->footnotesXml(true);
    if ($o->comments) $files += $o->commentsXml();

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
        . '<w:style w:type="paragraph" w:styleId="CommentText"><w:name w:val="annotation text"/><w:basedOn w:val="Normal"/><w:pPr><w:bidi/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
        . '<w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
        . '<w:style w:type="character" w:styleId="CommentReference"><w:name w:val="annotation reference"/><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="EndnoteText"><w:name w:val="endnote text"/><w:basedOn w:val="Normal"/><w:pPr><w:bidi/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
        . '<w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
        . '<w:style w:type="character" w:styleId="EndnoteReference"><w:name w:val="endnote reference"/><w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>'
        . '</w:styles>';
    $parts = $o->numberingParts();
    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering ' . W_NS . '>' . $parts['abstract'] . $parts['num'] . '</w:numbering>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings ' . W_NS . '>' . ($pc ? '<w:displayBackgroundShape/>' : '')
        . (!empty($json['attrs']['track']) ? '<w:trackRevisions/>' : '') . '<w:defaultTabStop w:val="720"/>'
        . (!empty($page['even_odd']) ? '<w:evenAndOddHeaders/>' : '')
        . '<w:characterSpacingControl w:val="doNotCompress"/>'
        . ($o->footnotes ? '<w:footnotePr><w:footnote w:id="-1"/><w:footnote w:id="0"/></w:footnotePr>' : '')
        . ($o->endnotes ? '<w:endnotePr><w:numFmt w:val="lowerRoman"/><w:endnote w:id="-1"/><w:endnote w:id="0"/></w:endnotePr>' : '') . '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="gif" ContentType="image/gif"/><Default Extension="webp" ContentType="image/webp"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    foreach (array_keys($files) as $p) {
        $kind = str_contains($p, 'commentsExtended') ? 'commentsExtended' : (str_contains($p, 'comments') ? 'comments' : (str_contains($p, 'footnotes') ? 'footnotes' : (str_contains($p, 'endnotes') ? 'endnotes' : (str_contains($p, 'header') ? 'header' : 'footer'))));
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
        . ($o->endnotes ? '<Relationship Id="rIdEn" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/endnotes" Target="endnotes.xml"/>' : '')
        . ($o->comments ? '<Relationship Id="rIdCm" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="comments.xml"/>'
            . '<Relationship Id="rIdCmx" Type="http://schemas.microsoft.com/office/2011/relationships/commentsExtended" Target="commentsExtended.xml"/>' : '')
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
    foreach ($files as $p => $x) {
        $zip->addFromString($p, $x);
        // תמונה בכותרת: לכל חלק כותרת — קובץ קשרים משלו, עם התמונות שהוא מפנה אליהן
        if (preg_match_all('/r:embed="([^"]+)"/', $x, $em) && preg_match('#^word/((header|footer)[^/]+\.xml)$#', $p, $pm)) {
            $rels = '';
            foreach ($o->media as $m) if (in_array($m['rid'], $em[1], true)) {
                $rels .= '<Relationship Id="' . $m['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $m['name'] . '"/>';
            }
            $zip->addFromString('word/_rels/' . $pm[1] . '.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        }
    }
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

require_once __DIR__ . '/export_odt.php';
