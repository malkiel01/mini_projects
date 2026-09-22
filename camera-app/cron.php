<?php
/**
 * ריצת התחזוקה: קליטה מה-FTP, מחיקה לפי מדיניות, ניקוי החי.
 *
 * ב-cPanel → Cron Jobs, כל דקה:
 *   php -q /home/<user>/public_html/mini_projects/camera-app/cron.php
 *
 * גם בלי זה המערכת עובדת: api.php מריץ את אותה תחזוקה "בדרך אגב" כשמישהו
 * משתמש בה, וכך גם heartbeat של הגשר. ה-cron רק מבטיח שקבצים מה-FTP
 * נקלטים גם כשאף אחד לא פותח את הדף.
 *
 * מהדפדפן/curl: cron.php?key=<cron_key> — המפתח במסך ההגדרות.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/ingest.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    $key = settingGet('cron_key', '');
    if ($key === '') { $key = bin2hex(random_bytes(12)); settingSet('cron_key', $key); }
    if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) { http_response_code(403); echo '{"success":false,"error":"מפתח שגוי"}'; exit; }
}

try {
    $out = runMaintenance(true);
    $line = json_encode(['success' => true, 'at' => nowIso()] + ($out ?? []), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $line = json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
echo $line, PHP_EOL;
