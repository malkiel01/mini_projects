<?php
/**
 * ייבוא RTF (שלב 5) — Word, WordPad, TextEdit ו-LibreOffice שומרים בו. מנתח משלנו, בלי ספריות:
 *  - קידוד: \ansicpg (ברירת מחדל) ו-\fcharset של הגופן (177 — עברית, 1255), \'hh — בתים בקידוד הזה, \uN — יוניקוד
 *    (ו-\ucN תווים חלופיים שמדלגים עליהם).
 *  - תווים: מודגש, נטוי, קו תחתון, קו חוצה, עילי / תחתי, גודל (\fs — חצאי נקודה), צבע (\cf), סימון (\highlight),
 *    מוסתר (\v — לא נכנס).
 *  - פסקאות: יישור, כיוון (\rtlpar / \ltrpar), הזחה ושורה ראשונה, רווח לפני / אחרי, מעבר שורה (\line), טאב,
 *    מעבר עמוד (\page, \pagebb). כותרות — \outlinelevel, או סגנון (\sN) ששמו "heading N" / "כותרת N"; עיצוב התו
 *    שבא מהסגנון (מודגש, גודל, צבע) — לא נכנס כעיצוב ישיר. גודל הגופן של "Normal" — גודל הגופן של המסמך.
 *  - רשימות (\ls + \listtext / \pntext — ממוספרת כשהסימון הוא מספר או אות), טבלאות (\intbl, \cell, \row),
 *    קישורים (שדה HYPERLINK), תמונות PNG / JPEG (\pict), הערות שוליים (\footnote — כטקסט), גודל הדף והשוליים
 *    (\paperw, \margl…), כותרת עליונה / תחתונה (הטקסט; שדה PAGE / \chpgn — מספור העמודים).
 *  - בדוח הייבוא: כותרת שונה לעמוד ראשון / זוגי, שדות אחרים (כטקסט), טבלה בתוך טבלה, תמונות בפורמט אחר, צורות.
 */

// \fcharset → code page; 0 (ANSI) ו-1 (ברירת מחדל) — ה-\ansicpg של המסמך
const RTF_CHARSET_CP = [161 => 1253, 162 => 1254, 177 => 1255, 178 => 1256, 186 => 1257, 204 => 1251, 238 => 1250, 222 => 874];
/** יעדים שהטקסט שלהם נאסף (שאר היעדים — מדלגים). */
const RTF_DEPTH = 200;   // עומק הקבוצות המרבי
const RTF_TEXT = ['main', 'fldinst', 'listtext', 'footnote', 'hf', 'stylesheet', 'revtbl'];
const RTF_SKIP = ['fonttbl', 'colortbl', 'info', 'listtable', 'listoverridetable', 'rsidtbl', 'generator',
    'xmlnstbl', 'themedata', 'colorschememapping', 'latentstyles', 'datastore', 'pnseclvl', 'pn', 'object', 'shp', 'nonshppict',
    'annotation', 'atnid',
    'atnauthor', 'bkmkstart', 'bkmkend', 'nesttableprops', 'nonesttables', 'filetbl', 'userprops', 'docvar', 'template', 'mmathPr'];

function import_rtf(string $bytes, string $docId): array
{
    return (new RtfCtx($docId))->run($bytes);
}

final class RtfCtx
{
    private array $fonts = [];       // מספר גופן => code page
    private array $colors = [];      // [null, '#rrggbb', …]
    private int $cp = 1252;          // \ansicpg
    private int $defFont = 0;
    private array $notes = [];
    private array $page = [];
    private array $blocks = [];
    private array $inline = [];      // הפסקה הנוכחית
    private ?array $table = null;    // [שורות] — בזמן \intbl
    private array $row = [];
    private array $cell = [];
    private array $fields = [];      // [{inst, }]
    private string $bytes = '';      // \'hh שעוד לא פוענחו
    private string $marker = '';     // הסימון של פריט הרשימה (\listtext / \pntext) — לפסקה שאחריו
    private array $styles = [];      // \sN => [name, outline, b, fs, cf]
    private ?array $style = null;    // הסגנון שנקרא עכשיו (\stylesheet)
    private string $fn = '';         // הערת השוליים שנקראת עכשיו
    private string $hf = '';         // הכותרת העליונה / התחתונה שנקראת עכשיו
    private array $authors = [''];   // \revtbl — מי שינה (מעקב שינויים)
    private bool $rtlRun = false;    // הטקסט האחרון — בכתב מימין לשמאל (לתווים ניטרליים: רווח, פיסוק)
    private array $st;               // מצב הקבוצה הנוכחית
    private array $stack = [];
    private int $deep = 0;           // קבוצות מעבר לעומק המרבי — נספרות, לא נשמרות
    private int $ssDepth = -1;       // העומק של \stylesheet — כל קבוצה ישירה בתוכו היא סגנון
    private int $hi = 0;             // חצי ראשון של זוג surrogate (\uN מעבר ל-BMP)

    public function __construct(private string $docId) {}

