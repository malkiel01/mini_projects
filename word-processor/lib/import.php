<?php
/**
 * ייבוא למסמך (embed.md, פרק 3): lines (תכנית העבודה של הצעות תקציב), html (בסיסי) ו-docx (בסיסי:
 * כותרות, עיצוב תווים ופסקאות, רשימות, טבלאות, תמונות, הגדרות עמוד).
 * כל ייבוא מחזיר ['json' => …, 'page' => הגדרות חלקיות או null].
 */

require_once __DIR__ . '/export.php';

function text_node(string $t, array $marks = []): array
{
    $n = ['type' => 'text', 'text' => $t];
    if ($marks) $n['marks'] = $marks;
    return $n;
}

function para(array $inline = [], array $attrs = [], string $type = 'paragraph'): array
{
    $n = ['type' => $type];
    if ($attrs) $n['attrs'] = $attrs;
    if ($inline) $n['content'] = $inline;
    return $n;
}

/**
 * "# " פרק (כותרת 1), "## " כותרת 2, "- " תבליט; כל שורה אחרת — פסקה. שורה ריקה מפרידה (ולא נשמרת),
 * כמו שתכנית העבודה מוצגת היום בהצעות תקציב (org-budget-proposals/lib/export.php, plan_blocks).
 */
function import_lines(string $text): array
{
    $out = [];
    $list = null;
    $flush = function () use (&$out, &$list) {
        if ($list) $out[] = ['type' => 'bulletList', 'content' => $list];
        $list = null;
    };
    foreach (preg_split('/\R/u', $text) as $raw) {
        $t = trim($raw);
        if ($t === '') { $flush(); continue; }
        if (str_starts_with($t, '- ')) {
            $list[] = ['type' => 'listItem', 'content' => [para([text_node(trim(mb_substr($t, 2)))])]];
            continue;
        }
        $flush();
        if (str_starts_with($t, '## ')) $out[] = para([text_node(trim(mb_substr($t, 3)))], ['level' => 2], 'heading');
        elseif (str_starts_with($t, '# ')) $out[] = para([text_node(trim(mb_substr($t, 2)))], ['level' => 1], 'heading');
        else $out[] = para([text_node($t)]);
    }
    $flush();
    return ['json' => ['type' => 'doc', 'content' => $out ?: [para()]], 'page' => null];
}

// ======================================================================
// HTML (בסיסי)
// ======================================================================

function import_html(string $html, string $docId): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR);
    libxml_clear_errors();
    $body = $dom->getElementsByTagName('body')->item(0);
    $blocks = $body ? html_blocks($body) : [];
    return ['json' => ['type' => 'doc', 'content' => $blocks ?: [para()]], 'page' => null];
}

function html_blocks(DOMNode $parent): array
{
    $out = [];
    $inline = [];
    $flush = function () use (&$out, &$inline) {
        $inline = trim_inline($inline);
        if ($inline) $out[] = para($inline);
        $inline = [];
    };
    foreach ($parent->childNodes as $c) {
        $tag = $c instanceof DOMElement ? strtolower($c->tagName) : '';
        if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'section', 'article', 'hr'], true)) {
            $flush();
            if ($tag === 'ul' || $tag === 'ol') $out[] = html_list($c);
            elseif ($tag === 'table') $out[] = html_table($c);
            elseif ($tag === 'hr') $out[] = ['type' => 'horizontalRule'];
            elseif (in_array($tag, ['div', 'blockquote', 'section', 'article'], true) && html_has_blocks($c)) array_push($out, ...html_blocks($c));
            else {
                $attrs = html_para_attrs($c);
                $kids = trim_inline(html_inline($c, []));
                if ($tag[0] === 'h' && $tag !== 'hr') $out[] = para($kids, $attrs + ['level' => min(3, (int)$tag[1])], 'heading');
                else $out[] = para($kids, $attrs);
            }
        } else {
            array_push($inline, ...html_inline($c, [], true));
        }
    }
    $flush();
    return $out;
}

function html_has_blocks(DOMNode $n): bool
{
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['p', 'div', 'h1', 'h2', 'h3', 'ul', 'ol', 'table'], true)) return true;
    }
    return false;
}

