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
require_once __DIR__ . '/lib/chat.php';

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

        // ─── מ-ChatGPT ────────────────────────────────────────────────
        case 'chat_claim':
            $u = requireUser();
            out(claimChatImport((int)$u['id'], (string)(body()['code'] ?? '')));

        case 'chat_pending':
            $u = requireUser();
            out(['imports' => pendingChatImports((int)$u['id'])]);

        // הגדרת ה-GPT — רק לבעלים: פרטי OAuth ל-GPT Builder. ‏regenerate מחליף את הסוד (החיבורים הקיימים נשארים).
        case 'gpt_setup':
            $u = requireUser();
            if ($u['role'] !== 'owner') throw new ApiError('רק מנהל המערכת', 403);
            $cfg = gptConfig();
            if ($method === 'POST' || empty($cfg['client_id'])) {
                $new = regenerateGptClient();
                audit((int)$u['id'], 'gpt.client_regenerated');
                out($new + ['new' => true]);
            }
            out(['client_id' => $cfg['client_id'], 'new' => false]);

        // יומן אבחון מהעורך (js/log.js): נשמר בקובץ, ומוחזר קוד. editor/log.php מחזיר אותו לפי הקוד.
        case 'log_put':
            $u = requireUser();
            $raw = file_get_contents('php://input') ?: '';
            if (strlen($raw) > 3 * 1024 * 1024) throw new ApiError('היומן גדול מדי', 413);
            $in = json_decode($raw, true);
            if (!is_array($in) || !is_array($in['rows'] ?? null)) throw new ApiError('יומן לא תקין', 400);
            $dir = STUDIO_DATA . '/logs';
            if (!is_dir($dir)) mkdir($dir, 0770, true);
            foreach (glob($dir . '/*.json') ?: [] as $old) {
                if (filemtime($old) < time() - 30 * 86400) @unlink($old);
            }
            $id = bin2hex(random_bytes(6));
            $in['user'] = (int)$u['id'];
            $in['received'] = gmdate('c');
            file_put_contents($dir . "/$id.json", json_encode($in, JSON_UNESCAPED_UNICODE), LOCK_EX);
            audit((int)$u['id'], 'log.put', ['id' => $id, 'rows' => count($in['rows'])]);
            out(['id' => $id]);

        default:
            throw new ApiError('פעולה לא קיימת', 404);
    }
} catch (ApiError $e) {
    out(['error' => $e->getMessage()] + $e->extra, $e->status);
} catch (Throwable $e) {
    error_log('studio api: ' . $e);
    out(['error' => 'שגיאת שרת'], 500);
}
