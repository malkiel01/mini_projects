<?php
/**
 * העימוד ל-PDF: מה-JSON של המנוע לעמודים. הכללים — כמו בעורך על המסך (assets/js/pagination.js ו-editor.css),
 * כדי שה-PDF ייראה כמו המסך:
 *  - פסקה = שורות; ריווח לפני/אחרי כ-padding; גובה שורה = ריווח השורות × גודל הגופן (כמו line-height ב-CSS),
 *    או "מדויק" בנקודות.
 *  - שבירת עמוד בין שורות, לפחות 2 שורות של פסקה בתחתית עמוד, כותרת לא נשארת לבד בתחתית, מעבר עמוד ידני.
 *  - טבלה נשברת בין שורות (שורת הכותרת חוזרת בראש העמוד הבא — כמו ב-Word).
 *  - תמונה: בשורה, ריבוע/צמוד (הטקסט לצדה), מעל ומתחת, מאחורי/לפני הטקסט; חיתוך, סיבוב, כיתוב.
 * יחידות: נקודות. y נמדד מראש אזור הטקסט כלפי מטה; ההמרה לקואורדינטות PDF — ב-PdfCanvas.
 */

require_once __DIR__ . '/writer.php';
require_once __DIR__ . '/bidi.php';
require_once __DIR__ . '/../export.php';

const PT_PER_MM = 72 / 25.4;
const PT_PER_PX = 0.75;

/** ציור על עמוד: y מלמעלה (נקודות), שכבה bg / main / fg. */
final class PdfCanvas
{
    public function __construct(public PdfWriter $pdf, public int $page, public float $H) {}

