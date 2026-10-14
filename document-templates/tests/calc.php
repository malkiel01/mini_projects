<?php
/**
 * בדיקות שלב 11: מנוע החישוב והתנאים (PHP). אותם ווקטורים נבדקים גם ב-JS:
 *   php document-templates/tests/calc.php
 *   node document-templates/tests/calc.test.js
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/calc.php';

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}

$V = json_decode(file_get_contents(__DIR__ . '/calc-vectors.json'), true);
$lookup = calc_lookup($V['fields'], $V['values']);
foreach ($V['expr'] as [$e, $want]) {
    try { $got = calc_str(calc_eval($e, $lookup)); } catch (CalcError $x) { $got = 'ERR ' . $x->getMessage(); }
    check("$e = $want" . ($got === $want ? '' : " (קיבלנו $got)"), $got === $want);
}
foreach ($V['errors'] as $e) {
    try { calc_eval($e, $lookup); check("שגיאה: $e", false); }
    catch (CalcError $x) { check("שגיאה: $e ({$x->getMessage()})", true); }
}
$items = array_values(array_filter($V['fields'], fn($f) => $f['key'] === 'items'))[0];
$rows = table_compute($items, $V['fields'], $V['values'], table_rows($V['values']['items']));
check('עמודה מחושבת בטבלה', $rows === $V['table']);
$vals = $V['values'];
$vals['items'] = json_encode($rows, JSON_UNESCAPED_UNICODE);
$got = calc_all(['fields' => $V['fields']], $vals);
check('כל השדות המחושבים, כולל תלויים זה בזה: ' . json_encode($got, JSON_UNESCAPED_UNICODE), $got === $V['all']);
check('חישוב חוזר — אין שינוי', calc_all(['fields' => $V['fields']], array_merge($vals, $got)) === []);
foreach ($V['display'] as [$v, $fmt, $want]) check("תצוגה $v ($fmt) → $want", calc_display($v, $fmt) === $want);
foreach ($V['visible']['cases'] as $i => [$vals, $want]) {
    $got = array_column(visible_fields(['fields' => $V['visible']['fields']], $vals), 'key');
    check("תנאי הצגה $i: " . implode(',', $got), $got === $want);
}
check('בדיקת ביטוי: תקין', calc_check('sum(items.line) * 2', $V['fields']) === null);
check('בדיקת ביטוי: מזהה לא מוכר', calc_check('nope + 1', $V['fields']) === 'לא מוכר: nope');
check('בדיקת ביטוי בתוך טבלה: עמודות השורה', calc_check('qty * מחיר', $V['fields'], $items) === null);

echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
