<?php
/**
 * עטיפת HTML משותפת לפאנל.
 *
 * בלי מנוע תבניות ובלי CSS חיצוני: הפאנל הוא חמישה מסכים, וכל תלות
 * הייתה עולה יותר ממה שהיא חוסכת.
 */

declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function layoutTop(string $title, ?array $admin = null): void {
    ?><!doctype html>
<html lang="he" dir="rtl">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · ניהול הדפדפן</title>
<style>
  :root {
    --bg: #f6f7f9; --card: #fff; --ink: #16181d; --dim: #6b7280;
    --line: #e3e6eb; --brand: #2563eb; --ok: #16a34a; --stop: #dc2626; --warn: #d97706;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink);
         font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Arial, sans-serif; }
  header { background: var(--card); border-bottom: 1px solid var(--line); padding: 12px 20px;
           display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
  header h1 { font-size: 17px; margin: 0; }
  header nav { display: flex; gap: 14px; margin-inline-start: auto; flex-wrap: wrap; }
  a { color: var(--brand); }
  main { max-width: 1000px; margin: 22px auto; padding: 0 16px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px;
          padding: 18px; margin-bottom: 18px; }
  .card h2 { margin: 0 0 4px; font-size: 16px; }
  .card > p.hint { margin: 0 0 14px; color: var(--dim); font-size: 13px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: right; padding: 9px 8px; border-bottom: 1px solid var(--line);
           vertical-align: middle; }
  th { color: var(--dim); font-weight: 600; font-size: 13px; }
  label { display: block; margin-bottom: 12px; font-size: 13px; color: var(--dim); }
  label > span { display: block; margin-bottom: 4px; }
  input[type=text], input[type=password], input[type=number], input[type=date],
  input[type=time], select, textarea {
    width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 7px;
    font: inherit; background: #fff; color: var(--ink);
  }
  .grid { display: grid; gap: 0 16px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
  .btn { display: inline-block; border: 1px solid var(--line); background: #fff; color: var(--ink);
         padding: 8px 14px; border-radius: 7px; cursor: pointer; font: inherit; text-decoration: none; }
  .btn--go   { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn--stop { background: #fff; border-color: var(--stop); color: var(--stop); }
  .btn--sm   { padding: 4px 9px; font-size: 13px; }
  .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; }
  .pill--ok   { background: #dcfce7; color: #166534; }
  .pill--wait { background: #fef3c7; color: #92400e; }
  .pill--stop { background: #fee2e2; color: #991b1b; }
  .note { padding: 10px 13px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .note--ok   { background: #dcfce7; color: #166534; }
  .note--bad  { background: #fee2e2; color: #991b1b; }
  code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;
         direction: ltr; unicode-bidi: embed; display: inline-block; }
  .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
  fieldset { border: 1px solid var(--line); border-radius: 8px; padding: 14px; margin: 0 0 16px; }
  legend { padding: 0 6px; font-size: 13px; color: var(--dim); }
  .days { display: flex; gap: 10px; flex-wrap: wrap; }
  .days label { display: flex; gap: 4px; align-items: center; margin: 0; }
  .days label span { margin: 0; }
</style>
<header>
  <h1>ניהול הדפדפן</h1>
  <?php if ($admin): ?>
  <nav>
    <a href="index.php">משתמשים</a>
    <a href="audit.php">יומן</a>
    <a href="logout.php">יציאה (<?= h($admin['username']) ?>)</a>
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

/** תג מצב אחיד לכל מקום שבו מוצג status של משתמש. */
function statusPill(string $status): string {
    return match ($status) {
        'active'    => '<span class="pill pill--ok">פעיל</span>',
        'pending'   => '<span class="pill pill--wait">ממתין לאישור</span>',
        'suspended' => '<span class="pill pill--stop">מושעה</span>',
        default     => '<span class="pill">' . h($status) . '</span>',
    };
}

const MODE_LABELS = [
    MODE_KIOSK     => 'קיוסק — רק האריחים שהוגדרו, בלי שורת כתובת',
    MODE_ALLOWLIST => 'שורת כתובת — אבל רק מה שברשימה נפתח',
    MODE_FREE      => 'חופשי — הכול פתוח חוץ ממה שנאסר',
];

const SCOPE_LABELS = [
    'exact'       => 'הכתובת המדויקת בלבד',
    'domain'      => 'כל הדומיין ותת-הדומיינים',
    'domain_plus' => 'הדומיין + משאבים נלווים מכל מקור',
];

const DAY_NAMES = ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'];

function fmtSeconds(int $s): string {
    if ($s <= 0) return '—';
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h > 0 ? "{$h}ש' {$m}ד'" : "{$m} דקות";
}
