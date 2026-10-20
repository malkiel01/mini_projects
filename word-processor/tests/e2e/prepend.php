<?php
/**
 * לבדיקות הדפדפן בלבד (tests/e2e/run.mjs מריץ php -S עם auto_prepend_file=הקובץ הזה). לא נטען בשרת:
 * tests/ חסום ב-.htaccess, ואין auto_prepend בפריסה.
 * מסד זמני, http מקומי, ומשתמש מדומה מהשער (עוגייה nouser=1 — בלי משתמש).
 */
if (PHP_SAPI !== 'cli-server') return;
putenv('WP_DATA_DIR=' . getenv('WP_E2E_DATA'));
putenv('WP_BASE_URL=' . getenv('WP_E2E_BASE'));
putenv('WP_DETACHED=1');
putenv('WP_ALLOW_HTTP=1');
if (!isset($_COOKIE['nouser'])) {
    $_SERVER['PLATFORM_USER'] = json_encode(['sub' => '17', 'name' => 'דנה לוי', 'email' => 'dana@example.com', 'email_verified' => true], JSON_UNESCAPED_UNICODE);
}
