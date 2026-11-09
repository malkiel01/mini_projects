<?php
// בדיקות מנתח הביטויים ו-TsDefinedMethod (spec-stage-a 17.2.1, 17.2.5; PR 4ב/1). טהור — בלי מסד, בלי רשת.
// php trading-strategies/tests/defined.php
if (PHP_SAPI !== 'cli') exit;
define('TS_DB_PATH', sys_get_temp_dir() . '/ts-def-unused-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite');   // לא נפתח
require_once dirname(__DIR__) . '/lib/analysis.php';
require_once dirname(__DIR__) . '/lib/methods/defined.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function near($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want || ($got !== null && $want !== null && abs($got - $want) <= 1e-9 * max(1, abs($want)))) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
/** הודעת השגיאה של מנתח — או null כשעבר */
function rejects(callable $f): ?string
{
    try { $f(); return null; } catch (TsExprError $e) { return $e->getMessage(); }
}

// ── המנתח ────────────────────────────────────────────────
$N = ['ma_fast' => true, 'ma_slow' => true, 'close' => true, 'atr' => true];
$PP = ['fast' => ['label' => 'x', 'default' => 20, 'min' => 2, 'max' => 200, 'int' => true]];
$ev = fn(string $src, array $vars, array $prev = [], string $want = 'bool', array $names = []) =>
    ts_expr_eval(ts_expr_parse($src, ($names ?: $N), $PP, $want), $vars, $prev, ['fast' => 20.0]);

eq($ev('1 + 2 * 3', [], [], 'num'), 7.0, 'precedence: * before +');
eq($ev('(1 + 2) * 3', [], [], 'num'), 9.0, 'parentheses');
eq($ev('10 - 4 - 3', [], [], 'num'), 3.0, 'left-associative minus');
eq($ev('-close + $fast', ['close' => 5.0], [], 'num'), 15.0, 'unary minus and a $param');
eq($ev('close / 0', ['close' => 5.0], [], 'num'), null, 'division by zero → null (not an error)');
eq($ev('ma_fast > ma_slow and close >= 10 or atr < 0', ['ma_fast' => 2.0, 'ma_slow' => 1.0, 'close' => 10.0, 'atr' => 1.0]), true, 'and binds tighter than or');
eq($ev('ma_fast > ma_slow', ['ma_fast' => null, 'ma_slow' => 1.0]), false, 'null in a comparison → false (not ready)');
eq($ev('ma_fast crosses_above ma_slow', ['ma_fast' => 2.0, 'ma_slow' => 1.0], ['ma_fast' => 1.0, 'ma_slow' => 1.0]), true, 'crosses_above: before ≤, now >');
eq($ev('ma_fast crosses_above ma_slow', ['ma_fast' => 2.0, 'ma_slow' => 1.0], ['ma_fast' => 1.5, 'ma_slow' => 1.0]), false, 'crosses_above: already above → no cross');
eq($ev('ma_fast crosses_below ma_slow', ['ma_fast' => 0.5, 'ma_slow' => 1.0], ['ma_fast' => 1.0, 'ma_slow' => 1.0]), true, 'crosses_below: before ≥, now <');
eq($ev('ma_fast crosses_above ma_slow', ['ma_fast' => 2.0, 'ma_slow' => 1.0], []), false, 'crosses with no previous bar → false');
eq($ev('close crosses_above 10', ['close' => 11.0], ['close' => 9.0]), true, 'crosses against a constant');
eq(ts_expr_parse('close > 1', $N, $PP)['k'], 'cmp', 'the parse result is a tree (arrays), not code');