    public function run(string $rtf): array
    {
        $this->st = $this->freshState();
        $n = strlen($rtf);
        $i = 0;
        while ($i < $n) {
            $c = $rtf[$i];
            if ($c === '{' && count($this->stack) >= RTF_DEPTH) { $this->deep++; $i++; continue; }
            if ($c === '}' && $this->deep > 0) { $this->deep--; $i++; continue; }
            if ($c === '{') {
                $this->flushBytes();
                // סגנון חדש בטבלת הסגנונות (קבוצה ישירה בתוכה); בלי \sN — Normal (כך Word כותב אותו)
                if ($this->st['dest'] === 'stylesheet' && count($this->stack) === $this->ssDepth) $this->style = ['n' => 0, 'name' => ''];
                $this->stack[] = $this->st;
                $this->st['star'] = false;
                $i++;
                continue;
            }
            if ($c === '}') { $this->flushBytes(); $this->endGroup(); $i++; continue; }
            if ($c === "\r" || $c === "\n") { $i++; continue; }
            // הנתונים של תמונה (hex) — בבת אחת
            if ($this->st['pict'] !== null && ctype_xdigit($c)) {
                $len = strspn($rtf, "0123456789abcdefABCDEF\r\n", $i);
                if ($this->st['dest'] !== 'skip') $this->st['pict']['hex'] .= str_replace(["\r", "\n"], '', substr($rtf, $i, $len));
                $i += $len;
                continue;
            }
            if ($c !== '\\') { $this->char($c); $i++; continue; }
            // מילת בקרה / סמל בקרה
            $i++;
            if ($i >= $n) break;
            $c = $rtf[$i];
            if ($c === "'") {   // בית בקידוד הנוכחי
                $hex = substr($rtf, $i + 1, 2);
                $i += 3;
                if ($this->st['skip'] > 0) { $this->st['skip']--; continue; }
                if (ctype_xdigit($hex)) $this->byte(chr(hexdec($hex)));
                continue;
            }
            if (!ctype_alpha($c)) {   // סמל בקרה
                $i++;
                match ($c) {
                    '\\', '{', '}' => $this->char($c),
                    '~' => $this->text("\u{00A0}"),
                    '_' => $this->text("\u{2011}"),
                    '*' => $this->st['star'] = true,
                    "\n", "\r" => $this->word('par', null),
                    default => null,   // \- (מקף רך), \: ועוד
                };
                continue;
            }
            preg_match('/\G([a-zA-Z]{1,32})(-?\d{1,10})?\s?/', $rtf, $m, 0, $i);
            $i += strlen($m[0]);
            $this->flushBytes();
            $this->word($m[1], isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null);
        }
        $this->flushBytes();
        $this->endPara(true);
        $this->endTable();
        $content = $this->listify($this->blocks) ?: [para()];
        unset($this->page['_w'], $this->page['_h']);
        if (($fs = $this->styles[0]['fs'] ?? null) && $fs !== 24) $this->page['font']['size'] = $fs / 2;
        if ($this->page && !isset($this->page['numbering'])) $this->page['numbering'] = ['show' => false];
        $json = ['type' => 'doc', 'content' => $content];
        return ['json' => $json, 'page' => $this->page ?: null, 'report' => $this->report()];
    }

    private function freshState(): array
    {
        return ['dest' => 'main', 'star' => false, 'uc' => 1, 'skip' => 0, 'font' => null, 'field' => null, 'pict' => null, 'hfk' => null,
            'b' => false, 'i' => false, 'ul' => false, 'strike' => false, 'sup' => false, 'sub' => false, 'fs' => null, 'cf' => 0,
            'hl' => 0, 'v' => false, 'ins' => null, 'del' => null, 'ab' => null, 'ai' => null, 'afs' => null, 'af' => null,
            'p' => $this->freshPara()];
    }

    private function freshPara(): array
    {
        return ['align' => null, 'dir' => null, 'li' => 0, 'ri' => 0, 'lin' => null, 'fi' => 0, 'sb' => null, 'sa' => null,
            'outline' => null, 'intbl' => false, 'ls' => 0, 'ilvl' => 0, 'pagebb' => false, 'style' => 0];
    }

    // ---------- קבוצות ויעדים ----------

