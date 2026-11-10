<?php
/**
 * המתזמן של שירותי הרקע (אפיון 01 §9.5). נקרא מ-cPanel כל 5 דקות:
 *   curl -s "https://…/appraisers/cron.php?token=<האסימון>"       ← הכתובת המלאה במסך המפתח, "שירותי רקע"
 * ציבורי ב-app.json (המתזמן אינו מחובר למעטפת) — ולכן מוגן באסימון; אסימון שגוי → 404.
 * כל הרצה נועלת (שתיים לא רצות במקביל — השנייה מחזירה skipped), מריצה משימות עד 25 שניות, ומחזירה סיכום ב-JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

try {
    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    if ($token === '' || !hash_equals(apr_cron_token(), $token)) {
        http_response_code(404);
        echo '{"ok":false}';
        exit;
    }
    apr_db();
    echo json_encode(['ok' => true] + apr_jobs_run(25.0, 'cron'));
} catch (Throwable $e) {
    error_log('appraisers cron: ' . get_class($e) . ' ' . $e->getMessage());
    http_response_code(503);
    echo '{"ok":false}';
}
