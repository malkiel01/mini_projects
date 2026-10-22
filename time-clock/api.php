<?php
/**
 * API של שעון הנוכחות.
 *
 * כל בקשה היא POST עם JSON (ה-action ב-query או בגוף), פרט ל-GET של `me`.
 *
 * שני סוגי מבקשים:
 * - צוות הניהול — מאחורי השער, הזהות מ-PLATFORM_USER.
 * - עובד — `emp_*`, בלי פלטפורמה; הזהות היא העוגייה tc_session.
 * - עמדה — `station_*`; הזהות היא העוגייה tc_station.
 * הפעולות הציבוריות ב-"public" של app.json, וכל פעולה חדשה כזו חייבת להיכנס גם לשם — אחרת השער חוסם
 * אותה בשקט.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/attendance.php';
require_once __DIR__ . '/lib/station.php';
require_once __DIR__ . '/lib/corrections.php';

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
        case 'emp_punch':       ok(empPunch($in));
        case 'emp_month':       ok(empMonth($in));
        case 'emp_correct':     ok(empCorrect($in));
        case 'emp_correct_cancel': ok(empCorrectCancel($in));
        case 'emp_corrections': ok(empCorrections($in));
        case 'emp_approve':     ok(empApprove($in));
        case 'station_pair':    writeStationCookie(stationPair($in)); ok(stationState());
        case 'station_state':   ok(stationState());
        case 'station_check':   ok(stationCheck($in));
        case 'station_punch':   ok(stationPunch($in));
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
        case 'today_board':    ok(todayBoard($person, $in));
        case 'emp_month_m':    ok(empMonthManager($person, $in));
        case 'station_list':   ok(stationList($person, $in));
        case 'station_create': ok(stationCreate($person, $in));
        case 'station_revoke': ok(stationRevoke($person, $in));
        case 'corr_list':      ok(corrList($person, $in));
        case 'corr_decide':    ok(corrDecide($person, $in));
        case 'corr_direct':    ok(corrDirect($person, $in));
        case 'corr_history':   ok(corrHistory($person, $in));
        case 'steps_get':      ok(stepsGet($person, $in));
        case 'steps_save':     ok(stepsSave($person, $in));
        case 'period_view':    ok(periodView($person, $in));
        case 'period_open':    ok(periodOpen($person, $in));
        case 'period_approve': ok(periodApprove($person, $in));
        case 'period_lock':    ok(periodLock($person, $in));
        case 'period_reopen':  ok(periodReopen($person, $in));
        case 'period_csv':
            $csv = periodCsv($person, $in);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="attendance-' . preg_replace('/[^0-9-]/', '', (string) ($in['month'] ?? 'month')) . '.csv"');
            echo $csv;
            exit;
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
