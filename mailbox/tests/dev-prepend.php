<?php
// פיתוח מקומי בלבד (php -S): מדמה את השער — PLATFORM_USER לפי ?as= או העוגייה dev_as. לא נפרס.
define('MB_ALLOW_LOCALHOST', true);
define('MB_CPANEL_ENDPOINT', 'http://127.0.0.1:8090/mailbox/tests/cpanel-mock.php');   // tests/cpanel-mock.php
define('MB_DEV_IMAP_HOST', 'localhost');
define('MB_IMPORT_DIR', getenv('MB_IMPORT_DIR') ?: sys_get_temp_dir() . '/mb-import');
$users = [
    'admin' => ['sub' => '1', 'name' => 'בעל המערכת', 'email' => 'owner@test.local', 'email_verified' => true, 'platform_admin' => true],
    'dana'  => ['sub' => '2', 'name' => 'דנה לוי', 'email' => 'dana@test.local', 'email_verified' => true, 'platform_admin' => false],
    'eve'   => ['sub' => '3', 'name' => 'אורחת', 'email' => 'eve@test.local', 'email_verified' => true, 'platform_admin' => false],
];
$as = $_SERVER['HTTP_X_DEV_AS'] ?? $_COOKIE['dev_as'] ?? 'admin';
if (isset($users[$as])) $_SERVER['PLATFORM_USER'] = json_encode($users[$as], JSON_UNESCAPED_UNICODE);
