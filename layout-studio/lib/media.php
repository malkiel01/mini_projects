<?php
// תמונות של העימוד: נשמרות לפי הגיבוב — data/files/<משתמש>/<sha256>.<ext> — בדיוק כמו media/ ב-.mbe,
// כך שהפניה במודל (src: "media/<sha256>.<ext>") היא אותה הפניה בקובץ שיורד. data/files/ מוחרגת ב-deploy.yml.

require_once __DIR__ . '/db.php';

const LS_MEDIA_MAX = 8 * 1024 * 1024;
const LS_MEDIA_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ttf' => 'font/ttf'];
const LS_FONT_MAX = 20 * 1024 * 1024;

function ls_media_dir(int $owner): string
{
    $base = getenv('LAYOUT_STUDIO_FILES') ?: __DIR__ . '/../data/files';
    return $base . '/' . $owner;
}

/** סוג הקובץ לפי הבתים הראשונים (לא לפי מה שהדפדפן אמר): png / jpg / webp, או null. */
function ls_media_sniff(string $bytes): ?string
{
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') return 'webp';
    return null;
}

/**
 * שמירת תמונה. מחזיר ['src' => 'media/<sha>.<ext>', 'px' => [w, h], 'bytes' => n].
 * @throws InvalidArgumentException (too_big / bad_image)
 */
function ls_media_put(int $owner, string $bytes): array
{
    if (strlen($bytes) === 0) throw new InvalidArgumentException('bad_image');
    if (strlen($bytes) > LS_MEDIA_MAX) throw new InvalidArgumentException('too_big');
    $ext = ls_media_sniff($bytes);
    if (!$ext) throw new InvalidArgumentException('bad_image');
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 20000 || $info[1] > 20000) throw new InvalidArgumentException('bad_image');
    $name = hash('sha256', $bytes) . '.' . $ext;
    $dir = ls_media_dir($owner);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('mkdir');
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $bytes);
        rename($tmp, $path);
    }
    return ['src' => 'media/' . $name, 'px' => [(int)$info[0], (int)$info[1]], 'bytes' => strlen($bytes)];
}

/**
 * שמירת גופן של המשתמש (מהענן או מהמכשיר) — TrueType בלבד, כי pdf-core מטמיע TrueType (CFF / OTTO — לא).
 * הבדיקה לפי הבתים: גרסת sfnt (0x00010000 / 'true') ומספר טבלאות סביר. מחזיר ['src' => 'media/<sha>.ttf', 'bytes' => n].
 * @throws InvalidArgumentException (too_big / bad_font)
 */
function ls_font_put(int $owner, string $bytes): array
{
    if (strlen($bytes) < 12) throw new InvalidArgumentException('bad_font');
    if (strlen($bytes) > LS_FONT_MAX) throw new InvalidArgumentException('too_big');
    $tag = substr($bytes, 0, 4);
    if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') throw new InvalidArgumentException('bad_font');
    $n = unpack('n', substr($bytes, 4, 2))[1];
    if ($n < 4 || $n > 64 || strlen($bytes) < 12 + 16 * $n) throw new InvalidArgumentException('bad_font');
    $tables = [];
    for ($i = 0; $i < $n; $i++) $tables[substr($bytes, 12 + 16 * $i, 4)] = true;
    foreach (['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'glyf', 'loca'] as $t) if (!isset($tables[$t])) throw new InvalidArgumentException('bad_font');
    $name = hash('sha256', $bytes) . '.ttf';
    $dir = ls_media_dir($owner);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('mkdir');
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $bytes);
        rename($tmp, $path);
    }
    return ['src' => 'media/' . $name, 'bytes' => strlen($bytes)];
}

/** הנתיב בדיסק של תמונה של המשתמש לפי השם (<sha>.<ext>), או null — שם לא תקין או שלא קיים. */
function ls_media_path(int $owner, string $name): ?string
{
    if (!preg_match('/^[0-9a-f]{64}\.(png|jpg|webp|ttf)$/', $name)) return null;
    $p = ls_media_dir($owner) . '/' . $name;
    return is_file($p) ? $p : null;
}

/** הגשת תמונה (GET). שם הקובץ הוא הגיבוב — לכן אפשר לשמור במטמון לתמיד. */
function ls_media_send(int $owner, string $name): never
{
    $p = ls_media_path($owner, $name);
    if (!$p) { http_response_code(404); exit; }
    $ext = pathinfo($p, PATHINFO_EXTENSION);
    header('Content-Type: ' . LS_MEDIA_TYPES[$ext]);
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: private, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    readfile($p);
    exit;
}
