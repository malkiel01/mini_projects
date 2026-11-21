<?php
/**
 * X1 — הנתב של בדיקות הדפדפן, עם שירות בינה מדומה: KOL_AI_ENDPOINT ← KOL_TEST_AI (שרת מקומי בתוך x1.cjs). בלי קריאה אמיתית ל-API.
 * כל השאר — tests/router.php כמו שהוא.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || !getenv('KOL_TEST_DATA')) { http_response_code(404); exit; }
if (($ai = getenv('KOL_TEST_AI')) && preg_match('~^http://127\.0\.0\.1:\d+/$~', $ai)) define('KOL_AI_ENDPOINT', $ai);
require dirname(__DIR__) . '/router.php';
