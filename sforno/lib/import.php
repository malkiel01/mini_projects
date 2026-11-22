<?php
/**
 * ייבוא לטיוטה (אפיון 03): HTML, Word (docx), EPUB, PDF וסריקות.
 *
 *   העלאה (import-upload) → בדיקת קובץ: סיומת + חתימה + מגבלות → המקור נשמר ב-data/sources/<pid>/original.<ext>
 *   → עיבוד במשימת רקע ('import.process', 01) → ready: HTML נקי (sf_mk_clean של 02) + עץ מקטעים + אזהרות
 *   → מיפוי ותיקון (import-map) → import-apply: טיוטה חדשה במהדורה. **אין פרסום אוטומטי, ו-public_allowed לא משתנה.**
 *
 * PDF: אין בשרת כלי PDF מובטח — הדפדפן של המנהל מחלץ את הטקסט (pdf.js, assets/vendor/pdfjs) ושולח אותו
 * (import-pdf-text). מעט טקסט מדי ← pdf_scan: עמודים בלבד, בלי טקסט ובלי חיפוש.
 *
 * הגנות: zip — עד 2,000 רשומות, עד 200MB אחרי פריסה (מוצהר וגם בפועל, בקריאה מוגבלת), בלי נתיבים יוצאים. **לא
 * פורסים שום רשומה לדיסק לפי השם שלה** — תמונה נכתבת בשם שנוצר כאן. XML — בלי ישויות (ENTITY נדחה, DOCTYPE מוסר),
 * בלי רשת. תמונות — רק png/jpeg/webp, מפוענחות ומקודדות מחדש (GD): מוקטנות, ובלי EXIF.
 * ביומן הפעולות: import.upload / import.done / import.fail — בלי שם הקובץ.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/markup.php';
require_once __DIR__ . '/content.php';

const SF_IMPORT_MAX = 50 << 20;              // 50MB לקובץ (או מגבלת השרת, הנמוך)
const SF_ZIP_MAX_ENTRIES = 2000;
const SF_ZIP_MAX_TOTAL = 200 << 20;          // אחרי פריסה
const SF_ZIP_MAX_XML = 20 << 20;             // קובץ XML / XHTML אחד בתוך ה-zip (זיכרון ה-DOM)
const SF_IMG_MAX_BYTES = 15 << 20;
const SF_IMG_MAX_PIXELS = 40_000_000;
const SF_IMG_MAX_W = 1400;
const SF_IMPORT_MAX_SEC = 60;
const SF_IMPORT_MAX_IMAGES = 500;
const SF_PDF_MAX_PAGES = 2000;
/** סיומת → סוג. */
const SF_IMPORT_EXT = ['html' => 'html', 'htm' => 'html', 'docx' => 'docx', 'epub' => 'epub', 'pdf' => 'pdf',
    'png' => 'image', 'jpg' => 'image', 'jpeg' => 'image', 'webp' => 'image'];

final class SfImportFail extends RuntimeException {}

function sf_imp_fail(string $msg): never
{
    throw new SfImportFail($msg);
}

// ── הנתיבים ──────────────────────────────────────────────────

function sf_sources_dir(): string
{
    return sf_data_dir() . '/sources';
}

function sf_imp_dir(string $pid): string
{
    if (!sf_is_pid($pid)) throw new SfError('bad_input');
    return sf_sources_dir() . '/' . $pid;
}

function sf_imp_mkdir(string $d): void
{
    if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) throw new SfError('storage', 'לא ניתן לשמור את הקובץ בשרת');
}

/** הגודל המרבי שמותר להעלות: 50MB, או מגבלת השרת (upload_max_filesize / post_max_size), הנמוך. */
function sf_import_limit(): int
{
    $max = SF_IMPORT_MAX;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $v = function_exists('sf_ini_bytes') ? sf_ini_bytes((string)ini_get($k)) : 0;
        if ($v > 0) $max = min($max, $v);
    }
    return $max;
}

function sf_import_limits(): array
{
    return ['max' => sf_import_limit(), 'exts' => array_keys(SF_IMPORT_EXT), 'zip' => class_exists('ZipArchive'), 'images' => extension_loaded('gd')];
}

// ── zip ──────────────────────────────────────────────────────

/** שם רשומה בטוח: יחסי, בלי '..', בלי '\', בלי כונן ובלי תו NUL. */
function sf_zip_name_ok(string $n): bool
{
    if ($n === '' || str_contains($n, "\0") || str_contains($n, '\\') || $n[0] === '/' || preg_match('~^[A-Za-z]:~', $n)) return false;
    foreach (explode('/', $n) as $seg) if ($seg === '..') return false;
    return true;
}

/** פותח ובודק: מספר רשומות, גודל מוצהר אחרי פריסה, שמות. נכשל — SfImportFail עם סיבה. */
function sf_zip_open(string $file): ZipArchive
{
    if (!class_exists('ZipArchive')) sf_imp_fail('בשרת אין תמיכה בקובצי zip (ההרחבה zip של PHP) — אי אפשר לייבא Word או EPUB');
    $z = new ZipArchive();
    if ($z->open($file, ZipArchive::RDONLY) !== true) sf_imp_fail('הקובץ פגום — לא ניתן לפתוח אותו כ-zip');
    $n = $z->numFiles;
    if ($n > SF_ZIP_MAX_ENTRIES) { $z->close(); sf_imp_fail('יותר מדי קבצים בתוך הקובץ הדחוס (' . $n . '; עד ' . SF_ZIP_MAX_ENTRIES . ')'); }
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $st = $z->statIndex($i);
        if (!$st || !sf_zip_name_ok((string)$st['name'])) { $z->close(); sf_imp_fail('בקובץ הדחוס יש נתיב לא חוקי (יציאה מהתיקייה) — הקובץ נדחה'); }
        $total += max(0, (int)$st['size']);
        if ($total > SF_ZIP_MAX_TOTAL) { $z->close(); sf_imp_fail('הקובץ הדחוס גדול מדי אחרי פריסה (מעל 200MB)'); }
    }
    return $z;
}

/** קריאת רשומה, עד $max בתים **בפועל** (לא לפי מה שה-zip מצהיר). null = אין רשומה כזו. */
function sf_zip_read(ZipArchive $z, string $name, int $max): ?string
{
    if ($z->locateName($name) === false) return null;
    $s = $z->getStream($name);
    if (!$s) sf_imp_fail("לא ניתן לקרוא את \"$name\" מתוך הקובץ");
    $out = '';
    while (!feof($s)) {
        $chunk = fread($s, 1 << 16);
        if ($chunk === false) break;
        $out .= $chunk;
        if (strlen($out) > $max) { fclose($s); sf_imp_fail("\"$name\" גדול מדי אחרי פריסה"); }
    }
    fclose($s);
    return $out;
}

/** נתיב בתוך ה-zip: $href יחסית לתיקייה $base. null — יוצא מהשורש. */
function sf_zip_resolve(string $base, string $href): ?string
{
    $href = rawurldecode((string)preg_replace('~[#?].*$~s', '', $href));
    if ($href === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) || $href[0] === '/') return null;
    $parts = $base === '' ? [] : explode('/', trim($base, '/'));
    foreach (explode('/', $href) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { if (!$parts) return null; array_pop($parts); continue; }
        $parts[] = $seg;
    }
    $p = implode('/', $parts);
    return sf_zip_name_ok($p) ? $p : null;
}

// ── XML ו-HTML ───────────────────────────────────────────────

/** XML בלי ישויות ובלי רשת. ENTITY — נדחה; DOCTYPE — מוסר. */
function sf_imp_xml(string $xml, string $what): DOMDocument
{
    if (stripos($xml, '<!ENTITY') !== false) sf_imp_fail("$what: הקובץ מגדיר ישויות XML — נדחה (הגנה)");
    $xml = (string)preg_replace('~<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>~i', '', $xml);
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$doc->documentElement) sf_imp_fail("$what: ה-XML פגום");
    return $doc;
}

