<?php
/**
 * כותב PDF 1.7 מינימלי: עמודים עם שלוש שכבות (מאחורי הטקסט / הטקסט / לפני הטקסט), גופני TrueType מוטמעים
 * (Type0 + CIDFontType2, Identity-H, עם ToUnicode — כך שאפשר לחפש ולהעתיק עברית), ותמונות (JPEG כמו שהוא,
 * כל השאר דרך GD — RGB + שקיפות ב-SMask). הכול דחוס (Flate).
 * (pdf-core — עורכים ב-creative-suite/pdf-core/src ומריצים tools/sync.php)
 */

require_once __DIR__ . '/font.php';
require_once __DIR__ . '/subset.php';

final class PdfWriter
{
    /** @var array<int,array{w:float,h:float,bg:string,main:string,fg:string}> */
    public array $pages = [];
    private array $fontNames = [];     // spl_object_id => '/F1'
    private array $fontObjs = [];      // '/F1' => TtfFont
    private array $images = [];        // hash => [name, w, h, objects…]
    /** יעדים לקישורים פנימיים (סימניות): שם => [מספר עמוד, y בנקודות מראש העמוד] — הראשון בשם הזה */
    public array $dests = [];
    /** רישום ציור (למשל לתמונה ממוזערת — preview.png ב-‎.mbe של עורך התמלילים): לא null — image() מחזירה גם את ה-bytes */
    public ?array $rec = null;
    /** להטמיע רק את הגליפים שבשימוש (subset.php) — ברשימת ציור (draw.php); המסמכים של עורך התמלילים — הגופן המלא */
    public bool $subset = false;

    /**
     * עמוד ברוחב $w ובגובה $h (נקודות). $bleed > 0 — גלישה: MediaBox ו-BleedBox גדלים בה מכל צד, ו-TrimBox הוא
     * העמוד עצמו; w/h של העמוד ברשימה הם אז של MediaBox, והתוכן מוזז בעצמו (draw.php).
     */
    public function addPage(float $w, float $h, float $bleed = 0): int
    {
        $this->pages[] = ['w' => $w + 2 * $bleed, 'h' => $h + 2 * $bleed, 'bleed' => $bleed, 'bg' => '', 'main' => '', 'fg' => '', 'links' => []];
        return count($this->pages) - 1;
    }

    /** שקיפות (ExtGState): ca למילוי, CA לקו → '/GS1'. אותו זוג — אותו שם. נכנס למשאבים רק כשנעשה בו שימוש */
    public array $gstates = [];
    public function alpha(float $fill = 1, float $stroke = 1): string
    {
        $key = self::n(max(0, min(1, $fill))) . ' ' . self::n(max(0, min(1, $stroke)));
        return $this->gstates[$key] ??= '/GS' . (count($this->gstates) + 1);
    }

    /**
     * מעבר צבע (Pattern type 2 עם Shading) → '/P1'. $shading — מילון Shading מלא; $matrix — ממרחב התבנית למרחב
     * הדף (הקואורדינטות של המעבר). כל שימוש — שם משלו (המטריצה שונה בין פעולות). רק כשבשימוש — במשאבים
     */
    public array $patterns = [];
    public function pattern(string $shading, array $matrix): string
    {
        $name = '/P' . (count($this->patterns) + 1);
        $this->patterns[$name] = [$shading, $matrix];
        return $name;
    }

    /**
     * מסכה רכה לפי בהירות (שקיפות בעצירות של מעבר צבע) → '/SM1' של ExtGState. $shading — מעבר אפור (DeviceGray) של
     * האטימות, באותן קואורדינטות; נצבע בטופס (Form XObject) שה-SMask מפנה אליו. במשאבים רק כשבשימוש
     */
    public array $masks = [];
    public function softMask(string $shading): string
    {
        $this->masks[] = $shading;
        return '/SM' . count($this->masks);
    }

