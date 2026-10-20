<?php
/**
 * העורך בתוך iframe של אפליקציה מארחת (platform/docs/embed.md, פרק 4).
 * embed.php?t=<טוקן חד-פעמי>: הדף שולח frame-ancestors של הלקוח בלבד, והסקריפט מחליף את הטוקן בסשן
 * (embed_open), מוחק אותו מהכתובת ומחזיק את הסשן בזיכרון בלבד — לא בעוגייה.
 * טוקן פג / נוצל (למשל רענון של ה-iframe) → דף קצר ששולח למארחת "expired", והיא מבקשת embed_session חדש.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib/embed.php';

$t = (string)($_GET['t'] ?? '');
$tok = token_peek($t);
$client = $tok ? client_get($tok['client']) : null;
$valid = $tok && $client && $client['status'] === 'active';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// אין סוד בדף הזה עצמו (התוכן נטען רק אחרי embed_open), לכן דף השגיאה מותר בכל מסגרת — כדי שיוכל לבשר "expired".
$ancestors = $valid ? implode(' ', client_origins($client)) : '*';
header("Content-Security-Policy: frame-ancestors $ancestors; default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';"
    . " img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'");
$v = @filemtime(__DIR__ . '/assets/js/editor.js') ?: 1;
?><!doctype html>
<html lang="he" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex">
  <title>עורך התמלילים</title>
  <link rel="stylesheet" href="assets/css/fonts.css?v=<?= $v ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
  <link rel="stylesheet" href="assets/css/editor.css?v=<?= $v ?>">
</head>
<body class="embed">
  <div id="app" data-valid="<?= $valid ? '1' : '0' ?>" data-origins="<?= htmlspecialchars($valid ? implode(' ', client_origins($client)) : '', ENT_QUOTES) ?>">
    <p class="empty" id="boot"><?= $valid ? 'טוען את העורך…' : 'הקישור לעורך פג — פותחים אותו מחדש…' ?></p>
  </div>
  <div class="toast" role="status" hidden></div>
  <script type="module" src="assets/js/embed.js?v=<?= $v ?>"></script>
</body>
</html>
