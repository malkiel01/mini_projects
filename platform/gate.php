<?php
/**
 * מגיש קובץ סטטי מתוך תיקיית כלי, אחרי בדיקת השער.
 *
 * ה-.htaccess של כלי שהשער פעיל בו מפנה לכאן (הפניה פנימית) כל בקשה לקובץ שאינו PHP
 * (ראו build-gate.php): gate.php?__gate_app=<כלי>&__gate_path=<נתיב בתוך הכלי>.
 * קובצי PHP אינם מגיעים לכאן — עליהם שומר gate-prepend.php.
 */

declare(strict_types=1);

require __DIR__ . '/lib/gate.php';
require __DIR__ . '/lib/pwa.php';

use function Platform\Gate\{decide, deny, base_url, record_visit};

const TYPES = [
    'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
    'webmanifest' => 'application/manifest+json', 'map' => 'application/json',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'pdf' => 'application/pdf', 'zip' => 'application/zip', 'txt' => 'text/plain; charset=utf-8',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg',
    'm3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/mp2t', 'wasm' => 'application/wasm',
    'glb' => 'model/gltf-binary', 'gltf' => 'model/gltf+json', 'csv' => 'text/csv; charset=utf-8',
];

function not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

// הקובץ נגזר מהכתובת המקורית (REQUEST_URI — נשמרת בהפניה פנימית של rewrite),
// לא מפרמטרי השאילתה: את הכתובת המקורית השרת כבר בדק מול חוקי ה-.htaccess של
// הכלי (data/ חסומה וכו'), ואילו פרמטרים אפשר לזייף — ?__gate_path=data/x.sqlite
// בסוף כתובת לגיטימית היה דורס את הנתיב (QSA). פנייה ישירה ל-gate.php — 404.
$base = base_url($_SERVER['SCRIPT_NAME'] ?? '/platform/gate.php', 'platform/gate.php');
$uri  = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
if (!str_starts_with($uri, $base) || str_starts_with($uri, $base . 'platform/')) not_found();
[$app, $path] = array_pad(explode('/', substr($uri, strlen($base)), 2), 2, '');
if ($path === '' || str_ends_with($path, '/')) $path .= 'index.html';
if (($_GET['__gate_app'] ?? null) !== $app) not_found(); // הבלוק של הכלי הזה הוא שהפנה לכאן

$root = realpath(__DIR__ . '/..');
// הנתיב חייב להישאר בתוך תיקיית הכלי, ולא לגעת בקבצים נסתרים.
$appDir = $root ? realpath("$root/$app") : false;
$file = $appDir ? realpath("$appDir/$path") : false;
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $app) || !$appDir || dirname($appDir) !== $root
    || !$file || !is_file($file) || !str_starts_with($file, $appDir . '/')
    || preg_match('#(^|/)\.#', substr($file, strlen($appDir) + 1))) {
    not_found();
}
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!isset(TYPES[$ext])) not_found();

$rel = substr($file, strlen($appDir) + 1);
\Platform\Gate\handoff_consume($app, $base);   // ?platform-handoff — התחברות שהועברה מהכלים הדיגיטליים
$params = array_diff_key($_GET, ['__gate_app' => 1, '__gate_path' => 1]);
$verdict = decide($app, $rel, $params);
if ($verdict !== null) {
    \Platform\Gate\diag_nav('gate:' . $verdict[1], $app);
    deny($verdict, $base);
}
\Platform\Gate\touch_session($base);
if (in_array($ext, ['html', 'htm'], true)) {
    record_visit($app);
    \Platform\Gate\diag_nav('gate:ok', $app);
}

// דף HTML / manifest של אפליקציה מחוברת — מוגשים עם התוספות של הפלטפורמה (lib/pwa.php):
// בדיקת ההתקנה והפס "התקנה" בדף (רק בדף שאינו ציבורי, למשתמש מחובר), ו-related_applications
// ב-manifest. קטנים — נקראים לזיכרון.
$cfg = \Platform\Gate\app_config($app);
$patched = null;
if (\Platform\Pwa\applies($app, $cfg) && filesize($file) < 2_000_000) {
    if (in_array($ext, ['html', 'htm'], true)) {
        $patched = \Platform\Pwa\inject_html((string)file_get_contents($file), $app, $cfg, $base,
            \Platform\Pwa\wanted($app, $cfg, $rel, $params));
    } elseif ($ext === 'webmanifest' || ($ext === 'json' && preg_match('#(^|/)manifest\.json$#', $rel))) {
        $patched = \Platform\Pwa\patch_manifest((string)file_get_contents($file), \Platform\Pwa\absolute($base . $app . '/' . $rel));
    }
}

// ---- הגשה ----
$size  = $patched !== null ? strlen($patched) : filesize($file);
$mtime = filemtime($file);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . ($patched !== null ? '-p' : '') . '"';
header('Content-Type: ' . TYPES[$ext]);
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
// הקוד נבדק מול השרת בכל טעינה (כמו ב-.htaccess של הכלים); מדיה נשמרת שעה.
// private: התשובה תלויה במשתמש, אסור למטמון משותף לשמור אותה.
header(in_array($ext, ['html', 'htm', 'js', 'mjs', 'css', 'json', 'webmanifest'], true)
    ? 'Cache-Control: private, no-cache' : 'Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

// Range — וידאו בדפדפן מבקש חלקים.
$start = 0;
$end = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
if ($patched !== null) {
    echo substr($patched, $start, $end - $start + 1);
    exit;
}

$fh = fopen($file, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $left));
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