// ── קלט זדוני ונסיונות לפרוץ את השפה — נדחים ──
$rej = fn(string $src, string $want = 'bool') => rejects(fn() => ts_expr_parse($src, $N, $PP, $want));
$cases = [
    'function call' => ["system('ls')", 'תו לא מותר'],
    'function call (no quotes)' => ['system(close)', 'קריאה לפונקציה'],
    'function call (php builtin)' => ['file_get_contents(close) > 1', 'קריאה לפונקציה'],
    'file path' => ['/etc/passwd', 'לא צפוי'],
    'file name' => ['close > data.sqlite', 'ביטוי לא תקין'],
    'backticks' => ['`ls` > 1', 'תו לא מותר'],
    'loop keyword' => ['while (1) > 1', 'קריאה לפונקציה'],
    'loop with braces' => ['for(;;){}', 'תו לא מותר'],
    'semicolon (statement)' => ['close > 1; close < 2', 'תו לא מותר'],
    'string literal' => ['"abc" == close', 'תו לא מותר'],
    'php variable' => ['$_GET > 1', 'ביטוי לא תקין'],
    'unknown param' => ['$nope > 1', 'פרמטר לא מוגדר'],
    'unknown name' => ['eval > 1', 'שם לא מוכר'],
    'assignment' => ['close = 1', 'ביטוי לא תקין'],
    'array/index' => ['close[0] > 1', 'תו לא מותר'],
    'object access' => ['close->x > 1', 'לא צפוי'],
    'unicode/hebrew' => ['סגירה > 1', 'תו לא מותר'],
    'newline injection' => ["close > 1\n or 1 > 0", 'תו לא מותר'],
    'chained comparison' => ['1 < close < 3', 'בשרשרת'],
    'number where a condition is needed' => ['close + 1', 'נדרש תנאי'],
    'condition where a number is needed' => ['close > 1', 'נדרש מספר'],
    'and on numbers' => ['close and 1', 'צריך תנאים'],
    'trailing garbage' => ['close > 1 close', 'תוכן מיותר'],
    'unclosed parenthesis' => ['(close > 1', 'חסר ")"'],
    'empty' => ['   ', 'ביטוי ריק'],
];
foreach ($cases as $what => [$src, $msg]) {
    $e = $rej($src, $what === 'condition where a number is needed' ? 'num' : 'bool');
    eq($e !== null && str_contains($e, $msg), true, "rejected: $what" . ($e === null ? ' (accepted!)' : " — $e"));
}
eq(str_contains((string)$rej(str_repeat('close + ', 70) . 'close > 1'), 'ארוך מדי'), true, 'length cap: a long expression is rejected');
eq(str_contains((string)$rej(str_repeat('1+', 101) . '1 > 0'), 'ארוך מדי'), true, 'token cap: too many tokens are rejected');
eq(str_contains((string)$rej(str_repeat('(', 40) . 'close' . str_repeat(')', 40) . ' > 1'), 'קינון עמוק'), true, 'depth cap: nesting beyond 32 is rejected');
eq(str_contains((string)$rej(str_repeat('-', 40) . 'close > 1'), 'קינון עמוק'), true, 'depth cap: also through unary minus');
eq($rej(str_repeat('(', 30) . 'close' . str_repeat(')', 30) . ' > 1'), null, 'nesting of 30 is allowed');
$long = rejects(fn() => ts_expr_parse(str_repeat('a', 600), $N, $PP));
eq($long !== null && !str_contains($long, str_repeat('a', 20)), true, 'the error message does not echo the input');
// אין eval ואין קוד דינמי בקבצים — גם לא בעקיפין
$code = file_get_contents(dirname(__DIR__) . '/lib/expr.php') . file_get_contents(dirname(__DIR__) . '/lib/methods/defined.php');
$code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code);   // בלי הערות
eq(preg_match('/\b(eval|assert|create_function|call_user_func(_array)?|include|require(?!_once dirname)|file_get_contents|fopen|exec|system|shell_exec|passthru|proc_open|popen|curl_\w+|preg_replace_callback|unserialize)\s*\(/i', $code, $m) ? $m[0] : null, null, 'no eval / dynamic code / files / network in the parser and the adapter');

