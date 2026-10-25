<?php
/**
 * ייבוא למסמך (embed.md, פרק 3): lines (תכנית העבודה של הצעות תקציב), html (בסיסי) ו-docx (בסיסי:
 * כותרות, עיצוב תווים ופסקאות, רשימות, טבלאות, תמונות, הגדרות עמוד).
 * כל ייבוא מחזיר ['json' => …, 'page' => הגדרות חלקיות או null].
 */

require_once __DIR__ . '/export.php';
require_once __DIR__ . '/pdf/fontset.php';   // FontSet::FAMILIES — אילו גופנים זמינים (לדוח הייבוא)

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
                if ($tag[0] === 'h' && $tag !== 'hr') $out[] = para($kids, $attrs + ['level' => min(9, (int)$tag[1])], 'heading');
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
    if (($z = pt_val($style['font-size'] ?? null))) $ts['fontSize'] = (round($z * 2) / 2) . 'pt';   // חצאי נקודה (13.5), כמו ב-Word
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

/**
 * שכבות של תמונות מאחורי / לפני הטקסט: ב-Word relativeHeight (גבוה — מעל). אצלנו z — רק כשהסדר שונה מהסדר במסמך
 * (בסדר הרגיל z נשאר 0). _rh נמחק תמיד.
 */
