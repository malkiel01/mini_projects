<?php
/**
 * התצוגה החיה המבודדת (פרוסה F4; docs/spec-v2/00-overview.md §7.1, §8): מגיש את קבצי **הטיוטה** של הפרויקט, כל
 * אחד בנתיב שלו, תחת כותרות הבידוד — כדי שמה שעובד כאן יעבוד אצל הגולש, ומה שלא — ייכשל כבר כאן.
 *
 * הכתובת: preview/<אסימון>/<נתיב בפרויקט>. ה-.htaccess של preview/ מעביר כל בקשה ל-preview/index.php.
 *
 * I1 — כל תשובה, גם 403/404/שגיאה, נושאת CSP sandbox **בלי allow-same-origin**: המסמך מקבל מקור אטום גם כשפותחים
 *      את הכתובת ישירות בלשונית. קוד של בעל העמוד לעולם אינו רץ במקור של הפלטפורמה (S1).
 * I7 — **אין כאן סשן.** הקוד הזה לעולם אינו קורא ל-current_user ואינו קורא עוגייה; ההרשאה היא האסימון החתום
 *      שבכתובת, שנוצר (pv_token) רק למי שמורשה לקרוא את הפרויקט. שום תשובה אינה קובעת עוגייה.
 *
 * איפה הנתונים: אין נתון חדש. האסימון חתום ואינו נשמר; מפתח החתימה — preview_key ב-data/secrets.json (מוחרג בפריסה).
 */

declare(strict_types=1);

require_once __DIR__ . '/project.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/lexicon.php';

const PV_TTL = 1800;                // תוקף האסימון, בשניות
const PV_SANDBOX = 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation';
const PV_BRIDGE = '__kol/bridge.js';    // הגשר של המסוף וכלי הבדיקה — נתיב שמור, אינו קובץ של הפרויקט
const PV_WORDS = 'data/words.json';

/** I2: הרשימה הסגורה. כל סיומת אחרת — application/octet-stream כקובץ מצורף (x.php מוגש להורדה, ואינו מורץ). */
const PV_TYPES = [
    'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8', 'map' => 'application/json; charset=utf-8', 'webmanifest' => 'application/manifest+json',
    'txt' => 'text/plain; charset=utf-8', 'vtt' => 'text/vtt; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'weba' => 'audio/webm',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'wasm' => 'application/wasm',
];

// ── האסימון ───────────────────────────────────────────────

function pv_key(): string
{
    $k = secret_get('preview_key');
    if ($k !== null) return $k;
    $all = secrets_load();
    $all['preview_key'] = $k = bin2hex(random_bytes(32));
    secrets_save($all);
    return $k;
}

function pv_sign(int $exp): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', "pv1|$exp", pv_key(), true)), '+/', '-_'), '=');
}

function pv_token_make(?int $exp = null): string
{
    $exp ??= time() + PV_TTL;
    return base_convert((string)$exp, 10, 36) . '.' . pv_sign($exp);
}

function pv_token_ok(string $t): bool
{
    if (!preg_match('/^([0-9a-z]{1,9})\.([A-Za-z0-9_-]{43})$/', $t, $m)) return false;
    $exp = (int)base_convert($m[1], 36, 10);
    return $exp >= time() && $exp <= time() + PV_TTL + 60 && hash_equals(pv_sign($exp), $m[2]);
}

/** api.php?action=pv_token — רק למי שמורשה לקרוא את הפרויקט (עורך ומעלה). */
function pv_token(array $user): array
{
    pf_seed($user);
    $exp = time() + PV_TTL;
    $kol = pf_kol()['config'] ?? [];
    return [
        'token' => pv_token_make($exp), 'exp' => $exp, 'ttl' => PV_TTL, 'base' => 'preview/',
        'entry' => is_string($kol['entry'] ?? null) && $kol['entry'] !== '' ? $kol['entry'] : 'index.html',
        'breakpoints' => array_values(array_filter(is_array($kol['breakpoints'] ?? null) ? $kol['breakpoints'] : [], 'is_array')),
        'sandbox' => PV_SANDBOX,
    ];
}

// ── ההגשה ─────────────────────────────────────────────────

