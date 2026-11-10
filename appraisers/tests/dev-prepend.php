<?php
// פיתוח ובדיקות מקומיות בלבד (php -S … עם auto_prepend_file): מדמה את השער — PLATFORM_USER לפי העוגייה dev_as
// (או הכותרת X-Dev-As): "owner", "none", או sub:שם:מייל. בשרת הקובץ קיים אבל לא בשימוש: אף .user.ini לא מצביע
// עליו, ו-tests/.htaccess חוסם גישה ישירה.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
define('APR_DEV', true);
if ($p = getenv('APR_DATA_DIR')) define('APR_DATA_DIR', $p);
$as = (string)($_SERVER['HTTP_X_DEV_AS'] ?? ($_COOKIE['dev_as'] ?? 'owner'));
unset($_SERVER['PLATFORM_USER']);
if ($as === 'owner') {
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => '1', 'name' => 'הבעלים', 'email' => 'owner@example.com', 'email_verified' => true, 'platform_admin' => true, 'granted' => true]);
} elseif ($as !== 'none') {
    [$sub, $name, $email] = array_pad(explode(':', $as, 3), 3, '');
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => $sub, 'name' => $name, 'email' => $email ?: null, 'email_verified' => true, 'platform_admin' => false, 'granted' => true]);
}
