<?php
/**
 * מנתח ביטויים לשיטות מוגדרות (spec-stage-a 17.2.1, PR 4ב/1). שפה **סגורה**: מספרים, שמות מוכרים (אינדיקטורים
 * של ההגדרה, מחירי הנר, ומשתנים שהמקום מתיר — entry, dir), פרמטרים ($name), + - * /, השוואות, crosses_above,
 * crosses_below, and, or, וסוגריים.
 *
 * **אין eval, אין קוד חופשי, אין רשת, אין קבצים.** הטקסט הופך לעץ (מערכים) ומחושב בפונקציה אחת שמכירה רק את
 * הצמתים שלמעלה. כל תו שאינו ברשימה — נדחה; שם לא מוכר — נדחה; שם ואחריו "(" (קריאה לפונקציה) — נדחה; מחרוזות — אין.
 * תקרות: אורך הטקסט, מספר האסימונים ועומק הקינון.
 *
 * ערכים: float או null (אינדיקטור שעוד לא התחמם, חלוקה באפס). null בחשבון → null; null בהשוואה → false.
 */

declare(strict_types=1);

final class TsExprError extends InvalidArgumentException {}

const TS_EXPR_MAX_LEN = 500;
const TS_EXPR_MAX_TOKENS = 200;
const TS_EXPR_MAX_DEPTH = 32;
const TS_EXPR_CMP = ['<', '<=', '>', '>=', '==', '!=', 'crosses_above', 'crosses_below'];

/** טקסט → אסימונים. רק ASCII מהרשימה */
function ts_expr_tokens(string $src): array
{
    if (strlen($src) > TS_EXPR_MAX_LEN) throw new TsExprError('הביטוי ארוך מדי (עד ' . TS_EXPR_MAX_LEN . ' תווים)');
    if (preg_match('/[^A-Za-z0-9_$.+\-*\/<>=!() \t]/', $src, $m)) throw new TsExprError('תו לא מותר בביטוי: ' . json_encode($m[0], JSON_UNESCAPED_UNICODE));
    $out = [];
    $i = 0; $n = strlen($src);
    while ($i < $n) {
        $ch = $src[$i];
        if ($ch === ' ' || $ch === "\t") { $i++; continue; }
        if (preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)/', $src, $m, 0, $i)) {
            $out[] = ['num', (float)$m[0]];
        } elseif (preg_match('/\G\$[a-z][a-z0-9_]{0,39}/', $src, $m, 0, $i)) {
            $out[] = ['param', substr($m[0], 1)];
        } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]{0,39}/', $src, $m, 0, $i)) {
            $w = $m[0];
            $out[] = in_array($w, ['and', 'or', 'crosses_above', 'crosses_below'], true) ? ['op', $w] : ['id', $w];
        } elseif (preg_match('/\G(?:<=|>=|==|!=|[<>+\-*\/()])/', $src, $m, 0, $i)) {
            $out[] = ['op', $m[0]];
        } else {
            throw new TsExprError('ביטוי לא תקין ליד ' . json_encode(substr($src, $i, 10), JSON_UNESCAPED_UNICODE));
        }
        $i += strlen($m[0]);
        if (count($out) > TS_EXPR_MAX_TOKENS) throw new TsExprError('הביטוי ארוך מדי (אסימונים)');
    }
    if (!$out) throw new TsExprError('ביטוי ריק');
    return $out;
}

/**
 * מנתח ביטוי לעץ. $names — השמות המותרים (id → true); $params — הפרמטרים המותרים.
 * $want: 'bool' (תנאי) או 'num' (רמה). מחזיר את העץ, או זורק TsExprError עם הודעה בעברית.
 */
