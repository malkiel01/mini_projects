<?php
/**
 * תשובות JSON לממשקי הווב (dev/api.php, parent/api.php).
 *
 * ה-API של המכשיר (api/index.php) נשאר עם הפורמט שלו — האפליקציה כבר
 * מותקנת אצל אנשים, ושינוי שם שדה שם הוא שבירה.
 */

declare(strict_types=1);

function jsonHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function out(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $http = 400, string $code = 'error'): never {
    out(['ok' => false, 'error' => $message, 'code' => $code], $http);
}

function body(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return $cache = is_array($data) ? $data : [];
}

function str(string $k): string {
    $v = body()[$k] ?? '';
    return is_scalar($v) ? trim((string) $v) : '';
}

function num(string $k): int {
    return (int) (body()[$k] ?? 0);
}

/**
 * בקשה משנה: POST, הכותרת X-Migdalor (אתר זר לא יכול לשלוח כותרת כזו בלי
 * CORS), ולמחובר — גם X-CSRF שצמוד ל-session מהסוג הזה.
 */
function requirePostFrom(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('שיטה לא נתמכת', 405);
    if (($_SERVER['HTTP_X_MIGDALOR'] ?? '') !== '1') fail('בקשה לא תקינה', 400, 'bad_origin');
}

function requireCsrf(string $kind): void {
    $csrf = (string) (sessionRow($kind)['csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        fail('פג תוקף הדף — רעננו ונסו שוב', 400, 'csrf');
    }
}