function import_layers(array &$blocks): void
{
    $refs = ['behind' => [], 'front' => []];
    $walk = function (array &$nodes) use (&$walk, &$refs) {
        foreach ($nodes as &$n) {
            if (($n['type'] ?? '') === 'image' && isset($n['attrs']['_rh'])) $refs[$n['attrs']['wrap'] ?? 'front'][] = &$n['attrs'];
            if (is_array($n['content'] ?? null)) $walk($n['content']);
        }
    };
    $walk($blocks);
    foreach ($refs as $list) {
        $rh = array_map(fn($a) => $a['_rh'], $list);
        $sorted = $rh;
        sort($sorted);
        $inOrder = $rh === $sorted;
        foreach ($list as $i => &$a) {
            if (!$inOrder) $a['z'] = min(50, (int)array_search($rh[$i], $sorted, true));   // הדרגה (שווים — אותה דרגה)
            unset($a['_rh']);
        }
        unset($a);
    }
}

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
    if ($ctx->userStyles) $page = ($page ?? []) + ['styles' => $ctx->userStyles];   // סגנונות מותאמים מ-Word
    if ($ctx->headingNumbers) $page = ($page ?? []) + ['heading_numbers' => true];
    // רקע הדף: צבע עמוד (w:background), וסימן מים שנמצא בכותרות (hfPart)
    $bg = strtolower((string)$xp->evaluate('string(/w:document/w:background/@w:color)'));
    if (preg_match('/^[0-9a-f]{6}$/', $bg) && $bg !== 'ffffff') $page = ($page ?? []) + ['page_color' => '#' . $bg];
    if ($ctx->watermark !== '') $page = ($page ?? []) + ['watermark' => $ctx->watermark];
    import_layers($blocks);
    $k = 0;
    foreach ($blocks as &$b) {
        if (($b['type'] ?? '') !== 'sectionBreak') continue;
        unset($b['_sect']);
        $b['attrs'] = ['page' => $ctx->section($sects[++$k]) ?: null];
    }
    unset($b);
    $json = ['type' => 'doc', 'content' => $blocks ?: [para()]];
    // זוגי / אי-זוגי שונים — הגדרה של כל המסמך ב-Word (settings.xml); אצלנו בהגדרות המסמך
    $settings = $read('word/settings.xml') ?? '';
    if (preg_match('/<w:evenAndOddHeaders(?![^>]*w:val="(false|0)")/', $settings)) $page = ($page ?? []) + ['even_odd' => true];
    elseif (isset($page['even_odd'])) unset($page['even_odd']);
    // מעקב שינויים פועל במסמך (settings.xml) — פועל גם אצלנו
    if (($st = $read('word/settings.xml')) && str_contains($st, 'trackRevisions') && !preg_match('/<w:trackRevisions[^>]*w:val="(false|0)"/', $st)) $json['attrs']['track'] = true;
    $zip->close();
    unlink($tmp);
    // הערות: רק שרשורים שהטווח שלהם נכנס למסמך
    $used = [];
    array_walk_recursive($blocks, function ($v, $k) use (&$used) { if ($k === 'id' && is_string($v) && str_starts_with($v, 'c')) $used[$v] = true; });
    if ($cm = array_intersect_key($ctx->comments, $used)) $json['attrs']['comments'] = $cm;
    return ['json' => $json, 'page' => $page, 'report' => $ctx->report()];
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
    private array $numInfo = [];   // numId => ['start' => [ilvl => n], 'multi' => bool]
    private array $numSeen = [];   // numId => מספר הפריט הבא — רשימה נוספת עם אותו numId ממשיכה (Word)
    public bool $headingNumbers = false;   // סגנון כותרת מקושר למספור
    private array $rels = [];      // rId => target
    private array $paraIds = [];   // מזהי פסקה שכבר נקלטו — מזהה כפול (פסקה שהועתקה ב-Word עם הסימנייה) רק לראשונה
    public array $comments = [];   // השרשורים (attrs.comments של המסמך): "c<id ב-Word>" => {author, date, text, done, replies}
    private array $cmTop = [];     // id ב-Word של הערה => המזהה של השרשור שלה (תשובה — של ההורה)
    private array $cmOpen = [];    // הטווחים הפתוחים עכשיו (commentRangeStart עד End) — נמשכים בין פסקאות
    private array $footnotes = []; // id => attrs של ההערה: text, content (word/footnotes.xml)
    private array $custom = [];    // styleId => true — סגנון תו מותאם שיש בו עיצוב (העיצוב שלו לא מיובא)
    public array $userStyles = []; // u<N> => הגדרות הסגנון (name, font, size, bold…) — סגנונות פסקה מותאמים מ-Word
    private array $seen = [];      // מפתח => true — מה שנספר פעם אחת בלבד (סגנון, גופן)
    private array $notes = [];     // דוח הייבוא: מפתח => מספר מופעים
    private array $fonts = [];     // שמות גופנים שאינם זמינים → מוצגים ב-Heebo
    private ?int $contentTw = null;
    public string $watermark = '';  // סימן מים מהכותרת העליונה (צורת WordArt של Word) // רוחב השורה (twip) לפי המקטע האחרון — לרוחב טבלה באחוזים
    private $read;

    public function __construct(callable $read, private string $docId)
    {
        $this->read = $read;
        if ($s = $read('word/styles.xml')) {
            $xp = $this->xp(xml_dom($s));
            foreach ($xp->query('//w:style[@w:type="paragraph"]') as $st) {
                $name = strtolower((string)$xp->evaluate('string(w:name/@w:val)', $st));
                $id = $st->getAttributeNS(W, 'styleId');
                if ($name === 'title' || $name === 'caption') $this->styles[$id] = $name;
                elseif (preg_match('/^heading ([1-9])$/', $name, $m)) {
                    $this->styles[$id] = 'h' . (int)$m[1];
                    $nid = (string)$xp->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $st);
                    if ($nid !== '' && $nid !== '0') $this->headingNumbers = true;   // מספור כותרות (מקושר לסגנון)
                }
                elseif ($st->getAttributeNS(W, 'default') !== '1' && $xp->query('w:rPr/*|w:pPr/*[not(self::w:keepNext or self::w:keepLines or self::w:outlineLvl)]', $st)->length
                    && count($this->userStyles) < USER_STYLES_MAX) {
                    // סגנון פסקה מותאם — נכנס כסגנון של המשתמש (u<N>) עם השם והעיצוב שלו
                    $key = 'u' . (count($this->userStyles) + 1);
                    $this->styles[$id] = $key;
                    $this->userStyles[$key] = ['name' => style_name((string)$xp->evaluate('string(w:name/@w:val)', $st)) ?: $id] + $this->styleProps($st, $xp);
                }
            }
            // סגנון תו מותאם עם עיצוב (חוץ מ-Hyperlink והערות שוליים) — העיצוב שלו לא נכנס: לדוח
            foreach ($xp->query('//w:style[@w:type="character"][w:rPr/*]') as $st) {
                $id = $st->getAttributeNS(W, 'styleId');
                if (!preg_match('/hyperlink|footnote|endnote|default/i', $id . ' ' . $xp->evaluate('string(w:name/@w:val)', $st))) $this->custom[$id] = true;
            }
        }
        if ($s = $read('word/comments.xml')) $this->readComments($s, $read('word/commentsExtended.xml'));
        if ($s = $read('word/numbering.xml')) {
            $xp = $this->xp(xml_dom($s));
            $abs = [];
            $info = [];   // abstractNumId => ['start' => [lvl => n], 'multi' => bool]
            foreach ($xp->query('//w:abstractNum') as $a) {
                $aid = $a->getAttributeNS(W, 'abstractNumId');
                $info[$aid] = ['start' => [], 'multi' => false];
                foreach ($xp->query('w:lvl', $a) as $l) {
                    $lv = (int)$l->getAttributeNS(W, 'ilvl');
                    $abs[$aid][$lv] = (string)$xp->evaluate('string(w:numFmt/@w:val)', $l);
                    $st = (string)$xp->evaluate('string(w:start/@w:val)', $l);
                    if (is_numeric($st)) $info[$aid]['start'][$lv] = (int)$st;
                    // רב-רמתי: הרמה השנייה מציגה גם את הראשונה ("%1.%2")
                    $text = (string)$xp->evaluate('string(w:lvlText/@w:val)', $l);
                    if ($lv === 1 && str_contains($text, '%1') && str_contains($text, '%2')) $info[$aid]['multi'] = true;
                }
            }
            foreach ($xp->query('//w:num') as $n) {
                $aid = (string)$xp->evaluate('string(w:abstractNumId/@w:val)', $n);
                $id = $n->getAttributeNS(W, 'numId');
                $this->numFmt[$id] = $abs[$aid] ?? [];
                $this->numInfo[$id] = $info[$aid] ?? ['start' => [], 'multi' => false];
                foreach ($xp->query('w:lvlOverride[w:startOverride]', $n) as $o) {
                    $this->numInfo[$id]['start'][(int)$o->getAttributeNS(W, 'ilvl')] = (int)$xp->evaluate('string(w:startOverride/@w:val)', $o);
                }
            }
        }
        // הערות שוליים וסיום — אותו מבנה; הסיום נשמר תחת "e<id>" ומסומן end
        foreach (['footnote' => '', 'endnote' => 'e'] as $tag => $pre) {
            if (!($s = $read("word/{$tag}s.xml"))) continue;
            $xp = $this->xp(xml_dom($s));
            foreach ($xp->query("//w:$tag") as $f) {
                // הטקסט עם העיצוב שמותר בהערה (fn_inline); ריצת המספר (footnoteRef) — לא חלק מהטקסט
                $inline = [];
                foreach ($xp->query('.//w:p', $f) as $pi => $p) {
                    if ($pi > 0) $inline[] = ['text' => ' ', 'marks' => []];
                    foreach ($xp->query('w:r | w:hyperlink/w:r', $p) as $r) {
                        if ($xp->query('w:footnoteRef | w:endnoteRef', $r)->length) continue;
                        $t = '';
                        foreach ($xp->query('w:t | w:tab', $r) as $k) $t .= $k->localName === 'tab' ? ' ' : $k->textContent;
                        if ($t === '') continue;
                        $marks = array_values(array_filter($this->runMarks($r, $xp), fn($m) => in_array($m['type'], FN_MARKS, true)));
                        $marks = array_map(fn($m) => ['type' => $m['type']], $marks);
                        $last = count($inline) - 1;
                        if ($last >= 0 && $inline[$last]['marks'] == $marks) $inline[$last]['text'] .= $t;
                        else $inline[] = ['text' => $t, 'marks' => $marks];
                    }
                }
                if ($inline) {
                    $inline[0]['text'] = ltrim($inline[0]['text']);
                    $inline[count($inline) - 1]['text'] = rtrim($inline[count($inline) - 1]['text']);
                }
                $inline = array_values(array_filter($inline, fn($x) => $x['text'] !== ''));
                $text = implode('', array_column($inline, 'text'));
                $styled = (bool)array_filter($inline, fn($x) => $x['marks']);
                $this->footnotes[$pre . $f->getAttributeNS(W, 'id')] = ['text' => $text, 'content' => $styled
                    ? array_map(fn($x) => ['type' => 'text', 'text' => $x['text']] + ($x['marks'] ? ['marks' => $x['marks']] : []), $inline) : null]
                    + ($pre ? ['end' => true] : []);
            }
        }
        if ($s = $read('word/_rels/document.xml.rels')) {
            $d = xml_dom($s);
            foreach ($d->getElementsByTagName('Relationship') as $r) $this->rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
    }

    /** דוח הייבוא: מה שלא עבר בדיוק. [{key, label, count, detail}], לפי סדר IMPORT_REPORT. */
    public function report(): array
    {
        $out = [];
        foreach (IMPORT_REPORT as $k => [$label, $detail]) {
            $n = $k === 'fonts' ? count($this->fonts) : ($this->notes[$k] ?? 0);
            if (!$n) continue;
            if ($k === 'fonts') $detail = implode(', ', array_keys($this->fonts)) . ' — ' . $detail;
            $out[] = ['key' => $k, 'label' => $label, 'count' => $n, 'detail' => $detail];
        }
        return $out;
    }

    private function note(string $k, int $n = 1, ?string $once = null): void
    {
        if ($n <= 0) return;
        if ($once !== null) {
            if (isset($this->seen["$k:$once"])) return;
            $this->seen["$k:$once"] = true;
        }
        $this->notes[$k] = ($this->notes[$k] ?? 0) + $n;
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
            if ($listRun) array_push($out, ...$this->buildLists($listRun, 0));
            $listRun = [];
        };
        foreach ($parent->childNodes as $c) {
            if (!$c instanceof DOMElement || $c->namespaceURI !== W) continue;
            if ($c->localName === 'p') {
                [$block, $num, $breakAfter] = $this->para($c, $xp);
                $sect = $xp->query('w:pPr/w:sectPr', $c)->item(0);
                // פסקה ריקה שכל תפקידה לסיים מקטע — לא נכנסת למסמך
                // וגם פסקה שכל תוכנה מעבר עמוד / טור (כך Word ואנחנו כותבים מעבר) — המעבר לבדו
                $empty = ($sect || $breakAfter) && !($block['content'] ?? []);
                if ($num) { $listRun[] = ['p' => $block, 'num' => $num]; }
                elseif (!$empty) { $flush(); $out[] = $block; }
                if ($breakAfter) { $flush(); $out[] = ['type' => $breakAfter === 'column' ? 'columnBreak' : 'pageBreak']; }
                if ($sect) { $flush(); $out[] = ['type' => 'sectionBreak', '_sect' => $sect]; }
            } elseif ($c->localName === 'tbl') {
                $flush();
                $out[] = $this->table($c, $xp);
            } elseif ($c->localName === 'sdt') {
                $flush();
                // תוכן עניינים של Word (docPartGallery) → צומת toc עם השורות השמורות: טקסט, ומספר העמוד שאחרי הטאב
                if (str_contains((string)$xp->evaluate('string(w:sdtPr/w:docPartObj/w:docPartGallery/@w:val)', $c), 'Table of Contents')) {
                    $out[] = $this->toc($c, $xp);
                    continue;
                }
                foreach ($xp->query('w:sdtContent', $c) as $sc) array_push($out, ...$this->blocks($sc));
            }
        }
        $flush();
        return $out;
    }

    /**
     * פסקאות רשימה רצופות → רשימה אחת או יותר: באותה רמה, סוג אחר (תבליטים / מספור) או רשימה אחרת (numId) — רשימה
     * חדשה, כמו ב-Word (תבליטים ומיד אחריהם רשימה ממוספרת — שתי רשימות, לא אחת).
     */
    private function buildLists(array $items, int $lvl): array
    {
        $lists = [];
        $run = [];
        $key = null;
        foreach ($items as $it) {
            if ($it['num']['lvl'] <= $lvl) {
                $k = ($it['num']['fmt'] === 'bullet' ? 'b' : 'o') . ':' . ($it['num']['id'] ?? '');
                if ($run && $k !== $key) { $lists[] = $this->buildList($run, $lvl); $run = []; }
                $key = $k;
            }
            $run[] = $it;
        }
        if ($run) $lists[] = $this->buildList($run, $lvl);
        return $lists;
    }

    private function buildList(array $items, int $lvl): array
    {
        $fmt = $items[0]['num']['fmt'];
        $id = $items[0]['num']['id'] ?? '';
        $type = $fmt === 'bullet' ? 'bulletList' : 'orderedList';
        $list = ['type' => $type, 'content' => []];
        if ($type === 'orderedList') {
            $info = $this->numInfo[$id] ?? ['start' => [], 'multi' => false];
            $start = $info['start'][$lvl] ?? 1;
            $list['attrs'] = ['start' => $start, 'listType' => $lvl === 0 && $info['multi'] ? 'multi'
                : (in_array($fmt, ['hebrew1', 'hebrew2'], true) ? 'hebrew' : (in_array($fmt, ['lowerRoman', 'upperRoman'], true) ? 'roman' : 'decimal'))];
            // אותו numId שכבר הופיע ברשימה קודמת — Word ממשיך את המספור: "המשך מספור", מהמספר הבא
            if ($lvl === 0 && $id !== '' && isset($this->numSeen[$id])) $list['attrs'] = ['start' => $this->numSeen[$id], 'cont' => true] + $list['attrs'];
        }
        $i = 0;
        while ($i < count($items)) {
            $li = ['type' => 'listItem', 'content' => [$items[$i]['p']]];
            $i++;
            $sub = [];
            while ($i < count($items) && $items[$i]['num']['lvl'] > $lvl) $sub[] = $items[$i++];
            if ($sub) array_push($li['content'], ...$this->buildLists($sub, $lvl + 1));
            $list['content'][] = $li;
        }
        if ($type === 'orderedList' && $lvl === 0 && $id !== '') $this->numSeen[$id] = $list['attrs']['start'] + count($list['content']);
        return $list;
    }

    /** [בלוק, מספור|null, שבירת עמוד אחריו]. */
    /**
     * מאפייני הפסקה מ-w:pPr שתחת $host — הפסקה עצמה, או w:pPrChange (הפסקה כפי שהייתה, במעקב שינויים).
     * מחזיר [סוג, attrs, מספור].
     */
    private function pprParse(DOMElement $host, DOMXPath $xp, DOMElement $p): array
    {
        $attrs = [];
        $type = 'paragraph';
        $style = (string)$xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $host);
        $kind = $this->styles[$style] ?? null;
        if ($kind === 'title' || $kind === 'caption' || ($kind && $kind[0] === 'u')) $attrs['pstyle'] = $kind;
        elseif ($kind) { $type = 'heading'; $attrs['level'] = (int)$kind[1]; }
        $bidi = $xp->query('w:pPr/w:bidi', $host)->length > 0 && (string)$xp->evaluate('string(w:pPr/w:bidi/@w:val)', $host) !== '0';
        $jc = (string)$xp->evaluate('string(w:pPr/w:jc/@w:val)', $host);
        $align = match ($jc) {
            'center' => 'center', 'both', 'distribute' => 'justify',
            'start' => $bidi ? 'right' : 'left', 'end' => $bidi ? 'left' : 'right',
            'left' => 'left', 'right' => 'right', default => null,
        };
        if ($align) $attrs['textAlign'] = $align;
        if (!$bidi && $this->hasLatinOnly($p)) $attrs['dir'] = 'ltr';
        $sp = $xp->query('w:pPr/w:spacing', $host)->item(0);
        if ($sp instanceof DOMElement) {
            if ($sp->hasAttributeNS(W, 'before')) $attrs['spaceBefore'] = round((int)$sp->getAttributeNS(W, 'before') / 20, 1);
            if ($sp->hasAttributeNS(W, 'after')) $attrs['spaceAfter'] = round((int)$sp->getAttributeNS(W, 'after') / 20, 1);
            if ($sp->hasAttributeNS(W, 'line')) {
                $line = (int)$sp->getAttributeNS(W, 'line');
                $rule = $sp->getAttributeNS(W, 'lineRule') ?: 'auto';
                $attrs['lineHeight'] = $rule === 'auto' ? (string)round($line / 240, 2) : 'exact:' . round($line / 20, 1);
            }
        }
        // עצירות טאב (בפסקה עצמה): left / right הפוכים בפסקה מימין לשמאל; clear / bar / num — לא נכנסים
        $tabs = [];
        foreach ($xp->query('w:pPr/w:tabs/w:tab', $host) as $tb) {
            $al = match ($tb->getAttributeNS(W, 'val')) {
                'start' => 'start', 'end' => 'end', 'center' => 'center', 'decimal' => 'decimal',
                'left' => $bidi ? 'end' : 'start', 'right' => $bidi ? 'start' : 'end', default => null,
            };
            if ($al === null) continue;
            $ld = $tb->getAttributeNS(W, 'leader');
            $tabs[] = ['pos' => (int)$tb->getAttributeNS(W, 'pos') / 20, 'align' => $al,
                'leader' => in_array($ld, ['dot', 'middleDot'], true) ? 'dot' : (in_array($ld, ['underscore', 'heavy'], true) ? 'underscore' : ($ld === 'hyphen' ? 'hyphen' : 'none'))];
        }
        if ($tabs = tabs_val($tabs)) $attrs['tabs'] = $tabs;
        // שבירת עמודים (בפסקה עצמה; בכותרות — ממילא נשמרות עם הבא). w:val="0"/"false" — כבוי
        foreach (['keepNext' => 'keepNext', 'keepLines' => 'keepLines', 'pageBreakBefore' => 'breakBefore'] as $tag => $key) {
            $el = $xp->query("w:pPr/w:$tag", $host)->item(0);
            if ($el instanceof DOMElement && !in_array($el->getAttributeNS(W, 'val'), ['0', 'false', 'off'], true)) $attrs[$key] = true;
        }
        if ($xp->query('w:pPr/w:pBdr/*[@w:val!="nil" and @w:val!="none"]', $host)->length
            || preg_match('/^[0-9A-Fa-f]{6}$/', $f = (string)$xp->evaluate('string(w:pPr/w:shd/@w:fill)', $host)) && strtoupper($f) !== 'FFFFFF') { if ($host === $p) $this->note('pborder'); }
        $num = null;
        $numId = (string)$xp->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $host);
        if ($numId !== '' && $numId !== '0') {
            $lvl = (int)$xp->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $host);
            $num = ['lvl' => $lvl, 'fmt' => $this->numFmt[$numId][$lvl] ?? 'bullet', 'id' => $numId];
        }
        // הזחה: אצלנו בצעדים של חצי אינץ' (720 twip) מהצד שבו השורה מתחילה; ברשימה — ההזחה של המספור
        $ind = $xp->query('w:pPr/w:ind', $host)->item(0);
        if ($ind instanceof DOMElement && !$num) {
            // שורה ראשונה / תלויה: אצלנו firstLine בנקודות (שלילי — תלויה); ב-Word start כולל את התלויה
            $hang = (int)$ind->getAttributeNS(W, 'hanging');
            $first = (int)$ind->getAttributeNS(W, 'firstLine');
            if ($hang > 0) $attrs['firstLine'] = -round($hang / 20, 1);
            elseif ($first > 0) $attrs['firstLine'] = round($first / 20, 1);
            $start = $ind->getAttributeNS(W, 'start') ?: $ind->getAttributeNS(W, $bidi ? 'right' : 'left');
            if (is_numeric($start) && ($n = (int)round(((int)$start - max(0, $hang)) / 720)) > 0) $attrs['indent'] = min(8, $n);
        }
        return [$type, $attrs, $num];
    }

    private function para(DOMElement $p, DOMXPath $xp): array
    {
        [$type, $attrs, $num] = $this->pprParse($p, $xp, $p);
        // שינוי עיצוב פסקה במעקב (w:pPrChange): attrs.pchg — מחבר, תאריך, והפסקה כפי שהייתה (כמו בעורך — track.js)
        $chg = $xp->query('w:pPr/w:pPrChange', $p)->item(0);
        if ($chg instanceof DOMElement) {
            [$ot, $oa] = $this->pprParse($chg, $xp, $p);
            $d = $chg->getAttributeNS(W, 'date');
            $attrs['pchg'] = ['author' => mb_substr($chg->getAttributeNS(W, 'author'), 0, 120), 'date' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $d) ? substr($d, 0, 19) . 'Z' : '',
                'old' => ['type' => $ot, 'attrs' => $oa]];
        }
        // שדות — חוץ מ-HYPERLINK (קישור אמיתי), SEQ (כיתוב) ו-REF לכיתוב (הפניה צולבת)
        $known = fn($e) => 'starts-with(normalize-space(' . $e . '), "HYPERLINK") or starts-with(normalize-space(' . $e . '), "SEQ ") or starts-with(normalize-space(' . $e . '), "REF _Ref")';
        $this->note('fields', $xp->query('w:fldSimple|w:r/w:fldChar[@w:fldCharType="begin"]|w:hyperlink/w:r/w:fldChar[@w:fldCharType="begin"]', $p)->length
            - $xp->query('w:fldSimple[' . $known('@w:instr') . ']|w:r/w:instrText[' . $known('.') . ']|w:hyperlink/w:r/w:instrText[' . $known('.') . ']', $p)->length);
        $this->note('math', $xp->query('.//*[local-name()="oMath"]', $p)->length);
        if ($xp->query('w:pPr/w:framePr', $p)->length) $this->note('shapes');
        $inline = [];
        $breakAfter = false;
        $paraId = null;       // מזהה הפסקה מהסימנייה _mbe_p<id>
        // שדה פשוט (fldSimple) — התוצאה האחרונה שלו נכנסת כטקסט. קישור: w:hyperlink, או שדה HYPERLINK (מורכב / פשוט)
        $fieldLink = null;   // הכתובת של שדה HYPERLINK מורכב שבתוכו אנחנו (מ-separate עד end)
        $pending = null;
        $instr = '';          // הפקודה של השדה המורכב הנוכחי (מ-begin עד separate)
        $capField = null;     // שדה SEQ / REF שבתוכו (מ-separate עד end): [צומת, תוצאה] — התוצאה לא נכנסת כטקסט
        $refMark = null;      // הסימניה הנסתרת _Ref<id> האחרונה בפסקה — המזהה של הכיתוב שאחריה
        $simple = null;       // fldSimple של SEQ / REF שכבר נכנס
        foreach ($xp->query('w:commentRangeStart|w:commentRangeEnd|w:hyperlink/w:commentRangeStart|w:hyperlink/w:commentRangeEnd|w:bookmarkStart|w:hyperlink/w:bookmarkStart|w:r|w:hyperlink/w:r|w:ins/w:r|w:del/w:r|w:hyperlink/w:ins/w:r|w:hyperlink/w:del/w:r|w:smartTag/w:r|w:fldSimple/w:r|w:hyperlink/w:fldSimple/w:r', $p) as $r) {
            if ($r->localName === 'commentRangeStart' || $r->localName === 'commentRangeEnd') {
                $cid = $this->cmTop[$r->getAttributeNS(W, 'id')] ?? null;
                if ($cid !== null && $r->localName === 'commentRangeStart') $this->cmOpen[$cid] = true;
                elseif ($cid !== null) unset($this->cmOpen[$cid]);
                continue;
            }
            if ($r->localName === 'bookmarkStart') {
                $name = $r->getAttributeNS(W, 'name');
                // מזהה פסקה של חבילת היצירה (נסתרת גם ב-Word) — attrs.id של הפסקה, לא סימנייה
                if (str_starts_with($name, MBE_PARA_BOOKMARK)) {
                    $pid = substr($name, strlen(MBE_PARA_BOOKMARK));
                    if (preg_match(PARA_ID_RE, $pid)) $paraId ??= $pid;
                    continue;
                }
                if (str_starts_with($name, '_Ref')) { $refMark = substr($name, 4); continue; }   // נסתרת גם ב-Word
                if ($name !== '_GoBack' && ($b = bookmark_name($name))) $inline[] = ['type' => 'bookmark', 'attrs' => ['name' => $b]];
                continue;
            }
            if ($r->parentNode instanceof DOMElement && $r->parentNode->localName === 'fldSimple'
                && ($node = $this->captionField($r->parentNode->getAttributeNS(W, 'instr'), $refMark))) {
                if ($simple !== $r->parentNode) { $simple = $r->parentNode; $inline[] = $this->captionResult($node, $xp->evaluate('string(.)', $r->parentNode), $this->runMarks($r, $xp)); }
                continue;
            }
            $skip = $capField !== null;
            foreach ($xp->query('w:fldChar|w:instrText', $r) as $f) {
                if ($f->localName === 'instrText') { $instr .= $f->textContent; if (($u = $this->fieldHref($f->textContent)) !== null) $pending = $u; continue; }
                $ft = $f->getAttributeNS(W, 'fldCharType');
                if ($ft === 'begin') { $pending = null; $instr = ''; }
                elseif ($ft === 'separate') {
                    $fieldLink = $pending;
                    if ($node = $this->captionField($instr, $refMark)) { $capField = [$node, '', $this->runMarks($r, $xp)]; $skip = true; }
                }
                elseif ($ft === 'end') {
                    $fieldLink = null; $pending = null;
                    if ($capField) { $inline[] = $this->captionResult(...$capField); $capField = null; $skip = true; }
                }
            }
            if ($skip) {
                if ($capField) { $capField[1] .= $xp->evaluate('string(w:t)', $r); if ($xp->query('w:t', $r)->length) $capField[2] = $this->runMarks($r, $xp); }
                continue;
            }
            $marks = $this->runMarks($r, $xp);
            if ($r->parentNode instanceof DOMElement && in_array($r->parentNode->localName, ['ins', 'del'], true)) {
                $rv = $r->parentNode;
                $date = (string)$rv->getAttributeNS(W, 'date');
                $marks[] = ['type' => $rv->localName, 'attrs' => ['author' => mb_substr(trim((string)$rv->getAttributeNS(W, 'author')), 0, 120),
                    'date' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $date) ? substr($date, 0, 19) . 'Z' : '']];
            }
            foreach ($this->cmOpen as $cid => $_) $marks[] = ['type' => 'comment', 'attrs' => ['id' => $cid]];
            $rs = (string)$xp->evaluate('string(w:rPr/w:rStyle/@w:val)', $r);
            if ($rs !== '' && isset($this->custom[$rs])) $this->note('styles', 1, $rs);
            $href = $this->runLink($r, $p) ?? $fieldLink;
            if ($href !== null) $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
            foreach ($r->childNodes as $k) {
                if (!$k instanceof DOMElement) continue;
                switch ($k->localName) {
                    case 't': case 'delText': if ($k->textContent !== '') $inline[] = text_node($k->textContent, $marks); break;
                    case 'tab': $inline[] = text_node("\t", $marks); break;
                    case 'br':
                        $bt = $k->getAttributeNS(W, 'type');
                        if ($bt === 'page') $breakAfter = true;
                        elseif ($bt === 'column') $breakAfter = $breakAfter ?: 'column';   // מעבר עמוד גובר
                        else $inline[] = ['type' => 'hardBreak'];
                        break;
                    case 'drawing':
                        if ($img = $this->image($k)) $inline[] = $img;
                        break;
                    case 'AlternateContent': case 'pict': case 'object':
                        $this->note('shapes');
                        break;
                    case 'endnoteReference':
                        $inline[] = ['type' => 'footnote', 'attrs' => $this->footnotes['e' . $k->getAttributeNS(W, 'id')] ?? ['text' => '', 'content' => null, 'end' => true]];
                        break;
                    case 'footnoteReference':
                        $inline[] = ['type' => 'footnote', 'attrs' => $this->footnotes[$k->getAttributeNS(W, 'id')] ?? ['text' => '', 'content' => null]];
                        break;
                }
            }
        }
        if ($paraId !== null && !isset($this->paraIds[$paraId])) { $this->paraIds[$paraId] = true; $attrs['id'] = $paraId; }
        return [para($this->mergeText($inline), $attrs, $type), $num, $breakAfter];
    }

    /**
     * הערות: comments.xml (מחבר, תאריך, טקסט) ו-commentsExtended.xml (Word 2013+: תשובה — paraIdParent, נפתר — done).
     * הערה בלי הורה — שרשור; תשובה — נכנסת לשרשור של ההורה, בסדר שבקובץ.
     */
    private function readComments(string $s, $ext): void
    {
        $xp = $this->xp(xml_dom($s));
        $W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';
        $W15 = 'http://schemas.microsoft.com/office/word/2012/wordml';
        $exInfo = [];   // paraId => [parent paraId, done]
        if ($ext) {
            $ed = xml_dom($ext);
            foreach ($ed->getElementsByTagNameNS($W15, 'commentEx') as $e) {
                $exInfo[$e->getAttributeNS($W15, 'paraId')] = [$e->getAttributeNS($W15, 'paraIdParent') ?: null, $e->getAttributeNS($W15, 'done') === '1'];
            }
        }
        $byPara = [];   // paraId של הפסקה האחרונה בהערה => id ב-Word
        foreach ($xp->query('//w:comment') as $c) {
            $wid = $c->getAttributeNS(W, 'id');
            $lines = [];
            $paraId = '';
            foreach ($xp->query('w:p', $c) as $p) {
                $lines[] = $xp->evaluate('string(.)', $p);
                $paraId = $p->getAttributeNS($W14, 'paraId') ?: $paraId;
            }
            $date = (string)$c->getAttributeNS(W, 'date');
            $item = ['author' => mb_substr(trim((string)$c->getAttributeNS(W, 'author')), 0, 120),
                'date' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $date) ? substr($date, 0, 19) . 'Z' : '',
                'text' => mb_substr(trim(implode("\n", $lines)), 0, 2000)];
            [$parentPara, $done] = $exInfo[$paraId] ?? [null, false];
            $parent = $parentPara !== null ? ($byPara[$parentPara] ?? null) : null;
            if ($paraId !== '') $byPara[$paraId] = $wid;
            if ($parent !== null && isset($this->cmTop[$parent])) {
                $this->cmTop[$wid] = $top = $this->cmTop[$parent];
                $this->comments[$top]['replies'][] = $item;
                continue;
            }
            $this->cmTop[$wid] = 'c' . preg_replace('/\D/', '', $wid);
            $this->comments[$this->cmTop[$wid]] = $item + ['done' => $done, 'replies' => []];
        }
    }

    /** שדה SEQ (כיתוב) או REF לסימניה _Ref… (הפניה צולבת) → הצומת שלו בלי התוצאה; אחרת null. */
    private function captionField(string $instr, ?string $refMark): ?array
    {
        if (preg_match('/^\s*SEQ\s+(\S+)/u', $instr, $m)) {
            $id = seq_id($refMark ?? '') ?? substr(bin2hex(random_bytes(5)), 0, 9);
            return ['type' => 'seq', 'attrs' => ['label' => seq_label($m[1]), 'id' => $id]];
        }
        if (preg_match('/^\s*REF\s+_Ref(\S+)/u', $instr, $m) && ($id = seq_id($m[1])) !== null) return ['type' => 'xref', 'attrs' => ['target' => $id, 'show' => 'full']];
        return null;
    }

    /** הצומת עם התוצאה השמורה: הפניה שהתוצאה שלה רק מספר — show 'num' (התווית לפניה — טקסט רגיל). העיצוב — של ריצת התוצאה. */
    private function captionResult(array $node, string $result, array $marks): array
    {
        if ($node['type'] === 'xref' && preg_match('/^\s*\d+\s*$/u', $result)) $node['attrs']['show'] = 'num';
        $marks = array_values(array_filter($marks, fn($m) => $m['type'] !== 'link'));
        return $node + ($marks ? ['marks' => $marks] : []);
    }

    /** העיצוב של סגנון פסקה מ-styles.xml → הגדרות סגנון אצלנו (font, size, bold, italic, underline, color, align, before, after, line). */
    private function styleProps(DOMElement $st, DOMXPath $xp): array
    {
        $o = [];
        $on = function (string $tag) use ($xp, $st) {
            $e = $xp->query("w:rPr/w:$tag", $st)->item(0);
            return $e instanceof DOMElement && !in_array($e->getAttributeNS(W, 'val'), ['0', 'false', 'none'], true);
        };
        $font = (string)$xp->evaluate('string(w:rPr/w:rFonts/@w:cs)', $st) ?: (string)$xp->evaluate('string(w:rPr/w:rFonts/@w:ascii)', $st);
        if ($font !== '') $o['font'] = font_name($font);
        $sz = (string)$xp->evaluate('string(w:rPr/w:szCs/@w:val)', $st) ?: (string)$xp->evaluate('string(w:rPr/w:sz/@w:val)', $st);
        if (is_numeric($sz)) $o['size'] = (float)$sz / 2;
        if ($on('b') || $on('bCs')) $o['bold'] = true;
        if ($on('i') || $on('iCs')) $o['italic'] = true;
        if ($on('u')) $o['underline'] = true;
        $c = (string)$xp->evaluate('string(w:rPr/w:color/@w:val)', $st);
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $c) && strtoupper($c) !== '000000') $o['color'] = '#' . strtolower($c);
        $bidi = $xp->query('w:pPr/w:bidi', $st)->length > 0;
        $jc = (string)$xp->evaluate('string(w:pPr/w:jc/@w:val)', $st);
        $al = match ($jc) { 'center' => 'center', 'both', 'distribute' => 'justify', 'start' => $bidi ? 'right' : 'left', 'end' => $bidi ? 'left' : 'right', 'left' => 'left', 'right' => 'right', default => null };
        if ($al) $o['align'] = $al;
        $sp = $xp->query('w:pPr/w:spacing', $st)->item(0);
        if ($sp instanceof DOMElement) {
            if ($sp->hasAttributeNS(W, 'before')) $o['before'] = round((int)$sp->getAttributeNS(W, 'before') / 20, 1);
            if ($sp->hasAttributeNS(W, 'after')) $o['after'] = round((int)$sp->getAttributeNS(W, 'after') / 20, 1);
            if ($sp->hasAttributeNS(W, 'line') && ($sp->getAttributeNS(W, 'lineRule') ?: 'auto') === 'auto') $o['line'] = round((int)$sp->getAttributeNS(W, 'line') / 240, 2);
        }
        return $o;
    }

    /** הכתובת של ריצה שבתוך w:hyperlink (קשר חיצוני או w:anchor) או fldSimple של HYPERLINK — או null. */
    private function runLink(DOMElement $r, DOMElement $p): ?string
    {
        for ($e = $r->parentNode; $e instanceof DOMElement && $e !== $p; $e = $e->parentNode) {
            if ($e->localName === 'hyperlink') {
                $anchor = $e->getAttributeNS(W, 'anchor');
                if ($anchor !== '') return bookmark_name($anchor) !== null ? '#' . $anchor : null;
                return safe_href($this->rels[$e->getAttributeNS(R_NS, 'id')] ?? '');
            }
            if ($e->localName === 'fldSimple' && ($u = $this->fieldHref($e->getAttributeNS(W, 'instr'))) !== null) return $u;
        }
        return null;
    }

    /** קוד שדה HYPERLINK "url" או HYPERLINK \l "סימניה" → כתובת. */
    private function fieldHref(string $instr): ?string
    {
        if (!preg_match('/^\s*HYPERLINK\b(.*)$/s', $instr, $m)) return null;
        if (preg_match('/\\\\l\s+"([^"]+)"/', $m[1], $l)) return bookmark_name($l[1]) !== null ? '#' . $l[1] : null;
        return preg_match('/"([^"]+)"/', $m[1], $u) ? safe_href($u[1]) : null;
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
        $m = $this->rprMarks($rpr, $xp);
        // שינוי עיצוב במעקב (w:rPrChange): סימון fmt עם העיצוב הקודם
        $chg = $xp->query('w:rPrChange', $rpr)->item(0);
        if ($chg instanceof DOMElement) {
            $old = $xp->query('w:rPr', $chg)->item(0);
            $date = (string)$chg->getAttributeNS(W, 'date');
            $m[] = ['type' => 'fmt', 'attrs' => ['author' => mb_substr(trim((string)$chg->getAttributeNS(W, 'author')), 0, 120),
                'date' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $date) ? substr($date, 0, 19) . 'Z' : '',
                'old' => $old instanceof DOMElement ? $this->rprMarks($old, $xp) : []]];
        }
        return $m;
    }

    /** הסימונים של w:rPr (או של ה-rPr הקודם שבתוך rPrChange). */
    private function rprMarks(DOMElement $rpr, DOMXPath $xp): array
    {
        $on = function (string $tag) use ($xp, $rpr) {
            $e = $xp->query("w:$tag", $rpr)->item(0);
            if (!$e instanceof DOMElement) return false;
            $v = $e->getAttributeNS(W, 'val');
            return !in_array($v, ['0', 'false', 'none'], true);
        };
        $m = [];
        if ($on('b') || $on('bCs')) $m[] = ['type' => 'bold'];
        if ($on('i') || $on('iCs')) $m[] = ['type' => 'italic'];
        if ($on('u')) {
            $ul = ['double' => 'double', 'dotted' => 'dotted', 'dottedHeavy' => 'dotted', 'dash' => 'dashed', 'dashLong' => 'dashed', 'dashedHeavy' => 'dashed',
                'wave' => 'wavy', 'wavyHeavy' => 'wavy', 'wavyDouble' => 'wavy', 'thick' => 'thick'][(string)$xp->evaluate('string(w:u/@w:val)', $rpr)] ?? null;
            $m[] = $ul ? ['type' => 'underline', 'attrs' => ['ul' => $ul]] : ['type' => 'underline'];
        }
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
        if ($font !== '') {
            $ts['fontFamily'] = font_name($font);
            if (!isset(FontSet::FAMILIES[$ts['fontFamily']])) $this->fonts[$ts['fontFamily']] = true;
        }
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
        // ציור בלי תמונה — צורה, תיבת טקסט, תרשים, SmartArt
        if ($rid === '') { $this->note('shapes'); return null; }
        $target = $this->rels[$rid] ?? '';
        $bytes = $target === '' || str_contains($target, '..') ? null : ($this->read)('word/' . ltrim($target, '/'));
        if ($bytes === null) { $this->note('images'); return null; }
        try {
            $m = media_store($this->docId, $bytes);
        } catch (ApiError $e) {
            $this->note('images');
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
                $attrs['_rh'] = (int)$anchor->getAttribute('relativeHeight');   // שכבה — import_layers()
                // יחסית לעמוד בשני הצירים — מעוגנת לעמוד (נשארת במקום); אחרת — מהפסקה (העמודה / השוליים ≈ קצה התוכן)
                if ($xp->evaluate('string(wp:positionH/@relativeFrom)', $anchor) === 'page' && $xp->evaluate('string(wp:positionV/@relativeFrom)', $anchor) === 'page')
                    $attrs['anchor'] = 'page';
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
                $va = (string)$xp->evaluate('string(w:tcPr/w:vAlign/@w:val)', $tc);
                if ($va === 'center' || $va === 'bottom') $attrs['valign'] = $va === 'center' ? 'middle' : 'bottom';
                $all = $this->blocks($tc);
                $content = array_values(array_filter($all, fn($b) => in_array($b['type'], ['paragraph', 'heading', 'bulletList', 'orderedList'], true)));
                $this->note('nested', count(array_filter($all, fn($b) => $b['type'] === 'table')));
                $cells[] = ['type' => 'tableCell', 'attrs' => $attrs, 'content' => $content ?: [para()]];
                if ($vm instanceof DOMElement) $restart[$col] = [$ri, count($cells) - 1];
                else unset($restart[$col]);
                $col += $span;
            }
            $rows[$ri] = ['type' => 'tableRow', 'content' => $cells ?: [['type' => 'tableCell', 'content' => [para()]]]];
            // גובה שורה (מינימלי; גם "מדויק" — אצלנו מינימלי)
            $rh = (int)$xp->evaluate('string(w:trPr/w:trHeight/@w:val)', $tr);
            if ($rh > 0) $rows[$ri]['attrs'] = ['height' => min(400, round($rh / 20, 1))];
        }
        return $rows ? ['type' => 'table', 'attrs' => $this->tableAttrs($tbl, $xp, $grid), 'content' => array_values($rows)] : para();
    }

    /**
     * תוכן עניינים: פסקה עם טאב — לפניו הכותרת, אחריו מספר העמוד; הרמה — מההזחה (w:ind) או מסגנון TOCn.
     * הפסקה הראשונה בלי טאב (הכותרת "תוכן עניינים") — מדולגת. כמה רמות: הרמה העמוקה ביותר שנמצאה (לפחות 3).
     */
    private function toc(DOMElement $sdt, DOMXPath $xp): array
    {
        $entries = [];
        $max = 3;
        foreach ($xp->query('w:sdtContent//w:p', $sdt) as $p) {
            $parts = [''];
            foreach ($xp->query('.//w:t|.//w:tab[not(ancestor::w:pPr)]', $p) as $n) {
                if ($n->localName === 'tab') $parts[] = '';
                else $parts[count($parts) - 1] .= $n->textContent;
            }
            if (count($parts) < 2 || trim($parts[0]) === '') continue;
            $style = (string)$xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
            $lvl = preg_match('/^TOC(\d)$/i', $style, $m) ? (int)$m[1]
                : 1 + (int)round((int)($xp->evaluate('string(w:pPr/w:ind/@w:start)', $p) ?: $xp->evaluate('string(w:pPr/w:ind/@w:right)', $p)) / 280);
            $lvl = max(1, min(9, $lvl));
            $max = max($max, $lvl);
            $entries[] = ['t' => mb_substr(trim($parts[0]), 0, 300), 'l' => $lvl, 'p' => mb_substr(trim(end($parts)), 0, 12), 'i' => count($entries)];
        }
        // רשימת איורים: TOC \c "<תווית>" — שורות בלי רמה
        $instr = '';
        foreach ($xp->query('w:sdtContent//w:instrText', $sdt) as $n) $instr .= $n->textContent;
        foreach ($xp->query('w:sdtContent//w:fldSimple/@w:instr', $sdt) as $n) $instr .= ' ' . $n->value;
        if (preg_match('/\\\\c\s+"?([^"\\\\\s]+)/u', $instr, $m)) {
            return ['type' => 'toc', 'attrs' => ['seq' => seq_label($m[1]), 'levels' => 3, 'entries' => array_map(fn($e) => array_replace($e, ['l' => 0]), $entries)]];
        }
        return ['type' => 'toc', 'attrs' => ['levels' => $max, 'entries' => $entries]];
    }

    /** גבולות, צבע, רוחב (% מהשורה) ויישור של טבלה מ-tblPr — רק מה שאינו ברירת המחדל. */
    private function tableAttrs(DOMElement $tbl, DOMXPath $xp, array $grid): array
    {
        $a = [];
        $on = function (string $side) use ($tbl, $xp): ?bool {
            $alt = ['start' => 'right', 'end' => 'left'][$side] ?? null;   // Word ישן: right / left
            $el = $xp->query("w:tblPr/w:tblBorders/w:$side", $tbl)->item(0) ?? ($alt ? $xp->query("w:tblPr/w:tblBorders/w:$alt", $tbl)->item(0) : null);
            if (!$el instanceof DOMElement) return null;
            return !in_array($el->getAttributeNS(W, 'val'), ['nil', 'none', ''], true);
        };
        $outer = array_map($on, ['top', 'bottom', 'start', 'end']);
        $inner = array_map($on, ['insideH', 'insideV']);
        if (!in_array(null, $outer, true) && !in_array(null, $inner, true)) {
            $o = !in_array(false, $outer, true);
            $i = !in_array(false, $inner, true);
            if (!$o && !$i && !in_array(true, $outer, true) && !in_array(true, $inner, true)) $a['borders'] = 'none';
            elseif ($o && !in_array(true, $inner, true)) $a['borders'] = 'outer';
            elseif ($i && !in_array(true, $outer, true)) $a['borders'] = 'inner';
        }
        $col = strtolower((string)$xp->evaluate('string(w:tblPr/w:tblBorders/*[@w:color and @w:color!="auto"][1]/@w:color)', $tbl));
        if (preg_match('/^[0-9a-f]{6}$/', $col) && $col !== '808080' && $col !== '000000') $a['borderColor'] = '#' . $col;
        // רוחב: pct (חמישיות של אחוז) או dxa — ביחס לרוחב השורה
        if ($this->contentTw === null) {
            $sp = $xp->query('/w:document/w:body/w:sectPr')->item(0);
            $pw = $sp ? (int)$xp->evaluate('string(w:pgSz/@w:w)', $sp) : 0;
            $this->contentTw = $pw ? max(0, $pw - (int)$xp->evaluate('string(w:pgMar/@w:left)', $sp) - (int)$xp->evaluate('string(w:pgMar/@w:right)', $sp)) : 0;
        }
        $tw = $xp->query('w:tblPr/w:tblW', $tbl)->item(0);
        $pct = null;
        if ($tw instanceof DOMElement) {
            $v = (string)$tw->getAttributeNS(W, 'w');
            if ($tw->getAttributeNS(W, 'type') === 'pct') $pct = str_ends_with($v, '%') ? (float)$v : (float)$v / 50;
            elseif ($tw->getAttributeNS(W, 'type') === 'dxa' && $this->contentTw > 0 && (int)$v > 0) $pct = (int)$v / $this->contentTw * 100;
        }
        if ($pct === null && $grid && $this->contentTw > 0) $pct = array_sum($grid) / $this->contentTw * 100;
        if ($pct !== null && $pct >= 10 && $pct < 95) $a['width'] = (int)round($pct);
        if (isset($a['width'])) {
            $jc = (string)$xp->evaluate('string(w:tblPr/w:jc/@w:val)', $tbl);
            $bidi = $xp->query('w:tblPr/w:bidiVisual', $tbl)->length > 0;
            // start / end — לוגיים (בטבלה מימין לשמאל start = ימין); left / right — כפשוטם
            $al = match ($jc) { 'center' => 'center', 'end' => $bidi ? 'left' : 'right', 'start' => $bidi ? 'right' : 'left', 'left' => 'left', 'right' => 'right', default => null };
            if ($al && $al !== 'right') $a['align'] = $al;
        }
        return $a;
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
        // טורים: במקטע (מוצגים בעורך, בהדפסה וב-PDF, ויוצאים שוב ל-Word)
        // תמיד במפורש — מקטע בלי w:cols הוא טור אחד ב-Word, ואצלנו מקטע יורש מהקודם
        $cn = max(1, (int)$xp->evaluate('string(w:cols/@w:num)', $sect));
        $space = (string)$xp->evaluate('string(w:cols/@w:space)', $sect);
        $p['columns'] = ['n' => min(4, $cn), 'gap' => is_numeric($space) ? round((int)$space / TWIP_PER_MM_IMPORT, 1) : 12.7,
            'line' => in_array((string)$xp->evaluate('string(w:cols/@w:sep)', $sect), ['1', 'true', 'on'], true)];
        $p += $this->headerFooter($sect, $xp);
        // התחלת מספור מחדש (במקטע שאינו הראשון — המקטע הראשון לוקח את ההתחלה דרך numbering.start)
        $st = (string)$xp->evaluate('string(w:pgNumType/@w:start)', $sect);
        if (is_numeric($st)) { $p['num_restart'] = true; $p['num_start'] = max(0, (int)$st); }
        return $p ?: null;
    }

    /**
     * כותרת עליונה ותחתונה של מקטע: הטקסט, ומספר העמוד (שדה PAGE) → numbering. כותרת לעמוד הראשון (titlePg) שהיא
     * אותו טקסט בלי מספר = first_page:false; עם טקסט אחר — עמוד ראשון שונה (first_diff, header_first / footer_first).
     * לעמוד זוגי — header_even / footer_even. בלי PAGE — בלי מספור.
     */
    private function headerFooter(DOMElement $sect, DOMXPath $xp): array
    {
        $out = [];
        $num = null;
        $firstNum = false;
        $titlePg = $xp->query('w:titlePg', $sect)->length && (string)$xp->evaluate('string(w:titlePg/@w:val)', $sect) !== '0';
        $first = [];   // הכותרות לעמוד הראשון, אם אחת מהן שונה מהרגילה
        foreach (['header', 'footer'] as $kind) {
            $parts = [];
            foreach ($xp->query("w:{$kind}Reference", $sect) as $ref) {
                $target = $this->rels[$ref->getAttributeNS(R_NS, 'id')] ?? '';
                $xml = $target === '' || str_contains($target, '..') ? null : ($this->read)('word/' . ltrim($target, '/'));
                if ($xml !== null) $parts[$ref->getAttributeNS(W, 'type') ?: 'default'] = $this->hfPart(xml_dom($xml), $kind, ltrim($target, '/'));
            }
            $def = $parts['default'] ?? null;
            if ($def) {
                if ($def['rich']) $out[$kind] = $def['rich'];
                elseif ($def['text']) $out[$kind] = implode("\n", $def['text']);
                $num ??= $def['num'];
                if ($def['complex']) $this->note('hf_complex', 1, $kind);
            }
            foreach ($parts as $type => $part) {
                if ($type === 'default') continue;
                // כותרת לעמוד זוגי (evenAndOddHeaders) — הטקסט שלה; המספור — כמו בכותרת הרגילה
                if ($type === 'even') { $out[$kind . '_even'] = $part['rich'] ?: implode("\n", $part['text']); continue; }
                if ($type === 'first') {
                    if (!$titlePg) continue;   // בלי titlePg Word לא מציג אותה
                    $firstNum = $firstNum || (bool)$part['num'];
                    $same = $part['text'] === ($def['text'] ?? []) && !$part['rich'] && !($def['rich'] ?? null);
                    $first[$kind] = $same ? ($out[$kind] ?? '') : ($part['rich'] ?: implode("\n", $part['text']));
                    if (!$same) $first['diff'] = true;
                    continue;
                }
                if ($part['text'] || $part['num']) $this->note('hf_extra', 1, $type . $kind);
            }
        }
        if ($num) {
            $fmt = (string)$xp->evaluate('string(w:pgNumType/@w:fmt)', $sect);
            if (in_array($fmt, ['hebrew1', 'hebrew2'], true)) $num['format'] = 'hebrew';
            $start = (string)$xp->evaluate('string(w:pgNumType/@w:start)', $sect);
            if (is_numeric($start)) $num['start'] = (int)$start;
            $num['first_page'] = !$titlePg || $firstNum;
        }
        $out['numbering'] = $num ?: ['show' => false];
        // במפורש גם כשאין — מקטע אצלנו יורש מהקודם, וב-Word titlePg הוא של המקטע בלבד
        $out['first_diff'] = !empty($first['diff']);
        if ($out['first_diff']) $out += ['header_first' => $first['header'] ?? '', 'footer_first' => $first['footer'] ?? ''];
        return $out;
    }

    /** חלק כותרת: שורות הטקסט, המספור (הפסקה עם PAGE) והאם יש בו טבלה / תמונה. */
    private function hfPart(DOMDocument $d, string $kind, string $target = ''): array
    {
        $hx = $this->xp($d);
        $text = [];
        $num = null;
        // כותרת עשירה: הפסקאות (בלי פסקת מספר העמוד) כמו בגוף — עם הקשרים של חלק הכותרת (לתמונה)
        $saved = $this->rels;
        if ($target !== '' && !str_contains($target, '..') && ($rs = ($this->read)('word/_rels/' . basename($target) . '.rels'))) {
            $rd = xml_dom($rs);
            foreach ($rd->getElementsByTagName('Relationship') as $r) $this->rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
        $blocks = [];
        $rich = false;
        $seenTbl = [];
        foreach ($hx->query('//w:p') as $hp) {
            $instr = '';
            foreach ($hx->query('.//w:instrText', $hp) as $n) $instr .= ' ' . $n->textContent;
            foreach ($hx->query('.//w:fldSimple', $hp) as $n) $instr .= ' ' . $n->getAttributeNS(W, 'instr');
            $line = '';
            foreach ($hx->query('.//w:t|.//w:tab', $hp) as $t) $line .= $t->localName === 'tab' ? ' ' : $t->textContent;
            if (!$num && preg_match('/\bPAGE\b/', $instr)) {
                $bidi = $hx->query('w:pPr/w:bidi', $hp)->length > 0;
                $num = ['show' => true, 'position' => $kind === 'header' ? 'top' : 'bottom',
                    'align' => match ((string)$hx->evaluate('string(w:pPr/w:jc/@w:val)', $hp)) {
                        'center' => 'center', 'left' => 'left', 'right' => 'right',
                        'end' => $bidi ? 'left' : 'right',
                        default => $bidi ? 'right' : 'left',
                    },
                    'format' => preg_match('/\bNUMPAGES\b/', $instr) ? 'n_of_m' : (str_contains($line, "עמ'") ? 'page_n' : (str_contains($line, '-') ? 'dash_n' : 'n'))];
                continue;
            }
            if (trim($line) !== '') $text[] = trim($line);
            // פסקה בתוך טבלה: הטבלה כולה נכנסת כבלוק אחד — בפסקה הראשונה שלה (הטבלה החיצונית)
            $tbl = $hx->query('ancestor::w:tbl[last()]', $hp)->item(0);
            if ($tbl instanceof DOMElement) {
                if (isset($seenTbl[spl_object_id($tbl)])) continue;
                $seenTbl[spl_object_id($tbl)] = true;
                $b = hf_rich_val([$this->table($tbl, $hx)])[0] ?? null;
                if ($b && ($b['type'] ?? '') === 'table') { $blocks[] = $b; $rich = true; }
                continue;
            }
            if (trim($line) !== '' || $hx->query('.//w:drawing', $hp)->length) {
                [$b] = $this->para($hp, $hx);
                $b = hf_rich_val([$b])[0] ?? null;
                if (!$b) continue;
                $blocks[] = $b;
                // עשירה רק אם יש בה משהו שטקסט פשוט ממורכז לא מחזיק: עיצוב, יישור אחר, תמונה
                // (גודל 9 נק' בלבד — כך כותרת פשוטה נכתבת, ב-Word ואצלנו — לא נחשב עיצוב)
                $plain9 = fn($m) => ($m['type'] ?? '') === 'textStyle' && ($m['attrs'] ?? []) == ['fontSize' => '9pt'];
                foreach ($b['content'] ?? [] as $x) if (array_filter($x['marks'] ?? [], fn($m) => !$plain9($m)) || ($x['type'] ?? '') === 'image') $rich = true;
                if (isset($b['attrs']['textAlign']) && $b['attrs']['textAlign'] !== 'center') $rich = true;
            }
        }
        $this->rels = $saved;
        // סימן מים: צורת WordArt (VML) עם textpath — כך Word שומר "סימן מים" מותאם. היא לא נחשבת "כותרת מורכבת"
        $wm = $hx->query('//*[local-name()="textpath"][@string]')->item(0);
        if ($wm instanceof DOMElement && $this->watermark === '') $this->watermark = mb_substr(trim($wm->getAttribute('string')), 0, 40);
        // תמונה (גם צפה — נייר מכתבים: נכנסת בשורה) וטבלה — נכנסות לכותרת העשירה; צורה, תיבת טקסט, טבלה בתוך טבלה —
        // "כותרת מורכבת" (לדוח)
        $complex = $hx->query('//w:tbl//w:tbl|//w:drawing[not(wp:inline)][not(.//a:blip)]|//w:pict[not(.//*[local-name()="textpath"])]')->length > 0;
        return ['text' => $text, 'num' => $num, 'complex' => $complex, 'rich' => $rich ? $blocks : null];
    }
}

