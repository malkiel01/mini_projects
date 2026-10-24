<?php
/**
 * ייצוא ל-ODT (OpenDocument — LibreOffice, Google Docs, Word). שלב 5.
 *  - סגנונות: ברירת המחדל של המסמך (גופן, גודל, עברית, מימין לשמאל), "Standard", כותרות 1–9, כותרת המסמך, כיתוב,
 *    הסגנונות של המשתמש — בשמות ש-LibreOffice מכיר (Heading_20_1…), כך שהם מופיעים ברשימת הסגנונות שלו.
 *  - פסקאות: יישור, כיוון, הזחה ושורה ראשונה, רווחים, ריווח שורות, עצירות טאב, שמירה עם הבאה, מעבר עמוד (fo:break-before).
 *  - תווים: מודגש, נטוי, קווים תחתונים לסוגיהם, קו חוצה, עילי / תחתי, צבע, גודל, גופן, ריווח אותיות, סימון.
 *  - רשימות (גם מקוננות, עם מספר התחלה והמשך מספור), טבלאות (מיזוג תאים, שורת כותרת, רקע, גבולות, רוחב עמודות),
 *    תמונות (Pictures/), קישורים, סימניות, הערות שוליים וסיום, הערות (office:annotation), מעקב שינויים
 *    (text:tracked-changes — LibreOffice מציג ומאפשר לקבל / לדחות), תוכן עניינים (text:table-of-content — אפשר לעדכן).
 *  - הדף: גודל, כיוון, שוליים, עמודות, צבע עמוד, כותרת עליונה / תחתונה (גם לעמוד ראשון / זוגי) ומספר העמוד;
 *    מקטע עם הגדרות אחרות — עמוד-אב (master page) משלו.
 *  - לא נכנס: סימן מים, שינוי עיצוב במעקב (נשאר העיצוב החדש).
 */

const ODT_NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
    . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
    . ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
    . ' xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"'
    . ' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0"'
    . ' office:version="1.3"';
const PX_PER_CM = 37.7953;

function export_odt(array $json, array $page, string $title = ''): string
{
    return (new Odt($page))->package($json, $title);
}

final class Odt
{
    private array $auto = [];        // מפתח המאפיינים => [שם, family, xml] — סגנונות אוטומטיים
    private array $fonts = [];       // הגופנים שבשימוש (office:font-face-decls)
    private array $media = [];       // id => [path, type, bytes]
    private array $changes = [];     // text:changed-region
    private array $masters = [];     // עמודי-אב של מקטעים: [שם => הגדרות העמוד]
    private array $cmData = [];
    private array $cmFirst = [];
    private array $cmLast = [];
    private array $cmOpen = [];
    private int $inlineAt = 0;
    private array $seqList = [];
    private array $seqNums = [];
    private int $seqAt = 0;
    private int $notes = 0;
    private int $listN = 0;
    private int $tocN = 0;
    private bool $breakNext = false;  // מעבר עמוד — בפסקה / בטבלה הבאה
    private ?string $masterNext = null;
    private array $cur;               // הגדרות העמוד של המקטע הנוכחי
    private float $contentPx;

    public function __construct(private array $page)
    {
        $this->setSection($page);
    }

    private function setSection(array $p): void
    {
        $this->cur = $p;
        [$w] = page_mm($p);
        $this->contentPx = ($w - $p['margins']['left'] - $p['margins']['right']) / 10 * PX_PER_CM;
    }

