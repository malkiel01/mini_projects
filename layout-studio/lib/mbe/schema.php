<?php
/**
 * בודק JSON Schema קטן — אותן מילים, אותן בעיות ובאותו סדר כמו src/js/schema.js.
 * הערך בייצוג של MbeCanonical::decode (מערכים, ו-stdClass לאובייקט ריק / דמוי-רשימה); הסכמה — מערך.
 */

declare(strict_types=1);

final class MbeSchema
{
    public const MAX_ISSUES = 100;
    private const KNOWN = ['$id', '$schema', 'title', 'description', '$defs', '$ref', 'type', 'required', 'properties',
        'additionalProperties', 'items', 'const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'exclusiveMinimum', 'minItems', 'maxItems'];

    /** @return list<array{path:string,message:string}> */
    public static function validate(mixed $value, array $schema): array
    {
        $issues = [];
        self::check($value, $schema, $schema, '', $issues);
        return $issues;
    }

    public static function typeOf(mixed $v): string
    {
        if ($v === null) return 'null';
        if (is_bool($v)) return 'boolean';
        if (is_int($v)) return 'integer';
        if (is_float($v)) return (is_finite($v) && floor($v) === $v) ? 'integer' : 'number';
        if (is_string($v)) return 'string';
        if ($v instanceof stdClass) return 'object';
        if (is_array($v)) return array_is_list($v) ? 'array' : 'object';
        return 'unknown';
    }

    private static function typeMatches(mixed $v, string $t): bool
    {
        $a = self::typeOf($v);
        return $a === $t || ($t === 'number' && $a === 'integer');
    }

    private static function check(mixed $v, array $s, array $root, string $path, array &$issues): void
    {
        if (count($issues) >= self::MAX_ISSUES) return;
        foreach (array_keys($s) as $k) {
            if (!in_array($k, self::KNOWN, true)) throw new LogicException("schema: מילה לא נתמכת \"$k\"");
        }
        $add = function (string $message) use (&$issues, $path) { $issues[] = ['path' => $path === '' ? '/' : $path, 'message' => $message]; };
        if (isset($s['$ref'])) {
            if (!preg_match('~^#/\$defs/([A-Za-z0-9_-]+)$~', $s['$ref'], $m) || !isset($root['$defs'][$m[1]])) {
                throw new LogicException("schema: \$ref לא ידוע {$s['$ref']}");
            }
            self::check($v, $root['$defs'][$m[1]], $root, $path, $issues);
        }
        $type = self::typeOf($v);
        if (array_key_exists('type', $s)) {
            $types = (array)$s['type'];
            $match = false;
            foreach ($types as $t) if (self::typeMatches($v, $t)) $match = true;
            if (!$match) { $add('צריך להיות ' . implode(' או ', $types)); return; }
        }
        if (array_key_exists('const', $s) && $v !== $s['const']) {
            $add('צריך להיות ' . json_encode($s['const'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if (is_string($v)) {
            $len = mb_strlen($v, 'UTF-8');
            if (isset($s['minLength']) && $len < $s['minLength']) $add("קצר מ-{$s['minLength']} תווים");
            if (isset($s['maxLength']) && $len > $s['maxLength']) $add("ארוך מ-{$s['maxLength']} תווים");
            if (isset($s['pattern']) && !preg_match('/' . str_replace('/', '\/', $s['pattern']) . '/u', $v)) $add('לא בתבנית הנדרשת');
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s['minimum']) && $v < $s['minimum']) $add("קטן מ-{$s['minimum']}");
            if (isset($s['maximum']) && $v > $s['maximum']) $add("גדול מ-{$s['maximum']}");
            if (isset($s['exclusiveMinimum']) && $v <= $s['exclusiveMinimum']) $add("צריך להיות גדול מ-{$s['exclusiveMinimum']}");
        }
        if ($type === 'array') {
            if (isset($s['minItems']) && count($v) < $s['minItems']) $add("פחות מ-{$s['minItems']} פריטים");
            if (isset($s['maxItems']) && count($v) > $s['maxItems']) { $add("יותר מ-{$s['maxItems']} פריטים"); return; }
            if (isset($s['items'])) foreach ($v as $i => $x) self::check($x, $s['items'], $root, "$path/$i", $issues);
        }
        if ($type === 'object') {
            $map = [];
            foreach ((array)$v as $k => $x) $map[(string)$k] = $x;
            foreach ($s['required'] ?? [] as $r) if (!array_key_exists($r, $map)) $add("חסר השדה \"$r\"");
            foreach (MbeCanonical::sortedKeys($map) as $k) {
                $sub = (isset($s['properties']) && array_key_exists($k, $s['properties'])) ? $s['properties'][$k] : ($s['additionalProperties'] ?? null);
                if ($sub === false) $add("שדה לא מותר \"$k\"");
                elseif (is_array($sub)) self::check($map[$k], $sub, $root, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], $k), $issues);
            }
        }
    }
}
