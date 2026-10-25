<?php
/**
 * Plugin Name: קול מרוקו — קבלת גרסאות
 * Description: מקבל מסטודיו "קול מרוקו" גרסאות חתומות של עמוד אחד (Ed25519), בודק חתימה, זמן, nonce וגרסה, ומציג אותן בתבנית של העמוד הזה בלבד. בלי סיסמאות ובלי גישה מבחוץ לשום דבר אחר. כולל ייצוא של העמוד הקיים לסטודיו.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: הכלים הדיגיטליים
 * Text Domain: kol-maroko
 *
 * מה התוסף עושה — ורק את זה:
 *   POST /wp-json/kol-maroko/v1/ping     — בדיקת חיבור (חתומה). מחזיר את מספר הגרסה החיה.
 *   POST /wp-json/kol-maroko/v1/asset    — חלק של קובץ (חתום). נשמר ב-uploads/kol-maroko/<sha256>.<ext>.
 *   POST /wp-json/kol-maroko/v1/publish  — גרסה חדשה (חתומה). נשמרת באפשרויות; post_content לא נדרס.
 * כל בקשה נבדקת: חתימה מול המפתח הציבורי שהודבק בהגדרות, site = הדומיין הזה, page = lexicon, זמן ±5 דקות,
 * nonce שלא נראה, ולפרסום — מספר גרסה גבוה מהאחרון שהתקבל. שום נקודה לא מחזירה תוכן, משתמשים או קבצים.
 *
 * מסך ההגדרות (הגדרות ← קול מרוקו, מנהלים בלבד): המפתח הציבורי, העמוד, מתג "פעיל", הגרסאות שהתקבלו,
 * "חזרה לתוכן המקורי", ו"ייצוא העמוד הקיים" — קובץ ZIP עם התוכן, העיצוב והקבצים, להעלאה לסטודיו.
 */

if (!defined('ABSPATH')) exit;

define('KOL_MAROKO_VERSION', '1.0.0');
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
    foreach (['ping', 'asset', 'publish'] as $action) {
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
    $v = ['n' => $n, 'html' => (string)($b['html'] ?? ''), 'css' => (string)($b['css'] ?? ''), 'at' => time(),
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
    if (!$id || !is_page($id) || !kol_maroko_active_version()) return $template;
    return __DIR__ . '/template.php';
}, 99);

// אלמנטור לא מרנדר את העמוד כשהתבנית שלנו פעילה — אבל עמוד שיובא ממנו משתמש במחלקות שלו. לכן הסגנונות הבסיסיים שלו
add_action('wp_enqueue_scripts', function () {
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id || !is_page($id) || !kol_maroko_active_version() || !did_action('elementor/loaded')) return;
    $fe = \Elementor\Plugin::$instance->frontend;
    if (method_exists($fe, 'enqueue_styles')) $fe->enqueue_styles();
}, 20);

/** ה-HTML של הגרסה, עם הכתובת של הקבצים כאן. */
function kol_maroko_render()
{
    $v = kol_maroko_active_version();
    if (!$v) return '';
    $base = esc_url(kol_maroko_dir()['url']);
    $css = str_ireplace('</style', '', str_replace('{{KOL_ASSETS}}', $base, $v['css']));
    return '<style id="kol-maroko-css">' . $css . '</style>' . str_replace('{{KOL_ASSETS}}', $base, $v['html']);
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

    echo '<h2>ייצוא העמוד הקיים לסטודיו</h2><p>קובץ ZIP אחד: התוכן של העמוד כפי שהוא מוצג היום, העיצוב שלו (CSS), וכל הקבצים שהוא משתמש בהם — תמונות, הקלטות ומסמכים. מעלים אותו בסטודיו: ⚙️ הגדרות ← "ייבוא מהאתר".</p>';
    echo '<form method="post" action="' . $post . '">' . wp_nonce_field('kol_maroko_export', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="kol_maroko_export">';
    submit_button('⬇️ ייצוא העמוד', 'primary', 'submit', false);
    echo '</form></div>';
}

// ── ייצוא העמוד הקיים ─────────────────────────────────────

add_action('admin_post_kol_maroko_export', function () {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    check_admin_referer('kol_maroko_export');
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id) $id = (int)(get_page_by_path(KOL_MAROKO_PAGE)->ID ?? 0);
    if (!$id) wp_die('לא נמצא העמוד. בחרו אותו בהגדרות ושמרו.');
    if (!class_exists('ZipArchive')) wp_die('בשרת אין ZipArchive.');
    @set_time_limit(600);

    global $post;
    $post = get_post($id);
    setup_postdata($post);
    $builder = 'classic';
    if (did_action('elementor/loaded') && get_post_meta($id, '_elementor_edit_mode', true) === 'builder') {
        $builder = 'elementor';
        $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id, true);
    } else {
        $html = apply_filters('the_content', get_post_field('post_content', $id));
    }
    wp_reset_postdata();

    $up = wp_upload_dir();
    $css = [];
    if ($builder === 'elementor') {
        $kit = (int)get_option('elementor_active_kit');
        foreach (['post' => $id, 'kit' => $kit] as $k => $pid) {
            if (!$pid) continue;
            $f = $up['basedir'] . "/elementor/css/post-$pid.css";
            if (is_file($f)) $css[$k] = file_get_contents($f);
        }
        // הבסיס של אלמנטור — לתצוגה בסטודיו בלבד (באתר הוא נטען ממילא)
        foreach (['ELEMENTOR_ASSETS_PATH' => 'el', 'ELEMENTOR_PRO_ASSETS_PATH' => 'pro'] as $const => $p) {
            if (!defined($const)) continue;
            $dir = constant($const) . 'css/';
            foreach (array_merge([$dir . 'frontend.min.css'], glob($dir . 'widget-*.min.css') ?: []) as $f) {
                if (is_file($f)) $css['preview-' . $p . '-' . preg_replace('/[^a-z0-9_-]/', '', basename($f, '.min.css'))] = file_get_contents($f);
            }
        }
        $custom = get_post_meta($id, '_elementor_page_settings', true);
        if (is_array($custom) && !empty($custom['custom_css'])) $css['custom'] = (string)$custom['custom_css'];
    }

    // הקבצים: כל כתובת תחת uploads שמופיעה בתוכן או ב-CSS
    $media = [];
    $all = $html . "\n" . implode("\n", $css);
    $base = preg_quote(preg_replace('~^https?:~', '', $up['baseurl']), '~');
    if (preg_match_all('~(?:https?:)?' . $base . '/([^"\'\s<>()?#,]+)~i', $all, $m)) {
        foreach (array_unique($m[1]) as $rel) {
            $rel = rawurldecode($rel);
            if (strpos($rel, '..') !== false) continue;
            $path = $up['basedir'] . '/' . $rel;
            if (is_file($path) && filesize($path) <= KOL_MAROKO_MAX_FILE) $media[$rel] = $path;
        }
    }

    $zipPath = wp_tempnam('kol-maroko-export.zip');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('meta.json', wp_json_encode([
        'format' => 1, 'plugin' => KOL_MAROKO_VERSION, 'site' => home_url(), 'page_id' => $id, 'url' => get_permalink($id),
        'title' => get_the_title($id), 'builder' => $builder, 'uploads' => $up['baseurl'], 'exported_at' => gmdate('c'),
        'css' => array_keys($css), 'media' => array_keys($media),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->addFromString('content.html', (string)$html);
    foreach ($css as $k => $c) $zip->addFromString("css/$k.css", $c);
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