const EMU_PER_PX_IMPORT = 9525;
const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

/** דוח הייבוא: מפתח => [מה, מה קרה לו]. הסדר — הסדר בדוח. */
/**
 * כותרת עליונה / תחתונה מפסקאות שיובאו: טקסט פשוט (שורה לפסקה) — או כותרת עשירה כשיש בה מה שטקסט פשוט ממורכז לא
 * מחזיק: עיצוב, יישור אחר, תמונה (גודל 9 נק' לבד — כך כותרת פשוטה נכתבת, ב-Word ואצלנו — לא נחשב). null — ריקה.
 */
function hf_from_blocks(array $blocks): string|array|null
{
    $blocks = array_values(array_filter($blocks, fn($b) => ($b['type'] ?? '') === 'paragraph' && !empty($b['content'])));
    if (!$blocks) return null;
    $plain9 = fn($m) => ($m['type'] ?? '') === 'textStyle' && ($m['attrs'] ?? []) == ['fontSize' => '9pt'];
    foreach ($blocks as $b) {
        $rich = isset($b['attrs']['textAlign']) && $b['attrs']['textAlign'] !== 'center';
        foreach ($b['content'] as $x) $rich = $rich || ($x['type'] ?? '') !== 'text' || array_filter($x['marks'] ?? [], fn($m) => !$plain9($m));
        if ($rich) return hf_rich_val($blocks);
    }
    return implode("\n", array_map(fn($b) => trim(implode('', array_column($b['content'], 'text'))), $blocks));
}

