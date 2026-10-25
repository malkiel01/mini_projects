<?php
/**
 * Plugin Name: קול מרוקו — קבלת גרסאות
 * Description: מקבל מסטודיו "קול מרוקו" גרסאות חתומות של עמוד אחד (Ed25519), בודק חתימה, זמן, nonce וגרסה, ומציג אותן בתבנית של העמוד הזה בלבד. בלי סיסמאות ובלי גישה מבחוץ לשום דבר אחר. כולל ייצוא של העמוד הקיים לסטודיו.
 * Version: 1.5.0
 * Requires PHP: 7.4
 * Author: הכלים הדיגיטליים
 * Text Domain: kol-maroko
 *
 * מה התוסף עושה — ורק את זה:
 *   POST /wp-json/kol-maroko/v1/ping     — בדיקת חיבור (חתומה). מחזיר את מספר הגרסה החיה.
 *   POST /wp-json/kol-maroko/v1/asset    — חלק של קובץ (חתום). נשמר ב-uploads/kol-maroko/<sha256>.<ext>.
 *   POST /wp-json/kol-maroko/v1/publish  — גרסה חדשה (חתומה). נשמרת באפשרויות; post_content לא נדרס.
 *   POST /wp-json/kol-maroko/v1/switch   — הפעלה או כיבוי של הגרסה מהסטודיו (חתומה). כיבוי = העמוד המקורי חוזר כמו שהיה.
 * כל בקשה נבדקת: חתימה מול המפתח הציבורי שהודבק בהגדרות, site = הדומיין הזה, page = lexicon, זמן ±5 דקות,
 * nonce שלא נראה, ולפרסום — מספר גרסה גבוה מהאחרון שהתקבל. שום נקודה לא מחזירה תוכן, משתמשים או קבצים.
 *
 * מסך ההגדרות (הגדרות ← קול מרוקו, מנהלים בלבד): המפתח הציבורי, העמוד, מתג "פעיל", הגרסאות שהתקבלו,
 * "חזרה לתוכן המקורי", ו"ייצוא העמוד הקיים" — קובץ ZIP עם התוכן, העיצוב והקבצים, להעלאה לסטודיו.
 */

if (!defined('ABSPATH')) exit;

define('KOL_MAROKO_VERSION', '1.5.0');
if (!defined('KOL_MAROKO_AUDIO_PART')) define('KOL_MAROKO_AUDIO_PART', 300 * 1024 * 1024);   // גודל של כל קובץ בייצוא ההקלטות
define('KOL_MAROKO_PAGE', 'lexicon');
define('KOL_MAROKO_SKEW', 300);
define('KOL_MAROKO_KEEP', 10);
define('KOL_MAROKO_MAX_FILE', 600 * 1024 * 1024);
define('KOL_MAROKO_EXTS', 'jpg,jpeg,png,gif,webp,mp3,m4a,aac,ogg,webm,wav,mp4,mov,pdf,docx,xlsx,pptx,zip,txt');

// ── עזרים ──────────────────────────────────────────────────

function kol_maroko_opt($k, $d = null) { return get_option('kol_maroko_' . $k, $d); }
function kol_maroko_set($k, $v) { update_option('kol_maroko_' . $k, $v, false); }

function kol_maroko_dir()
{
    $u = wp_upload_dir();
    return ['path' => $u['basedir'] . '/kol-maroko', 'url' => $u['baseurl'] . '/kol-maroko'];
}

/** התיקייה של הקבצים — עם חסימת הרצה (גם אם יגיע לשם משהו לא צפוי, הוא לא ירוץ). */
function kol_maroko_ensure_dir()
{
    $d = kol_maroko_dir()['path'];
    if (!is_dir($d)) wp_mkdir_p($d);
    if (!file_exists("$d/.htaccess")) {
        file_put_contents("$d/.htaccess", "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh|html?|svg|js)$\">\n  Require all denied\n</FilesMatch>\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n");
    }
    if (!file_exists("$d/index.php")) file_put_contents("$d/index.php", "<?php // silence\n");
    return $d;
}

/** המפתחות שהודבקו: שורות "<kid>:<base64>" (עד 2 — הישן והחדש בזמן החלפה). */
function kol_maroko_keys()
{
    $out = [];
    foreach (preg_split('/\s+/', (string)kol_maroko_opt('keys', '')) as $line) {
        if (preg_match('/^([A-Za-z0-9._-]{1,40}):([A-Za-z0-9+\/=]{40,64})$/', trim($line), $m)) {
            $k = base64_decode($m[2], true);
            if ($k !== false && strlen($k) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) $out[$m[1]] = $k;
        }
    }
    return array_slice($out, 0, 2, true);
}

function kol_maroko_host()
{
    return preg_replace('/^www\./', '', (string)wp_parse_url(home_url(), PHP_URL_HOST));
}

function kol_maroko_fail($code, $msg, $status = 400, $extra = [])
{
    return new WP_REST_Response(['ok' => false, 'code' => $code, 'message' => $msg] + $extra, $status);
}

// ── נקודות הקבלה ──────────────────────────────────────────

add_action('rest_api_init', function () {
    foreach (['ping', 'asset', 'publish', 'switch'] as $action) {
        register_rest_route('kol-maroko/v1', '/' . $action, [
            'methods' => 'POST',
            'permission_callback' => '__return_true',     // ההגנה היא החתימה (נבדקת ב-kol_maroko_verify)
            'callback' => function (WP_REST_Request $req) use ($action) { return kol_maroko_handle($action, $req); },
        ]);
    }
});

