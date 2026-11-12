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
    // נעילה על קובץ נפרד, וכתיבה אטומית (קובץ זמני ← rename): מי שקורא בלי נעילה (בדיקת הדפדפן) רואה תמיד קובץ שלם
    $f = $GLOBALS['wp_dir'] . '/options.json';
    $lock = fopen($f . '.lock', 'c');
    flock($lock, LOCK_EX);
    $o = json_decode((string)@file_get_contents($f), true) ?: [];
    [$o2, $ret] = $fn($o);
    if ($o2 !== null) { $tmp = $f . '.' . getmypid() . '.tmp'; file_put_contents($tmp, json_encode($o2)); rename($tmp, $f); }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $ret;
}
function get_option($k, $d = false) { return wp_opts_locked(fn($o) => [null, array_key_exists($k, $o) ? $o[$k] : $d]); }
function update_option($k, $v, $autoload = null) { return wp_opts_locked(function ($o) use ($k, $v) { $o[$k] = $v; return [$o, true]; }); }
function delete_option($k) { return wp_opts_locked(function ($o) use ($k) { unset($o[$k]); return [$o, true]; }); }
$GLOBALS['wp_hooks'] = [];
function add_action($hook, $cb = null, ...$a) { $GLOBALS['wp_hooks'][$hook][] = $cb; }
function add_filter($hook, $cb = null, ...$a) { $GLOBALS['wp_filters'][$hook][] = $cb; }
function apply_filters($hook, $v, ...$a) { foreach ($GLOBALS['wp_filters'][$hook] ?? [] as $cb) $v = $cb($v, ...$a); return $v; }
function __return_true() { return true; }
// ── ערכת נושא מדומה (M4-BC): הכותרת והתחתית של האתר, עם סימונים — כדי לראות שהמעטפת היא עמוד של האתר ──
function status_header($c) { http_response_code((int)$c); }
function language_attributes() { echo 'lang="he-IL" dir="rtl"'; }
function bloginfo($k) { echo $k === 'charset' ? 'UTF-8' : 'Maghreb'; }
function body_class($c = '') { echo 'class="' . htmlspecialchars('site ' . $c) . '"'; }
function wp_body_open() {}
function wp_is_block_theme() { return false; }
function wp_head() { foreach ($GLOBALS['wp_hooks']['wp_head'] ?? [] as $cb) $cb(); }
function wp_footer() { echo '<script>window.WP_FOOTER_RAN=1</script>'; }
function get_header() {
    $t = apply_filters('pre_get_document_title', '');
    echo '<!doctype html><html lang="he-IL" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . ($t !== '' ? $t : 'Maghreb site') . '</title>';
    wp_head();
    echo '<style>body{margin:0;font-family:sans-serif}#site-header{height:120px;background:#123;color:#fff}#site-footer{height:200px;background:#321;color:#fff}</style>'
        . '</head><body class="site"><header id="site-header">SITE-HEADER <a href="/__site/other">תפריט</a></header>';
}
function get_footer() { echo '<footer id="site-footer">SITE-FOOTER</footer>'; wp_footer(); echo '</body></html>'; }
function do_action($hook, ...$a) { if ($hook !== 'init') update_option('test_actions', array_merge((array)get_option('test_actions', []), [[$hook, $a]])); }
function home_url($p = '') { return 'http://' . $_SERVER['HTTP_HOST'] . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_upload_dir() { return ['basedir' => $GLOBALS['wp_dir'] . '/uploads', 'baseurl' => home_url('/wp-content/uploads')]; }
function wp_mkdir_p($d) { return is_dir($d) || @mkdir($d, 0775, true) || is_dir($d); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function get_permalink($id) { return home_url('/lexicon/'); }
function clean_post_cache($id) {}
// ── מסך הניהול: "מחובר כמנהל" = העוגייה test_admin=1 (manage_options); nonce של וורדפרס = "nonce-<פעולה>" ──
function current_user_can($cap) { return $cap === 'manage_options' && ($_COOKIE['test_admin'] ?? '') === '1'; }
function wp_get_current_user() { return (object)['user_login' => ($_COOKIE['test_admin'] ?? '') === '1' ? 'admin<b>"' : '']; }
function wp_die($m = '') { http_response_code(403); echo 'WP_DIE: ' . $m; exit; }
function wp_create_nonce($a = -1) { return 'nonce-' . $a; }
function wp_nonce_field($a = -1, $n = '_wpnonce', $r = true, $echo = true) { $h = '<input type="hidden" name="' . $n . '" value="nonce-' . $a . '">'; if ($echo) echo $h; return $h; }
function check_admin_referer($a = -1, $n = '_wpnonce') { if (($_POST[$n] ?? '') !== 'nonce-' . $a) wp_die('nonce'); return 1; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k)); }
function wp_unslash($v) { return $v; }
function admin_url($p = '') { return home_url('/wp-admin/' . $p); }
function wp_safe_redirect($u) { header('Location: ' . $u, true, 302); }
function set_transient($k, $v, $t = 0) { update_option('_transient_' . $k, $v); }
function get_transient($k) { return get_option('_transient_' . $k, false); }
function delete_transient($k) { delete_option('_transient_' . $k); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function size_format($b) { return (int)$b . ' B'; }
function wp_date($f, $t) { return gmdate($f, (int)$t); }
function checked($a, $b = true, $echo = true) { $r = $a == $b ? ' checked="checked"' : ''; if ($echo) echo $r; return $r; }
function submit_button($t = '', $c = '', $n = 'submit', $wrap = true) { echo '<button type="submit" name="' . $n . '">' . esc_html($t) . '</button>'; }
final class WP_Error { public $m; public function __construct($c = '', $m = '') { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
/** הבקשה העצמית — curl אמיתי לאותו שרת (8 תהליכים). מתעד כל כתובת שהתבקשה, כדי להוכיח שרק לאתר עצמו. */
function wp_remote_request($url, $args = []) {
    update_option('test_remote', array_merge((array)get_option('test_remote', []), [$url]));
    $ch = curl_init($url);
    $hs = [];
    foreach ((array)($args['headers'] ?? []) as $k => $v) $hs[] = "$k: $v";
    $rh = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hs, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PATH_AS_IS => true,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$rh) { if (strpos($l, ':')) { [$k, $v] = explode(':', $l, 2); $k = strtolower(trim($k)); $rh[$k] = isset($rh[$k]) ? (array)$rh[$k] + [99 => trim($v)] : trim($v); } return strlen($l); }]);
    $body = curl_exec($ch);
    if ($body === false) return new WP_Error('http', curl_error($ch));
    return ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $rh, 'body' => $body];
}
function wp_remote_retrieve_headers($r) { return $r['headers']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }

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
// template_redirect — כמו וורדפרס אחרי הניתוב (M4-BC: מסמך המעטפת נרשם כאן כשהתוסף החליט עליו, ומסתיים ב-exit)
foreach ($GLOBALS['wp_hooks']['template_redirect'] ?? [] as $cb) $cb();
if (preg_match('~^/wp-json/kol-maroko/v1/([a-z_]+)$~', $path, $m)) {
    $r = $_SERVER['REQUEST_METHOD'] === 'POST' ? kol_maroko_handle($m[1], new WP_REST_Request()) : new WP_REST_Response(['code' => 'rest_no_route'], 404);
    http_response_code($r->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r->data, JSON_UNESCAPED_UNICODE);
    exit;
}
// H2 / H3 / H15: מה השרת קיבל בפועל — האם הגיעה העוגייה של המחובר, ומאיזה מקור
if (in_array($path, ['/wp-json/wp/v2/users/me', '/wp-admin/admin-ajax.php'], true)) {
    update_option('test_hits', array_merge((array)get_option('test_hits', []), [['path' => $path, 'method' => $_SERVER['REQUEST_METHOD'], 'cookie' => $logged,
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? null, 'action' => $_POST['action'] ?? null]]));
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
if ($path === '/wp-admin/admin-post.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {      // admin_post_<action>, כמו וורדפרס
    foreach ($GLOBALS['wp_hooks']['admin_post_' . sanitize_key($_POST['action'] ?? '')] ?? [] as $cb) $cb();
    exit;
}
if ($path === '/wp-admin/options-general.php') {                                          // עמוד ההגדרות — קטע 2.0
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    header('Content-Type: text/html; charset=utf-8');
    kol_maroko_v2_admin_section();
    kol_maroko_v2_selftest_html();
    exit;
}
header('Content-Type: text/html; charset=utf-8');
if ($path === '/__site/frame') {         // עמוד של האתר שמכיל את העמוד במסגרת (H4 / H15 בתוך מסגרת)
    echo '<!doctype html><meta charset="utf-8"><title>site frame</title><h1 id="site-marker">WP-SITE-PAGE frame</h1><iframe id="kit" src="/lexicon/" style="width:900px;height:700px"></iframe>';
    exit;
}
if (strpos($path, '/__site/') === 0) {
    // עמוד של האתר: מה שהבודק רואה בשאר האתר (H5), ובקרה חיובית ל-H2/H3 — אותו fetch, מהמקור של האתר, כן מחובר
    echo '<!doctype html><meta charset="utf-8"><title>site page</title><h1 id="site-marker" style="display:block">WP-SITE-PAGE</h1>'
        . '<script>Promise.all([fetch("/wp-json/wp/v2/users/me",{credentials:"include"}).then(r=>r.text()),'
        . 'fetch("/wp-admin/admin-ajax.php",{method:"POST",credentials:"include",body:"action=x"}).then(r=>r.text())])'
        . '.then(([a,b])=>console.log("SITECTL "+JSON.stringify({me:a.includes("LOGGED-IN-USER-DATA"),ajax:b==="ADMIN-AJAX-LOGGED-IN",cookie:document.cookie})))</script>';
    exit;
}
echo '<!doctype html><meta charset="utf-8"><title>wp</title><p>WP-SITE-PAGE ' . htmlspecialchars($path) . '</p>';