    private function endGroup(): void
    {
        $st = $this->st;
        if ($st['pict'] !== null && ($this->stack ? end($this->stack)['pict'] : null) === null) $this->picture($st['pict']);
        $parent = $this->stack ? end($this->stack) : $this->freshState();
        if ($st['dest'] === 'fldinst' && $st['field'] !== null && $parent['dest'] !== 'fldinst') {
            // סוף ההוראה: כתובת הקישור (HYPERLINK "…"); PAGE בכותרת — מספור העמודים (והתוצאה, המספר עצמו, לא נכנסת)
            $f = &$this->fields[$st['field']];
            if (preg_match('/HYPERLINK\s+"([^"]+)"/i', $f['inst'], $m)) $f['href'] = $m[1];
            elseif ($f['in'] === 'hf' && preg_match('/^\s*PAGE\b/i', $f['inst'])) { $f['page'] = true; $this->pageNumber(); }
            elseif (trim($f['inst']) !== '') $this->note('fields');
            unset($f);
        }
        if ($st['dest'] === 'footnote' && $parent['dest'] !== 'footnote') {
            $t = trim(preg_replace('/\s+/u', ' ', $this->fn));
            if ($t !== '') $this->inline[] = ['type' => 'footnote', 'attrs' => ['text' => mb_substr($t, 0, 2000), 'content' => null]];
            $this->fn = '';
        }
        if ($st['dest'] === 'hf' && $parent['dest'] !== 'hf') {
            $t = trim(preg_replace(['/[ \t]+/u', '/\s*\n\s*/u'], [' ', "\n"], $this->hf));
            if ($t !== '') $this->page[$st['hfk']] = $t;
            $this->hf = '';
        }
        // סוף סגנון; סגנון שדילגנו עליו (\*\cs — סגנון תו) — לא נשמר
        if (count($this->stack) === $this->ssDepth + 1 && $parent['dest'] === 'stylesheet') {
            if ($st['dest'] === 'stylesheet') $this->endStyle();
            $this->style = null;
        }
        array_pop($this->stack);
        // מאפייני פסקה נשמרים מעבר לסוף קבוצה של תוכן (כמו ב-Word); מאפייני תו — לא
        if ($st['dest'] === 'main' && $parent['dest'] === 'main') $parent['p'] = $st['p'];
        $this->st = $parent;
    }