/** בדיקת הבקשה. מחזיר את הגוף המפוענח, או WP_REST_Response של דחייה. */
function kol_maroko_verify($action, WP_REST_Request $req)
{
    $body = (string)$req->get_body();
    if (strlen($body) > 8 * 1024 * 1024) return kol_maroko_fail('too_large', 'הבקשה גדולה מדי', 413);
    $keys = kol_maroko_keys();
    if (!$keys) return kol_maroko_fail('no_key', 'לא הודבק מפתח ציבורי', 403);
    $kid = (string)$req->get_header('x_kol_kid');
    $sig = base64_decode((string)$req->get_header('x_kol_signature'), true);
    if (!isset($keys[$kid]) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached($sig, $body, $keys[$kid])) {
        return kol_maroko_fail('bad_signature', 'החתימה לא תקינה', 401);
    }
    $b = json_decode($body, true);
    if (!is_array($b) || ($b['action'] ?? '') !== $action) return kol_maroko_fail('bad_request', 'בקשה לא תקינה', 400);
    if (($b['site'] ?? '') !== kol_maroko_host()) return kol_maroko_fail('wrong_site', 'האתר לא תואם', 403);
    if (($b['page'] ?? '') !== KOL_MAROKO_PAGE) return kol_maroko_fail('wrong_page', 'העמוד לא תואם', 403);
    $t = (int)($b['time'] ?? 0);
    if (abs(time() - $t) > KOL_MAROKO_SKEW) return kol_maroko_fail('stale', 'הזמן בבקשה רחוק מדי', 401);
    $nonce = (string)($b['nonce'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return kol_maroko_fail('bad_request', 'nonce לא תקין', 400);
    // nonce שנראה ב-10 הדקות האחרונות — נדחה. שמירה עם נעילה קלה (אפשרות אחת, נמחקת מעצמה)
    $seen = (array)kol_maroko_opt('nonces', []);
    foreach ($seen as $n => $at) if ($at < time() - 2 * KOL_MAROKO_SKEW) unset($seen[$n]);
    if (isset($seen[$nonce])) return kol_maroko_fail('replay', 'הבקשה כבר התקבלה', 401);
    $seen[$nonce] = time();
    kol_maroko_set('nonces', $seen);
    return $b;
}

function kol_maroko_handle($action, WP_REST_Request $req)
{
    $b = kol_maroko_verify($action, $req);
    if ($b instanceof WP_REST_Response) return $b;
    if ($action === 'ping') {
        return new WP_REST_Response(['ok' => true, 'version' => (int)kol_maroko_opt('last', 0), 'live' => (int)kol_maroko_opt('live', 0),
            'enabled' => (bool)kol_maroko_opt('enabled', false), 'page_set' => (bool)kol_maroko_opt('page_id', 0), 'plugin' => KOL_MAROKO_VERSION]);
    }
    if ($action === 'asset') return kol_maroko_asset($b);
    if ($action === 'switch') return kol_maroko_switch($b);
    return kol_maroko_publish($b);
}

/** חלק של קובץ. החלקים בסדר (part = מה שכבר התקבל); בסוף — בדיקת sha256 ושינוי שם. */
function kol_maroko_asset(array $b)
{
    $sha = (string)($b['sha256'] ?? '');
    $ext = strtolower((string)($b['ext'] ?? ''));
    $size = (int)($b['size'] ?? 0);
    $part = (int)($b['part'] ?? -1);
    $parts = (int)($b['parts'] ?? 0);
    if (!preg_match('/^[a-f0-9]{64}$/', $sha) || !in_array($ext, explode(',', KOL_MAROKO_EXTS), true)
        || $size <= 0 || $size > KOL_MAROKO_MAX_FILE || $parts < 1 || $part < 0 || $part >= $parts) {
        return kol_maroko_fail('bad_request', 'פרטי הקובץ לא תקינים', 400);
    }
    $dir = kol_maroko_ensure_dir();
    $final = "$dir/$sha.$ext";
    if (file_exists($final)) return new WP_REST_Response(['ok' => true, 'have' => true]);
    $tmp = "$dir/$sha.$ext.part";
    $meta = "$tmp.n";
    $got = $part === 0 ? 0 : (int)@file_get_contents($meta);
    if ($part === 0) @unlink($tmp);
    if ($part !== $got) return kol_maroko_fail('bad_part', 'חלק לא בסדר', 409, ['expect' => $got]);
    $data = base64_decode((string)($b['data'] ?? ''), true);
    if ($data === false) return kol_maroko_fail('bad_request', 'תוכן לא תקין', 400);
    file_put_contents($tmp, $data, FILE_APPEND);
    file_put_contents($meta, (string)($part + 1));
    if ($part + 1 < $parts) return new WP_REST_Response(['ok' => true, 'received' => $part + 1]);
    clearstatcache();
    if (filesize($tmp) !== $size || hash_file('sha256', $tmp) !== $sha) {
        @unlink($tmp); @unlink($meta);
        return kol_maroko_fail('bad_hash', 'הקובץ הגיע פגום', 422);
    }
    rename($tmp, $final);
    @unlink($meta);
    return new WP_REST_Response(['ok' => true, 'done' => true]);
}

function kol_maroko_publish(array $b)
{
    $n = (int)($b['version'] ?? 0);
    $last = (int)kol_maroko_opt('last', 0);
    if ($n <= $last) return kol_maroko_fail('old_version', 'יש גרסה חדשה יותר', 409, ['version' => $last]);
    $dir = kol_maroko_dir()['path'];
    foreach ((array)($b['assets'] ?? []) as $a) {
        if (!preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', (string)$a) || !file_exists("$dir/$a")) {
            return kol_maroko_fail('asset_missing', 'חסר קובץ: ' . $a, 409);
        }
    }
    $v = ['n' => $n, 'html' => kol_maroko_clean_html((string)($b['html'] ?? '')), 'css' => (string)($b['css'] ?? ''), 'at' => time(),
        'by' => sanitize_text_field((string)($b['published_by'] ?? ''))];
    kol_maroko_set('v_' . $n, $v);
    $list = (array)kol_maroko_opt('versions', []);
    $list[$n] = ['at' => $v['at'], 'by' => $v['by']];
    krsort($list);
    foreach (array_slice(array_keys($list), KOL_MAROKO_KEEP) as $old) { delete_option('kol_maroko_v_' . $old); unset($list[$old]); }
    kol_maroko_set('versions', $list);
    kol_maroko_set('last', $n);
    kol_maroko_set('live', $n);
    kol_maroko_purge();
    return new WP_REST_Response(['ok' => true, 'version' => $n, 'url' => kol_maroko_opt('page_id') ? get_permalink((int)kol_maroko_opt('page_id')) : null]);
}

/** מתג מהסטודיו: enabled=false מחזיר את העמוד המקורי (התבנית, המילים בוורדפרס — שלא נגעו בהם); true מציג שוב את הגרסה מהסטודיו. */
function kol_maroko_switch(array $b)
{
    $on = !empty($b['enabled']);
    if ($on && !kol_maroko_opt('page_id', 0)) return kol_maroko_fail('no_page', 'לא נבחר העמוד בהגדרות התוסף', 409);
    if ($on && !kol_maroko_opt('v_' . (int)kol_maroko_opt('live', 0))) return kol_maroko_fail('no_version', 'עוד לא התקבלה גרסה להציג', 409);
    kol_maroko_set('enabled', $on);
    kol_maroko_purge();
    return new WP_REST_Response(['ok' => true, 'enabled' => $on, 'live' => (int)kol_maroko_opt('live', 0)]);
}

/**
 * קו הגנה שני: גם גרסה חתומה לא מריצה קוד בדף. נמחקים script, object, embed, base, meta, link; מאפייני on*;
 * כתובות javascript: / data: (חוץ מתמונה). iframe נשאר רק מבודד (sandbox בלי allow-same-origin) או נגן של
 * יוטיוב / וימאו. הסטודיו כבר שולח כך — כאן זה למקרה שמישהו השתלט עליו.
 */
function kol_maroko_clean_html($html)
{
    if ($html === '') return '';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="kol-maroko-root">' . $html . '</div></body>', LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('kol-maroko-root');
    if (!$root) return '';
    foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
        if (!$el->parentNode) continue;
        $tag = strtolower($el->tagName);
        if (in_array($tag, ['script', 'object', 'embed', 'applet', 'base', 'meta', 'link', 'frame', 'frameset', 'portal'], true)) { $el->parentNode->removeChild($el); continue; }
        if ($tag === 'iframe') {
            $sb = $el->hasAttribute('sandbox') ? strtolower($el->getAttribute('sandbox')) : null;
            $player = preg_match('~^https://(www\.youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)~', $el->getAttribute('src'));
            if (!$player && ($sb === null || strpos($sb, 'allow-same-origin') !== false || strpos($sb, 'allow-top-navigation') !== false)) { $el->parentNode->removeChild($el); continue; }
        }
        foreach (iterator_to_array($el->attributes) as $a) {
            $n = strtolower($a->name);
            $u = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($a->value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $badUrl = in_array($n, ['href', 'src', 'action', 'formaction', 'poster', 'background', 'xlink:href', 'data'], true)
                && preg_match('/^(javascript|vbscript|data|blob):/', $u) && !preg_match('/^data:image\/(png|jpe?g|gif|webp);/', $u);
            if (strpos($n, 'on') === 0 || $badUrl || ($n === 'srcdoc' && $tag !== 'iframe') || in_array($n, ['formaction', 'http-equiv'], true)) $el->removeAttribute($a->name);
        }
    }
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

/** ניקוי מטמון של העמוד — וורדפרס ותוספי מטמון נפוצים, אם קיימים. */
function kol_maroko_purge()
{
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id) return;
    clean_post_cache($id);
    $url = get_permalink($id);
    do_action('litespeed_purge_post', $id);
    if (function_exists('rocket_clean_post')) rocket_clean_post($id);
    if (function_exists('w3tc_flush_post')) w3tc_flush_post($id);
    if (function_exists('wp_cache_post_change')) wp_cache_post_change($id);
    do_action('kol_maroko_published', $id, $url);
}

// ── הצגה: תבנית של העמוד הזה בלבד ─────────────────────────

function kol_maroko_active_version()
{
    if (!kol_maroko_opt('enabled', false)) return null;
    $n = (int)kol_maroko_opt('live', 0);
    $v = $n ? kol_maroko_opt('v_' . $n) : null;
    return is_array($v) ? $v : null;
}

add_filter('template_include', function ($template) {
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id || !is_page($id) || !kol_maroko_active_version() || isset($_GET['kol-maroko-original'])) return $template;
    return __DIR__ . '/template.php';
}, 99);

// אלמנטור לא מרנדר את העמוד כשהתבנית שלנו פעילה — אבל עמוד שיובא ממנו משתמש במחלקות שלו. לכן הסגנונות הבסיסיים שלו
add_action('wp_enqueue_scripts', function () {
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id || !is_page($id) || !kol_maroko_active_version() || !did_action('elementor/loaded')) return;
    $fe = \Elementor\Plugin::$instance->frontend;
    if (method_exists($fe, 'enqueue_styles')) $fe->enqueue_styles();
}, 20);

