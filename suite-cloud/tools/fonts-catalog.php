<?php
/**
 * בונה את fonts/builtin/catalog.json מהקבצים שבתיקייה: php suite-cloud/tools/fonts-catalog.php
 * --check — רק בודק שהקטלוג תואם לקבצים (הבדיקות מריצות אותו).
 * גופן מובנה חדש: קובץ + OFL-<משפחה בלי רווחים>.txt בתיקייה, ואז להריץ את הכלי.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DATA_DIR', sys_get_temp_dir() . '/fonts-catalog-' . getmypid());
require_once __DIR__ . '/../lib/fonts.php';

$cat = fontBuildCatalog();
$json = json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
$file = FONT_BUILTIN_DIR . '/catalog.json';
$missing = array_keys(array_filter($cat, fn($c) => $c['license'] === null));
if ($missing) { fwrite(STDERR, "חסר רישיון OFL: " . implode(', ', $missing) . "\n"); exit(1); }
if (in_array('--check', $argv, true)) {
    if ((string) @file_get_contents($file) !== $json) { fwrite(STDERR, "fonts: הקטלוג לא מעודכן — php suite-cloud/tools/fonts-catalog.php\n"); exit(1); }
    echo "fonts: הקטלוג מעודכן (" . count($cat) . " גופנים)\n";
    exit(0);
}
file_put_contents($file, $json);
echo "fonts: " . count($cat) . " גופנים → fonts/builtin/catalog.json\n";