// ── TsDefinedMethod: sample-ma-cross כהגדרה ─────────────
$def = [
    'id' => 'ma-cross-def', 'name' => 'חציית ממוצעים 20/50 (מוגדרת)', 'source' => 'שיטת דוגמה — לא מהספר', 'version' => 1,
    'markets' => TS_DEF_MARKETS, 'timeframes' => TS_DEF_TIMEFRAMES,
    'params' => [
        'fast' => ['label' => 'ממוצע קצר', 'default' => 20, 'min' => 2, 'max' => 200, 'int' => true],
        'slow' => ['label' => 'ממוצע ארוך', 'default' => 50, 'min' => 3, 'max' => 400, 'int' => true],
        'atr' => ['label' => 'אורך ATR', 'default' => 14, 'min' => 2, 'max' => 100, 'int' => true],
        'stop_atr' => ['label' => 'סטופ (× ATR)', 'default' => 2, 'min' => 0.5, 'max' => 10, 'int' => false],
        'target_atr' => ['label' => 'יעד (× ATR)', 'default' => 3, 'min' => 0.5, 'max' => 20, 'int' => false],
        'horizon' => ['label' => 'אופק (נרות)', 'default' => 50, 'min' => 1, 'max' => 500, 'int' => true],
    ],
    'indicators' => [
        ['id' => 'ma_fast', 'type' => 'sma', 'source' => 'close', 'length' => '$fast'],
        ['id' => 'ma_slow', 'type' => 'sma', 'source' => 'close', 'length' => '$slow'],
        ['id' => 'atr14', 'type' => 'atr', 'length' => '$atr'],
    ],
    'rules' => [
        'long' => ['all' => [['left' => 'ma_fast', 'op' => 'crosses_above', 'right' => 'ma_slow']]],
        'short' => ['all' => ['ma_fast crosses_below ma_slow']],
    ],
    'levels' => ['stop' => 'entry - dir * $stop_atr * atr14', 'target' => 'entry + dir * $target_atr * atr14'],
    'horizon_bars' => '$horizon',
    'validate' => [['expr' => '$fast < $slow', 'message' => 'הממוצע הקצר חייב להיות קצר מהארוך']],
];
$dm = new TsDefinedMethod($def);
$sm = ts_method('sample-ma-cross');
$rows = json_decode(file_get_contents(__DIR__ . '/fixtures/backtest/candles-h1.json'), true);
$C = ts_bt_floats($rows);
$P = $dm->params();
eq($P, $sm->params(), 'defined: the same parameters and defaults as sample-ma-cross');
eq([$dm->meta()['kind'], $dm->meta()['label'], $dm->meta()['horizon_bars'], $dm->meta()['incremental'], $dm->meta()['alerts']], ['defined', 'מוגדרת ידנית', 50, true, false], 'meta: kind defined, "מוגדרת ידנית", horizon from the param default, incremental');
$sigD = $dm->signals($C, $P);
$sigS = $sm->signals($C, $P);
eq([count($sigD) > 20, $sigD === $sigS], [true, true], 'equality (17.2.5): the definition gives exactly the same signals as sample-ma-cross (' . count($sigS) . ' signals: t, side, entry, stop, target)');
foreach ([['fee' => 0, 'slip' => 0], ['fee' => 0.001, 'slip' => 0.0005]] as $cost) {
    $rd = ts_bt_result(ts_bt_run($dm, $P, $C, $cost));
    $rs = ts_bt_result(ts_bt_run($sm, $P, $C, $cost));
    eq($rd, $rs, 'equality: the same backtest result (N, wins, expectancy, drawdown, skipped) — fee ' . $cost['fee']);
}
$alt = ['fast' => 10, 'slow' => 30, 'atr' => 7, 'stop_atr' => 1.5, 'target_atr' => 2.5, 'horizon' => 20];
eq($dm->signals($C, $dm->params($alt)) === $sm->signals($C, $sm->params($alt)), true, 'equality also with other parameters (10/30, ATR 7)');
$ad = $dm->analyze($C, $P); $as = $sm->analyze($C, $P);
eq([$ad['bias'], $ad['levels'], $ad['as_of'], $ad['horizon_bars'], $ad['signal_ago']], [$as['bias'], $as['levels'], $as['as_of'], $as['horizon_bars'], $as['signal_ago']], 'analyze: the same bias, levels, as_of and horizon as sample-ma-cross');
eq(array_column($ad['conditions'], 'id'), ['l-1', 's-1'], 'conditions: one per rule, with ids by side');
eq([$dm->validate($dm->params(['fast' => 50, 'slow' => 50])), $dm->validate($P)], ['הממוצע הקצר חייב להיות קצר מהארוך', null], 'validate from the definition');