/** כותרות הבידוד — על כל תשובה. נקראת ראשונה, לפני כל דבר שעלול להיכשל. */
function pv_headers(): void
{
    while (ob_get_level() > 0) @ob_end_clean();          // שום שכבה שמעל (השער, PWA) אינה מוסיפה דבר למסמך של העמוד
    header_remove('Set-Cookie');                          // I7: גם לא עוגייה שנקבעה לפני שהגענו לכאן
    header_remove('Content-Type');
    header('Content-Security-Policy: ' . PV_SANDBOX);
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');             // I4: מקור אטום קורא את ה-JSON, המודולים והגופנים של עצמו
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Referrer-Policy: no-referrer');               // האסימון שבכתובת אינו דולף לאתרים אחרים
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

function pv_fail(int $code, string $text): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($text);
}

/** שליחה עם Range (שמע ווידאו דורשים). $bytes — תוכן בזיכרון; אחרת $file — קובץ בדיסק. */
function pv_send(?string $bytes, ?string $file, string $name, int $code = 200): never
{
    $type = PV_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    $size = $bytes !== null ? strlen($bytes) : (int)filesize((string)$file);
    header('Content-Type: ' . ($type ?? 'application/octet-stream'));
    if (!$type) header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($name)));
    header('Accept-Ranges: bytes');
    $start = 0;
    $end = $size - 1;
    if ($code === 200 && preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') $start = max(0, $size - (int)$m[2]);
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        $code = 206;
        header("Content-Range: bytes $start-$end/$size");
    }
    http_response_code($code);
    $len = max(0, $end - $start + 1);
    header('Content-Length: ' . $len);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $len === 0) exit;
    if ($bytes !== null) { echo $start === 0 && $len === $size ? $bytes : substr($bytes, $start, $len); exit; }
    $fh = fopen((string)$file, 'rb');
    fseek($fh, $start);
    while ($len > 0 && !feof($fh)) {
        $buf = (string)fread($fh, min(1 << 16, $len));
        echo $buf;
        $len -= strlen($buf);
    }
    exit;
}

/**
 * הגשר נכנס למסמך באותה שורה שבה נפתח ה-<head> — כך מספרי השורות שהמסוף מדווח הם מספרי השורות שבקובץ.
 *
 * ועוד שינוי אחד: לכל <script src> נוספת התכונה crossorigin. במקור אטום כל סקריפט חיצוני הוא "ממקור אחר", והדפדפן
 * מסתיר את פרטי השגיאות שלו ("Script error.", בלי קובץ ושורה) אלא אם נטען במצב CORS. הקבצים מוגשים ממילא עם
 * Access-Control-Allow-Origin: * (I4), כך שהטעינה עצמה אינה משתנה. אלה שני השינויים היחידים בבייטים של העמוד,
 * והם קיימים רק בתצוגה החיה.
 */
function pv_inject(string $html, string $src): string
{
    $html = (string)preg_replace('/<script\b(?![^>]*\bcrossorigin\b)(?=[^>]*\bsrc\s*=)/i', '<script crossorigin', $html);
    $tag = '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '"></script>';
    foreach (['/<head(?:\s[^>]*)?>/i', '/<html(?:\s[^>]*)?>/i', '/<!doctype[^>]*>/i'] as $re) {
        if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $at) . $tag . substr($html, $at);
        }
    }
    return $tag . $html;
}

/** data/words.json — נבנה מטבלת המילים ברגע הבקשה. N1: הפונקציה האחת words_json (lib/lexicon.php). $app — כתובת האפליקציה, להקלטות שהועלו. */
function pv_words(string $app): string
{
    return words_json($app);
}

/** קובץ של הפרויקט לפי נתיב, או null. I5: רק מה שבפרויקט; pf_path דוחה כל טיפוס (.., \, נתיב מוחלט). */
function pv_find(string $path): ?array
{
    try {
        $r = pf_by_path(pf_path($path));
    } catch (ApiError) {
        return null;
    }
    return $r && $r['kind'] !== 'dir' ? $r : null;
}

