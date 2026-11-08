<?php
// זמני, קריאה בלבד — crontab של משתמש הפריסה (בדיקת פער השורות אחרי #1278). לא משנה כלום.
$tab = (string)shell_exec('crontab -l 2>&1');
$lines = preg_split('/\r?\n/', rtrim($tab, "\n"));
$count = [];
foreach ($lines as $l) { if (trim($l) !== '') $count[$l] = ($count[$l] ?? 0) + 1; }
echo json_encode([
    'nonempty' => count(array_filter($lines, fn($l) => trim($l) !== '')),
    'lines'    => $lines,
    'dups'     => array_filter($count, fn($n) => $n > 1),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
