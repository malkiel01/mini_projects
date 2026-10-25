<?php
/**
 * קבצים והקלטות (docs/spec.md, פרק 9).
 *
 * העלאה בחלקים: upload_init → upload_chunk (גוף גולמי, לפי offset — כך אפשר לחדש אחרי ניתוק) → upload_done.
 * גודל החלק נגזר מ-post_max_size שנמדד בזמן ריצה; הדפדפן מקטין אותו לבד אם השרת עונה 413.
 * בסיום: זיהוי סוג לפי התוכן (finfo, לא הסיומת), רשימה מותרת, ותמונות — הסרת EXIF (מיקום!) והקטנה ל-WebP.
 *
 * הקבצים ב-data/files/<2 תווים>/<32 hex>.<ext> (מוחרג בפריסה, data/ חסומה). הגשה:
 *   file  — לחבר בכלי (השער + תפקיד).
 *   media — כתובת חתומה לזמן מוגבל, בשביל התצוגה בסטודיו: היא ב-iframe מבודד (origin אטום), ושם
 *           העוגייה של הפלטפורמה לא נשלחת. ב-"public" של app.json; בלי חתימה תקפה — 403.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const FILE_MAX = 50 * 1024 * 1024;
const DISK_CAP_DEFAULT = 5 * 1024 * 1024 * 1024;
const IMAGE_MAX_SIDE = 2400;
const MEDIA_TTL = 12 * 3600;
const FILE_TYPES = [
    'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'], 'image/gif' => ['image', 'gif'],
    'audio/mpeg' => ['audio', 'mp3'], 'audio/mp4' => ['audio', 'm4a'], 'audio/x-m4a' => ['audio', 'm4a'], 'audio/aac' => ['audio', 'aac'],
    'audio/ogg' => ['audio', 'ogg'], 'audio/webm' => ['audio', 'webm'], 'audio/wav' => ['audio', 'wav'], 'audio/x-wav' => ['audio', 'wav'],
    'video/mp4' => ['video', 'mp4'], 'video/webm' => ['video', 'webm'], 'video/quicktime' => ['video', 'mov'],
    'application/pdf' => ['doc', 'pdf'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['doc', 'docx'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['doc', 'xlsx'],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['doc', 'pptx'],
    'application/zip' => ['doc', 'zip'], 'text/plain' => ['doc', 'txt'],
];

function files_dir(): string
{
    return KOL_DATA_DIR . '/files';
}

function ini_bytes(string $k): int
{
    $v = trim((string)ini_get($k));
    if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** גודל חלק: עד 8MB, ותמיד מתחת ל-post_max_size שהשרת הזה מקבל בפועל. */
function chunk_size(): int
{
    return max(256 * 1024, min(8 << 20, ini_bytes('post_max_size') - (64 << 10)));
}

function disk_cap(): int
{
    return (int)(setting('disk_cap') ?? DISK_CAP_DEFAULT);
}

function disk_used(): int
{
    return (int)db()->query('SELECT COALESCE(SUM(size), 0) FROM files')->fetchColumn();
}

