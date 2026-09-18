<?php
/**
 * יעד קישור איפוס הסיסמה.
 *
 * ‏GET מציג טופס, POST מחליף את הסיסמה. האסימון נשאר בשדה מוסתר ולא
 * בכתובת בזמן ה-POST, כדי שלא יישאר בהיסטוריית הדפדפן אחרי ההחלפה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$done  = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($token === '') throw new AppError('הקישור חסר');
        $pass = (string) ($_POST['password'] ?? '');
        if ($pass !== (string) ($_POST['password2'] ?? '')) {
            throw new AppError('שתי הסיסמאות אינן זהות');
        }
        resetPassword($token, $pass);
        $done = true;
    } catch (AppError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('recipes-app reset: ' . $e->getMessage());
        $error = 'שגיאת שרת';
    }
}

$esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>איפוס סיסמה · אפליקציית מתכונים</title>
<meta name="theme-color" content="#0f8a4f">
<link rel="icon" href="./assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./assets/css/app.css">
</head>
<body class="centered">
<main class="card">
  <h1>איפוס סיסמה</h1>
  <?php if ($done): ?>
    <p class="note note--ok">הסיסמה הוחלפה. אפשר להיכנס.</p>
    <a class="btn btn--primary" href="./index.html">לכניסה</a>
  <?php else: ?>
    <?php if ($error !== null): ?>
      <p class="note note--err"><?= $esc($error) ?></p>
    <?php endif; ?>
    <form method="post" action="./reset.php" class="form">
      <input type="hidden" name="token" value="<?= $esc($token) ?>">
      <label>סיסמה חדשה
        <input type="password" name="password" minlength="8" required autocomplete="new-password">
      </label>
      <label>שוב, לאימות
        <input type="password" name="password2" minlength="8" required autocomplete="new-password">
      </label>
      <p class="muted">8 תווים לפחות.</p>
      <button class="btn btn--primary" type="submit">החלף סיסמה</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
