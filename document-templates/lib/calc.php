<?php
/**
 * שלב 11: שדות מחושבים ותנאי הצגה.
 *
 * שפת ביטויים קטנה, זהה ל-assets/js/calc.js (שתיהן נבדקות מול
 * tests/calc-vectors.json): מספרים, + - * /, סוגריים, השוואות (= != > < >= <=),
 * ופונקציות: sum(טבלה.עמודה), count(טבלה), round(x, n), min, max, if(תנאי, א, ב),
 * days(תאריך, n) — תאריך + n ימים, diff(תאריך1, תאריך2) — ימים ביניהם.
 *
 * מזהה = שם שדה: המפתח, הסימון ({{...}}) או התווית עם "_" במקום רווח.
 * הדפדפן מחשב לתצוגה מיידית; השרת מחשב מחדש בכל שמירה, ורק הערך שלו נשמר.
 */

declare(strict_types=1);

const CALC_FORMATS = ['number', 'money', 'date'];

final class CalcError extends RuntimeException {}

/** מפרק ביטוי לאסימונים. */
function calc_tokens(string $s): array
{
    $out = [];
    $i = 0;
    $n = mb_strlen($s);
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    while ($i < $n) {
        $c = $chars[$i];
        if (preg_match('/\s/u', $c)) { $i++; continue; }
        if (preg_match('/[0-9.]/', $c)) {
            $j = $i;
            while ($j < $n && preg_match('/[0-9.]/', $chars[$j])) $j++;
            $num = implode('', array_slice($chars, $i, $j - $i));
            if (!is_numeric($num)) throw new CalcError("מספר לא תקין: $num");
            $out[] = ['num', (float)$num];
            $i = $j;
            continue;
        }
        if (preg_match('/[\p{L}_"\']/u', $c)) {
            $j = $i;
            // מזהה: אותיות, ספרות, _, נקודה (טבלה.עמודה), וגרשיים (סה"כ, ת"ז)
            while ($j < $n && preg_match('/[\p{L}\p{N}_."\']/u', $chars[$j])) $j++;
            $out[] = ['id', implode('', array_slice($chars, $i, $j - $i))];
            $i = $j;
            continue;
        }
        $two = $c . ($chars[$i + 1] ?? '');
        if (in_array($two, ['>=', '<=', '!=', '=='], true)) { $out[] = ['op', $two === '==' ? '=' : $two]; $i += 2; continue; }
        if (str_contains('+-*/(),=<>', $c)) { $out[] = ['op', $c]; $i++; continue; }
        throw new CalcError("תו לא צפוי: $c");
    }
    return $out;
}

/**
 * מחשב ביטוי. $lookup(string $name): float|string|array|null — ערך המזהה
 * (null = לא מוכר). מערך = עמודה של טבלה (לסכום).
 */
function calc_eval(string $expr, callable $lookup)
{
    $t = calc_tokens($expr);
    $p = 0;
    $peek = function () use (&$t, &$p) { return $t[$p] ?? null; };
    $eat = function (?string $op = null) use (&$t, &$p) {
        $x = $t[$p] ?? null;
        if ($op !== null && (!$x || $x[0] !== 'op' || $x[1] !== $op)) throw new CalcError('חסר "' . $op . '"');
        $p++;
        return $x;
    };
    $num = function ($v): float {
        if (is_array($v)) return (float)array_sum(array_map('floatval', $v));
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return (float)(strtotime($v . ' UTC') / 86400);
        return is_numeric($v) ? (float)$v : 0.0;
    };
    $cmp = null; $add = null; $mul = null; $unary = null; $atom = null;
    $cmp = function () use (&$add, $peek, $eat, $num) {
        $a = $add();
        $x = $peek();
        if ($x && $x[0] === 'op' && in_array($x[1], ['=', '!=', '>', '<', '>=', '<='], true)) {
            $eat();
            $b = $add();
            if (in_array($x[1], ['=', '!='], true) && !is_numeric($a) && !is_numeric($b)) {
                $eq = (string)(is_array($a) ? '' : $a) === (string)(is_array($b) ? '' : $b);
            } else {
                $eq = abs($num($a) - $num($b)) < 1e-9;
            }
            $r = match ($x[1]) {
                '=' => $eq, '!=' => !$eq,
                '>' => $num($a) > $num($b), '<' => $num($a) < $num($b),
                '>=' => $num($a) >= $num($b), '<=' => $num($a) <= $num($b),
            };
            return $r ? 1.0 : 0.0;
        }
        return $a;
    };
    $add = function () use (&$mul, $peek, $eat, $num) {
        $a = $mul();
        while (($x = $peek()) && $x[0] === 'op' && ($x[1] === '+' || $x[1] === '-')) {
            $eat();
            $b = $mul();
            $a = $x[1] === '+' ? $num($a) + $num($b) : $num($a) - $num($b);
        }
        return $a;
    };
    $mul = function () use (&$unary, $peek, $eat, $num) {
        $a = $unary();
        while (($x = $peek()) && $x[0] === 'op' && ($x[1] === '*' || $x[1] === '/')) {
            $eat();
            $b = $unary();
            if ($x[1] === '*') { $a = $num($a) * $num($b); continue; }
            $a = $num($b) == 0.0 ? 0.0 : $num($a) / $num($b);   // חלוקה באפס — 0, לא שגיאה
        }
        return $a;
    };
    $unary = function () use (&$atom, $peek, $eat, $num) {
        $x = $peek();
        if ($x && $x[0] === 'op' && $x[1] === '-') { $eat(); return -$num($atom()); }
        return $atom();
    };
    $atom = function () use (&$cmp, $peek, $eat, $num, $lookup) {
        $x = $eat();
        if (!$x) throw new CalcError('הביטוי נגמר באמצע');
        if ($x[0] === 'num') return $x[1];
        if ($x[0] === 'op' && $x[1] === '(') { $v = $cmp(); $eat(')'); return $v; }
        if ($x[0] !== 'id') throw new CalcError('לא צפוי: ' . $x[1]);
        $name = $x[1];
        $next = $peek();
        if ($next && $next[0] === 'op' && $next[1] === '(') {
            $eat('(');
            $args = [];
            if (!(($y = $peek()) && $y[0] === 'op' && $y[1] === ')')) {
                $args[] = $cmp();
                while (($y = $peek()) && $y[0] === 'op' && $y[1] === ',') { $eat(); $args[] = $cmp(); }
            }
            $eat(')');
            return calc_fn(strtolower($name), $args, $num);
        }
        $v = $lookup($name);
        if ($v === null) throw new CalcError('לא מוכר: ' . $name);
        return $v;
    };
    if (!$t) return '';
    $v = $cmp();
    if ($p < count($t)) throw new CalcError('מיותר בסוף: ' . $t[$p][1]);
    return $v;
}

