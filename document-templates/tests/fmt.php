<?php
/**
 * בדיקות: מבנים מותאמים (lib/fmt.php) — אותם מקרים כמו tests/fmt.test.js,
 * והלוח העברי מול jdtojewish של PHP על 250 שנה.
 * הרצה: php document-templates/tests/fmt.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/fmt.php';

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}
$V = json_decode((string)file_get_contents(__DIR__ . '/fmt-vectors.json'), true);
foreach ($V['date'] as [$p, $v, $want]) check("תאריך $p ← $v = $want", fmt_date($p, $v) === $want);
foreach ($V['number'] as [$p, $v, $want]) check("מספר $p ← $v = $want", fmt_number($p, $v) === $want);
foreach ($V['text'] as [$p, $v, $want]) check("טקסט $p ← $v = $want", fmt_text_rich($p, $v)[0] === $want);
foreach ($V['color'] as [$k, $p, $v, $want]) check("צבע $p ← $v = " . ($want ?? 'בלי'), ($k === 'text' ? fmt_text_rich($p, $v) : fmt_number_rich($p, $v))[1] === $want);

if (function_exists('jdtojewish')) {
    $bad = 0; $n = 0;
    for ($t = gmmktime(0, 0, 0, 1, 1, 1900); $t < gmmktime(0, 0, 0, 1, 1, 2150); $t += 86400 * 7) {
        $iso = gmdate('Y-m-d', $t);
        $h = heb_date($iso);
        [$pm, $pd, $py] = array_map('intval', explode('/', jdtojewish(gregoriantojd((int)gmdate('n', $t), (int)gmdate('j', $t), (int)gmdate('Y', $t)))));
        $jm = $pm <= 5 ? $pm + 6 : ($pm === 6 ? 12 : ($pm === 7 ? (heb_leap($py) ? 13 : 12) : $pm - 7));
        $n++;
        if ($h['y'] !== $py || $h['d'] !== $pd || $h['m'] !== $jm) $bad++;
    }
    check("לוח עברי זהה ל-jdtojewish ב-$n תאריכים (1900–2150)", $bad === 0);
}
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
