<?php
/**
 * נקודת הקצה של ה-GPT "מעצב הכרטיסים" (Action). ציבורית (app.json → public) — שרתי OpenAI פונים אליה
 * בלי עוגייה. התבנית: gpt/openapi.json.
 *
 * POST, JSON: { "openaiFileIdRefs": [...], "design": {...} } — וכותרת Authorization: Bearer אם ה-GPT מחובר
 * ב-OAuth. מחזיר קישור לפתיחה בעורך.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function gptOut(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('POST בלבד', 405);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) throw new ApiError('גוף הבקשה אינו JSON', 400);

    $userId = bearerUser();
    $res = createChatImport((array)($in['openaiFileIdRefs'] ?? []), $in['design'] ?? null, $userId);
    cleanupChatImports();

    $scheme = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mbe-plus.com');
    $url = $scheme . dirname($_SERVER['SCRIPT_NAME']) . '/#import=' . $res['code'];
    gptOut([
        'ok' => true,
        'url' => $url,
        'code' => $res['code'],
        'images' => $res['files'],
        // הניסוח למשתמש — ה-GPT מעביר אותו כמו שהוא.
        'message' => $userId
            ? "העיצוב נשלח לחשבון שלך בסטודיו עיצוב ומופיע במסך הבית. לפתיחה: $url"
            : "העיצוב מוכן. לפתיחה בסטודיו עיצוב: $url (הקישור תקף 24 שעות; קוד: {$res['code']})",
    ]);
} catch (ApiError $e) {
    gptOut(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('studio gpt: ' . $e);
    gptOut(['ok' => false, 'error' => 'שגיאת שרת'], 500);
}
