<?php
/**
 * העטיפה והעיצוב של הפאנל.
 *
 * מהנייד קודם, לא דסקטופ מוקטן: הפאנל מנוהל בפועל מהטלפון, וטבלה
 * רחבה שגולשת מהמסך היא טבלה שאי אפשר להשתמש בה. כל טבלה כאן הופכת
 * לכרטיסים מתחת ל-640 פיקסלים.
 *
 * בלי מנוע תבניות ובלי CSS חיצוני — הפאנל הוא חמישה מסכים, וכל תלות
 * הייתה עולה יותר ממה שהיא חוסכת.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog.php';

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function layoutTop(string $title, ?array $admin = null): void {
    ?><!doctype html>
<html lang="he" dir="rtl">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title><?= h($title) ?> · ניהול הדפדפן</title>
<style>
  :root {
    --bg:#f4f5f7; --card:#fff; --ink:#14161a; --dim:#636b7a; --line:#e2e6ec;
    --brand:#2563eb; --brand-ink:#fff; --ok:#15803d; --ok-bg:#dcfce7;
    --stop:#b91c1c; --stop-bg:#fee2e2; --warn:#a16207; --warn-bg:#fef3c7;
    --shadow:0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.1);
    --r:12px;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg:#0f1115; --card:#171a21; --ink:#e8eaee; --dim:#98a1b2; --line:#282d38;
      --brand:#4f8bff; --ok:#4ade80; --ok-bg:#0d2f1c; --stop:#f87171; --stop-bg:#3a1414;
      --warn:#fbbf24; --warn-bg:#3a2c0a; --shadow:none;
    }
  }
  * { box-sizing:border-box; }
  html { -webkit-text-size-adjust:100%; }
  body {
    margin:0; background:var(--bg); color:var(--ink);
    font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
    padding-bottom:env(safe-area-inset-bottom);
  }
  a { color:var(--brand); }

  /* ── כותרת ── */
  .top {
    position:sticky; top:0; z-index:20; background:var(--card);
    border-bottom:1px solid var(--line); padding:10px 16px;
    display:flex; gap:12px; align-items:center; flex-wrap:wrap;
  }
  .top h1 { font-size:16px; margin:0; font-weight:700; }
  .top nav { display:flex; gap:4px; margin-inline-start:auto; flex-wrap:wrap; }
  .top nav a {
    text-decoration:none; padding:7px 12px; border-radius:8px; font-size:14px;
    color:var(--dim);
  }
  .top nav a:hover, .top nav a[aria-current] { background:var(--bg); color:var(--ink); }

  main { max-width:860px; margin:0 auto; padding:16px 14px 60px; }

  /* ── כרטיס ── */
  .card {
    background:var(--card); border:1px solid var(--line); border-radius:var(--r);
    padding:18px; margin-bottom:14px; box-shadow:var(--shadow);
  }
  .card > h2 { margin:0 0 2px; font-size:17px; }
  .hint { margin:0 0 16px; color:var(--dim); font-size:13.5px; }
  .card > h2 + .hint { margin-top:2px; }

  /* ── מפוחית: כל אזור נפתח בנפרד, בלי JS ── */
  details.sec {
    background:var(--card); border:1px solid var(--line); border-radius:var(--r);
    margin-bottom:12px; overflow:hidden; box-shadow:var(--shadow);
  }
  details.sec > summary {
    padding:16px 18px; cursor:pointer; font-weight:650; font-size:16px;
    display:flex; gap:10px; align-items:center; list-style:none;
    -webkit-tap-highlight-color:transparent;
  }
  details.sec > summary::-webkit-details-marker { display:none; }
  details.sec > summary::after {
    content:"⌄"; margin-inline-start:auto; color:var(--dim); font-size:20px;
    transition:transform .15s; line-height:1;
  }
  details.sec[open] > summary::after { transform:rotate(180deg); }
  details.sec[open] > summary { border-bottom:1px solid var(--line); }
  .sec-body { padding:18px; }
  .sec-tag { font-weight:400; font-size:13px; color:var(--dim); }

  /* ── טפסים ── */
  label { display:block; margin-bottom:14px; font-size:14px; }
  label > span.lbl { display:block; margin-bottom:5px; color:var(--dim); font-size:13.5px; }
  input[type=text], input[type=password], input[type=number], input[type=date],
  input[type=time], select, textarea {
    width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:9px;
    font:inherit; background:var(--bg); color:var(--ink); min-height:44px;
  }
  input:focus, select:focus, textarea:focus {
    outline:2px solid var(--brand); outline-offset:1px; border-color:var(--brand);
  }
  .grid { display:grid; gap:0 14px; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); }

  /* ── כפתורים: 44 פיקסלים מינימום, כדי שיהיו לחיצים באצבע ── */
  .btn {
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    min-height:44px; padding:10px 18px; border:1px solid var(--line);
    background:var(--card); color:var(--ink); border-radius:10px; cursor:pointer;
    font:inherit; font-weight:550; text-decoration:none;
  }
  .btn:active { transform:translateY(1px); }
  .btn--go { background:var(--brand); border-color:var(--brand); color:var(--brand-ink); }
  .btn--stop { border-color:var(--stop); color:var(--stop); background:transparent; }
  .btn--sm { min-height:36px; padding:6px 12px; font-size:13.5px; }
  .btn--wide { width:100%; }

  /* ── בחירה בין אפשרויות: כרטיס לכל אפשרות, לא רדיו זעיר ── */
  .pick { display:grid; gap:10px; margin-bottom:6px; }
  .pick label {
    display:flex; gap:12px; align-items:flex-start; margin:0; padding:14px;
    border:1.5px solid var(--line); border-radius:10px; cursor:pointer;
  }
  .pick label:has(input:checked) {
    border-color:var(--brand); background:color-mix(in srgb, var(--brand) 7%, transparent);
  }
  .pick input { margin:2px 0 0; width:20px; height:20px; flex:none; accent-color:var(--brand); }
  .pick b { display:block; font-weight:650; }
  .pick small { color:var(--dim); font-size:13px; }

  /* ── בורר שלוש-מצבים לקטגוריה ── */
  .cats { display:grid; gap:8px; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); }
  .cat {
    display:flex; align-items:center; gap:10px; padding:8px 10px;
    border:1px solid var(--line); border-radius:10px; background:var(--bg);
  }
  .cat .nm { flex:1; font-size:14.5px; min-width:0; }
  .cat .nm i { font-style:normal; margin-inline-end:6px; }
  .seg { display:flex; border:1px solid var(--line); border-radius:8px; overflow:hidden; flex:none;
         background:var(--card); }
  .seg label {
    margin:0; padding:7px 10px; font-size:12.5px; cursor:pointer; color:var(--dim);
    border-inline-start:1px solid var(--line); min-height:36px; display:flex; align-items:center;
  }
  .seg label:first-child { border-inline-start:0; }
  .seg input { position:absolute; opacity:0; pointer-events:none; }
  .seg label:has(input:checked) { color:#fff; font-weight:650; }
  .seg label.y:has(input:checked) { background:var(--ok); }
  .seg label.n:has(input:checked) { background:var(--stop); }
  .seg label.o:has(input:checked) { background:var(--dim); }

  /* ── תגיות סימון ── */
  .chips { display:flex; flex-wrap:wrap; gap:8px; }
  .chips label {
    margin:0; display:flex; align-items:center; gap:7px; padding:9px 13px;
    border:1.5px solid var(--line); border-radius:999px; cursor:pointer; font-size:14px;
    background:var(--bg); min-height:42px;
  }
  .chips label:has(input:checked) {
    border-color:var(--stop); background:var(--stop-bg); color:var(--stop); font-weight:600;
  }
  .chips input { accent-color:var(--stop); width:18px; height:18px; }

  .switch { display:flex; gap:11px; align-items:center; margin-bottom:12px; font-size:14.5px; }
  .switch input { width:20px; height:20px; accent-color:var(--brand); flex:none; }

  /* ── טבלה שהופכת לכרטיסים בנייד ── */
  table { width:100%; border-collapse:collapse; font-size:14.5px; }
  th, td { text-align:right; padding:11px 8px; border-bottom:1px solid var(--line); }
  th { color:var(--dim); font-weight:600; font-size:12.5px; text-transform:none; }
  @media (max-width:640px) {
    table, tbody, tr, td { display:block; width:100%; }
    thead { display:none; }
    tr { border:1px solid var(--line); border-radius:10px; margin-bottom:10px; padding:6px 10px; }
    td { border:0; padding:6px 0; display:flex; gap:10px; align-items:center; }
    td::before {
      content:attr(data-l); color:var(--dim); font-size:12.5px; min-width:82px; flex:none;
    }
    td:empty { display:none; }
  }

  .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12.5px;
          font-weight:600; white-space:nowrap; }
  .pill--ok { background:var(--ok-bg); color:var(--ok); }
  .pill--wait { background:var(--warn-bg); color:var(--warn); }
  .pill--stop { background:var(--stop-bg); color:var(--stop); }
  .pill--mute { background:var(--bg); color:var(--dim); }

  .note { padding:12px 15px; border-radius:10px; margin-bottom:14px; font-size:14.5px; }
  .note--ok { background:var(--ok-bg); color:var(--ok); }
  .note--bad { background:var(--stop-bg); color:var(--stop); }

  code { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px;
         direction:ltr; unicode-bidi:embed; display:inline-block; word-break:break-all; }
  .acts { display:flex; gap:7px; flex-wrap:wrap; }
  .acts form { display:inline; }
  hr.sep { border:0; border-top:1px solid var(--line); margin:18px 0; }

  /*
   * סרגל שמירה שנשאר בהישג יד גם בטופס ארוך.
   *
   * ‏margin-bottom שלילי היה מושך את הסעיף שאחריו אל מתחת לסרגל
   * ומסתיר אותו לגמרי — כך נעלם כל אזור יוטיוב מהמסך. סרגל דביק
   * חייב להשאיר לתוכן שאחריו את המקום שלו.
   */
  .savebar {
    position:sticky; bottom:0; background:var(--card); border-top:1px solid var(--line);
    padding:12px 14px calc(12px + env(safe-area-inset-bottom));
    margin:16px -14px 14px; z-index:15;
    box-shadow:0 -6px 14px rgba(0,0,0,.10);
  }
