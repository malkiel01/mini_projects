<?php
/**
 * עיצוב הטקסט המשותף (text-core) — סנכרון. המקור: creative-suite/text-core/src/js/; היעדים: apps.json.
 *
 *   php creative-suite/text-core/tools/sync.php            מעתיק לכל האפליקציות
 *   php creative-suite/text-core/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה — נכשל
 *
 * apps.json: { "<תיקיית האפליקציה>": { "js": "assets/js/text-core" } } — תיקיית יעד שכולה של המודול: הסנכרון
 * מעתיק את src/js לתוכה (כולל vendor/), ומוחק שם קבצים שאינם במקור. אפליקציה נכנסת בתור שלה (work-order.md §3).
 * תיקון במודול? עורכים ב-src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

$core = dirname(__DIR__);
$root = dirname($core, 2);
$check = in_array('--check', $argv, true);
$bad = 0;

$apps = json_decode((string)file_get_contents("$core/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "creative-suite/text-core/apps.json פגום\n"); exit(1); }

function text_core_tree(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($dir) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

$kinds = ['js' => text_core_tree("$core/src/js")];
foreach ($apps as $app => $map) {
    if (!is_dir("$root/$app")) { fwrite(STDERR, "✗ $app: אין תיקייה כזו\n"); $bad++; continue; }
    if (!array_intersect_key($map, $kinds)) { fwrite(STDERR, "✗ $app: אין js\n"); $bad++; continue; }
    foreach ($kinds as $kind => $from) {
        if (!isset($map[$kind])) continue;
        $target = trim((string)$map[$kind], '/');
        if ($target === '' || str_contains($target, '..')) { fwrite(STDERR, "✗ $app: יעד לא חוקי \"$target\"\n"); $bad++; continue; }
        $toDir = "$root/$app/$target";
        $to = text_core_tree($toDir);
        foreach ($from as $rel => $src) {
            $dst = "$toDir/$rel";
            if (isset($to[$rel]) && hash_file('sha256', $dst) === hash_file('sha256', $src)) continue;
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel שונה מ-text-core/src/$kind/$rel — עורכים במקור ומריצים sync\n"); $bad++; continue; }
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);
            echo "→ $app/$target/$rel\n";
        }
        foreach (array_diff_key($to, $from) as $rel => $path) {
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel אינו במקור — התיקייה הזו כולה של text-core\n"); $bad++; continue; }
            unlink($path);
            echo "✗ נמחק $app/$target/$rel\n";
        }
    }
}
if ($bad) exit(1);
echo $check ? "text-core: כל העותקים זהים למקור (" . count($apps) . " אפליקציות)\n" : "text-core: מסונכרן\n";