    public function package(array $json, string $title): string
    {
        $json = sections_top($json);
        $this->seqNums = seq_numbers($json);
        $this->seqList = seq_text_list($json);
        $this->commentRanges($json);
        $this->font($this->page['font']['family']);
        $body = '';
        foreach ($json['content'] ?? [] as $n) $body .= $this->block($n);
        if ($body === '') $body = '<text:p text:style-name="Standard"/>';
        $styles = $this->stylesXml();   // כותרות עליונות / תחתונות — לפני הסגנונות האוטומטיים (משתמשות בהם)
        $tracked = $this->changes ? '<text:tracked-changes text:track-changes="' . (!empty($json['attrs']['track']) ? 'true' : 'false') . '">' . implode('', $this->changes) . '</text:tracked-changes>' : '';
        $content = '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . ODT_NS . '>' . $this->fontDecls()
            . '<office:automatic-styles>' . $this->autoXml() . '</office:automatic-styles>'
            . '<office:body><office:text>' . $tracked . $body . '</office:text></office:body></office:document-content>';
        $meta = '<?xml version="1.0" encoding="UTF-8"?><office:document-meta ' . ODT_NS . '><office:meta><meta:generator>עורך התמלילים</meta:generator>'
            . '<dc:title>' . xml($title) . '</dc:title><meta:creation-date>' . gmdate('Y-m-d\TH:i:s') . '</meta:creation-date><dc:language>he-IL</dc:language></office:meta></office:document-meta>';
        $manifest = '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
            . '<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
            . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
            . '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>';
        foreach ($this->media as $m) $manifest .= '<manifest:file-entry manifest:full-path="' . $m['path'] . '" manifest:media-type="' . $m['type'] . '"/>';
        $manifest .= '</manifest:manifest>';

        $tmp = tempnam(sys_get_temp_dir(), 'wpodt');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        // mimetype — ראשון ובלי דחיסה (כך מזהים ODT)
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('content.xml', $content);
        $zip->addFromString('styles.xml', $styles);
        $zip->addFromString('meta.xml', $meta);
        $zip->addFromString('META-INF/manifest.xml', $manifest);
        foreach ($this->media as $m) $zip->addFromString($m['path'], $m['bytes']);
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    // ---------- בלוקים ----------

    /** $list — ['style' => שם סגנון הרשימה] כשבתוך פריט; $cell — בתוך תא (בלי רווח אחרי, כמו בעורך). */
    private function block(array $n, bool $cell = false): string
    {
        $a = $n['attrs'] ?? [];
        switch ($n['type'] ?? '') {
            case 'paragraph':
            case 'heading':
                return $this->para($n, $cell);
            case 'bulletList':
            case 'orderedList':
                return $this->listXml($n, $cell);
            case 'table':
                return $this->table($n);
            case 'pageBreak':
                $this->breakNext = true;
                return '';
            case 'columnBreak':
                return '<text:p text:style-name="' . $this->pstyle('Standard', ['fo:break-before' => 'column']) . '"/>';
            case 'sectionBreak':
                // מקטע חדש: עמוד-אב משלו (גודל, כיוון, שוליים, עמודות, כותרות), מהפסקה הבאה
                $next = page_section($a['page'] ?? null, $this->cur);
                $name = 'MP' . (count($this->masters) + 1);
                $this->masters[$name] = $next;
                $this->setSection($next);
                $this->masterNext = $name;
                $this->breakNext = false;
                return '';
            case 'horizontalRule':
                return '<text:p text:style-name="' . $this->pstyle('Standard', ['fo:border-bottom' => '0.75pt solid #000000', 'fo:padding-bottom' => '1pt', 'fo:margin-bottom' => '6pt']) . '"/>';
            case 'toc':
                return $this->toc($a);
            default:
                return '';
        }
    }

    /** מאפייני פסקה מה-attrs (כמו בעורך): יישור, כיוון, הזחה, רווחים, שורות, טאבים, שבירה. */
    private function paraProps(array $a, bool $cell, int $listIndent = 0): array
    {
        $rtl = ($a['dir'] ?? 'rtl') !== 'ltr';
        $pp = ['style:writing-mode' => $rtl ? 'rl-tb' : 'lr-tb'];
        $al = $a['textAlign'] ?? null;
        if (in_array($al, ['left', 'right', 'center', 'justify'], true)) $pp['fo:text-align'] = $al;
        else $pp['fo:text-align'] = $rtl ? 'right' : 'left';
        if (is_numeric($a['spaceBefore'] ?? null)) $pp['fo:margin-top'] = (float)$a['spaceBefore'] . 'pt';
        if (is_numeric($a['spaceAfter'] ?? null)) $pp['fo:margin-bottom'] = (float)$a['spaceAfter'] . 'pt';
        elseif ($cell) $pp['fo:margin-bottom'] = '0pt';
        if ($l = line_val($a['lineHeight'] ?? null)) $pp['fo:line-height'] = $l[0] === 'exact' ? $l[1] . 'pt' : round($l[1] * 100) . '%';
        $ind = (int)($a['indent'] ?? 0) * 36;
        $fl = is_numeric($a['firstLine'] ?? null) ? max(-200, min(200, (float)$a['firstLine'])) : 0;
        // הזחה בצד ההתחלה: LibreOffice — fo:margin-left גם בפסקה מימין לשמאל (כך הוא כותב וקורא)
        if ($ind || $fl < 0) $pp['fo:margin-left'] = ($ind + ($fl < 0 ? -$fl : 0)) . 'pt';
        if ($fl) $pp['fo:text-indent'] = $fl . 'pt';
        if (!empty($a['keepNext'])) $pp['fo:keep-with-next'] = 'always';
        if (!empty($a['keepLines'])) $pp['fo:keep-together'] = 'always';
        if (!empty($a['breakBefore']) || $this->breakNext) $pp['fo:break-before'] = 'page';
        $this->breakNext = false;
        if ($tabs = tabs_val($a['tabs'] ?? null)) {
            $x = '';
            foreach ($tabs as $t) {
                $type = match ($t['align']) { 'center' => 'center', 'end' => 'right', 'decimal' => 'char', default => 'left' };
                $x .= '<style:tab-stop style:position="' . round($t['pos'] / 72 * 2.54, 3) . 'cm" style:type="' . $type . '"'
                    . ($type === 'char' ? ' style:char="."' : '') . ($t['leader'] !== 'none' ? ' style:leader-style="' . ($t['leader'] === 'dot' ? 'dotted' : 'solid') . '" style:leader-text="' . ($t['leader'] === 'dot' ? '.' : ($t['leader'] === 'hyphen' ? '-' : '_')) . '"' : '') . '/>';
            }
            $pp['_tabs'] = $x;
        }
        return $pp;
    }

    private function para(array $n, bool $cell = false): string
    {
        $a = $n['attrs'] ?? [];
        $key = block_style_key($n, $this->page);
        $pp = $this->paraProps($a, $cell);
        $extra = [];
        if ($this->masterNext) { $extra['style:master-page-name'] = $this->masterNext; $this->masterNext = null; }
        $style = $this->pstyle($this->styleName($key), $pp, $extra);
        $inner = $this->inlines($n['content'] ?? [], $a);
        if ($n['type'] === 'heading') {
            $lvl = max(1, min(9, (int)($a['level'] ?? 1)));
            return '<text:h text:style-name="' . $style . '" text:outline-level="' . $lvl . '">' . $inner . '</text:h>';
        }
        $out = '<text:p text:style-name="' . $style . '">' . $inner . '</text:p>';
        // כיתוב של תמונה — פסקה ממורכזת אחריה (כמו ב-docx)
        foreach ($n['content'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'image' && !empty($c['attrs']['caption'])) {
                $out .= '<text:p text:style-name="' . $this->pstyle('Caption', ['fo:text-align' => 'center']) . '">' . $this->text((string)$c['attrs']['caption']) . '</text:p>';
            }
        }
        return $out;
    }

    /** רשימה: סגנון רשימה אוטומטי אחד לרשימה העליונה — סוג הרמה מהרשימה הראשונה בכל רמה. */
    private function listXml(array $n, bool $cell, int $lvl = 0, ?string $styleName = null): string
    {
        $top = $styleName === null;
        if ($top) {
            $levels = [];
            $walk = function (array $l, int $d) use (&$walk, &$levels) {
                if ($d > 9) return;
                $levels[$d] ??= ['type' => $l['type'] === 'bulletList' ? 'bullet' : (in_array($l['attrs']['listType'] ?? '', ['hebrew', 'roman', 'multi'], true) ? $l['attrs']['listType'] : 'decimal'),
                    'start' => max(1, (int)($l['attrs']['start'] ?? 1))];
                foreach ($l['content'] ?? [] as $li) foreach ($li['content'] ?? [] as $c) if (in_array($c['type'] ?? '', ['bulletList', 'orderedList'], true)) $walk($c, $d + 1);
            };
            $walk($n, 0);
            $styleName = 'L' . (++$this->listN);
            $x = '';
            $multi = $levels[0]['type'] === 'multi';
            $bullets = ['•', '◦', '▪', '•', '◦', '▪', '•', '◦', '▪', '•'];
            for ($i = 0; $i < 10; $i++) {
                $lv = $levels[$i] ?? ($levels[array_key_last($levels)] ?? ['type' => 'bullet', 'start' => 1]);
                $props = '<style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab"'
                    . ' text:list-tab-stop-position="' . round(0.635 * ($i + 1), 3) . 'cm" fo:text-indent="-0.635cm" fo:margin-left="' . round(0.635 * ($i + 1), 3) . 'cm"/></style:list-level-properties>';
                if ($lv['type'] === 'bullet') {
                    $x .= '<text:list-level-style-bullet text:level="' . ($i + 1) . '" text:bullet-char="' . $bullets[$i] . '">' . $props . '</text:list-level-style-bullet>';
                    continue;
                }
                $fmt = ['hebrew' => 'א, ב, ג, ...', 'roman' => 'i'][$lv['type']] ?? '1';
                $disp = $multi || ($lv['type'] === 'multi') ? ' text:display-levels="' . ($i + 1) . '"' : '';
                $x .= '<text:list-level-style-number text:level="' . ($i + 1) . '" style:num-suffix="." style:num-format="' . $fmt . '"' . $disp
                    . ($lv['start'] > 1 ? ' text:start-value="' . $lv['start'] . '"' : '') . '>' . $props . '</text:list-level-style-number>';
            }
            $this->auto['list:' . $styleName] = [$styleName, 'list', '<text:list-style style:name="' . $styleName . '">' . $x . '</text:list-style>'];
        }
        $cont = !empty($n['attrs']['cont']) && $n['type'] === 'orderedList' ? ' text:continue-numbering="true"' : '';
        $out = '<text:list' . ($top ? ' text:style-name="' . $styleName . '"' : '') . $cont . '>';
        foreach ($n['content'] ?? [] as $li) {
            $out .= '<text:list-item>';
            $any = false;
            foreach ($li['content'] ?? [] as $c) {
                if (in_array($c['type'] ?? '', ['bulletList', 'orderedList'], true)) { $out .= $this->listXml($c, $cell, $lvl + 1, $styleName); $any = true; continue; }
                $x = $this->block($c, $cell);
                if ($x !== '') { $out .= $x; $any = true; }
            }
            if (!$any) $out .= '<text:p text:style-name="Standard"/>';
            $out .= '</text:list-item>';
        }
        return $out . '</text:list>';
    }

    private function table(array $t): string
    {
        $rows = $t['content'] ?? [];
        // רשת: כמה עמודות, ואילו מקומות מכוסים בתא ממוזג (שורה / עמודה)
        $covered = [];
        $ncols = 0;
        $layout = [];
        foreach ($rows as $ri => $row) {
            $col = 0;
            $cells = [];
            foreach ($row['content'] ?? [] as $cell) {
                while (isset($covered[$ri][$col])) { $cells[] = null; $col++; }
                $cs = max(1, (int)($cell['attrs']['colspan'] ?? 1));
                $rs = max(1, (int)($cell['attrs']['rowspan'] ?? 1));
                for ($r = 0; $r < $rs; $r++) for ($k = 0; $k < $cs; $k++) if ($r || $k) $covered[$ri + $r][$col + $k] = true;
                $cells[] = ['cell' => $cell, 'col' => $col, 'cs' => $cs, 'rs' => $rs];
                for ($k = 1; $k < $cs; $k++) $cells[] = null;
                $col += $cs;
            }
            while (isset($covered[$ri][$col])) { $cells[] = null; $col++; }
            $ncols = max($ncols, $col);
            $layout[] = $cells;
        }
        if ($ncols === 0) return '';
        $ta = $t['attrs'] ?? [];
        $pct = is_numeric($ta['width'] ?? null) && $ta['width'] >= 10 && $ta['width'] < 100 ? (int)$ta['width'] : 100;
        $target = $this->contentPx * $pct / 100;
        $widths = array_fill(0, $ncols, 0.0);
        foreach ($layout as $cells) foreach ($cells as $c) {
            if ($c && $c['cs'] === 1 && !empty($c['cell']['attrs']['colwidth'][0])) $widths[$c['col']] = (float)$c['cell']['attrs']['colwidth'][0];
        }
        $known = array_sum($widths);
        $unknown = count(array_filter($widths, fn($w) => $w == 0));
        foreach ($widths as $i => $w) if ($w == 0) $widths[$i] = $unknown ? max(40, ($target - $known) / $unknown) : 0;
        $scale = array_sum($widths) > $target ? $target / array_sum($widths) : 1;
        $cm = fn($px) => round($px * $scale / PX_PER_CM, 3) . 'cm';
        $tp = ['style:width' => $cm(array_sum($widths)), 'style:writing-mode' => 'rl-tb',
            'table:align' => $pct < 100 ? match ($ta['align'] ?? 'right') { 'center' => 'center', 'left' => 'left', default => 'right' } : 'margins'];
        if ($this->breakNext) { $tp['fo:break-before'] = 'page'; $this->breakNext = false; }
        $tstyle = $this->autoStyle('table', $tp);
        $out = '<table:table table:style-name="' . $tstyle . '">';
        foreach ($widths as $w) $out .= '<table:table-column table:style-name="' . $this->autoStyle('table-column', ['style:column-width' => $cm($w)]) . '"/>';
        $mode = in_array($ta['borders'] ?? null, ['outer', 'inner', 'none'], true) ? $ta['borders'] : 'all';
        $bc = '#' . strtolower(color_val($ta['borderColor'] ?? null) ?? '808080');
        $line = '0.5pt solid ' . $bc;
        $nrows = count($layout);
        $header = false;
        foreach ($layout as $ri => $cells) {
            $isHeader = ($rows[$ri]['content'][0]['type'] ?? '') === 'tableHeader';
            if ($isHeader && $ri === 0) { $out .= '<table:table-header-rows>'; $header = true; }
            $rh = is_numeric($rows[$ri]['attrs']['height'] ?? null) && $rows[$ri]['attrs']['height'] > 0 ? ['style:min-row-height' => min(400, (float)$rows[$ri]['attrs']['height']) . 'pt'] : [];
            $out .= '<table:table-row' . ($rh ? ' table:style-name="' . $this->autoStyle('table-row', $rh) . '"' : '') . '>';
            foreach ($cells as $ci => $c) {
                if ($c === null) { $out .= '<table:covered-table-cell/>'; continue; }
                $cell = $c['cell'];
                // גבולות לפי מצב הטבלה: כולם / מסגרת / פנימיים / בלי — לכל צד של התא לפי מקומו
                $cp = ['fo:padding-top' => '0.07cm', 'fo:padding-bottom' => '0.07cm', 'fo:padding-left' => '0.14cm', 'fo:padding-right' => '0.14cm'];
                // בטבלה מימין לשמאל LibreOffice מפרש left / right של התא לפי הכיוון: left — צד ההתחלה (העמודה הראשונה)
                $edges = ['top' => $ri === 0, 'bottom' => $ri + $c['rs'] >= $nrows, 'left' => $c['col'] === 0, 'right' => $c['col'] + $c['cs'] >= $ncols];
                foreach ($edges as $side => $outer) {
                    $on = $mode === 'all' || ($mode === 'outer' && $outer) || ($mode === 'inner' && !$outer);
                    $cp["fo:border-$side"] = $on ? $line : 'none';
                }
                if ($bg = color_val($cell['attrs']['background'] ?? null)) $cp['fo:background-color'] = '#' . strtolower($bg);
                elseif ($cell['type'] === 'tableHeader') $cp['fo:background-color'] = '#f2f2f2';
                if (in_array($cell['attrs']['valign'] ?? null, ['middle', 'bottom'], true)) $cp['style:vertical-align'] = $cell['attrs']['valign'];
                $inner = '';
                foreach ($cell['content'] ?? [] as $p) {
                    if ($cell['type'] === 'tableHeader' && ($p['type'] ?? '') === 'paragraph') {
                        $p['content'] = array_map(function ($x) {
                            if (($x['type'] ?? '') === 'text') $x['marks'] = array_merge($x['marks'] ?? [], [['type' => 'bold']]);
                            return $x;
                        }, $p['content'] ?? []);
                    }
                    $inner .= $this->block($p, true);
                }
                $out .= '<table:table-cell table:style-name="' . $this->autoStyle('table-cell', $cp) . '" office:value-type="string"'
                    . ($c['cs'] > 1 ? ' table:number-columns-spanned="' . $c['cs'] . '"' : '') . ($c['rs'] > 1 ? ' table:number-rows-spanned="' . $c['rs'] . '"' : '') . '>'
                    . ($inner !== '' ? $inner : '<text:p text:style-name="Standard"/>') . '</table:table-cell>';
            }
            $out .= '</table:table-row>';
            if ($header && ($ri === $nrows - 1 || ($rows[$ri + 1]['content'][0]['type'] ?? '') !== 'tableHeader')) { $out .= '</table:table-header-rows>'; $header = false; }
        }
        return $out . '</table:table>';
    }

    /** תוכן עניינים / רשימת איורים: אינדקס של LibreOffice (אפשר "לעדכן"), עם השורות השמורות. */
    private function toc(array $a): string
    {
        $entries = is_array($a['entries'] ?? null) ? $a['entries'] : [];
        $tab = '<style:tab-stop style:position="' . round($this->contentPx / PX_PER_CM, 2) . 'cm" style:type="right" style:leader-style="dotted" style:leader-text="."/>';
        $head = '<text:p text:style-name="' . $this->pstyle('Standard', ['fo:margin-bottom' => '6pt'], [], ['fo:font-weight' => 'bold', 'style:font-weight-complex' => 'bold', 'fo:font-size' => round($this->page['font']['size'] * 1.25, 1) . 'pt', 'style:font-size-complex' => round($this->page['font']['size'] * 1.25, 1) . 'pt']) . '">' . $this->text(toc_title($a)) . '</text:p>';
        $lines = '';
        if (!$entries) $lines = '<text:p text:style-name="Standard">' . $this->text(toc_empty($a)) . '</text:p>';
        foreach ($entries as $e) {
            $l = max(1, min(9, (int)($e['l'] ?? 1)));
            // הסגנונות של LibreOffice לתוכן עניינים (Contents N) — עם עצירת הטאב לקצה השורה
            $st = $this->pstyle(empty($a['seq']) ? 'Contents_20_' . $l : 'Standard', ['fo:margin-bottom' => '0pt', 'fo:margin-left' => (min(3, $l - 1) * 14) . 'pt', '_tabs' => $tab], [], $l === 1 ? ['fo:font-weight' => 'bold', 'style:font-weight-complex' => 'bold'] : []);
            $lines .= '<text:p text:style-name="' . $st . '">' . $this->text((string)($e['t'] ?? '')) . '<text:tab/>' . $this->text((string)($e['p'] ?? '')) . '</text:p>';
        }
        $n = ++$this->tocN;
        // רשימת איורים (seq): אינדקס של כיתובים עם התווית — גם אותו אפשר לעדכן ב-LibreOffice
        if (!empty($a['seq'])) {
            return '<text:illustration-index text:name="ILL' . $n . '" text:protected="false"><text:illustration-index-source text:caption-sequence-name="' . xml(seq_label($a['seq'])) . '" text:caption-sequence-format="text">'
                . '<text:index-title-template>' . xml(toc_title($a)) . '</text:index-title-template></text:illustration-index-source>'
                . '<text:index-body><text:index-title text:name="ILL' . $n . '_Head">' . $head . '</text:index-title>' . $lines . '</text:index-body></text:illustration-index>';
        }
        $levels = max(1, min(9, (int)($a['levels'] ?? 3)));
        return '<text:table-of-content text:name="TOC' . $n . '" text:protected="false"><text:table-of-content-source text:outline-level="' . $levels . '">'
            . '<text:index-title-template>' . xml(toc_title($a)) . '</text:index-title-template></text:table-of-content-source>'
            . '<text:index-body><text:index-title text:name="TOC' . $n . '_Head">' . $head . '</text:index-title>' . $lines . '</text:index-body></text:table-of-content>';
    }

    // ---------- בתוך פסקה ----------

    private function inlines(array $content, array $pa): string
    {
        $out = '';
        $openLink = null;
        foreach ($content as $c) {
            $k = $this->inlineAt++;
            $href = ($c['type'] ?? '') === 'text' ? link_href($c['marks'] ?? []) : null;
            if ($href !== $openLink) {
                if ($openLink !== null) $out .= '</text:a>';
                if ($href !== null) $out .= '<text:a xlink:type="simple" xlink:href="' . xml($href) . '">';
                $openLink = $href;
            }
            // הערות: תחילת הטווח לפני הצומת, וסופו אחריו (מחוץ לקישור — LibreOffice לא אוהב חפיפה)
            $starts = $this->commentStarts($k);
            if ($starts !== '' && $openLink !== null) { $out .= '</text:a>'; $openLink = null; $starts .= $href !== null ? '<text:a xlink:type="simple" xlink:href="' . xml($href) . '">' : ''; $openLink = $href; }
            $out .= $starts . $this->inline($c, $pa);
            if (($ends = $this->commentEnds($k)) !== '') {
                if ($openLink !== null) { $out .= '</text:a>'; $openLink = null; }
                $out .= $ends;
            }
        }
        if ($openLink !== null) $out .= '</text:a>';
        return $out;
    }

    private function inline(array $c, array $pa): string
    {
        $a = $c['attrs'] ?? [];
        switch ($c['type'] ?? '') {
            case 'text':
                $marks = $c['marks'] ?? [];
                $t = $this->text((string)($c['text'] ?? ''));
                $tp = $this->textProps($marks, $pa);
                $span = $tp ? '<text:span text:style-name="' . $this->autoStyle('text', $tp) . '">' . $t . '</text:span>' : $t;
                // מעקב שינויים: הוספה — בין change-start ל-change-end; מחיקה — הטקסט עצמו בתוך ה-changed-region
                foreach ($marks as $m) {
                    $mt = $m['type'] ?? '';
                    if ($mt !== 'ins' && $mt !== 'del') continue;
                    $id = 'ct' . (count($this->changes) + 1);
                    $info = '<office:change-info><dc:creator>' . xml(mb_substr((string)($m['attrs']['author'] ?? ''), 0, 120)) . '</dc:creator><dc:date>'
                        . (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)Z$/', (string)($m['attrs']['date'] ?? ''), $d) ? $d[1] : gmdate('Y-m-d\TH:i:s')) . '</dc:date></office:change-info>';
                    if ($mt === 'ins') {
                        $this->changes[] = '<text:changed-region xml:id="' . $id . '" text:id="' . $id . '"><text:insertion>' . $info . '</text:insertion></text:changed-region>';
                        return '<text:change-start text:change-id="' . $id . '"/>' . $span . '<text:change-end text:change-id="' . $id . '"/>';
                    }
                    $this->changes[] = '<text:changed-region xml:id="' . $id . '" text:id="' . $id . '"><text:deletion>' . $info . '<text:p>' . $span . '</text:p></text:deletion></text:changed-region>';
                    return '<text:change text:change-id="' . $id . '"/>';
                }
                return $span;
            case 'hardBreak':
                return '<text:line-break/>';
            case 'bookmark':
                return ($b = bookmark_name($a['name'] ?? '')) ? '<text:bookmark text:name="' . xml($b) . '"/>' : '';
            case 'image':
                return $this->image($c);
            case 'seq':
                $label = seq_label($a['label'] ?? '');
                $v = $this->seqList[$this->seqAt++] ?? '1';
                return '<text:sequence text:name="' . xml($label) . '" text:formula="ooow:' . xml($label) . '+1" style:num-format="1">' . xml((string)$v) . '</text:sequence>';
            case 'xref':
                $t = $this->seqNums[seq_id($a['target'] ?? '') ?? ''] ?? null;
                if (!$t) return $this->text(XREF_MISSING);
                return $this->text((($a['show'] ?? 'full') === 'num' ? '' : $t['label'] . ' ') . $t['n']);
            case 'footnote':
                $id = 'ftn' . (++$this->notes);
                $end = !empty($a['end']);
                $inner = '';
                foreach (fn_inline($a) as $t) {
                    $tp = $this->textProps($t['marks'] ?? [], []);
                    $inner .= $tp ? '<text:span text:style-name="' . $this->autoStyle('text', $tp) . '">' . $this->text($t['text']) . '</text:span>' : $this->text($t['text']);
                }
                return '<text:note text:id="' . $id . '" text:note-class="' . ($end ? 'endnote' : 'footnote') . '"><text:note-citation>' . ($end ? roman_lower($this->notes) : $this->notes) . '</text:note-citation>'
                    . '<text:note-body><text:p text:style-name="' . ($end ? 'Endnote' : 'Footnote') . '">' . $inner . '</text:p></text:note-body></text:note>';
            default:
                return '';
        }
    }

    /** טקסט: רווחים רצופים (text:s), טאבים (text:tab) ומעברי שורה — ODF מכווץ רווחים אחרת. */
    private function text(string $s): string
    {
        $out = '';
        foreach (preg_split('/(\t|\n| {2,}|^ | $)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $p) {
            if ($p === "\t") $out .= '<text:tab/>';
            elseif ($p === "\n") $out .= '<text:line-break/>';
            elseif (trim($p, ' ') === '') $out .= strlen($p) === 1 ? '<text:s/>' : '<text:s text:c="' . strlen($p) . '"/>';
            else $out .= xml($p);
        }
        return $out;
    }

    private function textProps(array $marks, array $pa): array
    {
        $m = [];
        foreach ($marks as $mk) $m[$mk['type'] ?? ''] = $mk['attrs'] ?? [];
        $tp = [];
        $ts = $m['textStyle'] ?? [];
        if (!empty($ts['fontFamily'])) {
            $f = font_name((string)$ts['fontFamily']);
            $this->font($f);
            $tp['style:font-name'] = $f;
            $tp['style:font-name-complex'] = $f;
        }
        if (isset($m['bold'])) $tp += ['fo:font-weight' => 'bold', 'style:font-weight-complex' => 'bold'];
        if (isset($m['italic'])) $tp += ['fo:font-style' => 'italic', 'style:font-style-complex' => 'italic'];
        if (isset($m['underline'])) {
            $ul = $m['underline']['ul'] ?? '';
            $tp['style:text-underline-style'] = ['dotted' => 'dotted', 'dashed' => 'dash', 'wavy' => 'wave'][$ul] ?? 'solid';
            $tp['style:text-underline-type'] = $ul === 'double' ? 'double' : 'single';
            $tp['style:text-underline-width'] = $ul === 'thick' ? 'bold' : 'auto';
            $tp['style:text-underline-color'] = 'font-color';
        }
        if (isset($m['strike'])) $tp += ['style:text-line-through-style' => 'solid', 'style:text-line-through-type' => 'single'];
        if (isset($m['superscript'])) $tp['style:text-position'] = 'super 58%';
        elseif (isset($m['subscript'])) $tp['style:text-position'] = 'sub 58%';
        if (($c = color_val($ts['color'] ?? null))) $tp['fo:color'] = '#' . strtolower($c);
        if (($z = pt_val($ts['fontSize'] ?? null))) $tp += ['fo:font-size' => $z . 'pt', 'style:font-size-complex' => $z . 'pt'];
        $ls = pt_val($ts['letterSpacing'] ?? null) ?? (is_numeric($pa['letterSpacing'] ?? null) ? (float)$pa['letterSpacing'] : null);
        if ($ls) $tp['fo:letter-spacing'] = $ls . 'pt';
        $bg = color_val($m['highlight']['color'] ?? null) ?? (isset($m['highlight']) ? 'FFFF00' : color_val($ts['backgroundColor'] ?? null));
        if ($bg) $tp['fo:background-color'] = '#' . strtolower($bg);
        // קישור: כחול עם קו תחתון, כמו בעורך (LibreOffice מוסיף "Internet Link" רק לקישור שנוצר אצלו)
        if (isset($m['link']) && !isset($tp['fo:color'])) $tp['fo:color'] = '#0563c1';
        if (isset($m['link']) && !isset($m['underline'])) $tp += ['style:text-underline-style' => 'solid', 'style:text-underline-type' => 'single', 'style:text-underline-width' => 'auto', 'style:text-underline-color' => 'font-color'];
        return $tp;
    }

    private function image(array $c): string
    {
        $a = $c['attrs'] ?? [];
        $m = !empty($a['media']) ? media_row((string)$a['media']) : null;
        if (!$m || !is_file(media_path($m['id'], $m['type']))) return '';
        if (!isset($this->media[$m['id']])) {
            $this->media[$m['id']] = ['path' => 'Pictures/' . $m['id'] . '.' . MEDIA_TYPES[$m['type']], 'type' => $m['type'], 'bytes' => file_get_contents(media_path($m['id'], $m['type']))];
        }
        $g = image_geom($a, $m, $this->contentPx);
        $cm = fn($px) => round($px / PX_PER_CM, 3) . 'cm';
        $gp = [];
        $anchor = 'as-char';
        if ($g['wrap'] !== 'inline') {
            $anchor = 'paragraph';
            $gp['style:wrap'] = match ($g['wrap']) { 'square', 'tight' => 'parallel', 'topBottom' => 'none', default => 'run-through' };
            if ($g['wrap'] === 'behind') $gp['style:run-through'] = 'background';
            $gp['style:horizontal-pos'] = in_array($g['wrap'], ['behind', 'front'], true) ? 'from-left' : ['right' => 'right', 'center' => 'center', 'left' => 'left'][$g['align']];
            $gp['style:horizontal-rel'] = ($a['anchor'] ?? null) === 'page' ? 'page' : 'paragraph';
            $gp['style:vertical-pos'] = 'from-top';
            $gp['style:vertical-rel'] = ($a['anchor'] ?? null) === 'page' ? 'page' : 'paragraph';
            $d = round($g['dist'], 1) . 'pt';
            $gp += ['fo:margin-left' => $d, 'fo:margin-right' => $d, 'fo:margin-top' => $g['wrap'] === 'topBottom' ? $d : '0pt', 'fo:margin-bottom' => $g['wrap'] === 'topBottom' ? $d : '0pt'];
        } else {
            $gp += ['style:vertical-pos' => 'top', 'style:vertical-rel' => 'baseline'];
        }
        if (array_sum($g['crop']) > 0) {
            // fo:clip — מהקצוות של התמונה המקורית (בגודל שלה בעורך)
            $gp['fo:clip'] = 'rect(' . $cm($g['h'] * $g['crop']['t'] / 100) . ', ' . $cm($g['w'] * $g['crop']['r'] / 100) . ', ' . $cm($g['h'] * $g['crop']['b'] / 100) . ', ' . $cm($g['w'] * $g['crop']['l'] / 100) . ')';
        }
        $pos = $anchor === 'paragraph' && in_array($g['wrap'], ['behind', 'front'], true) ? ' svg:x="' . $cm((float)($a['x'] ?? 0)) . '" svg:y="' . $cm((float)($a['y'] ?? 0)) . '"' : '';
        $rot = $g['rot'] ? ' draw:transform="rotate(' . round(deg2rad(360 - $g['rot']), 6) . ')"' : '';
        return '<draw:frame draw:style-name="' . $this->autoStyle('graphic', $gp) . '" draw:name="img' . $this->inlineAt . '" text:anchor-type="' . $anchor . '"'
            . ' svg:width="' . $cm($g['cw']) . '" svg:height="' . $cm($g['ch']) . '"' . $pos . $rot . ' draw:z-index="' . (50 + max(-50, min(50, (int)($a['z'] ?? 0)))) . '">'
            . '<draw:image xlink:href="' . $this->media[$m['id']]['path'] . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>'
            . (($alt = trim((string)($a['alt'] ?? ''))) !== '' ? '<svg:desc>' . xml($alt) . '</svg:desc>' : '') . '</draw:frame>';
    }

    // ---------- הערות ----------

    private function commentRanges(array $json): void
    {
        $this->cmData = is_array($json['attrs']['comments'] ?? null) ? $json['attrs']['comments'] : [];
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

    /** הערה ותשובות: office:annotation לכל אחת (עם השם והתאריך), על אותו טווח; תשובה — loext:parent-name. */
    private function commentStarts(int $k): string
    {
        $out = '';
        foreach ($this->cmFirst as $id => $f) {
            if ($f !== $k) continue;
            $c = $this->cmData[$id];
            $all = [[$c, null]];
            foreach (is_array($c['replies'] ?? null) ? $c['replies'] : [] as $r) if (is_array($r)) $all[] = [$r, 'cm' . count($this->cmOpen)];
            $root = null;
            foreach ($all as [$x, $parent]) {
                $name = 'cm' . count($this->cmOpen);
                $this->cmOpen[$name] = $id;
                $root ??= $name;
                $date = preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)Z$/', (string)($x['date'] ?? ''), $d) ? $d[1] : gmdate('Y-m-d\TH:i:s');
                $paras = '';
                foreach (preg_split('/\R/u', (string)($x['text'] ?? '')) as $line) $paras .= '<text:p>' . $this->text($line) . '</text:p>';
                $out .= '<office:annotation office:name="' . $name . '"' . ($parent !== null ? ' loext:parent-name="' . $root . '"' : '') . (!empty($c['done']) ? ' loext:resolved="true"' : '') . '>'
                    . '<dc:creator>' . xml(mb_substr(trim((string)($x['author'] ?? '')), 0, 120)) . '</dc:creator><dc:date>' . $date . '</dc:date>' . $paras . '</office:annotation>';
            }
        }
        return $out;
    }

    private function commentEnds(int $k): string
    {
        $out = '';
        foreach ($this->cmLast as $id => $l) {
            if ($l !== $k) continue;
            foreach ($this->cmOpen as $name => $cid) if ($cid === $id) $out .= '<office:annotation-end office:name="' . $name . '"/>';
        }
        return $out;
    }

    // ---------- סגנונות ----------

    private function styleName(string $key): string
    {
        if ($key === 'normal') return 'Standard';
        if ($key === 'title') return 'Title';
        if ($key === 'caption') return 'Caption';
        if ($key[0] === 'h' && ctype_digit(substr($key, 1))) return 'Heading_20_' . substr($key, 1);
        return 'User_20_' . substr($key, 1);
    }

    /** סגנון פסקה אוטומטי (מבוסס על $parent) — אותם מאפיינים, אותו שם. */
    private function pstyle(string $parent, array $pp, array $extra = [], array $tp = []): string
    {
        return $this->autoStyle('paragraph', $pp, $parent, $extra, $tp);
    }

    private function autoStyle(string $family, array $props, string $parent = '', array $extra = [], array $tp = []): string
    {
        $key = $family . '|' . $parent . '|' . json_encode([$props, $extra, $tp]);
        if (isset($this->auto[$key])) return $this->auto[$key][0];
        $prefix = ['paragraph' => 'P', 'text' => 'T', 'table' => 'Tb', 'table-column' => 'Co', 'table-row' => 'Ro', 'table-cell' => 'Ce', 'graphic' => 'Fr'][$family];
        $name = $prefix . (count(array_filter($this->auto, fn($s) => $s[1] === $family)) + 1);
        $tabs = $props['_tabs'] ?? '';
        unset($props['_tabs']);
        $attr = fn(array $p) => implode('', array_map(fn($k, $v) => ' ' . $k . '="' . xml((string)$v) . '"', array_keys($p), $p));
        $tag = ['paragraph' => 'paragraph', 'text' => 'text', 'table' => 'table', 'table-column' => 'table-column', 'table-row' => 'table-row', 'table-cell' => 'table-cell', 'graphic' => 'graphic'][$family];
        $inner = $family === 'text' ? '<style:text-properties' . $attr($props) . '/>'
            : '<style:' . $tag . '-properties' . $attr($props) . ($tabs !== '' ? '><style:tab-stops>' . $tabs . '</style:tab-stops></style:' . $tag . '-properties>' : '/>')
              . ($tp ? '<style:text-properties' . $attr($tp) . '/>' : '');
        $this->auto[$key] = [$name, $family, '<style:style style:name="' . $name . '" style:family="' . $family . '"' . ($parent !== '' ? ' style:parent-style-name="' . $parent . '"' : '') . $attr($extra) . '>' . $inner . '</style:style>'];
        return $name;
    }

    private function autoXml(): string
    {
        $out = '';
        foreach ($this->auto as $s) $out .= $s[2];
        return $out;
    }

    private function font(string $f): void
    {
        $this->fonts[$f] = true;
    }

    private function fontDecls(): string
    {
        $out = '<office:font-face-decls>';
        foreach (array_keys($this->fonts) as $f) $out .= '<style:font-face style:name="' . xml($f) . '" svg:font-family="\'' . xml($f) . '\'"/>';
        return $out . '</office:font-face-decls>';
    }

    /** styles.xml: ברירת מחדל, סגנונות פסקה, הערות שוליים, פריסת הדף ועמודי-האב (עם הכותרות). */
    private function stylesXml(): string
    {
        $pg = $this->page;
        $f = xml($pg['font']['family']);
        $sz = $pg['font']['size'] . 'pt';
        $s = '<style:default-style style:family="paragraph"><style:paragraph-properties style:writing-mode="rl-tb" fo:text-align="right" style:tab-stop-distance="1.27cm"/>'
            . '<style:text-properties style:font-name="' . $f . '" style:font-name-complex="' . $f . '" fo:font-size="' . $sz . '" style:font-size-complex="' . $sz . '"'
            . ' fo:language="he" fo:country="IL" style:language-complex="he" style:country-complex="IL"/></style:default-style>'
            . '<style:default-style style:family="table"><style:table-properties style:writing-mode="rl-tb"/></style:default-style>';
        $names = ['normal' => ['Standard', 'Standard'], 'title' => ['Title', 'Title'], 'caption' => ['Caption', 'Caption']];
        for ($l = 1; $l <= 9; $l++) $names["h$l"] = ["Heading_20_$l", "Heading $l"];
        foreach ($pg['styles'] as $k => $st) if (preg_match(USER_STYLE_RE, (string)$k)) $names[$k] = [$this->styleName((string)$k), style_name((string)($st['name'] ?? $k)) ?: (string)$k];
        foreach ($names as $k => [$name, $display]) {
            $st = $pg['styles'][$k] ?? [];
            $pp = [];
            if (isset($st['before'])) $pp['fo:margin-top'] = $st['before'] . 'pt';
            if (isset($st['after'])) $pp['fo:margin-bottom'] = $st['after'] . 'pt';
            if (isset($st['line'])) $pp['fo:line-height'] = round($st['line'] * 100) . '%';
            if (!empty($st['align'])) $pp['fo:text-align'] = $st['align'];
            if ($k[0] === 'h' || $k === 'title') $pp['fo:keep-with-next'] = 'always';
            $tp = [];
            if (!empty($st['font'])) { $this->font($st['font']); $tp['style:font-name'] = $st['font']; $tp['style:font-name-complex'] = $st['font']; }
            if (isset($st['size'])) $tp += ['fo:font-size' => $st['size'] . 'pt', 'style:font-size-complex' => $st['size'] . 'pt'];
            $b = !empty($st['bold']) ? 'bold' : 'normal';
            if ($k !== 'normal' || !empty($st['bold'])) $tp += ['fo:font-weight' => $b, 'style:font-weight-complex' => $b];
            if (!empty($st['italic'])) $tp += ['fo:font-style' => 'italic', 'style:font-style-complex' => 'italic'];
            if (!empty($st['color'])) $tp['fo:color'] = strtolower($st['color']);
            if (!empty($st['underline'])) $tp += ['style:text-underline-style' => 'solid', 'style:text-underline-width' => 'auto', 'style:text-underline-color' => 'font-color'];
            $attr = fn(array $p) => implode('', array_map(fn($a, $v) => ' ' . $a . '="' . xml((string)$v) . '"', array_keys($p), $p));
            $head = $k[0] === 'h' && ctype_digit(substr($k, 1));
            $s .= '<style:style style:name="' . $name . '" style:display-name="' . xml($display) . '" style:family="paragraph"'
                . ($k !== 'normal' ? ' style:parent-style-name="Standard" style:next-style-name="Standard"' : '')
                . ($head ? ' style:default-outline-level="' . substr($k, 1) . '"' : '') . ' style:class="' . ($head ? 'text' : 'text') . '">'
                . ($pp ? '<style:paragraph-properties' . $attr($pp) . '/>' : '') . ($tp ? '<style:text-properties' . $attr($tp) . '/>' : '') . '</style:style>';
        }
        for ($l = 1; $l <= 9; $l++) $s .= '<style:style style:name="Contents_20_' . $l . '" style:display-name="Contents ' . $l . '" style:family="paragraph" style:parent-style-name="Standard" style:class="index"/>';
        foreach (['Footnote' => 'Footnote', 'Endnote' => 'Endnote'] as $n => $d) {
            $s .= '<style:style style:name="' . $n . '" style:display-name="' . $d . '" style:family="paragraph" style:parent-style-name="Standard" style:class="extra">'
                . '<style:paragraph-properties fo:margin-left="0.6cm" fo:margin-top="0pt" fo:margin-bottom="0pt" fo:text-indent="-0.6cm"/><style:text-properties fo:font-size="10pt" style:font-size-complex="10pt"/></style:style>';
        }
        // מספור כותרות (1. / 1.1): סגנון המתאר של LibreOffice
        $outline = '<text:outline-style style:name="Outline">';
        for ($l = 1; $l <= 10; $l++) {
            $outline .= '<text:outline-level-style text:level="' . $l . '" style:num-format="' . (!empty($pg['heading_numbers']) ? '1' : '') . '"' . (!empty($pg['heading_numbers']) ? ' text:display-levels="' . $l . '"' . ($l === 1 ? ' style:num-suffix="."' : '') : '') . '>'
                . '<style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="space"/></style:list-level-properties></text:outline-level-style>';
        }
        $s .= $outline . '</text:outline-style>'
            . '<text:notes-configuration text:note-class="footnote" style:num-format="1" text:start-value="0" text:footnotes-position="page" text:start-numbering-at="document"/>'
            . '<text:notes-configuration text:note-class="endnote" style:num-format="i" text:start-value="0"/>';

        // פריסות ועמודי-אב: המסמך (Standard) וכל מקטע
        $layouts = '';
        $masters = '';
        $all = ['Standard' => $pg] + $this->masters;
        $i = 0;
        foreach ($all as $mname => $p) {
            $i++;
            [$wmm, $hmm] = page_mm($p);
            $mg = $p['margins'];
            $nb = $p['numbering'];
            $hf = function (string $tag, $text, bool $num) use ($nb) {
                $out = '';
                if (is_array($text)) foreach ($text as $b) $out .= $this->block($b);
                elseif ($text !== '') foreach (preg_split('/\R/u', $text) as $line) $out .= '<text:p text:style-name="' . $this->hfStyle('center') . '">' . $this->text($line) . '</text:p>';
                if ($num) $out .= '<text:p text:style-name="' . $this->hfStyle($nb['align']) . '">' . $this->pageNumber($nb['format']) . '</text:p>';
                return $out === '' ? '' : "<style:$tag>$out</style:$tag>";
            };
            $top = $nb['show'] && $nb['position'] === 'top';
            $bottom = $nb['show'] && $nb['position'] === 'bottom';
            $head = $hf('header', $p['header'], $top) . ($pg['even_odd'] ? $hf('header-left', $p['header_even'], $top) : '')
                . (!empty($p['first_diff']) ? $hf('header-first', $p['header_first'], $top) : '');
            $foot = $hf('footer', $p['footer'], $bottom) . ($pg['even_odd'] ? $hf('footer-left', $p['footer_even'], $bottom) : '')
                . (!empty($p['first_diff']) ? $hf('footer-first', $p['footer_first'], $bottom) : '');
            // כותרת יושבת בתוך השוליים (כמו ב-Word): עד חצי מהשוליים (12.5 מ"מ לכל היותר) מהקצה, ומשם — הכותרת והמרווח עד
            // הטקסט (fo:min-height של LibreOffice כולל את המרווח), כך שהטקסט מתחיל בדיוק בשוליים שנקבעו. בלי כותרת — כמו שהם
            $edge = fn(string $k, bool $has) => $has ? min(12.5, $mg[$k] / 2) : $mg[$k];
            $hfp = fn(string $k, string $gapSide) => '<style:header-footer-properties fo:min-height="' . max(0, $mg[$k] - $edge($k, true)) . 'mm" fo:margin-' . $gapSide . '="2.5mm" style:dynamic-spacing="false"/>';
            $lp = ' fo:page-width="' . $wmm . 'mm" fo:page-height="' . $hmm . 'mm" style:print-orientation="' . $p['orientation'] . '"'
                . ' fo:margin-top="' . $edge('top', $head !== '') . 'mm" fo:margin-bottom="' . $edge('bottom', $foot !== '') . 'mm" fo:margin-left="' . $mg['left'] . 'mm" fo:margin-right="' . $mg['right'] . 'mm"'
                . ' style:writing-mode="rl-tb" style:num-format="' . ($nb['format'] === 'hebrew' ? 'א, ב, ג, ...' : '1') . '"'
                . (($pc = color_val($pg['page_color'] ?? null)) ? ' fo:background-color="#' . strtolower($pc) . '"' : '');
            $cols = ($c = $p['columns'] ?? null) && $c['n'] > 1 ? '<style:columns fo:column-count="' . $c['n'] . '" fo:column-gap="' . $c['gap'] . 'mm">'
                . ($c['line'] ? '<style:column-sep style:width="0.5pt" style:color="#000000" style:height="100%"/>' : '') . '</style:columns>' : '';
            $layouts .= '<style:page-layout style:name="pm' . $i . '"><style:page-layout-properties' . $lp . '>' . $cols . '</style:page-layout-properties>'
                . '<style:header-style>' . ($head !== '' ? $hfp('top', 'bottom') : '') . '</style:header-style><style:footer-style>' . ($foot !== '' ? $hfp('bottom', 'top') : '') . '</style:footer-style></style:page-layout>';
            $x = $head . $foot;
            $masters .= '<style:master-page style:name="' . $mname . '" style:page-layout-name="pm' . $i . '">' . $x . '</style:master-page>';
        }
        // הסגנונות האוטומטיים — גם כאן: כותרת עשירה (פסקאות, תמונה) משתמשת בהם, ו-styles.xml לא רואה את אלה של content.xml
        return '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . ODT_NS . '>' . $this->fontDecls() . '<office:styles>' . $s . '</office:styles>'
            . '<office:automatic-styles>' . $layouts . $this->hfAuto() . $this->autoXml() . '</office:automatic-styles><office:master-styles>' . $masters . '</office:master-styles></office:document-styles>';
    }

    private array $hf = [];

    /** סגנון פסקה של כותרת (ב-styles.xml): ממורכז / בצד, 9 נק' — כמו ב-docx. */
    private function hfStyle(string $align): string
    {
        $al = ['right' => 'right', 'left' => 'left'][$align] ?? 'center';
        $name = 'H' . ucfirst($al);
        $this->hf[$name] = '<style:style style:name="' . $name . '" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="' . $al . '" fo:margin-bottom="0pt"/>'
            . '<style:text-properties fo:font-size="9pt" style:font-size-complex="9pt"/></style:style>';
        return $name;
    }

    private function hfAuto(): string
    {
        return implode('', $this->hf);
    }

    private function pageNumber(string $format): string
    {
        $n = '<text:page-number text:select-page="current">1</text:page-number>';
        return match ($format) {
            'page_n' => xml("עמ' ") . $n,
            'n_of_m' => $n . xml(' מתוך ') . '<text:page-count>1</text:page-count>',
            'dash_n' => '- ' . $n . ' -',
            default => $n,
        };
    }
}