    private function word(string $w, ?int $n): void
    {
        $s = &$this->st;
        // יעד חדש (בתחילת קבוצה)
        if ($s['star'] && !in_array($w, ['fldinst', 'listtext', 'pntext', 'shppict', 'footnote', 'revtbl'], true)) { $s['dest'] = 'skip'; $s['star'] = false; return; }
        $s['star'] = false;
        if ($s['dest'] === 'skip') return;
        if ($s['dest'] === 'fonttbl') { if ($w === 'f') $s['font'] = $n; elseif ($w === 'fcharset' && $s['font'] !== null) $this->fonts[$s['font']] = RTF_CHARSET_CP[$n] ?? $this->cp; return; }
        if ($s['dest'] === 'colortbl') { if (in_array($w, ['red', 'green', 'blue'], true)) $s['rgb'][$w] = (int)$n; return; }
        if ($s['dest'] === 'stylesheet') {
            if (in_array($w, ['s', 'cs', 'ds', 'ts'], true)) $this->style = ['n' => $w === 's' ? (int)$n : null, 'name' => ''];
            elseif ($this->style === null) return;
            elseif ($w === 'outlinelevel') $this->style['outline'] = (int)$n;
            elseif (in_array($w, ['b', 'i', 'ab', 'ai'], true)) $this->style[ltrim($w, 'a')] = ($this->style[ltrim($w, 'a')] ?? false) || $n === null || $n !== 0;
            elseif ($w === 'fs') $this->style['fs'] = $n;
            elseif ($w === 'cf') $this->style['cf'] = $n;
            elseif ($w === 'sb' || $w === 'sa') $this->style[$w] = $n;
            elseif ($w === 'u' && $n !== null) { $this->unicode($n); $s['skip'] = $s['uc']; }
            return;
        }
        if ($s['pict'] !== null) {
            if (in_array($w, ['pngblip', 'jpegblip', 'emfblip', 'wmetafile', 'dibitmap', 'wbitmap', 'macpict'], true)) $s['pict']['fmt'] = $w;
            elseif (in_array($w, ['picw', 'pich', 'picwgoal', 'pichgoal', 'picscalex', 'picscaley'], true)) $s['pict'][$w] = $n;
            elseif ($w === 'bin') $s['pict']['fmt'] = 'bin';
            return;
        }
        switch ($w) {
            // יעדים
            case 'fonttbl': $s['dest'] = 'fonttbl'; return;
            case 'colortbl': $s['dest'] = 'colortbl'; $s['rgb'] = []; return;
            case 'fldinst': $s['dest'] = 'fldinst'; return;
            case 'fldrslt': $s['dest'] = $this->fields[$s['field']]['in'] ?? 'main'; return;
            case 'field': $this->fields[] = ['inst' => '', 'href' => null, 'page' => false, 'in' => $s['dest']]; $s['field'] = count($this->fields) - 1; return;
            case 'stylesheet': $s['dest'] = 'stylesheet'; $this->ssDepth = count($this->stack); return;
            case 'revtbl': $s['dest'] = 'revtbl'; $this->authors = []; return;
            case 'listtext': case 'pntext': $s['dest'] = 'listtext'; $this->marker = ''; return;
            case 'pict': $s['pict'] = ['fmt' => null, 'hex' => '']; return;
            // כותרת רגילה (\header, ובמסמך עם זוגי / אי-זוגי — \headerr); לעמוד ראשון / זוגי — בדוח
            case 'header': case 'headerr': case 'footer': case 'footerr':
                if ($s['dest'] !== 'main') { $s['dest'] = 'skip'; return; }
                $s['hfk'] = $w[0] === 'h' ? 'header' : 'footer';
                if (isset($this->page[$s['hfk']]) && strlen($w) > 6) { $s['dest'] = 'skip'; return; }
                $s['dest'] = 'hf';
                $this->hf = '';
                return;
            case 'headerl': case 'headerf': case 'footerl': case 'footerf': $this->note('hf_extra'); $s['dest'] = 'skip'; return;
            case 'footnote':
                if ($s['dest'] !== 'main') { $s['dest'] = 'skip'; return; }
                $s['dest'] = 'footnote';
                $this->fn = '';
                return;
            case 'chpgn': if ($s['dest'] === 'hf') $this->pageNumber(); return;
            case 'nesttableprops': $this->note('nested'); $s['dest'] = 'skip'; return;
            case 'shp': case 'shpgrp': case 'object': $this->note('shapes'); $s['dest'] = 'skip'; return;
        }
        if (in_array($w, RTF_SKIP, true)) { $s['dest'] = 'skip'; return; }
        if (!in_array($s['dest'], RTF_TEXT, true)) return;
        $on = $n === null || $n !== 0;
        switch ($w) {
            // מסמך
            case 'ansicpg': $this->cp = $n ?: 1252; return;
            case 'deff': $this->defFont = (int)$n; return;
            case 'uc': $s['uc'] = max(0, (int)$n); return;
            case 'u':
                if ($n === null) return;
                $this->unicode($n);
                $s['skip'] = $s['uc'];
                return;
            case 'paperw': $this->page['_w'] = $n; $this->pageSize(); return;
            case 'paperh': $this->page['_h'] = $n; $this->pageSize(); return;
            case 'landscape': $this->page['orientation'] = 'landscape'; return;
            case 'margl': case 'margr': case 'margt': case 'margb':
                $this->page['margins'][['margl' => 'left', 'margr' => 'right', 'margt' => 'top', 'margb' => 'bottom'][$w]] = round($n / TWIP_PER_MM_IMPORT, 1);
                return;
            // תווים
            case 'plain': foreach (['b', 'i', 'ul', 'strike', 'sup', 'sub', 'v'] as $k) $s[$k] = false; $s['fs'] = null; $s['cf'] = 0; $s['hl'] = 0; $s['ins'] = $s['del'] = $s['ab'] = $s['ai'] = $s['afs'] = $s['af'] = null; return;
            case 'ab': $s['ab'] = $on; return;
            case 'ai': $s['ai'] = $on; return;
            case 'afs': $s['afs'] = $n; return;
            case 'af': $this->flushBytes(); $s['af'] = $n; return;
            // מעקב שינויים: הוספה (\revised) ומחיקה (\deleted), עם מי (\revauth) ומתי (\revdttm)
            case 'revised': $s['ins'] = $on ? ($s['ins'] ?? []) : null; return;
            case 'deleted': $s['del'] = $on ? ($s['del'] ?? []) : null; return;
            case 'revauth': case 'revdttm': if ($s['ins'] !== null) $s['ins'][$w === 'revauth' ? 'a' : 'd'] = $n; return;
            case 'revauthdel': case 'revdttmdel': if ($s['del'] !== null) $s['del'][$w === 'revauthdel' ? 'a' : 'd'] = $n; return;
            case 'b': $s['b'] = $on; return;
            case 'i': $s['i'] = $on; return;
            case 'ul': case 'ulw': $s['ul'] = $on ? 'single' : false; return;
            case 'uldb': case 'ululdbwave': $s['ul'] = $on ? 'double' : false; return;
            case 'uld': case 'ulthd': $s['ul'] = $on ? 'dotted' : false; return;
            case 'uldash': case 'uldashd': case 'uldashdd': case 'ulldash': case 'ulthdash': case 'ulthdashd': case 'ulthdashdd': case 'ulthldash':
                $s['ul'] = $on ? 'dashed' : false; return;
            case 'ulth': $s['ul'] = $on ? 'thick' : false; return;
            case 'ulwave': case 'ulhwave': $s['ul'] = $on ? 'wavy' : false; return;
            case 'ulnone': $s['ul'] = false; return;
            case 'strike': case 'striked': $s['strike'] = $on; return;
            case 'super': $s['sup'] = true; $s['sub'] = false; return;
            case 'sub': $s['sub'] = true; $s['sup'] = false; return;
            case 'nosupersub': $s['sup'] = $s['sub'] = false; return;
            case 'fs': $s['fs'] = $n; return;
            case 'cf': $s['cf'] = (int)$n; return;
            case 'highlight': case 'cb': case 'chcbpat': $s['hl'] = (int)$n; return;
            case 'v': $s['v'] = $on; return;
            case 'f': $this->flushBytes(); $s['font'] = $n; return;
            // תווים מיוחדים
            case 'tab': $this->text("\t"); return;
            case 'line': $this->flushBytes(); if ($s['dest'] === 'main') { if (!$s['v']) $this->inline[] = ['type' => 'hardBreak']; } else $this->text("\n"); return;
            case 'emdash': $this->text('—'); return;
            case 'endash': $this->text('–'); return;
            case 'bullet': $this->text('•'); return;
            case 'lquote': $this->text('‘'); return;
            case 'rquote': $this->text('’'); return;
            case 'ldblquote': $this->text('“'); return;
            case 'rdblquote': $this->text('”'); return;
            case 'emspace': case 'enspace': case 'qmspace': $this->text(' '); return;
            case 'zwj': $this->text("\u{200D}"); return;
            case 'zwnj': $this->text("\u{200C}"); return;
            case 'rtlmark': $this->text("\u{200F}"); return;
            case 'ltrmark': $this->text("\u{200E}"); return;
            // פסקאות
            case 'pard': $s['p'] = $this->freshPara(); return;
            case 'par': $this->flushBytes(); if ($s['dest'] === 'main') $this->endPara(); else $this->text("\n"); return;
            case 'page': $this->flushBytes(); if ($s['dest'] === 'main') { $this->endPara(true); $this->endTable(); $this->blocks[] = ['type' => 'pageBreak']; } return;
            case 'pagebb': $s['p']['pagebb'] = true; return;
            case 'ql': $s['p']['align'] = 'left'; return;
            case 'qr': $s['p']['align'] = 'right'; return;
            case 'qc': $s['p']['align'] = 'center'; return;
            case 'qj': case 'qd': $s['p']['align'] = 'justify'; return;
            case 'rtlpar': $s['p']['dir'] = 'rtl'; return;
            case 'ltrpar': $s['p']['dir'] = 'ltr'; return;
            case 'li': $s['p']['li'] = (int)$n; return;
            case 'ri': $s['p']['ri'] = (int)$n; return;
            case 'lin': $s['p']['lin'] = (int)$n; return;
            case 'fi': $s['p']['fi'] = (int)$n; return;
            case 'sb': $s['p']['sb'] = (int)$n; return;
            case 'sa': $s['p']['sa'] = (int)$n; return;
            case 'outlinelevel': $s['p']['outline'] = (int)$n; return;
            case 's': $s['p']['style'] = (int)$n; return;
            case 'ls': $s['p']['ls'] = (int)$n; return;
            case 'ilvl': $s['p']['ilvl'] = max(0, min(8, (int)$n)); return;
            // טבלאות
            case 'intbl': $s['p']['intbl'] = true; return;
            case 'cell': $this->flushBytes(); $this->endCell(); return;
            case 'row': $this->flushBytes(); $this->endRow(); return;
            case 'nestcell': case 'nestrow': $this->note('nested'); return;
        }
    }

