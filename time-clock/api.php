<?php
/**
 * API של שעון הנוכחות.
 *
 * כל בקשה היא POST עם JSON (ה-action בגוף או ב-query), פרט ל-GET של `me`. בשלב הזה — רק צוות
 * הניהול, מאחורי השער. פעולות העובד (`emp_*`) והעמדה (`station_*`) יגיעו בשלבים הבאים, ויופיעו
 * ב-"public" של app.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/company.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function fail(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []): never {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** ביטול עסקה פתוחה — בלי לפתוח את המסד אם הוא עצמו הכשל. */
function rollBack(): void {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable) { /* אין מה לבטל */ }
}

$raw = file_get_contents('php://input');
$in = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
if (!is_array($in)) $in = [];
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

try {
    if ($action !== 'me' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('נדרש POST', 405);
    $person = currentPerson();

    switch ($action) {
        case 'me':
            ok(['person' => ['id' => (int) $person['id'], 'name' => $person['name'], 'email' => $person['email']],
                'companies' => myCompanies($person)]);
        case 'company_create': ok(companyCreate($person, $in));
        case 'company_get':    ok(companyGet($person, intIn($in, 'company_id')));
        case 'company_update': ok(companyUpdate($person, $in));
        case 'site_save':      ok(siteSave($person, $in));
        case 'site_delete':    ok(siteDelete($person, $in));
        case 'team_add':       ok(teamAdd($person, $in));
        case 'team_update':    ok(teamUpdate($person, $in));
        case 'team_remove':    ok(teamRemove($person, $in));
        default:               fail('פעולה לא מוכרת', 404);
    }
} catch (AppError $e) {
    rollBack();
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    rollBack();
    error_log('time-clock: ' . $e);
    fail('שגיאת שרת', 500);
}
