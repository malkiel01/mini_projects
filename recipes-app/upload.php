<?php
/**
 * העלאת קובץ למתכון.
 *
 * קובץ נפרד מ-api.php בכוונה: api.php קורא JSON מ-php://input, אבל העלאה
 * מגיעה כ-multipart/form-data ש-PHP מפרק בעצמו ל-$_FILES ו-$_POST, ומשאיר
 * את php://input ריק. במקום לכופף את החוזה של api.php, לקובץ הזה חוזה
 * משלו: שדה `file` ושדה `recipe_id`, ותשובה באותו מבנה JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/log.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$__t0 = microtime(true);
function fail(string $message, int $code = 400): never {
    logEvent($code >= 500 ? 'error' : 'warn', 'upload', $message,
             ['recipe_id' => (int) ($_POST['recipe_id'] ?? 0), 'bytes' => (int) ($_FILES['file']['size'] ?? 0), 'status' => $code],
             $GLOBALS['user'] ?? null, (int) round((microtime(true) - $GLOBALS['__t0']) * 1000));
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST בלבד', 405);

$user = currentUser();
if (!$user) fail('נדרשת התחברות', 401);

try {
    // post_max_size שנחצה משאיר $_POST ו-$_FILES ריקים לגמרי, בלי שגיאה
    // מפורשת. זה ההסבר היחיד לגוף בקשה לא ריק בלי שדות — ולכן ההודעה.
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('הקובץ גדול ממה שהשרת מרשה לבקשה אחת (' .
             (string) ini_get('post_max_size') . ')', 413);
    }
    $recipeId = (int) ($_POST['recipe_id'] ?? 0);
    if ($recipeId <= 0) fail('חסר מזהה מתכון');
    if (empty($_FILES['file'])) fail('לא נשלח קובץ');

    $media = storeUpload($recipeId, $user, $_FILES['file']);
    logEvent('info', 'upload', '', ['recipe_id' => $recipeId, 'kind' => $media['kind'] ?? null,
             'bytes' => (int) ($_FILES['file']['size'] ?? 0)], $user, (int) round((microtime(true) - $__t0) * 1000));
    echo json_encode(['success' => true, 'media' => $media, 'limits' => mediaLimits($user)],
                     JSON_UNESCAPED_UNICODE);
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('recipes-app upload: ' . $e->getMessage());
    logEvent('error', 'exception', get_class($e) . ': ' . $e->getMessage(),
             ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'action' => 'upload'], $user);
    fail('שגיאת שרת', 500);
}
