<?php
/**
 * subset לגופן TrueType (קווי מתאר glyf): נשארים רק הגליפים שבשימוש (ורכיבי הגליפים המורכבים שלהם) — השאר ריקים.
 * מזהי הגליפים לא משתנים, כך שרשימת גליפים שעוצבה מול הגופן המלא (HarfBuzz) מתאימה גם לקובץ המוקטן.
 * טבלאות שה-PDF לא צריך (GSUB, GPOS, GDEF, kern, DSIG…) יוצאות; post — גרסה 3 (בלי שמות גליפים).
 * גופן CFF (OTF עם 'CFF ') — חוזר כמו שהוא.
 * (pdf-core — עורכים ב-creative-suite/pdf-core/src ומריצים tools/sync.php)
 */

final class TtfSubset
{
    private const KEEP = ['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap', 'cvt ', 'fpgm', 'prep', 'OS/2', 'name', 'post'];

    /** @param int[] $gids */
    public static function make(string $sfnt, array $gids): string
    {
        $t = self::tables($sfnt);
        if (!isset($t['glyf'], $t['loca'], $t['head'], $t['maxp'])) return $sfnt;
        $long = unpack('n', substr($t['head'], 50, 2))[1] === 1;
        $num = unpack('n', substr($t['maxp'], 4, 2))[1];
        $offs = array_values(unpack($long ? 'N*' : 'n*', substr($t['loca'], 0, ($num + 1) * ($long ? 4 : 2))));
        if (!$long) $offs = array_map(fn($o) => $o * 2, $offs);
        $glyph = fn(int $g) => $g < $num ? substr($t['glyf'], $offs[$g], $offs[$g + 1] - $offs[$g]) : '';
        // הסגירה: גליף 0 (notdef), מה שבשימוש, ורכיבים של גליפים מורכבים — בכל עומק
        $keep = [0 => true];
        $todo = array_values(array_filter($gids, fn($g) => $g >= 0 && $g < $num));
        while ($todo) {
            $g = array_pop($todo);
            if (isset($keep[$g]) && $g !== 0) continue;
            $keep[$g] = true;
            foreach (self::components($glyph($g)) as $c) if (!isset($keep[$c]) && $c < $num) $todo[] = $c;
        }
        $glyf = '';
        $loca = '';
        for ($g = 0; $g < $num; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (!isset($keep[$g])) continue;
            $d = $glyph($g);
            $glyf .= $d . str_repeat("\0", (4 - strlen($d) % 4) % 4);
        }
        $loca .= pack('N', strlen($glyf));
        $t['glyf'] = $glyf;
        $t['loca'] = $loca;
        $t['head'] = substr($t['head'], 0, 8) . "\0\0\0\0" . substr($t['head'], 12, 38) . pack('n', 1) . substr($t['head'], 52);
        if (isset($t['post']) && strlen($t['post']) >= 32) $t['post'] = pack('N', 0x00030000) . substr($t['post'], 4, 28);
        $t = array_intersect_key($t, array_flip(self::KEEP));
        $out = self::build($t);
        // checkSumAdjustment: 0xB1B0AFBA פחות סכום הקובץ כולו
        $at = strpos($out, 'head') !== false ? self::tableOffset($out, 'head') + 8 : null;
        if ($at !== null) $out = substr_replace($out, pack('N', (0xB1B0AFBA - self::sum($out)) & 0xFFFFFFFF), $at, 4);
        return $out;
    }

    /** @return int[] מזהי הרכיבים של גליף מורכב (numberOfContours < 0) */
    private static function components(string $d): array
    {
        if (strlen($d) < 10 || unpack('n', substr($d, 0, 2))[1] < 0x8000) return [];
        $out = [];
        $p = 10;
        do {
            if ($p + 4 > strlen($d)) break;
            ['f' => $f, 'g' => $g] = unpack('nf/ng', substr($d, $p, 4));
            $out[] = $g;
            $p += 4 + (($f & 0x1) ? 4 : 2);
            if ($f & 0x8) $p += 2; elseif ($f & 0x40) $p += 4; elseif ($f & 0x80) $p += 8;
        } while ($f & 0x20);
        return $out;
    }

    private static function tables(string $s): array
    {
        $t = [];
        if (substr($s, 0, 4) === 'OTTO') return $t;
        $num = unpack('n', substr($s, 4, 2))[1];
        for ($i = 0; $i < $num; $i++) {
            $e = unpack('a4tag/Nchk/Noff/Nlen', substr($s, 12 + $i * 16, 16));
            $t[$e['tag']] = substr($s, $e['off'], $e['len']);
        }
        return $t;
    }

    private static function tableOffset(string $s, string $tag): ?int
    {
        $num = unpack('n', substr($s, 4, 2))[1];
        for ($i = 0; $i < $num; $i++) {
            $e = unpack('a4tag/Nchk/Noff', substr($s, 12 + $i * 16, 12));
            if ($e['tag'] === $tag) return $e['off'];
        }
        return null;
    }

    private static function build(array $t): string
    {
        ksort($t, SORT_STRING);
        $num = count($t);
        $es = (int)floor(log($num, 2));
        $search = (2 ** $es) * 16;
        $offset = 12 + 16 * $num;
        $dir = '';
        $body = '';
        foreach ($t as $tag => $data) {
            $dir .= str_pad($tag, 4) . pack('NNN', self::sum($data), $offset + strlen($body), strlen($data));
            $body .= $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
        }
        return pack('Nnnnn', 0x00010000, $num, $search, $es, $num * 16 - $search) . $dir . $body;
    }

    private static function sum(string $d): int
    {
        $d .= str_repeat("\0", (4 - strlen($d) % 4) % 4);
        $s = 0;
        foreach (unpack('N*', $d) ?: [] as $v) $s = ($s + $v) & 0xFFFFFFFF;
        return $s;
    }
}