/** HTML / XHTML → DOMDocument (פירוש סלחני), UTF-8, בלי DOCTYPE ובלי הצהרת XML. */
function sf_imp_html_doc(string $html): DOMDocument
{
    if (stripos($html, '<!ENTITY') !== false) sf_imp_fail('הקובץ מגדיר ישויות — נדחה (הגנה)');
    $html = (string)preg_replace(['~<\?xml[^>]*\?>~i', '~<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>~i'], '', $html);
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

function sf_imp_xpath(DOMDocument $d): DOMXPath
{
    $x = new DOMXPath($d);
    $x->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    return $x;
}

// ── הבונה: טקסט עם סימון → HTML במבנה של 02 ─────────────────

/**
 * בונה את המסמך מרצף של קטעי טקסט עם סימון (b / i) ובלוקים. כך קינון שבור במקור (בלוק בתוך מודגש, פסקה בתוך
 * פסקה) לא מגיע לפלט: הבלוק סוגר את הפסקה הנוכחית, והסימון חל על כל קטע טקסט בנפרד.
 */
final class SfImpDoc
{
    private string $out = '';
    /** @var list<array{0:string,1:string}> [marks, html] */
    private array $runs = [];
    private int $depth = 0;
    private bool $inList = false;
    public int $chars = 0;
    public int $hebrew = 0;
    public int $niqqud = 0;
    public int $images = 0;

    public static function esc(string $t): string
    {
        return htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function text(string $t, string $marks = ''): void
    {
        $t = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
        if ($t === '') return;
        $this->chars += mb_strlen($t);
        $this->hebrew += (int)preg_match_all('/[\x{05D0}-\x{05EA}]/u', $t);
        $this->niqqud += (int)preg_match_all('/[\x{05B0}-\x{05BC}\x{05C1}\x{05C2}\x{05C7}]/u', $t);
        $this->runs[] = [$marks, self::esc($t)];
    }

    public function br(): void
    {
        if ($this->runs) $this->runs[] = ['', '<br>'];
    }

    public function img(string $src, string $alt): void
    {
        $this->images++;
        $this->runs[] = ['', '<img src="' . self::esc($src) . '"' . ($alt !== '' ? ' alt="' . self::esc(mb_substr($alt, 0, 300)) . '"' : '') . '>'];
    }

    public function hasRuns(): bool
    {
        foreach ($this->runs as [, $h]) if ($h !== '<br>' && trim(html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\u{A0}") !== '') return true;
        return false;
    }

    /** הקטעים כ-HTML מוטבע: קטעים צמודים עם אותו סימון — יחד; b מבחוץ, i מבפנים. */
    private function inline(): string
    {
        $runs = $this->runs;
        while ($runs && end($runs)[1] === '<br>') array_pop($runs);
        $out = '';
        $cur = null;
        $buf = '';
        $wrap = function (?string $m, string $h): string {
            if ($m === null || $m === '' || trim($h) === '') return $h;
            if (str_contains($m, 'i')) $h = "<i>$h</i>";
            if (str_contains($m, 'b')) $h = "<b>$h</b>";
            return $h;
        };
        foreach ($runs as [$m, $h]) {
            if ($h === '<br>' || str_starts_with($h, '<img')) { $out .= $wrap($cur, $buf) . $h; $buf = ''; $cur = null; continue; }
            if ($m !== $cur) { $out .= $wrap($cur, $buf); $buf = ''; $cur = $m; }
            $buf .= $h;
        }
        return $out . $wrap($cur, $buf);
    }

    /** סוגר את הפסקה הנוכחית כ-$tag (p / li / h2 / h3). פסקה ריקה — לא נכנסת. */
    public function para(string $tag = 'p', ?string $dir = null): void
    {
        if (!$this->hasRuns()) { $this->runs = []; return; }
        $html = $this->inline();
        $this->runs = [];
        if ($tag === 'li' && !$this->inList) { $this->out .= '<ul>'; $this->inList = true; }
        if ($tag !== 'li') $this->endList();
        $this->out .= "<$tag" . ($dir ? " dir=\"$dir\"" : '') . ">$html</$tag>";
        $this->guard();
    }

    private function endList(): void
    {
        if ($this->inList) { $this->out .= '</ul>'; $this->inList = false; }
    }

    /** כותרת: 1 — מקטע ראשי; 2 — מקטע משנה; 3 — כותרת עם נקודת ציון. */
    public function heading(int $level, string $title): void
    {
        $this->para();
        $this->endList();
        $title = sf_mk_attr_text($title, 200);
        if ($title === '') return;
        $this->chars += mb_strlen($title);
        if ($level >= 3) { $this->out .= '<h3><sf-anchor>' . self::esc($title) . '</sf-anchor></h3>'; return; }
        $keep = $level === 1 ? 0 : min($this->depth, 1);
        while ($this->depth > $keep) { $this->out .= '</sf-section>'; $this->depth--; }
        $this->out .= '<sf-section title="' . self::esc($title) . '">';
        $this->depth++;
    }

    /** טבלה: rows = רשימת שורות, כל תא [html מוטבע, colspan]. */
    public function table(array $rows): void
    {
        $this->para();
        $this->endList();
        $h = '';
        foreach ($rows as $cells) {
            if (!$cells) continue;
            $h .= '<tr>';
            foreach ($cells as [$html, $span]) $h .= '<td' . ($span > 1 ? ' colspan="' . min(99, $span) . '"' : '') . '>' . $html . '</td>';
            $h .= '</tr>';
        }
        if ($h !== '') $this->out .= "<table><tbody>$h</tbody></table>";
        $this->guard();
    }

    /** מוציא את הקטעים שנאספו כ-HTML מוטבע (תא בטבלה), ומאפס. */
    public function take(): string
    {
        $h = $this->hasRuns() ? $this->inline() : '';
        $this->runs = [];
        return $h;
    }

    private function guard(): void
    {
        if (strlen($this->out) > SF_CONTENT_MAX) sf_imp_fail('התוכן גדול מדי לטיוטה אחת (מעל 6MB) — לפצל את הקובץ');
    }

    public function html(): string
    {
        $this->para();
        $this->endList();
        while ($this->depth > 0) { $this->out .= '</sf-section>'; $this->depth--; }
        return $this->out;
    }
}

// ── Word ─────────────────────────────────────────────────────

/** סגנונות Word: styleId → [level (כותרת 1–3, 0 = לא), b, i]. */
function sf_docx_styles(?string $xml): array
{
    if ($xml === null) return [];
    $d = sf_imp_xml($xml, 'styles.xml');
    $x = sf_imp_xpath($d);
    $raw = [];
    foreach ($x->query('//w:style') as $s) {
        /** @var DOMElement $s */
        $id = $s->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'styleId');
        $name = strtolower((string)$x->evaluate('string(w:name/@w:val)', $s));
        $lvl = 0;
        if (preg_match('/^heading ([1-9])$/', $name, $m)) $lvl = (int)$m[1];
        elseif ($name === 'title') $lvl = 1;
        elseif (preg_match('/^heading([1-9])$/i', $id, $m)) $lvl = (int)$m[1];
        $ol = (string)$x->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $s);
        if (!$lvl && $ol !== '' && ctype_digit($ol)) $lvl = (int)$ol + 1;
        $raw[$id] = ['level' => $lvl, 'b' => sf_docx_on($x, $s, 'w:rPr/w:b') || sf_docx_on($x, $s, 'w:rPr/w:bCs'),
            'i' => sf_docx_on($x, $s, 'w:rPr/w:i') || sf_docx_on($x, $s, 'w:rPr/w:iCs'), 'based' => (string)$x->evaluate('string(w:basedOn/@w:val)', $s)];
    }
    $out = [];
    foreach ($raw as $id => $r) {   // ירושה (basedOn), עד 10 רמות
        $lvl = $r['level']; $b = $r['b']; $i = $r['i']; $p = $r['based'];
        for ($k = 0; $k < 10 && $p !== '' && isset($raw[$p]); $k++) { $lvl = $lvl ?: $raw[$p]['level']; $b = $b || $raw[$p]['b']; $i = $i || $raw[$p]['i']; $p = $raw[$p]['based']; }
        $out[$id] = ['level' => $lvl > 3 ? 0 : $lvl, 'b' => $b, 'i' => $i];
    }
    return $out;
}

/** תכונת הפעלה של Word (w:b וכו'): קיימת, ו-val אינו 0 / false / off. */
function sf_docx_on(DOMXPath $x, DOMNode $ctx, string $path): bool
{
    $n = $x->query($path, $ctx)->item(0);
    if (!$n instanceof DOMElement) return false;
    $v = strtolower($n->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val'));
    return !in_array($v, ['0', 'false', 'off', 'none'], true);
}

function sf_imp_docx(string $file, callable $tick): array
{
    $z = sf_zip_open($file);
    try {
        $xml = sf_zip_read($z, 'word/document.xml', SF_ZIP_MAX_XML) ?? sf_imp_fail('הקובץ אינו מסמך Word (חסר word/document.xml)');
        $styles = sf_docx_styles(sf_zip_read($z, 'word/styles.xml', SF_ZIP_MAX_XML));
    } finally {
        $z->close();
    }
    $d = sf_imp_xml($xml, 'document.xml');
    $x = sf_imp_xpath($d);
    $body = $x->query('/w:document/w:body')->item(0) ?? sf_imp_fail('מסמך Word בלי גוף (w:body)');
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $doc = new SfImpDoc();
    $warn = [];
    $cnt = ['notes' => 0, 'images' => 0, 'vmerge' => 0];
    $paraNo = 0;
    $total = max(1, (int)$x->evaluate('count(//w:p)'));

    // הקטעים של פסקה: רצים, היפר-קישורים, הוספות (w:ins); מחיקות (w:del) — לא
    $runs = function (DOMNode $n, SfImpDoc $to) use (&$runs, $x, $W, $styles, &$cnt): void {
        foreach ($n->childNodes as $c) {
            if (!$c instanceof DOMElement || $c->namespaceURI !== $W) continue;
            switch ($c->localName) {
                case 'r':
                    $rs = (string)$x->evaluate('string(w:rPr/w:rStyle/@w:val)', $c);
                    $b = sf_docx_on($x, $c, 'w:rPr/w:b') || sf_docx_on($x, $c, 'w:rPr/w:bCs') || (!$x->query('w:rPr/w:b|w:rPr/w:bCs', $c)->length && ($styles[$rs]['b'] ?? false));
                    $i = sf_docx_on($x, $c, 'w:rPr/w:i') || sf_docx_on($x, $c, 'w:rPr/w:iCs') || (!$x->query('w:rPr/w:i|w:rPr/w:iCs', $c)->length && ($styles[$rs]['i'] ?? false));
                    $m = ($b ? 'b' : '') . ($i ? 'i' : '');
                    foreach ($c->childNodes as $e) {
                        if (!$e instanceof DOMElement || $e->namespaceURI !== $W) continue;
                        match ($e->localName) {
                            't' => $to->text($e->textContent, $m),
                            'tab' => $to->text(' ', $m),
                            'noBreakHyphen' => $to->text('-', $m),
                            'br' => in_array($e->getAttributeNS($W, 'type'), ['page', 'column'], true) ? null : $to->br(),
                            'cr' => $to->br(),
                            'footnoteReference', 'endnoteReference' => $cnt['notes']++,
                            'drawing', 'pict', 'object' => $cnt['images']++,
                            default => null,
                        };
                    }
                    break;
                case 'hyperlink': case 'ins': case 'smartTag': case 'sdt': case 'sdtContent': case 'fldSimple': case 'customXml': case 'bdo': case 'dir': case 'moveTo':
                    $runs($c, $to);
                    break;
            }
        }
    };
    $level = function (DOMElement $p) use ($x, $styles): int {
        $st = (string)$x->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
        $lvl = $styles[$st]['level'] ?? 0;
        if (!$lvl && preg_match('/^heading([1-3])$/i', $st, $m)) $lvl = (int)$m[1];
        $ol = (string)$x->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $p);
        if ($ol !== '' && ctype_digit($ol)) $lvl = (int)$ol < 3 ? (int)$ol + 1 : 0;
        return $lvl;
    };
    $dirOf = function (DOMElement $p) use ($x): ?string {
        if (sf_docx_on($x, $p, 'w:pPr/w:bidi')) return null;
        $t = $p->textContent;
        return preg_match('/[A-Za-z]/', $t) && !preg_match('/[\x{0590}-\x{05FF}]/u', $t) ? 'ltr' : null;
    };
    $block = function (DOMNode $parent) use (&$block, $x, $W, $doc, &$warn, &$cnt, &$paraNo, $total, $tick, $runs, $level, $dirOf): void {
        foreach ($parent->childNodes as $c) {
            if (!$c instanceof DOMElement || $c->namespaceURI !== $W) continue;
            if ($c->localName === 'p') {
                $paraNo++;
                if ($paraNo % 200 === 0) $tick(10 + (int)(85 * $paraNo / $total));
                $lvl = $level($c);
                if ($lvl) {
                    $tmp = new SfImpDoc();
                    $runs($c, $tmp);
                    $doc->heading($lvl, html_entity_decode(strip_tags(str_replace('<br>', ' ', $tmp->take())), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    continue;
                }
                $runs($c, $doc);
                $doc->para($x->query('w:pPr/w:numPr', $c)->length ? 'li' : 'p', $dirOf($c));
            } elseif ($c->localName === 'tbl') {
                $rows = [];
                foreach ($x->query('w:tr', $c) as $tr) {
                    $cells = [];
                    foreach ($x->query('w:tc', $tr) as $tc) {
                        if ($x->query('w:tcPr/w:vMerge', $tc)->length) $cnt['vmerge']++;
                        if ($x->query('.//w:tbl', $tc)->length && !isset($warn['nested'])) {
                            $warn['nested'] = ['line' => null, 'msg' => "פסקה {$paraNo}: טבלה בתוך טבלה אינה נתמכת — התוכן שלה נכנס כטקסט בתא", 'blocks' => false];
                        }
                        $parts = [];
                        foreach ($x->query('.//w:p', $tc) as $p) {
                            $paraNo++;
                            $tmp = new SfImpDoc();
                            $runs($p, $tmp);
                            $h = $tmp->take();
                            if ($h !== '') $parts[] = $h;
                        }
                        $span = (int)$x->evaluate('string(w:tcPr/w:gridSpan/@w:val)', $tc);
                        $cells[] = [implode('<br>', $parts), max(1, $span)];
                    }
                    $rows[] = $cells;
                }
                $doc->table($rows);
            } elseif (in_array($c->localName, ['sdt', 'sdtContent', 'customXml', 'ins'], true)) {
                $block($c);
            } elseif ($c->localName === 'sectPr' && (int)$x->evaluate('string(w:cols/@w:num)', $c) > 1) {
                $warn['cols'] = ['line' => null, 'msg' => 'המסמך כתוב בעמודות — העמודות אוחדו לטור אחד', 'blocks' => false];
            }
        }
    };
    $block($body);
    foreach ($x->query('//w:pPr/w:sectPr') as $s) if ((int)$x->evaluate('string(w:cols/@w:num)', $s) > 1) $warn['cols'] = ['line' => null, 'msg' => 'המסמך כתוב בעמודות — העמודות אוחדו לטור אחד', 'blocks' => false];
    if ($cnt['notes']) $warn[] = ['line' => null, 'msg' => "{$cnt['notes']} הערות שוליים לא יובאו (רק הסימון במקום שלהן הוסר)", 'blocks' => false];
    if ($cnt['images']) $warn[] = ['line' => null, 'msg' => "{$cnt['images']} תמונות או ציורים בתוך ה-Word לא יובאו", 'blocks' => false];
    if ($cnt['vmerge']) $warn[] = ['line' => null, 'msg' => 'תאים שמוזגו אנכית בטבלה מוצגים כתאים נפרדים', 'blocks' => false];
    return ['html' => $doc->html(), 'warnings' => array_values($warn), 'doc' => $doc];
}

// ── HTML (וגם הפרקים של EPUB) ────────────────────────────────

const SF_IMP_BLOCKS = ['p', 'div', 'blockquote', 'section', 'article', 'header', 'footer', 'aside', 'figure', 'figcaption', 'pre', 'address',
    'dd', 'dt', 'dl', 'center', 'main', 'nav', 'hgroup', 'body', 'html'];

/**
 * DOM של HTML → הבונה. $o: hmap fn(int level): int (0 = לדלג), img fn(string src, string alt): ?string (src בתוך files/ או null),
 * skipFirst — טקסט הכותרת שכבר שימשה כשם הפרק (לא נכנסת שוב).
 */
function sf_imp_walk(DOMNode $n, SfImpDoc $doc, array &$o, string $marks = ''): void
{
    foreach ($n->childNodes as $c) sf_imp_node($c, $doc, $o, $marks);
}

function sf_imp_node(DOMNode $c, SfImpDoc $doc, array &$o, string $marks): void
{
    if ($c instanceof DOMText) {
        $t = (string)preg_replace('/[ \t\r\n\f]+/', ' ', $c->data);
        if ($t !== ' ' || $doc->hasRuns()) $doc->text($t, $marks);
        return;
    }
    if (!$c instanceof DOMElement) return;
    $tag = strtolower($c->localName ?? '');
    if (in_array($tag, SF_MK_DROP, true)) { $o['dropped'][$tag] = ($o['dropped'][$tag] ?? 0) + 1; return; }
    if (preg_match('/^h([1-6])$/', $tag, $m)) {
        $title = sf_mk_attr_text($c->textContent, 200);
        if (($o['skipFirst'] ?? null) !== null && $title === $o['skipFirst']) { $o['skipFirst'] = null; return; }
        $lvl = ($o['hmap'])((int)$m[1]);
        if ($lvl) { $doc->heading($lvl, $title); return; }
        $doc->para();
        sf_imp_walk($c, $doc, $o, str_contains($marks, 'b') ? $marks : $marks . 'b');
        $doc->para();
        return;
    }
    switch ($tag) {
        case 'br': $doc->br(); break;
        case 'img':
            $alt = sf_mk_attr_text($c->getAttribute('alt'), 300);
            $src = ($o['img'])($c->getAttribute('src'), $alt);
            if ($src !== null) $doc->img($src, $alt);
            break;
        case 'b': case 'strong': sf_imp_walk($c, $doc, $o, str_contains($marks, 'b') ? $marks : $marks . 'b'); break;
        case 'i': case 'em': case 'cite': sf_imp_walk($c, $doc, $o, str_contains($marks, 'i') ? $marks : $marks . 'i'); break;
        case 'ul': case 'ol': $doc->para(); sf_imp_walk($c, $doc, $o, $marks); $doc->para(); break;
        case 'li': $doc->para(); sf_imp_walk($c, $doc, $o, $marks); $doc->para('li'); break;
        case 'table':
            $rows = [];
            foreach ((new DOMXPath($c->ownerDocument))->query('.//tr', $c) as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if (!$td instanceof DOMElement || !in_array(strtolower($td->localName), ['td', 'th'], true)) continue;
                    $tmp = new SfImpDoc();
                    $oo = $o;
                    $oo['hmap'] = fn() => 0;
                    sf_imp_cell($td, $tmp, $oo, strtolower($td->localName) === 'th' ? 'b' : '');
                    $cells[] = [$tmp->take(), max(1, (int)$td->getAttribute('colspan'))];
                }
                $rows[] = $cells;
            }
            $doc->table($rows);
            break;
        default:
            if (in_array($tag, SF_IMP_BLOCKS, true)) { $doc->para(); sf_imp_walk($c, $doc, $o, $marks); $doc->para(); }
            else sf_imp_walk($c, $doc, $o, $marks);   // span, a, font, u … — הטקסט נשאר
    }
}

/** תא בטבלה: פסקאות → <br> (בתוך תא אין בלוקים); טבלה בתוך תא — כטקסט. */
function sf_imp_cell(DOMNode $td, SfImpDoc $doc, array &$o, string $marks): void
{
    foreach ($td->childNodes as $c) {
        $tag = $c instanceof DOMElement ? strtolower($c->localName) : '';
        if ($tag !== '' && (in_array($tag, SF_IMP_BLOCKS, true) || in_array($tag, ['ul', 'ol', 'li', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true))) {
            if ($doc->hasRuns()) $doc->br();
            if ($tag === 'table') { $doc->text(sf_mk_attr_text($c->textContent, 100000), $marks); continue; }
            sf_imp_cell($c, $doc, $o, $marks);
            continue;
        }
        sf_imp_node($c, $doc, $o, $marks);
    }
}

/** HTML: סימון של ספורנו (sf-*) — ישירות לניקוי של 02 (שגיאת מבנה = כשל עם שורה); כל HTML אחר — דרך הבונה. */
function sf_imp_html(string $file, array &$warn): array
{
    $raw = (string)file_get_contents($file);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = function_exists('iconv') ? @iconv('WINDOWS-1255', 'UTF-8//IGNORE', $raw) : false;
        if ($conv === false || !mb_check_encoding($conv, 'UTF-8')) sf_imp_fail('הקובץ אינו בקידוד UTF-8, ולא ניתן להמיר אותו');
        $raw = $conv;
        $warn[] = ['line' => null, 'msg' => 'הקידוד הומר מ-Windows-1255 ל-UTF-8 — לבדוק שהטקסט תקין', 'blocks' => false];
    }
    if (preg_match('~<sf-(section|anchor|style|cond|note|link|audio)\b~i', $raw)) {
        if (preg_match('~<body[^>]*>(.*)</body>~is', $raw, $m)) $raw = $m[1];
        $r = sf_mk_clean($raw);
        if ($r['errors']) { $e = $r['errors'][0]; sf_imp_fail("שורה {$e['line']}: {$e['msg']}"); }
        return ['html' => $r['html'], 'doc' => null];
    }
    $d = sf_imp_html_doc($raw);
    $doc = new SfImpDoc();
    $imgs = 0;
    $o = ['hmap' => fn(int $l) => min($l, 3), 'img' => function () use (&$imgs) { $imgs++; return null; }, 'dropped' => []];
    sf_imp_walk($d->getElementsByTagName('body')->item(0) ?? $d, $doc, $o);
    if ($imgs) $warn[] = ['line' => null, 'msg' => "$imgs תמונות בקובץ HTML לא יובאו (התמונות אינן חלק מהקובץ)", 'blocks' => false];
    if ($o['dropped']) $warn[] = ['line' => null, 'msg' => 'הוסרו רכיבים אסורים: ' . implode(', ', array_keys($o['dropped'])), 'blocks' => false];
    return ['html' => $doc->html(), 'doc' => $doc];
}

// ── EPUB ─────────────────────────────────────────────────────

function sf_imp_epub(string $file, string $pid, callable $tick, array &$warn): array
{
    $z = sf_zip_open($file);
    try {
        if (trim((string)sf_zip_read($z, 'mimetype', 100)) !== 'application/epub+zip') sf_imp_fail('הקובץ אינו EPUB (חסר mimetype)');
        $cx = sf_imp_xml(sf_zip_read($z, 'META-INF/container.xml', 1 << 20) ?? sf_imp_fail('EPUB בלי META-INF/container.xml'), 'container.xml');
        $opfPath = (string)(new DOMXPath($cx))->evaluate("string(//*[local-name()='rootfile']/@full-path)");
        if ($opfPath === '' || !sf_zip_name_ok($opfPath)) sf_imp_fail('EPUB בלי קובץ OPF תקין');
        $opf = sf_imp_xml(sf_zip_read($z, $opfPath, SF_ZIP_MAX_XML) ?? sf_imp_fail('קובץ ה-OPF חסר'), 'OPF');
        $ox = new DOMXPath($opf);
        $base = str_contains($opfPath, '/') ? substr($opfPath, 0, (int)strrpos($opfPath, '/')) : '';
        $items = [];
        foreach ($ox->query("//*[local-name()='manifest']/*[local-name()='item']") as $it) {
            /** @var DOMElement $it */
            $p = sf_zip_resolve($base, $it->getAttribute('href'));
            if ($p !== null) $items[$it->getAttribute('id')] = ['path' => $p, 'type' => strtolower($it->getAttribute('media-type'))];
        }
        $byPath = array_column($items, 'type', 'path');
        $spine = [];
        foreach ($ox->query("//*[local-name()='spine']/*[local-name()='itemref']") as $ir) {
            $it = $items[$ir->getAttribute('idref')] ?? null;
            if ($it && in_array($it['type'], ['application/xhtml+xml', 'text/html'], true)) $spine[] = $it['path'];
        }
        if (!$spine) sf_imp_fail('ב-EPUB אין פרקים (ה-spine ריק)');

        $doc = new SfImpDoc();
        $imgDir = sf_imp_dir($pid) . '/files';
        $saved = [];
        $skipped = ['type' => 0, 'bad' => 0, 'nogd' => 0, 'many' => 0];
        foreach ($spine as $k => $path) {
            $tick(10 + (int)(85 * $k / count($spine)));
            $x = sf_zip_read($z, $path, SF_ZIP_MAX_XML);
            if ($x === null) { $warn[] = ['line' => null, 'msg' => 'פרק ' . ($k + 1) . ": הקובץ \"" . basename($path) . '" חסר — דילגנו', 'blocks' => false]; continue; }
            $hd = sf_imp_html_doc($x);
            $body = $hd->getElementsByTagName('body')->item(0);
            if (!$body) continue;
            $hx = new DOMXPath($hd);
            $first = $hx->query('.//h1|.//h2|.//h3', $body)->item(0);
            $title = sf_mk_attr_text($first ? $first->textContent : (string)$hx->evaluate('string(//title)'), 200);
            if ($title === '') $title = 'פרק ' . ($k + 1);
            $dir = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/')) : '';
            $o = [
                'hmap' => fn(int $l) => $l <= 2 ? 2 : 3,
                'skipFirst' => $first ? $title : null,
                'dropped' => [],
                'img' => function (string $src, string $alt) use ($z, $dir, $byPath, $imgDir, $pid, &$saved, &$skipped): ?string {
                    $p = sf_zip_resolve($dir, $src);
                    if ($p === null || !isset($byPath[$p])) { $skipped['bad']++; return null; }
                    if (isset($saved[$p])) return $saved[$p];
                    if (!in_array($byPath[$p], ['image/png', 'image/jpeg', 'image/webp'], true)) { $skipped['type']++; return null; }
                    if (count($saved) >= SF_IMPORT_MAX_IMAGES) { $skipped['many']++; return null; }
                    if (!extension_loaded('gd')) { $skipped['nogd']++; return null; }
                    $bin = sf_zip_read($z, $p, SF_IMG_MAX_BYTES);
                    $img = $bin === null ? null : sf_imp_image($bin);
                    if (!$img) { $skipped['bad']++; return null; }
                    sf_imp_mkdir($imgDir);
                    $name = (count($saved) + 1) . '.' . $img['ext'];
                    if (@file_put_contents("$imgDir/$name", $img['bin'], LOCK_EX) === false) throw new SfError('storage');
                    return $saved[$p] = "files/$pid/$name";
                },
            ];
            $doc->heading(1, $title);
            sf_imp_walk($body, $doc, $o);
            if ($o['dropped']) $warn[] = ['line' => null, 'msg' => 'פרק ' . ($k + 1) . ': הוסרו רכיבים אסורים: ' . implode(', ', array_keys($o['dropped'])), 'blocks' => false];
        }
    } finally {
        $z->close();
    }
    if ($skipped['type']) $warn[] = ['line' => null, 'msg' => "{$skipped['type']} תמונות בפורמט שאינו png / jpeg / webp לא יובאו", 'blocks' => false];
    if ($skipped['bad']) $warn[] = ['line' => null, 'msg' => "{$skipped['bad']} תמונות חסרות או פגומות לא יובאו", 'blocks' => false];
    if ($skipped['nogd']) $warn[] = ['line' => null, 'msg' => "{$skipped['nogd']} תמונות לא יובאו: אין בשרת עיבוד תמונות (GD)", 'blocks' => false];
    if ($skipped['many']) $warn[] = ['line' => null, 'msg' => "{$skipped['many']} תמונות מעבר ל-" . SF_IMPORT_MAX_IMAGES . ' לא יובאו', 'blocks' => false];
    return ['html' => $doc->html(), 'doc' => $doc];
}

/**
 * תמונה: png / jpeg / webp בלבד (לפי התוכן), עד 40 מגה-פיקסל; מפוענחת ומקודדת מחדש — מוקטנת לרוחב 1400 ובלי EXIF
 * (הקידוד מחדש לא מעתיק מטא-נתונים). null — לא תמונה נתמכת. דורש GD.
 */
function sf_imp_image(string $bin): ?array
{
    if (!extension_loaded('gd')) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info) return null;
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
    if ($ext === null || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > SF_IMG_MAX_PIXELS) return null;
    if ($ext === 'webp' && !function_exists('imagewebp')) $ext = 'png';
    $im = @imagecreatefromstring($bin);
    if (!$im) return null;
    if (imagesx($im) > SF_IMG_MAX_W) {
        $s = imagescale($im, SF_IMG_MAX_W, -1, IMG_BICUBIC);
        imagedestroy($im);
        if (!$s) return null;
        $im = $s;
    }
    ob_start();
    if ($ext === 'png') { imagesavealpha($im, true); imagepng($im, null, 6); }
    elseif ($ext === 'jpg') imagejpeg($im, null, 85);
    else imagewebp($im, null, 85);
    $out = (string)ob_get_clean();
    imagedestroy($im);
    return $out === '' ? null : ['ext' => $ext, 'bin' => $out];
}

// ── PDF (הטקסט מגיע מהדפדפן) ─────────────────────────────────

/** סף "סרוק": פחות מ-20 תווים לעמוד בממוצע. */
function sf_imp_pdf_is_scan(array $pages): bool
{
    $n = 0;
    foreach ($pages as $p) $n += mb_strlen((string)preg_replace('/\s+/u', '', (string)$p));
    return $n < 20 * max(1, count($pages));
}

function sf_imp_pdf(array $pages, callable $tick, array &$warn): array
{
    $doc = new SfImpDoc();
    foreach ($pages as $k => $text) {
        if ($k % 20 === 0) $tick(10 + (int)(85 * $k / max(1, count($pages))));
        foreach (preg_split('/\n\s*\n/u', str_replace("\r", '', (string)$text)) ?: [] as $para) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $para)), fn($l) => $l !== ''));
            foreach ($lines as $j => $l) { if ($j) $doc->text(' '); $doc->text($l); }
            $doc->para();
        }
    }
    $html = $doc->html();
    // טקסט הפוך (סדר חזותי): מילים עבריות שמתחילות באות סופית
    preg_match_all('/(?<![\x{05D0}-\x{05EA}])[\x{05D0}-\x{05EA}]{2,}/u', (string)preg_replace('/[\x{0591}-\x{05C7}]/u', '', $html), $m);
    $words = $m[0];
    $rev = count(array_filter($words, fn($w) => preg_match('/^[ךםןףץ]/u', $w)));
    if (count($words) >= 30 && $rev / count($words) > 0.08) {
        $warn[] = ['line' => null, 'msg' => 'נראה שהטקסט הפוך (סדר חזותי — מילים שמתחילות באות סופית). PDF כזה עדיף לייבא כעמודים בלבד', 'blocks' => false];
    }
    $warn[] = ['line' => null, 'msg' => 'טקסט שחולץ מ-PDF — לא מוגה. לבדוק מול העמודים המקוריים לפני פרסום', 'blocks' => false];
    return ['html' => $html, 'doc' => $doc];
}

