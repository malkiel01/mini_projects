<?php
/**
 * manifest לאפליקציה שאין לה manifest משלה — כך כל אפליקציה מחוברת ניתנת להתקנה כאפליקציה
 * נפרדת, בלי לגעת בקבצים שלה. השער מוסיף <link rel="manifest"> לדפים שלה (lib/pwa.php).
 *
 *   GET platform/pwa.php?app=<id>
 *
 * הטווח הוא התיקייה של האפליקציה; האייקון — platform/assets/app-icons/<id>-*.png
 * (platform/tools/app-icons.mjs), ואם אין — של הפלטפורמה. related_applications מצביע על
 * ה-manifest הזה עצמו, כדי שהדף יוכל לשאול את הדפדפן אם הוא מותקן.
 */

declare(strict_types=1);

require __DIR__ . '/lib/gate.php';
require __DIR__ . '/lib/pwa.php';

$app = is_string($_GET['app'] ?? null) ? $_GET['app'] : '';
$cfg = \Platform\Gate\app_config($app);
if (!$cfg || !\Platform\Pwa\applies($app, $cfg)) {
    http_response_code(404);
    exit;
}
$base = \Platform\Gate\base_url($_SERVER['SCRIPT_NAME'] ?? '/platform/pwa.php', 'platform/pwa.php');
$scope = $base . $app . '/';
$entry = is_string($cfg['entry'] ?? null) ? $cfg['entry'] : '';
$self = \Platform\Pwa\absolute($base . 'platform/pwa.php?app=' . rawurlencode($app));

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'id'          => $scope,
    'name'        => $cfg['name'],
    'short_name'  => short_name((string)$cfg['name']),
    'description' => $cfg['description'] ?? '',
    'lang'        => 'he',
    'dir'         => 'rtl',
    // pwa-launch=1 — סימן שהדף נפתח מהאייקון של האפליקציה הזו (pwa-beacon.js), ולא מתוך חלון
    // של אפליקציה אחרת (למשל הפלטפורמה המותקנת) שגם הוא "מצב אפליקציה".
    'start_url'   => $scope . $entry . '?pwa-launch=1',
    'scope'       => $scope,
    'display'     => 'standalone',
    'background_color' => '#f5f6fa',
    'theme_color' => '#4338ca',
    'icons' => [
        ['src' => \Platform\Pwa\icon_url($app, $base, 192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => \Platform\Pwa\icon_url($app, $base, 512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => \Platform\Pwa\icon_url($app, $base, 512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'related_applications' => [['platform' => 'webapp', 'url' => $self]],
    'prefer_related_applications' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

/** שם קצר לאייקון: מילים שלמות עד 14 תווים. */
function short_name(string $name): string
{
    if (mb_strlen($name) <= 14) return $name;
    $out = '';
    foreach (preg_split('/\s+/u', $name) as $w) {
        if (mb_strlen(trim("$out $w")) > 14) break;
        $out = trim("$out $w");
    }
    return $out !== '' ? $out : mb_substr($name, 0, 14);
}
