<?php
/**
 * קול מרוקו 2.0 — הקבלה והאחסון של החבילות (פרוסה M2; docs/spec-v2/07-publish-and-mount.md §5.1).
 *
 * הפעולות (כולן עוברות קודם את kol_maroko_verify — חתימה, site, page, זמן, nonce):
 *   asset_has  {sha256: [...]}                         ← {have: [...]}        עד 10,000 בבקשה
 *   asset_put  {sha256, size, type?, part, parts, data} — חלקים בסדר; בסוף גודל ו-sha256 נבדקים לפני שהגוש נשמר
 *   manifest   {manifest (בייטים), manifest_sig, kid}  — חתימה נפרדת על הבייטים; רק אחרי כל הבדיקות — החלפת המצביע
 *   activate   {version}                               — המצביע לגרסה שמורה אחרת, מיידית
 *
 * האחסון: <uploads>/kol-maroko/v2/
 *   blobs/<sha>        טקסט וכל סוג שאינו מדיה — בלי סיומת
 *   blobs/<sha>.<ext>  מדיה מרשימה סגורה (KOL_MAROKO_V2_MEDIA)
 *   parts/             חלקים של גושים בדרך (נמחקים אחרי 24 שעות)
 *   manifest-<n>.json  הבייטים של כל מניפסט שהתקבל
 *   live.json          הגרסה שבאוויר — עותק של manifest-<n>.json, מוחלף ב-rename() (אטומי)
 * כל התיקייה חסומה מגישה ישירה (.htaccess, web.config, index.html). מה שבתוכה מוגש רק דרך v2-serve.php.
 *
 * הקובץ הזה אינו מבצע include/eval לשום קובץ מהחבילה, אינו פונה לשום שרת ואינו נוגע ב-post_content.
 */

if (!defined('ABSPATH')) exit;

define('KOL_MAROKO_PROTOCOL', 2);
const KOL_MAROKO_V2_MEDIA = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp3', 'm4a', 'ogg', 'opus', 'wav', 'mp4', 'webm', 'woff', 'woff2', 'ttf', 'otf'];
/** סוג MIME (כפי שהכלי שולח ב-asset_put) ← סיומת המדיה שבה הגוש נשמר. סוג אחר — בלי סיומת. */
const KOL_MAROKO_V2_MIME_EXT = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus',
    'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'audio/webm' => 'webm',
    'font/woff' => 'woff', 'font/woff2' => 'woff2', 'font/ttf' => 'ttf', 'font/otf' => 'otf',
];
const KOL_MAROKO_V2_HAS_MAX = 10000;
const KOL_MAROKO_V2_PART_TTL = 86400;
const KOL_MAROKO_V2_QUOTA_DEFAULT = ['file_mb' => 50, 'total_mb' => 500, 'files' => 5000, 'keep' => 10, 'rate' => 600];

// ── מקום ומכסות ───────────────────────────────────────────

function kol_maroko_v2_dir()
{
    return kol_maroko_dir()['path'] . '/v2';
}

/** המכסות (G5) — מההגדרות בעמוד התוסף, עם ברירות מחדל. כל ערך שלם וחיובי. */
function kol_maroko_v2_quota()
{
    $q = (array)kol_maroko_opt('v2_quota', []);
    $out = [];
    foreach (KOL_MAROKO_V2_QUOTA_DEFAULT as $k => $d) {
        $v = isset($q[$k]) ? (int)$q[$k] : $d;
        $out[$k] = $v > 0 ? $v : $d;
    }
    return $out;
}

/** התיקייה, נעולה. נכתב בכל קבלה אם חסר. */
function kol_maroko_v2_ensure()
{
    $root = kol_maroko_v2_dir();
    foreach ([$root, "$root/blobs", "$root/parts"] as $d) {
        if (!is_dir($d)) wp_mkdir_p($d);
        if (!file_exists("$d/index.html")) @file_put_contents("$d/index.html", '');
    }
    kol_maroko_v2_lock_files();
    return $root;
}