function file_row(string $id): array
{
    $st = db()->prepare('SELECT * FROM files WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new ApiError('הקובץ לא נמצא', 404);
}

function file_public(array $f): array
{
    return [
        'id' => $f['id'], 'name' => $f['name'], 'type' => $f['type'], 'kind' => $f['kind'], 'size' => (int)$f['size'],
        'w' => $f['w'] ? (int)$f['w'] : null, 'h' => $f['h'] ? (int)$f['h'] : null, 'by' => $f['by_name'], 'at' => $f['at'],
        'url' => media_url($f['id']),
    ];
}

function file_path(array $f): string
{
    return files_dir() . '/' . substr($f['id'], 0, 2) . '/' . $f['id'] . '.' . $f['ext'];
}

function tmp_path(string $id): string
{
    return files_dir() . '/tmp/' . $id . '.part';
}

// ── העלאה ─────────────────────────────────────────────────

function upload_init(array $user, array $b): array
{
    $size = (int)($b['size'] ?? 0);
    $name = str_replace(['/', '\\', "\0"], '_', mb_substr(trim((string)($b['name'] ?? '')), 0, 150)) ?: 'קובץ';
    if ($size <= 0) throw new ApiError('הקובץ ריק');
    if ($size > FILE_MAX) throw new ApiError('הקובץ גדול מ-50MB');
    if (disk_used() + $size > disk_cap()) throw new ApiError('התקרה של הכלי מלאה. מחקו קבצים שלא בשימוש', 507);

    // העלאות שנתקעו יותר מיממה — נמחקות
    $old = db()->query("SELECT id FROM uploads WHERE started < " . (time() - 86400))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($old as $id) @unlink(tmp_path($id));
    db()->exec('DELETE FROM uploads WHERE started < ' . (time() - 86400));

    $id = bin2hex(random_bytes(16));
    if (!is_dir(files_dir() . '/tmp') && !@mkdir(files_dir() . '/tmp', 0775, true)) throw new ApiError('אין גישה לתיקיית הקבצים', 500);
    touch(tmp_path($id));
    db()->prepare('INSERT INTO uploads (id, name, size, sub, started) VALUES (?, ?, ?, ?, ?)')->execute([$id, $name, $size, $user['sub'], time()]);
    return ['id' => $id, 'chunk' => chunk_size(), 'received' => 0];
}

function upload_get(array $user, string $id): array
{
    $st = db()->prepare('SELECT * FROM uploads WHERE id = ? AND sub = ?');
    $st->execute([$id, $user['sub']]);
    return $st->fetch() ?: throw new ApiError('ההעלאה לא נמצאה. התחילו מחדש', 404);
}

/** חלק: גוף גולמי. offset חייב להיות בדיוק מה שכבר התקבל — אחרת 409 עם הנכון (חידוש). */
function upload_chunk(array $user, array $q): array
{
    $u = upload_get($user, (string)($q['id'] ?? ''));
    $path = tmp_path($u['id']);
    clearstatcache(true, $path);
    $have = (int)@filesize($path);
    if ((int)($q['offset'] ?? -1) !== $have) throw new ApiError('המיקום בקובץ לא תואם', 409, ['received' => $have]);
    $in = fopen('php://input', 'rb');
    $out = fopen($path, 'ab');
    $n = stream_copy_to_stream($in, $out, $u['size'] - $have + 1);
    fclose($in);
    fclose($out);
    if ($have + $n > $u['size']) {
        ftruncate(fopen($path, 'r+'), $have);
        throw new ApiError('הקובץ גדול מהגודל שהוצהר');
    }
    return ['received' => $have + $n];
}

function upload_done(array $user, array $b): array
{
    $u = upload_get($user, (string)($b['id'] ?? ''));
    $tmp = tmp_path($u['id']);
    clearstatcache(true, $tmp);
    if ((int)@filesize($tmp) !== (int)$u['size']) throw new ApiError('ההעלאה לא הושלמה', 409, ['received' => (int)@filesize($tmp)]);
    db()->prepare('DELETE FROM uploads WHERE id = ?')->execute([$u['id']]);

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    // finfo מזהה m4a / mp4 של שמע בלבד כ-video/mp4 או audio/mp4 לפי הגרסה; הקלטה מהדפדפן מוצהרת כשמע
    if ($mime === 'video/mp4' && preg_match('/\.(m4a|aac)$/i', $u['name'])) $mime = 'audio/mp4';
    if ($mime === 'video/webm' && preg_match('/\.(weba|webm)$/i', $u['name']) && str_starts_with((string)($b['type'] ?? ''), 'audio/')) $mime = 'audio/webm';
    if (!isset(FILE_TYPES[$mime])) {
        @unlink($tmp);
        throw new ApiError($mime === 'image/svg+xml' ? 'קובצי SVG אסורים (יכול להיות בהם קוד)' : 'סוג הקובץ לא נתמך');
    }
    [$kind, $ext] = FILE_TYPES[$mime];
    $w = $h = null;
    if ($kind === 'image') [$mime, $ext, $w, $h] = image_process($tmp, $mime, $ext);

    $id = $u['id'];
    $f = ['id' => $id, 'ext' => $ext];
    $dest = file_path($f);
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    if (!@rename($tmp, $dest)) { @unlink($tmp); throw new ApiError('לא הצלחתי לשמור את הקובץ', 500); }
    $size = filesize($dest);
    db()->prepare('INSERT INTO files (id, name, type, kind, ext, size, sha256, w, h, by_sub, by_name, at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $u['name'], $mime, $kind, $ext, $size, hash_file('sha256', $dest), $w, $h, $user['sub'], $user['name'], now_iso()]);
    audit_as($user, 'file_add', $u['name']);
    return ['file' => file_public(file_row($id))];
}

/**
 * תמונה: נטענת מחדש בציור (כך נופל כל EXIF, כולל מיקום, וגם תוכן זדוני שהוסתר בקובץ), מוקטנת לצלע של
 * IMAGE_MAX_SIDE, ונשמרת כ-WebP. GIF — נשאר כמו שהוא (אנימציה); אין בו EXIF.
 */
function image_process(string $path, string $mime, string $ext): array
{
    $info = @getimagesize($path);
    if (!$info) throw new ApiError('התמונה פגומה');
    [$w, $h] = $info;
    if ($mime === 'image/gif') return [$mime, $ext, $w, $h];
    if ($w * $h > 60_000_000) throw new ApiError('התמונה גדולה מדי (יותר מ-60 מגה-פיקסל)');

    if (extension_loaded('imagick')) {
        $im = new Imagick($path);
        $im->autoOrient();
        $im->stripImage();
        if (max($im->getImageWidth(), $im->getImageHeight()) > IMAGE_MAX_SIDE) $im->thumbnailImage(IMAGE_MAX_SIDE, IMAGE_MAX_SIDE, true);
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(82);
        $im->writeImage('webp:' . $path);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $im->clear();
        return ['image/webp', 'webp', $w, $h];
    }
    $src = match ($mime) { 'image/jpeg' => @imagecreatefromjpeg($path), 'image/png' => @imagecreatefrompng($path), 'image/webp' => @imagecreatefromwebp($path) };
    if (!$src) throw new ApiError('התמונה פגומה');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {          // סיבוב לפי המצלמה, לפני שה-EXIF נופל
        $o = (int)(@exif_read_data($path)['Orientation'] ?? 1);
        if (in_array($o, [3, 6, 8], true)) $src = imagerotate($src, [3 => 180, 6 => -90, 8 => 90][$o], 0);
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $k = min(1, IMAGE_MAX_SIDE / max($w, $h));
    if ($k < 1) {
        $dst = imagecreatetruecolor((int)round($w * $k), (int)round($h * $k));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        $src = $dst;
        $w = imagesx($src);
        $h = imagesy($src);
    }
    imagesavealpha($src, true);
    if (!imagewebp($src, $path, 82)) throw new ApiError('לא הצלחתי לעבד את התמונה', 500);
    return ['image/webp', 'webp', $w, $h];
}

// ── ספרייה ────────────────────────────────────────────────

function files_list(): array
{
    $rows = db()->query('SELECT * FROM files ORDER BY at DESC')->fetchAll();
    $used = files_in_use();
    return [
        'files' => array_map(fn($f) => file_public($f) + ['in_use' => isset($used[$f['id']])], $rows),
        'used' => disk_used(), 'cap' => disk_cap(), 'max' => FILE_MAX, 'chunk' => chunk_size(),
    ];
}

/** מזהי קבצים שהטיוטה מפנה אליהם (ובשלב 6 — גם הגרסה החיה). */
function files_in_use(): array
{
    $json = (string)db()->query('SELECT json FROM draft WHERE id = 1')->fetchColumn();
    preg_match_all('/"([0-9a-f]{32})"/', $json, $m);
    return array_flip($m[1]);
}

function file_delete(array $user, array $b): array
{
    $f = file_row((string)($b['id'] ?? ''));
    if (isset(files_in_use()[$f['id']])) throw new ApiError('הקובץ בשימוש בעמוד. הסירו אותו מהעמוד קודם');
    db()->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
    @unlink(file_path($f));
    audit_as($user, 'file_delete', $f['name']);
    return files_list();
}

function disk_cap_set(array $owner, array $b): array
{
    $gb = (float)($b['gb'] ?? 0);
    if ($gb < 0.5 || $gb > 200) throw new ApiError('תקרה בין 0.5 ל-200GB');
    setting_set('disk_cap', (string)(int)($gb * (1 << 30)));
    audit_as($owner, 'disk_cap', $gb . 'GB');
    return files_list();
}

// ── הגשה ──────────────────────────────────────────────────

function media_secret(): string
{
    $s = setting('media_secret');
    if (!$s) { $s = bin2hex(random_bytes(32)); setting_set('media_secret', $s); }
    return $s;
}

/** כתובת חתומה. התפוגה מעוגלת לשעה — כך הכתובת יציבה ונשמרת במטמון הדפדפן. */
function media_url(string $id): string
{
    $exp = (intdiv(time(), 3600) + 1) * 3600 + MEDIA_TTL;
    return 'api.php?action=media&id=' . $id . '&e=' . $exp . '&s=' . substr(hash_hmac('sha256', "$id|$exp", media_secret()), 0, 32);
}

function media_check(array $q): array
{
    $id = (string)($q['id'] ?? '');
    $exp = (int)($q['e'] ?? 0);
    $sig = (string)($q['s'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $id) || $exp < time()
        || !hash_equals(substr(hash_hmac('sha256', "$id|$exp", media_secret()), 0, 32), $sig)) {
        throw new ApiError('הקישור לא תקף', 403);
    }
    return file_row($id);
}

/** שליחת הקובץ, עם Range (שמע ווידאו בספארי דורשים). לא חוזר. */
function file_send(array $f, bool $download = false): never
{
    $path = file_path($f);
    if (!is_file($path)) throw new ApiError('הקובץ חסר בשרת', 404);
    $size = filesize($path);
    $etag = '"' . substr($f['sha256'], 0, 32) . '"';
    header_remove('Content-Type');
    header('Content-Type: ' . $f['type']);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=86400');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    $disp = $download || $f['kind'] === 'doc' ? 'attachment' : 'inline';
    header("Content-Disposition: $disp; filename*=UTF-8''" . rawurlencode($f['name']));
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

    $start = 0;
    $end = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $buf = fread($fh, min(1 << 16, $left));
        echo $buf;
        $left -= strlen($buf);
    }
    fclose($fh);
    exit;
}

/** המטא-דאטה של הקבצים שהעמוד מפנה אליהם — לרינדור. */
function files_meta(array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM files WHERE id IN ($in)");
    $st->execute(array_values($ids));
    $out = [];
    foreach ($st->fetchAll() as $f) $out[$f['id']] = $f;
    return $out;
}
