<?php
/**
 * גופני TrueType ל-PDF — קוראים WOFF (zlib) מ-vendor/fonts/pdf, משחזרים ממנו את קובץ ה-sfnt (להטמעה כמו שהוא),
 * ומפענחים מה שהעימוד צריך: cmap (תו → גליף), hmtx (רוחב), head/hhea/OS/2 (מידות). בלי ספרייה חיצונית.
 *
 * הגופנים — חופשיים (OFL), אותם גופני גיבוי שהעורך מציג על המסך (assets/js/editor.js, cssFont), כך שה-PDF
 * נראה כמו המסך. כל משפחה מגיעה בשני קבצים (עברית / לטינית) — הבחירה בין הקבצים לכל תו (FontSet).
 */

final class TtfFont
{
    public string $sfnt;            // קובץ הגופן המלא (להטמעה ב-FontFile2)
    public string $name;
    public int $unitsPerEm = 1000;
    public int $ascent = 800;
    public int $descent = -200;
    public int $capHeight = 700;
    public array $bbox = [0, -200, 1000, 800];
    private array $tables = [];
    private array $cmap = [];       // codepoint => gid
    private array $widths = [];     // gid => advance (יחידות הגופן)
    public array $used = [];        // gid => codepoint (ל-ToUnicode ול-W)

    public static function fromFile(string $path): self
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException("גופן חסר: $path");
        $f = new self();
        $f->name = preg_replace('/[^A-Za-z0-9\-]/', '', pathinfo($path, PATHINFO_FILENAME));
        $f->sfnt = substr($bytes, 0, 4) === 'wOFF' ? self::woffToSfnt($bytes) : $bytes;
        $f->parse();
        return $f;
    }

    /** WOFF 1.0 → sfnt: פורקים כל טבלה (zlib) ובונים מחדש את ספריית הטבלאות. */
    private static function woffToSfnt(string $w): string
    {
        $h = unpack('Nsig/Nflavor/Nlength/nnum', $w);
        $num = $h['num'];
        $tables = [];
        for ($i = 0; $i < $num; $i++) {
            $e = unpack('a4tag/Noff/NcompLen/NorigLen/Nchk', substr($w, 44 + $i * 20, 20));
            $data = substr($w, $e['off'], $e['compLen']);
            if ($e['compLen'] < $e['origLen']) $data = zlib_decode($data);
            $tables[] = [$e['tag'], $data, $e['chk']];
        }
        $es = (int)floor(log($num, 2));
        $search = (2 ** $es) * 16;
        $offset = 12 + 16 * $num;
        $dir = '';
        $body = '';
        foreach ($tables as [$tag, $data, $chk]) {
            $dir .= $tag . pack('NNN', $chk, $offset + strlen($body), strlen($data));
            $body .= $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
        }
        return pack('Nnnnn', $h['flavor'], $num, $search, $es, $num * 16 - $search) . $dir . $body;
    }

    private function table(string $tag): string
    {
        return $this->tables[$tag] ?? '';
    }

    private function parse(): void
    {
        $s = $this->sfnt;
        $num = unpack('n', substr($s, 4, 2))[1];
        for ($i = 0; $i < $num; $i++) {
            $e = unpack('a4tag/Nchk/Noff/Nlen', substr($s, 12 + $i * 16, 16));
            $this->tables[$e['tag']] = substr($s, $e['off'], $e['len']);
        }
        $head = $this->table('head');
        $this->unitsPerEm = unpack('n', substr($head, 18, 2))[1];
        $bb = unpack('n4', substr($head, 36, 8));
        $this->bbox = array_map(fn($v) => $v >= 0x8000 ? $v - 0x10000 : $v, array_values($bb));
        $hhea = $this->table('hhea');
        $this->ascent = self::s16(substr($hhea, 4, 2));
        $this->descent = self::s16(substr($hhea, 6, 2));
        $numH = unpack('n', substr($hhea, 34, 2))[1];
        $os2 = $this->table('OS/2');
        if (strlen($os2) >= 90) $this->capHeight = self::s16(substr($os2, 88, 2)) ?: (int)($this->ascent * 0.7);
        else $this->capHeight = (int)($this->ascent * 0.7);
        $hmtx = $this->table('hmtx');
        $last = 0;
        $numGlyphs = unpack('n', substr($this->table('maxp'), 4, 2))[1];
        for ($g = 0; $g < $numGlyphs; $g++) {
            if ($g < $numH) $last = unpack('n', substr($hmtx, $g * 4, 2))[1];
            $this->widths[$g] = $last;
        }
        $this->parseCmap();
    }

    private static function s16(string $b): int
    {
        $v = unpack('n', $b)[1];
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    /** cmap: פורמט 12 (אם יש) או 4, Unicode. */
    private function parseCmap(): void
    {
        $c = $this->table('cmap');
        $n = unpack('n', substr($c, 2, 2))[1];
        $best = null;
        $bestFmt = 0;
        for ($i = 0; $i < $n; $i++) {
            $e = unpack('nplat/nenc/Noff', substr($c, 4 + $i * 8, 8));
            $fmt = unpack('n', substr($c, $e['off'], 2))[1];
            $unicode = $e['plat'] === 0 || ($e['plat'] === 3 && in_array($e['enc'], [1, 10], true));
            if (!$unicode) continue;
            if ($fmt === 12 && $bestFmt !== 12) { $best = $e['off']; $bestFmt = 12; }
            elseif ($fmt === 4 && !$best) { $best = $e['off']; $bestFmt = 4; }
        }
        if ($best === null) return;
        if ($bestFmt === 12) {
            $groups = unpack('N', substr($c, $best + 12, 4))[1];
            for ($i = 0; $i < $groups; $i++) {
                $g = unpack('Nstart/Nend/Ngid', substr($c, $best + 16 + $i * 12, 12));
                for ($cp = $g['start']; $cp <= $g['end'] && $cp - $g['start'] < 70000; $cp++) $this->cmap[$cp] = $g['gid'] + ($cp - $g['start']);
            }
            return;
        }
        $segX2 = unpack('n', substr($c, $best + 6, 2))[1];
        $seg = $segX2 / 2;
        $ends = array_values(unpack('n*', substr($c, $best + 14, $segX2)));
        $starts = array_values(unpack('n*', substr($c, $best + 16 + $segX2, $segX2)));
        $deltas = array_values(unpack('n*', substr($c, $best + 16 + 2 * $segX2, $segX2)));
        $rangeOff = $best + 16 + 3 * $segX2;
        $ranges = array_values(unpack('n*', substr($c, $rangeOff, $segX2)));
        for ($i = 0; $i < $seg; $i++) {
            for ($cp = $starts[$i]; $cp <= $ends[$i] && $cp !== 0xFFFF; $cp++) {
                if ($ranges[$i] === 0) {
                    $gid = ($cp + $deltas[$i]) & 0xFFFF;
                } else {
                    $addr = $rangeOff + $i * 2 + $ranges[$i] + ($cp - $starts[$i]) * 2;
                    $gid = unpack('n', substr($c, $addr, 2))[1] ?? 0;
                    if ($gid) $gid = ($gid + $deltas[$i]) & 0xFFFF;
                }
                if ($gid) $this->cmap[$cp] = $gid;
            }
        }
    }

    public function has(int $cp): bool
    {
        return isset($this->cmap[$cp]);
    }

    public function gid(int $cp): int
    {
        return $this->cmap[$cp] ?? 0;
    }

    /** רוחב תו בנקודות, בגודל $size. */
    public function advance(int $cp, float $size): float
    {
        return ($this->widths[$this->gid($cp)] ?? 0) * $size / $this->unitsPerEm;
    }

    /** מסמן את הגליף כבשימוש ומחזיר אותו כ-hex של 2 בתים (Identity-H). */
    public function encode(int $cp): string
    {
        $g = $this->gid($cp);
        $this->used[$g] = $cp;
        return sprintf('%04X', $g);
    }

    public function widthUnits(int $gid): int
    {
        return $this->widths[$gid] ?? 0;
    }
}