    private function pageSize(): void
    {
        $w = $this->page['_w'] ?? null;
        $h = $this->page['_h'] ?? null;
        if (!$w || !$h) return;
        $this->page['size'] = abs(min($w, $h) / TWIP_PER_MM_IMPORT - 215.9) < 3 ? 'Letter' : 'A4';
        if ($w > $h) $this->page['orientation'] = 'landscape';
    }

    // ---------- טקסט ----------

    private function char(string $c): void
    {
        $s = &$this->st;
        if ($s['dest'] === 'skip') return;
        if ($s['dest'] === 'stylesheet' && $c === ';') { $this->flushBytes(); $this->endStyle(); return; }
        if ($s['dest'] === 'revtbl' && $c === ';') { $this->flushBytes(); $this->authors[] = ''; return; }
        if ($s['dest'] === 'colortbl') {
            if ($c === ';') { $rgb = $s['rgb'] ?? []; $this->colors[] = $rgb ? sprintf('#%02x%02x%02x', $rgb['red'] ?? 0, $rgb['green'] ?? 0, $rgb['blue'] ?? 0) : null; $s['rgb'] = []; }
            return;
        }
        if ($s['pict'] !== null) { if (ctype_xdigit($c)) $s['pict']['hex'] .= $c; return; }
        if ($s['skip'] > 0) { $s['skip']--; return; }
        $this->byte($c);
    }

    /** בית אחד בקידוד של הגופן הנוכחי — נאסף, ומפוענח כשמתחלף משהו. */
    private function byte(string $b): void
    {
        if (in_array($this->st['dest'], RTF_TEXT, true)) $this->bytes .= $b;
    }

    /** \uN (שלילי — מעל 32767); זוג surrogate — תו אחד (אימוג'י ושאר מה שמעבר ל-BMP). */
    private function unicode(int $n): void
    {
        $cp = $n < 0 ? $n + 65536 : $n;
        if ($cp >= 0xD800 && $cp <= 0xDBFF) { $this->hi = $cp; return; }
        if ($cp >= 0xDC00 && $cp <= 0xDFFF) { $cp = $this->hi ? 0x10000 + (($this->hi - 0xD800) << 10) + ($cp - 0xDC00) : 0; }
        $this->hi = 0;
        if ($cp > 0) $this->text(mb_chr($cp, 'UTF-8') ?: '');
    }