    public function add(string $layer, string $ops): void
    {
        $this->pdf->pages[$this->page][$layer] .= $ops;
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb, string $layer = 'main'): void
    {
        $this->add($layer, self::rgb($rgb, 'rg') . ' ' . self::n($x) . ' ' . self::n($this->H - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . " re f\n");
    }

    public function strokeRect(float $x, float $y, float $w, float $h, array $rgb, float $lw): void
    {
        $this->add('main', self::rgb($rgb, 'RG') . ' ' . self::n($lw) . ' w ' . self::n($x) . ' ' . self::n($this->H - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . " re S\n");
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $lw, string $layer = 'main'): void
    {
        $this->add($layer, self::rgb($rgb, 'RG') . ' ' . self::n($lw) . ' w ' . self::n($x1) . ' ' . self::n($this->H - $y1) . ' m '
            . self::n($x2) . ' ' . self::n($this->H - $y2) . " l S\n");
    }

    /** קו מקווקו / מנוקד: dash — [קו, רווח] בנקודות. */
    public function dashLine(float $x1, float $y, float $x2, array $rgb, float $lw, array $dash): void
    {
        $this->add('main', 'q ' . self::rgb($rgb, 'RG') . ' ' . self::n($lw) . ' w [' . self::n($dash[0]) . ' ' . self::n($dash[1]) . '] 0 d ' . self::n($x1) . ' ' . self::n($this->H - $y) . ' m '
            . self::n($x2) . ' ' . self::n($this->H - $y) . " l S Q\n");
    }

    /** קו גלי (זיגזג רך) מ-x1 עד x2 סביב y. */
    public function waveLine(float $x1, float $y, float $x2, array $rgb, float $lw, float $amp): void
    {
        $step = $amp * 2;
        $ops = self::rgb($rgb, 'RG') . ' ' . self::n($lw) . ' w ' . self::n($x1) . ' ' . self::n($this->H - $y) . ' m';
        for ($x = $x1, $k = 0; $x < $x2; $x += $step, $k++) {
            $ops .= ' ' . self::n(min($x2, $x + $step)) . ' ' . self::n($this->H - $y + ($k % 2 ? -$amp : $amp)) . ' l';
        }
        $this->add('main', $ops . " S\n");
    }

    /** טקסט: גליפים (hex) מקו בסיס y. italic — הטיה; rise — עילי/תחתי; tc — ריווח בין תווים. */
    public function glyphs(TtfFont $f, float $size, float $x, float $baseline, string $hex, array $rgb, bool $italic, float $rise, float $tc): void
    {
        $name = $this->pdf->font($f);
        $skew = $italic ? ' 0.21' : ' 0';
        $this->add('main', 'BT ' . self::rgb($rgb, 'rg') . " $name " . self::n($size) . ' Tf ' . ($tc ? self::n($tc) . ' Tc ' : '') . ($rise ? self::n($rise) . ' Ts ' : '')
            . '1 0' . $skew . ' 1 ' . self::n($x) . ' ' . self::n($this->H - $baseline) . " Tm <$hex> Tj ET\n");
    }

    /**
     * תמונה במסגרת (x, y, bw, bh): חלון חיתוך cw×ch שמסתובב סביב מרכז המסגרת, והתמונה המלאה w×h בתוכו.
     */
    public function image(array $im, float $x, float $y, array $g, string $layer): void
    {
        $cx = $x + $g['bw'] / 2;
        $cy = $this->H - ($y + $g['bh'] / 2);
        $a = -$g['rot'] * M_PI / 180;   // CSS מסובב עם כיוון השעון; PDF — נגדו
        $cos = round(cos($a), 6);
        $sin = round(sin($a), 6);
        $cw = $g['cw'];
        $ch = $g['ch'];
        $left = -$cw / 2 - $g['w'] * $g['crop']['l'] / 100;
        $top = $ch / 2 + $g['h'] * $g['crop']['t'] / 100;
        $this->add($layer, "q\n" . self::n($cos) . ' ' . self::n($sin) . ' ' . self::n(-$sin) . ' ' . self::n($cos) . ' ' . self::n($cx) . ' ' . self::n($cy) . " cm\n"
            . self::n(-$cw / 2) . ' ' . self::n(-$ch / 2) . ' ' . self::n($cw) . ' ' . self::n($ch) . " re W n\n"
            . self::n($g['w']) . ' 0 0 ' . self::n($g['h']) . ' ' . self::n($left) . ' ' . self::n($top - $g['h']) . " cm {$im['name']} Do\nQ\n");
    }

    public static function rgb(array $c, string $op): string
    {
        return self::n($c[0] / 255) . ' ' . self::n($c[1] / 255) . ' ' . self::n($c[2] / 255) . " $op";
    }

    public static function n(float $v): string
    {
        return PdfWriter::n($v);
    }
}

/**
 * פריט בזרימה: גובה, ציור (x שמאל העמודה, y ראש הפריט), ותכונות לשבירת עמוד.
 * kind: line (שורת פסקה) · block · row (שורת טבלה) · force (מעבר עמוד).
 */
final class FlowItem
{
    public float $h = 0;
    public string $kind = 'block';
    public ?int $para = null;      // מזהה הפסקה (לשורות יתומות)
    public int $index = 0;
    public int $count = 1;
    public bool $keepNext = false; // כותרת / "שמור עם הבא" / "שמור שורות יחד" — נשאר עם הפריט הבא
    public bool $breakBefore = false; // "מעבר עמוד לפני" — השורה הראשונה של הפסקה
    public ?int $table = null;
    public bool $header = false;   // שורת כותרת של טבלה — חוזרת בעמוד הבא
    public array $notes = [];      // הערות שוליים שהסימן שלהן בפריט: [{n, h, draw}] — יוצגו בתחתית העמוד שלו
    /** @var callable(PdfCanvas, float, float): void */
    public $draw;

    public function __construct(float $h, callable $draw, string $kind = 'block')
    {
        $this->h = $h;
        $this->draw = $draw;
        $this->kind = $kind;
    }
}

final class PdfLayout
{
    private PdfWriter $pdf;
    private array $page;           // הגדרות העמוד של המקטע הנוכחי
    private array $first;          // של המקטע הראשון (מספור העמודים מתחיל בו)
    private float $W, $H, $mt, $mb, $ml, $mr, $cw, $chH;
    private int $paraSeq = 0;
    private int $fnSeq = 0;        // מספור הערות השוליים — לאורך כל המסמך
    private int $tableSeq = 0;

    private ?array $multiPath = null;   // המספרים של הפריטים שמעל (רשימה רב-רמתית), או null

    public function __construct(array $page)
    {
        $this->first = page_normalize($page);
        $this->setSection($this->first);
        $this->pdf = new PdfWriter();
    }

    private function setSection(array $p): void
    {
        $this->page = $p;
        [$wmm, $hmm] = page_mm($p);
        $this->W = $wmm * PT_PER_MM;
        $this->H = $hmm * PT_PER_MM;
        $m = $p['margins'];
        $this->mt = $m['top'] * PT_PER_MM;
        $this->mb = $m['bottom'] * PT_PER_MM;
        $this->ml = $m['left'] * PT_PER_MM;
        $this->mr = $m['right'] * PT_PER_MM;
        $this->cw = $this->W - $this->ml - $this->mr;
        $this->chH = $this->H - $this->mt - $this->mb;
    }

    public function render(array $json, string $title = ''): string
    {
        FontSet::reset();
        if (!empty($this->first['heading_numbers'])) $json = heading_numbers($json);
        // מקטעים: כל מקטע מתחיל בעמוד חדש, ולכן נפרס ומעומד לבד — בגיאומטריה שלו. מעבר מקטע נושא את
        // השינויים למקטע שאחריו (כמו בעורך)
        $chunks = [[]];
        $sections = [$this->first];
        foreach ($json['content'] ?? [] as $n) {
            if (($n['type'] ?? '') === 'sectionBreak') {
                $sections[] = page_normalize(is_array($n['attrs']['page'] ?? null) ? $n['attrs']['page'] : [], end($sections));
                $chunks[] = [];
                continue;
            }
            $chunks[count($chunks) - 1][] = $n;
        }
        $all = [];
        foreach ($chunks as $k => $nodes) {
            $this->setSection($sections[$k]);
            foreach ($this->paginate($this->blocks($nodes, $this->cw, 0)) as $pi => $placed) $all[] = [$k, $placed, $this->pageNotes[$pi]];
        }
        $total = count($all);
        foreach ($all as $i => [$k, $placed, $notes]) {
            $this->setSection($sections[$k]);
            $pi = $this->pdf->addPage($this->W, $this->H);
            $c = new PdfCanvas($this->pdf, $pi, $this->H);
            $this->decorate($c, $i, $total);
            foreach ($placed as [$item, $y]) ($item->draw)($c, $this->ml, $this->mt + $y);
            $this->drawNotes($c, $notes);
        }
        return $this->pdf->output($title);
    }

    // ---------- הערות שוליים ----------

    private const FN_SEP = 10.5;   // קו ההפרדה + רווח (נק') — כמו בעורך (14px)

    private function notesHeight(array $notes): float
    {
        if (!$notes) return 0.0;
        return self::FN_SEP + array_sum(array_column($notes, 'h'));
    }

    /** קו הפרדה (שליש מהרוחב, בצד ההתחלה — ימין) וההערות, בתחתית אזור הטקסט. */
    private function drawNotes(PdfCanvas $c, array $notes): void
    {
        if (!$notes) return;
        $y = $this->mt + $this->chH - $this->notesHeight($notes);
        $c->line($this->ml + $this->cw * 2 / 3, $y + 4.5, $this->ml + $this->cw, $y + 4.5, [0, 0, 0], 0.75);
        $y += self::FN_SEP;
        foreach ($notes as $nt) { ($nt['draw'])($c, $this->ml, $y); $y += $nt['h']; }
    }

    /** הערה: מספר עילי + הטקסט, 10 נק', ברוחב הטקסט — הגובה מחושב מראש (לעימוד). */
    private function footnote(array $attrs, bool $rtl): array
    {
        $n = ++$this->fnSeq;
        $base = $this->blockStyle('normal');
        $base['size'] = 10.0;
        $st = $this->charStyle($base, []);
        $sup = $st;
        $sup['va'] = 'sup';
        $atoms = [];
        foreach (mb_str_split((string)$n) as $ch) $atoms[] = $this->charAtom(mb_ord($ch), $sup);
        $atoms[] = $this->charAtom(0x20, $st);
        foreach (fn_inline($attrs) as $t) {
            $cs = $this->charStyle($base, $t['marks'] ?? []);
            foreach (mb_str_split($t['text']) as $ch) $atoms[] = $this->charAtom(mb_ord($ch), $cs);
        }
        $levels = bidi_levels(array_column($atoms, 'cp'), $rtl ? 1 : 0);
        foreach ($atoms as $i => &$at) $at['lvl'] = $levels[$i] ?? ($rtl ? 1 : 0);
        unset($at);
        $width = $this->cw;
        $lines = $this->breakLines($atoms, fn() => [0.0, $width], 0.0, $base, ['auto', 1.25]);
        $dir = $rtl ? 1 : 0;
        // חלק מההערה (שורות from..to) — הערה ארוכה שנמשכת לעמוד הבא (paginate)
        $part = function (int $from, int $to) use ($lines, $atoms, $dir, $rtl, $n, &$part) {
            $slice = array_slice($lines, $from, $to - $from);
            return ['n' => $n, 'h' => array_sum(array_column($slice, 'h')), 'lines' => array_column($lines, 'h'), 'part' => $part,
                'draw' => function (PdfCanvas $c, float $x, float $y) use ($slice, $atoms, $dir, $rtl) {
                    foreach ($slice as $ln) { $this->drawLine($c, $ln, $atoms, $x, $y, $rtl ? 'right' : 'left', true, $dir); $y += $ln['h']; }
                }];
        };
        return $part(0, count($lines));
    }

    // ==================================================================
    // סגנונות
    // ==================================================================

    /** סגנון בסיס של בלוק: 'normal' | 'title' | 'h1'..'h9' | סגנון משתמש (u<N> — מעל "רגיל", כמו בעורך). */
    private function blockStyle(string $kind): array
    {
        $s = $this->page['styles'];
        $user = (bool)preg_match(USER_STYLE_RE, $kind);
        $st = $user ? ($s[$kind] ?? []) + ($s['normal'] ?? []) : ($s[$kind] ?? []);
        return [
            'family' => $st['font'] ?? $this->page['font']['family'],
            'size' => (float)($st['size'] ?? $this->page['font']['size']),
            'bold' => !empty($st['bold']),
            'italic' => !empty($st['italic']),
            'u' => !empty($st['underline']),
            'color' => self::hex($st['color'] ?? '#000000'),
            'align' => $st['align'] ?? null,
            'before' => (float)($st['before'] ?? 0),
            'after' => (float)($st['after'] ?? 0),
            'line' => (float)(($user ? $st['line'] ?? null : null) ?? $s['normal']['line'] ?? 1.15),
        ];
    }

    private static function hex(?string $c): array
    {
        $v = color_val($c) ?? '000000';
        return [hexdec(substr($v, 0, 2)), hexdec(substr($v, 2, 2)), hexdec(substr($v, 4, 2))];
    }

    /** סגנון תו: הבסיס + הסימונים. */
    private function charStyle(array $base, array $marks): array
    {
        $s = ['family' => $base['family'], 'size' => $base['size'], 'bold' => $base['bold'], 'italic' => $base['italic'], 'u' => $base['u'],
            'strike' => false, 'color' => $base['color'], 'bg' => null, 'va' => null, 'ls' => 0.0];
        foreach ($marks as $m) {
            $a = $m['attrs'] ?? [];
            switch ($m['type'] ?? '') {
                case 'bold': $s['bold'] = true; break;
                case 'italic': $s['italic'] = true; break;
                case 'underline': $s['u'] = in_array($a['ul'] ?? null, UNDERLINES, true) ? $a['ul'] : true; break;
                case 'strike': $s['strike'] = true; break;
                case 'subscript': $s['va'] = 'sub'; break;
                case 'superscript': $s['va'] = 'sup'; break;
                case 'highlight': $s['bg'] = self::hex($a['color'] ?? '#ffff00'); break;
                // קישור: כחול עם קו תחתון, כמו במסך (צבע מפורש שאחריו — גובר, כמו ב-CSS)
                case 'link': $s['u'] = true; $s['color'] = self::hex('#0563c1'); break;
                case 'textStyle':
                    if (!empty($a['fontFamily'])) $s['family'] = font_name((string)$a['fontFamily']);
                    if (($z = pt_val($a['fontSize'] ?? null))) $s['size'] = $z;
                    if (color_val($a['color'] ?? null)) $s['color'] = self::hex($a['color']);
                    if (color_val($a['backgroundColor'] ?? null)) $s['bg'] = self::hex($a['backgroundColor']);
                    if (($l = pt_val($a['letterSpacing'] ?? null)) !== null) $s['ls'] = $l;
                    break;
            }
        }
        return $s;
    }

    // ==================================================================
    // בלוקים
    // ==================================================================

    /** @return FlowItem[] */
    private function blocks(array $nodes, float $width, float $indent, array $list = [], int $depth = -1, bool $cell = false): array
    {
        $items = [];
        $floats = [];   // [side, top, bottom, width] — במקומי של העמודה
        $y = 0.0;
        foreach ($nodes as $n) {
            $t = $n['type'] ?? '';
            switch ($t) {
                case 'paragraph':
                case 'heading':
                    $new = $this->paragraph($n, $width, $indent, $list, $floats, $y, $cell);
                    break;
                case 'bulletList':
                case 'orderedList':
                    $new = $this->listItems($n, $width, $indent, $depth, $floats, $y);
                    break;
                case 'table':
                    $new = $this->table($n, $width, $indent);
                    break;
                case 'pageBreak':
                    $new = [new FlowItem(0, fn() => null, 'force')];
                    break;
                case 'horizontalRule':
                    $new = [new FlowItem(12, function (PdfCanvas $c, float $x, float $yy) use ($width) {
                        $c->line($x, $yy + 6, $x + $width, $yy + 6, [136, 136, 136], 0.75);
                    })];
                    break;
                default:
                    $new = [];
            }
            foreach ($new as $it) { $y += $it->h; $items[] = $it; }
            $list = [];   // רק הבלוק הראשון בפריט רשימה מקבל את הסימן
        }
        return $items;
    }

    /** רשימה: כל פריט — הבלוקים שלו, מוזחים 24 נק', והסימן (• / 1. / א. / i.) ליד השורה הראשונה. */
    private function listItems(array $n, float $width, float $indent, int $parentDepth, array &$floats, float &$y): array
    {
        $ordered = $n['type'] === 'orderedList';
        $type = $ordered ? (in_array($n['attrs']['listType'] ?? '', ['hebrew', 'roman', 'multi'], true) ? $n['attrs']['listType'] : 'decimal') : 'bullet';
        // רב-רמתי: רשימה ממוספרת בתוך רשימה רב-רמתית — גם היא 1.1.1 (כמו ב-editor.css)
        if ($ordered && $this->multiPath !== null) $type = 'multi';
        $depth = $parentDepth + 1;
        $num = (int)($n['attrs']['start'] ?? 1);
        $out = [];
        $saved = $this->multiPath;
        foreach ($n['content'] ?? [] as $li) {
            if ($type === 'multi') {
                $path = [...($saved ?? []), $num++];
                $marker = implode('.', $path) . '.';
                $this->multiPath = $path;
            } else {
                $marker = $type === 'bullet' ? ['•', '◦', '▪'][min(2, $depth % 3)] : list_marker($num++, $type);
            }
            $items = $this->blocks($li['content'] ?? [], $width, $indent + 24, ['marker' => $marker], $depth);
            foreach ($items as $it) { $out[] = $it; }
        }
        $this->multiPath = $saved;
        return $out;
    }

    // ==================================================================
    // פסקה
    // ==================================================================

    private function paragraph(array $n, float $width, float $indent, array $list, array &$floats, float $yLocal, bool $cell = false): array
    {
        $a = $n['attrs'] ?? [];
        $kind = block_style_key($n, $this->page);
        $base = $this->blockStyle($kind);
        $rtl = ($a['dir'] ?? 'rtl') !== 'ltr';
        $align = $a['textAlign'] ?? $base['align'] ?? ($rtl ? 'right' : 'left');
        $before = is_numeric($a['spaceBefore'] ?? null) ? (float)$a['spaceBefore'] : $base['before'];
        $after = is_numeric($a['spaceAfter'] ?? null) ? (float)$a['spaceAfter'] : $base['after'];
        if ($list && $kind === 'normal') { $before = is_numeric($a['spaceBefore'] ?? null) ? $before : 0; $after = is_numeric($a['spaceAfter'] ?? null) ? $after : min(3, $base['after']); }
        if ($cell && !is_numeric($a['spaceAfter'] ?? null)) $after = 0;   // כמו td > p בעורך
        $lh = line_val($a['lineHeight'] ?? null) ?? ['auto', $base['line']];
        $ind = $indent + (int)($a['indent'] ?? 0) * 36;
        $paraId = ++$this->paraSeq;

        // אטומים: תו / תמונה בשורה / שבירת שורה. תמונות אחרות — בנפרד.
        $atoms = [];
        // מספור כותרות — המספר לפני הטקסט, בסגנון הכותרת (בעורך: ::before)
        if (isset($a['_hnum'])) foreach (mb_str_split((string)$a['_hnum']) as $ch) $atoms[] = $this->charAtom(mb_ord($ch), $this->charStyle($base, []));
        $absImgs = [];
        $blockImgs = [];
        foreach ($n['content'] ?? [] as $c) {
            $ct = $c['type'] ?? '';
            if ($ct === 'text') {
                $st = $this->charStyle($base, $c['marks'] ?? []);
                foreach (mb_str_split((string)($c['text'] ?? '')) as $ch) $atoms[] = $this->charAtom(mb_ord($ch), $st);
            } elseif ($ct === 'hardBreak') {
                $atoms[] = ['br' => true, 'cp' => 0x0A, 'w' => 0];
            } elseif ($ct === 'footnote') {
                // הסימן: המספר בכתב עילי; ההערה עצמה — נמדדת עכשיו ותצויר בתחתית העמוד של השורה
                $note = $this->footnote($c['attrs'] ?? [], $rtl);
                $sup = $this->charStyle($base, []);
                $sup['va'] = 'sup';
                foreach (mb_str_split((string)$note['n']) as $k => $ch) {
                    $at = $this->charAtom(mb_ord($ch), $sup);
                    if ($k === 0) $at['fn'] = $note;
                    $atoms[] = $at;
                }
            } elseif ($ct === 'image') {
                $img = $this->imageInfo($c['attrs'] ?? []);
                if (!$img) continue;
                $wrap = $img['g']['wrap'];
                if (in_array($wrap, ['square', 'tight'], true) && $img['g']['align'] !== 'center') $atoms[] = ['float' => $img, 'cp' => 0xFFFC, 'w' => 0.0];
                elseif (in_array($wrap, ['square', 'tight', 'topBottom'], true)) $blockImgs[] = $img;
                elseif (in_array($wrap, ['behind', 'front'], true)) $absImgs[] = $img;
                else $atoms[] = ['img' => $img, 'cp' => 0xFFFC, 'w' => $img['g']['bw'] * PT_PER_PX, 'h' => $img['boxH']];
            }
        }

        // תמונות צפות: נרשמות בשורה שבה הן יושבות (כמו float בדפדפן), ומצמצמות את השורות שלידן
        $floatDraw = [];   // [img, side, אינדקס השורה]
        $onFloat = function (array $img, float $top, int $lineIndex) use (&$floats, &$floatDraw) {
            $w = $img['g']['bw'] * PT_PER_PX;
            $d = $img['g']['dist'];
            $side = $img['g']['align'] === 'left' ? 'left' : 'right';
            $floats[] = [$side, $top, $top + $img['boxH'] + $d, $w + $d];
            $floatDraw[] = [$img, $side, $lineIndex];
        };

        $items = [];
        // תמונות "מעל ומתחת" (ובמרכז): שורה משלהן לפני הטקסט
        foreach ($blockImgs as $img) {
            $d = $img['g']['dist'];
            $h = $img['boxH'] + 2 * $d;
            $it = new FlowItem($h, function (PdfCanvas $c, float $x, float $y) use ($img, $width, $ind, $d, $rtl) {
                $w = $img['g']['bw'] * PT_PER_PX;
                $avail = $width - $ind;
                $x0 = $rtl ? $x : $x + $ind;
                $ix = match ($img['g']['align']) { 'center' => $x0 + ($avail - $w) / 2, 'left' => $x0, default => $x0 + $avail - $w };
                $this->drawImage($c, $img, $ix, $y + $d, 'main');
            });
            $items[] = $it;
            $yLocal += $h;
        }

        $levels = bidi_levels(array_column($atoms, 'cp'), $rtl ? 1 : 0);
        foreach ($atoms as $i => &$at) $at['lvl'] = $levels[$i] ?? ($rtl ? 1 : 0);
        unset($at);

        // שבירת שורות
        // הזחת שורה ראשונה (חיובי) / תלויה (שלילי): השורה הראשונה זזה ב-fl, או כל השאר ב-‎−fl (כמו text-indent + padding)
        $fl = is_numeric($a['firstLine'] ?? null) ? max(-200, min(200, (float)$a['firstLine'])) : 0.0;
        $lines = $this->breakLines($atoms, function (float $top, float $lineH, int $k = 0) use ($width, $ind, &$floats, $rtl, $fl) {
            $l = 0.0; $r = 0.0;
            foreach ($floats as [$side, $ft, $fb, $fw]) {
                if ($top < $fb && $top + $lineH > $ft) { if ($side === 'left') $l = max($l, $fw); else $r = max($r, $fw); }
            }
            $extra = $k === 0 ? max(0.0, $fl) : max(0.0, -$fl);
            // הזחה בצד ההתחלה: ימין בפסקה מימין לשמאל. שלישי — איפה השורה מתחילה ביחס לקצה התוכן (לעצירות הטאב)
            return $rtl ? [$l, $width - $ind - $r - $extra, $k === 0 ? $fl : 0.0] : [$ind + $l + $extra, $width - $r, $k === 0 ? $fl : 0.0];
        }, $yLocal + $before, $base, $lh, $onFloat);

        $count = count($lines);
        $dir = $rtl ? 1 : 0;
        // שבירת עמודים (כמו בעורך): "שמור שורות יחד" — רק כשכל הפסקה נכנסת בעמוד
        $keepNext = !empty($a['keepNext']);
        $together = !empty($a['keepLines']) && $before + array_sum(array_column($lines, 'h')) + $after <= $this->chH + 0.5;
        foreach ($lines as $k => $ln) {
            $first = $k === 0;
            $last = $k === $count - 1;
            $padTop = $first ? $before : 0;
            $padBottom = $last ? $after : 0;
            $h = $padTop + $ln['h'] + $padBottom;
            $it = new FlowItem($h, function (PdfCanvas $c, float $x, float $y) use ($k, &$floatDraw, $ln, $atoms, $padTop, $first, $last, $align, $dir, $list, $base, $ind, $width, $rtl, $absImgs) {
                $top = $y + $padTop;
                foreach ($floatDraw as [$img, $side, $li]) {
                    if ($li !== $k) continue;
                    $w = $img['g']['bw'] * PT_PER_PX;
                    $this->drawImage($c, $img, $side === 'left' ? $x : $x + $width - $w, $top, 'main');
                }
                if ($first) {
                    foreach ($absImgs as $img) {
                        $this->drawImage($c, $img, $x + (float)($img['a']['x'] ?? 0) * PT_PER_PX, $y + (float)($img['a']['y'] ?? 0) * PT_PER_PX,
                            $img['g']['wrap'] === 'behind' ? 'bg' : 'fg');
                    }
                    if ($list) $this->drawMarker($c, $list['marker'], $base, $ln, $x, $top, $width, $ind, $rtl);
                }
                $this->drawLine($c, $ln, $atoms, $x, $top, $align, $last, $dir);
            }, 'line');
            $it->para = $paraId;
            $it->index = $k;
            $it->count = $count;
            if ($ln['from'] !== null) {
                for ($q = $ln['from']; $q <= $ln['to']; $q++) if (isset($atoms[$q]['fn'])) $it->notes[] = $atoms[$q]['fn'];
            }
            // "שמור עם הבא" — לכותרות (כמו בעורך); לא לסגנון של משתמש, שהוא פסקה רגילה
            $it->keepNext = $last ? ($keepNext || ($kind !== 'normal' && !preg_match(USER_STYLE_RE, $kind))) : $together;
            $it->breakBefore = $first && !empty($a['breakBefore']);
            $items[] = $it;
        }
        return $items;
    }

    /** קו תחתון לפי הסוג (כמו text-decoration-style בעורך): רגיל, כפול, מנוקד, מקווקו, גלי, עבה. */
    private function underline(PdfCanvas $c, array $s, float $x1, float $x2, float $baseline): void
    {
        $y = $baseline + $s['size'] * 0.12;
        $lw = max(0.5, $s['size'] * 0.05);
        match ($s['u'] === true ? 'single' : $s['u']) {
            'double' => [$c->line($x1, $y - $lw, $x2, $y - $lw, $s['color'], $lw * 0.8), $c->line($x1, $y + $lw * 1.2, $x2, $y + $lw * 1.2, $s['color'], $lw * 0.8)],
            'dotted' => $c->dashLine($x1, $y, $x2, $s['color'], $lw, [$lw, $lw * 1.5]),
            'dashed' => $c->dashLine($x1, $y, $x2, $s['color'], $lw, [$lw * 3, $lw * 2]),
            'wavy' => $c->waveLine($x1, $y + $lw, $x2, $s['color'], $lw * 0.8, $lw * 1.2),
            'thick' => $c->line($x1, $y, $x2, $y, $s['color'], $lw * 2),
            default => $c->line($x1, $y, $x2, $y, $s['color'], $lw),
        };
    }

    private function charAtom(int $cp, array $st): array
    {
        $size = $st['va'] ? $st['size'] * 0.75 : $st['size'];
        $stack = FontSet::stack($st['family'], $st['bold']);
        $f = FontSet::pick($stack, $cp);
        $w = $cp === 0x09 ? TAB_STOP : $f->advance($cp, $size) + $st['ls'];
        return ['cp' => $cp, 's' => $st, 'f' => $f, 'size' => $size, 'w' => $w, 'ws' => $cp === 0x20];
    }

    /**
     * שורות: מילים (עם הרווחים שאחריהן, "תלויים" בסוף שורה), חלוקה חמדנית לרוחב הזמין בגובה השורה.
     * $range($top, $h) → [שמאל, ימין] בתוך העמודה.
     */
    private function breakLines(array &$atoms, callable $range, float $top, array $base, array $lh, ?callable $onFloat = null): array
    {
        // מילים: התווים שאינם רווח, והרווחים שאחריהם ("תלויים" — לא נספרים בסוף שורה)
        $words = [];
        $cur = null;
        foreach ($atoms as $i => $at) {
            if (!empty($at['br'])) { if ($cur) $words[] = $cur; $words[] = ['br' => true, 'from' => $i, 'to' => $i, 'w' => 0.0, 'trail' => 0.0]; $cur = null; continue; }
            // טאב: מילה משלו, ורוחבו נקבע במקום — עד העצירה הבאה (כל 36 נק' מתחילת השורה, כמו tab-size בעורך)
            if ($at['cp'] === 0x09) { if ($cur) $words[] = $cur; $words[] = ['tab' => true, 'from' => $i, 'to' => $i, 'w' => 0.0, 'trail' => 0.0]; $cur = null; continue; }
            $ws = !empty($at['ws']);
            if ($cur && !$ws && $cur['trail'] > 0) { $words[] = $cur; $cur = null; }
            $cur ??= ['from' => $i, 'to' => $i, 'w' => 0.0, 'trail' => 0.0];
            $cur['to'] = $i;
            if ($ws && $cur['w'] > 0) $cur['trail'] += $at['w']; else $cur['w'] += $at['w'];
        }
        if ($cur) $words[] = $cur;

        $lines = [];
        $line = null;
        $y = $top;
        $newLine = function () use (&$line, $range, &$y, $base, $lh, &$lines) {
            $estH = $lh[0] === 'exact' ? $lh[1] : $base['size'] * $lh[1];
            [$l, $r, $off] = $range($y, $estH, count($lines)) + [2 => 0.0];
            $line = ['from' => null, 'to' => null, 'w' => 0.0, 'trail' => 0.0, 'l' => $l, 'r' => $r, 'br' => false, 'off' => $off];
        };
        $finish = function (bool $br = false) use (&$line, &$lines, &$y, $atoms, $base, $lh) {
            $line['br'] = $br;
            $this->measureLine($line, $atoms, $base, $lh);
            $lines[] = $line;
            $y += $line['h'];
            $line = null;
        };
        $newLine();
        foreach ($words as $wd) {
            if (!empty($wd['br'])) { $finish(true); $newLine(); continue; }
            if (!empty($wd['tab'])) {
                $pos = $line['from'] === null ? 0.0 : $line['w'] + $line['trail'];
                // העצירות נמדדות מקצה התוכן של הפסקה (כמו בדפדפן): השורה הראשונה מתחילה ב-off (הזחה / תלויה)
                $abs = $pos + $line['off'];
                $tw = TAB_STOP - (fmod(fmod($abs, TAB_STOP) + TAB_STOP, TAB_STOP));
                if ($tw < 0.5) $tw += TAB_STOP;
                if ($line['from'] !== null && $pos + $tw > $line['r'] - $line['l'] + 0.01) {
                    // טאב שלא נכנס — "תלוי" בסוף השורה (כמו רווח, בלי רוחב), והמילה הבאה מתחילה שורה חדשה (כמו בדפדפן)
                    $atoms[$wd['from']]['w'] = 0.0;
                    $line['to'] = $wd['from'];
                    $finish();
                    $newLine();
                    continue;
                }
                $atoms[$wd['from']]['w'] = $tw;
                $line['w'] = $pos + $tw;
                $line['trail'] = 0.0;
                $line['from'] ??= $wd['from'];
                $line['to'] = $wd['from'];
                continue;
            }
            $avail = $line['r'] - $line['l'];
            if ($line['from'] !== null && $line['w'] + $line['trail'] + $wd['w'] > $avail + 0.01) { $finish(); $newLine(); $avail = $line['r'] - $line['l']; }
            if ($line['from'] === null && $wd['w'] > $avail) {
                // מילה ארוכה מהשורה — חותכים בין תווים
                for ($i = $wd['from']; $i <= $wd['to']; $i++) {
                    $w = $atoms[$i]['w'];
                    if ($line['from'] !== null && $line['w'] + $w > $line['r'] - $line['l']) { $finish(); $newLine(); }
                    $line['from'] ??= $i;
                    $line['to'] = $i;
                    if (empty($atoms[$i]['ws'])) $line['w'] += $w;
                }
                continue;
            }
            if ($line['from'] !== null) $line['w'] += $line['trail'];
            $line['from'] ??= $wd['from'];
            $line['to'] = $wd['to'];
            $line['w'] += $wd['w'];
            $line['trail'] = $wd['trail'];
            for ($i = $wd['from']; $i <= $wd['to']; $i++) {
                if (!isset($atoms[$i]['float']) || !$onFloat) continue;
                $onFloat($atoms[$i]['float'], $y, count($lines));
                $estH = $lh[0] === 'exact' ? $lh[1] : $base['size'] * $lh[1];
                [$line['l'], $line['r']] = $range($y, $estH, count($lines));
            }
        }
        $finish(true);
        return $lines;
    }

    /** גובה השורה וקו הבסיס: ריווח × הגודל הגדול בשורה (כמו line-height ב-CSS), ותמונות בשורה. */
    private function measureLine(array &$line, array $atoms, array $base, array $lh): void
    {
        $maxSize = $base['size'];
        $asc = 0.0;
        $desc = 0.0;
        $imgH = 0.0;
        if ($line['from'] !== null) {
            for ($i = $line['from']; $i <= $line['to']; $i++) {
                $at = $atoms[$i];
                if (isset($at['img'])) { $imgH = max($imgH, $at['h']); continue; }
                if (!empty($at['br']) || isset($at['float'])) continue;
                $sz = $at['s']['size'];
                $maxSize = max($maxSize, $sz);
                $f = $at['f'];
                $asc = max($asc, $f->ascent / $f->unitsPerEm * $sz);
                $desc = max($desc, -$f->descent / $f->unitsPerEm * $sz);
            }
        }
        if ($asc === 0.0) {
            $f = FontSet::stack($base['family'], $base['bold'])[0];
            $asc = $f->ascent / $f->unitsPerEm * $base['size'];
            $desc = -$f->descent / $f->unitsPerEm * $base['size'];
        }
        $h = $lh[0] === 'exact' ? $lh[1] : $maxSize * $lh[1];
        $baseline = ($h - ($asc + $desc)) / 2 + $asc;
        if ($imgH > $baseline) { $h += $imgH - $baseline; $baseline = $imgH; }
        $line['h'] = $h;
        $line['baseline'] = $baseline;
    }

    /** ציור שורה: סדר חזותי (bidi), יישור, יישור לשני הצדדים, ואז ריצות של אותו גופן וסגנון. */
    private function drawLine(PdfCanvas $c, array $ln, array $atoms, float $x, float $top, string $align, bool $last, int $para): void
    {
        if ($ln['from'] === null) return;
        $idx = range($ln['from'], $ln['to']);
        // רווחים תלויים בסוף השורה — לא נמדדים ולא מצוירים
        while ($idx && !empty($atoms[end($idx)]['ws'])) array_pop($idx);
        $idx = array_values(array_filter($idx, fn($i) => empty($atoms[$i]['br']) && !isset($atoms[$i]['float'])));
        if (!$idx) return;
        $levels = array_map(fn($i) => $atoms[$i]['lvl'], $idx);
        $cps = array_map(fn($i) => $atoms[$i]['cp'], $idx);
        $order = array_map(fn($k) => $idx[$k], bidi_visual($levels, $cps, $para));
        $w = 0.0;
        $spaces = 0;
        foreach ($order as $i) { $w += $atoms[$i]['w']; if (!empty($atoms[$i]['ws'])) $spaces++; }
        $avail = $ln['r'] - $ln['l'];
        $extra = 0.0;
        $justify = $align === 'justify' && !$last && !$ln['br'] && $spaces > 0;
        if ($justify) $extra = max(0, ($avail - $w) / $spaces);
        $xx = $x + $ln['l'] + match (true) {
            $justify => 0,
            $align === 'center' => ($avail - $w) / 2,
            $align === 'left' => 0,
            $align === 'justify' => $para ? $avail - $w : 0,
            default => $avail - $w,
        };
        $baseline = $top + $ln['baseline'];
        $run = null;
        $flush = function () use (&$run, $c, $baseline) {
            if (!$run) return;
            $s = $run['s'];
            if ($s['bg']) $c->rect($run['x'], $baseline - $run['asc'], $run['w'], $run['asc'] + $run['desc'], $s['bg']);
            $rise = $s['va'] === 'sup' ? $s['size'] * 0.33 : ($s['va'] === 'sub' ? -$s['size'] * 0.15 : 0);
            $c->glyphs($run['f'], $run['size'], $run['x'], $baseline, $run['hex'], $s['color'], $s['italic'], $rise, $s['ls']);
            if ($s['u']) $this->underline($c, $s, $run['x'], $run['x'] + $run['w'] - $s['ls'], $baseline);
            if ($s['strike']) $c->line($run['x'], $baseline - $s['size'] * 0.28, $run['x'] + $run['w'] - $s['ls'], $baseline - $s['size'] * 0.28, $s['color'], max(0.5, $s['size'] * 0.05));
            $run = null;
        };
        foreach ($order as $i) {
            $at = $atoms[$i];
            if (isset($at['img'])) {
                $flush();
                $this->drawImage($c, $at['img'], $xx, $baseline - $at['h'], 'main');
                $xx += $at['w'];
                continue;
            }
            $cp = $at['cp'];
            if ($at['lvl'] % 2 && isset(BIDI_MIRROR[mb_chr($cp)])) $cp = mb_ord(BIDI_MIRROR[mb_chr($cp)]);
            // טאב: רק מקום (הרוחב נקבע בשבירת השורות) — הריצה נקטעת, כי ב-PDF כל גליף זז ברוחב שלו
            if ($cp === 0x09) { $flush(); $xx += $at['w']; continue; }
            $ws = !empty($at['ws']);
            if ($run && ($run['f'] !== $at['f'] || $run['s'] != $at['s'] || ($justify && $ws))) $flush();
            if (!$run) {
                $f = $at['f'];
                $run = ['f' => $f, 's' => $at['s'], 'size' => $at['size'], 'x' => $xx, 'w' => 0.0, 'hex' => '',
                    'asc' => $f->ascent / $f->unitsPerEm * $at['size'], 'desc' => -$f->descent / $f->unitsPerEm * $at['size']];
            }
            $run['hex'] .= $at['f']->encode($cp);
            $run['w'] += $at['w'];
            $xx += $at['w'];
            if ($justify && $ws) { $flush(); $xx += $extra; }
        }
        $flush();
    }

    /** הסימן של פריט רשימה, בצד ההתחלה, צמוד לתחילת השורה. */
    private function drawMarker(PdfCanvas $c, string $marker, array $base, array $ln, float $x, float $top, float $width, float $ind, bool $rtl): void
    {
        $st = $this->charStyle($base, []);
        $atoms = array_map(fn($ch) => $this->charAtom(mb_ord($ch), $st), mb_str_split($marker));
        $cps = array_column($atoms, 'cp');
        $lv = bidi_levels($cps, $rtl ? 1 : 0);
        $order = bidi_visual($lv, $cps, $rtl ? 1 : 0);
        $w = array_sum(array_column($atoms, 'w'));
        $gap = 6;
        $xx = $rtl ? $x + $width - $ind + $gap : $x + $ind - $gap - $w;
        $baseline = $top + $ln['baseline'];
        foreach ($order as $i) {
            $at = $atoms[$i];
            $c->glyphs($at['f'], $at['size'], $xx, $baseline, $at['f']->encode($at['cp']), $st['color'], false, 0, 0);
            $xx += $at['w'];
        }
    }

    // ==================================================================
    // תמונות
    // ==================================================================

    /** תמונה: הקובץ, הגאומטריה (פיקסלים), ובנקודות — גובה המסגרת עם הכיתוב. */
    private function imageInfo(array $a): ?array
    {
        $m = !empty($a['media']) ? media_row((string)$a['media']) : null;
        if (!$m || !is_file($f = media_path($m['id'], $m['type']))) return null;
        $im = $this->pdf->image((string)file_get_contents($f));
        if (!$im) return null;
        $g = image_geom($a, $m, $this->cw / PT_PER_PX);
        $capH = !empty($a['caption']) ? 9 * 1.2 + 2 : 0;
        return ['im' => $im, 'g' => $g, 'a' => $a, 'boxH' => $g['bh'] * PT_PER_PX + $capH];
    }

    private function drawImage(PdfCanvas $c, array $img, float $x, float $y, string $layer): void
    {
        $g = $img['g'];
        $k = PT_PER_PX;
        $gp = ['w' => $g['w'] * $k, 'h' => $g['h'] * $k, 'cw' => $g['cw'] * $k, 'ch' => $g['ch'] * $k, 'bw' => $g['bw'] * $k, 'bh' => $g['bh'] * $k,
            'crop' => $g['crop'], 'rot' => $g['rot']];
        $c->image($img['im'], $x, $y, $gp, $layer);
        if (!empty($img['a']['caption'])) {
            $st = ['family' => $this->page['font']['family'], 'size' => 9.0, 'bold' => false, 'italic' => true, 'u' => false, 'strike' => false,
                'color' => [51, 51, 51], 'bg' => null, 'va' => null, 'ls' => 0.0];
            $this->drawTextCentered($c, (string)$img['a']['caption'], $st, $x, $x + $gp['bw'], $y + $gp['bh'] + 2 + 9 * 0.95, $layer === 'bg' ? 'main' : 'main');
        }
    }

    /** שורת טקסט אחת ממורכזת בין x1 ל-x2 (כיתוב, כותרת עליונה ותחתונה, מספר עמוד). */
    private function drawTextCentered(PdfCanvas $c, string $text, array $st, float $x1, float $x2, float $baseline, string $layer = 'main', string $align = 'center'): void
    {
        $atoms = array_map(fn($ch) => $this->charAtom(mb_ord($ch), $st), mb_str_split($text));
        if (!$atoms) return;
        $cps = array_column($atoms, 'cp');
        $lv = bidi_levels($cps, 1);
        $order = bidi_visual($lv, $cps, 1);
        $w = array_sum(array_column($atoms, 'w'));
        $xx = match ($align) { 'right' => $x2 - $w, 'left' => $x1, default => $x1 + ($x2 - $x1 - $w) / 2 };
        foreach ($order as $i) {
            $at = $atoms[$i];
            $cp = $lv[$i] % 2 && isset(BIDI_MIRROR[mb_chr($at['cp'])]) ? mb_ord(BIDI_MIRROR[mb_chr($at['cp'])]) : $at['cp'];
            $c->glyphs($at['f'], $at['size'], $xx, $baseline, $at['f']->encode($cp), $st['color'], $st['italic'], 0, 0);
            $xx += $at['w'];
        }
    }

    // ==================================================================
    // טבלה
    // ==================================================================

    private function table(array $t, float $width, float $indent): array
    {
        $tid = ++$this->tableSeq;
        $rows = $t['content'] ?? [];
        // רשת: עמודות, ותאים ממוזגים אנכית
        $occupied = [];
        $grid = [];
        $ncols = 0;
        foreach ($rows as $ri => $row) {
            $col = 0;
            foreach ($row['content'] ?? [] as $cell) {
                while (isset($occupied[$ri][$col])) $col++;
                $cs = max(1, (int)($cell['attrs']['colspan'] ?? 1));
                $rs = max(1, (int)($cell['attrs']['rowspan'] ?? 1));
                for ($r = 0; $r < $rs; $r++) for ($k = 0; $k < $cs; $k++) $occupied[$ri + $r][$col + $k] = true;
                $grid[$ri][] = ['cell' => $cell, 'col' => $col, 'cs' => $cs, 'rs' => $rs];
                $col += $cs;
            }
            $ncols = max($ncols, $col, count($occupied[$ri] ?? []));
        }
        if (!$ncols) return [];
        $ta = $t['attrs'] ?? [];
        // רוחב (% מהשורה) ויישור — כמו בעורך: offR — המרחק מהקצה הימני של השורה לקצה הימני של הטבלה
        $full = $width - $indent;
        $pct = is_numeric($ta['width'] ?? null) && $ta['width'] >= 10 && $ta['width'] < 100 ? (float)$ta['width'] : 100.0;
        $avail = $full * $pct / 100;
        $offR = match ($pct < 100 ? ($ta['align'] ?? 'right') : 'right') { 'center' => ($full - $avail) / 2, 'left' => $full - $avail, default => 0.0 };
        // גבולות: כולם / מסגרת / פנימיים / בלי — תמיד באותו רוחב (בעורך גבול כבוי שקוף), רק מה שמצויר משתנה
        $bmode = in_array($ta['borders'] ?? null, ['outer', 'inner', 'none'], true) ? $ta['borders'] : 'all';
        $bcol = color_val($ta['borderColor'] ?? null) ? self::hex($ta['borderColor']) : [136, 136, 136];
        $nrows = count($rows);
        $widths = array_fill(0, $ncols, 0.0);
        foreach ($grid as $cells) foreach ($cells as $c) {
            if ($c['cs'] === 1 && !empty($c['cell']['attrs']['colwidth'][0])) $widths[$c['col']] = (float)$c['cell']['attrs']['colwidth'][0] * PT_PER_PX;
        }
        $known = array_sum($widths);
        $unknown = count(array_filter($widths, fn($w) => $w == 0));
        foreach ($widths as $i => $w) if ($w == 0) $widths[$i] = $unknown ? max(20, ($avail - $known) / $unknown) : 20;
        $k = $avail / max(1, array_sum($widths));
        $widths = array_map(fn($w) => $w * $k, $widths);
        $padX = 4;
        $padY = 2;

        // תוכן כל תא, וגובה כל שורה
        $laid = [];
        $rowH = array_fill(0, count($rows), 0.0);
        foreach ($grid as $ri => $cells) {
            foreach ($cells as $ci => $c) {
                $cw = array_sum(array_slice($widths, $c['col'], $c['cs']));
                $content = $c['cell']['content'] ?? [];
                if ($c['cell']['type'] === 'tableHeader') $content = array_map(fn($p) => self::boldify($p), $content);
                $items = $this->blocks($content, $cw - 2 * $padX, 0, [], -1, true);
                // + גבול התא: בעורך (border-collapse, קו של 1px) כל שורה גבוהה ב-1px = 0.75pt מהתוכן והריפוד
                $h = array_sum(array_map(fn($it) => $it->h, $items)) + 2 * $padY + 0.75;
                $laid[$ri][$ci] = ['items' => $items, 'h' => $h, 'w' => $cw] + $c;
                if ($c['rs'] === 1) $rowH[$ri] = max($rowH[$ri], $h);
            }
            $rowH[$ri] = max($rowH[$ri], 12);
            // גובה מינימלי לשורה (נק') — כולל הריפוד והגבול, כמו height של tr בעורך
            if (is_numeric($rows[$ri]['attrs']['height'] ?? null) && $rows[$ri]['attrs']['height'] > 0) $rowH[$ri] = max($rowH[$ri], min(400, (float)$rows[$ri]['attrs']['height']));
        }
        foreach ($laid as $ri => $cells) foreach ($cells as $c) {
            if ($c['rs'] > 1) {
                $span = array_sum(array_slice($rowH, $ri, $c['rs']));
                if ($c['h'] > $span) $rowH[$ri + $c['rs'] - 1] += $c['h'] - $span;
            }
        }
        $out = [];
        foreach ($laid as $ri => $cells) {
            $h = $rowH[$ri];
            $it = new FlowItem($h, function (PdfCanvas $cv, float $x, float $y) use ($cells, $widths, $rowH, $ri, $indent, $width, $padX, $padY, $offR, $bmode, $bcol, $nrows, $ncols) {
                foreach ($cells as $c) {
                    // מימין לשמאל: העמודה הראשונה בצד ימין
                    $right = $x + $width - $offR - array_sum(array_slice($widths, 0, $c['col']));
                    $cx = $right - $c['w'];
                    $ch = array_sum(array_slice($rowH, $ri, $c['rs']));
                    $bg = $c['cell']['attrs']['background'] ?? null;
                    if (color_val($bg)) $cv->rect($cx, $y, $c['w'], $ch, self::hex($bg));
                    elseif ($c['cell']['type'] === 'tableHeader') $cv->rect($cx, $y, $c['w'], $ch, [242, 242, 242]);
                    // יישור אנכי: התוכן (בלי הריפוד והגבול) בתוך גובה התא
                    $free = $ch - $c['h'];
                    $va = $c['cell']['attrs']['valign'] ?? null;
                    $yy = $y + $padY + ($va === 'middle' ? $free / 2 : ($va === 'bottom' ? $free : 0));
                    foreach ($c['items'] as $item) { ($item->draw)($cv, $cx + $padX, $yy); $yy += $item->h; }
                    // ארבעת הצדדים — כל אחד לפי מצב הגבולות (קצה הטבלה או פנימי)
                    $edges = ['t' => $ri === 0, 'b' => $ri + $c['rs'] >= $nrows, 'r' => $c['col'] === 0, 'l' => $c['col'] + $c['cs'] >= $ncols];
                    foreach ($edges as $e => $outer) {
                        if (!($bmode === 'all' || ($bmode === 'outer' && $outer) || ($bmode === 'inner' && !$outer))) continue;
                        if ($e === 't') $cv->line($cx, $y, $cx + $c['w'], $y, $bcol, 0.75);
                        elseif ($e === 'b') $cv->line($cx, $y + $ch, $cx + $c['w'], $y + $ch, $bcol, 0.75);
                        elseif ($e === 'r') $cv->line($cx + $c['w'], $y, $cx + $c['w'], $y + $ch, $bcol, 0.75);
                        else $cv->line($cx, $y, $cx, $y + $ch, $bcol, 0.75);
                    }
                }
            }, 'row');
            $it->table = $tid;
            $it->header = ($rows[$ri]['content'][0]['type'] ?? '') === 'tableHeader' && $ri === 0;
            $out[] = $it;
        }
        $out[] = new FlowItem(4, fn() => null);   // רווח קטן אחרי טבלה
        return $out;
    }

    private static function boldify(array $p): array
    {
        $p['content'] = array_map(function ($t) {
            if (($t['type'] ?? '') === 'text') $t['marks'] = array_merge($t['marks'] ?? [], [['type' => 'bold']]);
            return $t;
        }, $p['content'] ?? []);
        return $p;
    }

    // ==================================================================
    // עימוד
    // ==================================================================

    /** @return array<int, array<int, array{0: FlowItem, 1: float}>> */
    /** ההערות של כל עמוד מהעימוד האחרון (paginate) — לפי מספר העמוד בו. */
    private array $pageNotes = [];

    private function paginate(array $items): array
    {
        $pages = [[]];
        $y = 0.0;
        $H = $this->chH;
        $headers = [];   // טבלה → שורת הכותרת שלה
        $n = count($items);
        // הערות השוליים של כל עמוד: ההמשך של הערה מהעמוד הקודם (carry), ואז של הפריטים שבו (split — הפריט שההערה
        // האחרונה שלו נחתכה: בעמוד שלו רק השורות שנכנסו)
        $carry = [[]];
        $split = [];
        $next = null;
        $newPage = function () use (&$pages, &$y, &$carry, &$next) { $pages[] = []; $carry[] = $next ? [$next] : []; $next = null; $y = 0.0; };
        $pageNotes = function () use (&$pages, &$carry, &$split) {
            $p = count($pages) - 1;
            return array_merge($carry[$p], ...array_map(fn($x) => $split[spl_object_id($x[0])] ?? $x[0]->notes, $pages[$p]));
        };
        $forced = false;
        for ($i = 0; $i < $n; $i++) {
            $it = $items[$i];
            if ($it->kind === 'force') { $newPage(); $forced = false; continue; }
            if ($it->header && $it->table) $headers[$it->table] = $it;
            $H = $this->chH - $this->notesHeight(array_merge($pageNotes(), $it->notes));
            $fits = $y + $it->h <= $H + 0.5;
            $atTop = $y < 0.01;
            // הפריט נכנס אבל ההערה האחרונה שלו לא כולה (כמו ב-Word): השורות שנכנסות — כאן, והשאר בראש ההערות של הבא
            $pb = $it->breakBefore && !$atTop;   // "מעבר עמוד לפני": שוברים לפניה, בלי תיקונים
            if (!$pb && !$fits && !$atTop && !$forced && $it->notes && isset($items[$i + 1]) && $items[$i + 1]->kind !== 'force') {
                $before = array_merge($pageNotes(), array_slice($it->notes, 0, -1));
                $last = end($it->notes);
                $room = $this->chH - $y - $it->h - self::FN_SEP - array_sum(array_column($before, 'h'));
                $k = 0;
                $acc = 0.0;
                foreach ($last['lines'] as $lh) { if ($acc + $lh > $room + 0.5) break; $acc += $lh; $k++; }
                if ($k >= 1 && $k < count($last['lines'])) {
                    $split[spl_object_id($it)] = array_merge(array_slice($it->notes, 0, -1), [($last['part'])(0, $k)]);
                    $next = ($last['part'])($k, count($last['lines']));
                    $pages[count($pages) - 1][] = [$it, $y];
                    $y += $it->h;
                    $forced = true;   // הפריט הבא — בעמוד חדש
                    continue;
                }
            }
            $break = (!$fits && !$atTop) || $forced || $pb;
            if (!$break && !$atTop) {
                // שורה ראשונה של פסקה בתחתית העמוד, כשהשנייה כבר לא נכנסת — עוברת איתה
                if ($it->kind === 'line' && $it->index === 0 && $it->count >= 2 && isset($items[$i + 1]) && $y + $it->h + $items[$i + 1]->h > $H + 0.5) $break = true;
                // שורה אחת לפני האחרונה: אם האחרונה לא נכנסת והפסקה ארוכה — שתיים עוברות יחד
                elseif ($it->kind === 'line' && $it->count >= 4 && $it->index === $it->count - 2 && isset($items[$i + 1]) && $y + $it->h + $items[$i + 1]->h > $H + 0.5) $break = true;
                // כותרת לא נשארת לבד בתחתית
                elseif ($it->keepNext && isset($items[$i + 1]) && $items[$i + 1]->kind !== 'force' && $y + $it->h + $items[$i + 1]->h > $H + 0.5) $break = true;
            }
            if ($break) {
                // כותרת שלפני — עוברת גם היא (אם אינה ראשונה בעמוד)
                $moved = [];
                $cur = &$pages[count($pages) - 1];
                while (!$forced && !$pb && $cur && end($cur)[0]->keepNext && count($cur) > 1) {
                    $moved[] = array_pop($cur)[0];
                }
                // המעבר נפל באמצע פסקה (שמור עם הבא / שורות יחד): לפחות 2 שורות שלה בכל צד — כמו בעורך
                $e = $moved ? end($moved) : null;
                if ($e && $e->kind === 'line' && $e->index > 0) {
                    if ($e->index < 2) {
                        while (count($cur) > 1 && end($cur)[0]->para === $e->para) $moved[] = array_pop($cur)[0];
                    } elseif ($e->count - $e->index < 2 && $e->index >= 3 && count($cur) > 1) {
                        $moved[] = array_pop($cur)[0];
                    }
                }
                unset($cur);
                $newPage();
                // שורת הכותרת של טבלה שנמשכת — חוזרת בראש העמוד
                if ($it->kind === 'row' && $it->table && isset($headers[$it->table]) && $headers[$it->table] !== $it) {
                    $pages[count($pages) - 1][] = [$headers[$it->table], $y];
                    $y += $headers[$it->table]->h;
                }
                foreach (array_reverse($moved) as $m) { $pages[count($pages) - 1][] = [$m, $y]; $y += $m->h; }
                $forced = false;
            }
            $pages[count($pages) - 1][] = [$it, $y];
            $y += $it->h;
        }
        $this->pageNotes = [];
        foreach ($pages as $p => $placed) {
            $this->pageNotes[$p] = array_merge($carry[$p], ...array_map(fn($x) => $split[spl_object_id($x[0])] ?? $x[0]->notes, $placed));
        }
        return $pages;
    }

    /** כותרת עליונה ותחתונה ומספר העמוד, בשוליים — כמו בעורך. */
    private function decorate(PdfCanvas $c, int $i, int $total): void
    {
        $nb = $this->page['numbering'];
        $st = ['family' => $this->page['font']['family'], 'size' => 9.0, 'bold' => false, 'italic' => false, 'u' => false, 'strike' => false,
            'color' => [85, 85, 85], 'bg' => null, 'va' => null, 'ls' => 0.0];
        $label = $nb['show'] && ($i > 0 || $nb['first_page']) ? page_label($nb['start'] + $i, $total, $nb['format']) : '';
        foreach (['top' => [$this->page['header'], $this->mt / 2], 'bottom' => [$this->page['footer'], $this->H - $this->mb / 2]] as $pos => [$text, $mid]) {
            $baseline = $mid + 9 * 0.35;
            $num = $label !== '' && $nb['position'] === $pos ? $label : '';
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($num !== '' && $nb['align'] === 'center') {
                $this->drawTextCentered($c, $text !== '' ? "$text   $num" : $num, $st, $this->ml, $this->W - $this->mr, $baseline);
                continue;
            }
            if ($text !== '') $this->drawTextCentered($c, $text, $st, $this->ml, $this->W - $this->mr, $baseline);
            if ($num !== '') $this->drawTextCentered($c, $num, $st, $this->ml, $this->W - $this->mr, $baseline, 'main', $nb['align']);
        }
    }
}