// ── העיבוד (משימת הרקע) ──────────────────────────────────────

function sf_import_by_pid(mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_row('SELECT * FROM imports WHERE pid = ?', [$pid]) ?? sf_fail('not_found');
}

function sf_import_set(int $id, array $f): void
{
    $f['updated_at'] = sf_now();
    sf_exec('UPDATE imports SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
}

/** המעבד: payload {import}. כשל — import.status = failed עם סיבה ברורה, import.fail ביומן, והמשימה נכשלת. */
function sf_import_process(array $payload): void
{
    $im = sf_row('SELECT * FROM imports WHERE pid = ?', [(string)($payload['import'] ?? '')]);
    if (!$im || !in_array($im['status'], ['queued', 'processing'], true)) return;   // בוטל / כבר עובד
    $id = (int)$im['id'];
    $t0 = microtime(true);
    $last = 0;
    $tick = function (int $p) use ($id, $t0, &$last): void {
        if (microtime(true) - $t0 > SF_IMPORT_MAX_SEC) sf_imp_fail('העיבוד ארך יותר מ-' . SF_IMPORT_MAX_SEC . ' שניות — הקובץ גדול או מורכב מדי. אפשר לפצל אותו ולנסות שוב');
        $p = max(0, min(99, $p));
        if ($p >= $last + 5) { $last = $p; sf_import_set($id, ['progress' => $p]); }
    };
    sf_import_set($id, ['status' => 'processing', 'progress' => 5, 'error' => null]);
    $dir = sf_imp_dir($im['pid']);
    $file = "$dir/original.{$im['ext']}";
    try {
        if (!is_file($file)) sf_imp_fail('הקובץ המקורי חסר בשרת — להעלות אותו שוב');
        $warn = [];
        $pages = null;
        $kind = $im['kind'];
        switch ($kind) {
            case 'docx': $r = sf_imp_docx($file, $tick); $warn = $r['warnings']; break;
            case 'epub': $r = sf_imp_epub($file, $im['pid'], $tick, $warn); break;
            case 'html': $r = sf_imp_html($file, $warn); break;
            case 'pdf': case 'pdf_scan':
                $pj = json_decode((string)@file_get_contents("$dir/pages.json"), true);
                if (!is_array($pj)) sf_imp_fail('הטקסט של ה-PDF לא הגיע מהדפדפן — לפתוח את הייבוא ולנסות שוב');
                $pages = count($pj);
                if (sf_imp_pdf_is_scan($pj)) { $kind = 'pdf_scan'; $r = ['html' => null, 'doc' => null]; break; }
                $kind = 'pdf';
                $r = sf_imp_pdf($pj, $tick, $warn);
                break;
            case 'image':
                $img = sf_imp_image((string)file_get_contents($file));
                if ($img) { sf_imp_mkdir("$dir/files"); file_put_contents("$dir/files/page-1.{$img['ext']}", $img['bin'], LOCK_EX); }
                else $warn[] = ['line' => null, 'msg' => extension_loaded('gd') ? 'לא ניתן לעבד את התמונה' : 'אין בשרת עיבוד תמונות (GD) — נשמר רק המקור', 'blocks' => false];
                $pages = 1;
                $r = ['html' => null, 'doc' => null];
                break;
            default: sf_imp_fail('סוג ייבוא לא מוכר');
        }
        $tick(96);
        $html = null;
        if ($r['html'] !== null) {
            $clean = sf_mk_clean($r['html'], sf_mk_ctx((int)$im['edition_id']));
            if ($clean['errors']) { $e = $clean['errors'][0]; sf_imp_fail("התוכן שהתקבל אינו תקין (שורה {$e['line']}: {$e['msg']})"); }
            $html = $clean['html'];
            if (trim(strip_tags($html)) === '' && !str_contains($html, '<img')) sf_imp_fail('לא נמצא בקובץ טקסט לייבוא');
            $warn = array_merge($warn, array_values(array_filter($clean['warnings'], fn($w) => $w['line'] === null || $kind === 'html')));
            $d = $r['doc'];
            if ($d instanceof SfImpDoc && $d->hebrew > 200 && $d->niqqud === 0) {
                $warn[] = ['line' => null, 'msg' => 'לא נמצא ניקוד בטקסט. אם המקור מנוקד — ייתכן שהניקוד אבד בדרך', 'blocks' => false];
            }
        } elseif ($kind === 'pdf_scan') {
            $warn[] = ['line' => null, 'msg' => 'PDF סרוק (בלי שכבת טקסט): נשמרים העמודים המקוריים בלבד — בלי טקסט, בלי חיפוש ובלי זיהוי', 'blocks' => false];
        }
        sf_tx(function () use ($id, $im, $kind, $html, $warn, $pages) {
            sf_import_set($id, ['status' => 'ready', 'progress' => 100, 'kind' => $kind, 'result_html' => $html, 'pages' => $pages,
                'warnings' => json_encode(array_values($warn), JSON_UNESCAPED_UNICODE)]);
            sf_audit('import.done', 'import', $im['pid'], ['kind' => $kind, 'edition' => sf_val('SELECT pid FROM editions WHERE id = ?', [$im['edition_id']]),
                'sections' => $html !== null ? substr_count($html, '<sf-section') : 0, 'warnings' => count($warn)], null, 'job');
        });
    } catch (Throwable $e) {
        $msg = $e instanceof SfImportFail ? $e->getMessage() : ($e instanceof SfError ? $e->getMessage() : 'תקלה לא צפויה בעיבוד הקובץ. היא נרשמה ביומן');
        sf_import_set($id, ['status' => 'failed', 'error' => mb_substr($msg, 0, 500)]);
        sf_audit('import.fail', 'import', $im['pid'], ['kind' => $im['kind'], 'reason' => $e instanceof SfImportFail ? 'file' : 'server'], null, 'job');
        throw $e;
    }
}

sf_job_register('import.process', 'ייבוא קובץ לטיוטה (03)', fn(array $p) => sf_import_process($p));

// ── הפעולות ──────────────────────────────────────────────────

/** בדיקת הקובץ: סיומת + תוכן. מחזיר את הסוג, או נכשל ב-SfError('invalid') עם הסיבה. */
function sf_import_check_file(string $file, string $ext): string
{
    $kind = SF_IMPORT_EXT[$ext] ?? sf_fail('invalid', 'סוג קובץ לא נתמך. אפשר: ' . implode(', ', array_keys(SF_IMPORT_EXT)));
    $head = (string)file_get_contents($file, false, null, 0, 16);
    try {
        switch ($kind) {
            case 'docx': case 'epub':
                if (!str_starts_with($head, "PK\x03\x04")) sf_imp_fail('הקובץ אינו ' . ($kind === 'docx' ? 'מסמך Word' : 'EPUB') . ' (הוא אינו קובץ zip)');
                $z = sf_zip_open($file);
                $ok = $kind === 'docx' ? $z->locateName('word/document.xml') !== false
                    : $z->locateName('META-INF/container.xml') !== false && trim((string)sf_zip_read($z, 'mimetype', 100)) === 'application/epub+zip';
                $z->close();
                if (!$ok) sf_imp_fail($kind === 'docx' ? 'הקובץ אינו מסמך Word (חסר word/document.xml)' : 'הקובץ אינו EPUB (חסרים mimetype או container.xml)');
                break;
            case 'pdf':
                if (!str_starts_with($head, '%PDF-')) sf_imp_fail('הקובץ אינו PDF');
                break;
            case 'image':
                $t = @getimagesize($file);
                $want = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'webp' => IMAGETYPE_WEBP][$ext];
                if (!$t || $t[2] !== $want) sf_imp_fail('הקובץ אינו תמונה מהסוג שבסיומת');
                break;
            case 'html':
                $s = (string)file_get_contents($file, false, null, 0, 1 << 20);
                if (str_contains($s, "\0") || str_starts_with($head, 'PK') || str_starts_with($head, '%PDF')) sf_imp_fail('הקובץ אינו HTML (תוכן בינארי)');
                break;
        }
    } catch (SfImportFail $e) {
        sf_fail('invalid', $e->getMessage());
    }
    return $kind;
}