const IMPORT_REPORT = [
    'shapes' => ['צורות, תיבות טקסט ותרשימים', 'לא יובאו'],
    'images' => ['תמונות שלא נטענו', 'הקובץ פגום או בפורמט שאינו נתמך'],
    'math' => ['נוסחאות', 'לא יובאו'],
    'nested' => ['טבלה בתוך טבלה', 'הטבלה הפנימית לא יובאה'],
    'fields' => ['שדות (תוכן עניינים, תאריך, הפניות)', 'יובאו כטקסט קבוע — לא יתעדכנו'],
    'hf_extra' => ['כותרת שונה לעמוד הראשון או לעמודים זוגיים', 'יובאה רק הכותרת הרגילה'],
    'hf_complex' => ['צורה, תמונה צפה או טבלה בתוך טבלה בכותרת עליונה / תחתונה', 'יובא רק הטקסט'],
    'styles' => ['סגנונות תו מותאמים', 'העיצוב שבא מסגנון התו לא יובא; סגנונות פסקה ועיצוב ישיר — כן'],
    'pborder' => ['גבולות והצללה של פסקה', 'לא יובאו'],
    'fonts' => ['גופנים שאינם זמינים', 'מוצגים ב-Heebo, זהה במסך, בהדפסה וב-PDF'],
    'doc_comments' => ['הערות (Word ישן — ‎.doc)', 'לא יובאו'],
];
const TWIP_PER_MM_IMPORT = 56.6929;