/**
 * .htaccess ו-web.config של v2/. כברירת מחדל — הכול חסום. כשההגדרה "הגשה ישירה של מדיה" דולקת (כבויה כברירת מחדל,
 * ורק אחרי שהאבחון הוכיח את הכותרות) — blobs/<hash>.<ext> מסוגי המדיה בלבד נפתחים, עם כותרות I1–I7.
 */
function kol_maroko_v2_lock_files($force = false)
{
    $root = kol_maroko_v2_dir();
    $php = "RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .pht .phps .cgi .pl .py .sh\n"
        . "RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .pht .phps\n"
        . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n"
        . "Options -Indexes -ExecCGI\n";
    $deny = "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $root_ht = "# קול מרוקו 2.0 — התיקייה חסומה. הקבצים מוגשים רק דרך התוסף.\n" . $php . $deny;
    $blobs_ht = $root_ht;
    if (kol_maroko_opt('v2_direct_media', false)) {
        $ext = implode('|', KOL_MAROKO_V2_MEDIA);
        $blobs_ht = "# קול מרוקו 2.0 — רק מדיה <hash>.<ext> פתוחה (הגשה ישירה). כל השאר חסום.\n" . $php . $deny
            . "<FilesMatch \"^[a-f0-9]{64}\\.($ext)\$\">\n  Require all granted\n  <IfModule mod_headers.c>\n"
            . "    Header always set Content-Security-Policy \"" . KOL_MAROKO_V2_CSP . "\"\n"
            . "    Header always set X-Content-Type-Options nosniff\n    Header always set Access-Control-Allow-Origin *\n"
            . "    Header always set Cross-Origin-Resource-Policy cross-origin\n    Header always set Referrer-Policy strict-origin-when-cross-origin\n"
            . "    Header always set Cache-Control \"public, max-age=31536000, immutable\"\n    Header always unset Set-Cookie\n  </IfModule>\n</FilesMatch>\n";
    }
    $web = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<configuration><system.webServer><handlers><clear /></handlers>"
        . '<security><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></security>'
        . "</system.webServer></configuration>\n";
    foreach (["$root/.htaccess" => $root_ht, "$root/blobs/.htaccess" => $blobs_ht, "$root/parts/.htaccess" => $root_ht,
        "$root/web.config" => $web, "$root/blobs/web.config" => $web, "$root/parts/web.config" => $web] as $f => $c) {
        if (!is_dir(dirname($f))) continue;
        if ($force || !file_exists($f) || @file_get_contents($f) !== $c) @file_put_contents($f, $c);
    }
}

// ── גושים ─────────────────────────────────────────────────

function kol_maroko_v2_sha_ok($s)
{
    return is_string($s) && preg_match('/^[a-f0-9]{64}$/', $s) === 1;
}

/** הקובץ של גוש, בכל אחד משני השמות האפשריים; null אם אינו. */
function kol_maroko_v2_blob($sha)
{
    if (!kol_maroko_v2_sha_ok($sha)) return null;
    $b = kol_maroko_v2_dir() . '/blobs/' . $sha;
    if (is_file($b)) return $b;
    foreach (KOL_MAROKO_V2_MEDIA as $e) if (is_file("$b.$e")) return "$b.$e";
    return null;
}

/** sha ← קובץ, לכל הגושים שמורים (סריקה אחת — ל-asset_has גדול ולניקוי). */
function kol_maroko_v2_blob_index()
{
    $out = [];
    $d = kol_maroko_v2_dir() . '/blobs';
    foreach (is_dir($d) ? scandir($d) : [] as $f) {
        if (preg_match('/^([a-f0-9]{64})(?:\.([a-z0-9]{2,5}))?$/', $f, $m) && (!isset($m[2]) || in_array($m[2], KOL_MAROKO_V2_MEDIA, true))) $out[$m[1]] = "$d/$f";
    }
    return $out;
}