/**
 * העלאה. $q: edition, name (שם הקובץ — לסיומת ולתצוגה; לא ביומן), owner=1 (EPUB: "בהרשאת בעליו"). הגוף — הקובץ עצמו.
 * $env['upload'] — נתיב לקובץ (רק בבדיקות; api.php לא מעביר אותו). הקובץ נכתב לתיקייה זמנית, נבדק, ורק אז עובר
 * ל-data/sources/<pid>/. נדחה — נמחק, ושום דבר לא נשאר.
 */
function sf_import_upload(array $q, array $env, int $me): array
{
    $e = sf_edition_by_pid($q['edition'] ?? null);
    $name = sf_mk_attr_text(basename(str_replace('\\', '/', (string)($q['name'] ?? ''))), 200);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(SF_IMPORT_EXT[$ext])) sf_fail('invalid', 'סוג קובץ לא נתמך. אפשר: ' . implode(', ', array_keys(SF_IMPORT_EXT)));
    $owner = ($q['owner'] ?? '') === '1';
    if (SF_IMPORT_EXT[$ext] === 'epub' && !$owner) sf_fail('invalid', 'ייבוא EPUB רק בהרשאת בעליו — צריך לסמן שיש הרשאה');
    $limit = sf_import_limit();
    sf_imp_mkdir(sf_sources_dir());
    $tmp = sf_sources_dir() . '/.up_' . bin2hex(random_bytes(8));
    try {
        if (isset($env['upload'])) {
            $n = @filesize((string)$env['upload']);
            if ($n === false || $n > $limit) sf_fail('too_large', 'הקובץ גדול מדי — עד ' . sf_imp_mb($limit));
            copy((string)$env['upload'], $tmp);
        } else {
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) sf_fail('too_large', 'הקובץ גדול מדי — עד ' . sf_imp_mb($limit));
            $in = fopen('php://input', 'rb');
            $out = fopen($tmp, 'wb');
            $n = $in && $out ? stream_copy_to_stream($in, $out, $limit + 1) : false;
            if ($in) fclose($in);
            if ($out) fclose($out);
            if ($n === false) throw new SfError('storage', 'לא ניתן לשמור את הקובץ בשרת');
            if ($n > $limit) sf_fail('too_large', 'הקובץ גדול מדי — עד ' . sf_imp_mb($limit));
        }
        $size = (int)filesize($tmp);
        if ($size <= 0) sf_fail('invalid', 'הקובץ ריק');
        $kind = sf_import_check_file($tmp, $ext);
        $pid = sf_pid();
        $dir = sf_imp_dir($pid);
        sf_imp_mkdir($dir);
        if (!@rename($tmp, "$dir/original.$ext")) throw new SfError('storage', 'לא ניתן לשמור את הקובץ בשרת');
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
    $sha = (string)hash_file('sha256', "$dir/original.$ext");
    $status = $kind === 'pdf' ? 'awaiting_text' : 'queued';
    return sf_tx(function () use ($e, $pid, $kind, $ext, $name, $size, $sha, $owner, $status, $me) {
        $now = sf_now();
        $id = sf_insert('imports', ['pid' => $pid, 'edition_id' => $e['id'], 'kind' => $kind, 'ext' => $ext, 'file_name' => $name, 'size' => $size, 'sha256' => $sha,
            'owner_permission' => $owner ? 1 : 0, 'status' => $status, 'progress' => 0, 'created_by' => $me, 'created_at' => $now, 'updated_at' => $now]);
        if ($status === 'queued') sf_import_set($id, ['job_pid' => sf_job_add('import.process', ['import' => $pid], null, 0, 1)]);
        sf_audit('import.upload', 'import', $pid, ['kind' => $kind, 'size' => $size, 'edition' => $e['pid'], 'owner_permission' => $owner]);
        return ['import' => sf_import_out(sf_row('SELECT * FROM imports WHERE id = ?', [$id]))];
    });
}

