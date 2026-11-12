<?php
/**
 * P1 — platform/provision.php מדומה. התשובה הבאה נקבעת בקובץ KOL_TEST_PROV ({"mode": invited|granted|blocked|fail, "sub"}),
 * וכל בקשה נרשמת בו תחת "log" (הכותרות החתומות והגוף), כדי שהבדיקה תאמת את החתימה.
 */

declare(strict_types=1);

$f = getenv('KOL_TEST_PROV');
if (PHP_SAPI !== 'cli-server' || !$f) { http_response_code(404); exit; }
$st = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
$body = (string)file_get_contents('php://input');
$st['log'][] = ['action' => $_GET['action'] ?? 'provision', 'body' => $body, 'client' => $_SERVER['HTTP_X_EMBED_CLIENT'] ?? '', 'time' => $_SERVER['HTTP_X_EMBED_TIME'] ?? '',
    'nonce' => $_SERVER['HTTP_X_EMBED_NONCE'] ?? '', 'sig' => $_SERVER['HTTP_X_EMBED_SIGNATURE'] ?? ''];
file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE));
header('Content-Type: application/json');
$in = json_decode($body, true) ?: [];
$mode = $st['mode'] ?? 'invited';
if ($mode === 'fail') { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
if (($in['grant'] ?? true) === false) { echo '{"status":"revoked"}'; exit; }
echo json_encode(match ($mode) {
    'blocked' => ['status' => 'blocked'],
    default => ['status' => $mode, 'sub' => (string)($st['sub'] ?? ('9' . random_int(1000, 9999)))],
});
