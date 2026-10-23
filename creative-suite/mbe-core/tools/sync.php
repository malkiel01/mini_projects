<?php
/**
 * ליבת ‎.mbe — בנייה וסנכרון. המקור: creative-suite/mbe-core/src/ (+ schema/); היעדים: apps.json.
 *
 *   php creative-suite/mbe-core/tools/sync.php            בונה את schemas.js/schemas.php ומעתיק לכל האפליקציות
 *   php creative-suite/mbe-core/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה או סכמה שלא נבנתה — נכשל
 *
 * apps.json: { "<תיקיית האפליקציה>": { "js": "assets/js/mbe", "php": "lib/mbe" } } — תיקיות יעד שכולן של הליבה:
 * הסנכרון מעתיק את src/js או src/php לתוכן, ומוחק שם קבצים שאינם במקור. אפליקציה נכנסת רק בהסכמת הצ'אט שלה.
 * תיקון בליבה? עורכים ב-src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

$core = dirname(__DIR__);
$root = dirname($core, 2);
$check = in_array('--check', $argv, true);
$bad = 0;

// 1. הסכמות — מקור אחד (schema/*.json), נטען בשתי השפות בלי fetch ובלי נתיב יחסי לקובץ.
$schemas = [];
foreach (['manifest', 'document'] as $name) {
    $text = (string)file_get_contents("$core/schema/$name-1.json");
    if (!is_array(json_decode($text, true))) { fwrite(STDERR, "✗ schema/$name-1.json אינו JSON תקין\n"); exit(1); }
    $schemas[$name] = json_encode(json_decode($text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
$head = "נוצר אוטומטית מ-creative-suite/mbe-core/schema/ — לא לערוך. php creative-suite/mbe-core/tools/sync.php";
$js = "// $head\nexport const SCHEMAS = {\n";
$php = "<?php\n// $head\nreturn [\n";
foreach ($schemas as $name => $json) {
    $js .= "    $name: $json,\n";
    $php .= "    '$name' => json_decode(" . var_export($json, true) . ", true),\n";
}
$js .= "};\n";
$php .= "];\n";
foreach (["$core/src/js/schemas.js" => $js, "$core/src/php/schemas.php" => $php] as $file => $want) {
    if (is_file($file) && file_get_contents($file) === $want) continue;
    $rel = substr($file, strlen($root) + 1);
    if ($check) { fwrite(STDERR, "✗ $rel לא תואם ל-schema/ — מריצים: php creative-suite/mbe-core/tools/sync.php\n"); $bad++; continue; }
    file_put_contents($file, $want);
    echo "→ $rel\n";
}

// 2. העותקים באפליקציות
$apps = json_decode((string)file_get_contents("$core/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "creative-suite/mbe-core/apps.json פגום\n"); exit(1); }

function mbe_tree(string $dir): array {
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
        $target = trim((string)$map[$kind], '/');
        if ($target === '' || str_contains($target, '..')) { fwrite(STDERR, "✗ $app: יעד לא חוקי \"$target\"\n"); $bad++; continue; }
        $from = mbe_tree("$core/src/$kind");
        $toDir = "$root/$app/$target";
        $to = mbe_tree($toDir);
        foreach ($from as $rel => $src) {
            $dst = "$toDir/$rel";
            if (isset($to[$rel]) && hash_file('sha256', $dst) === hash_file('sha256', $src)) continue;
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel שונה מ-mbe-core/src/$kind/$rel — עורכים במקור ומריצים sync\n"); $bad++; continue; }
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
            copy($src, $dst);
            echo "→ $app/$target/$rel\n";
        }
        foreach (array_diff_key($to, $from) as $rel => $path) {
            if ($check) { fwrite(STDERR, "✗ $app/$target/$rel אינו במקור — התיקייה הזו כולה של mbe-core\n"); $bad++; continue; }
            unlink($path);
            echo "✗ נמחק $app/$target/$rel\n";
        }
    }
}
if ($bad) exit(1);
echo $check ? "mbe-core: כל העותקים זהים למקור (" . count($apps) . " אפליקציות)\n" : "mbe-core: מסונכרן\n";
