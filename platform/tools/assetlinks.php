<?php
/**
 * מאחד קובצי assetlinks.json לקובץ אחד — זה שיושב בשורש הדומיין (https://mbe-plus.com/.well-known/).
 *
 * אפליקציית אנדרואיד (TWA) נפתחת במסך מלא רק אם הדומיין מאשר אותה בקובץ הזה, והקובץ אחד לכל הדומיין —
 * רשומה לכל חבילה. כל אפליקציה מפרסמת את שלה (בתיקיית החנות, או ב-<app>/.well-known/assetlinks.json),
 * והפריסה מאחדת (deploy.yml, "Digital Asset Links").
 *
 *   php platform/tools/assetlinks.php <קובץ> [<קובץ> ...]   → JSON מאוחד ל-stdout
 *
 * הקובץ הראשון הוא הקיים בשרת (אם יש): שום רשומה ממנו לא נמחקת — גם כזו שהועלתה ידנית.
 * אותה חבילה בכמה קבצים → רשומה אחת, עם כל טביעות האצבע. קובץ חסר או פגום — מדלגים עליו.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;

$byPackage = [];   // package_name → ['relation' => [...], 'fingerprints' => [...]]
$other = [];       // רשומות שאינן android_app (למשל web) — נשמרות כמו שהן

foreach (array_slice($argv, 1) as $file) {
    $list = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($list)) {
        fwrite(STDERR, "מדלג: $file\n");
        continue;
    }
    foreach ($list as $entry) {
        $t = $entry['target'] ?? null;
        if (($t['namespace'] ?? '') !== 'android_app' || !is_string($t['package_name'] ?? null)) {
            if (is_array($entry) && !in_array($entry, $other, true)) $other[] = $entry;
            continue;
        }
        $p = $t['package_name'];
        $byPackage[$p] ??= ['relation' => [], 'fingerprints' => []];
        foreach ((array)($entry['relation'] ?? []) as $r) {
            if (is_string($r) && !in_array($r, $byPackage[$p]['relation'], true)) $byPackage[$p]['relation'][] = $r;
        }
        foreach ((array)($t['sha256_cert_fingerprints'] ?? []) as $f) {
            if (is_string($f) && preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/i', $f)) {
                $f = strtoupper($f);
                if (!in_array($f, $byPackage[$p]['fingerprints'], true)) $byPackage[$p]['fingerprints'][] = $f;
            }
        }
    }
}

ksort($byPackage);
$out = $other;
foreach ($byPackage as $p => $e) {
    if (!$e['fingerprints']) continue;   // בלי טביעת אצבע אין מה לאשר — אפליקציה שעוד לא נחתמה
    $out[] = [
        'relation' => $e['relation'] ?: ['delegate_permission/common.handle_all_urls'],
        'target'   => ['namespace' => 'android_app', 'package_name' => $p, 'sha256_cert_fingerprints' => $e['fingerprints']],
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
