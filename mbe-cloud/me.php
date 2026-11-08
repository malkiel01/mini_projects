<?php
/**
 * "החשבון שלי בענן" — לכל משתמש פלטפורמה מחובר, גם בלי כונן (docs/spec-5-account-tiers.md ש2, סעיף 5).
 * ב-"signed_in" של app.json: השער מעביר כל משתמש פעיל, וכותב PLATFORM_USER עם granted. index.html נשאר רק עם הרשאה.
 *
 * הדף בלבד. השרת שלו — me-api.php (4.1א), ברשימה סגורה: api.php לא ב-signed_in (משתמש בלי כונן לא מגיע אליו).
 * אין כאן קבצים, גופנים או ניהול.
 * החיבורים — לקריאה בלבד (spec-6 4.1): בלי ניתוק ובלי מתגים.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/clients.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

try {
    requireAccount();   // מאחורי השער (signed_in): בלי זהות — 401
} catch (AppError $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());
}
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2d4a8a">
<title>החשבון שלי — ענן MBE</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/ui-core/ui.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="top"><div class="titles"><h1>☁️ החשבון שלי בענן</h1><p class="sub" id="who"></p></div></header>
<main id="main" class="me">
  <p class="hint" id="drive-line"></p>
  <dl class="cells" id="me-cells"></dl>
  <h2 class="sec-title">🔗 חיבורים</h2>
  <p class="hint">האפליקציות שמחוברות לענן שלך, ומה כל אחת יכולה לעשות. את השכבות קובע מנהל הענן, לכל המשתמשים.</p>
  <ul class="list" id="links"></ul>
  <h2 class="sec-title">🧩 נתוני אפליקציות</h2>
  <p class="hint">ההגדרות והנתונים שאפליקציות שמרו בענן בשבילך. "🗑️ מחק נתונים" מוחק אותם; האפליקציה מתחילה מאפס.</p>
  <ul class="list" id="appdata"></ul>
  <h2 class="sec-title">🔑 החשבון</h2>
  <div class="row-btns me-acts">
    <button class="btn ghost" id="export" type="button"><span aria-hidden="true">⬇️</span> ייצוא כל הנתונים שלי</button>
    <button class="btn ghost danger" id="delete-acc" type="button">מחיקת החשבון בענן</button>
  </div>
</main>
<script type="module" src="assets/js/me.js"></script>
</body>
</html>
