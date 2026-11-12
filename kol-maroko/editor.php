<?php
/**
 * 🎨 העורך החזותי ועורך הקוד — כמסמך משלו (פרוסה V1b), כדי שתחול עליהם **המדיניות המלאה של C1 שלב ב** ככותרת שרת.
 *
 * הגוף הוא studio.html (המעטפת של הסטודיו) כמו שהוא (אותה מעטפת, אותם קבצים — אין כאן עותק שני של הדף); ההבדל הוא הכותרות. assets/js/app.js
 * מזהה את המסמך לפי הכתובת: כאן רצים רק "🎨 עורך" ו-"📁 פרויקט"; שאר הלשוניות מובילות חזרה ל-index.html, שנשאר
 * בשלב א (הסטודיו הישן מציג srcdoc, שהמדיניות המלאה שוברת — docs/spec-v2/slices/C1-studio-csp.md §1).
 *
 * הקנבס של העורך הוא srcdoc, ולכן **יורש** את המדיניות הזו: סקריפט של העמוד אינו רץ בו (גם בלי ה-sandbox), תמונות
 * ומדיה נטענות רק מהפרויקט (אותו מקור) ומהאתר המחובר, וגופנים — רק מ-Google Fonts, שהעמוד המוסב מקשר אליו.
 * המסך עצמו מאחורי השער (אינו ב-public של app.json).
 */

declare(strict_types=1);

while (ob_get_level() > 0) @ob_end_clean();         // שום שכבה שמעל אינה מזריקה סקריפט לדף הזה

require_once __DIR__ . '/lib/csp.php';     // editor_csp() — המדיניות המלאה (C1 §2, §2א), ונקודת הדיווח

$dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/editor.php'))), '/');
$host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$site = csp_site();                                  // האתר המחובר — מההגדרות; בלי מסד (או בלי הגדרה) — בלי מקור נוסף

header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: ' . editor_csp($host . $dir, $site) . '; report-uri ' . csp_report_uri('editor'));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
readfile(__DIR__ . '/studio.html');
