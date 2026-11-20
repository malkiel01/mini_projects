<?php
/**
 * M6: כלי בדיקות בלבד — pf_migrate / pf_migrate_last / pf_blank לשרת הבדיקות (tests/router.php), אחרי שההסבה הוסרה מ-api.php.
 *
 * בדיקות הדפדפן של העורך זורעות את "העמוד המוסב" ב-api('pf_migrate', {}) — כמו לפני M6. כאן אותה בדיקת זהות, CSRF והרשאה
 * (בעל העמוד ומעלה) כמו ב-api.php, ואותה צורת תשובה. בייצור אין לזה כניסה: tests/ חסומה, והנתב רץ רק ב-php -S עם KOL_TEST_DATA.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/members.php';
require_once dirname(__DIR__, 2) . '/lib/site.php';
require_once dirname(__DIR__, 2) . '/lib/restore.php';
require_once __DIR__ . '/migrate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$action = (string)($_GET['action'] ?? '');
try {
    $user = current_user() ?? throw new ApiError('נדרשת כניסה', 401);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') check_csrf($user);
    if (!role_at_least($user, 'page_owner')) throw new ApiError('אין הרשאה', 403);
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $result = match ($action) {
        'pf_migrate' => pf_migrate($user, $body),
        'pf_migrate_last' => pf_migrate_last(),
        'pf_blank' => pf_blank($user),
    };
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (ApiError $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
