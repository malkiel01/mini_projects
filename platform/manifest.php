<?php
/**
 * המניפסט של הכלים הדיגיטליים — manifest.webmanifest עם related_applications אישי (.htaccess מפנה לכאן).
 *
 * כרום בודק ב-getInstalledRelatedApps רק 3 חבילות (נמדד: platform/probe/related, האפיון "גרסאות וחשבונות"
 * סעיף 3.1). לכן הרשימה כאן: ה-webapp, ועוד 3 חבילות שהדף ביקש בעוגייה platform-related — האפליקציות שהכי
 * פחות ודאי שמותקנות (app.js, relatedRotate). בלי עוגייה — 3 הראשונות. כל השאר כמו בקובץ.
 * related_applications אינו חלק מעדכון ה-WebAPK — שינוי בו לא מתקין מחדש את האפליקציה המותקנת.
 */

declare(strict_types=1);

$m = json_decode((string)file_get_contents(__DIR__ . '/manifest.webmanifest'), true);
if (!is_array($m)) {
    http_response_code(500);
    exit;
}
$related = $m['related_applications'] ?? [];
$play = array_values(array_filter($related, fn($a) => ($a['platform'] ?? '') === 'play'));
$known = array_column($play, 'id');
$want = array_values(array_unique(array_intersect(explode(',', (string)($_COOKIE['platform-related'] ?? '')), $known)));
$pick = $want ? array_slice($want, 0, 3) : array_slice($known, 0, 3);
$m['related_applications'] = array_merge(
    array_values(array_filter($related, fn($a) => ($a['platform'] ?? '') !== 'play')),
    array_map(fn($id) => ['platform' => 'play', 'id' => $id], $pick)
);

header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache, must-revalidate');
header('Vary: Cookie');
echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
