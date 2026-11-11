<?php
/**
 * אתר וורדפרס מדומה לבדיקות (php -S): טוען את קובץ התוסף **האמיתי** (wordpress-plugin/kol-maroko/kol-maroko.php)
 * עם תחליפים מינימליים לפונקציות של וורדפרס, ומגיש את /wp-json/kol-maroko/v1/<action>. כך החתימה, ה-nonce ומספר
 * הגרסה נבדקים בקוד שרץ באתר — לא בחיקוי שלו. האפשרויות (options) נשמרות בקובץ JSON בתיקייה הזמנית.
 */

declare(strict_types=1);

$dir = getenv('KOL_TEST_WP');
if (PHP_SAPI !== 'cli-server' || !$dir || !is_dir($dir)) { http_response_code(404); exit; }

define('ABSPATH', $dir . '/');
$GLOBALS['wp_dir'] = $dir;
function wp_opts(?array $set = null): array
{
    $f = $GLOBALS['wp_dir'] . '/options.json';
    if ($set !== null) { file_put_contents($f, json_encode($set), LOCK_EX); return $set; }
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}
function get_option($k, $d = false) { $o = wp_opts(); return array_key_exists($k, $o) ? $o[$k] : $d; }
function update_option($k, $v, $autoload = null) { $o = wp_opts(); $o[$k] = $v; wp_opts($o); return true; }
function delete_option($k) { $o = wp_opts(); unset($o[$k]); wp_opts($o); return true; }
function add_action(...$a) {}
function add_filter(...$a) {}
function do_action(...$a) {}
function home_url($p = '') { return 'http://' . $_SERVER['HTTP_HOST'] . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_upload_dir() { return ['basedir' => $GLOBALS['wp_dir'] . '/uploads', 'baseurl' => home_url('/uploads')]; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0775, true); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function get_permalink($id) { return home_url('/?p=' . (int)$id); }
function clean_post_cache($id) {}
final class WP_REST_Request
{
    public function get_body() { return (string)file_get_contents('php://input'); }
    public function get_header($h) { return $_SERVER['HTTP_' . strtoupper($h)] ?? null; }
}
final class WP_REST_Response
{
    public function __construct(public $data = null, public int $status = 200) {}
}

require dirname(__DIR__) . '/wordpress-plugin/kol-maroko/kol-maroko.php';

if (!preg_match('~^/wp-json/kol-maroko/v1/(ping|asset|publish|switch)$~', (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) { http_response_code(404); exit; }
$r = kol_maroko_handle($m[1], new WP_REST_Request());
http_response_code($r->status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($r->data, JSON_UNESCAPED_UNICODE);
