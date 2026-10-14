<?php
/**
 * DevLoop — סנכרון המודול לאפליקציות. המקור: devloop/src/; היעדים: devloop/apps.json.
 *
 *   php devloop/tools/sync.php            מעתיק לכל האפליקציות
 *   php devloop/tools/sync.php --check    רק בודק (שער בפריסה): עותק ששונה בתוך אפליקציה — נכשל
 *
 * תיקון במודול? עורכים ב-devloop/src, מריצים sync, ומעלים את המקור ואת העותקים באותו commit.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$check = in_array('--check', $argv, true);
$apps = json_decode((string)file_get_contents("$root/devloop/apps.json"), true);
if (!is_array($apps)) { fwrite(STDERR, "devloop/apps.json פגום\n"); exit(1); }
$src = ['php' => 'devloop.php', 'js' => 'devloop.js', 'css' => 'devloop.css'];
$bad = 0;
foreach ($apps as $app => $map) {
    if (!is_dir("$root/$app")) { fwrite(STDERR, "✗ $app: אין תיקייה כזו\n"); $bad++; continue; }
    foreach ($src as $kind => $file) {
        if (empty($map[$kind])) continue;
        $from = "$root/devloop/src/$file";
        $to = "$root/$app/{$map[$kind]}";
        $same = is_file($to) && hash_file('sha256', $to) === hash_file('sha256', $from);
        if ($same) continue;
        if ($check) {
            fwrite(STDERR, "✗ $app/{$map[$kind]} שונה מ-devloop/src/$file — עורכים במקור ומריצים: php devloop/tools/sync.php\n");
            $bad++;
            continue;
        }
        if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
        copy($from, $to);
        echo "→ $app/{$map[$kind]}\n";
    }
}
if ($bad) exit(1);
echo $check ? "DevLoop: כל העותקים זהים למקור (" . count($apps) . " אפליקציות)\n" : "DevLoop: מסונכרן\n";