function html_para_attrs(DOMElement $e): array
{
    $a = [];
    $style = css_props($e->getAttribute('style'));
    $align = $style['text-align'] ?? $e->getAttribute('align');
    if (in_array($align, ['right', 'center', 'left', 'justify'], true)) $a['textAlign'] = $align;
    if (in_array($e->getAttribute('dir'), ['rtl', 'ltr'], true)) $a['dir'] = $e->getAttribute('dir');
    return $a;
}

function css_props(string $s): array
{
    $out = [];
    foreach (explode(';', $s) as $d) {
        [$k, $v] = array_pad(explode(':', $d, 2), 2, '');
        if (trim($k) !== '') $out[strtolower(trim($k))] = trim($v);
    }
    return $out;
}

/** צמתים פנימיים (טקסט + סימונים). $loose — טקסט ישירות בגוף. */
function html_inline(DOMNode $n, array $marks, bool $loose = false): array
{
    if ($n instanceof DOMText) {
        $t = preg_replace('/\s+/u', ' ', $n->textContent);
        return $t === '' ? [] : [text_node($t, $marks)];
    }
    if (!$n instanceof DOMElement) return [];
    $tag = strtolower($n->tagName);
    if ($tag === 'br') return [['type' => 'hardBreak']];
    if (in_array($tag, ['script', 'style', 'img'], true)) return [];
    $m = $marks;
    $add = function ($t, $attrs = null) use (&$m) { $m[] = $attrs ? ['type' => $t, 'attrs' => $attrs] : ['type' => $t]; };
    if (in_array($tag, ['b', 'strong'], true)) $add('bold');
    if (in_array($tag, ['i', 'em'], true)) $add('italic');
    if ($tag === 'u') $add('underline');
    if (in_array($tag, ['s', 'strike', 'del'], true)) $add('strike');
    if ($tag === 'sub') $add('subscript');
    if ($tag === 'sup') $add('superscript');
    $style = css_props($n->getAttribute('style'));
    $ts = [];
    if (($c = color_val($style['color'] ?? null))) $ts['color'] = '#' . strtolower($c);
    if (($z = pt_val($style['font-size'] ?? null))) $ts['fontSize'] = round($z) . 'pt';
    if (!empty($style['font-family'])) $ts['fontFamily'] = font_name(trim(explode(',', $style['font-family'])[0], " '\""));
    if ($ts) $add('textStyle', $ts);
    if (($style['font-weight'] ?? '') === 'bold' || (int)($style['font-weight'] ?? 0) >= 600) $add('bold');
    $out = [];
    foreach ($n->childNodes as $c) array_push($out, ...html_inline($c, $m));
    return $out;
}

function trim_inline(array $inline): array
{
    while ($inline && ($inline[0]['type'] ?? '') === 'text' && trim($inline[0]['text']) === '') array_shift($inline);
    while ($inline && ($inline[count($inline) - 1]['type'] ?? '') === 'text' && trim($inline[count($inline) - 1]['text']) === '') array_pop($inline);
    if ($inline && ($inline[0]['type'] ?? '') === 'text') $inline[0]['text'] = ltrim($inline[0]['text']);
    $last = count($inline) - 1;
    if ($inline && ($inline[$last]['type'] ?? '') === 'text') $inline[$last]['text'] = rtrim($inline[$last]['text']);
    return array_values(array_filter($inline, fn($x) => ($x['type'] ?? '') !== 'text' || $x['text'] !== ''));
}

function html_list(DOMElement $e): array
{
    $ordered = strtolower($e->tagName) === 'ol';
    $items = [];
    foreach ($e->childNodes as $li) {
        if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') continue;
        $content = html_blocks($li);
        $items[] = ['type' => 'listItem', 'content' => $content ?: [para()]];
    }
    $list = ['type' => $ordered ? 'orderedList' : 'bulletList', 'content' => $items ?: [['type' => 'listItem', 'content' => [para()]]]];
    if ($ordered) {
        $type = strtolower($e->getAttribute('type'));
        $list['attrs'] = ['start' => max(1, (int)($e->getAttribute('start') ?: 1)), 'listType' => $type === 'i' ? 'roman' : 'decimal'];
    }
    return $list;
}

