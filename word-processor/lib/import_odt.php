<?php
/**
 * ייבוא ODT (OpenDocument — LibreOffice, Google Docs "הורדה כ-ODT"), כמו import_docx: כותרות, פסקאות (יישור, כיוון,
 * ריווח, הזחה, שורה ראשונה, מעבר עמוד), עיצוב תווים (מודגש, נטוי, קו, קו חוצה, צבע, גודל, גופן, הדגשה, עילי / תחתי),
 * קישורים, רשימות (תבליטים / ממוספרות, מקוננות), טבלאות (עם מיזוג), תמונות (Pictures/), הערות שוליים וסיום,
 * גודל הדף, השוליים והכותרות. מה שלא נכנס — לדוח (IMPORT_REPORT, כמו ב-Word).
 * מחזיר ['json', 'page', 'report'].
 */

const ODF_NS = [
    'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
    'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
    'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
    'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
    'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
    'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
    'xlink' => 'http://www.w3.org/1999/xlink',
    'svg' => 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0',
];

/** האם הבייטים הם ODT (ZIP שה-mimetype שלו מסמך טקסט של OpenDocument). */
function is_odt(string $bytes): bool
{
    return str_starts_with($bytes, "PK") && str_contains(substr($bytes, 0, 200), 'application/vnd.oasis.opendocument.text');
}

/** אורך ב-ODF ("2cm", "0.5in", "12pt", "20mm", "16px") → נקודות. */
function odf_pt(?string $v): ?float
{
    if ($v === null || !preg_match('/^(-?[\d.]+)\s*(cm|mm|in|pt|pc|px)?$/', trim($v), $m)) return null;
    $n = (float)$m[1];
    return match ($m[2] ?? 'pt') { 'cm' => $n * 72 / 2.54, 'mm' => $n * 72 / 25.4, 'in' => $n * 72, 'pc' => $n * 12, 'px' => $n * 0.75, default => $n };
}

function import_odt(string $bytes, string $docId): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'wpodt');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { unlink($tmp); throw new ApiError('הקובץ אינו ODT תקין', 'bad_file'); }
    $read = function (string $name) use ($zip) { $s = $zip->getFromName($name); return $s === false ? null : $s; };
    $content = $read('content.xml');
    if ($content === null) { $zip->close(); unlink($tmp); throw new ApiError('הקובץ אינו ODT תקין (אין content.xml)', 'bad_file'); }
    $ctx = new OdtCtx($read, $docId, $read('styles.xml'));
    $out = $ctx->run($content);
    $zip->close();
    unlink($tmp);
    return $out;
}

final class OdtCtx
{
    private array $styles = [];    // שם → ['family', 'parent', 'text' => [...], 'para' => [...], 'outline' => n, 'list' => שם]
    private array $lists = [];     // סגנון רשימה → [רמה => 'number'|'bullet', 'fmt' => …]
    private array $fonts = [];     // שם הגופן בהצהרה → המשפחה
    private array $missing = [];   // גופנים שאינם זמינים (לדוח)
    private array $notes = [];
    private bool $rtl = false;     // כיוון ברירת המחדל של הפסקאות
    private ?array $page = null;
    private ?DOMXPath $xp = null;

    public function __construct(private $read, private string $docId, private ?string $stylesXml) {}

    public function run(string $content): array
    {
        if ($this->stylesXml) $this->loadStyles(xml_dom($this->stylesXml), true);
        $d = xml_dom($content);
        $this->loadStyles($d, false);
        $xp = $this->xpath($d);
        $body = $xp->query('/office:document-content/office:body/office:text')->item(0);
        $blocks = $body ? $this->blocks($body) : [];
        $json = ['type' => 'doc', 'content' => $blocks ?: [para()]];
        return ['json' => $json, 'page' => $this->page, 'report' => $this->report()];
    }

    private function xpath(DOMDocument $d): DOMXPath
    {
        $xp = new DOMXPath($d);
        foreach (ODF_NS as $p => $ns) $xp->registerNamespace($p, $ns);
        return $this->xp = $xp;
    }

