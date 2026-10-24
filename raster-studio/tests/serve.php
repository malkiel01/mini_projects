<?php
// נתב לשרת הבדיקות של PHP (php -S … tests/serve.php) — מדמה את השער: משתמש קבוע, ומסד זמני.
// רק בשרת הבדיקות המובנה; בשרת אמיתי — 404 (וגם tests/.htaccess חוסם).

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$_SERVER['PLATFORM_USER'] = json_encode(['sub' => 'test', 'name' => 'בודק', 'email' => 'test@example.com']);
putenv('RASTER_STUDIO_DB=' . (getenv('RASTER_STUDIO_DB') ?: sys_get_temp_dir() . '/raster-studio-browser.sqlite'));

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api.php') {
    require __DIR__ . '/../api.php';
    exit;
}
return false;
