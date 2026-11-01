<?php
/**
 * מנוע ה-PDF המשותף (pdf-core) — סנכרון. המקור: creative-suite/pdf-core/src/php/; היעדים: apps.json.
 *
 *   php creative-suite/pdf-core/tools/sync.php            מעתיק לכל האפליקציות
 *   php creative-suite/pdf-core/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה — נכשל
 *
 * apps.json: { "<תיקיית האפליקציה>": { "php": "lib/pdf-core", "js": "assets/js/pdf-core" } } — כל אחד רשות (לפחות
 * אחד); תיקיית יעד שכולה של המנוע: הסנכרון מעתיק את src/php (או src/js) לתוכה, ומוחק שם קבצים שאינם במקור. אפליקציה נכנסת רק בהסכמת הצ'אט שלה.
 * תיקון במנוע? עורכים ב-src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

$core = dirname(__DIR__);
$root = dirname($core, 2);
$check = in_array('--check', $argv, true);
$bad = 0;

$apps = json_decode((string)file_get_contents("$core/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "creative-suite/pdf-core/apps.json פגום\n"); exit(1); }

function pdf_core_tree(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($dir) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

$kinds = ['php' => pdf_core_tree("$core/src/php"), 'js' => pdf_core_tree("$core/src/js")];
foreach ($apps as $app => $map) {
    if (!is_dir("$root/$app")) { fwrite(STDERR, "✗ $app: אין תיקייה כזו\n"); $bad++; continue; }
    if (!array_intersect_key($map, $kinds)) { fwrite(STDERR, "✗ $app: אין php ואין js\n"); $bad++; continue; }
    foreach ($kinds as $kind => $from) {
        if (!isset($map[$kind])) continue;
        $target = trim((string)$map[$kind], '/');
        if ($target === '' || str_contains($target, '..')) { fwrite(STDERR, "✗ $app: יעד לא חוקי \"$target\"\n"); $bad++; continue; }
        $toDir = "$root/$app/$target";
        $to = pdf_core_tree($toDir);
        foreach ($from as $rel => $src) {
            $dst = "$toDir/$rel";
            if (isset($to[$rel]) && hash_file('sha256', $dst) === hash_file('sha256', $src)) continue;
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel שונה מ-pdf-core/src/$kind/$rel — עורכים במקור ומריצים sync\n"); $bad++; continue; }
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);
            echo "→ $app/$target/$rel\n";
        }
        foreach (array_diff_key($to, $from) as $rel => $path) {
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel אינו במקור — התיקייה הזו כולה של pdf-core\n"); $bad++; continue; }
            unlink($path);
            echo "✗ נמחק $app/$target/$rel\n";
        }
    }
}
if ($bad) exit(1);
echo $check ? "pdf-core: כל העותקים זהים למקור (" . count($apps) . " אפליקציות)\n" : "pdf-core: מסונכרן\n";
