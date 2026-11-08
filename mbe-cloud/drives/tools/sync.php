<?php
/**
 * מודול הכוננים — סנכרון. המקור: mbe-cloud/drives/src/{js,php}; היעדים: mbe-cloud/drives/apps.json.
 *
 *   php mbe-cloud/drives/tools/sync.php            מעתיק לכל האפליקציות
 *   php mbe-cloud/drives/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה — נכשל
 *
 * apps.json: { "<תיקיית האפליקציה>": { "js": "assets/js/drives", "php": "lib/drives", "layers": ["files", "libs", "ai"] } }
 * — תיקיות יעד שכולן של המודול. layers (לא חובה; ברירת המחדל — שלושתן): השכבות של האפליקציה, מתג לכל אחת
 * (spec-0-vision.md 7.3). ה-sync כותב אותן ל-<php>/layers.json, שהמודול קורא — כך האפליקציה לא תלויה בקובץ מחוץ לתיקייה שלה.
 * הסנכרון מעתיק לתוכן ומוחק שם קבצים שאינם במקור. אפליקציה נכנסת רק בהסכמת הצ'אט שלה.
 * תיקון? עורכים ב-src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

$mod = dirname(__DIR__);
$root = dirname($mod, 2);
$check = in_array('--check', $argv, true);
$bad = 0;

$apps = json_decode((string) file_get_contents("$mod/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "mbe-cloud/drives/apps.json פגום\n"); exit(1); }

function drives_tree(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($dir) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

const DRIVES_LAYERS = ['files', 'libs', 'appdata', 'ai'];
const DRIVES_FILES_SCOPES = ['files', 'files.readonly', 'files.app'];   // שלב 3 (spec-3-files-scopes.md). חדשה — files.app
const DRIVES_DEFAULT = ['files', 'libs', 'ai'];             // בלי layers — כמו לפני שלב 4

foreach ($apps as $app => $map) {
    if (!is_dir("$root/$app")) { fwrite(STDERR, "✗ $app: אין תיקייה כזו\n"); $bad++; continue; }
    $layers = $map['layers'] ?? DRIVES_DEFAULT;
    $fscope = $map['files_scope'] ?? 'files';
    if (!is_array($layers) || !$layers || array_diff($layers, DRIVES_LAYERS) || !in_array($fscope, DRIVES_FILES_SCOPES, true)) {
        fwrite(STDERR, "✗ $app: layers חייב להיות מתוך " . implode(', ', DRIVES_LAYERS) . ", ו-files_scope מתוך " . implode(', ', DRIVES_FILES_SCOPES) . "\n"); $bad++; continue;
    }
    $layersJson = json_encode(['layers' => array_values($layers), 'files_scope' => $fscope], JSON_UNESCAPED_SLASHES) . "\n";
    foreach (['js', 'php'] as $kind) {
        if (empty($map[$kind])) continue;
        $target = trim((string) $map[$kind], '/');
        if ($target === '' || str_contains($target, '..')) { fwrite(STDERR, "✗ $app: יעד לא חוקי \"$target\"\n"); $bad++; continue; }
        $from = drives_tree("$mod/src/$kind");
        $toDir = "$root/$app/$target";
        $to = drives_tree($toDir);
        foreach ($from as $rel => $src) {
            $dst = "$toDir/$rel";
            if (isset($to[$rel]) && hash_file('sha256', $dst) === hash_file('sha256', $src)) continue;
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel שונה מ-mbe-cloud/drives/src/$kind/$rel — עורכים במקור ומריצים sync\n"); $bad++; continue; }
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);
            echo "→ $app/$target/$rel\n";
        }
        if ($kind === 'php') {   // נוצר מ-apps.json — לא מהמקור
            $dst = "$toDir/layers.json";
            unset($to['layers.json']);
            if (!is_file($dst) || file_get_contents($dst) !== $layersJson) {
                if ($check) { fwrite(STDERR, "✗ $app/$target/layers.json לא תואם את apps.json — מריצים sync\n"); $bad++; }
                else { file_put_contents($dst, $layersJson); echo "→ $app/$target/layers.json\n"; }
            }
        }
        foreach (array_diff_key($to, $from) as $rel => $path) {
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel אינו במקור — התיקייה הזו כולה של מודול הכוננים\n"); $bad++; continue; }
            unlink($path);
            echo "✗ נמחק $app/$target/$rel\n";
        }
    }
}
if ($bad) exit(1);
echo $check ? "drives: כל העותקים זהים למקור (" . count($apps) . " אפליקציות)\n" : "drives: מסונכרן\n";
