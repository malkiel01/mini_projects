<?php
/** תשובות JSON ושגיאות ל-api.php. */

declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * גוף הבקשה. בקשה משנה חייבת להיות JSON עם הכותרת X-Platform — אתר זר לא
 * יכול לשלוח כזו בלי אישור CORS, וזו ההגנה גם על טופסי הכניסה וההרשמה,
 * שקודמים לאסימון ה-CSRF.
 */
function json_body(): array
{
    if (($_SERVER['HTTP_X_PLATFORM'] ?? '') !== '1'
        || !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        throw new ApiError('בקשה לא תקינה', 400);
    }
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) throw new ApiError('בקשה לא תקינה', 400);
    return $data;
}