/**
 * קוד מהסטודיו לא רץ בדף של האתר (הכרעה 2026-10-25). שני סימונים בגרסה, למילון בלבד:
 *  - data-kol-css   — ה-CSS של המילון בגרסה (מנוקה וממוקד בסטודיו): ה-CSS של ערכת הנושא לא נטען בעמוד הזה.
 *    ה-JS של ערכת הנושא — כן (הוא שלה, לא מהסטודיו).
 *  - data-kol-frame — ה-JS נערך בסטודיו: המילון כולו במסגרת מבודדת (sandbox בלי allow-same-origin — בלי עוגיות,
 *    בלי גישה לדף, ל-wp-admin או ל-AJAX). ה-CSS וה-JS של ערכת הנושא לא נטענים. הקוד היחיד בדף — של התוסף הזה
 *    (קבוע): גובה המסגרת, מיקום החלונות הקופצים, ושליחת משוב על מילה — הפעולה האחת, בשדות קבועים.
 */
const KOL_MAROKO_HANDLES = ['kadisha-lexicon'];

function kol_maroko_marks()
{
    $v = kol_maroko_active_version();
    $out = ['css' => [], 'frame' => []];
    if (!$v) return $out;
    foreach (['css', 'frame'] as $k) {
        if (preg_match_all('/data-kol-' . $k . '="([a-z0-9_-]+)"/', $v['html'], $m)) $out[$k] = array_values(array_intersect(KOL_MAROKO_HANDLES, $m[1]));
    }
    return $out;
}

add_action('wp_enqueue_scripts', function () {
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id || !is_page($id) || isset($_GET['kol-maroko-original'])) return;
    $m = kol_maroko_marks();
    foreach (array_unique(array_merge($m['css'], $m['frame'])) as $h) wp_dequeue_style($h);
    foreach ($m['frame'] as $h) wp_dequeue_script($h);
}, 100);

/** הקוד הקבוע של התוסף למסגרת המבודדת. מקבל הודעות רק מהמסגרת עצמה. */
function kol_maroko_frame_bridge()
{
    $fb = function_exists('kadisha_lexicon_get') && !empty(kadisha_lexicon_get()['feedback_email']);
    $cfg = wp_json_encode(['ajax' => admin_url('admin-ajax.php'), 'nonce' => $fb ? wp_create_nonce('kadisha_lex_feedback') : '', 'feedback' => $fb]);
    return '<script>(function(C){var F=function(){return document.querySelector("iframe[data-kol-frame]")};'
        . 'var view=function(){var f=F();if(!f||!f.contentWindow)return;var r=f.getBoundingClientRect();f.contentWindow.postMessage({kolView:{top:-r.top,h:innerHeight}},"*")};'
        . 'addEventListener("scroll",view,{passive:true});addEventListener("resize",view);'
        . 'addEventListener("message",function(e){var f=F();if(!f||e.source!==f.contentWindow)return;var d=e.data||{};'
        . 'if(d.kolFrame){f.style.height=Math.min(400000,Math.max(200,+d.h||0))+"px";view();return;}'
        . 'if(!d.kolFeedback||!C.feedback)return;var x=d.kolFeedback,fd=new FormData();'
        . 'fd.append("action","kadisha_lex_send_feedback");fd.append("_nonce",C.nonce);'
        . '["word_id","name","email","message","website"].forEach(function(k){fd.append(k,String(x[k]==null?"":x[k]).slice(0,3000))});'
        . 'var rid=String(d.rid||"").slice(0,20),back=function(s,b){e.source.postMessage({kolFeedbackReply:rid,status:s,body:String(b).slice(0,2000)},"*")};'
        . 'fetch(C.ajax,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.text().then(function(t){back(r.status,t)})}).catch(function(){back(0,"")});'
        . '});})(' . $cfg . ');</script>';
}

