<?php
/**
 * נתב לשרת הבדיקות (php -S) — מריץ את api.php / agent.php האמיתיים מול מסד זמני.
 * הזהות, שבייצור מגיעה מהשער ($_SERVER['PLATFORM_USER']), מגיעה כאן מהכותרת X-Test-User.
 *
 * ⚠️ רץ רק בשרת המובנה של PHP ורק כש-KOL_TEST_DATA מוגדר. בכל מצב אחר — 404. (וגם tests/.htaccess חוסם את התיקייה.)
 */

declare(strict_types=1);

$data = getenv('KOL_TEST_DATA');
if (PHP_SAPI !== 'cli-server' || !$data || !is_dir($data)) { http_response_code(404); exit; }

define('KOL_DATA_DIR', $data);
if (isset($_SERVER['HTTP_X_TEST_USER'])) $_SERVER['PLATFORM_USER'] = $_SERVER['HTTP_X_TEST_USER'];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__);
if ($path === '/api.php' || $path === '/agent.php') {
    if (isset($_SERVER['HTTP_X_TEST_WARN'])) {          // אזהרת PHP אמיתית בתוך בקשה — לבדיקת הלכידה
        require_once $root . '/lib/devlog.php';
        devlog_begin('api');
        trigger_error('test-warning ' . $_SERVER['HTTP_X_TEST_WARN'], E_USER_WARNING);
    }
    chdir($root);
    require $root . $path;
    exit;
}
if (str_starts_with($path, '/preview/')) {              // כמו preview/.htaccess: הכול ל-index.php
    chdir($root);
    require $root . '/preview/index.php';
    exit;
}
// קבצים סטטיים (לבדיקות הדפדפן). data/ — כמו בשרת: חסום. ?open=1 מדמה שרת שבו ה-.htaccess לא פועל
if (str_starts_with($path, '/data/')) {
    if (getenv('KOL_TEST_DATA_OPEN')) { echo 'open'; exit; }
    http_response_code(403); exit;
}
$file = realpath($root . ($path === '/' ? '/index.html' : $path));
if (!$file || !str_starts_with($file, $root . '/') || !is_file($file) || str_ends_with($file, '.php')) { http_response_code(404); exit; }
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml', 'json' => 'application/json'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: no-store');
readfile($file);
