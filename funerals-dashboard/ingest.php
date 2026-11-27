<?php
declare(strict_types=1);

/**
 * נקודת הקצה למערכות חיצוניות: list, export ו-import, עם Authorization: Bearer בלבד.
 * רשומה ב-"public" של app.json, כי מכונה ניגשת אליה בלי כניסה לפלטפורמה.
 * אין כאן session ואין עוגייה — בלי אסימון תקין התשובה 401.
 * עובדת עם האסימון הקיים (data/config.php) וכותבת לארגון 1 בלבד (הכרעה ה9); ייבוא נרשם ב-events עם source: import.
 */
require __DIR__ . '/lib.php';

const INGEST_ORG = 1;
$t0 = microtime(true);
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
register_shutdown_function(function () use ($t0, $action, $method) {
    logRequest($method, 'ingest:' . $action, http_response_code() ?: 200, (int)round((microtime(true) - $t0) * 1000), null, (string)($GLOBALS['fd_err'] ?? ''));
});
$cfg = config();
if (!bearerOk($cfg)) reply(401, ['error' => 'נדרש אסימון API תקין (Authorization: Bearer)']);
$pdo = db();
if ($action === 'list' || $action === 'export') listMonth($pdo, $action, $method, INGEST_ORG);
if ($action === 'import') importRecords($pdo, $method, INGEST_ORG, ['id' => null, 'actor' => 'api'], 'import');
reply(404, ['error' => 'פעולה לא מוכרת']);
