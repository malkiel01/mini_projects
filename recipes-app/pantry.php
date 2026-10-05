<?php
/**
 * צילום מוצרים → רשימת מוצרים (JSON). multipart כמו upload.php, בלי לשמור
 * את התמונה: היא נשלחת לזיהוי וזהו. המשתמש מאשר מה להוסיף דרך api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/pantry.php';
require_once __DIR__ . '/lib/log.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail(string $message, int $code = 400): never {
    logEvent($code >= 500 ? 'error' : 'warn', 'pantry-photo', $message, ['status' => $code], $GLOBALS['user'] ?? null);
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST בלבד', 405);
$user = currentUser();
if (!$user) fail('נדרשת התחברות', 401);

try {
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) fail('התמונה גדולה ממה שהשרת מרשה לבקשה אחת', 413);
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('לא התקבלה תמונה');
    $tmp = (string) $_FILES['file']['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $products = pantryRecognize((string) file_get_contents($tmp), $mime, $user);
    echo json_encode(['success' => true, 'products' => $products], JSON_UNESCAPED_UNICODE);
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('recipes-app pantry: ' . $e->getMessage());
    logEvent('error', 'exception', get_class($e) . ': ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'action' => 'pantry-photo'], $user);
    fail('שגיאת שרת', 500);
}
