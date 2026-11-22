<?php
/**
 * המתזמן של שירותי הרקע (אפיון 01 §8.5). נקרא מ-cPanel כל 5 דקות:
 *   curl -s "https://…/sforno/cron.php?token=<האסימון>" > /dev/null    ← הכתובת המלאה במסך המפתח, "⚙️ שירותי רקע"
 * ציבורי ב-app.json (המתזמן אינו מחובר למעטפת) — ולכן מוגן באסימון; אסימון חסר או שגוי → 404.
 * כל הרצה נועלת (שתיים במקביל — השנייה יוצאת בלי לעבוד), מריצה משימות עד 25 שניות, ומחזירה סיכום JSON קצר.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

try {
    [$status, $body] = sf_cron_handle($_GET['token'] ?? null);
} catch (Throwable $e) {
    error_log('sforno cron: ' . get_class($e) . ' ' . $e->getMessage());
    [$status, $body] = [503, ['ok' => false]];
}
http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
