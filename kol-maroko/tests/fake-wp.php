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

// M2: התוסף האמיתי (2.0) — אלא אם הבדיקה מבקשת "תוסף 1.x" (kol_wp_plugin_v1, והמדומה כבוי): אז העותק הקפוא של 1.6.0.
// הטעינה מריצה גם את ההגשה של 2.0 (v2-serve.php) — בקשה שאינה wp-json מגיעה לתוסף לפני ה-404 שבסוף, כמו בוורדפרס.
require !get_option('kol_v2_mock') && get_option('kol_wp_plugin_v1')
    ? __DIR__ . '/plugin/fixtures/kol-maroko-1.6.0.php'
    : dirname(__DIR__) . '/wordpress-plugin/kol-maroko/kol-maroko.php';

/**
 * **המקבל המדומה v2** (פרוסה M1 §3) — החוזה של M2, כתוב כבדיקה. פעיל רק כשהאפשרות kol_v2_mock דולקת.
 * החתימה, ה-nonce והזמן של כל בקשה — בקוד האמיתי של התוסף (kol_maroko_verify). מעליו, לפי 07 §5.1:
 *   asset_has — אילו גושים כבר כאן;  asset_put — גוש בחלקים, נבדק לפי sha256 וגודל לפני שנשמר;
 *   manifest — חתימה נפרדת על המניפסט עצמו, site / page / זמן / nonce / גרסה עולה, נתיבים חוקיים, וכל קובץ קיים
 *   ותואם — ורק אז "מחליף מצביע" (אפשרות אחת). כל דחייה — הגרסה שבאוויר לא נגעה.
 * הגושים ב-<dir>/v2/blobs/<sha>; המניפסט שבאוויר — <dir>/v2/live.json (לשרת הסטטי של בדיקת הדפדפן).
 */
function v2_dir(): string
{
    $d = $GLOBALS['wp_dir'] . '/v2/blobs';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}
function v2_log(string $k, $v): void { $o = (array)get_option($k, []); $o[] = $v; update_option($k, $o); }
function v2_handle(string $action, WP_REST_Request $req)
{
    $b = kol_maroko_verify($action, $req);
    if ($b instanceof WP_REST_Response) return $b;
    if ($action === 'ping') return new WP_REST_Response(['ok' => true, 'plugin' => '2.0.0-mock', 'protocol' => 2, 'version' => (int)get_option('v2_last', 0), 'live' => (int)get_option('v2_live', 0)]);
    if ($action === 'asset_has') {
        $have = [];
        foreach ((array)($b['sha256'] ?? []) as $sha) if (is_string($sha) && preg_match('/^[a-f0-9]{64}$/', $sha) && is_file(v2_dir() . "/$sha")) $have[] = $sha;
        return new WP_REST_Response(['ok' => true, 'have' => $have]);
    }
    if ($action === 'asset_put') {
        $sha = (string)($b['sha256'] ?? '');
        $size = (int)($b['size'] ?? 0);
        $part = (int)($b['part'] ?? -1);
        $parts = (int)($b['parts'] ?? 0);
        if (!preg_match('/^[a-f0-9]{64}$/', $sha) || $size < 0 || $parts < 1 || $part < 0 || $part >= $parts) return kol_maroko_fail('bad_request', 'פרטי הגוש לא תקינים');
        // כשל מדומה באמצע: אחרי N גושים שהתקבלו — גוש אחד נכשל (פעם אחת)
        $after = get_option('v2_fail_after', null);
        if ($after !== null && count((array)get_option('v2_puts', [])) >= (int)$after) { delete_option('v2_fail_after'); return kol_maroko_fail('boom', 'כשל מדומה באמצע', 500); }
        $final = v2_dir() . "/$sha";
        if (is_file($final)) return new WP_REST_Response(['ok' => true, 'have' => true]);
        $tmp = "$final.part";
        if ($part === 0) @unlink($tmp);
        $data = base64_decode((string)($b['data'] ?? ''), true);
        if ($data === false) return kol_maroko_fail('bad_request', 'תוכן לא תקין');
        file_put_contents($tmp, $data, FILE_APPEND);
        if ($part + 1 < $parts) return new WP_REST_Response(['ok' => true, 'received' => $part + 1]);
        clearstatcache();
        if (filesize($tmp) !== $size || hash_file('sha256', $tmp) !== $sha) { @unlink($tmp); return kol_maroko_fail('bad_hash', 'הגוש הגיע פגום', 422); }
        rename($tmp, $final);
        v2_log('v2_puts', $sha);
        return new WP_REST_Response(['ok' => true, 'done' => true]);
    }
    // manifest
    $bytes = (string)($b['manifest'] ?? '');
    $keys = kol_maroko_keys();
    $sig = base64_decode((string)($b['manifest_sig'] ?? ''), true);
    $kid = (string)($b['kid'] ?? '');
    if (!isset($keys[$kid]) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $bytes, $keys[$kid])) {
        return kol_maroko_fail('bad_signature', 'החתימה על המניפסט לא תקינה', 401);
    }
    $man = json_decode($bytes, true);
    if (!is_array($man) || !is_array($man['files'] ?? null)) return kol_maroko_fail('bad_request', 'מניפסט לא תקין');
    if (($man['site'] ?? '') !== kol_maroko_host()) return kol_maroko_fail('wrong_site', 'האתר לא תואם', 403);
    if (($man['page'] ?? '') !== KOL_MAROKO_PAGE) return kol_maroko_fail('wrong_page', 'העמוד לא תואם', 403);
    if (abs(time() - (int)($man['time'] ?? 0)) > KOL_MAROKO_SKEW) return kol_maroko_fail('stale', 'המניפסט ישן', 401);
    $nonces = (array)get_option('v2_nonces', []);
    if (!preg_match('/^[a-f0-9]{32}$/', (string)($man['nonce'] ?? '')) || isset($nonces[$man['nonce']])) return kol_maroko_fail('replay', 'המניפסט כבר התקבל', 401);
    $n = (int)($man['version'] ?? 0);
    $last = (int)get_option('v2_last', 0);
    if ($n <= $last) return kol_maroko_fail('old_version', 'יש גרסה חדשה יותר', 409, ['version' => $last]);
    foreach ($man['files'] as $path => $f) {
        $ok = is_string($path) && $path !== '' && $path[0] !== '/' && !str_contains($path, '\\') && strlen($path) <= 1024 && !preg_match('/[\x00-\x1f]/', $path);
        foreach ($ok ? explode('/', $path) : [] as $seg) if ($seg === '' || $seg === '.' || $seg === '..') $ok = false;
        if (!$ok) return kol_maroko_fail('bad_path', 'נתיב לא חוקי: ' . $path, 422);
        $sha = (string)($f['sha256'] ?? '');
        $file = v2_dir() . "/$sha";
        if (!preg_match('/^[a-f0-9]{64}$/', $sha) || !is_file($file)) return kol_maroko_fail('asset_missing', 'חסר קובץ: ' . $path, 409);
        if (hash_file('sha256', $file) !== $sha || filesize($file) !== (int)($f['size'] ?? -1)) return kol_maroko_fail('bad_hash', 'קובץ לא תואם: ' . $path, 409);
    }
    if (!isset($man['files'][(string)($man['entry'] ?? '')])) return kol_maroko_fail('bad_request', 'נקודת הכניסה אינה בחבילה', 422);
    $nonces[$man['nonce']] = time();
    update_option('v2_nonces', $nonces);
    file_put_contents($GLOBALS['wp_dir'] . "/v2/manifest-$n.json", $bytes);
    copy($GLOBALS['wp_dir'] . "/v2/manifest-$n.json", $GLOBALS['wp_dir'] . '/v2/live.json');        // "החלפת המצביע" — פעולה אחת
    update_option('v2_last', $n);
    update_option('v2_live', $n);
    return new WP_REST_Response(['ok' => true, 'version' => $n]);
}