function sf_imp_mb(int $b): string
{
    return rtrim(rtrim(number_format($b / 1048576, 1), '0'), '.') . 'MB';
}

/** הטקסט של PDF מהדפדפן: $in {import, pages: [טקסט לכל עמוד]}. נכנס לתור כמו כל ייבוא. */
function sf_import_pdf_text(array $in): array
{
    $pages = $in['pages'] ?? null;
    if (!is_array($pages) || !array_is_list($pages) || count($pages) < 1 || count($pages) > SF_PDF_MAX_PAGES) sf_fail('bad_input', 'רשימת העמודים אינה תקינה');
    $total = 0;
    foreach ($pages as $p) {
        if (!is_string($p) || !mb_check_encoding($p, 'UTF-8')) sf_fail('bad_input');
        $total += strlen($p);
    }
    if ($total > SF_CONTENT_MAX) sf_fail('too_large', 'הטקסט של ה-PDF גדול מדי לטיוטה אחת');
    return sf_tx(function () use ($in, $pages) {
        $im = sf_import_by_pid($in['import'] ?? null);
        if (!in_array($im['kind'], ['pdf', 'pdf_scan'], true)) sf_fail('state', 'זה אינו ייבוא PDF');
        if (!in_array($im['status'], ['awaiting_text', 'failed', 'ready'], true)) sf_fail('state');
        file_put_contents(sf_imp_dir($im['pid']) . '/pages.json', json_encode($pages, JSON_UNESCAPED_UNICODE), LOCK_EX);
        sf_import_set((int)$im['id'], ['status' => 'queued', 'progress' => 0, 'error' => null, 'pages' => count($pages),
            'job_pid' => sf_job_add('import.process', ['import' => $im['pid']], null, 0, 1)]);
        return ['import' => sf_import_out(sf_import_by_pid($im['pid']))];
    });
}

