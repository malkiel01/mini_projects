<?php
/**
 * זהות פיתוח לבדיקות דפדפן מקומיות בלבד. מאז F11, גם php -S (cli-server) דורש PLATFORM_USER מורשה, כמו השרת.
 *   php -S 127.0.0.1:8765 -t . -d auto_prepend_file=$PWD/cyber-academy/tests/browser/dev-identity.php   (נתיב מוחלט)
 * לא פועל בשום SAPI אחר, ובשרת אין לו שימוש (tests/ חסום, ואין auto_prepend אליו).
 */
if (PHP_SAPI !== 'cli-server') return;
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => 'dev-local', 'granted' => true]);
