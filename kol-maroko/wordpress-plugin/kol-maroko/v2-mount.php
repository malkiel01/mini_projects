<?php
/**
 * קול מרוקו 2.1 — אופני ההצבה (פרוסה M4-BC; 00 §7, I8; 07 §7): העמוד כחלון פנימי בתוך האתר.
 *
 *   full   (M-A) — החבילה היא המסמך העליון. כמו 2.0; ברירת המחדל.
 *   framed (M-C) — מסמך מעטפת של התוסף: הכותרת של ערכת הנושא, iframe בגובה התוכן, והתחתית של ערכת הנושא.
 *   bar    (M-B) — הכותרת של ערכת הנושא, ומתחתיה iframe שממלא את שאר המסך וגולל בתוכו. בלי התחתית.
 *
 * ההגדרה עוברת בפעולה חתומה `mount` (אותה חתימה של activate) ונשמרת ב-kol_maroko_v2_mount. שינוי — מיידי, בלי פרסום.
 *
 * **מסמך המעטפת הוא קוד קבוע של התוסף, לא של בעל העמוד.** החבילה עצמה לא משתנה בשום אופן, ואינה נכנסת למסמך של האתר:
 *  - ה-iframe: sandbox **בלי allow-same-origin** (מקור אטום — בלי עוגיות, אחסון, DOM של האתר, ניווט עליון). הקובץ שבתוכו
 *    מוגש עם אותה כותרת I1 (v2-serve.php) — שתי שכבות, ואחת מספיקה.
 *  - מה שעובר מהחבילה למסמך של האתר: **טקסט בלבד** — הכותרת, התיאור ותגי השיתוף מה-<head> של הדף, ב-esc_html / esc_attr.
 *  - הגשר: שלוש הודעות בלבד, ממקור "null" ומה-iframe עצמו (event.source), בצורה מדויקת ובגודל חסום. כל הודעה אחרת —
 *    נזרקת. שום הודעה אינה פונה לוורדפרס, ל-wp-json, לעוגייה או לאחסון:
 *      {kol:"height", h}  — רק ב-framed; מספר סופי 0..50,000 → גובה ה-iframe.
 *      {kol:"title",  t}  — מחרוזת עד 200 → document.title (טקסט).
 *      {kol:"path",   p}  — נתיב בחבילה שקיים במניפסט (HTML) → history.replaceState בתוך תחום הכתובת בלבד.
 *
 * מתי המעטפת: GET/HEAD לכתובת "יפה" (תחום הכתובת, או נתיב שמסתיים ב-/) שממופה לקובץ HTML במניפסט, כשהמפסק דולק (או
 * בכתובת הבדיקה), כשהאופן אינו full — וכשהבקשה אינה של iframe (Sec-Fetch-Dest). בקשה של ה-iframe, וכל קובץ בשמו
 * (about/index.html, css, מדיה) — מוגשים כמו ב-M-A. דפדפן ישן בלי Sec-Fetch-Dest שמנווט בתוך החלון לכתובת "יפה" — המעטפת
 * מזהה שהיא בתוך מסגרת ועוברת מיד לקובץ עצמו (location.replace), כך שאין מעטפת בתוך מעטפת.
 */

if (!defined('ABSPATH')) exit;

const KOL_MAROKO_V2_MOUNTS = ['full', 'bar', 'framed'];
const KOL_MAROKO_V2_FRAME_SANDBOX = 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation';
const KOL_MAROKO_V2_MAX_H = 50000;
const KOL_MAROKO_V2_TITLE_MAX = 200;

function kol_maroko_v2_mount()
{
    $m = kol_maroko_opt('v2_mount', 'full');
    return is_string($m) && in_array($m, KOL_MAROKO_V2_MOUNTS, true) ? $m : 'full';
}

