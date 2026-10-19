<?php
/**
 * הפעולות הציבוריות ב-api.php זהות לאלה שב-"public" של app.json.
 * פעולה שחסרה ב-app.json נחסמת בשער בשקט — הלקוח מקבל 401, ומי שמחובר לפלטפורמה לא רואה תקלה.
 * הרצה: php org-budget-proposals/tests/gate_public.php
 */

declare(strict_types=1);

$src = file_get_contents(__DIR__ . '/../api.php');
preg_match("/const PUBLIC_ACTIONS = \[(.*?)\];/s", $src, $m) or exit("✕ PUBLIC_ACTIONS לא נמצא\n");
preg_match_all("/'([a-z_-]+)'/", $m[1], $mm);
$api = $mm[1];

$app = json_decode(file_get_contents(__DIR__ . '/../app.json'), true);
$gate = [];
foreach ($app['public'] ?? [] as $p) {
    if (preg_match('/^api\.php\?action=(.+)$/', $p, $x)) $gate = array_merge($gate, explode('|', $x[1]));
}
sort($api);
sort($gate);
if ($api !== $gate) {
    echo "✕ לא זהות\n  api.php:  " . implode(', ', $api) . "\n  app.json: " . implode(', ', $gate) . "\n";
    exit(1);
}
echo '✓ ' . count($api) . " פעולות ציבוריות — זהות ב-api.php וב-app.json\n";
