<?php
/**
 * הודעה נכנסת מקארדקום (‏WebHookUrl של דף התשלום, ‏?pa=…). לא חתומה — ולכן רק "רמז": לוקחים ממנה את
 * ‏LowProfileId, מוצאים את הקנייה שלנו לפיו, ושואלים את קארדקום (‏GetLpResult) — שם נבדקים גם המצב וגם הסכום.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$paId = (string)($_GET['pa'] ?? '');
$pa = preg_match('/^pa_[a-z0-9]{20}$/', $paId) ? db_one("SELECT * FROM provider_account WHERE id = ? AND provider = 'cardcom'", [$paId]) : null;
if (!$pa) { http_response_code(404); echo '{"ok":false}'; exit; }

$raw = (string)file_get_contents('php://input', false, null, 0, 200_000);
$lp = CardcomProvider::hookLowProfileId($raw, $_GET);
// קנייה שנסגרה — אין מה לשאול; קריאה לא חתומה לא מפעילה שאלה לקארדקום שוב ושוב
$c = $lp !== '' ? db_one("SELECT * FROM checkout WHERE lower(provider_ref) = ? AND provider = ? AND status = 'open'", ['lp:' . $lp, $pa['id']]) : null;
if ($c) {
    try {
        checkout_sync($c);
    } catch (Throwable $e) {
        error_log('payments cardcom hook ' . $c['id'] . ': ' . $e->getMessage());
    }
}
echo '{"ok":true}';
tick_after_response('hook');
