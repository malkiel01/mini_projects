<?php
/**
 * הגשת מדיה — ההקלטות, הצילומים והשידור החי.
 *
 * data/ חסומה ל-Apache; כל קובץ עובר כאן ונבדק שהמבקש מחובר. תומך
 * ב-Range, אחרת <video> לא יודע לדלג ולא יודע כמה זמן הסרטון.
 *
 *   media.php?r=<id>            ההקלטה עצמה
 *   media.php?r=<id>&t=1        הממוזערת/הפוסטר (או המקור אם אין)
 *   media.php?live=<key>&f=..   קובץ HLS של השידור החי
 *   &dl=1                       הורדה עם שם קובץ, במקום הצגה
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recordings.php';

$user = currentUser();
if (!$user) { http_response_code(401); exit('נדרשת התחברות'); }

function serveFile(string $path, string $mime, ?string $downloadName = null, bool $noCache = false): never {
    if (!is_file($path)) { http_response_code(404); exit('אין קובץ'); }
    $size = filesize($path);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header($noCache ? 'Cache-Control: no-store' : 'Cache-Control: private, max-age=86400');
    if ($downloadName) header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
    else header('Content-Disposition: inline');

    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') { $start = (int) $m[1]; $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1; }
        elseif ($m[2] !== '') { $start = max(0, $size - (int) $m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, min(256 * 1024, $left));
        if ($chunk === false) break;
        echo $chunk;
        $left -= strlen($chunk);
        if (connection_aborted()) break;
        flush();
    }
    fclose($fp);
    exit;
}

$mimes = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'ts' => 'video/mp2t',
          'm4v' => 'video/mp4', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
          'webp' => 'image/webp', 'm3u8' => 'application/vnd.apple.mpegurl', 'm4s' => 'video/iso.segment'];

if (isset($_GET['live'])) {
    $key = (string) $_GET['live'];
    $f   = basename((string) ($_GET['f'] ?? 'live.m3u8'));
    if (!preg_match('/^[a-z0-9\-_]{2,32}$/', $key) || !preg_match('/^(live\.m3u8|init\.mp4|seg\d{1,8}\.(ts|m4s))$/', $f)) {
        http_response_code(400); exit('בקשה לא תקינה');
    }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    serveFile(LIVE_DIR . "/$key/$f", $mimes[$ext] ?? 'application/octet-stream', null, true);
}

$id = (int) ($_GET['r'] ?? 0);
try { $r = recordingById($id); } catch (AppError) { http_response_code(404); exit('אין הקלטה כזו'); }

$rel = $r['path'];
if (!empty($_GET['t']) && $r['thumb_path']) $rel = $r['thumb_path'];
$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
$name = null;
if (!empty($_GET['dl'])) {
    $ts = strtotime($r['started_at']) ?: 0;
    $name = sprintf('cam%d_%s.%s', $r['camera_id'], gmdate('Ymd_His', $ts), $ext);
}
serveFile(MEDIA_DIR . '/' . $rel, $mimes[$ext] ?? 'application/octet-stream', $name);
