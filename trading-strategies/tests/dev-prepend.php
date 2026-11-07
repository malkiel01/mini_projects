<?php
// פיתוח ובדיקות מקומיות בלבד (php -S): מדמה את השער — PLATFORM_USER לפי הכותרת X-Dev-As או העוגייה dev_as.
// "none" — בלי זהות, כמו מי שאין לו הרשאה. בשרת הקובץ קיים אבל לא בשימוש: אף .user.ini לא מצביע עליו,
// ו-tests/.htaccess חוסם גישה ישירה.
define('TS_DEV', true);
if ($p = getenv('TS_DB_PATH')) {
    define('TS_DB_PATH', $p);
    define('TS_CACHE_PATH', $p . '.cache');
    define('TS_SECRET_KEY_PATH', $p . '.key');
}
$users = [
    'alice' => ['sub' => '101', 'name' => 'אליס', 'email' => 'alice@test.local', 'email_verified' => true, 'platform_admin' => false],
    'bob'   => ['sub' => '102', 'name' => 'בוב', 'email' => 'bob@test.local', 'email_verified' => true, 'platform_admin' => false],
    // בעל המערכת — מנהל ראשי (platform_admin מהשער, spec-stage-a 1.1)
    'owner' => ['sub' => '100', 'name' => 'בעל המערכת', 'email' => 'owner@test.local', 'email_verified' => true, 'platform_admin' => true],
    'dave'  => ['sub' => '104', 'name' => 'דייב', 'email' => 'dave@test.local', 'email_verified' => true, 'platform_admin' => false],
    'carol' => ['sub' => '103', 'name' => 'קרול', 'email' => 'carol@test.local', 'email_verified' => true, 'platform_admin' => false],
];
$as = $_SERVER['HTTP_X_DEV_AS'] ?? $_COOKIE['dev_as'] ?? 'alice';
if (isset($users[$as])) $_SERVER['PLATFORM_USER'] = json_encode($users[$as], JSON_UNESCAPED_UNICODE);