    public function font(TtfFont $f): string
    {
        $id = spl_object_id($f);
        if (!isset($this->fontNames[$id])) {
            $name = '/F' . (count($this->fontNames) + 1);
            $this->fontNames[$id] = $name;
            $this->fontObjs[$name] = $f;
        }
        return $this->fontNames[$id];
    }

    /**
     * תמונה → ['name' => '/Im1', 'w' => פיקסלים, 'h' => פיקסלים] או null (פורמט שלא נתמך).
     * JPEG — DCTDecode ישירות; PNG/GIF/WebP — פענוח ב-GD.
     */
    public function image(string $bytes): ?array
    {
        $key = md5($bytes);
        if (isset($this->images[$key])) return $this->images[$key];
        $info = @getimagesizefromstring($bytes);
        if (!$info) return null;
        [$w, $h] = $info;
        $name = '/Im' . (count($this->images) + 1);
        if ($info[2] === IMAGETYPE_JPEG) {
            $ch = $info['channels'] ?? 3;
            $cs = $ch === 1 ? '/DeviceGray' : ($ch === 4 ? '/DeviceCMYK' : '/DeviceRGB');
            $dict = "/Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace $cs /BitsPerComponent 8 /Filter /DCTDecode"
                . ($ch === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '');
            return $this->images[$key] = ['name' => $name, 'w' => $w, 'h' => $h, 'dict' => $dict, 'data' => $bytes, 'smask' => null]
                + ($this->rec !== null ? ['bytes' => $bytes] : []);
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) return null;
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        // פענוח פיקסל-פיקסל ב-PHP — תמונה גדולה מוקטנת ל-1600 בצלע הארוכה (מספיק להדפסה ברוחב עמוד)
        if (max($w, $h) > 1600) {
            $k = 1600 / max($w, $h);
            $small = imagescale($im, max(1, (int)round($w * $k)), max(1, (int)round($h * $k)), IMG_BICUBIC);
            if ($small) { imagedestroy($im); $im = $small; imagesavealpha($im, true); $w = imagesx($im); $h = imagesy($im); }
        }
        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255);
                $a = ($c >> 24) & 127;           // GD: 0 אטום … 127 שקוף
                if ($a) $hasAlpha = true;
                $alpha .= chr(255 - (int)round($a * 255 / 127));
            }
        }
        imagedestroy($im);
        $dict = "/Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode";
        return $this->images[$key] = ['name' => $name, 'w' => $w, 'h' => $h, 'dict' => $dict, 'data' => gzcompress($rgb, 6),
            'smask' => $hasAlpha ? gzcompress($alpha, 6) : null] + ($this->rec !== null ? ['bytes' => $bytes] : []);
    }

    /** ה-PDF כולו. */
    /** @param string $xmp חבילת XMP (‎<?xpacket …?>‎) — זרם Metadata של הקטלוג, לא דחוס; ריק — בלי */
    public function output(string $title = '', string $xmp = ''): string
    {
        $objs = [];        // מספר → תוכן
        $next = 1;
        $alloc = function () use (&$next) { return $next++; };
        $catalog = $alloc();
        $pagesId = $alloc();
        $info = $alloc();

        // גופנים
        $fontRefs = '';
        foreach ($this->fontObjs as $name => $f) {
            if (!$f->used) continue;
            $type0 = $alloc(); $cid = $alloc(); $desc = $alloc(); $file = $alloc(); $tou = $alloc();
            $k = 1000 / $f->unitsPerEm;
            $tag = self::subsetTag($f->name);
            $base = $tag . '+' . $f->name;
            ksort($f->used);
            $w = '';
            foreach ($f->used as $gid => $cp) $w .= $gid . ' [' . round($f->widthUnits($gid) * $k) . '] ';
            $bbox = implode(' ', array_map(fn($v) => (int)round($v * $k), $f->bbox));
            $objs[$type0] = "<< /Type /Font /Subtype /Type0 /BaseFont /$base /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $tou 0 R >>";
            $objs[$cid] = "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$base /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>"
                . " /FontDescriptor $desc 0 R /DW 0 /W [ $w] /CIDToGIDMap /Identity >>";
            $objs[$desc] = "<< /Type /FontDescriptor /FontName /$base /Flags 32 /FontBBox [$bbox] /ItalicAngle 0 /Ascent " . (int)round($f->ascent * $k)
                . ' /Descent ' . (int)round($f->descent * $k) . ' /CapHeight ' . (int)round($f->capHeight * $k) . " /StemV 80 /FontFile2 $file 0 R >>";
            $sfnt = $this->subset ? TtfSubset::make($f->sfnt, array_keys($f->used)) : $f->sfnt;
            $objs[$file] = self::stream("/Length1 " . strlen($sfnt), gzcompress($sfnt, 6), true);
            $objs[$tou] = self::stream('', gzcompress(self::toUnicode($f->used), 6), true);
            $fontRefs .= "$name $type0 0 R ";
        }
        // תמונות
        $imgRefs = '';
        foreach ($this->images as $im) {
            $id = $alloc();
            $smask = '';
            if ($im['smask'] !== null) {
                $sid = $alloc();
                $objs[$sid] = self::stream("/Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode", $im['smask']);
                $smask = " /SMask $sid 0 R";
            }
            $objs[$id] = self::stream($im['dict'] . $smask, $im['data']);
            $imgRefs .= "{$im['name']} $id 0 R ";
        }
        $res = $alloc();
        $pat = '';
        foreach ($this->patterns as $name => [$sh, $mx]) {
            $id = $alloc();
            $objs[$id] = "<< /Type /Pattern /PatternType 2 /Shading $sh /Matrix [" . implode(' ', array_map([self::class, 'n'], $mx)) . '] >>';
            $pat .= "$name $id 0 R ";
        }
        $gs = '';
        foreach ($this->gstates as $key => $name) { [$ca, $CA] = explode(' ', $key); $gs .= "$name << /Type /ExtGState /ca $ca /CA $CA >> "; }
        foreach ($this->masks as $i => $sh) {
            $id = $alloc();
            $objs[$id] = self::stream("/Type /XObject /Subtype /Form /BBox [-10000 -10000 10000 10000] /Group << /Type /Group /S /Transparency /CS /DeviceGray >>"
                . " /Resources << /Shading << /Sh0 $sh >> >>", '/Sh0 sh');
            $gs .= '/SM' . ($i + 1) . " << /Type /ExtGState /SMask << /Type /Mask /S /Luminosity /G $id 0 R >> >> ";
        }
        $objs[$res] = "<< /Font << $fontRefs>> /XObject << $imgRefs>>" . ($gs !== '' ? " /ExtGState << $gs>>" : '') . ($pat !== '' ? " /Pattern << $pat>>" : '') . " /ProcSet [/PDF /Text /ImageB /ImageC] >>";
        // עמודים — המספרים מוקצים קודם, כי קישור פנימי מפנה לעמוד אחר
        $kids = [];
        $pids = array_map(fn() => $alloc(), $this->pages);
        foreach ($this->pages as $pn => $p) {
            $pid = $pids[$pn];
            $cid = $alloc();
            // כל שכבה: הבסיס, ואחריו תתי-השכבות שלה (bg:z / fg:z) לפי z
            $layer = function (string $base) use ($p): string {
                $subs = array_filter(array_keys($p), fn($k) => str_starts_with((string)$k, "$base:"));
                usort($subs, fn($a, $b) => (int)substr($a, strlen($base) + 1) <=> (int)substr($b, strlen($base) + 1));
                return $p[$base] . implode('', array_map(fn($k) => $p[$k], $subs));
            };
            $content = "q\n" . $layer('bg') . "Q\nq\n" . $p['main'] . "Q\nq\n" . $layer('fg') . "Q\n";
            $objs[$cid] = self::stream('', gzcompress($content, 6), true);
            // קישורים: אזור לחיץ (בלי מסגרת) — כתובת, או סימניה במסמך (קישור לסימניה שלא קיימת — לא לחיץ)
            $annots = [];
            foreach ($p['links'] as [$x1, $y1, $x2, $y2, $href]) {
                if ($href[0] === '#') {
                    $d = $this->dests[substr($href, 1)] ?? null;
                    if (!$d) continue;
                    $act = '/Dest [' . $pids[$d[0]] . ' 0 R /XYZ null ' . self::n($this->pages[$d[0]]['h'] - $d[1]) . ' null]';
                } else $act = '/A << /S /URI /URI ' . self::uri($href) . ' >>';
                $aid = $alloc();
                $objs[$aid] = '<< /Type /Annot /Subtype /Link /Rect [' . implode(' ', array_map([self::class, 'n'], [$x1, $y1, $x2, $y2])) . "] /Border [0 0 0] $act >>";
                $annots[] = "$aid 0 R";
            }
            $b = (float)($p['bleed'] ?? 0);
            $box = $b > 0 ? ' /BleedBox [0 0 ' . self::n($p['w']) . ' ' . self::n($p['h']) . '] /TrimBox [' . implode(' ', array_map([self::class, 'n'], [$b, $b, $p['w'] - $b, $p['h'] - $b])) . ']' : '';
            $objs[$pid] = "<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 " . self::n($p['w']) . ' ' . self::n($p['h']) . "]$box /Resources $res 0 R /Contents $cid 0 R"
                . ($annots ? ' /Annots [' . implode(' ', $annots) . ']' : '') . ' >>';
            $kids[] = "$pid 0 R";
        }
        $objs[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $meta = '';
        if ($xmp !== '') {
            $mid = $alloc();
            $objs[$mid] = self::stream('/Type /Metadata /Subtype /XML', $xmp);
            $meta = " /Metadata $mid 0 R";
        }
        $objs[$catalog] = "<< /Type /Catalog /Pages $pagesId 0 R$meta /ViewerPreferences << /Direction /R2L /DisplayDocTitle true >> /Lang (he-IL) >>";
        $objs[$info] = '<< /Title ' . self::text($title) . ' /Producer (pdf-core) /CreationDate (D:' . gmdate('YmdHis') . "Z) >>";

        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i < $next; $i++) {
            $offsets[$i] = strlen($out);
            $out .= "$i 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 $next\n0000000000 65535 f \n";
        for ($i = 1; $i < $next; $i++) $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $out .= "trailer\n<< /Size $next /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    private static function stream(string $dict, string $data, bool $flate = false): string
    {
        return '<< ' . $dict . ($flate ? ' /Filter /FlateDecode' : '') . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    /** CMap שממפה כל גליף שבשימוש לתו שלו — חיפוש והעתקה מה-PDF. */
    private static function toUnicode(array $used): string
    {
        $lines = [];
        foreach ($used as $gid => $cp) {
            if ($cp < 0) continue;
            $u = mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8');
            $lines[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex($u)));
        }
        $out = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($lines, 100) as $chunk) $out .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        return $out . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    private static function subsetTag(string $name): string
    {
        $h = hexdec(substr(md5($name), 0, 8));
        $t = '';
        for ($i = 0; $i < 6; $i++) { $t .= chr(65 + $h % 26); $h = intdiv($h, 26); }
        return $t;
    }

    /** כתובת לקישור: ASCII בלבד (אותיות עבריות ורווחים — בקידוד אחוזים), עם בריחה של \ ( ). */
    private static function uri(string $s): string
    {
        $s = preg_replace_callback('/[^\x21-\x7e]/', fn($m) => rawurlencode($m[0]), $s);
        return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }

    /** מחרוזת טקסט ל-PDF (UTF-16BE עם BOM). */
    public static function text(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    public static function n(float $v): string
    {
        $s = number_format($v, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }
}
