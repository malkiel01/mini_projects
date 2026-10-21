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
    public bool $keepNext = false; // כותרת — נשארת עם הבא
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
    private function footnote(string $text, bool $rtl): array
    {
        $n = ++$this->fnSeq;
        $base = $this->blockStyle('normal');
        $base['size'] = 10.0;
        $st = $this->charStyle($base, []);
        $sup = $st;
        $sup['va'] = 'sup';
        $atoms = [];
        foreach (mb_str_split((string)$n) as $ch) $atoms[] = $this->charAtom(mb_ord($ch), $sup);
        foreach (mb_str_split(' ' . $text) as $ch) $atoms[] = $this->charAtom(mb_ord($ch), $st);
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

    /** סגנון בסיס של בלוק: 'normal' | 'title' | 'h1'..'h3'. */
    private function blockStyle(string $kind): array
    {
        $s = $this->page['styles'];
        $st = $s[$kind] ?? [];
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
            'line' => (float)($s['normal']['line'] ?? 1.15),
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
                case 'underline': $s['u'] = true; break;
                case 'strike': $s['strike'] = true; break;
                case 'subscript': $s['va'] = 'sub'; break;
                case 'superscript': $s['va'] = 'sup'; break;
                case 'highlight': $s['bg'] = self::hex($a['color'] ?? '#ffff00'); break;
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
        $type = $ordered ? (in_array($n['attrs']['listType'] ?? '', ['hebrew', 'roman'], true) ? $n['attrs']['listType'] : 'decimal') : 'bullet';
        $depth = $parentDepth + 1;
        $num = (int)($n['attrs']['start'] ?? 1);
        $out = [];
        foreach ($n['content'] ?? [] as $li) {
            $marker = $type === 'bullet' ? ['•', '◦', '▪'][min(2, $depth % 3)] : list_marker($num++, $type);
            $items = $this->blocks($li['content'] ?? [], $width, $indent + 24, ['marker' => $marker], $depth);
            foreach ($items as $it) { $out[] = $it; }
        }
        return $out;
    }

    // ==================================================================
    // פסקה
    // ==================================================================

    private function paragraph(array $n, float $width, float $indent, array $list, array &$floats, float $yLocal, bool $cell = false): array
    {
        $a = $n['attrs'] ?? [];
        $kind = $n['type'] === 'heading' ? 'h' . max(1, min(3, (int)($a['level'] ?? 1))) : (($a['pstyle'] ?? '') === 'title' ? 'title' : 'normal');
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
                $note = $this->footnote(trim((string)($c['attrs']['text'] ?? '')), $rtl);
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
        $lines = $this->breakLines($atoms, function (float $top, float $lineH) use ($width, $ind, &$floats, $rtl) {
            $l = 0.0; $r = 0.0;
            foreach ($floats as [$side, $ft, $fb, $fw]) {
                if ($top < $fb && $top + $lineH > $ft) { if ($side === 'left') $l = max($l, $fw); else $r = max($r, $fw); }
            }
            // הזחה בצד ההתחלה: ימין בפסקה מימין לשמאל
            return $rtl ? [$l, $width - $ind - $r] : [$ind + $l, $width - $r];
        }, $yLocal + $before, $base, $lh, $onFloat);

        $count = count($lines);
        $dir = $rtl ? 1 : 0;
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
            $it->keepNext = $kind !== 'normal' && $last;
            $items[] = $it;
        }
        return $items;
    }

    private function charAtom(int $cp, array $st): array
    {
        $size = $st['va'] ? $st['size'] * 0.75 : $st['size'];
        $stack = FontSet::stack($st['family'], $st['bold']);
        $f = FontSet::pick($stack, $cp);
        $w = $cp === 0x09 ? 36 : $f->advance($cp, $size) + $st['ls'];
        return ['cp' => $cp, 's' => $st, 'f' => $f, 'size' => $size, 'w' => $w, 'ws' => $cp === 0x20 || $cp === 0x09];
    }

    /**
     * שורות: מילים (עם הרווחים שאחריהן, "תלויים" בסוף שורה), חלוקה חמדנית לרוחב הזמין בגובה השורה.
     * $range($top, $h) → [שמאל, ימין] בתוך העמודה.
     */
    private function breakLines(array $atoms, callable $range, float $top, array $base, array $lh, ?callable $onFloat = null): array
    {
        // מילים: התווים שאינם רווח, והרווחים שאחריהם ("תלויים" — לא נספרים בסוף שורה)
        $words = [];
        $cur = null;
        foreach ($atoms as $i => $at) {
            if (!empty($at['br'])) { if ($cur) $words[] = $cur; $words[] = ['br' => true, 'from' => $i, 'to' => $i, 'w' => 0.0, 'trail' => 0.0]; $cur = null; continue; }
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
        $newLine = function () use (&$line, $range, &$y, $base, $lh) {
            $estH = $lh[0] === 'exact' ? $lh[1] : $base['size'] * $lh[1];
            [$l, $r] = $range($y, $estH);
            $line = ['from' => null, 'to' => null, 'w' => 0.0, 'trail' => 0.0, 'l' => $l, 'r' => $r, 'br' => false];
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
                [$line['l'], $line['r']] = $range($y, $estH);
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
            if ($s['u']) $c->line($run['x'], $baseline + $s['size'] * 0.12, $run['x'] + $run['w'] - $s['ls'], $baseline + $s['size'] * 0.12, $s['color'], max(0.5, $s['size'] * 0.05));
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
            $ws = !empty($at['ws']);
            if ($run && ($run['f'] !== $at['f'] || $run['s'] != $at['s'] || ($justify && $ws))) $flush();
            if (!$run) {
                $f = $at['f'];
                $run = ['f' => $f, 's' => $at['s'], 'size' => $at['size'], 'x' => $xx, 'w' => 0.0, 'hex' => '',
                    'asc' => $f->ascent / $f->unitsPerEm * $at['size'], 'desc' => -$f->descent / $f->unitsPerEm * $at['size']];
            }
            $run['hex'] .= $cp === 0x09 ? $at['f']->encode(0x20) : $at['f']->encode($cp);
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
        $avail = $width - $indent;
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
            $it = new FlowItem($h, function (PdfCanvas $cv, float $x, float $y) use ($cells, $widths, $rowH, $ri, $indent, $width, $padX, $padY) {
                foreach ($cells as $c) {
                    // מימין לשמאל: העמודה הראשונה בצד ימין
                    $right = $x + $width - array_sum(array_slice($widths, 0, $c['col']));
                    $cx = $right - $c['w'];
                    $ch = array_sum(array_slice($rowH, $ri, $c['rs']));
                    $bg = $c['cell']['attrs']['background'] ?? null;
                    if (color_val($bg)) $cv->rect($cx, $y, $c['w'], $ch, self::hex($bg));
                    elseif ($c['cell']['type'] === 'tableHeader') $cv->rect($cx, $y, $c['w'], $ch, [242, 242, 242]);
                    $yy = $y + $padY;
                    foreach ($c['items'] as $item) { ($item->draw)($cv, $cx + $padX, $yy); $yy += $item->h; }
                    $cv->strokeRect($cx, $y, $c['w'], $ch, [136, 136, 136], 0.75);
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
            if (!$fits && !$atTop && !$forced && $it->notes && isset($items[$i + 1]) && $items[$i + 1]->kind !== 'force') {
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
            $break = (!$fits && !$atTop) || $forced;
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
                while (!$forced && $pages[count($pages) - 1] && end($pages[count($pages) - 1])[0]->keepNext && count($pages[count($pages) - 1]) > 1) {
                    $moved[] = array_pop($pages[count($pages) - 1])[0];
                }
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
