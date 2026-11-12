<?php
/**
 * וורדפרס מדומה לבדיקות של התוסף 2.0 (php -S; פרוסה M2 §3). טוען את קובץ התוסף **האמיתי**, כמו וורדפרס שטוען תוסף:
 * ההגשה של 2.0 רצה בטעינת הקובץ — לפני "הניתוב" שלמטה. מה שהתוסף לא תפס מגיע לאתר המדומה:
 *
 *   /wp-json/kol-maroko/v1/<action>  — הקבלה (kol_maroko_handle)
 *   /wp-json/wp/v2/users/me          — H2: "מחובר" רק עם העוגייה wordpress_logged_in_test
 *   /wp-admin/admin-ajax.php         — H3: פעולה שדורשת התחברות — רק עם העוגייה
 *   /__login                         — קובע את העוגייה (כמו התחברות לוורדפרס)
 *   /__site/…                        — עמוד של האתר (H5: "שאר האתר ללא שינוי"), ועמוד הבקרה החיובית של H2/H3
 *   כל השאר                          — "עמוד של וורדפרס" עם הסימון WP-SITE-PAGE (כלומר: התוסף לא תפס)
 *
 * לפני שהתוסף נטען, האתר המדומה קובע עוגייה (כמו session_start של תוסף אחר) — כדי להוכיח שהתוסף מסיר אותה.
 * האפשרויות נשמרות ב-<KOL_TEST_WP>/options.json (עם נעילה — השרת רץ בכמה תהליכים).
 */

declare(strict_types=1);

$dir = getenv('KOL_TEST_WP');
if (PHP_SAPI !== 'cli-server' || !$dir || !is_dir($dir)) { http_response_code(404); exit; }

define('ABSPATH', $dir . '/');
$GLOBALS['wp_dir'] = $dir;

function wp_opts_locked(callable $fn)
{
    $fh = fopen($GLOBALS['wp_dir'] . '/options.json', 'c+');
    flock($fh, LOCK_EX);
    $o = json_decode((string)stream_get_contents($fh), true) ?: [];
    [$o2, $ret] = $fn($o);
    if ($o2 !== null) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($o2)); fflush($fh); }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ret;
}
function get_option($k, $d = false) { return wp_opts_locked(fn($o) => [null, array_key_exists($k, $o) ? $o[$k] : $d]); }
function update_option($k, $v, $autoload = null) { return wp_opts_locked(function ($o) use ($k, $v) { $o[$k] = $v; return [$o, true]; }); }
function delete_option($k) { return wp_opts_locked(function ($o) use ($k) { unset($o[$k]); return [$o, true]; }); }
function add_action(...$a) {}
function add_filter(...$a) {}
function do_action($hook, ...$a) { if ($hook !== 'init') update_option('test_actions', array_merge((array)get_option('test_actions', []), [[$hook, $a]])); }
function home_url($p = '') { return 'http://' . $_SERVER['HTTP_HOST'] . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_upload_dir() { return ['basedir' => $GLOBALS['wp_dir'] . '/uploads', 'baseurl' => home_url('/wp-content/uploads')]; }
function wp_mkdir_p($d) { return is_dir($d) || @mkdir($d, 0775, true) || is_dir($d); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function get_permalink($id) { return home_url('/lexicon/'); }
function clean_post_cache($id) {}
final class WP_REST_Request
{
    public function get_body() { return (string)file_get_contents('php://input'); }
    public function get_header($h) { return $_SERVER['HTTP_' . strtoupper($h)] ?? null; }
}
final class WP_REST_Response
{
    public $data;
    public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = (int)$status; }
}

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$logged = ($_COOKIE['wordpress_logged_in_test'] ?? '') === '1';

// "תוסף אחר" שקובע עוגייה לפני שהתוסף שלנו נטען — ופלט שכבר במאגר
setcookie('other_plugin_session', 'abc123', ['path' => '/']);
ob_start();
echo 'OUTPUT-BEFORE-PLUGIN';

require dirname(__DIR__, 2) . '/wordpress-plugin/kol-maroko/kol-maroko.php';

// ── מכאן: "וורדפרס" — התוסף לא תפס את הבקשה ─────────────────
ob_end_clean();
if (preg_match('~^/wp-json/kol-maroko/v1/([a-z_]+)$~', $path, $m)) {
    $r = $_SERVER['REQUEST_METHOD'] === 'POST' ? kol_maroko_handle($m[1], new WP_REST_Request()) : new WP_REST_Response(['code' => 'rest_no_route'], 404);
    http_response_code($r->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r->data, JSON_UNESCAPED_UNICODE);
    exit;
}
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));       // כמו אתר "פתוח" — כדי שרק העוגייה תכריע
header('Access-Control-Allow-Credentials: true');
if ($path === '/wp-json/wp/v2/users/me') {
    header('Content-Type: application/json');
    if (!$logged) { http_response_code(401); echo '{"code":"rest_not_logged_in"}'; exit; }
    echo '{"id":1,"name":"admin","slug":"admin","SECRET":"LOGGED-IN-USER-DATA"}';
    exit;
}
if ($path === '/wp-admin/admin-ajax.php') {
    header('Content-Type: text/plain');
    if (!$logged) { http_response_code(400); echo '0'; exit; }
    echo 'ADMIN-AJAX-LOGGED-IN';
    exit;
}
if ($path === '/__login') {
    setcookie('wordpress_logged_in_test', '1', ['path' => '/', 'httponly' => true]);
    header('Content-Type: text/plain');
    echo 'logged in';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
if (strpos($path, '/__site/') === 0) {
    // עמוד של האתר: מה שהבודק רואה בשאר האתר (H5), ובקרה חיובית ל-H2/H3 — אותו fetch, מהמקור של האתר, כן מחובר
    echo '<!doctype html><meta charset="utf-8"><title>site page</title><h1 id="site-marker" style="display:block">WP-SITE-PAGE</h1>'
        . '<script>Promise.all([fetch("/wp-json/wp/v2/users/me",{credentials:"include"}).then(r=>r.text()),'
        . 'fetch("/wp-admin/admin-ajax.php",{method:"POST",credentials:"include",body:"action=x"}).then(r=>r.text())])'
        . '.then(([a,b])=>console.log("SITECTL "+JSON.stringify({me:a.includes("LOGGED-IN-USER-DATA"),ajax:b==="ADMIN-AJAX-LOGGED-IN",cookie:document.cookie})))</script>';
    exit;
}
echo '<!doctype html><meta charset="utf-8"><title>wp</title><p>WP-SITE-PAGE ' . htmlspecialchars($path) . '</p>';
