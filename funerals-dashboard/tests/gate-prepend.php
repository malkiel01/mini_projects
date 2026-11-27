<?php
// בדיקות מקומיות בלבד (php -S … -d auto_prepend_file=tests/gate-prepend.php): מדמה את השער של הפלטפורמה.
// הזהות נקראת מקובץ שהנתיב אליו במשתנה הסביבה FD_TEST_IDENTITY_FILE של תהליך שרת הפיתוח — לא מכותרת ולא
// מעוגייה, ולכן בקשה לא יכולה לבחור זהות. קובץ ריק או חסר = אין PLATFORM_USER (כמו כתובת ציבורית).
// הקובץ לא נפרס (tests/ ב-exclude של deploy.yml), ורץ רק תחת שרת הפיתוח של PHP.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
unset($_SERVER['PLATFORM_USER']);
$fdIdentityFile = getenv('FD_TEST_IDENTITY_FILE');
if (is_string($fdIdentityFile) && $fdIdentityFile !== '' && is_file($fdIdentityFile)) {
    $fdIdentity = trim((string)file_get_contents($fdIdentityFile));
    if ($fdIdentity !== '') $_SERVER['PLATFORM_USER'] = $fdIdentity;
}
unset($fdIdentityFile, $fdIdentity);