    // ---------- סגנונות ----------

    private function loadStyles(DOMDocument $d, bool $common): void
    {
        $xp = $this->xpath($d);
        foreach ($xp->query('//office:font-face-decls/style:font-face') as $f) {
            $fam = trim($f->getAttributeNS(ODF_NS['svg'], 'font-family'), "'\" ");
            $this->fonts[$f->getAttributeNS(ODF_NS['style'], 'name')] = $fam !== '' ? $fam : $f->getAttributeNS(ODF_NS['style'], 'name');
        }
        // כיוון ברירת המחדל: הסגנון הבסיסי של פסקאות (style:default-style)
        foreach ($xp->query('//style:default-style[@style:family="paragraph"]/style:paragraph-properties') as $pp) {
            if (str_starts_with($pp->getAttributeNS(ODF_NS['style'], 'writing-mode'), 'rl')) $this->rtl = true;
        }
        foreach ($xp->query('//office:styles/style:style|//office:automatic-styles/style:style') as $s) {
            $name = $s->getAttributeNS(ODF_NS['style'], 'name');
            $st = ['auto' => $s->parentNode->localName === 'automatic-styles', 'family' => $s->getAttributeNS(ODF_NS['style'], 'family'), 'parent' => $s->getAttributeNS(ODF_NS['style'], 'parent-style-name'),
                'outline' => (int)$s->getAttributeNS(ODF_NS['style'], 'default-outline-level'), 'list' => $s->getAttributeNS(ODF_NS['style'], 'list-style-name'),
                'display' => $s->getAttributeNS(ODF_NS['style'], 'display-name') ?: $name, 'text' => [], 'para' => [], 'cell' => [], 'graphic' => []];
            foreach (['text' => 'text-properties', 'para' => 'paragraph-properties', 'cell' => 'table-cell-properties', 'graphic' => 'graphic-properties'] as $k => $tag) {
                $el = $xp->query("style:$tag", $s)->item(0);
                if ($el instanceof DOMElement) foreach ($el->attributes as $a) $st[$k][$a->prefix . ':' . $a->localName] = $a->value;
            }
            $this->styles[$name] = $st;
        }
        foreach ($xp->query('//text:list-style') as $ls) {
            $lv = [];
            foreach ($ls->childNodes as $c) {
                if (!$c instanceof DOMElement) continue;
                $level = (int)$c->getAttributeNS(ODF_NS['text'], 'level') ?: 1;
                $lv[$level] = $c->localName === 'list-level-style-number'
                    ? ['type' => 'number', 'fmt' => $c->getAttributeNS(ODF_NS['style'], 'num-format'), 'start' => (int)($c->getAttributeNS(ODF_NS['text'], 'start-value') ?: 1)]
                    : ['type' => 'bullet'];
            }
            $this->lists[$ls->getAttributeNS(ODF_NS['style'], 'name')] = $lv;
        }
        if ($common) $this->pageLayout($xp);
    }

    /** מאפיין של סגנון, עם הירושה (parent-style-name). */
    private function prop(string $style, string $group, string $key): ?string
    {
        for ($i = 0, $s = $style; $s !== '' && $i < 20; $i++) {
            $st = $this->styles[$s] ?? null;
            if (!$st) return null;
            if (isset($st[$group][$key])) return $st[$group][$key];
            $s = $st['parent'];
        }
        return null;
    }

    /** מאפיין של העיצוב הישיר בלבד (סגנונות אוטומטיים — לא של סגנון בעל שם כמו "Standard" או "Heading 1"). */
    private function own(string $style, string $group, string $key): ?string
    {
        for ($i = 0, $s = $style; $s !== '' && $i < 20; $i++) {
            $st = $this->styles[$s] ?? null;
            if (!$st || !$st['auto']) return null;
            if (isset($st[$group][$key])) return $st[$group][$key];
            $s = $st['parent'];
        }
        return null;
    }