/** הפעולה החתומה `mount` {mount: full|bar|framed}. */
function kol_maroko_v2_mount_set(array $b)
{
    $m = $b['mount'] ?? null;
    if (!is_string($m) || !in_array($m, KOL_MAROKO_V2_MOUNTS, true)) return kol_maroko_fail('bad_request', 'אופן הצבה לא מוכר', 400);
    $old = kol_maroko_v2_mount();
    kol_maroko_set('v2_mount', $m);
    if ($old !== $m) {
        kol_maroko_v2_log('mount ' . $old . ' → ' . $m, (int)kol_maroko_opt('v2_live', 0));
        kol_maroko_v2_purge();
    }
    return new WP_REST_Response(['ok' => true, 'mount' => $m, 'previous' => $old]);
}

/** האם הבקשה הזו מקבלת מסמך מעטפת. $rest — הנתיב אחרי תחום הכתובת (גולמי); $p — הקובץ שמופה (או null). */
function kol_maroko_v2_wrap_wanted($rest, $p)
{
    if (!in_array($GLOBALS['kol_maroko_v2_mode'] ?? '', ['on', 'preview'], true)) return false;
    if (kol_maroko_v2_mount() === 'full' || $p === null) return false;
    if ($rest !== '' && substr($rest, -1) !== '/') return false;                 // קובץ בשמו — תמיד הקובץ עצמו
    if (!preg_match('/\.html?$/i', $p)) return false;
    $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    return !in_array($dest, ['iframe', 'frame', 'embed', 'object'], true);
}

/** כתובת הקובץ עצמו (ה-src של ה-iframe): תחום הכתובת + הנתיב בחבילה, כל מקטע מקודד. */
function kol_maroko_v2_raw_url($p, $scope)
{
    return $scope . implode('/', array_map('rawurlencode', explode('/', $p)));
}

/** הכתובת "היפה" של קובץ HTML בחבילה (index.html ← התיקייה). */
function kol_maroko_v2_pretty($p, $scope)
{
    if ($p === 'index.html') return $scope;
    if (substr($p, -11) === '/index.html') $p = substr($p, 0, -10);
    return kol_maroko_v2_raw_url($p, $scope);
}

/**
 * הכותרת, התיאור ותגי השיתוף מה-<head> של הדף בחבילה — **כטקסט** (00 §7.3). מפוענחים (ישויות HTML) ומוחזרים גולמיים;
 * ה-escape — בהדפסה. רק שמות מרשימה סגורה, עד 30 תגים, ערך עד 1,000 תווים.
 */
function kol_maroko_v2_head_meta($html)
{
    $html = substr((string)$html, 0, 512 * 1024);
    $end = stripos($html, '</head');
    $head = $end === false ? substr($html, 0, 64 * 1024) : substr($html, 0, $end);
    $out = ['title' => '', 'meta' => []];
    $dec = function ($s) {
        $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s);
        return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
    };
    if (preg_match('~<title\b[^>]*>(.*?)</title\s*>~is', $head, $m)) $out['title'] = mb_substr($dec(strip_tags($m[1])), 0, KOL_MAROKO_V2_TITLE_MAX);
    if (preg_match_all('~<meta\b([^>]*)>~i', $head, $all)) {
        foreach ($all[1] as $attrs) {
            $a = [];
            if (preg_match_all('~([a-zA-Z][a-zA-Z0-9:_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~', $attrs, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $x) $a[strtolower($x[1])] = $x[2] !== '' ? $x[2] : (($x[3] ?? '') !== '' ? $x[3] : ($x[4] ?? ''));
            }
            if (!isset($a['content'])) continue;
            $key = isset($a['property']) ? 'property' : (isset($a['name']) ? 'name' : null);
            if ($key === null) continue;
            $name = strtolower(trim($a[$key]));
            if (!preg_match('/^(description|(og|twitter|article):[a-z0-9_:.-]{1,60})$/', $name)) continue;
            $out['meta'][] = [$key, $name, mb_substr($dec($a['content']), 0, 1000)];
            if (count($out['meta']) >= 30) break;
        }
    }
    return $out;
}

