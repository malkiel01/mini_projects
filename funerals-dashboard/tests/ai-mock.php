<?php
// ספק מדומה (Anthropic Messages API) לבדיקות פרוסה 06 — בלי רשת. רץ כ-router של php -S, רק בבדיקות (tests/ לא נפרס).
// AI_MOCK_DIR/queue.json — תור תשובות [{status, body}], כל בקשה לוקחת את הראשונה.
// AI_MOCK_DIR/requests.jsonl — כל בקשה: הנתיב, האם המפתח הוא המצופה (key_ok — לא המפתח עצמו), וגוף הבקשה.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$dir = (string)getenv('AI_MOCK_DIR');
$expected = is_file("$dir/expected_key") ? trim((string)file_get_contents("$dir/expected_key")) : '';
$lock = fopen("$dir/.lock", 'c');
flock($lock, LOCK_EX);
$raw = (string)file_get_contents('php://input');
file_put_contents("$dir/requests.jsonl", json_encode(['path' => $_SERVER['REQUEST_URI'] ?? '',
    'key_ok' => $expected !== '' && ($_SERVER['HTTP_X_API_KEY'] ?? '') === $expected,
    'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '', 'beta' => $_SERVER['HTTP_ANTHROPIC_BETA'] ?? '',
    'body' => json_decode($raw, true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$queue = is_file("$dir/queue.json") ? (json_decode((string)file_get_contents("$dir/queue.json"), true) ?: []) : [];
$next = array_shift($queue);
file_put_contents("$dir/queue.json", json_encode($queue, JSON_UNESCAPED_UNICODE));
flock($lock, LOCK_UN);
header('Content-Type: application/json');
if ($next === null) { http_response_code(500); echo '{"type":"error","error":{"type":"api_error","message":"mock queue empty"}}'; exit; }
http_response_code((int)$next['status']);
echo json_encode($next['body'], JSON_UNESCAPED_UNICODE);
