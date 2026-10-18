<?php
/**
 * רץ לפני כל קובץ PHP בתיקיית כלי שהשער פעיל בה (auto_prepend_file ב-.user.ini
 * של הכלי, שנכתב בפריסה — ראו build-gate.php). מותר → ממשיך לקובץ של הכלי
 * כאילו לא קרה דבר. אחרת → עוצר עם דף כניסה, 403 או JSON.
 *
 * אסור להשאיר כאן שמות גלובליים: הקוד רץ בתוך התהליך של הכלי. לכן הכול בתוך
 * פונקציה אנונימית, והלוגיקה ב-namespace Platform\Gate.
 */
(static function (): void {
    if (PHP_SAPI === 'cli') return; // cron מקומי (php -q …) — לא בקשת רשת
    require_once __DIR__ . '/lib/gate.php';

    $root = realpath(__DIR__ . '/..');
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (!$root || !$script || !str_starts_with($script, $root . '/')) return;

    $rel = substr($script, strlen($root) + 1);               // carpentry-3d/api.php
    [$app, $path] = array_pad(explode('/', $rel, 2), 2, '');
    if ($app === 'platform') return;

    // פרמטרים לתבניות כמו api.php?action=view — גם מגוף JSON. php://input ניתן
    // לקריאה חוזרת, כך שהכלי עדיין יקבל את הגוף כולו.
    $params = $_GET + $_POST;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $json = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($json)) $params += array_filter($json, 'is_scalar');
    }

    $verdict = \Platform\Gate\decide($app, $path, $params);
    if ($verdict !== null) {
        \Platform\Gate\deny($verdict, \Platform\Gate\base_url($_SERVER['SCRIPT_NAME'] ?? '/', $rel));
    }
    \Platform\Gate\record_visit($app);

    // דף PHP של אפליקציה מחוברת — בדיקת ההתקנה והפס "התקנה" (lib/pwa.php). לא בדף ציבורי.
    try {
        require_once __DIR__ . '/lib/pwa.php';
        $cfg = \Platform\Gate\app_config($app);
        if (\Platform\Pwa\applies($app, $cfg)) {
            \Platform\Pwa\inject_php_page($app, $cfg, \Platform\Gate\base_url($_SERVER['SCRIPT_NAME'] ?? '/', $rel), $path, $params);
        }
    } catch (\Throwable $e) {
        error_log('platform pwa: ' . $e->getMessage());   // לעולם לא מפיל את הכלי
    }
})();