</style>
<header class="top">
  <h1>ניהול הדפדפן</h1>
  <?php if ($admin): ?>
  <nav>
    <a href="index.php">משתמשים</a>
    <a href="categories.php">קטלוג</a>
    <a href="audit.php">יומן</a>
    <a href="health.php">אבחון</a>
    <a href="logout.php">יציאה</a>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php
}

function layoutEnd(): void { echo "</main>\n"; }

function note(string $text, string $kind = 'ok'): void {
    if ($text === '') return;
    echo '<div class="note note--' . h($kind) . '">' . h($text) . "</div>\n";
}

/** אזור מתקפל. הראשון פתוח, השאר סגורים — אחרת המסך בלתי קריא. */
function secOpen(string $title, string $tag = '', bool $open = false): void {
    echo '<details class="sec"' . ($open ? ' open' : '') . '><summary>' . h($title)
       . ($tag !== '' ? ' <span class="sec-tag">' . h($tag) . '</span>' : '')
       . '</summary><div class="sec-body">';
}

function secClose(): void { echo "</div></details>\n"; }

function statusPill(string $status): string {
    return match ($status) {
        'active'    => '<span class="pill pill--ok">פעיל</span>',
        'pending'   => '<span class="pill pill--wait">ממתין לאישור</span>',
        'suspended' => '<span class="pill pill--stop">מושעה</span>',
        default     => '<span class="pill pill--mute">' . h($status) . '</span>',
    };
}

