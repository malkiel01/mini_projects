<?php
/**
 * כל הבדיקות של האפליקציה: php appraisers/tests/run.php
 * בלי שרת ובלי רשת — SQLite בזיכרון. כל קובץ *.test.php רץ בתהליך משלו; קוד יציאה 1 אם משהו נכשל.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$bad = 0;
$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);
foreach ($files as $f) {
    echo basename($f, '.test.php') . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f), $code);
    if ($code !== 0) $bad++;
}
echo $bad ? "✗ $bad קובצי בדיקה נכשלו\n" : '✓ כל ' . count($files) . " קובצי הבדיקה עברו\n";
exit($bad ? 1 : 0);
