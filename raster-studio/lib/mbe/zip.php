<?php
/**
 * ZIP מהזיכרון — אותו קורא ואותו כותב כמו src/js/zip.js, באותם כללים ובאותם קודי בעיות.
 * לא ZipArchive: הוא דורש קובץ בדיסק, ומפרסר אחר היה מחזיר בעיות אחרות מה-JS על אותו קובץ עוין.
 * Deflate — zlib של PHP (inflate_add בחתיכות, כדי שגודל מזויף לא יפתח יותר ממה שהוצהר).
 */

declare(strict_types=1);

final class MbeZip
{
    public const LIMITS = [
        'maxEntries' => 10000,
        'maxTotalBytes' => 1024 * 1024 * 1024,
        'softTotalBytes' => 200 * 1024 * 1024,
        'maxRatio' => 100,
        'ratioFloor' => 1024 * 1024,
        'maxNameBytes' => 512,
    ];

    public static function nameProblem(string $name): ?string
    {
        if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name)) return 'zip-slip';
        foreach (explode('/', $name) as $seg) if ($seg === '..' || $seg === '.') return 'zip-slip';
        if (preg_match('/[\x00-\x1f\x7f]/', $name) || str_contains($name, '//')) return 'name-invalid';
        return null;
    }

    private static function u16(string $b, int $o): int { return unpack('v', $b, $o)[1]; }
    private static function u32(string $b, int $o): int { return unpack('V', $b, $o)[1]; }

    /**
     * @return array{entries: list<array>, issues: list<array>}
     *  לכל רשומה: name, method, crc, csize, usize, localExtra, start, dir. קריאה: MbeZip::read($bytes, $entry, $issues).
     */
    public static function readZip(string $bytes, array $limits = self::LIMITS): array
    {
        $issues = [];
        $fail = function (string $code, string $message, ?string $path = null) use (&$issues) {
            $issues[] = ['level' => 'error', 'code' => $code, 'message' => $message] + ($path !== null ? ['path' => $path] : []);
            return ['entries' => [], 'issues' => $issues];
        };
        $len = strlen($bytes);
        if ($len < 22 || self::u32($bytes, 0) !== 0x04034b50) return $fail('not-zip', 'זה אינו קובץ ZIP');

        $eocd = -1;
        for ($i = $len - 22, $stop = max(0, $len - 22 - 65535); $i >= $stop; $i--) {
            if ($bytes[$i] === 'P' && self::u32($bytes, $i) === 0x06054b50) { $eocd = $i; break; }
        }
        if ($eocd < 0) return $fail('zip-invalid', 'סוף ה-ZIP (EOCD) לא נמצא — הקובץ קטוע או פגום');
        $disk = self::u16($bytes, $eocd + 4); $cdDisk = self::u16($bytes, $eocd + 6);
        $count = self::u16($bytes, $eocd + 10); $cdSize = self::u32($bytes, $eocd + 12); $cdOffset = self::u32($bytes, $eocd + 16);
        if (($eocd >= 20 && self::u32($bytes, $eocd - 20) === 0x07064b50) || $count === 0xFFFF || $cdOffset === 0xFFFFFFFF) {
            return $fail('zip64-unsupported', 'ZIP64 (מעל 4GB או מעל 65,535 קבצים) אינו נתמך בגרסה 1');
        }
        if ($disk !== 0 || $cdDisk !== 0 || self::u16($bytes, $eocd + 8) !== $count) return $fail('zip-invalid', 'ZIP מפוצל לכמה דיסקים אינו נתמך');
        if ($count > $limits['maxEntries']) return $fail('too-many-entries', "יותר מ-{$limits['maxEntries']} קבצים בתוך ה-ZIP");
        if ($cdOffset + $cdSize > $eocd) return $fail('zip-invalid', 'המדריך המרכזי חורג מגבולות הקובץ');

        $entries = [];
        $seen = [];
        $total = 0;
        $p = $cdOffset;
        for ($i = 0; $i < $count; $i++) {
            if ($p + 46 > $eocd || self::u32($bytes, $p) !== 0x02014b50) return $fail('zip-invalid', 'רשומה פגומה במדריך המרכזי');
            $flags = self::u16($bytes, $p + 8); $method = self::u16($bytes, $p + 10); $crc = self::u32($bytes, $p + 16);
            $csize = self::u32($bytes, $p + 20); $usize = self::u32($bytes, $p + 24);
            $nlen = self::u16($bytes, $p + 28); $xlen = self::u16($bytes, $p + 30); $clen = self::u16($bytes, $p + 32);
            $local = self::u32($bytes, $p + 42);
            if ($p + 46 + $nlen > $eocd) return $fail('zip-invalid', 'שם חורג מהמדריך המרכזי');
            $name = substr($bytes, $p + 46, $nlen);
            $p += 46 + $nlen + $xlen + $clen;
            if ($csize === 0xFFFFFFFF || $usize === 0xFFFFFFFF || $local === 0xFFFFFFFF) {
                return $fail('zip64-unsupported', 'ZIP64 (מעל 4GB או מעל 65,535 קבצים) אינו נתמך בגרסה 1');
            }
            if (!mb_check_encoding($name, 'UTF-8')) return $fail('name-invalid', 'שם קובץ בתוך ה-ZIP אינו UTF-8 תקין');
            if ($nlen > $limits['maxNameBytes']) return $fail('name-invalid', 'שם ארוך מדי', mb_substr($name, 0, 80));
            $bad = self::nameProblem($name);
            if ($bad) return $fail($bad, $bad === 'zip-slip' ? 'נתיב שיוצא מהקובץ (zip-slip) — נדחה' : 'שם קובץ עם תווים אסורים', $name);
            if (isset($seen[$name])) return $fail('duplicate-name', 'אותו שם מופיע פעמיים', $name);
            $seen[$name] = true;
            if ($flags & 1) return $fail('encrypted', 'ZIP מוצפן אינו נתמך', $name);
            if ($method !== 0 && $method !== 8) return $fail('compression-unsupported', "שיטת דחיסה $method אינה נתמכת", $name);
            if ($method === 0 && $csize !== $usize) return $fail('zip-invalid', 'חלק לא דחוס שגודלו לא תואם', $name);
            $total += $usize;
            if ($total > $limits['maxTotalBytes']) return $fail('too-large', 'הקובץ הפתוח גדול מ-' . round($limits['maxTotalBytes'] / 1048576) . 'MB');
            if ($usize > $limits['ratioFloor'] && $usize > $csize * $limits['maxRatio']) return $fail('zip-bomb', 'יחס דחיסה חריג (zip-bomb) — נדחה', $name);
            if ($local + 30 > $len || self::u32($bytes, $local) !== 0x04034b50) return $fail('zip-invalid', 'כותרת מקומית חסרה', $name);
            $lnlen = self::u16($bytes, $local + 26); $lxlen = self::u16($bytes, $local + 28);
            if ($lnlen !== $nlen || substr($bytes, $local + 30, $lnlen) !== $name) return $fail('zip-invalid', 'השם בכותרת המקומית שונה מהמדריך', $name);
            $start = $local + 30 + $lnlen + $lxlen;
            if ($start + $csize > $cdOffset) return $fail('zip-invalid', 'נתוני החלק חורגים מגבולות הקובץ', $name);
            $entries[] = ['name' => $name, 'method' => $method, 'crc' => $crc, 'csize' => $csize, 'usize' => $usize,
                'offset' => $local, 'localExtra' => $lxlen, 'start' => $start, 'dir' => str_ends_with($name, '/')];
        }
        if ($total > $limits['softTotalBytes']) {
            $issues[] = ['level' => 'warning', 'code' => 'size-soft-limit', 'message' => 'הקובץ הפתוח גדול מ-' . round($limits['softTotalBytes'] / 1048576) . 'MB'];
        }
        return ['entries' => $entries, 'issues' => $issues];
    }

    /** תוכן הרשומה, או null עם בעיה ב-$issues. */
    public static function read(string $bytes, array $e, array &$issues): ?string
    {
        $raw = substr($bytes, $e['start'], $e['csize']);
        if ($e['method'] === 0) {
            $out = $raw;
        } else {
            $ctx = inflate_init(ZLIB_ENCODING_RAW);
            $out = '';
            $ok = true;
            for ($i = 0, $n = strlen($raw); $i < $n || $n === 0; $i += 4096) {
                $chunk = @inflate_add($ctx, substr($raw, $i, 4096), $i + 4096 >= $n ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($chunk === false) { $ok = false; break; }
                $out .= $chunk;
                if (strlen($out) > $e['usize']) {
                    $issues[] = ['level' => 'error', 'code' => 'size-mismatch', 'message' => 'החלק גדול מהגודל שהוצהר — נדחה', 'path' => $e['name']];
                    return null;
                }
                if ($n === 0) break;
            }
            if (!$ok) {
                $issues[] = ['level' => 'error', 'code' => 'zip-invalid', 'message' => 'הדחיסה פגומה', 'path' => $e['name']];
                return null;
            }
            if (strlen($out) !== $e['usize']) {
                $issues[] = ['level' => 'error', 'code' => 'size-mismatch', 'message' => 'החלק קטן מהגודל שהוצהר', 'path' => $e['name']];
                return null;
            }
        }
        if (crc32($out) !== $e['crc']) {
            $issues[] = ['level' => 'error', 'code' => 'crc-mismatch', 'message' => 'בדיקת CRC נכשלה — החלק פגום', 'path' => $e['name']];
            return null;
        }
        return $out;
    }

    /** @param list<array{name:string,data:string,store?:bool}> $files */
    public static function writeZip(array $files, ?DateTimeInterface $date = null): string
    {
        if (count($files) > 0xFFFF) throw new RuntimeException('יותר מ-65,535 קבצים — ZIP64 אינו נתמך בגרסה 1');
        $d = DateTimeImmutable::createFromInterface($date ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $time = (((int)$d->format('G') << 11) | ((int)$d->format('i') << 5) | ((int)$d->format('s') >> 1)) & 0xFFFF;
        $day = (((max(1980, (int)$d->format('Y')) - 1980) << 9) | ((int)$d->format('n') << 5) | (int)$d->format('j')) & 0xFFFF;
        $body = '';
        $central = '';
        foreach ($files as $f) {
            $name = $f['name'];
            $data = $f['data'];
            $crc = crc32($data);
            $method = 0;
            $payload = $data;
            if (empty($f['store']) && $data !== '') {
                $z = gzdeflate($data, 6, ZLIB_ENCODING_RAW);
                if ($z !== false && strlen($z) < strlen($data)) { $method = 8; $payload = $z; }
            }
            $offset = strlen($body);
            if (strlen($payload) >= 0xFFFFFFFF || $offset >= 0xFFFFFFFF) throw new RuntimeException('חלק גדול מ-4GB — ZIP64 אינו נתמך בגרסה 1');
            $utf = preg_match('/[^\x20-\x7e]/', $name) ? 0x0800 : 0;
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, $utf, $method, $time, $day, $crc, strlen($payload), strlen($data), strlen($name), 0)
                . $name . $payload;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $utf, $method, $time, $day, $crc, strlen($payload), strlen($data),
                strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($body), 0);
    }
}