const MODE_LABELS = [
    MODE_KIOSK   => ['קיוסק', 'רק האריחים שהוגדרו. אין שורת כתובת, ואי אפשר להקליד כתובת חדשה.'],
    MODE_BROWSER => ['דפדפן', 'יש שורת כתובת. מה שייפתח בה נקבע לפי הכללים שלמטה.'],
];

const POSTURE_LABELS = [
    POSTURE_DENY  => ['הכול חסום — ואני פותח לפי היתר',
                      'ברירת המחדל הבטוחה. שום דבר לא נפתח עד שהתרתם אותו במפורש.'],
    POSTURE_ALLOW => ['הכול פתוח — ואני חוסם לפי איסור',
                      'האינטרנט פתוח, חוץ ממה שאסרתם: קטגוריה, סוג תוכן או כתובת.'],
];

const SCOPE_LABELS = [
    'exact'       => 'הכתובת המדויקת בלבד',
    'domain'      => 'כל הדומיין ותת-הדומיינים',
    'domain_plus' => 'הדומיין + משאבים נלווים מכל מקור',
];

const YT_MODE_LABELS = [
    'off'        => ['חסום לגמרי', 'יוטיוב לא ייפתח בכלל.'],
    'restricted' => ['רק מה שאישרתי', 'ערוצים וסרטונים מהרשימה. דף הבית והמלצות חסומים.'],
    'full'       => ['פתוח לגמרי', 'כל יוטיוב, למעט פריטים שתאסרו במפורש.'],
];

const DAY_NAMES = ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'];

function fmtSeconds(int $s): string {
    if ($s <= 0) return '—';
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h > 0 ? "{$h}ש׳ {$m}ד׳" : "{$m} דקות";
}
