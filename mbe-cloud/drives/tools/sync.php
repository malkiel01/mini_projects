<?php
/**
 * מודול הכוננים — סנכרון. המקור: suite-cloud/drives/src/{js,php}; היעדים: suite-cloud/drives/apps.json.
 *
 *   php suite-cloud/drives/tools/sync.php            מעתיק לכל האפליקציות
 *   php suite-cloud/drives/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה — נכשל
 *
 * apps.json: { "<תיקיית האפליקציה>": { "js": "assets/js/drives", "php": "lib/drives" } } — תיקיות יעד שכולן של המודול:
 * הסנכרון מעתיק לתוכן ומוחק שם קבצים שאינם במקור. אפליקציה נכנסת רק בהסכמת הצ'אט שלה.
 * תיקון? עורכים ב-src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

$mod = dirname(__DIR__);
$root = dirname($mod, 2);
$check = in_array('--check', $argv, true);
$bad = 0;

$apps = json_decode((string) file_get_contents("$mod/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "suite-cloud/drives/apps.json פגום\n"); exit(1); }

function drives_tree(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($dir) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

foreach ($apps as $app => $map) {
    if (!is_dir("$root/$app")) { fwrite(STDERR, "✗ $app: אין תיקייה כזו\n"); $bad++; continue; }
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
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel שונה מ-suite-cloud/drives/src/$kind/$rel — עורכים במקור ומריצים sync\n"); $bad++; continue; }
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);
            echo "→ $app/$target/$rel\n";
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
