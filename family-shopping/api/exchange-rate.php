<?php
/**
 * השער היציג של בנק ישראל, לטופס הקנייה
 * api/exchange-rate.php?currency=USD&date=2026-10-26
 *
 * רק להצגה בזמן ההקלדה ("= ₪307.80"). השמירה עצמה לא סומכת על מה
 * שהדפדפן שלח - השרת מביא את השער שוב (מהמטמון) בזמן הרישום.
 */

require_once '../config.php';
require_once '../includes/session.php';
require_once '../includes/exchange.php';

bootstrapSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'לא מחובר']);
    exit;
}

$code = strtoupper((string)($_GET['currency'] ?? ''));
$date = validRateDate((string)($_GET['date'] ?? '')) ?? date('Y-m-d');

if (!isSupportedCurrency($code) || $code === BASE_CURRENCY) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'מטבע לא נתמך']);
    exit;
}

$pdo = getDBConnection();

if (!currencyColumnsReady($pdo)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'קנייה במטבע זר תהיה זמינה אחרי עדכון מסד הנתונים']);
    exit;
}

$found = exchangeRate($pdo, $code, $date);

if ($found === null) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'בנק ישראל לא ענה. אפשר להזין שער ידני']);
    exit;
}

echo json_encode([
    'success' => true,
    'rate'    => $found['rate'],
    'date'    => $found['date'],
]);
