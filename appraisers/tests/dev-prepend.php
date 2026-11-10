<?php
// פיתוח ובדיקות מקומיות בלבד (php -S … -d auto_prepend_file=…): מדמה את השער של המעטפת.
// PLATFORM_USER נקבע לפי הכותרת X-Dev-As או העוגייה dev_as: "owner" (ברירת המחדל), "none" (בלי זהות, המעטפת
// מחוברת), "detached" / "unavailable" (מצב המעטפת), או sub:שם:מייל (עם "!" בסוף — מייל לא מאומת).
// בשרת הקובץ קיים אבל לא בשימוש: אף .user.ini לא מצביע עליו, tests/.htaccess חוסם גישה ישירה, והוא רץ רק תחת
// שרת הפיתוח של PHP.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
define('APR_DEV', true);
if ($p = getenv('APR_DATA_DIR')) define('APR_DATA_DIR', $p);
// בפיתוח אין מעטפת לשאול, ואין https לבדיקה עצמית
$as = (string)($_SERVER['HTTP_X_DEV_AS'] ?? ($_COOKIE['dev_as'] ?? 'owner'));
// במצב מנותק המעטפת המדומה פותחת גם כניסה בסיסמה, כדי שאפשר יהיה לראות את מסך הכניסה
$GLOBALS['apr_http'] = fn(string $url) => str_contains($url, 'sso.php')
    ? [200, $as === 'detached' ? '{"connected":false,"login":{"platform":true,"password":true}}' : '{"connected":true}'] : [403, ''];
unset($_SERVER['PLATFORM_USER']);
if ($as === 'owner') {
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => '1', 'name' => 'הבעלים', 'email' => 'owner@example.com', 'email_verified' => true, 'platform_admin' => true, 'granted' => true]);
} elseif ($as === 'detached' || $as === 'unavailable') {
    define('APR_DEV_PLATFORM', $as);
} elseif ($as === 'none') {
    define('APR_DEV_PLATFORM', 'connected');
} else {
    [$sub, $name, $email] = array_pad(explode(':', $as, 3), 3, '');
    $verified = !str_ends_with($email, '!');
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => $sub, 'name' => $name, 'email' => rtrim($email, '!') ?: null, 'email_verified' => $verified, 'platform_admin' => false, 'granted' => true]);
}