/** מריץ עכשיו את העיבוד של הייבוא (בלי לחכות ל-cron). */
function sf_import_run(array $in): array
{
    $im = sf_import_by_pid($in['import'] ?? null);
    if ($im['status'] === 'queued' && $im['job_pid']) {
        try { sf_job_run_now($im['job_pid']); } catch (Throwable $e) { /* הכשל כבר נרשם בייבוא ובמשימה */ }
    }
    return ['import' => sf_import_out(sf_import_by_pid($im['pid']))];
}

/** ניסיון חוזר לייבוא שנכשל: משימה חדשה. */
function sf_import_retry(array $in): array
{
    return sf_tx(function () use ($in) {
        $im = sf_import_by_pid($in['import'] ?? null);
        if ($im['status'] !== 'failed') sf_fail('state', 'אפשר לנסות שוב רק ייבוא שנכשל');
        $awaiting = $im['kind'] === 'pdf' && !is_file(sf_imp_dir($im['pid']) . '/pages.json');
        sf_import_set((int)$im['id'], ['status' => $awaiting ? 'awaiting_text' : 'queued', 'progress' => 0, 'error' => null,
            'job_pid' => $awaiting ? null : sf_job_add('import.process', ['import' => $im['pid']], null, 0, 1)]);
        sf_audit('import.retry', 'import', $im['pid'], ['kind' => $im['kind']]);
        return ['import' => sf_import_out(sf_import_by_pid($im['pid']))];
    });
}

