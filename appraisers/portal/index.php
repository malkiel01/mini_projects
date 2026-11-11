<?php
/**
 * דף הפורטל לנמען (אפיון 07 §5). דף ציבורי ונפרד: אינו טוען את קוד המערכת, אין בו קישור אליה, ואין בו שום משאב חיצוני.
 * נשלח ב-PHP ולא כקובץ סטטי — כדי שהכותרות (CSP, no-referrer, no-store, noindex) ייקבעו כאן, בלי תלות בהגדרות השרת.
 * האסימון נמצא אחרי ה-# בכתובת: הוא אינו מגיע לשרת עם הבקשה לדף, והדף שולח אותו רק בגוף הבקשות ל-API.
 *
 * **שום סקריפט זר לא רץ כאן — גם לא של הפלטפורמה.** השער (platform/gate-prepend.php → lib/pwa.php, inject_html) מוסיף לכל דף
 * PHP של אפליקציה מחוברת את יומן האבחון שלו (diag.js), ששולח את הכתובת כולל ה-# ושם עוגייה אצל הגולש. בדף הזה ה-# הוא האסימון.
 * שתי הגנות, כל אחת מספיקה לבדה:
 *  1. inject_html מדלג על דף שכבר מכיל את שם הסקריפט — ולכן השם מופיע למטה בהערת HTML (ביקורת 07, 🔴1). לא למחוק אותה.
 *  2. ה-CSP מתיר סקריפט רק עם ה-nonce של הבקשה הזו: גם אם ההזרקה תשתנה, תג <script> שלא אנחנו כתבנו לא ירוץ.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'self'; img-src 'self' blob:; frame-src blob:; connect-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
$v = '0.8';
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#2d4a8a">
<title>העלאת מסמכים</title>
<!-- הדף הזה אינו טוען את platform/assets/diag.js ולא את pwa-beacon.js: הכתובת שלו נושאת אסימון אישי של נמען. השורה הזו היא שמונעת מהשער להוסיף אותם. -->
<link rel="stylesheet" href="portal.css?v=<?= $v ?>">
</head>
<body>
<main id="main" tabindex="-1"><p class="boot">טוען…</p></main>
<div class="toast" role="status" hidden></div>
<script nonce="<?= $nonce ?>" src="portal.js?v=<?= $v ?>"></script>
</body>
</html>
