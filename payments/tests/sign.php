<?php
/**
 * כלי בדיקה: בקשה חתומה בקוד סודי, כמו שמערכת מחוברת תשלח.
 * php payments/tests/sign.php <base> <sk_…> <action> '<json>'
 */
if (PHP_SAPI !== 'cli' || $argc < 5) { fwrite(STDERR, "usage: sign.php <base> <key> <action> <json>\n"); exit(2); }
[, $base, $key, $action, $body] = $argv;
[$keyId, $secret] = explode('.', $key, 2);
$time = (string)time();
$nonce = bin2hex(random_bytes(16));
$sig = hash_hmac('sha256', "$action\n$time\n$nonce\n" . hash('sha256', $body), $secret);
$ch = curl_init(rtrim($base, '/') . "/api.php?action=$action");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
    'Content-Type: application/json', "X-Pay-Key: $keyId", "X-Pay-Time: $time", "X-Pay-Nonce: $nonce", "X-Pay-Signature: $sig",
]]);
echo curl_exec($ch), "\n";