/** העץ: הרכיבים העליונים של התוצאה — מקטע (עם כותרת, ומספר מקטעי משנה) או רצף טקסט. */
function sf_import_items(?string $html): array
{
    if ($html === null || $html === '') return [];
    $root = sf_mk_parse($html);
    $items = [];
    foreach (iterator_to_array($root->childNodes) as $n) {
        $isSec = $n instanceof DOMElement && $n->localName === 'sf-section';
        if (!$isSec && $n instanceof DOMText && trim($n->data) === '') { if ($items) $items[count($items) - 1]['nodes'][] = $n; continue; }
        if ($isSec || !$items || $items[count($items) - 1]['type'] === 'section') $items[] = ['type' => $isSec ? 'section' : 'text', 'nodes' => []];
        $items[count($items) - 1]['nodes'][] = $n;
    }
    return $items;
}

function sf_import_tree(?string $html): array
{
    $out = [];
    foreach (sf_import_items($html) as $it) {
        $first = $it['nodes'][0];
        $text = '';
        foreach ($it['nodes'] as $n) $text .= ' ' . $n->textContent;
        $out[] = ['type' => $it['type'], 'title' => $it['type'] === 'section' ? $first->getAttribute('title') : null,
            'sub' => $it['type'] === 'section' ? (new DOMXPath($first->ownerDocument))->query('.//sf-section', $first)->length : 0,
            'chars' => mb_strlen(trim((string)preg_replace('/\s+/u', ' ', $text))),
            'preview' => mb_substr(trim((string)preg_replace('/\s+/u', ' ', $text)), 0, 90)];
    }
    return $out;
}

function sf_import_out(array $im): array
{
    $html = $im['result_html'];
    return ['pid' => $im['pid'], 'kind' => $im['kind'], 'ext' => $im['ext'], 'name' => $im['file_name'], 'size' => (int)$im['size'], 'status' => $im['status'],
        'progress' => (int)$im['progress'], 'error' => $im['error'], 'warnings' => json_decode((string)$im['warnings'], true) ?: [],
        'pages' => $im['pages'] !== null ? (int)$im['pages'] : null, 'text_checked' => (bool)$im['text_checked'], 'owner_permission' => (bool)$im['owner_permission'],
        'has_text' => $html !== null, 'tree' => $im['status'] === 'ready' ? sf_import_tree($html) : [],
        'applied_n' => $im['revision_id'] !== null ? (int)sf_val('SELECT n FROM revisions WHERE id = ?', [$im['revision_id']]) : null,
        'edition' => sf_val('SELECT pid FROM editions WHERE id = ?', [$im['edition_id']]),
        'page_src' => $im['kind'] === 'image' ? (($g = glob(sf_imp_dir($im['pid']) . '/files/page-1.*')) ? "files/{$im['pid']}/" . basename($g[0]) : null) : null,
        'created_at' => $im['created_at'], 'created_by' => sf_staff_name($im['created_by'])];
}

