<?php
/**
 * מניפסט בדיקה ל-getInstalledRelatedApps (index.html כאן): ?p=<חבילה>,<חבילה> — רק החבילות האלה
 * ב-related_applications. ?p=all — כל הרשימה של מניפסט הפלטפורמה. בודק אם האורך של הרשימה משנה.
 */
declare(strict_types=1);

$platform = json_decode((string)file_get_contents(__DIR__ . '/../../manifest.webmanifest'), true) ?: [];
$all = array_values(array_filter($platform['related_applications'] ?? [], fn($a) => ($a['platform'] ?? '') === 'play'));
$want = (string)($_GET['p'] ?? 'all');
$list = $want === 'all' ? $all : array_values(array_filter(array_map(
    fn($id) => preg_match('/^[a-z][a-z0-9_.]{2,100}$/', $id) ? ['platform' => 'play', 'id' => $id] : null,
    explode(',', $want)
)));

header('Content-Type: application/manifest+json');
header('Cache-Control: no-store');
echo json_encode([
    'name' => 'בדיקת אפליקציות מותקנות',
    'short_name' => 'בדיקה',
    'start_url' => './',
    'scope' => './',
    'display' => 'browser',
    'related_applications' => $list,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
