<?php
/**
 * קול מרוקו 2.0 — ההגשה לגולש, אופן M-A (פרוסה M2; 07 §5.2; 00 §7.1 I1–I8).
 *
 * נקרא בטעינת קובץ התוסף — לפני parse_request, לפני ערכת הנושא — ומסתיים ב-exit. תופס רק כשהמפסק "העמוד החדש" דולק
 * **וגם** יש גרסה v2 באוויר, ורק בקשות GET / HEAD לתחום הכתובת של העמוד (/lexicon/, /lexicon/**). אחרת — אינו נוגע
 * בדבר, והאתר מגיש את מה שהוא מגיש היום (G7).
 *
 * הכותרות על **כל** תשובה (200, 206, 304, 404, 416, HEAD): ארגז חול בלי allow-same-origin, סוג תוכן מרשימה סגורה, nosniff,
 * CORS פתוח לקריאה, Referrer-Policy, CORP — ובלי Set-Cookie (כל עוגייה שוורדפרס או תוסף אחר קבעו — מוסרת).
 * אינו מבצע include/eval לשום קובץ מהחבילה, ואינו מדפיס תוכן חבילה בתוך מסמך של האתר: הבייטים יוצאים כמו שהם.
 */

if (!defined('ABSPATH')) exit;

/** I1 — זהה ל-PV_SANDBOX של התצוגה החיה בכלי (lib/preview.php). */
define('KOL_MAROKO_V2_CSP', 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation');
define('KOL_MAROKO_V2_TAG', 'kol_maroko_v2');

/** I2 — הרשימה הסגורה. סיומת אחרת ← application/octet-stream והורדה. */
const KOL_MAROKO_V2_TYPES = [
    'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
    'json' => 'application/json', 'map' => 'application/json', 'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml',
    'txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/csv', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'pdf' => 'application/pdf', 'wasm' => 'application/wasm',
];

/** תחום הכתובת: הנתיב של העמוד שנבחר בהגדרות (נשמר בכל שמירה ובכל init — get_permalink אינו זמין לפני הניתוב). */
function kol_maroko_v2_scope()
{
    $s = (string)kol_maroko_opt('v2_scope', '');
    if ($s === '' || $s[0] !== '/' || substr($s, -1) !== '/' || strlen($s) < 3) $s = '/' . KOL_MAROKO_PAGE . '/';
    return $s;
}

/** מעדכן את תחום הכתובת לפי הקישור הקבוע של העמוד שנבחר. */
function kol_maroko_v2_scope_refresh()
{
    $id = (int)kol_maroko_opt('page_id', 0);
    if (!$id || !function_exists('get_permalink')) return;
    $path = (string)wp_parse_url((string)get_permalink($id), PHP_URL_PATH);
    if (preg_match('~^/[A-Za-z0-9._~%/-]*$~', $path) && strpos($path, '?') === false && $path !== '/') {
        $path = rtrim($path, '/') . '/';
        if ($path !== kol_maroko_opt('v2_scope', '')) kol_maroko_set('v2_scope', $path);
    }
}

function kol_maroko_v2_type($path)
{
    $e = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $t = KOL_MAROKO_V2_TYPES[$e] ?? 'application/octet-stream';
    $text = strpos($t, 'text/') === 0 || in_array($t, ['application/json', 'application/manifest+json', 'application/xml', 'image/svg+xml'], true);
    return [$t . ($text ? '; charset=utf-8' : ''), $t === 'application/octet-stream', in_array($e, KOL_MAROKO_V2_MEDIA, true)];
}

/**
 * נתיב הבקשה (אחרי תחום הכתובת, גולמי) ← נתיב בחבילה, או null (404). מפוענח פעם אחת ונבדק אחרי הפענוח.
 */
function kol_maroko_v2_map($rest, array $man)
{
    if (preg_match('~//|\\\\|%00~i', $rest)) return null;
    $p = rawurldecode($rest);
    if (strpos($p, "\0") !== false || strpos($p, '\\') !== false || strpos($p, '//') !== false || preg_match('/[\x00-\x1f\x7f]/', $p)) return null;
    foreach (explode('/', $p) as $s) if ($s === '.' || $s === '..') return null;
    if ($p === '') $p = (string)($man['entry'] ?? '');
    elseif (substr($p, -1) === '/') $p .= 'index.html';
    if (!kol_maroko_v2_path_ok($p) || !isset($man['files'][$p]) || !is_array($man['files'][$p])) return null;
    return $p;
}

/** הכותרות של I1–I7 — על כל תשובה. */
function kol_maroko_v2_base_headers($type = 'text/plain; charset=utf-8')
{
    header_remove();
    header('Content-Security-Policy: ' . KOL_MAROKO_V2_CSP);
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Resource-Policy: cross-origin');
    // עמוד שנפתח מעמוד של האתר (window.open) — בלי opener: אחרת הוא יכול לנווט את הלשונית של האתר, גם תחת sandbox (H4)
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Accept-Ranges: bytes');
    header('X-LiteSpeed-Tag: ' . KOL_MAROKO_V2_TAG);
}

/** תשובה סופית בלי גוף (או עם גוף קטן קבוע), עם כל הכותרות. */
function kol_maroko_v2_status($code, array $extra = [])
{
    kol_maroko_v2_base_headers();
    header('Cache-Control: no-store');
    foreach ($extra as $h) header($h);
    http_response_code($code);
    header('Content-Length: 0');
    kol_maroko_v2_end();
}

function kol_maroko_v2_end()
{
    header_remove('Set-Cookie');
    exit;
}

/**
 * נקודת הכניסה. $method / $uri — לבדיקות; בפועל מהבקשה. אינה חוזרת כשתפסה את הבקשה.
 */
function kol_maroko_v2_maybe_serve($method = null, $uri = null)
{
    $method = strtoupper((string)($method ?? ($_SERVER['REQUEST_METHOD'] ?? '')));
    if ($method !== 'GET' && $method !== 'HEAD') return;
    if (!(int)kol_maroko_opt('v2_live', 0) || !(kol_maroko_opt('v2_on', false) || kol_maroko_v2_selftest_req())) return;
    $path = (string)parse_url('http://x' . (string)($uri ?? ($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
    $scope = kol_maroko_v2_scope();
    if ($path !== rtrim($scope, '/') && strpos($path, $scope) !== 0) return;
    $man = kol_maroko_v2_live_manifest();                   // קריאה אחת: כל הבקשה מוגשת מהמניפסט הזה
    if (!$man) return;

    // מכאן הבקשה שלנו. שום פלט של וורדפרס או של תוסף אחר לא יוצא איתה, ושום עוגייה
    while (ob_get_level() > 0) @ob_end_clean();
    if (function_exists('header_register_callback')) header_register_callback(function () { header_remove('Set-Cookie'); });
    if ($path === rtrim($scope, '/')) kol_maroko_v2_status(301, ['Location: ' . $scope]);

    $p = kol_maroko_v2_map(substr($path, strlen($scope)), $man);
    $code = 200;
    if ($p === null) {
        $code = 404;
        $p = isset($man['files']['404.html']) && is_array($man['files']['404.html']) ? '404.html' : null;
        if ($p === null) kol_maroko_v2_status(404);
    }
    $f = $man['files'][$p];
    $file = kol_maroko_v2_blob($f['sha256'] ?? '');
    if ($file === null) kol_maroko_v2_status(404);
    $size = (int)filesize($file);
    [$type, $download, $media] = kol_maroko_v2_type($p);
    $etag = '"' . $f['sha256'] . '"';
    // הגשה ישירה (כבויה כברירת מחדל; נדלקת רק אחרי שהבדיקה העצמית הוכיחה את הכותרות של השרת) — הפניה לגוש המדיה
    if ($code === 200 && $media && strpos(basename($file), '.') !== false && kol_maroko_opt('v2_direct_media', false) && !kol_maroko_v2_selftest_req()) {
        kol_maroko_v2_status(302, ['Location: ' . kol_maroko_dir()['url'] . '/v2/blobs/' . basename($file)]);
    }

    kol_maroko_v2_base_headers($type);
    if ($download) header('Content-Disposition: attachment');
    header('ETag: ' . $etag);
    if ($code === 404) header('Cache-Control: no-store');
    elseif ($media || !empty($f['immutable'])) header('Cache-Control: public, max-age=31536000, immutable');
    // HTML ו"יפה" — נבדק מול השרת בכל טעינה (ETag ← 304), כדי שכיבוי המפסק יחזיר את העמוד המקורי מיד (H14, G7)
    else header('Cache-Control: public, max-age=0, must-revalidate');

    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($code === 200 && $inm !== '' && (trim($inm) === '*' || in_array($etag, array_map('trim', explode(',', str_replace('W/', '', $inm))), true))) {
        http_response_code(304);
        kol_maroko_v2_end();
    }

    $from = 0;
    $to = $size - 1;
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($code === 200 && $range !== '') {
        $ok = preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '');
        if ($ok) {
            if ($m[1] === '') { $from = max(0, $size - (int)$m[2]); }
            else { $from = (int)$m[1]; if ($m[2] !== '') $to = min($to, (int)$m[2]); }
            $ok = $from <= $to && $from < $size && ($m[1] === '' ? (int)$m[2] > 0 : true);
        }
        if (!$ok && preg_match('/^bytes=/', trim($range)) && strpos($range, ',') === false) {
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            http_response_code(416);
            kol_maroko_v2_end();
        }
        if ($ok) {
            $code = 206;
            header("Content-Range: bytes $from-$to/$size");
        } else {                                              // כמה טווחים / תחביר אחר — כל הקובץ
            $from = 0;
            $to = $size - 1;
        }
    }
    http_response_code($code);
    $len = $size === 0 ? 0 : $to - $from + 1;
    header('Content-Length: ' . $len);
    header_remove('Set-Cookie');
    if ($method === 'HEAD' || $len === 0) kol_maroko_v2_end();
    $fh = fopen($file, 'rb');
    if ($fh) {
        fseek($fh, $from);
        $left = $len;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(262144, $left));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $left -= strlen($chunk);
            if (connection_aborted()) break;
        }
        fclose($fh);
    }
    kol_maroko_v2_end();
}