    private function outlineOf(string $style): int
    {
        for ($i = 0, $s = $style; $s !== '' && $i < 20; $i++) {
            $st = $this->styles[$s] ?? null;
            if (!$st) return 0;
            if ($st['outline']) return $st['outline'];
            $s = $st['parent'];
        }
        return 0;
    }

    /** גודל הדף, כיוון, שוליים, וכותרות (עמוד המאסטר הראשון — כמו המקטע הראשון ב-Word). */
    private function pageLayout(DOMXPath $xp): void
    {
        $mp = $xp->query('//office:master-styles/style:master-page')->item(0);
        if (!$mp instanceof DOMElement) return;
        $pl = $xp->query('//style:page-layout[@style:name="' . $mp->getAttributeNS(ODF_NS['style'], 'page-layout-name') . '"]/style:page-layout-properties')->item(0);
        $p = [];
        if ($pl instanceof DOMElement) {
            $w = odf_pt($pl->getAttributeNS(ODF_NS['fo'], 'page-width'));
            $h = odf_pt($pl->getAttributeNS(ODF_NS['fo'], 'page-height'));
            if ($w && $h) {
                $p['size'] = abs(min($w, $h) * 25.4 / 72 - 215.9) < 3 ? 'Letter' : 'A4';
                $p['orientation'] = $w > $h ? 'landscape' : 'portrait';
            }
            foreach (['top', 'bottom', 'right', 'left'] as $k) {
                $v = odf_pt($pl->getAttributeNS(ODF_NS['fo'], "margin-$k"));
                // עם כותרת: ב-ODF השוליים הם עד הכותרת, והכותרת (גובה + רווח) מעליהם — אצלנו (כמו ב-Word) השוליים הם עד הטקסט
                if (in_array($k, ['top', 'bottom'], true) && $xp->query('style:' . ($k === 'top' ? 'header' : 'footer'), $mp)->length) {
                    $hs = $xp->query('style:' . ($k === 'top' ? 'header' : 'footer') . '-style/style:header-footer-properties', $pl->parentNode)->item(0);
                    if ($hs instanceof DOMElement) $v = ($v ?? 0) + (odf_pt($hs->getAttributeNS(ODF_NS['fo'], 'min-height')) ?? 0);
                }
                if ($v !== null) $p['margins'][$k] = round($v * 25.4 / 72, 1);
            }
        }
        // כותרות: הטקסט (עם מספר עמוד — text:page-number — כמספור)
        foreach (['header', 'footer'] as $k) {
            $hf = $xp->query("style:$k", $mp)->item(0);
            if (!$hf instanceof DOMElement) continue;
            $lines = [];
            foreach ($xp->query('.//text:p|.//text:h', $hf) as $hp) {
                if ($xp->query('.//text:page-number', $hp)->length) { $p['numbering'] = ['show' => true, 'position' => $k === 'header' ? 'top' : 'bottom']; continue; }
                if (($t = trim($hp->textContent)) !== '') $lines[] = $t;
            }
            if ($lines) $p[$k] = implode("\n", $lines);
        }
        if (!isset($p['numbering'])) $p['numbering'] = ['show' => false];
        $this->page = $p;
    }

    // ---------- בלוקים ----------

