<?php
/**
 * פתיחת גיבוי שבועי (‏*.mbepay) שהגיע במייל: ‏php payments/tools/decrypt-backup.php <קובץ> <פלט.sqlite>
 * מבקש את הסיסמה שבחרת בהגדרות הגיבוי. לא צריך את השרת ולא את המפתח שלו — רק PHP 8 עם sodium.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $in, $out] = $argv + [null, null, null];
if (!$in || !$out) { fwrite(STDERR, "שימוש: php decrypt-backup.php <קובץ.mbepay> <פלט.sqlite>\n"); exit(2); }
if (file_exists($out)) { fwrite(STDERR, "הקובץ $out כבר קיים — לא דורסים.\n"); exit(2); }

// הפונקציה בלבד, בלי לטעון את התוסף (אין צורך במסד או במפתחות של השרת)
require_once __DIR__ . '/../lib/offsite_decrypt.php';

$pass = getenv('BACKUP_PASSPHRASE');
if ($pass === false || $pass === '') {
    fwrite(STDOUT, 'סיסמה: ');
    if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $pass = rtrim((string)fgets(STDIN), "\r\n");
    if (DIRECTORY_SEPARATOR === '/') system('stty echo');
    fwrite(STDOUT, "\n");
}
$plain = offsite_decrypt((string)file_get_contents($in), $pass);
if ($plain === null) { fwrite(STDERR, "סיסמה שגויה או קובץ פגום.\n"); exit(1); }
file_put_contents($out, $plain);
echo "נפתח: $out (" . round(strlen($plain) / 1024) . " ק\"ב)\n";
