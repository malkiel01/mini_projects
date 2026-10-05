<?php
/**
 * מדמה את /v1/messages של Anthropic — לבדיקות בלבד (מוגש משרת קבצי הדוגמה).
 * ?mode=ok       מחזיר שלבים מנוסחים מחדש (כל שלב מקבל קידומת ומבנה אחר)
 * ?mode=same     מחזיר את השלבים כלשונם — לבדיקת מדד הדמיון והניסיון השני
 * ?mode=refusal  stop_reason=refusal
 * ?mode=401      מפתח נדחה
 */
header('Content-Type: application/json; charset=utf-8');
$mode = $_GET['mode'] ?? 'ok';
if ($mode === '401') { http_response_code(401); echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]); exit; }
if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '') { http_response_code(401); echo json_encode(['error' => ['message' => 'missing key']]); exit; }
$body = json_decode((string) file_get_contents('php://input'), true);
// בקשת זיהוי תמונה (תוכן עם בלוק image) → רשימת מוצרים קבועה
$content = $body['messages'][0]['content'] ?? '';
if (is_array($content) && array_filter($content, fn($b) => ($b['type'] ?? '') === 'image')) {
    echo json_encode(['id' => 'msg_mock', 'type' => 'message', 'model' => $body['model'] ?? 'mock', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode(['products' => ['עגבנייה', 'גבינה צהובה', 'ביצה', 'עגבנייה']], JSON_UNESCAPED_UNICODE)]],
        'usage' => ['input_tokens' => 1500, 'output_tokens' => 40]], JSON_UNESCAPED_UNICODE);
    exit;
}
$text = (string) ($body['messages'][0]['content'] ?? '');
$json = substr($text, (int) strpos($text, '['));
$json = substr($json, 0, (int) strrpos($json, ']') + 1);
$sections = json_decode($json, true) ?: [];
$stronger = str_contains($text, 'הניסיון הקודם');

$out = [];
foreach ($sections as $s) {
    $steps = [];
    foreach ($s['steps'] as $i => $st) {
        if ($mode === 'same' && !$stronger) $steps[] = $st;                       // זהה — יגרור ניסיון שני
        else $steps[] = 'בשלב ' . ($i + 1) . ' של הניסוח החדש: ' . mb_strrev_u($st);
    }
    $out[] = ['name' => $s['name'], 'steps' => $steps];
}
function mb_strrev_u(string $s): string { $w = preg_split('/\s+/u', $s) ?: []; return implode(' ', array_reverse($w)); }

if ($mode === 'refusal') {
    echo json_encode(['id' => 'msg_mock', 'type' => 'message', 'model' => $body['model'], 'stop_reason' => 'refusal',
        'stop_details' => ['type' => 'refusal', 'category' => 'general_harms'], 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode([
    'id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? 'mock',
    'stop_reason' => 'end_turn', 'stop_details' => null,
    'content' => [['type' => 'text', 'text' => json_encode(['sections' => $out], JSON_UNESCAPED_UNICODE)]],
    'usage' => ['input_tokens' => 300, 'output_tokens' => 200],
], JSON_UNESCAPED_UNICODE);