function calc_fn(string $fn, array $a, callable $num)
{
    $need = fn(int $k) => count($a) >= $k ?: throw new CalcError("ל-$fn חסרים ערכים");
    switch ($fn) {
        case 'sum':   $need(1); return array_sum(array_map($num, $a));
        case 'count': $need(1); return (float)(is_array($a[0]) ? count($a[0]) : ($a[0] === '' ? 0 : 1));
        case 'round': $need(1); return round($num($a[0]), (int)$num($a[1] ?? 0));
        case 'min':   $need(1); return min(array_map($num, $a));
        case 'max':   $need(1); return max(array_map($num, $a));
        case 'if':    $need(3); return $num($a[0]) != 0.0 ? $a[1] : $a[2];
        case 'days':
            $need(2);
            if (!is_string($a[0]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a[0])) return '';
            return gmdate('Y-m-d', strtotime($a[0] . ' UTC') + (int)round($num($a[1])) * 86400);
        case 'diff':
            $need(2);
            return round($num($a[1]) - $num($a[0]));
    }
    throw new CalcError('פונקציה לא מוכרת: ' . $fn);
}

/** מספר → מחרוזת לשמירה: עד 2 ספרות אחרי הנקודה, בלי אפסים מיותרים. */
function calc_str($v): string
{
    if (is_string($v)) return $v;
    if (is_array($v)) return '';
    $v = round((float)$v, 2);
    if (abs($v) < 1e-9) $v = 0.0;
    $s = number_format($v, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/** הצגה במסמך: 1,234.5 · ‏1,234.50 ₪ · 13/10/2026. */
function calc_display(string $v, string $format): string
{
    if ($v === '') return '';
    if ($format === 'date') return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v;
    if (!is_numeric($v)) return $v;
    if ($format === 'money') return number_format((float)$v, 2, '.', ',') . ' ₪';
    $dec = str_contains($v, '.') ? strlen(substr($v, strpos($v, '.') + 1)) : 0;
    return number_format((float)$v, $dec, '.', ',');
}

/** שם → מזהים אפשריים של שדה: מפתח, סימון, תווית עם "_". */
function calc_names(array $f): array
{
    $n = [$f['key']];
    if (!empty($f['placeholder'])) $n[] = $f['placeholder'];
    if (!empty($f['label'])) $n[] = preg_replace('/\s+/u', '_', trim($f['label']));
    return $n;
}

/** טבלה: שורות כמערך של [מפתח-עמודה => ערך]. */
function table_rows(string $v): array
{
    $rows = $v === '' ? [] : json_decode($v, true);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** מאתר עמודה בטבלה לפי מפתח או תווית. */
function table_col(array $f, string $name): ?array
{
    foreach ($f['columns'] ?? [] as $c) {
        if ($c['key'] === $name || ($c['name'] ?? '') === $name || preg_replace('/\s+/u', '_', trim($c['label'])) === $name) return $c;
    }
    return null;
}

/**
 * פונקציית חיפוש לביטוי: שדות המסמך, עמודות "טבלה.עמודה", ובתוך שורה של טבלה —
 * גם העמודות של אותה שורה ישירות.
 */
function calc_lookup(array $fields, array $values, ?array $tableField = null, ?array $row = null): callable
{
    $byName = [];
    foreach ($fields as $f) foreach (calc_names($f) as $n) $byName[$n] ??= $f;
    return function (string $name) use ($byName, $values, $tableField, $row) {
        if ($tableField && $row !== null && ($c = table_col($tableField, $name))) return $row[$c['key']] ?? '';
        if (str_contains($name, '.')) {
            [$t, $col] = explode('.', $name, 2);
            $f = $byName[$t] ?? null;
            if (!$f || $f['type'] !== 'table' || !($c = table_col($f, $col))) return null;
            return array_map(fn($r) => $r[$c['key']] ?? '', table_rows($values[$f['key']] ?? ''));
        }
        $f = $byName[$name] ?? null;
        if (!$f) return null;
        $v = $values[$f['key']] ?? '';
        if ($f['type'] === 'table') return table_rows($v);
        if ($f['type'] === 'checkbox') return $v === '1' ? 1.0 : 0.0;
        return $v;
    };
}

/** בודק שביטוי תקין ושכל המזהים מוכרים. מחזיר הודעת שגיאה, או null. */
function calc_check(string $expr, array $fields, ?array $tableField = null): ?string
{
    if (trim($expr) === '') return null;
    try {
        $row = $tableField ? array_fill_keys(array_column($tableField['columns'] ?? [], 'key'), '') : null;
        calc_eval($expr, calc_lookup($fields, [], $tableField, $row));
        return null;
    } catch (CalcError $e) {
        return $e->getMessage();
    }
}

/** ערך של עמודות מחושבות בשורות טבלה. */
function table_compute(array $f, array $fields, array $values, array $rows): array
{
    foreach ($rows as &$r) {
        foreach ($f['columns'] ?? [] as $c) {
            if ($c['type'] !== 'calc') continue;
            try { $r[$c['key']] = calc_str(calc_eval($c['formula'] ?? '', calc_lookup($fields, $values, $f, $r))); }
            catch (CalcError) { $r[$c['key']] = ''; }
        }
    }
    return $rows;
}

/**
 * מחשב את כל השדות המחושבים (גם שתלויים זה בזה — כמה סבבים, עד שמתייצב).
 * מחזיר רק את מה שהשתנה: [מפתח => ערך].
 */
function calc_all(array $schema, array $values): array
{
    $fields = $schema['fields'] ?? [];
    $calcs = array_filter($fields, fn($f) => $f['type'] === 'calc');
    if (!$calcs) return [];
    $orig = $values;
    for ($pass = 0; $pass <= count($calcs); $pass++) {
        $changed = false;
        foreach ($calcs as $f) {
            try { $v = calc_str(calc_eval($f['formula'] ?? '', calc_lookup($fields, $values))); }
            catch (CalcError) { $v = ''; }
            if (($values[$f['key']] ?? '') !== $v) { $values[$f['key']] = $v; $changed = true; }
        }
        if (!$changed) break;
    }
    $out = [];
    foreach ($calcs as $f) if (($orig[$f['key']] ?? '') !== ($values[$f['key']] ?? '')) $out[$f['key']] = $values[$f['key']] ?? '';
    return $out;
}

/** תנאי הצגה: {key, op: eq|neq|filled|empty, value}. בלי תנאי — מוצג. */
function is_shown(?array $cond, array $fields, array $values): bool
{
    if (!$cond || empty($cond['key'])) return true;
    $f = null;
    foreach ($fields as $x) if ($x['key'] === $cond['key']) { $f = $x; break; }
    if (!$f) return true;
    $v = (string)($values[$f['key']] ?? '');
    if ($f['type'] === 'table') $v = table_rows($v) ? '1' : '';
    $want = (string)($cond['value'] ?? '');
    if ($f['type'] === 'checkbox') { $v = $v === '1' ? '1' : ''; $want = in_array($want, ['1', 'כן', 'true'], true) ? '1' : ''; }
    return match ($cond['op'] ?? 'eq') {
        'filled' => trim($v) !== '',
        'empty' => trim($v) === '',
        'neq' => trim($v) !== trim($want),
        default => trim($v) === trim($want),
    };
}

/** השדות שמוצגים עכשיו (לפי הערכים). שדה שתנאיו תלוי בשדה מוסתר — מוסתר גם הוא. */
function visible_fields(array $schema, array $values): array
{
    $fields = $schema['fields'] ?? [];
    $shown = [];
    foreach ($fields as $f) $shown[$f['key']] = true;
    for ($pass = 0; $pass < 5; $pass++) {
        $changed = false;
        foreach ($fields as $f) {
            $c = $f['showIf'] ?? null;
            $ok = is_shown($c, $fields, $values) && (empty($c['key']) || ($shown[$c['key']] ?? true));
            if ($ok !== $shown[$f['key']]) { $shown[$f['key']] = $ok; $changed = true; }
        }
        if (!$changed) break;
    }
    return array_values(array_filter($fields, fn($f) => $shown[$f['key']]));
}