// ── הבטחות המנוע על שיטה מוגדרת: בלי הצצה, דטרמיניזם, מצטבר = מלא ──
$rsiDef = [
    'id' => 'rsi-levels', 'name' => 'RSI עם רמות', 'source' => 'בדיקה', 'version' => 1,
    'markets' => ['crypto'], 'timeframes' => ['h1', 'd1'],
    'params' => [
        'len' => ['label' => 'RSI', 'default' => 14, 'min' => 2, 'max' => 50, 'int' => true],
        'lo' => ['label' => 'רמה תחתונה', 'default' => 30, 'min' => 5, 'max' => 50],
        'hi' => ['label' => 'רמה עליונה', 'default' => 70, 'min' => 50, 'max' => 95],
    ],
    'indicators' => [
        ['id' => 'rsi', 'type' => 'rsi', 'source' => 'close', 'length' => '$len'],
        ['id' => 'ema50', 'type' => 'ema', 'source' => 'close', 'length' => 50],
        ['id' => 'atr', 'type' => 'atr', 'length' => 14],
    ],
    'rules' => [
        'long' => ['all' => ['rsi crosses_above 50', 'close > ema50']],
        'short' => ['any' => ['rsi crosses_below $hi', 'close < ema50 - 3 * atr and rsi < 50']],
    ],
    'levels' => ['stop' => 'entry - dir * 2 * atr', 'target' => null],
    'horizon_bars' => 30,
];
$rm = new TsDefinedMethod($rsiDef);
$RP = $rm->params();
// האינדיקטורים בזרימה = המערכים של lib/indicators.php
$cl = array_column($C, 'c');
$rsiA = ts_rsi($cl, 14); $emaA = ts_ema($cl, 50); $atrA = ts_atr(array_column($C, 'h'), array_column($C, 'l'), $cl, 14);
$s = $rm->init($RP); $bad = 0;
foreach ($C as $i => $c) {
    [$s] = $rm->step($s, $c, $RP);
    foreach ([[$s['vars']['rsi'], $rsiA[$i]], [$s['vars']['ema50'], $emaA[$i]], [$s['vars']['atr'], $atrA[$i]]] as [$g, $w]) {
        if (!($g === $w || ($g !== null && $w !== null && abs($g - $w) <= 1e-9 * max(1, abs($w))))) $bad++;
    }
}
eq($bad, 0, 'streaming RSI / EMA / ATR inside the adapter = lib/indicators.php (1e-9), on every bar');
$sigR = $rm->signals($C, $RP);
eq(count($sigR) > 20 && count(array_unique(array_column($sigR, 'side'))) === 2, true, 'the RSI definition gives long and short signals on the fixed set (' . count($sigR) . ')');
eq(array_unique(array_map(fn($x) => $x['target'], $sigR)), [null], 'target: null → no target (exit at the horizon, 6.2(ו))');
eq(json_encode($rm->signals($C, $RP)), json_encode($sigR), 'determinism: the same signals twice');
foreach ([[$dm, $P, 'ma-cross-def'], [$rm, $RP, 'rsi-levels']] as [$mm, $pp, $name]) {
    $all = $mm->signals($C, $pp);
    $byT = array_column($all, null, 't');
    $ts = array_column($C, 't');
    $peek = 0;
    foreach (array_merge(array_slice(array_column($all, 't'), 0, 20), [$ts[60], $ts[2345], $ts[4999]]) as $t) {
        $i = array_search($t, $ts, true);
        $part = $mm->signals(array_slice($C, 0, $i + 1), $pp);
        $lp = $part && end($part)['t'] === $t ? end($part) : null;
        if (($byT[$t] ?? null) != $lp) $peek++;
        if ($mm->analyze(array_slice($C, 0, $i + 1), $pp)['as_of'] !== $t) $peek++;
    }
    eq($peek, 0, "$name: no look-ahead — the signal at i is the same on [0..i] and on the full set");
    $cost = ['fee' => 0.001, 'slip' => 0.0005];
    $full = ts_bt_run($mm, $pp, $C, $cost);
    eq(json_encode(ts_bt_run($mm, $pp, $C, $cost)), json_encode($full), "$name: determinism — identical engine state twice");
    foreach ([70, 1999, 3500] as $k) {
        $half = json_decode(json_encode(ts_bt_run($mm, $pp, array_slice($C, 0, $k), $cost)), true);
        eq(json_encode(ts_bt_run($mm, $pp, $C, $cost, $half)), json_encode($full), "$name: incremental (split at $k, through JSON) = full");
    }
}