function ts_expr_parse(string $src, array $names, array $params, string $want = 'bool'): array
{
    $tok = ts_expr_tokens($src);
    $pos = 0;
    $peek = function () use (&$tok, &$pos) { return $tok[$pos] ?? null; };
    $isOp = fn($t, string ...$ops) => $t !== null && $t[0] === 'op' && in_array($t[1], $ops, true);

    $or = null; $atom = null;   // הצהרה מוקדמת לרקורסיה
    $atom = function (int $d) use (&$pos, $peek, $isOp, &$or, &$atom, $names, $params): array {
        if ($d > TS_EXPR_MAX_DEPTH) throw new TsExprError('קינון עמוק מדי (עד ' . TS_EXPR_MAX_DEPTH . ')');
        $t = $peek();
        if ($t === null) throw new TsExprError('הביטוי נגמר באמצע');
        if ($isOp($t, '(')) {
            $pos++;
            $e = $or($d + 1);
            if (!$isOp($peek(), ')')) throw new TsExprError('חסר ")"');
            $pos++;
            return $e;
        }
        if ($isOp($t, '-')) {
            $pos++;
            $e = $atom($d + 1);
            if ($e['t'] !== 'num') throw new TsExprError('מינוס לפני תנאי');
            return ['k' => 'neg', 'a' => $e, 't' => 'num'];
        }
        $pos++;
        if ($t[0] === 'num') return ['k' => 'num', 'v' => $t[1], 't' => 'num'];
        if ($t[0] === 'param') {
            if (!isset($params[$t[1]])) throw new TsExprError('פרמטר לא מוגדר: $' . $t[1]);
            return ['k' => 'param', 'n' => $t[1], 't' => 'num'];
        }
        if ($t[0] === 'id') {
            if ($isOp($peek(), '(')) throw new TsExprError('קריאה לפונקציה אינה מותרת: ' . $t[1]);
            if (!isset($names[$t[1]])) throw new TsExprError('שם לא מוכר: ' . $t[1]);
            return ['k' => 'var', 'n' => $t[1], 't' => 'num'];
        }
        throw new TsExprError('לא צפוי: ' . $t[1]);
    };
    $bin = function (callable $next, array $ops, string $type) use (&$pos, $peek, $isOp) {
        return function (int $d) use ($next, $ops, $type, &$pos, $peek, $isOp): array {
            $l = $next($d);
            while ($isOp($peek(), ...$ops)) {
                $op = $peek()[1];
                $pos++;
                $r = $next($d);
                foreach ([$l, $r] as $x) if ($x['t'] !== $type) throw new TsExprError("\"$op\" צריך " . ($type === 'num' ? 'מספרים' : 'תנאים') . ' משני הצדדים');
                $l = ['k' => 'bin', 'op' => $op, 'a' => $l, 'b' => $r, 't' => $type];
            }
            return $l;
        };
    };
    $prod = $bin($atom, ['*', '/'], 'num');
    $sum = $bin($prod, ['+', '-'], 'num');
    $cmp = function (int $d) use ($sum, &$pos, $peek, $isOp): array {
        $l = $sum($d);
        if (!$isOp($peek(), ...TS_EXPR_CMP)) return $l;
        $op = $peek()[1];
        $pos++;
        $r = $sum($d);
        if ($l['t'] !== 'num' || $r['t'] !== 'num') throw new TsExprError("\"$op\" משווה מספרים");
        if (str_starts_with($op, 'crosses_') && (!ts_expr_series($l) || !ts_expr_series($r))) throw new TsExprError("$op — רק בין אינדיקטורים, מחירים ומספרים");
        if ($isOp($peek(), ...TS_EXPR_CMP)) throw new TsExprError('השוואה בשרשרת אינה מותרת — השתמשו ב-and');
        return ['k' => 'cmp', 'op' => $op, 'a' => $l, 'b' => $r, 't' => 'bool'];
    };
    $and = $bin($cmp, ['and'], 'bool');
    $or = $bin($and, ['or'], 'bool');

    $tree = $or(0);
    if ($pos !== count($tok)) throw new TsExprError('תוכן מיותר בסוף הביטוי: ' . $tok[$pos][1]);
    if ($tree['t'] !== $want) throw new TsExprError($want === 'bool' ? 'נדרש תנאי (השוואה), לא מספר' : 'נדרש מספר, לא תנאי');
    return $tree;
}

/** crosses_* מותר רק על סדרה שיש לה ערך קודם: משתנה, פרמטר, מספר, או חשבון שלהם */
function ts_expr_series(array $e): bool
{
    return match ($e['k']) {
        'num', 'param', 'var' => true,
        'neg' => ts_expr_series($e['a']),
        'bin' => ts_expr_series($e['a']) && ts_expr_series($e['b']),
        default => false,
    };
}

/**
 * מחשב עץ. $vars — הערכים בנר הנוכחי (name → float|null); $prev — בנר הקודם (ל-crosses_*); $params — הפרמטרים.
 * crosses_above: בנר הקודם a ≤ b, ועכשיו a > b. crosses_below: בקודם a ≥ b, ועכשיו a < b.
 */
function ts_expr_eval(array $e, array $vars, array $prev, array $params): float|bool|null
{
    switch ($e['k']) {
        case 'num': return $e['v'];
        case 'param': return (float)$params[$e['n']];
        case 'var': return $vars[$e['n']] ?? null;
        case 'neg':
            $a = ts_expr_eval($e['a'], $vars, $prev, $params);
            return $a === null ? null : -$a;
        case 'bin':
            $a = ts_expr_eval($e['a'], $vars, $prev, $params);
            if ($e['op'] === 'and') return $a && ts_expr_eval($e['b'], $vars, $prev, $params);
            if ($e['op'] === 'or') return $a || ts_expr_eval($e['b'], $vars, $prev, $params);
            $b = ts_expr_eval($e['b'], $vars, $prev, $params);
            if ($a === null || $b === null) return null;
            return match ($e['op']) {
                '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
                '/' => $b == 0.0 ? null : $a / $b,
            };
        case 'cmp':
            $a = ts_expr_eval($e['a'], $vars, $prev, $params);
            $b = ts_expr_eval($e['b'], $vars, $prev, $params);
            if ($a === null || $b === null) return false;
            if (str_starts_with($e['op'], 'crosses_')) {
                $pa = ts_expr_eval($e['a'], $prev, [], $params);
                $pb = ts_expr_eval($e['b'], $prev, [], $params);
                if ($pa === null || $pb === null) return false;
                return $e['op'] === 'crosses_above' ? $pa <= $pb && $a > $b : $pa >= $pb && $a < $b;
            }
            return match ($e['op']) {
                '<' => $a < $b, '<=' => $a <= $b, '>' => $a > $b, '>=' => $a >= $b, '==' => $a == $b, '!=' => $a != $b,
            };
    }
    throw new TsExprError('צומת לא מוכר');
}
