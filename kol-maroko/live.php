<?php
/**
 * ▶️ התצוגה החיה (פרוסה F4) — המסך שעוטף את המסגרת המבודדת: מצבי מכשיר, מסוף וכלי בדיקה.
 *
 * מסמך משלו, ולא חלק מ-index.html, כדי שתחול עליו **המדיניות המלאה של C1 שלב ב** ככותרת שרת: בלי סקריפט בתוך הדף,
 * בלי eval, ו-frame-src שמתיר רק את כתובת התצוגה המבודדת (preview/). קוד העמוד רץ במסגרת, במקור אטום — לא כאן.
 * המסך עצמו מאחורי השער (אינו ב-public); הנתונים שלו מגיעים מ-api.php, בהרשאות הרגילות.
 */

declare(strict_types=1);

while (ob_get_level() > 0) @ob_end_clean();         // שום שכבה שמעל אינה מזריקה סקריפט לדף הזה (ה-CSP היה חוסם אותו ממילא)

/** המדיניות המלאה (docs/spec-v2/slices/C1-studio-csp.md §2, שלב ב). $preview — כתובת התצוגה, בלי סכמה: נכונה ב-http וב-https. */
function live_csp(string $preview): string
{
    return implode('; ', [
        "default-src 'none'", "script-src 'self'", "style-src 'self' 'unsafe-inline'", "img-src 'self' blob: data:", "media-src 'self' blob: data:",
        "font-src 'self' data:", "connect-src 'self'", "worker-src 'self' blob:", 'frame-src ' . $preview, "frame-ancestors 'self'",
        "base-uri 'self'", "object-src 'none'", "form-action 'self'",
    ]);
}

$dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/live.php'))), '/');
$host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/lib/csp.php';
header('Content-Security-Policy: ' . live_csp($host . $dir . '/preview/') . '; report-uri ' . csp_report_uri('live'));   // דוחות ההפרה ליומן (C1)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#a3322a">
  <title>תצוגה חיה · קול מרוקו</title>
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="assets/css/live.css">
</head>
<body class="lv-body">
  <div class="lv" id="lv">
    <header class="lv-top">
      <button type="button" class="icon-btn" id="lv-close" aria-label="סגירת התצוגה" hidden>✕</button>
      <div class="lv-where"><strong class="one" id="lv-title">תצוגה חיה</strong><span class="one muted" id="lv-path" dir="ltr"></span></div>
      <button type="button" class="icon-btn" id="lv-reload" title="רענון" aria-label="רענון">⟳</button>
      <button type="button" class="icon-btn" id="lv-stop" title="עצור ורענן — כשהעמוד תקוע" aria-label="עצור ורענן">⏹️</button>
      <button type="button" class="icon-btn" id="lv-pick" title="כלי הבדיקה: מצביעים על אלמנט" aria-label="כלי הבדיקה" aria-pressed="false">🔍</button>
      <a class="icon-btn" id="lv-tab" target="_blank" rel="noopener noreferrer" title="פתח בלשונית חדשה" aria-label="פתח בלשונית חדשה">↗️</a>
    </header>
    <div class="lv-devices">
      <div class="seg" id="lv-seg" role="group" aria-label="מצב מכשיר"></div>
      <span class="lv-size muted" id="lv-size" dir="ltr"></span>
    </div>
    <p class="lv-limits" id="lv-limits" role="note" hidden></p>
    <div class="lv-stage" id="lv-stage">
      <div class="lv-frame" id="lv-frame"><div class="lv-grip" id="lv-grip" role="separator" aria-orientation="vertical" aria-label="גרירה לשינוי הרוחב" tabindex="0"></div></div>
      <p class="empty" id="lv-msg">טוען…</p>
    </div>
    <section class="lv-panel" id="lv-panel" aria-label="מסוף וכלי בדיקה">
      <div class="lv-panel-head">
        <div class="seg" role="tablist">
          <button type="button" role="tab" id="lv-t-console" aria-selected="true">מסוף <small id="lv-count"></small></button>
          <button type="button" role="tab" id="lv-t-inspect" aria-selected="false">בדיקה</button>
        </div>
        <button type="button" class="btn sm" id="lv-clear">ניקוי</button>
        <button type="button" class="icon-btn" id="lv-fold" aria-label="קיפול החלונית" aria-expanded="true">▾</button>
      </div>
      <ol class="lv-console" id="lv-console" aria-live="polite"></ol>
      <div class="lv-inspect" id="lv-inspect" hidden><p class="empty">לוחצים 🔍 ומצביעים על אלמנט בעמוד.</p></div>
    </section>
  </div>
  <div class="toast" role="status" hidden></div>
  <script src="assets/js/live.js" defer></script>
</body>
</html>