function pv_serve(): never
{
    pv_headers();
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') { header('Access-Control-Allow-Methods: GET, HEAD'); header('Access-Control-Allow-Headers: *'); http_response_code(204); exit; }
        if ($method !== 'GET' && $method !== 'HEAD') pv_fail(405, 'GET only');

        $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $at = strpos($uri, '/preview/');
        if ($at === false) pv_fail(404, 'Not found');
        $app = substr($uri, 0, $at + 1);                              // /mini_projects/kol-maroko/
        [$token, $rest] = array_pad(explode('/', substr($uri, $at + 9), 2), 2, '');
        // M1 — מצב בנייה: אסימון של גרסה בנויה (b…). מגיש מהחבילה, לא מהמאגר (lib/build.php, build_serve)
        if (str_starts_with($token, 'b')) {
            require_once __DIR__ . '/build.php';
            $bt = build_token_parse($token) ?? pv_fail(403, "403 - the build link is missing, invalid or expired.\nהקישור לגרסה הבנויה שגוי או שפג תוקפו.");
            build_serve($bt[0], $bt[1], $app . 'preview/' . $token . '/', rawurldecode($rest));
        }
        if (!pv_token_ok($token)) pv_fail(403, "403 - the preview link is missing, invalid or expired.\nהקישור לתצוגה חסר, שגוי או שפג תוקפו. פותחים אותו מחדש מהסטודיו.");
        $root = $app . 'preview/' . $token . '/';
        $path = rawurldecode($rest);

        if ($path === PV_BRIDGE) pv_send((string)file_get_contents(dirname(__DIR__) . '/assets/js/preview-bridge.js'), null, 'bridge.js');
        if ($path === PV_WORDS) pv_send(pv_words($app), null, 'words.json');

        $html = fn(string $bytes) => pv_inject($bytes, $root . PV_BRIDGE);
        $r = null;
        if ($path === '' || str_ends_with($path, '/')) {
            $entry = $path === '' ? (string)(pf_kol()['config']['entry'] ?? 'index.html') : $path . 'index.html';
            $r = pv_find($entry) ?? ($path === '' ? pv_find('index.html') : null);
        } else {
            $r = pv_find($path);
        }
        if (!$r) {
            $nf = pv_find('404.html');
            if ($nf && $nf['kind'] === 'file') pv_send($html(pf_blob_get($nf['sha'])), null, '404.html', 404);
            pv_fail(404, "404 - no such file in the project.\nאין קובץ כזה בפרויקט: " . $path);
        }
        if ($r['kind'] === 'media') {
            $file = file_path(file_row((string)$r['file_id']));
            if (!is_file($file)) pv_fail(404, '404 - the media file is missing on the server.');
            pv_send(null, $file, $r['path']);
        }
        $ext = strtolower(pathinfo($r['path'], PATHINFO_EXTENSION));
        if ($ext === 'html' || $ext === 'htm') pv_send($html(pf_blob_get($r['sha'])), null, $r['path']);
        $file = pf_blob_path($r['sha']);
        if (!is_file($file)) pv_fail(404, '404 - the file content is missing on the server.');
        pv_send(null, $file, $r['path']);
    } catch (Throwable $e) {
        error_log('kol preview: ' . $e->getMessage());
        if (headers_sent()) exit;
        pv_fail(500, '500 - preview error.');
    }
}

/** התוצאה האחרונה של ערכת ההתקפה (מסך המפתח) — מטבלת dev_runs הקיימת. */
function pv_attack_last(): ?array
{
    $r = db()->query("SELECT at, ok, note FROM dev_runs WHERE name = 'attack' ORDER BY id DESC LIMIT 1")->fetch();
    return $r ? ['at' => $r['at'], 'ok' => (bool)$r['ok'], 'note' => $r['note']] : null;
}

/** הערכה רצה בדפדפן (assets/js/attack-kit.js); כאן רק נרשמת התוצאה, עם מי שהריץ. */
function pv_attack_save(array $dev, array $b): array
{
    $ok = !empty($b['ok']);
    $note = mb_substr((string)($b['note'] ?? ''), 0, 400);
    dev_run_note('attack', $ok, $note);
    devlog_write($ok ? 'info' : 'error', 'health', 'attack.kit', ['message' => $note]);
    return ['attack' => pv_attack_last()];
}
