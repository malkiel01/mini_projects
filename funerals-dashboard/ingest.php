<?php
declare(strict_types=1);

/**
 * נקודת הקצה למערכות חיצוניות: list, export ו-import, עם Authorization: Bearer בלבד.
 * רשומה ב-"public" של app.json, כי מכונה ניגשת אליה בלי כניסה לפלטפורמה.
 * אין כאן session ואין עוגייה — בלי אסימון תקין התשובה 401.
 */
require __DIR__ . '/lib.php';

$cfg = config();
if (!bearerOk($cfg)) reply(401, ['error' => 'נדרש אסימון API תקין (Authorization: Bearer)']);
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();
if ($action === 'list' || $action === 'export') listMonth($pdo, $action, $method);
if ($action === 'import') importRecords($pdo, $method, 'api');
reply(404, ['error' => 'פעולה לא מוכרת']);