/**
 * משפחת גופנים לפי השם ב-Word (David, Frank Ruehl…) → קבצי העברית והלטינית, רגיל ומודגש.
 * לכל תו — הקובץ הראשון שיש בו גליף; אין — Heebo; אין גם שם — הקובץ הראשון (ריבוע ריק).
 */
final class FontSet
{
    private const DIR = __DIR__ . '/../../vendor/fonts/pdf/';
    public const FAMILIES = [
        'David' => 'david-libre', 'David Libre' => 'david-libre',
        'Frank Ruehl' => 'frank-ruhl-libre', 'Frank Ruhl Libre' => 'frank-ruhl-libre', 'Times New Roman' => 'frank-ruhl-libre',
        'Miriam' => 'miriam-libre', 'Miriam Libre' => 'miriam-libre',
        'Arial' => 'heebo', 'Tahoma' => 'heebo', 'Heebo' => 'heebo',
        'Narkisim' => 'assistant', 'Calibri' => 'assistant', 'Assistant' => 'assistant',
        'Rubik' => 'rubik', 'Alef' => 'alef',
    ];
    private static array $cache = [];

    /** @return TtfFont[] לפי סדר העדיפות */
    public static function stack(string $family, bool $bold): array
    {
        $key = (self::FAMILIES[$family] ?? 'heebo') . ($bold ? '-700' : '-400');
        if (isset(self::$cache[$key])) return self::$cache[$key];
        $base = self::FAMILIES[$family] ?? 'heebo';
        $w = $bold ? 700 : 400;
        $files = ["$base-hebrew-$w", "$base-latin-$w"];
        if ($base !== 'heebo') array_push($files, "heebo-hebrew-$w", "heebo-latin-$w");
        return self::$cache[$key] = array_map(fn($f) => self::load($f), $files);
    }

    private static array $files = [];

    private static function load(string $name): TtfFont
    {
        return self::$files[$name] ??= TtfFont::fromFile(self::DIR . $name . '-normal.woff');
    }

    /** הגופן לתו: הראשון ברשימה שיש בו גליף. */
    public static function pick(array $stack, int $cp): TtfFont
    {
        foreach ($stack as $f) if ($f->has($cp)) return $f;
        return $stack[0];
    }

    /** כל הגופנים שנטענו (להטמעה). */
    public static function loaded(): array
    {
        return self::$files;
    }

    public static function reset(): void
    {
        foreach (self::$files as $f) $f->used = [];
    }
}
