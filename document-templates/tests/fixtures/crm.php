<?php
// מערכת חיצונית מדומה לבדיקות: רשימת לקוחות (דורשת אסימון), וקולטת webhook.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$log = getenv('HOOK_LOG');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/customers') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer s3cret') { http_response_code(401); echo '{"error":"no"}'; exit; }
    echo json_encode(['data' => ['items' => [
        ['id' => 'C-1', 'name' => 'דנה כהן', 'contact' => ['phone' => '050-1111111', 'city' => 'חיפה']],
        ['id' => 'C-2', 'name' => 'משה לוי', 'contact' => ['phone' => '052-2222222', 'city' => 'אילת']],
    ]]], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/hook') {
    file_put_contents($log, json_encode(['sig' => $_SERVER['HTTP_X_DT_SIGNATURE'] ?? '', 'body' => file_get_contents('php://input')]) . "\n", FILE_APPEND);
    echo '{"ok":true}';
    exit;
}
http_response_code(404);
echo '{}';
