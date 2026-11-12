<?php
/**
 * L1b — פלט הבנייה של הפרסום (build_version) לעמוד HTML עצמאי:   php l1-build.php <תיקיית הנתונים> <קובץ הפלט>
 * ה-HTML וה-CSS כמו שהם נשלחים לאתר, עם ה-CSS של ערכת הנושא (preview_css). רק אחרי f3-seed.php על אותה תיקייה.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 3 || !is_dir($argv[1])) exit(1);
define('KOL_DATA_DIR', $argv[1]);
$root = dirname(__DIR__, 2);
if (realpath(KOL_DATA_DIR) === realpath($root . '/data')) exit("לא על data/\n");
foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'publish'] as $lib) require_once "$root/lib/$lib.php";
$b = build_version(page_normalize(json_decode((string)draft_row()['json'], true)));
file_put_contents($argv[2], "<!doctype html>\n<html lang=\"he\" dir=\"rtl\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>build</title>\n"
    . "<style>body{margin:0}\n" . setting('preview_css', '') . "\n" . $b['css'] . "</style></head><body>\n" . $b['html'] . "\n</body></html>\n");
echo strlen($b['html']), "\n";