// ======================================================================
// קובץ שהועלה — לפי התוכן (לא לפי הסיומת): docx, ODT, HTML, טקסט
// ======================================================================

require_once __DIR__ . '/import_odt.php';
require_once __DIR__ . '/import_rtf.php';
require_once __DIR__ . '/mbe_import.php';
require_once __DIR__ . '/import_doc.php';

/**
 * ייבוא קובץ שהמשתמש העלה (my_new): Word (docx), OpenDocument (ODT), RTF, HTML, או טקסט (UTF-8 / Windows-1255) — כל
 * שורה פסקה, "# " כותרת, "- " תבליט (import_lines). Word ישן (‎.doc) — lib/import_doc.php. מחזיר ['json', 'page', 'report'].
 */
function import_file(string $bytes, string $name, string $docId): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (str_starts_with($bytes, 'PK')) {
        if (is_mbe($bytes)) return import_mbe($bytes, $docId);
        if (is_odt($bytes)) return import_odt($bytes, $docId);
        return import_docx($bytes, $docId);
    }
    if (str_starts_with($bytes, '{\\rtf')) return import_rtf($bytes, $docId);
    if (is_doc($bytes)) return import_doc($bytes, $docId);   // Word 97–2003 (lib/import_doc.php)
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        $conv = @iconv('WINDOWS-1255', 'UTF-8//IGNORE', $bytes);
        if ($conv === false) throw new ApiError('הקובץ אינו מסמך שאפשר לייבא', 'bad_file');
        $bytes = $conv;
    }
    $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
    if (in_array($ext, ['html', 'htm'], true) || preg_match('/^\s*(<!doctype html|<html|<body|<meta|<p[\s>]|<div[\s>]|<h[1-6][\s>])/i', $bytes)) {
        // מסמך שלם — רק מה שבתוך body
        if (preg_match('#<body[^>]*>(.*)</body>#is', $bytes, $m)) $bytes = $m[1];
        return import_html($bytes, $docId) + ['report' => []];
    }
    if (str_contains($bytes, "\0")) throw new ApiError('הקובץ אינו מסמך שאפשר לייבא', 'bad_file');
    return import_lines($bytes) + ['report' => []];
}

