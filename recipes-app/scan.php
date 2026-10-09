<?php
/**
 * סריקת מתכון מתמונה — העלאה והצגה. multipart כמו upload.php.
 *
 *   POST  files[] (עד SCAN_MAX_IMAGES), note, visibility (private/public), attach (1/0)
 *         → סריקה חדשה, ממתינה לאישור המפתח (lib/scan.php).
 *   GET   ?id=<סריקה>&i=<מספר תמונה> → התמונה, לבעלים או למפתח בלבד.
 *         התמונות ב-data/scans/, שאינה מוגשת ישירות — רק דרך כאן.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/scan.php';
require_once __DIR__ . '/lib/log.php';

header('X-Content-Type-Options: nosniff');

function fail(string $message, int $code = 400): never {
    logEvent($code >= 500 ? 'error' : 'warn', 'scan-upload', $message, ['status' => $code], $GLOBALS['user'] ?? null);
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = currentUser();
if (!$user) fail('נדרשת התחברות', 401);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $scan = scanFor((int) ($_GET['id'] ?? 0), $user);
        [$path, $mime] = scanImagePath($scan, (int) ($_GET['i'] ?? 0));
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST בלבד', 405);
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('התמונות גדולות ממה שהשרת מרשה לבקשה אחת (' . (string) ini_get('post_max_size') . ') — לנסות פחות תמונות', 413);
    }
    // $_FILES['files'] מגיע "הפוך" (name[], tmp_name[]...) — לרשימה של קבצים
    $f = $_FILES['files'] ?? null;
    $files = [];
    if ($f && is_array($f['tmp_name'])) {
        foreach ($f['tmp_name'] as $i => $tmp) $files[] = ['tmp_name' => $tmp, 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
    }
    $scan = scanCreate($user, $files, (string) ($_POST['note'] ?? ''), (string) ($_POST['visibility'] ?? 'private'), ($_POST['attach'] ?? '1') === '1');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'scan' => $scan], JSON_UNESCAPED_UNICODE);
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('recipes-app scan: ' . $e->getMessage());
    logEvent('error', 'exception', get_class($e) . ': ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'action' => 'scan'], $user);
    fail('שגיאת שרת', 500);
}