function html_table(DOMElement $t): array
{
    $rows = [];
    foreach ((new DOMXPath($t->ownerDocument))->query('.//tr', $t) as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            if (!$td instanceof DOMElement || !in_array(strtolower($td->tagName), ['td', 'th'], true)) continue;
            $attrs = ['colspan' => max(1, (int)($td->getAttribute('colspan') ?: 1)), 'rowspan' => max(1, (int)($td->getAttribute('rowspan') ?: 1))];
            $cells[] = ['type' => strtolower($td->tagName) === 'th' ? 'tableHeader' : 'tableCell', 'attrs' => $attrs, 'content' => html_blocks($td) ?: [para()]];
        }
        if ($cells) $rows[] = ['type' => 'tableRow', 'content' => $cells];
    }
    return $rows ? ['type' => 'table', 'content' => $rows] : para();
}

// ======================================================================
// docx (בסיסי)
// ======================================================================

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function import_docx(string $bytes, string $docId): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'wpimp');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { unlink($tmp); throw new ApiError('הקובץ אינו docx תקין', 'bad_file'); }
    $read = function (string $name) use ($zip) {
        $s = $zip->getFromName($name);
        return $s === false ? null : $s;
    };
    $docXml = $read('word/document.xml');
    if ($docXml === null) { $zip->close(); unlink($tmp); throw new ApiError('הקובץ אינו docx תקין (אין word/document.xml)', 'bad_file'); }
    $ctx = new DocxCtx($read, $docId);
    $dom = xml_dom($docXml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W);
    $body = $xp->query('/w:document/w:body')->item(0);
    $blocks = $body ? $ctx->blocks($body) : [];
    // מקטעים: ב-Word כל מקטע מסתיים בפסקה עם sectPr (ההגדרות שלו), והאחרון — ב-sectPr של הגוף. אצלנו: הגדרות
    // המסמך = המקטע הראשון, וכל מעבר מקטע נושא את ההגדרות של המקטע שאחריו
    $sects = [];
    foreach ($blocks as $b) if (($b['type'] ?? '') === 'sectionBreak') $sects[] = $b['_sect'];
    $sects[] = $xp->query('/w:document/w:body/w:sectPr')->item(0);
    $page = $ctx->section($sects[0]);
    $k = 0;
    foreach ($blocks as &$b) {
        if (($b['type'] ?? '') !== 'sectionBreak') continue;
        unset($b['_sect']);
        $b['attrs'] = ['page' => $ctx->section($sects[++$k]) ?: null];
    }
    unset($b);
    $zip->close();
    unlink($tmp);
    return ['json' => ['type' => 'doc', 'content' => $blocks ?: [para()]], 'page' => $page];
}

function xml_dom(string $xml): DOMDocument
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) throw new ApiError('קובץ ה-docx פגום', 'bad_file');
    return $dom;
}

final class DocxCtx
{
    private array $styles = [];    // styleId => 'title'|'h1'..
    private array $numFmt = [];    // numId => [ilvl => fmt]
    private array $rels = [];      // rId => target
    private array $footnotes = []; // id => טקסט ההערה (word/footnotes.xml)
    private $read;