/** נקרא מ-v2-serve אחרי שהוחלט על מעטפת: שומר את מה שצריך ומחבר את המעטפת למחזור של וורדפרס. */
function kol_maroko_v2_wrap_prepare($p, array $man, $scope, $preview_token)
{
    $f = $man['files'][$p];
    $file = kol_maroko_v2_blob($f['sha256'] ?? '');
    $head = $file !== null ? kol_maroko_v2_head_meta((string)@file_get_contents($file, false, null, 0, 512 * 1024)) : ['title' => '', 'meta' => []];
    $paths = [];
    foreach ($man['files'] as $q => $x) if (is_string($q) && preg_match('/\.html?$/i', $q) && kol_maroko_v2_path_ok($q)) $paths[] = $q;
    $src = kol_maroko_v2_raw_url($p, $scope) . ($preview_token ? '?kol_preview=' . $preview_token : '');
    $GLOBALS['kol_maroko_v2_wrap'] = ['p' => $p, 'mode' => kol_maroko_v2_mount(), 'scope' => $scope, 'src' => $src, 'head' => $head, 'paths' => $paths];

    add_filter('pre_handle_404', '__return_true', 99);                          // /lexicon/about/ אינו עמוד של וורדפרס — וזה בסדר
    add_action('template_redirect', 'kol_maroko_v2_wrap_render', 0);           // לפני redirect_canonical ולפני template_include
    add_filter('pre_get_document_title', 'kol_maroko_v2_wrap_title', 99);
    add_filter('wp_title', 'kol_maroko_v2_wrap_title', 99);
    add_action('wp_head', 'kol_maroko_v2_wrap_head', 1);
}

function kol_maroko_v2_wrap_title($t = '')
{
    $w = $GLOBALS['kol_maroko_v2_wrap'] ?? null;
    return $w && $w['head']['title'] !== '' ? esc_html($w['head']['title']) : $t;
}

