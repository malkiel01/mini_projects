<?php
/**
 * הנקודה הציבורית של הטפסים (פרוסה M5; 06-data.md §3.3). פתוחה לכל העולם — ב-"public" של app.json.
 *
 * POST בלבד, אנונימי. **בלי session_start, בלי עוגייה, בלי מודול הכניסה**: הקובץ אינו קורא $_COOKIE ואינו טוען את
 * lib/auth.php — בקשה עם עוגיית הסטודיו ובלעדיה מקבלות אותה תשובה. התשובה — רק {"ok":true} או
 * {"ok":false,"reason":"rate|size|invalid|closed"}. כל מגבלה נאכפת בשרת: lib/forms.php, forms_receive().
 * ?test=1 — מהתצוגה החיה: הרישום מהטיוטה, והפנייה נשמרת כבדיקה (🧪, נמחקת אחרי 7 ימים).
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
header('Referrer-Policy: no-referrer');

function form_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') form_out(405, ['ok' => false, 'reason' => 'invalid']);

try {
    require_once __DIR__ . '/lib/forms.php';
    [$code, $body] = forms_receive($_POST, $_FILES, (int)($_SERVER['CONTENT_LENGTH'] ?? 0), (string)($_SERVER['REMOTE_ADDR'] ?? ''), ($_GET['test'] ?? '') === '1');
    form_out($code, $body);
} catch (Throwable $e) {
    error_log('kol-maroko form.php: ' . $e->getMessage());
    try { devlog_write('error', 'forms', 'form.error', ['message' => $e->getMessage(), 'action' => 'form', 'status' => 500]); } catch (Throwable) {}
    form_out(500, ['ok' => false, 'reason' => 'closed']);
}
