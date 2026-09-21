<?php
/**
 * יעד קישור האימות שנשלח בדוא"ל.
 *
 * דף HTML ולא JSON, כי המשתמש מגיע לכאן מלחיצה על קישור בתוכנת הדוא"ל
 * שלו — לא מתוך האפליקציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$token = (string) ($_GET['token'] ?? '');
$okMsg = null;
$error = null;

try {
    if ($token === '') throw new AppError('הקישור חסר');
    verifyEmail($token);
    $okMsg = 'החשבון אומת. אפשר להיכנס.';
    logEvent('info', 'verify-email', 'חשבון אומת דרך הקישור');
} catch (AppError $e) {
    $error = $e->getMessage();
    logEvent('warn', 'verify-email', $error);
} catch (Throwable $e) {
    error_log('recipes-app verify: ' . $e->getMessage());
    logEvent('error', 'exception', get_class($e) . ': ' . $e->getMessage(), ['action' => 'verify-email']);
    $error = 'שגיאת שרת';
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>אימות חשבון · אפליקציית מתכונים</title>
<meta name="theme-color" content="#0f8a4f">
<link rel="icon" href="./assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./assets/css/app.css">
</head>
<body class="centered">
<main class="card">
  <h1>אימות חשבון</h1>
  <?php if ($okMsg !== null): ?>
    <p class="note note--ok"><?= htmlspecialchars($okMsg, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="btn btn--primary" href="./index.html">לכניסה</a>
  <?php else: ?>
    <p class="note note--err"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="muted">קישור אימות תקף 24 שעות ולשימוש אחד. אם פג — יש לפנות למנהל.</p>
    <a class="btn" href="./index.html">חזרה</a>
  <?php endif; ?>
</main>
</body>
</html>