function kol_maroko_v2_asset_has(array $b)
{
    $list = $b['sha256'] ?? null;
    if (!is_array($list) || count($list) > KOL_MAROKO_V2_HAS_MAX) return kol_maroko_fail('bad_request', 'רשימה לא תקינה (עד ' . KOL_MAROKO_V2_HAS_MAX . ')', 400);
    $idx = count($list) > 40 ? kol_maroko_v2_blob_index() : null;
    $have = [];
    foreach ($list as $sha) {
        if (!kol_maroko_v2_sha_ok($sha) || isset($have[$sha])) continue;
        if ($idx !== null ? isset($idx[$sha]) : kol_maroko_v2_blob($sha) !== null) $have[$sha] = true;
    }
    return new WP_REST_Response(['ok' => true, 'have' => array_keys($have)]);
}

function kol_maroko_v2_asset_put(array $b)
{
    $sha = $b['sha256'] ?? '';
    $size = $b['size'] ?? null;
    $part = $b['part'] ?? null;
    $parts = $b['parts'] ?? null;
    if (!kol_maroko_v2_sha_ok($sha) || !is_int($size) || !is_int($part) || !is_int($parts) || $size < 0 || $parts < 1 || $parts > 100000 || $part < 0 || $part >= $parts) {
        return kol_maroko_fail('bad_request', 'פרטי הגוש לא תקינים', 400);
    }
    $q = kol_maroko_v2_quota();
    if ($size > $q['file_mb'] * 1048576) return kol_maroko_fail('quota', 'הקובץ גדול מהמכסה', 413, ['quota' => 'file_mb', 'limit' => $q['file_mb'], 'value' => $size]);
    $data = base64_decode((string)($b['data'] ?? ''), true);
    if ($data === false) return kol_maroko_fail('bad_request', 'תוכן לא תקין', 400);
    $root = kol_maroko_v2_ensure();
    if (kol_maroko_v2_blob($sha) !== null) return new WP_REST_Response(['ok' => true, 'have' => true]);

    $tmp = "$root/parts/$sha";
    $lock = fopen("$tmp.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return kol_maroko_fail('busy', 'הגוש בכתיבה כרגע', 409);
    try {
        if (kol_maroko_v2_blob($sha) !== null) return new WP_REST_Response(['ok' => true, 'have' => true]);
        $got = $part === 0 ? 0 : (int)@file_get_contents("$tmp.n");
        if ($part === 0) @unlink("$tmp.part");
        if ($part !== $got) return kol_maroko_fail('bad_part', 'חלק לא בסדר', 409, ['expect' => $got]);
        clearstatcache();
        $have = is_file("$tmp.part") ? filesize("$tmp.part") : 0;
        if ($have + strlen($data) > $size) {                            // יותר בייטים ממה שהוכרז — פגום, לפני שנכתב
            @unlink("$tmp.part"); @unlink("$tmp.n");
            return kol_maroko_fail('bad_hash', 'הגוש גדול ממה שהוכרז', 422);
        }
        if (file_put_contents("$tmp.part", $data, FILE_APPEND) !== strlen($data)) return kol_maroko_fail('write_failed', 'הכתיבה נכשלה', 500);
        file_put_contents("$tmp.n", (string)($part + 1));
        if ($part + 1 < $parts) return new WP_REST_Response(['ok' => true, 'received' => $part + 1]);
        clearstatcache();
        if (filesize("$tmp.part") !== $size || hash_file('sha256', "$tmp.part") !== $sha) {
            @unlink("$tmp.part"); @unlink("$tmp.n");
            return kol_maroko_fail('bad_hash', 'הגוש הגיע פגום', 422);
        }
        $ext = KOL_MAROKO_V2_MIME_EXT[strtolower(trim(explode(';', (string)($b['type'] ?? ''))[0]))] ?? '';
        $final = "$root/blobs/$sha" . ($ext !== '' ? ".$ext" : '');
        if (!@rename("$tmp.part", $final)) return kol_maroko_fail('write_failed', 'השמירה נכשלה', 500);
        @unlink("$tmp.n");
        return new WP_REST_Response(['ok' => true, 'done' => true]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink("$tmp.lock");
    }
}

// ── מניפסט ────────────────────────────────────────────────

/** נתיב בחבילה: יחסי, בלי `\`, בלי `.`/`..`/ריק בין `/`, בלי תווי בקרה, UTF-8 תקין, עד 1,024 בייטים. */
function kol_maroko_v2_path_ok($p)
{
    if (!is_string($p) || $p === '' || strlen($p) > 1024 || $p[0] === '/' || strpos($p, '\\') !== false) return false;
    if (preg_match('/[\x00-\x1f\x7f]/', $p) || !preg_match('//u', $p) || preg_match('/[\x{80}-\x{9f}\x{2028}\x{2029}]/u', $p)) return false;
    foreach (explode('/', $p) as $s) if ($s === '' || $s === '.' || $s === '..') return false;
    return true;
}

/** הגרסה האחרונה שהתקבלה — בשני המסלולים (המונה משותף, M1 §2). */
function kol_maroko_v2_last_any()
{
    return max((int)kol_maroko_opt('last', 0), (int)kol_maroko_opt('v2_last', 0));
}

/** המניפסטים השמורים: n ← קובץ, מהחדש לישן. */
function kol_maroko_v2_saved()
{
    $out = [];
    foreach (glob(kol_maroko_v2_dir() . '/manifest-*.json') ?: [] as $f) {
        if (preg_match('/manifest-([1-9][0-9]{0,17})\.json$/', $f, $m)) $out[(int)$m[1]] = $f;
    }
    krsort($out);
    return $out;
}

/** המניפסט שבאוויר, מפוענח — קריאה אחת של live.json. null אם אין. */
function kol_maroko_v2_live_manifest()
{
    $f = kol_maroko_v2_dir() . '/live.json';
    $bytes = @file_get_contents($f);
    if ($bytes === false) return null;
    $m = json_decode($bytes, true);
    return is_array($m) && is_array($m['files'] ?? null) ? $m : null;
}

/**
 * בודק מניפסט מפוענח מול המכסות והגושים. מחזיר null אם תקין, או WP_REST_Response של דחייה.
 * (החתימה, site, page, זמן ו-nonce — נבדקים לפניו ב-kol_maroko_v2_manifest.)
 */
function kol_maroko_v2_check_files(array $man, $blobs = true)
{
    $q = kol_maroko_v2_quota();
    $files = $man['files'];
    if (count($files) > $q['files']) return kol_maroko_fail('quota', 'יותר מדי קבצים בחבילה', 413, ['quota' => 'files', 'limit' => $q['files'], 'value' => count($files)]);
    $total = 0;
    foreach ($files as $path => $f) {
        $path = (string)$path;
        if (!kol_maroko_v2_path_ok($path)) return kol_maroko_fail('bad_path', 'נתיב לא חוקי: ' . substr(preg_replace('/[\x00-\x1f\x7f]/', '?', $path), 0, 200), 422);
        if (!is_array($f) || !kol_maroko_v2_sha_ok($f['sha256'] ?? null) || !is_int($f['size'] ?? null) || $f['size'] < 0) {
            return kol_maroko_fail('bad_request', 'פרטי קובץ לא תקינים: ' . $path, 400);
        }
        if ($f['size'] > $q['file_mb'] * 1048576) return kol_maroko_fail('quota', 'קובץ גדול מהמכסה: ' . $path, 413, ['quota' => 'file_mb', 'limit' => $q['file_mb'], 'value' => $f['size']]);
        $total += $f['size'];
    }
    if ($total > $q['total_mb'] * 1048576) return kol_maroko_fail('quota', 'החבילה גדולה מהמכסה', 413, ['quota' => 'total_mb', 'limit' => $q['total_mb'], 'value' => $total]);
    $entry = $man['entry'] ?? null;
    if (!is_string($entry) || !isset($files[$entry])) return kol_maroko_fail('bad_request', 'נקודת הכניסה אינה בחבילה', 422);
    if (!$blobs) return null;                    // M7: לפני ההורדה — המבנה והמכסות בלבד
    foreach ($files as $path => $f) {
        $file = kol_maroko_v2_blob($f['sha256']);
        if ($file === null) return kol_maroko_fail('asset_missing', 'חסר קובץ: ' . $path, 409);
        clearstatcache(false, $file);
        if (filesize($file) !== $f['size']) return kol_maroko_fail('bad_hash', 'קובץ לא תואם: ' . $path, 409);
    }
    // ה-hash של כל גוש נבדק כשהתקבל (asset_put); כאן — שוב, לכל גוש ייחודי, כי הדיסק אינו חתום
    $seen = [];
    foreach ($files as $path => $f) {
        if (isset($seen[$f['sha256']])) continue;
        $seen[$f['sha256']] = true;
        if (hash_file('sha256', kol_maroko_v2_blob($f['sha256'])) !== $f['sha256']) return kol_maroko_fail('bad_hash', 'קובץ לא תואם: ' . $path, 409);
    }
    return null;
}

/**
 * החתימה על המניפסט, site, page, הזמן וה-nonce — בלי לכתוב דבר. ['man', 'bytes', 'nonce'] או WP_REST_Response של דחייה.
 * $seq (M7, ערוץ המשיכה): במקום חלון הזמן ±5 דקות — המניפסט החתום חייב לשאת את אותו seq ו-expires של המעטפה, ו-expires בעתיד.
 * כך מניפסט מהתור אינו נכנס במעטפה אחרת, ומניפסט של הדחיפה (בלי seq) אינו נכנס בתור.
 */
function kol_maroko_v2_manifest_parse(array $b, $seq = null)
{
    $bytes = $b['manifest'] ?? null;
    if (!is_string($bytes) || $bytes === '') return kol_maroko_fail('bad_request', 'מניפסט חסר', 400);
    $keys = kol_maroko_keys();
    $kid = (string)($b['kid'] ?? '');
    $sig = base64_decode((string)($b['manifest_sig'] ?? ''), true);
    if (!isset($keys[$kid]) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $bytes, $keys[$kid])) {
        return kol_maroko_fail('bad_signature', 'החתימה על המניפסט לא תקינה', 401);
    }
    $man = json_decode($bytes, true);
    if (!is_array($man) || !is_array($man['files'] ?? null) || array_values($man['files']) === $man['files'] && $man['files'] !== []) {
        return kol_maroko_fail('bad_request', 'מניפסט לא תקין', 400);
    }
    if (($man['site'] ?? '') !== kol_maroko_host()) return kol_maroko_fail('wrong_site', 'האתר לא תואם', 403);
    if (($man['page'] ?? '') !== KOL_MAROKO_PAGE) return kol_maroko_fail('wrong_page', 'העמוד לא תואם', 403);
    if ($seq === null) {
        if (array_key_exists('seq', $man) || array_key_exists('expires', $man)) return kol_maroko_fail('wrong_channel', 'מניפסט מהתור אינו מתקבל בדחיפה', 409);
        if (!is_int($man['time'] ?? null) || abs(time() - $man['time']) > KOL_MAROKO_SKEW) return kol_maroko_fail('stale', 'המניפסט ישן', 401);
    } else {
        if (($man['seq'] ?? null) !== $seq || ($man['expires'] ?? null) !== ($b['expires'] ?? false)) return kol_maroko_fail('bad_request', 'המניפסט אינו של הפקודה הזו', 400);
        if (!is_int($man['expires']) || $man['expires'] <= time()) return kol_maroko_fail('expired', 'פג התוקף', 401);
    }
    $nonce = $man['nonce'] ?? '';
    if (!is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/', $nonce)) return kol_maroko_fail('bad_request', 'nonce לא תקין', 400);
    return ['man' => $man, 'bytes' => $bytes, 'nonce' => $nonce];
}

function kol_maroko_v2_manifest(array $b, $seq = null)
{
    $pre = kol_maroko_v2_manifest_parse($b, $seq);
    if ($pre instanceof WP_REST_Response) return $pre;
    $man = $pre['man'];
    $bytes = $pre['bytes'];
    $nonce = $pre['nonce'];

    $root = kol_maroko_v2_ensure();
    // כל השאר — תחת נעילה אחת: שני מניפסטים במקביל לא יחליפו מצביע זה על גבי זה
    $lock = fopen("$root/publish.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return kol_maroko_fail('busy', 'פרסום אחר באמצע', 409);
    try {
        $seen = (array)kol_maroko_opt('v2_nonces', []);
        foreach ($seen as $n => $at) if ($at < time() - 2 * KOL_MAROKO_SKEW) unset($seen[$n]);
        if (isset($seen[$nonce])) return kol_maroko_fail('replay', 'המניפסט כבר התקבל', 401);
        $n = $man['version'] ?? null;
        $last = kol_maroko_v2_last_any();
        if (!is_int($n) || $n < 1 || $n > 999999999999) return kol_maroko_fail('bad_request', 'מספר גרסה לא תקין', 400);
        if ($n <= $last) return kol_maroko_fail('old_version', 'יש גרסה חדשה יותר', 409, ['version' => $last]);
        $bad = kol_maroko_v2_check_files($man);
        if ($bad) return $bad;

        // הכול תקין — רק עכשיו נכתב משהו: המניפסט, ואז המצביע (rename אטומי באותה מערכת קבצים)
        $seen[$nonce] = time();
        kol_maroko_set('v2_nonces', $seen);
        if (file_put_contents("$root/manifest-$n.json", $bytes, LOCK_EX) !== strlen($bytes)) return kol_maroko_fail('write_failed', 'כתיבת המניפסט נכשלה', 500);
        if (!kol_maroko_v2_point($n)) return kol_maroko_fail('write_failed', 'החלפת המצביע נכשלה', 500);
        kol_maroko_set('v2_last', $n);
        $info = (array)kol_maroko_opt('v2_versions', []);
        $info[$n] = ['at' => time(), 'by' => sanitize_text_field((string)($man['published_by'] ?? '')), 'files' => count($man['files']),
            'bytes' => array_sum(array_map(fn($f) => $f['size'], $man['files']))];
        kol_maroko_set('v2_versions', $info);
        kol_maroko_v2_cleanup();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    kol_maroko_v2_purge();
    return new WP_REST_Response(['ok' => true, 'version' => $n, 'live' => $n]);
}

/** החלפת המצביע ל-n: manifest-<n>.json ← live.json.tmp ← rename ← live.json, ואז האפשרות. */
function kol_maroko_v2_point($n)
{
    $root = kol_maroko_v2_dir();
    $src = "$root/manifest-$n.json";
    $tmp = "$root/live.json.tmp." . bin2hex(random_bytes(4));
    if (!is_file($src) || !@copy($src, $tmp)) return false;
    if (!@rename($tmp, "$root/live.json")) { @unlink($tmp); return false; }
    kol_maroko_set('v2_live', (int)$n);
    return true;
}

function kol_maroko_v2_activate(array $b)
{
    $n = $b['version'] ?? null;
    if (!is_int($n) || $n < 1) return kol_maroko_fail('bad_request', 'מספר גרסה לא תקין', 400);
    $r = kol_maroko_v2_activate_n($n);
    return $r === true ? new WP_REST_Response(['ok' => true, 'live' => $n, 'version' => (int)kol_maroko_opt('v2_last', 0)]) : $r;
}

/** "חזרה לגרסה" — מהכלי (activate) ומעמוד התוסף. true, או WP_REST_Response של דחייה. */
function kol_maroko_v2_activate_n($n)
{
    $root = kol_maroko_v2_ensure();
    $saved = kol_maroko_v2_saved();
    if (!isset($saved[$n])) return kol_maroko_fail('not_found', 'הגרסה אינה שמורה', 404);
    $man = json_decode((string)file_get_contents($saved[$n]), true);
    if (!is_array($man) || !is_array($man['files'] ?? null)) return kol_maroko_fail('not_found', 'הגרסה השמורה פגומה', 404);
    foreach ($man['files'] as $p => $f) if (kol_maroko_v2_blob($f['sha256'] ?? '') === null) return kol_maroko_fail('asset_missing', 'חסר קובץ: ' . $p, 409);
    $lock = fopen("$root/publish.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return kol_maroko_fail('busy', 'פרסום אחר באמצע', 409);
    try {
        if (!kol_maroko_v2_point($n)) return kol_maroko_fail('write_failed', 'החלפת המצביע נכשלה', 500);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    kol_maroko_v2_purge();
    return true;
}

/**
 * הניקוי — רק אחרי manifest מוצלח: גרסאות מעבר למכסה (לא זו שבאוויר), גושים שאף מניפסט שמור אינו מפנה אליהם,
 * וחלקים שלא נשלמו תוך 24 שעות.
 */
function kol_maroko_v2_cleanup()
{
    $root = kol_maroko_v2_dir();
    $keep = kol_maroko_v2_quota()['keep'];
    $live = (int)kol_maroko_opt('v2_live', 0);
    $saved = kol_maroko_v2_saved();
    $info = (array)kol_maroko_opt('v2_versions', []);
    $i = 0;
    foreach ($saved as $n => $f) {
        if (++$i <= $keep || $n === $live) continue;
        @unlink($f);
        unset($saved[$n], $info[$n]);
    }
    foreach (array_keys($info) as $n) if (!isset($saved[$n])) unset($info[$n]);
    kol_maroko_set('v2_versions', $info);
    $used = [];
    foreach ($saved as $f) {
        $m = json_decode((string)@file_get_contents($f), true);
        if (!is_array($m) || !is_array($m['files'] ?? null)) return;          // מניפסט שלא נקרא — לא מוחקים דבר
        foreach ($m['files'] as $x) if (is_array($x) && isset($x['sha256'])) $used[$x['sha256']] = true;
    }
    foreach (kol_maroko_v2_blob_index() as $sha => $file) if (!isset($used[$sha])) @unlink($file);
    foreach (glob("$root/parts/*.{part,n}", GLOB_BRACE) ?: [] as $f) if (@filemtime($f) < time() - KOL_MAROKO_V2_PART_TTL) @unlink($f);
}

/** מגבלת הקצב (G5): בקשות קבלה חתומות בדקה. מונה בקובץ, עם נעילה. null — בתוך המכסה. */
function kol_maroko_v2_rate()
{
    $root = kol_maroko_v2_ensure();
    $max = kol_maroko_v2_quota()['rate'];
    $fh = @fopen("$root/rate.json", 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    $minute = (int)floor(time() / 60);
    $r = json_decode((string)stream_get_contents($fh), true);
    $count = is_array($r) && ($r['m'] ?? 0) === $minute ? (int)$r['c'] : 0;
    $count++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['m' => $minute, 'c' => $count]));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $count > $max ? kol_maroko_fail('quota', 'יותר מדי בקשות בדקה', 413, ['quota' => 'rate', 'limit' => $max, 'value' => $count]) : null;
}

function kol_maroko_v2_handle($action, array $b)
{
    $over = kol_maroko_v2_rate();
    if ($over) return $over;
    if ($action === 'asset_has') return kol_maroko_v2_asset_has($b);
    if ($action === 'asset_put') return kol_maroko_v2_asset_put($b);
    if ($action === 'manifest') return kol_maroko_v2_manifest($b);
    if ($action === 'mount') return kol_maroko_v2_mount_set($b);
    return kol_maroko_v2_activate($b);
}

/** נפח האחסון של v2 (בייטים). */
function kol_maroko_v2_usage()
{
    $sum = 0;
    foreach (kol_maroko_v2_blob_index() as $f) $sum += (int)@filesize($f);
    return $sum;
}

// ── ניקוי מטמון אחרי פרסום / activate ─────────────────────

/** LiteSpeed (פעולה + כותרת לפי תגית), ו-hook לכל השאר. כשל אינו מכשיל את הפרסום — נרשם לאבחון. */
function kol_maroko_v2_purge()
{
    $log = [];
    try {
        $url = home_url(kol_maroko_v2_scope());
        do_action('litespeed_purge_url', $url);
        if (!headers_sent()) header('X-LiteSpeed-Purge: tag=' . KOL_MAROKO_V2_TAG, false);
        do_action('kol_maroko_v2_published', $url, (int)kol_maroko_opt('v2_live', 0));
        $log = ['at' => time(), 'ok' => true, 'url' => $url];
    } catch (Throwable $e) {
        $log = ['at' => time(), 'ok' => false, 'error' => substr($e->getMessage(), 0, 300)];
    }
    kol_maroko_set('v2_purge', $log);
}
