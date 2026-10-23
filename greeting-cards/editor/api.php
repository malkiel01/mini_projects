<?php
/**
 * ה-API של סטודיו העיצוב: משתמשים, עיצובים וקבצים. JSON פנימה והחוצה; ‏?action=<פעולה>.
 *
 * בלי CORS — רק אותו מקור. כל בקשה שכותבת חייבת את הכותרת X-Studio: 1: טופס מאתר זר לא יכול להוסיף
 * כותרת משלו, ולכן זו הגנת CSRF מספיקה (יחד עם SameSite=Lax על העוגייה).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['ok' => $status < 400] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    return is_array($in) ? $in : [];
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET' && ($_SERVER['HTTP_X_STUDIO'] ?? '') !== '1') throw new ApiError('בקשה לא מורשית', 403);

    switch ($action) {
        case 'me':
            $u = currentUser();
            out($u ? ['user' => publicUser($u)] : ['user' => null]);

        case 'login_code':
            requestLoginCode((string)(body()['email'] ?? ''));
            out(['sent' => true]);

        case 'login_verify':
            $in = body();
            out(['user' => publicUser(verifyLoginCode((string)($in['email'] ?? ''), (string)($in['code'] ?? '')))]);

        case 'logout':
            endSession();
            out([]);

        case 'designs':
            $u = requireUser();
            out(['designs' => listDesigns((int)$u['id'], (int)($_GET['since'] ?? 0)), 'now' => nowMs()]);

        case 'design':
            $u = requireUser();
            out(['design' => getDesign((int)$u['id'], (string)($_GET['id'] ?? ''))]);

        case 'design_put':
            $u = requireUser();
            out(putDesign((int)$u['id'], body()));

        case 'design_delete':
            $u = requireUser();
            deleteDesign((int)$u['id'], (string)(body()['id'] ?? ''));
            out([]);

        case 'upload':
            $u = requireUser();
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new ApiError('לא התקבל קובץ', 422);
            out(['file' => storeImage((int)$u['id'], (string)file_get_contents($f['tmp_name']))]);

        case 'file':
            $u = requireUser();
            sendFile((int)$u['id'], (string)($_GET['id'] ?? ''));

        default:
            throw new ApiError('פעולה לא קיימת', 404);
    }
} catch (ApiError $e) {
    out(['error' => $e->getMessage()] + $e->extra, $e->status);
} catch (Throwable $e) {
    error_log('studio api: ' . $e);
    out(['error' => 'שגיאת שרת'], 500);
}