    private function blocks(DOMElement $parent, int $depth = 0): array
    {
        $out = [];
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof DOMElement) continue;
            switch ($n->namespaceURI . '#' . $n->localName) {
                case ODF_NS['text'] . '#p':
                case ODF_NS['text'] . '#h':
                    array_push($out, ...$this->para($n));
                    break;
                case ODF_NS['text'] . '#list':
                    $out[] = $this->listNode($n, $n->getAttributeNS(ODF_NS['text'], 'style-name'), 1);
                    break;
                case ODF_NS['table'] . '#table':
                    if ($depth > 0) { $this->note('nested'); break; }
                    $out[] = $this->table($n);
                    break;
                case ODF_NS['text'] . '#section':
                case ODF_NS['text'] . '#index-body':
                    array_push($out, ...$this->blocks($n, $depth));
                    break;
                case ODF_NS['text'] . '#table-of-content':
                case ODF_NS['text'] . '#alphabetical-index':
                    $this->note('fields');
                    foreach ($this->xp->query('text:index-body', $n) as $ib) array_push($out, ...$this->blocks($ib, $depth));
                    break;
            }
        }
        return $out;
    }

    /** פסקה / כותרת → [בלוק] (ומעבר עמוד לפניה, אם הסגנון אומר). */
    private function para(DOMElement $p): array
    {
        $style = $p->getAttributeNS(ODF_NS['text'], 'style-name');
        $attrs = [];
        $type = 'paragraph';
        $level = $p->localName === 'h' ? (int)($p->getAttributeNS(ODF_NS['text'], 'outline-level') ?: 1) : $this->outlineOf($style);
        if ($level > 0) { $type = 'heading'; $attrs['level'] = max(1, min(9, $level)); }
        elseif (preg_match('/^Title$/i', $this->styles[$this->styles[$style]['parent'] ?? '']['display'] ?? $this->styles[$style]['display'] ?? '')) $attrs['pstyle'] = 'title';
        $wm = $this->prop($style, 'para', 'style:writing-mode');
        $rtl = $wm !== null && $wm !== 'page' ? str_starts_with($wm, 'rl') : $this->rtl;
        $al = $this->prop($style, 'para', 'fo:text-align');
        // LibreOffice כותב start / end כשמאל / ימין (גם בפסקה מימין לשמאל: "end" — לימין)
        $align = match ($al) { 'center' => 'center', 'justify' => 'justify', 'start', 'left' => 'left', 'end', 'right' => 'right', default => null };
        if ($align && $align !== ($rtl ? 'right' : 'left')) $attrs['textAlign'] = $align;
        // כמו בייבוא מ-Word: פסקה בלי עברית / ערבית ועם אותיות לטיניות — משמאל לימין
        $txt = $p->textContent;
        if (!preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}]/u', $txt) && (!$rtl || preg_match('/[A-Za-z]/', $txt))) { $attrs['dir'] = 'ltr'; $rtl = false; }
        foreach (['fo:margin-top' => 'spaceBefore', 'fo:margin-bottom' => 'spaceAfter'] as $k => $a) {
            $v = odf_pt($this->own($style, 'para', $k));
            if ($v !== null && $v > 0) $attrs[$a] = round($v, 1);
        }
        $lh = $this->own($style, 'para', 'fo:line-height');
        if ($lh !== null && preg_match('/^([\d.]+)%$/', $lh, $m) && (float)$m[1] !== 100.0) $attrs['lineHeight'] = (string)round((float)$m[1] / 100, 2);
        elseif ($lh !== null && ($v = odf_pt($lh))) $attrs['lineHeight'] = 'exact:' . round($v, 1);
        // LibreOffice: ההזחה בצד ההתחלה — fo:margin-left, גם בפסקה מימין לשמאל
        $start = odf_pt($this->own($style, 'para', 'fo:margin-left') ?? $this->own($style, 'para', 'fo:margin-right'));
        if ($start !== null && ($n = (int)round($start / 36)) > 0) $attrs['indent'] = min(8, $n);
        $fl = odf_pt($this->own($style, 'para', 'fo:text-indent'));
        if ($fl !== null && abs($fl) >= 1) $attrs['firstLine'] = round(max(-200, min(200, $fl)), 1);
        if ($type !== 'heading' && $this->own($style, 'para', 'fo:keep-with-next') === 'always') $attrs['keepNext'] = true;
        $pre = [];
        if (in_array($this->styles[$style]['para']['fo:break-before'] ?? null, ['page'], true)) $pre[] = ['type' => 'pageBreak'];
        $marks = $type === 'heading' ? [] : $this->marksOf($style, true);
        $inline = $this->inline($p, $marks);
        $block = para($inline, $attrs, $type);
        return [...$pre, $block];
    }

    /** הסימונים של סגנון (טקסט) — $paraOnly: רק העיצוב הישיר של הפסקה (סגנון אוטומטי), לא של הסגנון בעל השם. */
    private function marksOf(string $style, bool $paraOnly = false): array
    {
        if ($style === '') return [];
        $get = fn($k) => $paraOnly ? ($this->styles[$style]['text'][$k] ?? null) : $this->prop($style, 'text', $k);
        $m = [];
        if (in_array($get('fo:font-weight'), ['bold', '600', '700', '800', '900'], true)) $m[] = ['type' => 'bold'];
        if (in_array($get('fo:font-style'), ['italic', 'oblique'], true)) $m[] = ['type' => 'italic'];
        $ul = $get('style:text-underline-style');
        if ($ul && $ul !== 'none') $m[] = ['type' => 'underline'];
        $lt = $get('style:text-line-through-style');
        if ($lt && $lt !== 'none') $m[] = ['type' => 'strike'];
        $pos = (string)$get('style:text-position');
        if (preg_match('/^(super|[1-9]\d*%)/', $pos)) $m[] = ['type' => 'superscript'];
        elseif (preg_match('/^(sub|-\d+%)/', $pos)) $m[] = ['type' => 'subscript'];
        $ts = [];
        $c = strtolower((string)$get('fo:color'));
        if (preg_match('/^#[0-9a-f]{6}$/', $c) && $c !== '#000000') $ts['color'] = $c;
        $sz = $get('style:font-size-complex') ?: $get('fo:font-size');
        if ($sz && ($v = odf_pt($sz)) && !str_ends_with($sz, '%')) $ts['fontSize'] = round($v, 1) . 'pt';
        $fn = $get('style:font-name-complex') ?: $get('style:font-name') ?: $get('fo:font-family');
        if ($fn) {
            $fam = font_name($this->fonts[$fn] ?? trim($fn, "'\" "));
            $ts['fontFamily'] = $fam;
            if (!isset(FontSet::FAMILIES[$fam])) $this->missing[$fam] = true;
        }
        if ($ts) $m[] = ['type' => 'textStyle', 'attrs' => $ts];
        $bg = strtolower((string)$get('fo:background-color'));
        if (preg_match('/^#[0-9a-f]{6}$/', $bg) && $bg !== '#ffffff') $m[] = ['type' => 'highlight', 'attrs' => ['color' => $bg]];
        return $m;
    }

    private static function mergeMarks(array $a, array $b): array
    {
        $out = [];
        foreach ([...$a, ...$b] as $m) {
            $k = $m['type'];
            if ($k === 'textStyle' && isset($out[$k])) { $out[$k]['attrs'] = $m['attrs'] + $out[$k]['attrs']; continue; }
            $out[$k] = $m;
        }
        return array_values($out);
    }

    /** התוכן שבשורה: טקסט, רווחים, טאב, שבירת שורה, קישור, תמונה, הערה. */
    private function inline(DOMElement $el, array $marks): array
    {
        $out = [];
        $push = function (string $t, array $mk) use (&$out) {
            if ($t === '') return;
            $last = count($out) - 1;
            if ($last >= 0 && ($out[$last]['type'] ?? '') === 'text' && ($out[$last]['marks'] ?? []) == $mk) $out[$last]['text'] .= $t;
            else $out[] = text_node($t, $mk);
        };
        $walk = function (DOMNode $node, array $mk) use (&$walk, &$out, $push) {
            foreach ($node->childNodes as $c) {
                if ($c instanceof DOMText) { $push(preg_replace('/[\r\n\t]+/', ' ', $c->data), $mk); continue; }
                if (!$c instanceof DOMElement) continue;
                $ns = $c->namespaceURI;
                $ln = $c->localName;
                if ($ns === ODF_NS['text']) {
                    switch ($ln) {
                        case 's': $push(str_repeat(' ', max(1, (int)$c->getAttributeNS(ODF_NS['text'], 'c'))), $mk); break;
                        case 'tab': $push("\t", $mk); break;
                        case 'line-break': $out[] = ['type' => 'hardBreak']; break;
                        case 'span': $walk($c, self::mergeMarks($mk, $this->marksOf($c->getAttributeNS(ODF_NS['text'], 'style-name')))); break;
                        case 'a':
                            $href = $c->getAttributeNS(ODF_NS['xlink'], 'href');
                            $walk($c, safe_href($href) !== null ? [...$mk, ['type' => 'link', 'attrs' => ['href' => safe_href($href)]]] : $mk);
                            break;
                        case 'note':
                            $body = $this->xp->query('text:note-body', $c)->item(0);
                            $text = trim(preg_replace('/\s+/u', ' ', $body ? $body->textContent : ''));
                            $a = ['text' => mb_substr($text, 0, 4000), 'content' => null];
                            if ($c->getAttributeNS(ODF_NS['text'], 'note-class') === 'endnote') $a['end'] = true;
                            $out[] = ['type' => 'footnote', 'attrs' => $a];
                            break;
                        case 'bookmark':
                        case 'bookmark-start':
                            if ($name = bookmark_name($c->getAttributeNS(ODF_NS['text'], 'name'))) $out[] = ['type' => 'bookmark', 'attrs' => ['name' => $name]];
                            break;
                        case 'soft-page-break': case 'bookmark-end': case 'reference-mark-start': case 'reference-mark-end': break;
                        default:
                            // שדה (תאריך, מספר עמוד, הפניה…) — התוצאה שלו כטקסט קבוע
                            if (in_array($ln, ['date', 'time', 'page-number', 'page-count', 'chapter', 'sequence', 'bookmark-ref', 'reference-ref', 'author-name', 'title'], true)) $this->note('fields');
                            $walk($c, $mk);
                    }
                    continue;
                }
                if ($ns === ODF_NS['draw'] && $ln === 'frame') { if ($img = $this->image($c)) $out[] = $img; continue; }
                if ($ns === ODF_NS['draw']) { $this->note('shapes'); continue; }
                $walk($c, $mk);
            }
        };
        $walk($el, $marks);
        return $out;
    }

    private function image(DOMElement $frame): ?array
    {
        $img = $this->xp->query('draw:image', $frame)->item(0);
        if (!$img instanceof DOMElement) {
            // תיבת טקסט / נוסחה / תרשים
            $this->note($this->xp->query('draw:object', $frame)->length ? 'math' : 'shapes');
            return null;
        }
        $href = $img->getAttributeNS(ODF_NS['xlink'], 'href');
        $bytes = $href === '' || str_contains($href, '..') || preg_match('#^[a-z]+:#i', $href) ? null : ($this->read)(ltrim($href, './'));
        if ($bytes === null) { $this->note('images'); return null; }
        try { $m = media_store($this->docId, $bytes); } catch (ApiError $e) { $this->note('images'); return null; }
        $w = odf_pt($frame->getAttributeNS(ODF_NS['svg'], 'width'));
        $h = odf_pt($frame->getAttributeNS(ODF_NS['svg'], 'height'));
        $wpx = $w ? (int)round($w / 0.75) : $m['width'];
        $hpx = $h ? (int)round($h / 0.75) : $m['height'];
        $attrs = ['media' => $m['id'], 'src' => $m['url'], 'width' => max(4, min(2000, $wpx)), 'height' => max(4, min(2000, $hpx))];
        // עיגון לפסקה עם גלישה — ריבוע; כתו — בשורה
        if ($frame->getAttributeNS(ODF_NS['text'], 'anchor-type') !== 'as-char') {
            $wrap = $this->prop($frame->getAttributeNS(ODF_NS['draw'], 'style-name'), 'graphic', 'style:wrap');
            $attrs['wrap'] = in_array($wrap, ['parallel', 'dynamic', 'left', 'right'], true) ? 'square' : 'topBottom';
        }
        return ['type' => 'image', 'attrs' => $attrs];
    }

    // ---------- רשימות וטבלאות ----------

    private function listNode(DOMElement $list, string $style, int $level): array
    {
        $own = $list->getAttributeNS(ODF_NS['text'], 'style-name');
        if ($own !== '') $style = $own;
        // סגנון הרשימה יכול לבוא גם מסגנון הפסקה הראשונה (list-style-name)
        if (!isset($this->lists[$style])) {
            $first = $this->xp->query('text:list-item/text:p|text:list-item/text:h', $list)->item(0);
            if ($first instanceof DOMElement) $style = $this->styles[$first->getAttributeNS(ODF_NS['text'], 'style-name')]['list'] ?? $style;
        }
        $lv = $this->lists[$style][$level] ?? ['type' => 'bullet'];
        $items = [];
        foreach ($this->xp->query('text:list-item|text:list-header', $list) as $li) {
            $content = [];
            foreach ($li->childNodes as $c) {
                if (!$c instanceof DOMElement) continue;
                if ($c->localName === 'list') $content[] = $this->listNode($c, $style, $level + 1);
                elseif ($c->localName === 'p' || $c->localName === 'h') array_push($content, ...array_filter($this->para($c), fn($b) => $b['type'] !== 'pageBreak'));
            }
            $items[] = ['type' => 'listItem', 'content' => $content && ($content[0]['type'] ?? '') !== 'bulletList' && ($content[0]['type'] ?? '') !== 'orderedList' ? $content : [para(), ...$content]];
        }
        $items = $items ?: [['type' => 'listItem', 'content' => [para()]]];
        if ($lv['type'] !== 'number') return ['type' => 'bulletList', 'content' => $items];
        $attrs = [];
        // אצלנו: 1. / א. / i. (אותיות לטיניות — כמו 1.)
        $t = match ($lv['fmt'] ?? '1') { 'i', 'I' => 'roman', 'א' => 'hebrew', default => null };
        if ($t) $attrs['listType'] = $t;
        if (($lv['start'] ?? 1) !== 1) $attrs['start'] = $lv['start'];
        return $attrs ? ['type' => 'orderedList', 'attrs' => $attrs, 'content' => $items] : ['type' => 'orderedList', 'content' => $items];
    }

    private function table(DOMElement $tbl): array
    {
        $rows = [];
        foreach ($this->xp->query('table:table-row|table:table-header-rows/table:table-row|table:table-rows/table:table-row', $tbl) as $tr) {
            $cells = [];
            foreach ($this->xp->query('table:table-cell', $tr) as $tc) {
                $attrs = ['colspan' => max(1, (int)($tc->getAttributeNS(ODF_NS['table'], 'number-columns-spanned') ?: 1)),
                    'rowspan' => max(1, (int)($tc->getAttributeNS(ODF_NS['table'], 'number-rows-spanned') ?: 1))];
                $cs = $tc->getAttributeNS(ODF_NS['table'], 'style-name');
                $bg = strtolower((string)($this->styles[$cs]['cell']['fo:background-color'] ?? ''));
                if (preg_match('/^#[0-9a-f]{6}$/', $bg) && $bg !== '#ffffff') $attrs['background'] = $bg;
                $va = $this->styles[$cs]['cell']['style:vertical-align'] ?? '';
                if ($va === 'middle' || $va === 'bottom') $attrs['valign'] = $va;
                $content = array_values(array_filter($this->blocks($tc, 1), fn($b) => in_array($b['type'], ['paragraph', 'heading', 'bulletList', 'orderedList'], true)));
                $cells[] = ['type' => 'tableCell', 'attrs' => $attrs, 'content' => $content ?: [para()]];
            }
            if ($cells) $rows[] = ['type' => 'tableRow', 'content' => $cells];
        }
        return $rows ? ['type' => 'table', 'content' => $rows] : para();
    }

    // ---------- דוח ----------

    private function note(string $k, int $n = 1): void
    {
        $this->notes[$k] = ($this->notes[$k] ?? 0) + $n;
    }

    private function report(): array
    {
        $out = [];
        foreach (IMPORT_REPORT as $k => [$label, $detail]) {
            $n = $k === 'fonts' ? count($this->missing) : ($this->notes[$k] ?? 0);
            if (!$n) continue;
            if ($k === 'fonts') $detail = implode(', ', array_keys($this->missing)) . ' — ' . $detail;
            $out[] = ['key' => $k, 'label' => $label, 'count' => $n, 'detail' => $detail];
        }
        return $out;
    }
}
