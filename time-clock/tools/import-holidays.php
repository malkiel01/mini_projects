<?php
/**
 * הזנת לוח חגים של דת (מוסלמי / נוצרי / דרוזי) מקובץ CSV, לקובץ lib/holidays/<דת>.json.
 *
 * הרצה:
 *   php time-clock/tools/import-holidays.php <דת> <קובץ.csv> --source="חוזר נש״מ 2027" --verified=2026-11-04 [--replace-year=2027] [--dry-run]
 *
 * ה-CSV: שורה לכל יום, "תאריך,שם" (YYYY-MM-DD), בקידוד UTF-8; שורה ראשונה "date,name" מותרת.
 * - ימים שכבר בקובץ באותו תאריך מוחלפים. --replace-year מוחק קודם את כל ימי אותה שנה (לתיקון שנה שהוזנה לא נכון).
 * - --source ו---verified חובה: בלי מקור ותאריך אימות לא מכניסים תאריכים ללוח משפטי.
 * - הלוח היהודי מחושב (tools/gen-holidays.php) ולא מוזן.
 * - התיקייה אפשר להחליף עם TC_HOLIDAYS_DIR (בדיקות).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const ALLOWED = ['muslim', 'christian', 'druze'];

function usage(string $msg = ''): never {
    if ($msg !== '') fwrite(STDERR, "שגיאה: $msg\n\n");
    fwrite(STDERR, "שימוש: php time-clock/tools/import-holidays.php <" . implode('|', ALLOWED) . "> <קובץ.csv> --source=\"...\" --verified=YYYY-MM-DD [--replace-year=YYYY] [--dry-run]\n");
    exit(2);
}

$args = array_slice($argv, 1);
$opts = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) $opts[$m[1]] = $m[2] ?? true; else $pos[] = $a;
}
if (count($pos) !== 2) usage();
[$religion, $csvFile] = $pos;
if (!in_array($religion, ALLOWED, true)) usage($religion === 'jewish' ? 'הלוח היהודי מחושב ב-gen-holidays.php' : 'דת לא מוכרת');
$source = is_string($opts['source'] ?? null) ? trim($opts['source']) : '';
$verified = is_string($opts['verified'] ?? null) ? $opts['verified'] : '';
if ($source === '') usage('חסר --source');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $verified) || !checkdate((int) substr($verified, 5, 2), (int) substr($verified, 8, 2), (int) substr($verified, 0, 4))) usage('--verified חייב להיות תאריך YYYY-MM-DD');
$replaceYear = $opts['replace-year'] ?? null;
if ($replaceYear !== null && !preg_match('/^\d{4}$/', (string) $replaceYear)) usage('--replace-year: שנה בת 4 ספרות');
if (!is_file($csvFile)) usage('הקובץ לא נמצא: ' . $csvFile);

$new = [];
$errors = [];
$fh = fopen($csvFile, 'r');
$line = 0;
while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $line++;
    if ($row === [null] || ($line === 1 && isset($row[0]) && ltrim((string) $row[0], "\xEF\xBB\xBF") === 'date')) continue;
    $date = trim(ltrim((string) ($row[0] ?? ''), "\xEF\xBB\xBF"));
    $name = trim((string) ($row[1] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) { $errors[] = "שורה $line: תאריך לא תקין '$date'"; continue; }
    if ($name === '' || mb_strlen($name) > 60) { $errors[] = "שורה $line: שם חסר או ארוך מ-60 תווים"; continue; }
    if (isset($new[$date])) { $errors[] = "שורה $line: התאריך $date מופיע פעמיים"; continue; }
    $new[$date] = $name;
}
fclose($fh);
if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\nלא נכתב כלום.\n"); exit(1); }
if (!$new) usage('הקובץ ריק');

$dir = getenv('TC_HOLIDAYS_DIR') ?: __DIR__ . '/../lib/holidays';
$file = $dir . '/' . $religion . '.json';
$cur = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
$days = is_array($cur['days'] ?? null) ? $cur['days'] : [];
if ($replaceYear !== null) $days = array_filter($days, fn($d) => !str_starts_with($d, $replaceYear . '-'), ARRAY_FILTER_USE_KEY);
$prev = is_string($cur['source'] ?? null) ? $cur['source'] : '';
if ($prev !== '' && !str_contains($prev, $source)) $source = $prev . ' | ' . $source;   // כמה פרסומים (שנים) — כל המקורות נשמרים
$replaced = count(array_intersect_key($days, $new));
$days = array_merge($days, $new);
ksort($days);

$out = json_encode(['religion' => $religion, 'source' => $source, 'verified_at' => $verified, 'days' => $days],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
$summary = sprintf("%s: %d ימים חדשים (%d הוחלפו), בסך הכול %d, %s עד %s", $religion, count($new), $replaced, count($days), array_key_first($days), array_key_last($days));
if (isset($opts['dry-run'])) { echo "[dry-run] $summary\n"; exit(0); }
$tmp = $file . '.tmp';
if (file_put_contents($tmp, $out) === false || !rename($tmp, $file)) { fwrite(STDERR, "כתיבה נכשלה\n"); exit(1); }
echo "$summary\nנכתב: $file\n";
