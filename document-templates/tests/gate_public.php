<?php
/**
 * בדיקה: כל פעולה שהאפליקציה פותחת בלי כניסה ($PUBLIC ב-api.php) מופיעה גם ב-"public" של app.json —
 * אחרת שער הפלטפורמה חוסם אותה בשקט (הממלא מקבל "נדרשת כניסה"). וגם הדפים שנפתחים בלי כניסה.
 * הרצה: php document-templates/tests/gate_public.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}

$cfg = json_decode((string)file_get_contents(__DIR__ . '/../app.json'), true);
preg_match('/\$PUBLIC = \[(.*?)\];/s', (string)file_get_contents(__DIR__ . '/../api.php'), $m);
preg_match_all("/'([a-z_\-]+)'/", $m[1] ?? '', $a);
$app = $a[1];

$gate = [];
$paths = [];
foreach ($cfg['public'] ?? [] as $p) {
    if (preg_match('/^api\.php\?action=(.+)$/', $p, $q)) $gate = array_merge($gate, explode('|', $q[1]));
    else $paths[] = $p;
}

check('api.php: נמצאה רשימת $PUBLIC', count($app) > 10);
$missing = array_values(array_diff($app, $gate));
check('כל פעולה ציבורית של האפליקציה פתוחה בשער' . ($missing ? ' — חסר: ' . implode(', ', $missing) : ''), !$missing);
$extra = array_values(array_diff($gate, $app));
check('השער לא פותח פעולה שהאפליקציה דורשת לה כניסה' . ($extra ? ' — עודף: ' . implode(', ', $extra) : ''), !$extra);
foreach (['index.html', 'fill.html', 'assets/**'] as $p) check("דף פתוח בשער: $p", in_array($p, $paths, true));

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
