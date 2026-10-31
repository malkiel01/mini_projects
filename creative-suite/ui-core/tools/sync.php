<?php
/**
 * ui-core — ערכת הממשק המשותפת של חבילת היצירה. סנכרון. המקור: creative-suite/ui-core/src/<kind>/; היעדים: apps.json.
 *
 *   php creative-suite/ui-core/tools/sync.php            מעתיק לכל האפליקציות
 *   php creative-suite/ui-core/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה — נכשל
 *
 * apps.json: { "<תיקיית האפליקציה>": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" } } — כל סוג רשות.
 * הסוגים: css, js (ובהמשך fonts) — כל אחד הוא תיקייה ב-src/. תיקיית היעד כולה של ui-core: הסנכרון מעתיק אליה את
 * src/<kind>, ומוחק שם קבצים שאינם במקור. אפליקציה נכנסת רק בהסכמת הצ'אט שלה.
 * תיקון ב-ui-core? עורכים ב-src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

const UI_CORE_KINDS = ['css', 'js', 'fonts'];

$core = dirname(__DIR__);
$root = dirname($core, 2);
$check = in_array('--check', $argv, true);
$bad = 0;

$apps = json_decode((string)file_get_contents("$core/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "creative-suite/ui-core/apps.json פגום\n"); exit(1); }

function ui_core_tree(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && $f->getFilename() !== '.gitkeep') $out[substr($f->getPathname(), strlen($dir) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

foreach ($apps as $app => $map) {
    if (!is_dir("$root/$app")) { fwrite(STDERR, "✗ $app: אין תיקייה כזו\n"); $bad++; continue; }
    if (!is_array($map)) { fwrite(STDERR, "✗ $app: צריך אובייקט {\"css\": …, \"js\": …}\n"); $bad++; continue; }
    foreach (array_diff(array_keys($map), UI_CORE_KINDS) as $k) { fwrite(STDERR, "✗ $app: סוג לא מוכר \"$k\" (" . implode(', ', UI_CORE_KINDS) . ")\n"); $bad++; }
    foreach (UI_CORE_KINDS as $kind) {
        if (empty($map[$kind])) continue;
        $target = trim((string)$map[$kind], '/');
        if ($target === '' || str_contains($target, '..')) { fwrite(STDERR, "✗ $app: יעד לא חוקי \"$target\"\n"); $bad++; continue; }
        $from = ui_core_tree("$core/src/$kind");
        $toDir = "$root/$app/$target";
        $to = ui_core_tree($toDir);
        foreach ($from as $rel => $src) {
            $dst = "$toDir/$rel";
            if (isset($to[$rel]) && hash_file('sha256', $dst) === hash_file('sha256', $src)) continue;
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel שונה מ-ui-core/src/$kind/$rel — עורכים במקור ומריצים sync\n"); $bad++; continue; }
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);
            echo "→ $app/$target/$rel\n";
        }
        foreach (array_diff_key($to, $from) as $rel => $path) {
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel אינו במקור — התיקייה הזו כולה של ui-core\n"); $bad++; continue; }
            unlink($path);
            echo "✗ נמחק $app/$target/$rel\n";
        }
    }
}
if ($bad) exit(1);
echo $check ? "ui-core: כל העותקים זהים למקור (" . count($apps) . " אפליקציות)\n" : "ui-core: מסונכרן\n";
