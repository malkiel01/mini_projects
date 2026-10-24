<?php
/**
 * API של וקטורי. בקשה אחת = action אחד (ב-query או בגוף ה-JSON).
 *
 * me — מי נכנס. הספרייה: list, get, create, save, delete, thumb (GET, PNG), versions, restore.
 * כתיבה רק ב-POST עם Content-Type: application/json — טופס מאתר אחר לא יכול לשלוח כזה בלי preflight.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/drawings.php';

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

const WRITES = ['create', 'save', 'delete', 'restore'];

try {
    if (in_array($action, WRITES, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new AppError('בקשה לא תקינה', 400);
        }
    }
    $id = (int) ($_GET['id'] ?? $in['id'] ?? 0);
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
