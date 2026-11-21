<?php
/**
 * הסטודיו — studio.html (המעטפת, לשעבר index.html) כמו שהוא, עם כותרות האבטחה של C1 שלב א (lib/csp.php):
 * object-src / base-uri / form-action נאכפים, והמדיניות המלאה בדיווח בלבד. studio.html חסום לגישה ישירה (.htaccess) —
 * השער של הפלטפורמה מגיש קבצים סטטיים בהפניה פנימית, וכותרת מ-.htaccess לא הייתה מגיעה אליו. index.html (קישור ישן,
 * קיצור דרך בטלפון) — מופנה לכאן ב-302.
 *
 * בלי לנקות את ה-output buffer: השער מוסיף לדף את הסקריפטים של הפלטפורמה (PWA, יומן אבחון) כמו שהוסיף ל-index.html.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/csp.php';

header('Content-Type: text/html; charset=utf-8');
csp_studio_headers();
header('Cache-Control: no-store');
readfile(__DIR__ . '/studio.html');
