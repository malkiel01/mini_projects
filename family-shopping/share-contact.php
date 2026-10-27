<?php
/**
 * יעד השיתוף של איש קשר (share_target ב-manifest.webmanifest)
 * share-contact.php
 *
 * באנשי הקשר בטלפון: איש קשר ← "שיתוף" ← קניות משותפות. אנדרואיד שולח
 * לכאן POST עם קובץ vCard (או טקסט). מפרקים אותו ומפנים (303) לאנשי
 * הקשר, עם הפרטים אחרי # - שם נפתח "איש קשר חדש" ממולא.
 *
 * למה אחרי #: מה שאחרי # לא נשלח לשרת ולא נשמר ביומני הגישה, ושם, מייל
 * וטלפון של אדם שלישי לא צריכים להיות שם. אין כאן שינוי במסד - רק מעבר.
 */

require_once 'config.php';
// POST מאנדרואיד בלי טוקן CSRF - והדף לא משנה דבר במסד (ראו auth_check.php)
define('AUTH_NO_STATE_CHANGE', true);
require_once 'includes/auth_check.php';
require_once 'includes/vcard.php';

$contact = ['name' => '', 'email' => '', 'phone' => ''];

$file = $_FILES['contact'] ?? null;
if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    && (int)$file['size'] > 0 && (int)$file['size'] < 512 * 1024) {
    $contact = parseVcard((string)file_get_contents($file['tmp_name']));
} else {
    $text = trim(($_POST['title'] ?? '') . "\n" . ($_POST['text'] ?? '') . "\n" . ($_POST['url'] ?? ''));
    if ($text !== '') {
        $contact = parseSharedText($text);
    }
}

if ($contact['name'] === '' && $contact['email'] === '' && $contact['phone'] === '') {
    $contact['error'] = 'השיתוף הגיע ריק - לא נמצאו שם, מייל או טלפון';
}

header('Location: ' . APP_BASE_PATH . '/contacts.php#shared='
    . rawurlencode(json_encode($contact, JSON_UNESCAPED_UNICODE)), true, 303);
exit;
