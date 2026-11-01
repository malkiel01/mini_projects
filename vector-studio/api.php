<?php
/**
 * API של וקטורי. בקשה אחת = action אחד (ב-query או בגוף ה-JSON).
 *
 * me — מי נכנס. הספרייה: list, get, create, save, delete, thumb (GET, PNG), container (GET, ‎.mbe המקורי), versions, restore.
 * תמונות בציור: media_put (POST, base64 → { src }), media (GET ?src=media/<sha>.<ext>).
 * צבעים וסגנונות שמורים של המשתמש: prefs (GET), prefs_put (POST { swatches, styles }).
 * תפריט המשתמש (ui-core, lib/ui-core/account): account_prefs, account_prefs_set, account_export, account_delete — POST.
 * כתיבה רק ב-POST עם Content-Type: application/json — טופס מאתר אחר לא יכול לשלוח כזה בלי preflight.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/drawings.php';
require_once __DIR__ . '/lib/ui-core/account/account.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function reply(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

const WRITES = ['create', 'save', 'delete', 'restore', 'media_put', 'prefs_put',
                'account_prefs', 'account_prefs_set', 'account_export', 'account_delete'];

try {
    if (in_array($action, WRITES, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new AppError('בקשה לא תקינה', 400);
        }
    }
    $id = (int) ($_GET['id'] ?? $in['id'] ?? 0);
    if (str_starts_with($action, 'account_')) {
        // תפריט המשתמש המשותף: ההגדרות בטבלה uic_prefs במסד שלנו; ייצוא ומחיקה — של הציורים שלנו
        try {
            reply(['success' => true] + uic_account_api($action, $in, [
                'app'    => 'vector-studio',
                'pdo'    => fn() => db(),
                'user'   => fn() => currentUser()['id'] ?? null,
                'export' => fn($uid) => exportUserData((int) $uid),
                'delete' => fn($uid) => deleteUserData((int) $uid),
            ]));
        } catch (UicAccountError $e) {
            reply(['success' => false, 'error' => $e->getMessage()], $e->status);
        }
    }
    switch ($action) {
        case 'me':
            reply(['success' => true, 'user' => publicUser(requireUser())]);
        case 'list':
            reply(['success' => true, 'drawings' => listDrawings(requireUser())]);
        case 'get':
            reply(['success' => true, 'drawing' => getDrawing(requireUser(), $id)]);
        case 'create':
            reply(['success' => true] + createDrawing(requireUser(), $in));
        case 'save':
            reply(['success' => true] + saveDrawing(requireUser(), $id, $in));
        case 'delete':
            deleteDrawing(requireUser(), $id);
            reply(['success' => true]);
        case 'versions':
            reply(['success' => true, 'versions' => listVersions(requireUser(), $id)]);
        case 'restore':
            reply(['success' => true, 'drawing' => restoreVersion(requireUser(), $id, (int) ($in['version'] ?? 0), (int) ($in['rev'] ?? 0))]);
        case 'container': {
            $b = drawingContainer(requireUser(), $id);
            if ($b === null) { http_response_code(404); exit; }
            header('Content-Type: application/vnd.mbe+zip');
            echo $b;
            exit;
        }
        case 'prefs':
            reply(['success' => true] + getPrefs(requireUser()));
        case 'prefs_put':
            reply(['success' => true] + putPrefs(requireUser(), $in));
        case 'media_put':
            reply(['success' => true] + putMedia(requireUser(), $in['data'] ?? null));
        case 'media': {
            $m = getMedia(requireUser(), (string) ($_GET['src'] ?? ''));
            if ($m === null) { http_response_code(404); exit; }
            header('Content-Type: ' . $m[1]);
            header('Cache-Control: private, max-age=31536000, immutable'); // לפי sha — לא משתנה לעולם
            echo $m[0];
            exit;
        }
        case 'thumb': {
            $png = drawingThumb(requireUser(), $id);
            if ($png === null) { http_response_code(404); exit; }
            header('Content-Type: image/png');
            header('Cache-Control: private, max-age=60');
            echo $png;
            exit;
        }
        default:
            reply(['success' => false, 'error' => 'פעולה לא מוכרת'], 404);
    }
} catch (AppError $e) {
    reply(['success' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('vector-studio: ' . $e);
    reply(['success' => false, 'error' => 'שגיאת שרת'], 500);
}