function sf_import_view(array $q, bool $html = false): array
{
    $im = sf_import_by_pid($q['import'] ?? null);
    $out = sf_import_out($im);
    if (($q['html'] ?? '') === '1') $out['html'] = $im['result_html'];
    return ['import' => $out];
}

function sf_import_list(array $q): array
{
    $e = sf_edition_by_pid($q['edition'] ?? null);
    $t = sf_row('SELECT * FROM titles WHERE id = ?', [$e['title_id']]);
    return ['title' => ['pid' => $t['pid'], 'name' => $t['name']], 'edition' => ['pid' => $e['pid'], 'label' => $e['label']], 'limits' => sf_import_limits(),
        'has_draft' => sf_draft((int)$e['id']) !== null,
        'imports' => array_map(fn($r) => sf_import_out($r), sf_rows('SELECT * FROM imports WHERE edition_id = ? ORDER BY id DESC LIMIT 30', [$e['id']]))];
}

/**
 * מיפוי: $in {import, op: rename · merge · up · down, i, title}. merge — הפריט i נכנס לסוף המקטע שלפניו (שם המקטע
 * שמוזג נשאר ככותרת ‹h2›). שינוי הכותרת, הגרירה (up/down) והמיזוג — על התוצאה, לפני שהיא נכנסת לטיוטה.
 */
function sf_import_map(array $in): array
{
    $op = $in['op'] ?? null;
    $i = $in['i'] ?? null;
    if (!in_array($op, ['rename', 'merge', 'up', 'down'], true) || !is_int($i) || $i < 0) sf_fail('bad_input');
    $title = $op === 'rename' ? sf_str_in($in, 'title', 200, true) : null;
    return sf_tx(function () use ($in, $op, $i, $title) {
        $im = sf_import_by_pid($in['import'] ?? null);
        if ($im['status'] !== 'ready' || $im['result_html'] === null) sf_fail('state', 'אין תוצאה לעריכה');
        $items = sf_import_items($im['result_html']);
        if (!isset($items[$i])) sf_fail('bad_input');
        $it = $items[$i];
        $doc = $it['nodes'][0]->ownerDocument;
        switch ($op) {
            case 'rename':
                if ($it['type'] !== 'section') sf_fail('state', 'אפשר לשנות שם רק למקטע');
                $it['nodes'][0]->setAttribute('title', $title);
                break;
            case 'merge':
                if ($i === 0 || $items[$i - 1]['type'] !== 'section') sf_fail('state', 'אפשר למזג רק לתוך מקטע שלפניו');
                $into = $items[$i - 1]['nodes'][0];
                if ($it['type'] === 'section') {
                    $sec = $it['nodes'][0];
                    if ($sec->getAttribute('title') !== '') { $h = $doc->createElement('h2'); $h->appendChild($doc->createTextNode($sec->getAttribute('title'))); $into->appendChild($h); }
                    while ($sec->firstChild) $into->appendChild($sec->firstChild);
                    $sec->parentNode->removeChild($sec);
                } else {
                    foreach ($it['nodes'] as $n) $into->appendChild($n);
                }
                break;
            case 'up': case 'down':
                $j = $op === 'up' ? $i - 1 : $i + 1;
                if (!isset($items[$j])) sf_fail('state');
                [$a, $b] = $op === 'up' ? [$items[$j], $it] : [$it, $items[$j]];   // b עובר לפני a
                $ref = $a['nodes'][0];
                foreach ($b['nodes'] as $n) $ref->parentNode->insertBefore($n, $ref);
                break;
        }
        $root = $it['nodes'][0]->ownerDocument->getElementsByTagName('body')->item(0)->firstChild;
        $html = '';
        foreach ($root->childNodes as $c) $html .= $doc->saveHTML($c);
        $clean = sf_mk_clean($html, sf_mk_ctx((int)$im['edition_id']));
        if ($clean['errors']) sf_fail('invalid', null, ['errors' => $clean['errors']]);
        sf_import_set((int)$im['id'], ['result_html' => $clean['html']]);
        return ['import' => sf_import_out(sf_import_by_pid($im['pid']))];
    });
}

/**
 * מעבר לטיוטה: התוצאה נכנסת כטיוטה חדשה במהדורה (sf_rev_insert של 02). מהדורה עם טיוטה פתוחה — נדחה (לא דורסים
 * עבודה). **לא מפרסם, ולא נוגע ב-public_allowed.**
 */
function sf_import_apply(array $in, int $me): array
{
    return sf_tx(function () use ($in, $me) {
        $im = sf_import_by_pid($in['import'] ?? null);
        if ($im['status'] === 'applied') sf_fail('state', 'הייבוא הזה כבר נכנס לטיוטה');
        if ($im['status'] !== 'ready') sf_fail('state', 'העיבוד עוד לא הסתיים');
        if ($im['result_html'] === null) sf_fail('state', 'אין טקסט לטיוטה: זה מקור של עמודים בלבד');
        $eid = (int)$im['edition_id'];
        if (sf_draft($eid)) sf_fail('state', 'במהדורה יש טיוטה פתוחה. כדי לא לדרוס אותה — לפרסם או לבטל אותה, ואז להכניס את הייבוא');
        $r = sf_mk_clean($im['result_html'], sf_mk_ctx($eid));
        if ($r['errors']) sf_fail('invalid', null, ['errors' => $r['errors']]);
        $pub = sf_published($eid);
        $rid = sf_rev_insert($eid, $pub ? (int)$pub['id'] : null, $r['html'], $me);
        $rev = sf_row('SELECT * FROM revisions WHERE id = ?', [$rid]);
        sf_import_set((int)$im['id'], ['status' => 'applied', 'revision_id' => $rid]);
        $ep = (string)sf_val('SELECT pid FROM editions WHERE id = ?', [$eid]);
        sf_audit('content.draft', 'revision', $rev['pid'], ['edition' => $ep, 'from_n' => $pub ? (int)$pub['n'] : null, 'import' => $im['pid'], 'kind' => $im['kind']]);
        return ['edition' => $ep, 'draft' => sf_rev_out($rev)];
    });
}

/** PDF: המנהל סימן שהטקסט נבדק מול העמודים (רק אז התצוגה הזורמת מוצגת). */
function sf_import_checked(array $in): array
{
    $on = $in['on'] ?? null;
    if (!is_bool($on)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $on) {
        $im = sf_import_by_pid($in['import'] ?? null);
        if ($im['kind'] !== 'pdf' || $im['result_html'] === null) sf_fail('state', 'רק ל-PDF עם טקסט');
        sf_import_set((int)$im['id'], ['text_checked' => $on ? 1 : 0]);
        sf_audit($on ? 'import.text_checked' : 'import.text_unchecked', 'import', $im['pid']);
        return ['import' => sf_import_out(sf_import_by_pid($im['pid']))];
    });
}

// ── קבצים: המקור (לתצוגת עמודים) ותמונות שחולצו ─────────────

/** [נתיב, mime] של המקור. */
function sf_import_source_path(array $q): array
{
    $im = sf_import_by_pid($q['import'] ?? null);
    $f = sf_imp_dir($im['pid']) . "/original.{$im['ext']}";
    if (!is_file($f)) sf_fail('not_found');
    $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$im['ext']] ?? 'application/octet-stream';
    return [$f, $mime];
}

/** [נתיב, mime] של תמונה לפי src של הסימון: files/<pid>/<שם>. רק שם שנוצר כאן (מספר / page-N) — שום נתיב אחר. */
function sf_content_file_path(array $q): array
{
    $p = (string)($q['path'] ?? '');
    if (!preg_match('~^files/([a-z0-9]{16})/((?:page-)?[0-9]{1,5})\.(png|jpg|webp)\z~', $p, $m)) sf_fail('not_found');
    $f = sf_imp_dir($m[1]) . "/files/{$m[2]}.{$m[3]}";
    if (!is_file($f)) sf_fail('not_found');
    return [$f, ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$m[3]]];
}

function sf_send_file(array $fm, bool $download = false): never
{
    [$f, $mime] = $fm;
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    if ($download) header('Content-Disposition: attachment');
    readfile($f);
    exit;
}
