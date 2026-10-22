<?php
/**
 * API של שעון הנוכחות.
 *
 * כל בקשה היא POST עם JSON (ה-action ב-query או בגוף), פרט ל-GET של `me`.
 *
 * שני סוגי מבקשים:
 * - צוות הניהול — מאחורי השער, הזהות מ-PLATFORM_USER.
 * - עובד — `emp_*`, בלי פלטפורמה; הזהות היא העוגייה tc_session. הפעולות האלה ב-"public" של app.json,
 *   וכל פעולה חדשה כזו חייבת להיכנס גם לשם — אחרת השער חוסם אותה בשקט.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/session.php';

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

    // עובד — לפני הבדיקה של צוות הניהול: אין לו PLATFORM_USER.
    switch ($action) {
        case 'emp_invite_info': ok(empInviteInfo($in));
        case 'emp_activate':    ok(empActivateAndLogin($in));
        case 'emp_state':       ok(empState());
    }

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
        case 'emp_list':       ok(empList($person, $in));
        case 'emp_get':        ok(empGet($person, $in));
        case 'emp_add':        ok(empAdd($person, $in));
        case 'emp_update':     ok(empUpdate($person, $in));
        case 'emp_terms_add':  ok(empTermsAdd($person, $in));
        case 'emp_invite':     ok(empInvite($person, $in));
        case 'emp_end':        ok(empEnd($person, $in));
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
