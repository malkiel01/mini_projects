<?php
/**
 * מרכז הניהול — תצוגת "מרכז" בדשבורד החבילה (האפיון creative-suite/docs/spec/command-center.md, סעיף 5).
 *
 * הדף עצמו הוא מעטפת: הנתונים מגיעים מהלוח "מטלות לקלוד" (מקור האמת למטלות, הכרעה 5), בקריאות מהדפדפן עם
 * עוגיית הלוח של בעל המערכת. אין כאן אסימון ואין כאן נתונים משלו. מה שעוד חסר בלוח — מסומן "ממתין ללוח".
 */
declare(strict_types=1);
require __DIR__ . '/lib/board.php';

$boot = [
    'api'     => '../../claude-tasks/api.php',
    'board'   => '../../claude-tasks/',
    'prefix'  => SUITE_PREFIX,
    'icons'   => suite_icons(),
    'catalog' => APPROVAL_TYPES,
];
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>מרכז — חבילת היצירה</title>
<link rel="icon" href="../assets/icon.png" type="image/png">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<a class="skip" href="#main">דילוג לתוכן</a>
<header class="top">
  <a class="icon-btn" href="./" aria-label="חזרה לדשבורד">→</a>
  <div class="titles"><h1 class="one">מרכז הניהול</h1><div class="sub one" id="sub">מהלוח "מטלות לקלוד"</div></div>
  <button type="button" class="icon-btn" id="reload" aria-label="רענון">⟳</button>
</header>
<main id="main" tabindex="-1">
  <div id="state" class="summary" aria-live="polite"><p class="muted">טוען מהלוח…</p></div>

  <div id="app" hidden>
    <div class="seg-tabs" role="tablist" aria-label="אזורי המרכז">
      <button type="button" role="tab" id="t-wait" aria-controls="p-wait" aria-selected="true">❓ ממתין לך <small id="n-wait"></small></button>
      <button type="button" role="tab" id="t-proj" aria-controls="p-proj" aria-selected="false">📁 פרויקטים <small id="n-proj"></small></button>
      <button type="button" role="tab" id="t-pol" aria-controls="p-pol" aria-selected="false">🔐 מדיניות</button>
      <button type="button" role="tab" id="t-route" aria-controls="p-route" aria-selected="false">💻 מסלול</button>
    </div>

    <section id="p-wait" role="tabpanel" aria-labelledby="t-wait">
      <p class="hint">שאלות של הצ'אטים, וגם מה שרק אתה יכול לעשות. התשובה נרשמת על המטלה, והמטלה חוזרת לראש התור של הצ'אט.</p>
      <ul class="list asks" id="asks"></ul>
      <details class="group sub" data-id="center-answered" id="answered-wrap" hidden>
        <summary><h3>↩️ נענו, הצ'אט עוד לא לקח <span class="count" id="n-answered"></span></h3></summary>
        <ul class="list goals" id="answered"></ul>
        <p class="hint">הצ'אט יקלוט את התשובה בבדיקה הבאה שלו (עד ~15 דק'). הסימון "↩️ נמסר" יופיע כשהלוח יוסיף את <code>inbox</code>.</p>
      </details>
      <p class="pending-note">🔐 <b>אישורים</b> (ארבעה כפתורים: פעם אחת · תמיד · דחה · דחה תמיד) יופיעו כאן כשהלוח יוסיף את המצב <code>awaiting_approval</code>.</p>
    </section>

    <section id="p-proj" role="tabpanel" aria-labelledby="t-proj" hidden>
      <p class="hint">כל פרויקט בחבילה, עם המטלות שלו לפי מצב. הקשה פותחת את השלבים.</p>
      <ul class="list" id="projects"></ul>
      <p class="hint">⏳ ממתינה · 🔧 בפיתוח · ❓ שאלה לך · ↩️ נענתה · ✅ הושלמה.</p>
    </section>

    <section id="p-pol" role="tabpanel" aria-labelledby="t-pol" hidden>
      <p class="hint">סוגי האישור שהצ'אטים מבקשים, ומה הוחלט לכל סוג. "תמיד" חל על כל הצ'אטים, ונכתב גם ל-<code>.claude/settings.json</code> ב-PR שאתה רואה.</p>
      <ul class="list goals" id="policy"></ul>
      <p class="pending-note">ההחלטות "תמיד" / "אסור תמיד" והיומן יעבדו כשהלוח יוסיף את <code>policy</code>. עד אז כל סוג הוא "פעם אחת".</p>
    </section>

    <section id="p-route" role="tabpanel" aria-labelledby="t-route" hidden>
      <p class="hint">איפה רץ הפיתוח: 💻 Claude Code (הצ'אטים) או ☁️ Claude Platform (המנוע ב-GitHub Actions). מטלה באמצע מסתיימת במסלול שבו התחילה.</p>
      <ul class="list goals" id="routes"></ul>
      <p class="pending-note">המתגים, העלות והתקרה יעבדו כשהלוח יוסיף <code>route</code> ומונה עלות (שלבים 2 ו-8). עד אז הכול 💻 Claude Code.</p>
    </section>
  </div>
</main>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script id="boot" type="application/json"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/center.js" defer></script>
</body>
</html>
