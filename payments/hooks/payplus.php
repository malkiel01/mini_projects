<?php
/**
 * הודעה נכנסת מפייפלוס (‏refURL_callback של דף התשלום, ‏?pa=…). חתומה (‏hash), אבל גם כך — רק "רמז": לוקחים ממנה את
 * ‏payment_request_uid, מוצאים את הקנייה שלנו, ושואלים את פייפלוס (‏PaymentPages/ipn) — שם נבדקים המצב והסכום.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$paId = (string)($_GET['pa'] ?? '');
$pa = preg_match('/^pa_[a-z0-9]{20}$/', $paId) ? db_one("SELECT * FROM provider_account WHERE id = ? AND provider = 'payplus'", [$paId]) : null;
if (!$pa) { http_response_code(404); echo '{"ok":false}'; exit; }

$raw = (string)file_get_contents('php://input', false, null, 0, 200_000);
$p = provider_from_account($pa);
if (!$p instanceof PayplusProvider || !$p->verifyCallback($raw, (string)($_SERVER['HTTP_HASH'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
    http_response_code(401); echo '{"ok":false}'; exit;
}
$j = json_decode($raw, true);
$uid = is_array($j) ? (string)($j['transaction']['payment_request_uid'] ?? '') : '';
$c = preg_match('/^[0-9a-f-]{36}$/i', $uid) ? db_one("SELECT * FROM checkout WHERE provider = ? AND status = 'open' AND lower(provider_ref) IN (?, ?)",
    [$pa['id'], 'pp:' . strtolower($uid), 'ppz:' . strtolower($uid)]) : null;
if ($c) {
    try {
        checkout_sync($c);
    } catch (Throwable $e) {
        error_log('payments payplus hook ' . $c['id'] . ': ' . $e->getMessage());
    }
}
echo '{"ok":true}';
tick_after_response('hook');