    public function __construct(callable $read, private string $docId)
    {
        $this->read = $read;
        if ($s = $read('word/styles.xml')) {
            $xp = $this->xp(xml_dom($s));
            foreach ($xp->query('//w:style[@w:type="paragraph"]') as $st) {
                $name = strtolower((string)$xp->evaluate('string(w:name/@w:val)', $st));
                $id = $st->getAttributeNS(W, 'styleId');
                if ($name === 'title') $this->styles[$id] = 'title';
                elseif (preg_match('/^heading ([1-9])$/', $name, $m)) $this->styles[$id] = 'h' . min(3, (int)$m[1]);
            }
        }
        if ($s = $read('word/numbering.xml')) {
            $xp = $this->xp(xml_dom($s));
            $abs = [];
            foreach ($xp->query('//w:abstractNum') as $a) {
                foreach ($xp->query('w:lvl', $a) as $l) {
                    $abs[$a->getAttributeNS(W, 'abstractNumId')][(int)$l->getAttributeNS(W, 'ilvl')] = (string)$xp->evaluate('string(w:numFmt/@w:val)', $l);
                }
            }
            foreach ($xp->query('//w:num') as $n) {
                $this->numFmt[$n->getAttributeNS(W, 'numId')] = $abs[(string)$xp->evaluate('string(w:abstractNumId/@w:val)', $n)] ?? [];
            }
        }
        if ($s = $read('word/footnotes.xml')) {
            $xp = $this->xp(xml_dom($s));
            foreach ($xp->query('//w:footnote') as $f) {
                $text = '';
                foreach ($xp->query('.//w:t', $f) as $t) $text .= $t->textContent;
                $this->footnotes[$f->getAttributeNS(W, 'id')] = trim($text);
            }
        }
        if ($s = $read('word/_rels/document.xml.rels')) {
            $d = xml_dom($s);
            foreach ($d->getElementsByTagName('Relationship') as $r) $this->rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
    }

    private function xp(DOMDocument $d): DOMXPath
    {
        $xp = new DOMXPath($d);
        $xp->registerNamespace('w', W);
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        return $xp;
    }

    /** בלוקים מגוף / תא. פסקאות רשימה רצופות → רשימה (מקוננת לפי ilvl). */
    public function blocks(DOMNode $parent): array
    {
        $xp = $this->xp($parent->ownerDocument);
        $out = [];
        $listRun = [];
        $flush = function () use (&$out, &$listRun) {
            if ($listRun) $out[] = $this->buildList($listRun, 0);
            $listRun = [];
        };
        foreach ($parent->childNodes as $c) {
            if (!$c instanceof DOMElement || $c->namespaceURI !== W) continue;
            if ($c->localName === 'p') {
                [$block, $num, $breakAfter] = $this->para($c, $xp);
                $sect = $xp->query('w:pPr/w:sectPr', $c)->item(0);
                // פסקה ריקה שכל תפקידה לסיים מקטע — לא נכנסת למסמך
                $empty = $sect && !($block['content'] ?? []);
                if ($num) { $listRun[] = ['p' => $block, 'num' => $num]; }
                elseif (!$empty) { $flush(); $out[] = $block; }
                if ($breakAfter) { $flush(); $out[] = ['type' => 'pageBreak']; }
                if ($sect) { $flush(); $out[] = ['type' => 'sectionBreak', '_sect' => $sect]; }
            } elseif ($c->localName === 'tbl') {
                $flush();
                $out[] = $this->table($c, $xp);
            } elseif ($c->localName === 'sdt') {
                $flush();
                foreach ($xp->query('w:sdtContent', $c) as $sc) array_push($out, ...$this->blocks($sc));
            }
        }
        $flush();
        return $out;
    }

    private function buildList(array $items, int $lvl): array
    {
        $fmt = $items[0]['num']['fmt'];
        $type = $fmt === 'bullet' ? 'bulletList' : 'orderedList';
        $list = ['type' => $type, 'content' => []];
        if ($type === 'orderedList') $list['attrs'] = ['start' => 1, 'listType' => in_array($fmt, ['hebrew1', 'hebrew2'], true) ? 'hebrew' : (in_array($fmt, ['lowerRoman', 'upperRoman'], true) ? 'roman' : 'decimal')];
        $i = 0;
        while ($i < count($items)) {
            $li = ['type' => 'listItem', 'content' => [$items[$i]['p']]];
            $i++;
            $sub = [];
            while ($i < count($items) && $items[$i]['num']['lvl'] > $lvl) $sub[] = $items[$i++];
            if ($sub) $li['content'][] = $this->buildList($sub, $lvl + 1);
            $list['content'][] = $li;
        }
        return $list;
    }

    /** [בלוק, מספור|null, שבירת עמוד אחריו]. */
    private function para(DOMElement $p, DOMXPath $xp): array
    {
        $attrs = [];
        $type = 'paragraph';
        $style = (string)$xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
        $kind = $this->styles[$style] ?? null;
        if ($kind === 'title') $attrs['pstyle'] = 'title';
        elseif ($kind) { $type = 'heading'; $attrs['level'] = (int)$kind[1]; }
        $bidi = $xp->query('w:pPr/w:bidi', $p)->length > 0 && (string)$xp->evaluate('string(w:pPr/w:bidi/@w:val)', $p) !== '0';
        $jc = (string)$xp->evaluate('string(w:pPr/w:jc/@w:val)', $p);
        $align = match ($jc) {
            'center' => 'center', 'both', 'distribute' => 'justify',
            'start' => $bidi ? 'right' : 'left', 'end' => $bidi ? 'left' : 'right',
            'left' => 'left', 'right' => 'right', default => null,
        };
        if ($align) $attrs['textAlign'] = $align;
        if (!$bidi && $this->hasLatinOnly($p)) $attrs['dir'] = 'ltr';
        $sp = $xp->query('w:pPr/w:spacing', $p)->item(0);
        if ($sp instanceof DOMElement) {
            if ($sp->hasAttributeNS(W, 'before')) $attrs['spaceBefore'] = round((int)$sp->getAttributeNS(W, 'before') / 20, 1);
            if ($sp->hasAttributeNS(W, 'after')) $attrs['spaceAfter'] = round((int)$sp->getAttributeNS(W, 'after') / 20, 1);
            if ($sp->hasAttributeNS(W, 'line')) {
                $line = (int)$sp->getAttributeNS(W, 'line');
                $rule = $sp->getAttributeNS(W, 'lineRule') ?: 'auto';
                $attrs['lineHeight'] = $rule === 'auto' ? (string)round($line / 240, 2) : 'exact:' . round($line / 20, 1);
            }
        }
        $num = null;
        $numId = (string)$xp->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $p);
        if ($numId !== '' && $numId !== '0') {
            $lvl = (int)$xp->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $p);
            $num = ['lvl' => $lvl, 'fmt' => $this->numFmt[$numId][$lvl] ?? 'bullet'];
        }
        $inline = [];
        $breakAfter = false;
        foreach ($xp->query('w:r|w:hyperlink/w:r|w:ins/w:r|w:smartTag/w:r', $p) as $r) {
            $marks = $this->runMarks($r, $xp);
            foreach ($r->childNodes as $k) {
                if (!$k instanceof DOMElement) continue;
                switch ($k->localName) {
                    case 't': if ($k->textContent !== '') $inline[] = text_node($k->textContent, $marks); break;
                    case 'tab': $inline[] = text_node("\t", $marks); break;
                    case 'br':
                        if ($k->getAttributeNS(W, 'type') === 'page') $breakAfter = true;
                        else $inline[] = ['type' => 'hardBreak'];
                        break;
                    case 'drawing':
                        if ($img = $this->image($k)) $inline[] = $img;
                        break;
                    case 'footnoteReference':
                        $inline[] = ['type' => 'footnote', 'attrs' => ['text' => $this->footnotes[$k->getAttributeNS(W, 'id')] ?? '']];
                        break;
                }
            }
        }
        return [para($this->mergeText($inline), $attrs, $type), $num, $breakAfter];
    }

    private function hasLatinOnly(DOMElement $p): bool
    {
        $t = $p->textContent;
        return $t !== '' && !preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}]/u', $t) && preg_match('/[A-Za-z]/', $t);
    }

    private function mergeText(array $inline): array
    {
        $out = [];
        foreach ($inline as $n) {
            $last = count($out) - 1;
            if ($last >= 0 && ($n['type'] ?? '') === 'text' && ($out[$last]['type'] ?? '') === 'text' && ($n['marks'] ?? []) == ($out[$last]['marks'] ?? [])) {
                $out[$last]['text'] .= $n['text'];
            } else {
                $out[] = $n;
            }
        }
        return $out;
    }

    private function runMarks(DOMElement $r, DOMXPath $xp): array
    {
        $rpr = $xp->query('w:rPr', $r)->item(0);
        if (!$rpr) return [];
        $on = function (string $tag) use ($xp, $rpr) {
            $e = $xp->query("w:$tag", $rpr)->item(0);
            if (!$e instanceof DOMElement) return false;
            $v = $e->getAttributeNS(W, 'val');
            return !in_array($v, ['0', 'false', 'none'], true);
        };
        $m = [];
        if ($on('b') || $on('bCs')) $m[] = ['type' => 'bold'];
        if ($on('i') || $on('iCs')) $m[] = ['type' => 'italic'];
        if ($on('u')) $m[] = ['type' => 'underline'];
        if ($on('strike') || $on('dstrike')) $m[] = ['type' => 'strike'];
        $va = (string)$xp->evaluate('string(w:vertAlign/@w:val)', $rpr);
        if ($va === 'superscript') $m[] = ['type' => 'superscript'];
        if ($va === 'subscript') $m[] = ['type' => 'subscript'];
        $ts = [];
        $color = (string)$xp->evaluate('string(w:color/@w:val)', $rpr);
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $color) && strtoupper($color) !== '000000') $ts['color'] = '#' . strtolower($color);
        $sz = (string)$xp->evaluate('string(w:szCs/@w:val)', $rpr) ?: (string)$xp->evaluate('string(w:sz/@w:val)', $rpr);
        if (is_numeric($sz)) $ts['fontSize'] = ((float)$sz / 2) . 'pt';
        $font = (string)$xp->evaluate('string(w:rFonts/@w:cs)', $rpr) ?: (string)$xp->evaluate('string(w:rFonts/@w:ascii)', $rpr);
        if ($font !== '') $ts['fontFamily'] = font_name($font);
        $sp = (string)$xp->evaluate('string(w:spacing/@w:val)', $rpr);
        if (is_numeric($sp) && (int)$sp !== 0) $ts['letterSpacing'] = round((int)$sp / 20, 1) . 'pt';
        if ($ts) $m[] = ['type' => 'textStyle', 'attrs' => $ts];
        $hl = (string)$xp->evaluate('string(w:highlight/@w:val)', $rpr);
        $fill = (string)$xp->evaluate('string(w:shd/@w:fill)', $rpr);
        $named = ['yellow' => '#ffff00', 'green' => '#00ff00', 'cyan' => '#00ffff', 'magenta' => '#ff00ff', 'blue' => '#0000ff', 'red' => '#ff0000',
            'darkBlue' => '#000080', 'darkCyan' => '#008080', 'darkGreen' => '#008000', 'darkMagenta' => '#800080', 'darkRed' => '#800000',
            'darkYellow' => '#808000', 'darkGray' => '#808080', 'lightGray' => '#c0c0c0', 'black' => '#000000'];
        if (isset($named[$hl])) $m[] = ['type' => 'highlight', 'attrs' => ['color' => $named[$hl]]];
        elseif (preg_match('/^[0-9A-Fa-f]{6}$/', $fill) && strtoupper($fill) !== 'FFFFFF') $m[] = ['type' => 'highlight', 'attrs' => ['color' => '#' . strtolower($fill)]];
        return $m;
    }

    private function image(DOMElement $drawing): ?array
    {
        $xp = $this->xp($drawing->ownerDocument);
        $rid = (string)$xp->evaluate('string(.//a:blip/@r:embed)', $drawing);
        $target = $this->rels[$rid] ?? '';
        if ($target === '' || str_contains($target, '..')) return null;
        $bytes = ($this->read)('word/' . ltrim($target, '/'));
        if ($bytes === null) return null;
        try {
            $m = media_store($this->docId, $bytes);
        } catch (ApiError $e) {
            return null;
        }
        $cx = (int)$xp->evaluate('string(.//wp:extent/@cx)', $drawing);
        $anchor = $xp->query('.//wp:anchor', $drawing)->item(0);
        $wrap = 'inline';
        if ($anchor instanceof DOMElement) {
            $wrap = match (true) {
                $xp->query('wp:wrapSquare', $anchor)->length > 0 => 'square',
                $xp->query('wp:wrapTight|wp:wrapThrough', $anchor)->length > 0 => 'tight',
                $xp->query('wp:wrapTopAndBottom', $anchor)->length > 0 => 'topBottom',
                default => $anchor->getAttribute('behindDoc') === '1' ? 'behind' : 'front',
            };
        }
        $align = (string)$xp->evaluate('string(.//wp:positionH/wp:align)', $drawing);
        // חיתוך: srcRect באלפיות האחוז; extent הוא החלק הנראה — מחשבים את גודל התמונה המלאה
        $crop = [];
        foreach (['l', 't', 'r', 'b'] as $k) {
            $v = (string)$xp->evaluate("string(.//a:srcRect/@$k)", $drawing);
            if (is_numeric($v) && (int)$v > 0) $crop[$k] = min(45, round((int)$v / 1000, 1));
        }
        $visW = $cx > 0 ? $cx / EMU_PER_PX_IMPORT : $m['width'];
        $w = (int)round($visW / max(0.1, 1 - (($crop['l'] ?? 0) + ($crop['r'] ?? 0)) / 100));
        $h = $m['width'] ? (int)round($w * $m['height'] / $m['width']) : $m['height'];
        $rot = (int)round(((int)$xp->evaluate('string(.//a:xfrm/@rot)', $drawing)) / 60000 / 90) * 90 % 360;
        $attrs = ['media' => $m['id'], 'width' => $w, 'height' => $h, 'wrap' => $wrap,
            'align' => in_array($align, ['left', 'right', 'center'], true) ? $align : 'right'];
        if ($crop) $attrs['crop'] = ['t' => $crop['t'] ?? 0, 'r' => $crop['r'] ?? 0, 'b' => $crop['b'] ?? 0, 'l' => $crop['l'] ?? 0];
        if ($rot) $attrs['rotate'] = $rot;
        if ($anchor instanceof DOMElement) {
            $d = $wrap === 'topBottom' ? $anchor->getAttribute('distT') : $anchor->getAttribute('distL');
            if (is_numeric($d)) $attrs['dist'] = round((int)$d / 12700, 1);
            if (in_array($wrap, ['behind', 'front'], true)) {
                $attrs['x'] = (int)round(((int)$xp->evaluate('string(wp:positionH/wp:posOffset)', $anchor)) / EMU_PER_PX_IMPORT);
                $attrs['y'] = (int)round(((int)$xp->evaluate('string(wp:positionV/wp:posOffset)', $anchor)) / EMU_PER_PX_IMPORT);
            }
        }
        return ['type' => 'image', 'attrs' => $attrs];
    }

    private function table(DOMElement $tbl, DOMXPath $xp): array
    {
        $rows = [];
        $grid = [];
        foreach ($xp->query('w:gridCol', $xp->query('w:tblGrid', $tbl)->item(0) ?: $tbl) as $g) $grid[] = (int)$g->getAttributeNS(W, 'w');
        $restart = [];   // col => [rowIndex, cellIndex] של התא שמתחיל מיזוג אנכי
        foreach ($xp->query('w:tr', $tbl) as $ri => $tr) {
            $cells = [];
            $col = 0;
            foreach ($xp->query('w:tc', $tr) as $tc) {
                $span = max(1, (int)$xp->evaluate('string(w:tcPr/w:gridSpan/@w:val)', $tc));
                $vm = $xp->query('w:tcPr/w:vMerge', $tc)->item(0);
                if ($vm instanceof DOMElement && $vm->getAttributeNS(W, 'val') !== 'restart') {
                    if (isset($restart[$col])) {
                        [$r0, $c0] = $restart[$col];
                        $rows[$r0]['content'][$c0]['attrs']['rowspan']++;
                    }
                    $col += $span;
                    continue;
                }
                $attrs = ['colspan' => $span, 'rowspan' => 1];
                $w = array_sum(array_slice($grid, $col, $span));
                if ($w > 0 && $span === 1) $attrs['colwidth'] = [(int)round($w / 15)];
                $fill = (string)$xp->evaluate('string(w:tcPr/w:shd/@w:fill)', $tc);
                if (preg_match('/^[0-9A-Fa-f]{6}$/', $fill) && strtoupper($fill) !== 'FFFFFF') $attrs['background'] = '#' . strtolower($fill);
                $content = array_values(array_filter($this->blocks($tc), fn($b) => in_array($b['type'], ['paragraph', 'heading', 'bulletList', 'orderedList'], true)));
                $cells[] = ['type' => 'tableCell', 'attrs' => $attrs, 'content' => $content ?: [para()]];
                if ($vm instanceof DOMElement) $restart[$col] = [$ri, count($cells) - 1];
                else unset($restart[$col]);
                $col += $span;
            }
            $rows[$ri] = ['type' => 'tableRow', 'content' => $cells ?: [['type' => 'tableCell', 'content' => [para()]]]];
        }
        return $rows ? ['type' => 'table', 'content' => array_values($rows)] : para();
    }

    /** גודל עמוד ושוליים מ-sectPr → page חלקי. */
    public function section(?DOMNode $sect): ?array
    {
        if (!$sect instanceof DOMElement) return null;
        $xp = $this->xp($sect->ownerDocument);
        $w = (int)$xp->evaluate('string(w:pgSz/@w:w)', $sect);
        $h = (int)$xp->evaluate('string(w:pgSz/@w:h)', $sect);
        $p = [];
        if ($w && $h) {
            $wmm = min($w, $h) / TWIP_PER_MM_IMPORT;
            $p['size'] = abs($wmm - 215.9) < 3 ? 'Letter' : 'A4';
            $p['orientation'] = $w > $h ? 'landscape' : 'portrait';
        }
        foreach (['top', 'bottom', 'right', 'left'] as $k) {
            $v = (string)$xp->evaluate("string(w:pgMar/@w:$k)", $sect);
            if (is_numeric($v)) $p['margins'][$k] = round((int)$v / TWIP_PER_MM_IMPORT, 1);
        }
        return $p ?: null;
    }
}

const EMU_PER_PX_IMPORT = 9525;
const TWIP_PER_MM_IMPORT = 56.6929;