// ── הגדרות לא תקינות — נדחות ──
$with = fn(array $patch) => rejects(fn() => new TsDefinedMethod(array_replace_recursive($def, $patch)));
$defCases = [
    'bad id' => [['id' => 'Bad Id!'], 'מזהה'],
    'unknown top-level key' => [['code' => 'x'], 'שדה לא מוכר'],
    'unknown indicator type' => [['indicators' => [0 => ['type' => 'shell']]], 'סוג לא מוכר'],
    'indicator length out of range' => [['indicators' => [0 => ['length' => 100000]]], 'אורך'],
    'indicator length from a float param' => [['indicators' => [0 => ['length' => '$stop_atr']]], 'אורך'],
    'reserved indicator id' => [['indicators' => [0 => ['id' => 'entry']]], 'מזהה אינדיקטור'],
    'duplicate indicator id' => [['indicators' => [1 => ['id' => 'ma_fast']]], 'כפול'],
    'rule with a function call' => [['rules' => ['short' => ['all' => ['exec(close) > 1']]]], 'קריאה לפונקציה'],
    'rule with an unknown op' => [['rules' => ['long' => ['all' => [0 => ['op' => 'eval']]]]], 'פעולה לא מוכרת'],
    'entry in a rule (only in levels)' => [['rules' => ['short' => ['all' => ['close > entry']]]], 'שם לא מוכר'],
    'level that is a condition' => [['levels' => ['stop' => 'close > 1']], 'נדרש מספר'],
    'horizon out of range' => [['horizon_bars' => 100000], 'אופק'],
    'param default out of range' => [['params' => ['fast' => ['default' => 1000]]], 'מחוץ לטווח'],
    'market not on the list' => [['markets' => ['moon']], 'markets'],
    'validate with a name (params only)' => [['validate' => [0 => ['expr' => 'close > 1']]], 'שם לא מוכר'],
];
foreach ($defCases as $what => [$patch, $msg]) {
    $e = $with($patch);
    eq($e !== null && str_contains($e, $msg), true, "definition rejected: $what" . ($e === null ? ' (accepted!)' : " — $e"));
}
$big = $def; $big['rules']['long']['all'] = array_fill(0, 11, 'close > 1');
eq(str_contains((string)rejects(fn() => new TsDefinedMethod($big)), 'עד 10'), true, 'definition rejected: more than 10 conditions on a side');
$huge = $def; $huge['display'] = ['x' => str_repeat('a', 30000)];
eq(str_contains((string)rejects(fn() => new TsDefinedMethod($huge)), 'גדולה מדי'), true, 'definition rejected: over the size cap');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
