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
// בדיקות הדפדפן של הבידוד (F4): הזהות בעוגייה — כמו בייצור (HttpOnly, SameSite=Lax), כדי שהדפדפן עצמו יחליט מתי לשלוח אותה
elseif (isset($_COOKIE['kol_test_user'])) $_SERVER['PLATFORM_USER'] = (string)base64_decode($_COOKIE['kol_test_user']);
// A2: "האתר" של ההקלטות — שרת מדומה מקומי (tests/fake-audio.php). JSON: {"https://maghreb.co.il": "http://127.0.0.1:PORT"}
// P1: platform/provision.php — שרת מדומה (tests/fake-provision.php)
// P1: מטריצת ההרשאות — api.php עוצר מיד אחרי authorize() (בלי להריץ את הפעולה)
if (isset($_SERVER['HTTP_X_TEST_AUTHORIZE_ONLY'])) $GLOBALS['kol_test_authorize_only'] = true;
// בלי שרת מדומה — כתובת מקומית שאינה עונה (נכשל מיד), לעולם לא הפלטפורמה האמיתית
$GLOBALS['kol_provision_url'] = getenv('KOL_TEST_PROVISION') ?: 'http://127.0.0.1:9/provision.php';
if ($map = getenv('KOL_TEST_REC_MAP')) $GLOBALS['kol_rec_test_map'] = json_decode($map, true);
// ויומן של מה שהשרת ראה בפועל: מי פנה, מאיזה מקור, ועם זהות או בלי
if ($trace = getenv('KOL_TEST_TRACE')) {
    file_put_contents($trace, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'method' => $_SERVER['REQUEST_METHOD'], 'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
        'site' => $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null, 'dest' => $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null, 'user' => isset($_SERVER['PLATFORM_USER']), 'cookie' => isset($_SERVER['HTTP_COOKIE'])]) . "\n", FILE_APPEND | LOCK_EX);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__);
// הסטודיו (C1): '/' ו-index.php — דרך index.php, עם הכותרות; studio.html ישירות — חסום; index.html — 302, כמו ב-.htaccess שבשורש
if ($path === '/' || $path === '/index.php') { chdir($root); require $root . '/index.php'; exit; }
if ($path === '/studio.html') { http_response_code(403); exit; }
if ($path === '/index.html') { $q = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY); header('Location: /index.php' . ($q !== '' ? '?' . $q : ''), true, 302); exit; }
if ($path === '/csp.php') { chdir($root); require $root . '/csp.php'; exit; }
if ($path === '/live.php') { chdir($root); require $root . '/live.php'; exit; }
if ($path === '/form.php') { chdir($root); require $root . '/form.php'; exit; }     // M5 — הנקודה הציבורית של הטפסים
if ($path === '/editor.php') { chdir($root); require $root . '/editor.php'; exit; }
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
$file = realpath($root . $path);
if (!$file || !str_starts_with($file, $root . '/') || !is_file($file) || str_ends_with($file, '.php')) { http_response_code(404); exit; }
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml', 'json' => 'application/json'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: no-store');
readfile($file);