/**
 * M2 — "הבדיקה המכרעת": KOL_V2_REAL=1 בסביבה (php tests/run.php / node tests/browser/m1.cjs) מריץ את הבדיקות של M1
 * **מול התוסף האמיתי** גם כשהן מדליקות את kol_v2_mock. המתאם רק מציג את המצב של התוסף באותם שמות שהבדיקות קוראות
 * (v2_live, v2_last, v2_puts, <dir>/v2/live.json, <dir>/v2/blobs/<sha>) ומכבד את הזרקת הכשל (v2_fail_after).
 * הקבלה, הבדיקות והכתיבה עצמן — בקוד של התוסף בלבד.
 */
function v2_real(string $action, WP_REST_Request $req)
{
    if ($action === 'asset_put') {
        $after = get_option('v2_fail_after', null);
        if ($after !== null && count((array)get_option('v2_puts', [])) >= (int)$after) { delete_option('v2_fail_after'); return kol_maroko_fail('boom', 'כשל מדומה באמצע', 500); }
    }
    $r = kol_maroko_handle($action, $req);
    if ($r->status !== 200) return $r;
    $b = json_decode((string)$req->get_body(), true);
    if ($action === 'asset_put' && !empty($r->data['done'])) {
        $sha = (string)$b['sha256'];
        @link(kol_maroko_v2_blob($sha), v2_dir() . "/$sha") || @copy(kol_maroko_v2_blob($sha), v2_dir() . "/$sha");
        v2_log('v2_puts', $sha);
    }
    if ($action === 'manifest' || $action === 'activate') {
        v2_dir();
        copy(kol_maroko_v2_dir() . '/live.json', $GLOBALS['wp_dir'] . '/v2/live.json');
        $man = json_decode((string)file_get_contents(kol_maroko_v2_dir() . '/live.json'), true);
        foreach ($man['files'] as $f) if (!is_file(v2_dir() . '/' . $f['sha256']) && ($src = kol_maroko_v2_blob($f['sha256']))) copy($src, v2_dir() . '/' . $f['sha256']);
        update_option('v2_last', (int)get_option('kol_maroko_v2_last', 0));
        update_option('v2_live', (int)get_option('kol_maroko_v2_live', 0));
    }
    return $r;
}

if (!preg_match('~^/wp-json/kol-maroko/v1/(ping|asset|publish|switch|asset_has|asset_put|manifest|activate)$~', (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) { http_response_code(404); exit; }
// B1: ניקוי באתר ממש לפני המניפסט — הגושים ב-v2_orphan נמחקים (גם אצל התוסף האמיתי). v2_orphan_keep — בכל מניפסט, לא פעם אחת
if ($m[1] === 'manifest' && ($orph = (array)get_option('v2_orphan', []))) {
    foreach ($orph as $sha) {
        @unlink($GLOBALS['wp_dir'] . "/v2/blobs/$sha");
        for ($i = 0; $i < 5 && function_exists('kol_maroko_v2_blob') && ($b = kol_maroko_v2_blob($sha)); $i++) @unlink($b);
    }
    if (!get_option('v2_orphan_keep')) delete_option('v2_orphan');
}
$r = get_option('kol_v2_mock') ? (getenv('KOL_V2_REAL') ? v2_real($m[1], new WP_REST_Request()) : v2_handle($m[1], new WP_REST_Request())) : kol_maroko_handle($m[1], new WP_REST_Request());
http_response_code($r->status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($r->data, JSON_UNESCAPED_UNICODE);
