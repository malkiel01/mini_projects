<?php
/**
 * קריאת יומן אבחון לפי הקוד (?id=<12 תווי hex>) — כדי שקלוד יוכל לנתח בעיה שהמשתמש שלח מהטלפון.
 * ציבורי (app.json → public): הקוד אקראי (48 ביט) והוא ההרשאה, כמו קישור שיתוף. ביומן אין טקסטים
 * ואין תמונות — רק נגיעות, בחירות, גדלים וזום (js/log.js). יומן נמחק אחרי 30 יום.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$id = (string)($_GET['id'] ?? '');
$file = STUDIO_DATA . "/logs/$id.json";
if (!preg_match('/^[a-f0-9]{12}$/', $id) || !is_file($file)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'אין יומן כזה'], JSON_UNESCAPED_UNICODE);
    exit;
}
readfile($file);
