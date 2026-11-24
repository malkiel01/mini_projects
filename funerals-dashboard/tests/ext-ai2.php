<?php
// בדיקות 05 בלבד: שגיאות של extDo() הן ExtError עם סטטוס (06 מחזיר אותן למודל). שורת פקודה בלבד.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
$pdo = db();
$m = extMember($pdo, 9, 1) ?? exit(1);
extSetContext($m);
foreach ([['case_create', ['name' => 'x'], 403], ['nope', [], 404], ['find', ['q' => 'x'], 400]] as [$a, $in, $want]) {
    try { extDo($pdo, $a, $in, 'ai'); fwrite(STDERR, "FAIL (05/ai): $a לא נכשל\n"); exit(1); }
    catch (ExtError $e) { if ($e->getCode() !== $want) { fwrite(STDERR, "FAIL (05/ai): $a → {$e->getCode()} במקום $want\n"); exit(1); } }
}
