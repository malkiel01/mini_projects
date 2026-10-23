<?php
/**
 * JSON קנוני לפי RFC 8785 (JCS) — זהה בית-לבית ל-src/js/canonical.js, כדי שהגיבוב של document.json יהיה אחד.
 *
 * ייצוג ב-PHP: אובייקט JSON → מערך אסוציאטיבי; מערך JSON → list. שני מקרים נשארים stdClass, כי מערך PHP
 * לא מבדיל בהם: אובייקט ריק ({}) ואובייקט שמפתחותיו 0,1,2… — כך {} לא הופך ל-[] בשמירה.
 */

declare(strict_types=1);

final class MbeCanonicalError extends RuntimeException {}

final class MbeCanonical
{
    public const MAX_DEPTH = 256;

    public static function encode(mixed $v): string
    {
        return self::write($v, 0);
    }

    /** bytes → ערך. UTF-8 תקין בלבד, בלי BOM, עם תקרת עומק — אותם כללים כמו parseJson ב-JS. */
    public static function decode(string $bytes): mixed
    {
        if (!mb_check_encoding($bytes, 'UTF-8')) throw new MbeCanonicalError('הקובץ אינו UTF-8 תקין');
        $v = json_decode($bytes, false, self::MAX_DEPTH + 2);
        if (json_last_error() === JSON_ERROR_DEPTH) throw new MbeCanonicalError('עומק JSON מעל ' . self::MAX_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE) throw new MbeCanonicalError('JSON לא תקין: ' . json_last_error_msg());
        if (self::depthOf($v, 0) > self::MAX_DEPTH) throw new MbeCanonicalError('עומק JSON מעל ' . self::MAX_DEPTH);
        return self::fromObjects($v);
    }

    private static function fromObjects(mixed $v): mixed
    {
        if (is_array($v)) return array_map([self::class, 'fromObjects'], $v);
        if (!($v instanceof stdClass)) return $v;
        $a = [];
        foreach (get_object_vars($v) as $k => $x) $a[(string)$k] = self::fromObjects($x);
        if ($a === [] || array_is_list($a)) {
            $o = new stdClass();
            foreach ($a as $k => $x) $o->{(string)$k} = $x;
            return $o;
        }
        return $a;
    }

    private static function depthOf(mixed $v, int $d): int
    {
        if ($d > self::MAX_DEPTH || !(is_array($v) || is_object($v))) return $d;
        $max = $d;
        foreach ((array)$v as $x) {
            $max = max($max, self::depthOf($x, $d + 1));
            if ($max > self::MAX_DEPTH) break;
        }
        return $max;
    }

    private static function write(mixed $v, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) throw new MbeCanonicalError('עומק JSON מעל ' . self::MAX_DEPTH);
        if ($v === null) return 'null';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_int($v)) return abs($v) <= 9007199254740992 ? (string)$v : self::number((float)$v);
        if (is_float($v)) return self::number($v);
        if (is_string($v)) return self::string($v);
        if (is_array($v) && array_is_list($v)) {
            return '[' . implode(',', array_map(fn($x) => self::write($x, $depth + 1), $v)) . ']';
        }
        if (is_array($v) || $v instanceof stdClass) {
            $map = [];
            foreach ((array)$v as $k => $x) $map[(string)$k] = $x;
            $out = [];
            foreach (self::sortedKeys($map) as $k) $out[] = self::string($k) . ':' . self::write($map[$k], $depth + 1);
            return '{' . implode(',', $out) . '}';
        }
        throw new MbeCanonicalError('ערך מסוג ' . get_debug_type($v) . ' אינו JSON');
    }

    /** מפתחות ממוינים לפי יחידות UTF-16 (כמו sort() ב-JS): השוואת בתים של UTF-16BE שקולה לזה. */
    public static function sortedKeys(array $map): array
    {
        $keys = array_map('strval', array_keys($map));
        $u16 = [];
        foreach ($keys as $k) $u16[$k] = mb_convert_encoding($k, 'UTF-16BE', 'UTF-8');
        usort($keys, fn($a, $b) => strcmp($u16[$a], $u16[$b]));
        return $keys;
    }

    private static function string(string $s): string
    {
        $j = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        if ($j === false) throw new MbeCanonicalError('מחרוזת שאינה UTF-8 תקין');
        return $j;
    }

    /** Number::toString של ECMAScript — הספרות הקצרות ביותר (serialize_precision=-1) ואז כללי התצוגה של JS. */
    public static function number(float $f): string
    {
        if (!is_finite($f)) throw new MbeCanonicalError('מספר לא סופי (NaN/Infinity) אינו JSON');
        if ($f == 0.0) return '0';
        $old = ini_set('serialize_precision', '-1');
        $r = var_export($f, true); // ‎"1.0E+25", "5.0E-7", "0.30000000000000004", "100.0"
        if ($old !== false) ini_set('serialize_precision', $old);
        $neg = $r[0] === '-';
        if ($neg) $r = substr($r, 1);
        $exp = 0;
        if (($e = stripos($r, 'e')) !== false) { $exp = (int)substr($r, $e + 1); $r = substr($r, 0, $e); }
        $dot = strpos($r, '.');
        $int = $dot === false ? $r : substr($r, 0, $dot);
        $frac = $dot === false ? '' : substr($r, $dot + 1);
        $digits = ltrim($int . $frac, '0');
        $lead = strlen($int . $frac) - strlen($digits);   // אפסים מובילים שהוסרו
        $n = strlen($int) - $lead + $exp;                 // מיקום הנקודה ביחס לספרות
        $digits = rtrim($digits, '0');
        $k = strlen($digits);
        if ($k <= $n && $n <= 21) $s = $digits . str_repeat('0', $n - $k);
        elseif (0 < $n && $n <= 21) $s = substr($digits, 0, $n) . '.' . substr($digits, $n);
        elseif (-6 < $n && $n <= 0) $s = '0.' . str_repeat('0', -$n) . $digits;
        else {
            $e = $n - 1;
            $s = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
        }
        return ($neg ? '-' : '') . $s;
    }
}