/** JSON שבטוח להדפיס בתוך <script>: בלי < > & ' " גולמיים. */
function kol_maroko_v2_js($v)
{
    return (string)json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function kol_maroko_v2_wrap_head()
{
    $w = $GLOBALS['kol_maroko_v2_wrap'] ?? null;
    if (!$w) return;
    // מעטפת בתוך מסגרת (דפדפן בלי Sec-Fetch-Dest שניווט בתוך החלון) — מיד לקובץ עצמו
    echo '<script>if(window.top!==window.self){location.replace(' . kol_maroko_v2_js($w['src']) . ')}</script>' . "\n";
    foreach ($w['head']['meta'] as [$key, $name, $content]) {
        echo '<meta ' . ($key === 'property' ? 'property' : 'name') . '="' . esc_attr($name) . '" content="' . esc_attr($content) . '">' . "\n";
    }
    if (in_array($GLOBALS['kol_maroko_v2_mode'] ?? '', ['preview', 'self'], true)) echo '<meta name="robots" content="noindex,nofollow">' . "\n";
}

/** ה-iframe, הסגנון שלו, והגשר — הקוד הקבוע של התוסף. */
function kol_maroko_v2_wrap_frame(array $w)
{
    $title = $w['head']['title'] !== '' ? $w['head']['title'] : 'קול מרוקו';
    $cfg = ['mode' => $w['mode'], 'scope' => $w['scope'], 'paths' => $w['paths'], 'max' => KOL_MAROKO_V2_MAX_H, 'tmax' => KOL_MAROKO_V2_TITLE_MAX];
    $css = '#kol-maroko-mount{margin:0;padding:0;width:100%;max-width:none}'
        . '#kol-maroko-frame{display:block;width:100%;border:0;margin:0;padding:0;background:transparent;'
        . ($w['mode'] === 'bar' ? 'height:calc(100vh - 80px);height:calc(100dvh - 80px)}' : 'height:100vh;overflow:hidden}');
    $js = <<<'JS'
(function(C){
var f=document.getElementById('kol-maroko-frame');if(!f)return;
var exact=function(d,keys){if(!d||typeof d!=='object'||Array.isArray(d)||Object.getPrototypeOf(d)!==Object.prototype)return false;var k=Object.keys(d);if(k.length!==keys.length)return false;for(var i=0;i<k.length;i++)if(keys.indexOf(k[i])<0)return false;return true};
var pretty=function(p){if(p==='index.html')return C.scope;if(/\/index\.html$/.test(p))p=p.slice(0,-10);return C.scope+p.split('/').map(encodeURIComponent).join('/')};
addEventListener('message',function(e){
if(e.source!==f.contentWindow||e.origin!=='null')return;
var d=e.data;
if(exact(d,['kol','h'])&&d.kol==='height'){if(C.mode!=='framed'||typeof d.h!=='number'||!isFinite(d.h)||d.h<0||d.h>C.max)return;f.style.height=Math.ceil(d.h)+'px';return}
if(exact(d,['kol','t'])&&d.kol==='title'){if(typeof d.t!=='string'||d.t.length>C.tmax)return;document.title=d.t;return}
if(exact(d,['kol','p'])&&d.kol==='path'){if(typeof d.p!=='string'||d.p.length>1024||C.paths.indexOf(d.p)<0)return;var u=pretty(d.p);if(u!==location.pathname)history.replaceState(history.state,'',u+location.search);return}
});
if(C.mode==='bar'){var fit=function(){var t=Math.max(0,Math.round(f.getBoundingClientRect().top+(window.scrollY||0)));f.style.height=Math.max(160,window.innerHeight-t)+'px'};fit();addEventListener('resize',fit);addEventListener('load',fit);setTimeout(fit,300)}
})
JS;
    return '<style id="kol-maroko-mount-css">' . $css . '</style>'
        . '<main id="kol-maroko-mount" class="kol-maroko-mount kol-maroko-' . esc_attr($w['mode']) . '">'
        . '<iframe id="kol-maroko-frame" src="' . esc_url($w['src']) . '" title="' . esc_attr($title) . '" sandbox="' . KOL_MAROKO_V2_FRAME_SANDBOX . '"'
        . ' referrerpolicy="strict-origin-when-cross-origin"></iframe></main>'
        . '<script>' . $js . '(' . kol_maroko_v2_js($cfg) . ');</script>';
}

/** מסמך המעטפת. template_redirect, עדיפות 0 — ומסתיים ב-exit. */
function kol_maroko_v2_wrap_render()
{
    $w = $GLOBALS['kol_maroko_v2_wrap'] ?? null;
    if (!$w) return;
    if (function_exists('status_header')) status_header(200);
    if (!headers_sent()) {
        header('X-LiteSpeed-Tag: ' . KOL_MAROKO_V2_TAG);
        header('X-Content-Type-Options: nosniff');
        if (kol_maroko_v2_private()) {
            header('Cache-Control: private, no-store');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Vary: Cookie');
        }
    }
    $frame = kol_maroko_v2_wrap_frame($w);
    $framed = $w['mode'] === 'framed';
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        echo "<!doctype html>\n<html "; language_attributes(); echo ">\n<head>\n<meta charset=\"";
        bloginfo('charset'); echo "\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        wp_head();
        echo "</head>\n<body "; body_class('kol-maroko-wrap'); echo ">\n";
        wp_body_open();
        echo '<div class="wp-site-blocks">';
        block_template_part('header');
        echo $frame; // phpcs:ignore — קוד קבוע של התוסף; כל ערך בתוכו עבר escape
        if ($framed) block_template_part('footer');
        echo '</div>';
        wp_footer();
        echo "</body>\n</html>";
    } else {
        get_header();
        echo $frame; // phpcs:ignore — קוד קבוע של התוסף; כל ערך בתוכו עבר escape
        if ($framed) get_footer();
        else { wp_footer(); echo "</body>\n</html>"; }
    }
    exit;
}
