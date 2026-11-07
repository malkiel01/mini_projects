<?php
// פיתוח ובדיקות מקומיות בלבד (php -S): מדמה את השער — PLATFORM_USER לפי הכותרת X-Dev-As או העוגייה dev_as.
// "none" — בלי זהות, כמו מי שאין לו הרשאה. בשרת הקובץ קיים אבל לא בשימוש: אף .user.ini לא מצביע עליו,
// ו-tests/.htaccess חוסם גישה ישירה.
define('TS_DEV', true);
if ($p = getenv('TS_DB_PATH')) define('TS_DB_PATH', $p);
$users = [
    'alice' => ['sub' => '101', 'name' => 'אליס', 'email' => 'alice@test.local', 'email_verified' => true, 'platform_admin' => false],
    'bob'   => ['sub' => '102', 'name' => 'בוב', 'email' => 'bob@test.local', 'email_verified' => true, 'platform_admin' => false],
];
$as = $_SERVER['HTTP_X_DEV_AS'] ?? $_COOKIE['dev_as'] ?? 'alice';
if (isset($users[$as])) $_SERVER['PLATFORM_USER'] = json_encode($users[$as], JSON_UNESCAPED_UNICODE);
