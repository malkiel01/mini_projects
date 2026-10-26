<?php
/**
 * המפתח הציבורי להזמנות לכלים הדיגיטליים
 * api/platform-key.php
 *
 * ה-key_url שב-app.json ("provision"): הפלטפורמה מורידה מכאן את
 * המפתח שבו היא בודקת את החתימה על בקשות ההזמנה (platform/docs/sso.md,
 * סעיף 6). כתובת ציבורית - הפלטפורמה ניגשת אליה שרת-לשרת, בלי כניסה.
 * המפתח הסודי נשאר במסד (app_settings) ולעולם לא יוצא מהשרת.
 */

require_once '../config.php';
require_once '../includes/platform.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    echo json_encode(provisionKeyPayload(getDBConnection()), JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    error_log('platform key: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'המפתח אינו זמין']);
}