/** ה-HTML של הגרסה, עם הכתובת של הקבצים כאן. */
function kol_maroko_render()
{
    $v = kol_maroko_active_version();
    if (!$v) return '';
    $base = esc_url(kol_maroko_dir()['url']);
    $css = str_ireplace('</style', '', str_replace('{{KOL_ASSETS}}', $base, $v['css']));
    $bridge = kol_maroko_marks()['frame'] ? kol_maroko_frame_bridge() : '';
    // קטעי קוד (מסגרות מבודדות) עם גובה אוטומטי — המסגרת מדווחת, והקוד הקבוע של התוסף מעדכן
    if (strpos($v['html'], 'data-kol-auto') !== false) {
        $bridge .= '<script>addEventListener("message",function(e){var d=e.data;if(!d||!d.kolCode)return;'
            . 'var f=document.querySelector(\'iframe[data-kol-code="\'+String(d.kolCode).replace(/[^a-z0-9]/g,"")+\'"]\');'
            . 'if(f&&e.source===f.contentWindow)f.style.height=Math.min(4000,Math.max(40,+d.h||0))+"px";});</script>';
    }
    return '<style id="kol-maroko-css">' . $css . '</style>' . str_replace('{{KOL_ASSETS}}', $base, $v['html']) . $bridge;
}

// ── מסך ההגדרות ───────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page('קול מרוקו', 'קול מרוקו', 'manage_options', 'kol-maroko', 'kol_maroko_admin');
});

add_action('admin_post_kol_maroko_save', function () {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    check_admin_referer('kol_maroko_save');
    $op = sanitize_key($_POST['op'] ?? 'save');
    if ($op === 'save') {
        kol_maroko_set('keys', trim(sanitize_textarea_field(wp_unslash($_POST['keys'] ?? ''))));
        kol_maroko_set('page_id', (int)($_POST['page_id'] ?? 0));
        kol_maroko_set('enabled', !empty($_POST['enabled']));
    } elseif ($op === 'original') {
        kol_maroko_set('enabled', false);
    } elseif ($op === 'show' && ($n = (int)($_POST['n'] ?? 0)) && kol_maroko_opt('v_' . $n)) {
        kol_maroko_set('live', $n);                     // הצגה של גרסה קודמת שהתקבלה. "last" לא משתנה
        kol_maroko_set('enabled', true);
    }
    kol_maroko_purge();
    wp_safe_redirect(admin_url('options-general.php?page=kol-maroko&saved=1'));
    exit;
});