    private function flushBytes(): void
    {
        if ($this->bytes === '') return;
        $cp = $this->fonts[$this->st['font'] ?? $this->defFont] ?? $this->cp;
        // הגופן הרגיל מערבי והגופן של כתב מימין לשמאל (\af) עברי / ערבי — הבתים בקידוד שלו (Word בעברית)
        if ($cp === 1252 && in_array($af = $this->fonts[$this->st['af'] ?? -1] ?? null, [1255, 1256], true)) $cp = $af;
        $s = $cp === 65001 ? $this->bytes : @iconv('CP' . $cp, 'UTF-8//IGNORE', $this->bytes);
        $this->bytes = '';
        if ($s === false || $s === '') return;
        $this->text($s);
    }

    private function text(string $t): void
    {
        if ($this->bytes !== '') $this->flushBytes();
        $s = $this->st;
        if ($t === '' || $s['v']) return;
        if ($s['dest'] === 'fldinst') { $this->fields[$s['field']]['inst'] .= $t; return; }
        if ($s['dest'] === 'listtext') { $this->marker .= $t; return; }
        if ($s['dest'] === 'stylesheet') { if ($this->style) $this->style['name'] .= $t; return; }
        if ($s['dest'] === 'footnote') { $this->fn .= $t; return; }
        if ($s['dest'] === 'revtbl') { $this->authors[array_key_last($this->authors) ?? 0] = ($this->authors[array_key_last($this->authors) ?? 0] ?? '') . $t; return; }
        if ($s['dest'] === 'hf') { if (!($this->fields[$s['field'] ?? -1]['page'] ?? false)) $this->hf .= $t; return; }
        if ($s['dest'] !== 'main') return;
        if (preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $t)) $this->rtlRun = true;
        elseif (preg_match('/\p{L}/u', $t)) $this->rtlRun = false;
        $marks = $this->marks();
        $last = count($this->inline) - 1;
        if ($last >= 0 && ($this->inline[$last]['type'] ?? '') === 'text' && ($this->inline[$last]['marks'] ?? []) == $marks) $this->inline[$last]['text'] .= $t;
        else $this->inline[] = text_node($t, $marks);
    }

    private function marks(): array
    {
        $s = $this->st;
        // עיצוב שבא מסגנון הכותרת (מודגש, גודל, צבע) — הכותרת כבר מעוצבת; בשאר — הגודל של Normal הוא גודל המסמך
        $head = $this->outline($s['p']) !== null;
        $sty = $head ? ($this->styles[$s['p']['style']] ?? []) : [];
        // טקסט מימין לשמאל — מאפייני ה-bidi (\ab, \ai, \afs; כמו bCs / szCs ב-docx), אם ניתנו
        $rtl = $this->rtlRun;
        $b = $rtl ? ($s['ab'] ?? $s['b']) : $s['b'];
        $i = $rtl ? ($s['ai'] ?? $s['i']) : $s['i'];
        $fs = $rtl ? ($s['afs'] ?? $s['fs']) : $s['fs'];
        $m = [];
        if ($b && !($sty['b'] ?? false)) $m[] = ['type' => 'bold'];
        if ($i && !($sty['i'] ?? false)) $m[] = ['type' => 'italic'];
        if ($s['ul']) $m[] = $s['ul'] === 'single' ? ['type' => 'underline'] : ['type' => 'underline', 'attrs' => ['ul' => $s['ul']]];
        if ($s['strike']) $m[] = ['type' => 'strike'];
        if ($s['sup']) $m[] = ['type' => 'superscript'];
        if ($s['sub']) $m[] = ['type' => 'subscript'];
        $ts = [];
        if (($c = $this->colors[$s['cf']] ?? null) && $c !== '#000000' && $s['cf'] !== ($sty['cf'] ?? null)) $ts['color'] = $c;
        if ($fs && $fs !== ($head ? ($sty['fs'] ?? null) : ($this->styles[0]['fs'] ?? 24))) $ts['fontSize'] = ($fs / 2) . 'pt';
        if ($ts) $m[] = ['type' => 'textStyle', 'attrs' => $ts];
        if ($s['hl'] && ($c = $this->colors[$s['hl']] ?? null) && $c !== '#ffffff') $m[] = ['type' => 'highlight', 'attrs' => ['color' => $c]];
        foreach (['ins', 'del'] as $k) if ($s[$k] !== null) $m[] = ['type' => $k, 'attrs' => $this->revision($s[$k])];
        // קישור — מהשדה שבתוכו התוצאה
        foreach (array_reverse(array_merge($this->stack, [$s])) as $g) {
            if ($g['field'] !== null && ($href = $this->fields[$g['field']]['href'] ?? null)) { $m[] = ['type' => 'link', 'attrs' => ['href' => $href]]; break; }
        }
        return $m;
    }

    // ---------- פסקאות, רשימות וטבלאות ----------

    /** סוף פסקה: לבלוקים, או לתא (\intbl). $final — בסוף הקובץ / לפני מעבר עמוד: פסקה ריקה לא נוספת. */
    private function endPara(bool $final = false): void
    {
        $p = $this->st['p'];
        if ($final && !$this->inline) return;
        $block = $this->paraBlock($p);
        $this->inline = [];
        $this->marker = '';
        if ($p['intbl']) { $this->cell[] = $block; return; }
        $this->endTable();
        if ($p['pagebb'] && $this->blocks) $this->blocks[] = ['type' => 'pageBreak'];
        $this->blocks[] = $block;
    }

    private function paraBlock(array $p): array
    {
        $a = [];
        $sty = $this->styles[$p['style']] ?? [];
        // יישור לצד ההתחלה — ברירת המחדל; משמאל לימין — רק פסקה שאינה \rtlpar ובלי עברית / ערבית (כמו ב-docx);
        // רווח כמו בסגנון — לא נשמר כעיצוב ישיר
        if ($p['align'] && $p['align'] !== ($p['dir'] === 'rtl' ? 'right' : 'left')) $a['textAlign'] = $p['align'];
        $text = implode('', array_map(fn($c) => $c['text'] ?? '', $this->inline));
        if ($p['dir'] !== 'rtl' && preg_match('/\p{Latin}/u', $text) && !preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text)) $a['dir'] = 'ltr';
        // ההזחה בצד ההתחלה: \lin, ואם אין — \ri בפסקה מימין לשמאל, \li בשמאל לימין
        $start = $p['lin'] ?? ($p['dir'] === 'rtl' ? $p['ri'] : $p['li']);
        $list = $p['ls'] !== 0 || trim($this->marker) !== '';
        if (!$list && $start > 0) $a['indent'] = max(0, min(8, (int)round($start / 20 / 36)));
        if ($p['fi'] && !$list) $a['firstLine'] = round($p['fi'] / 20, 1);
        if ($p['sb'] !== null && $p['sb'] !== ($sty['sb'] ?? null)) $a['spaceBefore'] = round($p['sb'] / 20, 1);
        if ($p['sa'] !== null && $p['sa'] !== ($sty['sa'] ?? null)) $a['spaceAfter'] = round($p['sa'] / 20, 1);
        $inline = $this->inline;
        // רווחים בסוף הפסקה (נפוץ לפני \par) — לא נשמרים
        $k = count($inline) - 1;
        if ($k >= 0 && ($inline[$k]['type'] ?? '') === 'text') { $inline[$k]['text'] = rtrim($inline[$k]['text'], ' '); if ($inline[$k]['text'] === '') array_pop($inline); }
        $lvl = $this->outline($p);
        $type = $lvl !== null ? 'heading' : 'paragraph';
        if ($type === 'heading') $a['level'] = $lvl + 1;
        $block = para($inline, $a, $type);
        $marker = trim(str_replace("\t", '', $this->marker), " \u{00A0}");
        if (($p['ls'] !== 0 || $marker !== '') && !$p['intbl']) {
            // כותרת ממוספרת — מספור כותרות (של המסמך), לא רשימה
            if ($type === 'heading') { $this->page['heading_numbers'] = true; return $block; }
            // ממוספרת: יש בסימון ספרה ("3.", "1.2", "(4)"), או אות עם נקודה / סוגר ("א.", "b)"); אחרת — תבליט
            $ordered = preg_match('/\p{N}/u', $marker) || preg_match('/^\(?\p{L}{1,4}[.)]$/u', $marker);
            $num = $ordered && preg_match('/(\d+)\D*$/', $marker, $m) ? (int)$m[1] : 1;
            $block['_list'] = ['lvl' => $p['ilvl'], 'ordered' => $ordered, 'num' => $num];
        }
        return $block;
    }

    private function endCell(): void
    {
        if ($this->inline || !$this->cell) { $this->cell[] = $this->paraBlock(['intbl' => true] + $this->st['p']); $this->inline = []; $this->marker = ''; }
        $this->row[] = ['type' => 'tableCell', 'content' => $this->listify($this->cell) ?: [para()]];
        $this->cell = [];
    }

    private function endRow(): void
    {
        if ($this->cell || $this->inline) $this->endCell();
        if ($this->row) { $this->table ??= []; $this->table[] = ['type' => 'tableRow', 'content' => $this->row]; }
        $this->row = [];
    }

    private function endTable(): void
    {
        if ($this->row || $this->cell) $this->endRow();
        if (!$this->table) return;
        // כל השורות באותו מספר תאים (ProseMirror) — משלימים בתאים ריקים
        $w = max(array_map(fn($r) => count($r['content']), $this->table));
        foreach ($this->table as &$r) while (count($r['content']) < $w) $r['content'][] = ['type' => 'tableCell', 'content' => [para()]];
        unset($r);
        $this->blocks[] = ['type' => 'table', 'content' => $this->table];
        $this->table = null;
    }

    /** פסקאות רשימה רצופות → רשימות (מקוננות לפי \ilvl); סוג אחר באותה רמה — רשימה חדשה. */
    private function listify(array $blocks): array
    {
        $out = [];
        $run = [];
        $flush = function () use (&$out, &$run) {
            if ($run) array_push($out, ...$this->lists($run, 0));
            $run = [];
        };
        foreach ($blocks as $b) {
            if (isset($b['_list'])) { $run[] = $b; continue; }
            $flush();
            $out[] = $b;
        }
        $flush();
        return $out;
    }

    private function lists(array $items, int $lvl): array
    {
        $lists = [];
        $cur = null;
        $i = 0;
        while ($i < count($items)) {
            $it = $items[$i];
            $type = $it['_list']['ordered'] ? 'orderedList' : 'bulletList';
            if (!$cur || $cur['type'] !== $type) {
                if ($cur) $lists[] = $cur;
                // מספר ההתחלה — מהסימון של הפריט הראשון (רשימה שממשיכה אחרי הפסקה: "3.")
                $cur = ['type' => $type, 'content' => []] + ($type === 'orderedList' ? ['attrs' => ['start' => max(1, min(9999, $it['_list']['num']))]] : []);
            }
            $p = $it;
            unset($p['_list']);
            $li = ['type' => 'listItem', 'content' => [$p]];
            $i++;
            $sub = [];
            while ($i < count($items) && $items[$i]['_list']['lvl'] > $lvl) $sub[] = $items[$i++];
            if ($sub) array_push($li['content'], ...$this->lists($sub, $lvl + 1));
            $cur['content'][] = $li;
        }
        if ($cur) $lists[] = $cur;
        return $lists;
    }

    /** רמת הכותרת (0–8) של הפסקה — \outlinelevel, או של הסגנון; null — לא כותרת. */
    private function outline(array $p): ?int
    {
        $o = $p['outline'] ?? ($this->styles[$p['style']]['outline'] ?? null);
        return $o !== null && $o >= 0 && $o < 9 ? $o : null;
    }

    private function endStyle(): void
    {
        $st = $this->style;
        $this->style = null;
        if ($st === null || $st['n'] === null) return;
        $st['name'] = trim($st['name']);
        if (!isset($st['outline']) && preg_match('/^(heading|כותרת)\s*([1-9])$/iu', $st['name'], $m)) $st['outline'] = (int)$m[2] - 1;
        $this->styles[$st['n']] = $st;
    }

    /** מי ומתי של שינוי במעקב: \revauth — מספר ב-\revtbl; \revdttm — תאריך דחוס (דקה, שעה, יום, חודש, שנה) */
    private function revision(array $r): array
    {
        $who = trim($this->authors[$r['a'] ?? 0] ?? '');
        $out = ['author' => $who === '' || $who === 'Unknown' ? 'לא ידוע' : $who];
        if ($v = $r['d'] ?? 0) $out['date'] = sprintf('%04d-%02d-%02dT%02d:%02d:00Z', (($v >> 20) & 511) + 1900, ($v >> 16) & 15, ($v >> 11) & 31, ($v >> 6) & 31, $v & 63);
        return $out;
    }

    /** מספר עמוד בכותרת — מספור העמודים, למעלה או למטה. */
    private function pageNumber(): void
    {
        $this->page['numbering'] = ['show' => true, 'position' => $this->st['hfk'] === 'header' ? 'top' : 'bottom'];
    }

    // ---------- תמונות ----------

    private function picture(array $pict): void
    {
        if (!in_array($pict['fmt'], ['pngblip', 'jpegblip'], true) || $pict['hex'] === '') { $this->note('images'); return; }
        $bytes = (string)hex2bin(strlen($pict['hex']) % 2 ? substr($pict['hex'], 0, -1) : $pict['hex']);
        try { $m = media_store($this->docId, $bytes); } catch (ApiError $e) { $this->note('images'); return; }
        // גודל התצוגה: picwgoal / pichgoal בטוויפים (וקנה מידה באחוזים)
        $w = !empty($pict['picwgoal']) ? (int)round($pict['picwgoal'] / 15 * ($pict['picscalex'] ?? 100) / 100) : $m['width'];
        $h = !empty($pict['pichgoal']) ? (int)round($pict['pichgoal'] / 15 * ($pict['picscaley'] ?? 100) / 100) : $m['height'];
        $this->inline[] = ['type' => 'image', 'attrs' => ['media' => $m['id'], 'src' => $m['url'], 'width' => max(4, min(2000, $w)), 'height' => max(4, min(2000, $h))]];
    }

    // ---------- דוח ----------

    private function note(string $k): void
    {
        $this->notes[$k] = ($this->notes[$k] ?? 0) + 1;
    }

    private function report(): array
    {
        $out = [];
        foreach (IMPORT_REPORT as $k => [$label, $detail]) {
            if (!($n = $this->notes[$k] ?? 0)) continue;
            $out[] = ['key' => $k, 'label' => $label, 'count' => $n, 'detail' => $detail];
        }
        return $out;
    }
}
