<?php
/**
 * M7 — נקודת המשיכה. התוסף באתר **יוצא** לכאן; אין שום בקשה נכנסת לאתר.
 *
 *   GET  pull.php?after=<seq>   עד 20 פקודות חתומות מהתור (queued / delivered), לפי seq
 *   GET  pull.php?blob=<sha256> הבייטים של גוש שמופיע במניפסט שבתור — אחרת 404. Range, עד 8MB לתשובה
 *   GET  pull.php?ping          "בדוק חיבור" בתוסף — משיכה ריקה (בלי לסמן דבר)
 *   POST pull.php?report        הדיווח: מה הוחל / נכשל, ומצב התוסף
 *
 * ציבורית ב-app.json (השער של הפלטפורמה אינו בדרך), וההגנה כאן: אסימון משיכה (Authorization: Bearer / X-Kol-Pull-Token), שנבדק
 * ב-hash_equals מול sha256 שמור. האסימון מאפשר **רק** לקרוא את התור ולדווח — הפרסום דורש את החתימה, והמפתח הפרטי רק בכלי.
 * בלי עוגייה, בלי session, Cache-Control: no-store. 60 בקשות לדקה. כל בקשה — ביומן הבקשות של מסך המפתח (lib/devlog.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/devlog.php';
require_once __DIR__ . '/lib/build.php';

devlog_begin('pull');

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header_remove('Set-Cookie');
require_once __DIR__ . '/lib/csp.php';
csp_api_headers();

function pull_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

try {
    pull_auth($ip);
    pull_rate();
    if ($method === 'POST' && isset($_GET['report'])) {
        $raw = (string)file_get_contents('php://input', false, null, 0, PULL_REPORT_MAX + 1);
        if (strlen($raw) > PULL_REPORT_MAX) throw new ApiError('הדיווח גדול מדי', 413);
        $b = json_decode($raw, true);
        if (!is_array($b)) throw new ApiError('הדיווח אינו JSON תקין', 400);
        pull_out(pull_report($b, $ip));
    }
    if ($method !== 'GET' && $method !== 'HEAD') throw new ApiError('שיטה לא נתמכת', 405);
    if (isset($_GET['ping'])) {
        pull_heartbeat_touch('ping', $ip);
        $waiting = (int)db()->query("SELECT COUNT(*) FROM site_outbox WHERE state IN ('queued','delivered')")->fetchColumn();
        pull_out(['ok' => true, 'server_time' => time(), 'waiting' => $waiting, 'site' => site_host()]);
    }
    if (isset($_GET['blob'])) {
        $sha = (string)$_GET['blob'];
        $f = pull_blob_find($sha);
        if (!$f) throw new ApiError('לא נמצא', 404, ['code' => 'not_found']);
        $range = pull_range($_SERVER['HTTP_RANGE'] ?? null, $f['size']);
        if ($f['size'] === 0) { header('Content-Type: application/octet-stream'); header('Content-Length: 0'); exit; }
        if ($range === null) { header('Content-Range: bytes */' . $f['size']); throw new ApiError('טווח לא חוקי', 416); }
        [$a, $z] = $range;
        $partial = isset($_SERVER['HTTP_RANGE']) || $z < $f['size'] - 1;
        http_response_code($partial ? 206 : 200);
        header('Content-Type: application/octet-stream');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($z - $a + 1));
        if ($partial) header("Content-Range: bytes $a-$z/{$f['size']}");
        header('X-Kol-Sha256: ' . $sha);
        pull_heartbeat_touch('blob', $ip);
        if ($method === 'HEAD') exit;
        $fh = fopen($f['path'], 'rb');
        fseek($fh, $a);
        $left = $z - $a + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(1 << 20, $left));
            if ($chunk === false) break;
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }
    $after = $_GET['after'] ?? '0';
    if (!is_string($after) || !preg_match('/^\d{1,18}$/', $after)) throw new ApiError('after לא תקין', 400);
    pull_out(pull_items((int)$after, $ip));
} catch (ApiError $e) {
    $st = &devlog_state();
    $st['error'] = $e->getMessage();
    pull_out(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->extra['code'] ?? null], $e->getCode() ?: 400);
} catch (Throwable $e) {
    devlog_exception($e);
    pull_out(['ok' => false, 'error' => 'תקלה בשרת · מזהה ' . devlog_req()], 500);
}