function kol_maroko_admin()
{
    if (!current_user_can('manage_options')) return;
    $keys = kol_maroko_keys();
    $live = (int)kol_maroko_opt('live', 0);
    $versions = (array)kol_maroko_opt('versions', []);
    $nonce = wp_nonce_field('kol_maroko_save', '_wpnonce', true, false);
    $post = esc_url(admin_url('admin-post.php'));
    echo '<div class="wrap" dir="rtl"><h1>קול מרוקו</h1>';
    if (!empty($_GET['saved'])) echo '<div class="notice notice-success"><p>נשמר.</p></div>';
    if (!function_exists('sodium_crypto_sign_verify_detached')) echo '<div class="notice notice-error"><p>חסרה ב-PHP הפונקציה sodium — התוסף לא יוכל לבדוק חתימות.</p></div>';
    echo '<p>הסטודיו "קול מרוקו" שולח לכאן גרסאות חתומות של עמוד אחד. לסטודיו אין סיסמה, משתמש או גישה לאתר — רק את מה שהוא חותם.</p>';
    echo '<form method="post" action="' . $post . '">' . $nonce . '<input type="hidden" name="action" value="kol_maroko_save"><input type="hidden" name="op" value="save">';
    echo '<table class="form-table"><tr><th>המפתח הציבורי</th><td><textarea name="keys" rows="3" cols="70" dir="ltr" style="font-family:monospace">'
        . esc_textarea((string)kol_maroko_opt('keys', '')) . '</textarea><p class="description">מהסטודיו: ⚙️ הגדרות ← "המפתח הציבורי". בהחלפת מפתח — שתי שורות. '
        . ($keys ? 'תקינים: ' . esc_html(implode(', ', array_keys($keys))) : '<b>עוד לא הודבק מפתח תקין.</b>') . '</p></td></tr>';
    echo '<tr><th>העמוד</th><td>' . wp_dropdown_pages(['name' => 'page_id', 'selected' => (int)kol_maroko_opt('page_id', 0), 'show_option_none' => '— לבחור —', 'echo' => 0])
        . '<p class="description">העמוד שהגרסאות מחליפות (lexicon).</p></td></tr>';
    echo '<tr><th>פעיל</th><td><label><input type="checkbox" name="enabled" value="1"' . checked((bool)kol_maroko_opt('enabled', false), true, false)
        . '> להציג בעמוד את הגרסה מהסטודיו</label><p class="description">כבוי — העמוד מוצג כמו שהוא היום. התוכן המקורי לא נמחק אף פעם.</p></td></tr></table>';
    submit_button('שמירה');
    echo '</form>';

    echo '<h2>גרסאות שהתקבלו</h2>';
    if (!$versions) echo '<p>עוד לא התקבלה גרסה.</p>';
    else {
        echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>גרסה</th><th>מתי</th><th>מי</th><th></th></tr></thead><tbody>';
        foreach ($versions as $n => $m) {
            echo '<tr><td>' . (int)$n . ($n === $live && kol_maroko_opt('enabled') ? ' <b>(מוצגת)</b>' : '') . '</td><td>' . esc_html(wp_date('d.m.Y H:i', (int)$m['at'])) . '</td><td>' . esc_html($m['by']) . '</td><td>';
            if ($n !== $live || !kol_maroko_opt('enabled')) {
                echo '<form method="post" action="' . $post . '" style="margin:0">' . $nonce . '<input type="hidden" name="action" value="kol_maroko_save"><input type="hidden" name="op" value="show"><input type="hidden" name="n" value="' . (int)$n . '"><button class="button">להציג אותה</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<form method="post" action="' . $post . '" style="margin-top:16px">' . $nonce . '<input type="hidden" name="action" value="kol_maroko_save"><input type="hidden" name="op" value="original">';
    submit_button('חזרה לתוכן המקורי (כיבוי)', 'secondary', 'submit', false);
    echo '</form>';

    echo '<h2>ייצוא העמוד הקיים לסטודיו</h2><p>קובץ ZIP אחד: העמוד כפי שהמבקר רואה אותו, העיצוב שלו, ההגדרות והמבוא של המילון וכל המילים עם הנושאים שלהן. ההקלטות נשארות כאן באתר — הסטודיו מפנה אליהן. מעלים אותו בסטודיו: ⚙️ הגדרות ← "ייבוא מהאתר".</p>';
    echo '<form method="post" action="' . $post . '">' . wp_nonce_field('kol_maroko_export', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="kol_maroko_export">';
    submit_button('⬇️ ייצוא העמוד', 'primary', 'submit', false);
    echo '</form>';

    // ההקלטות של המילים — בכמה קבצים, כדי שכל אחד יירד ויעלה בלי להיתקע
    $audio = kol_maroko_audio_files();
    if ($audio) {
        $parts = kol_maroko_audio_parts($audio);
        $total = array_sum(array_column($audio, 'size'));
        echo '<h2>🎙️ ייצוא ההקלטות לסטודיו</h2><p>' . count($audio) . ' הקלטות, ' . esc_html(size_format($total)) . ', ב-' . count($parts)
            . ' קבצים. מורידים את כולם ומעלים בסטודיו: ⚙️ הגדרות ← "ייבוא ההקלטות" (אפשר לבחור את כולם יחד). כל הקלטה נקשרת למילה שלה.</p><p>';
        foreach ($parts as $i => $p) {
            echo '<form method="post" action="' . $post . '" style="display:inline-block;margin:0 0 8px 8px">' . wp_nonce_field('kol_maroko_export_audio', '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="kol_maroko_export_audio"><input type="hidden" name="part" value="' . (int)$i . '">'
                . '<button class="button">⬇️ חלק ' . ($i + 1) . ' מתוך ' . count($parts) . ' (' . esc_html(size_format(array_sum(array_column($p, 'size')))) . ')</button></form>';
        }
        echo '</p>';
    }

    echo '<h2 id="kol-diag">🔎 אבחון</h2><p>איך העמוד בנוי: התבניות, סוגי התוכן, והרכיבים שהמבקר רואה. מעתיקים את כל הטקסט בתיבה ושולחים לצ\'אט של קול מרוקו.</p>';
    echo '<form method="post" action="' . $post . '">' . wp_nonce_field('kol_maroko_diagnose', '_wpnonce', true, false) . '<input type="hidden" name="action" value="kol_maroko_diagnose">';
    submit_button('🔎 הפקת אבחון', 'secondary', 'submit', false);
    echo '</form>';
    $diag = get_transient('kol_maroko_diag');
    if ($diag) {
        echo '<textarea readonly rows="18" dir="ltr" style="width:100%;max-width:900px;font:12px monospace" onclick="this.select()">'
            . esc_textarea(wp_json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . '</textarea>';
    }
    echo '</div>';
}

// ── ייצוא העמוד הקיים ─────────────────────────────────────
//
// גרסה 1.0 ייצאה רק את הטקסט ששמור בתוך העמוד — ובאתר מגרב העמוד מציג את רשומות "קול מרוקו" (סוג תוכן של
// JetEngine) דרך תבנית, כך שכמעט כלום לא יצא. מ-1.1: (1) העמוד כפי שהמבקר רואה אותו — בקשה מהשרת לעצמו, ואם
// היא נכשלת, רינדור של התבנית של אלמנטור; (2) הרשומות עצמן כנתונים, עם הקבצים שלהן; (3) דוח אבחון.

/** סוגי התוכן שהעמוד מציג: שהשם שלהם מזכיר מרוקו, או שהרשומות שלהם מופיעות ב-HTML של העמוד. */
function kol_maroko_entry_types($html = '')
{
    $out = [];
    foreach (get_post_types(['_builtin' => false], 'objects') as $pt) {
        if (in_array($pt->name, ['elementor_library', 'jet-engine', 'jet-smart-filters', 'jet-theme-core', 'jet-engine-booking', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'], true)) continue;
        $label = $pt->label . ' ' . ($pt->labels->singular_name ?? '') . ' ' . $pt->name;
        $hit = preg_match('/מרוק|maroc|morocc|kol|lexic|מילון|לקסיקון/iu', $label);
        if (!$hit && $pt->name === 'kadisha_word') $hit = true;
        if (!$hit && $html !== '' && preg_match('/\b(?:post-type-|type-)' . preg_quote($pt->name, '/') . '\b/', $html)) $hit = true;
        if ($hit) $out[] = $pt->name;
    }
    return $out;
}

/** העמוד כפי שהמבקר רואה: בקשה לעצמנו (בלי התבנית שלנו), ואם לא — רינדור בשרת. */
function kol_maroko_render_page($id)
{
    $url = add_query_arg(['kol-maroko-original' => '1', 'nocache' => time()], get_permalink($id));
    $r = wp_remote_get($url, ['timeout' => 25, 'sslverify' => false, 'redirection' => 3, 'headers' => ['Cache-Control' => 'no-cache']]);
    $code = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
    $body = is_wp_error($r) ? '' : (string)wp_remote_retrieve_body($r);
    if ($code === 200 && stripos($body, '<body') !== false) {
        return ['source' => 'loopback', 'full' => $body, 'error' => null];
    }
    $err = is_wp_error($r) ? $r->get_error_message() : "HTTP $code";
    // רינדור בשרת: התבניות של אלמנטור פרו שחלות על עמוד יחיד, ואחריהן התוכן של העמוד
    global $post, $wp_query;
    $post = get_post($id);
    $wp_query = new WP_Query(['page_id' => $id]);
    $wp_query->the_post();
    $html = '';
    try {
        if (class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
            $docs = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_documents_for_location('single');
            foreach ((array)$docs as $doc_id => $doc) $html .= \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($doc_id, true);
        }
        if (did_action('elementor/loaded') && get_post_meta($id, '_elementor_edit_mode', true) === 'builder') {
            $html .= \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id, true);
        } elseif (strpos($html, 'elementor-widget-theme-post-content') === false) {
            $html .= apply_filters('the_content', get_post_field('post_content', $id));
        }
    } catch (Throwable $e) {
        $err .= ' · ' . $e->getMessage();
    }
    wp_reset_postdata();
    return ['source' => 'server', 'full' => '<html><body>' . $html . '</body></html>', 'error' => $err];
}

/** מתוך עמוד מלא: אזור התוכן (מסמכי אלמנטור שאינם כותרת / תחתית / חלון קופץ), ו-CSS של אלמנטור מה-head. */
function kol_maroko_extract($full)
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $full);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $parts = [];
    foreach ($xp->query('//*[@data-elementor-type]') as $el) {
        $type = $el->getAttribute('data-elementor-type');
        if (in_array($type, ['header', 'footer', 'popup'], true)) continue;
        // רק מסמך עליון (לא מסמך שמקונן בתוך מסמך שכבר נלקח)
        for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) if ($p->hasAttribute('data-elementor-type') && !in_array($p->getAttribute('data-elementor-type'), ['header', 'footer', 'popup'], true)) continue 2;
        $parts[] = $doc->saveHTML($el);
    }
    if (!$parts) {
        $main = $xp->query('//main')->item(0) ?: $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]')->item(0) ?: $xp->query('//body')->item(0);
        if ($main) foreach ($main->childNodes as $c) $parts[] = $doc->saveHTML($c);
    }
    $css = [];
    $links = [];
    foreach ($xp->query('//style') as $i => $st) {
        $id = $st->getAttribute('id');
        if ($id === '' || preg_match('/elementor|jet|kit|post-\d|global/i', $id)) $css['inline-' . $i] = $st->textContent;
    }
    foreach ($xp->query('//link[@rel="stylesheet"]') as $l) $links[] = $l->getAttribute('href');
    return ['html' => implode("\n", $parts), 'css' => $css, 'links' => $links];
}

/** רשומות של סוג תוכן: שדות, מונחים, תמונה ראשית — וכל קובץ שהן מפנות אליו. */
function kol_maroko_entries($types, &$urls)
{
    $out = [];
    $skip = '/^(_edit_|_wp_old|_wp_trash|_encloseme|_pingme|_elementor_css|_elementor_page_assets|_oembed)/';
    foreach ($types as $t) {
        $q = get_posts(['post_type' => $t, 'post_status' => ['publish', 'draft', 'private', 'pending'], 'numberposts' => 5000, 'orderby' => 'menu_order title', 'order' => 'ASC']);
        foreach ($q as $p) {
            $meta = [];
            foreach (get_post_meta($p->ID) as $k => $vals) {
                if (preg_match($skip, $k)) continue;
                $v = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
                $meta[$k] = $v;
            }
            $terms = [];
            foreach (get_object_taxonomies($t) as $tax) {
                $tt = wp_get_post_terms($p->ID, $tax, ['fields' => 'names']);
                if ($tt && !is_wp_error($tt)) $terms[$tax] = $tt;
            }
            $thumb = get_the_post_thumbnail_url($p->ID, 'full');
            // קבצים: מזהי קבצים בשדות → כתובות; וכל כתובת uploads בשדות ובתוכן
            $files = [];
            array_walk_recursive($meta, function ($v) use (&$files) {
                if (is_numeric($v) && (int)$v > 0 && get_post_type((int)$v) === 'attachment') $files[(int)$v] = wp_get_attachment_url((int)$v);
                if (is_string($v) && preg_match_all('~https?://[^"\'\s<>]+/wp-content/uploads/[^"\'\s<>?#]+~i', $v, $m)) foreach ($m[0] as $u) $files[$u] = $u;
            });
            if (preg_match_all('~https?://[^"\'\s<>]+/wp-content/uploads/[^"\'\s<>?#]+~i', $p->post_content, $m)) foreach ($m[0] as $u) $files[$u] = $u;
            foreach (get_attached_media('', $p->ID) as $a) $files[$a->ID] = wp_get_attachment_url($a->ID);
            if ($thumb) $files['thumb'] = $thumb;
            foreach ($files as $u) if ($u) $urls[] = $u;
            $out[] = ['id' => $p->ID, 'type' => $t, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status,
                'date' => $p->post_date_gmt, 'order' => $p->menu_order, 'link' => get_permalink($p->ID), 'content' => $p->post_content,
                'excerpt' => $p->post_excerpt, 'thumbnail' => $thumb ?: null, 'terms' => $terms, 'meta' => $meta,
                'files' => array_values(array_filter(array_unique($files)))];
        }
    }
    return $out;
}

/**
 * עמוד המילון של התבנית (page-kadisha-lexicon.php) בנוי מהגדרות ומרשומות kadisha_word — לא מאלמנטור. מ-1.2 הם
 * יוצאים כנתונים: ההגדרות, המבוא, כל המילים והנושאים. ההקלטות לא נכנסות לקובץ (1,500 קבצים) — רק הכתובות
 * שלהן כאן באתר, והסטודיו מפנה אליהן. ה-CSS וה-JS של התבנית — לתצוגה בסטודיו בלבד (באתר הם נטענים ממילא).
 */
function kol_maroko_lexicon_data()
{
    if (!function_exists('kadisha_lexicon_get') || !function_exists('kadisha_lexicon_get_all_words')) return null;
    $s = kadisha_lexicon_get();
    $intro = function_exists('kadisha_lexicon_build_intro_html') ? kadisha_lexicon_build_intro_html($s) : (string)($s['intro_he'] ?? '');
    $words = [];
    foreach (kadisha_lexicon_get_all_words() as $w) {
        $words[] = ['id' => (int)$w['id'], 'word' => (string)$w['word'], 'meaning' => (string)$w['meaning'],
            'topics' => array_values((array)$w['topics']), 'audio_url' => (string)$w['audio_url'], 'external_id' => (string)($w['external_id'] ?? '')];
    }
    $topics = function_exists('kadisha_lexicon_get_all_topics') ? kadisha_lexicon_get_all_topics() : [];
    $hero = (string)($s['hero_background_image'] ?? '');
    $overlay = (int)($s['hero_background_overlay'] ?? 0);
    if ($hero === '' && function_exists('kadisha_get_settings')) {
        $kc = kadisha_get_settings();
        $hero = (string)($kc['hero_background_image'] ?? '');
        $overlay = (int)($kc['hero_background_overlay'] ?? 0);
    }
    unset($s['feedback_email']);                     // כתובת המייל נשארת באתר; הסטודיו צריך רק לדעת שהמשוב פעיל
    return ['settings' => $s, 'intro_html' => $intro, 'hero_image' => $hero, 'hero_overlay' => $overlay,
        'feedback' => !empty(kadisha_lexicon_get()['feedback_email']),
        'show_external_id' => defined('KADISHA_LEXICON_SHOW_EXTERNAL_ID') && KADISHA_LEXICON_SHOW_EXTERNAL_ID,
        'words' => $words, 'topics' => $topics];
}

/** ההקלטות של המילים: הכתובת, הקובץ בשרת והמילה. רק קבצים שקיימים תחת uploads. */
function kol_maroko_audio_files()
{
    if (!function_exists('kadisha_lexicon_get_all_words')) return [];
    $up = wp_upload_dir();
    $base = preg_replace('~^https?:~', '', untrailingslashit($up['baseurl'])) . '/';
    $out = [];
    foreach (kadisha_lexicon_get_all_words() as $w) {
        $u = (string)($w['audio_url'] ?? '');
        $bare = preg_replace('~^https?:~', '', $u);
        if ($u === '' || strpos($bare, $base) !== 0) continue;
        $rel = rawurldecode(substr($bare, strlen($base)));
        $path = $up['basedir'] . '/' . $rel;
        if (strpos($rel, '..') !== false || !is_file($path)) continue;
        $out[] = ['url' => $u, 'word_id' => (int)$w['id'], 'word' => (string)$w['word'], 'rel' => $rel, 'path' => $path, 'size' => (int)filesize($path)];
    }
    usort($out, fn($a, $b) => $a['word_id'] <=> $b['word_id']);   // סדר קבוע — כל חלק מכיל תמיד את אותם קבצים
    return $out;
}

/** חלוקה לחלקים של עד KOL_MAROKO_AUDIO_PART. */
function kol_maroko_audio_parts(array $files)
{
    $parts = [[]];
    $size = 0;
    foreach ($files as $f) {
        if ($size > 0 && $size + $f['size'] > KOL_MAROKO_AUDIO_PART) { $parts[] = []; $size = 0; }
        $parts[count($parts) - 1][] = $f;
        $size += $f['size'];
    }
    return $files ? $parts : [];
}

add_action('admin_post_kol_maroko_export_audio', function () {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    check_admin_referer('kol_maroko_export_audio');
    if (!class_exists('ZipArchive')) wp_die('בשרת אין ZipArchive.');
    @set_time_limit(1800);
    $parts = kol_maroko_audio_parts(kol_maroko_audio_files());
    $i = (int)($_POST['part'] ?? 0);
    if (!isset($parts[$i])) wp_die('החלק לא נמצא. רעננו את העמוד ונסו שוב.');
    $zipPath = wp_tempnam('kol-maroko-audio.zip');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $list = [];
    foreach ($parts[$i] as $f) {
        $zip->addFile($f['path'], 'audio/' . $f['rel']);
        $zip->setCompressionName('audio/' . $f['rel'], ZipArchive::CM_STORE);
        $list[] = ['url' => $f['url'], 'file' => 'audio/' . $f['rel'], 'word_id' => $f['word_id'], 'word' => $f['word']];
    }
    $zip->addFromString('audio.json', wp_json_encode(['format' => 'kol-audio', 'plugin' => KOL_MAROKO_VERSION, 'site' => home_url(),
        'part' => $i + 1, 'parts' => count($parts), 'files' => $list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->close();
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="kol-maroko-audio-' . ($i + 1) . '-of-' . count($parts) . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
});

/** קובץ של התבנית הפעילה, עם כתובות url(...) יחסיות שהופכות למלאות (כדי שיעבדו גם בתצוגה בסטודיו). */
function kol_maroko_theme_file($rel)
{
    $f = get_stylesheet_directory() . '/' . $rel;
    if (!is_file($f)) return null;
    $c = (string)file_get_contents($f);
    if (substr($rel, -4) !== '.css') return $c;
    $base = trailingslashit(get_stylesheet_directory_uri() . '/' . dirname($rel));
    return preg_replace_callback('~url\(\s*([\'"]?)(?!data:|https?:|//|#)([^\'")]+)\1\s*\)~i', fn($m) => 'url(' . $m[1] . $base . $m[2] . $m[1] . ')', $c);
}

/** דוח אבחון: איך העמוד בנוי. מוצג במסך ההגדרות ונכנס לייצוא. */
function kol_maroko_diagnose($id, $render = null)
{
    $d = ['plugin' => KOL_MAROKO_VERSION, 'wp' => get_bloginfo('version'), 'php' => PHP_VERSION, 'site' => home_url(),
        'theme' => get_stylesheet() . (get_template() !== get_stylesheet() ? ' (parent ' . get_template() . ')' : ''),
        'block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme()];
    $p = $id ? get_post($id) : null;
    $d['page'] = $p ? ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'url' => get_permalink($p->ID), 'status' => $p->post_status,
        'template' => get_post_meta($p->ID, '_wp_page_template', true), 'elementor_mode' => get_post_meta($p->ID, '_elementor_edit_mode', true),
        'elementor_data_len' => strlen((string)get_post_meta($p->ID, '_elementor_data', true)), 'content_len' => strlen($p->post_content),
        'shortcodes' => preg_match_all('/\[([a-z0-9_-]+)/i', $p->post_content, $m) ? array_values(array_unique($m[1])) : []] : null;
    $d['active_plugins'] = array_values(array_map(fn($f) => dirname($f) === '.' ? $f : dirname($f), (array)get_option('active_plugins', [])));
    $d['post_types'] = [];
    foreach (get_post_types(['_builtin' => false], 'objects') as $pt) {
        $c = wp_count_posts($pt->name);
        $d['post_types'][] = ['name' => $pt->name, 'label' => $pt->label, 'publish' => (int)($c->publish ?? 0), 'public' => (bool)$pt->public];
    }
    $d['entry_types'] = kol_maroko_entry_types($render['full'] ?? '');
    // תבניות אלמנטור (Theme Builder / JetThemeCore) ותנאיהן
    $d['templates'] = [];
    foreach (get_posts(['post_type' => ['elementor_library', 'jet-theme-core'], 'numberposts' => 80, 'post_status' => 'publish']) as $t) {
        $d['templates'][] = ['id' => $t->ID, 'title' => $t->post_title, 'type' => get_post_meta($t->ID, '_elementor_template_type', true),
            'conditions' => get_post_meta($t->ID, '_elementor_conditions', true) ?: get_post_meta($t->ID, '_jet_template_conditions', true) ?: null];
    }
    if ($render) {
        $h = $render['full'];
        $d['render'] = ['source' => $render['source'], 'error' => $render['error'], 'bytes' => strlen($h),
            'audio' => substr_count(strtolower($h), '<audio'), 'img' => substr_count(strtolower($h), '<img'),
            'elementor_docs' => preg_match_all('/data-elementor-type="([^"]+)"[^>]*data-elementor-id="(\d+)"/', $h, $m, PREG_SET_ORDER) ? array_map(fn($x) => $x[1] . '#' . $x[2], array_slice($m, 0, 40)) : [],
            'widgets' => preg_match_all('/data-widget_type="([^"]+)"/', $h, $m) ? array_count_values($m[1]) : []];
    }
    return $d;
}

function kol_maroko_page_id()
{
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id) { $p = get_page_by_path(KOL_MAROKO_PAGE); $id = $p ? (int)$p->ID : 0; }
    return $id;
}

add_action('admin_post_kol_maroko_diagnose', function () {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    check_admin_referer('kol_maroko_diagnose');
    $id = kol_maroko_page_id();
    $d = kol_maroko_diagnose($id, $id ? kol_maroko_render_page($id) : null);
    set_transient('kol_maroko_diag', $d, HOUR_IN_SECONDS);
    wp_safe_redirect(admin_url('options-general.php?page=kol-maroko#kol-diag'));
    exit;
});

add_action('admin_post_kol_maroko_export', function () {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    check_admin_referer('kol_maroko_export');
    $id = kol_maroko_page_id();
    if (!$id) wp_die('לא נמצא העמוד. בחרו אותו בהגדרות ושמרו.');
    if (!class_exists('ZipArchive')) wp_die('בשרת אין ZipArchive.');
    @set_time_limit(1800);
    @ini_set('memory_limit', '1024M');

    $render = kol_maroko_render_page($id);
    $ex = kol_maroko_extract($render['full']);
    $html = $ex['html'];
    $up = wp_upload_dir();
    $css = $ex['css'];
    // קובצי ה-CSS של אלמנטור שהעמוד טוען (העמוד, התבנית, פריטי הרשימה של JetEngine, הערכה)
    foreach ($ex['links'] as $href) {
        if (!preg_match('~/wp-content/uploads/(elementor/css/[a-z0-9_.-]+\.css)~i', $href, $m)) continue;
        $f = $up['basedir'] . '/' . $m[1];
        if (is_file($f)) $css[preg_replace('/[^a-z0-9_-]/', '', basename($m[1], '.css'))] = file_get_contents($f);
    }
    $custom = get_post_meta($id, '_elementor_page_settings', true);
    if (is_array($custom) && !empty($custom['custom_css'])) $css['custom'] = (string)$custom['custom_css'];
    // הבסיס של אלמנטור ושל JetEngine — לתצוגה בסטודיו בלבד (באתר הם נטענים ממילא)
    foreach (['ELEMENTOR_ASSETS_PATH' => 'el', 'ELEMENTOR_PRO_ASSETS_PATH' => 'pro'] as $const => $p) {
        if (!defined($const)) continue;
        $dir = constant($const) . 'css/';
        foreach (array_merge([$dir . 'frontend.min.css'], glob($dir . 'widget-*.min.css') ?: []) as $f) {
            if (is_file($f)) $css['preview-' . $p . '-' . preg_replace('/[^a-z0-9_-]/', '', basename($f, '.min.css'))] = file_get_contents($f);
        }
    }
    if (defined('JET_ENGINE_PATH') && is_file(JET_ENGINE_PATH . 'assets/css/frontend.css')) $css['preview-jetengine'] = file_get_contents(JET_ENGINE_PATH . 'assets/css/frontend.css');

    $lex = kol_maroko_lexicon_data();
    $js = [];
    if ($lex) {
        // התבנית של המילון: העיצוב שלה לתצוגה בסטודיו, וההתנהגות (חיפוש, נושאים, שמע) — אותו קובץ
        foreach (['kadisha-assets/style.css' => 'preview-kadisha-style', 'kadisha-assets/lexicon.css' => 'preview-kadisha-lexicon'] as $rel => $k) {
            $c = kol_maroko_theme_file($rel);
            if ($c !== null) $css[$k] = $c;
        }
        $c = kol_maroko_theme_file('kadisha-assets/lexicon.js');
        if ($c !== null) $js['lexicon'] = $c;
    }
    // המילים כבר בנתוני המילון (עם כתובות ההקלטות) — לא כרשומות גולמיות ולא כקבצים
    $types = $lex ? array_values(array_diff(kol_maroko_entry_types($render['full']), ['kadisha_word'])) : kol_maroko_entry_types($render['full']);
    $urls = [];
    $entries = kol_maroko_entries($types, $urls);

    // הקבצים: כל כתובת תחת uploads שמופיעה בתוכן, ב-CSS או ברשומות (בעמוד המילון — לא מהעמוד: שם כל ההקלטות)
    $media = [];
    $all = ($lex ? '' : $html) . "\n" . implode("\n", $css) . "\n" . implode("\n", $urls);
    $base = preg_quote(preg_replace('~^https?:~', '', $up['baseurl']), '~');
    if (preg_match_all('~(?:https?:)?' . $base . '/([^"\'\s<>()?#,]+)~i', $all, $m)) {
        foreach (array_unique($m[1]) as $rel) {
            $rel = rawurldecode($rel);
            if (strpos($rel, '..') !== false || strpos($rel, 'elementor/css/') === 0) continue;
            $path = $up['basedir'] . '/' . $rel;
            if (is_file($path) && filesize($path) <= KOL_MAROKO_MAX_FILE) $media[$rel] = $path;
        }
    }

    $zipPath = wp_tempnam('kol-maroko-export.zip');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('meta.json', wp_json_encode([
        'format' => 2, 'plugin' => KOL_MAROKO_VERSION, 'site' => home_url(), 'page_id' => $id, 'url' => get_permalink($id),
        'title' => get_the_title($id), 'builder' => strpos($html, 'data-elementor-type') !== false ? 'elementor' : 'classic',
        'source' => $render['source'], 'uploads' => $up['baseurl'], 'exported_at' => gmdate('c'),
        'css' => array_keys($css), 'js' => array_keys($js), 'media' => array_keys($media), 'entry_types' => $types, 'entries' => count($entries),
        'lexicon' => $lex ? count($lex['words']) : null,
        'diagnostics' => kol_maroko_diagnose($id, $render),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->addFromString('content.html', (string)$html);
    $zip->addFromString('page-full.html', (string)$render['full']);
    $zip->addFromString('data/entries.json', wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach ($css as $k => $c) $zip->addFromString("css/$k.css", $c);
    foreach ($js as $k => $c) $zip->addFromString("js/$k.js", $c);
    if ($lex) $zip->addFromString('data/lexicon.json', wp_json_encode($lex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach ($media as $rel => $path) {
        $zip->addFile($path, 'media/' . $rel);
        $zip->setCompressionName('media/' . $rel, ZipArchive::CM_STORE);   // מדיה כבר דחוסה
    }
    $zip->close();

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="kol-maroko-export-' . gmdate('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
});
