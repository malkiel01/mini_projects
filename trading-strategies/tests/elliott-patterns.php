<?php
// בדיקות 4א/5 — סיווג דפוסים (flat, triangle, אלכסוני סוגר) מול בדיקת הספר (team/handoff/elliott-4a5-book-check.md).
// php trading-strategies/tests/elliott-patterns.php — טהור, בלי רשת ובלי מסד.
if (PHP_SAPI !== 'cli') exit;

require_once dirname(__DIR__) . '/lib/elliott-patterns.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── שטוח (עמ' 45–46, 89; 2.8) — הסיווג לפי B ו-C מול A ─────
// רגיל: B בערך בגובה תחילת A (b = 0.95), C בסוף A או מעבר (c = 1.0 ≥ b)
eq(ts_ew_flat_kind([100, 110, 100.5, 110.5]), 'regular', 'flat regular: b≈1 (B ~ start of A), C at or beyond end of A (p.45–46, 2.8)');
// מורחב: B מעבר לתחילת A (b = 1.2), C מעבר לסוף B (c = 1.4 > b) (עמ' 46)
eq(ts_ew_flat_kind([100, 110, 98, 112]), 'expanded', 'flat expanded: b > 1.05 and c > b (p.46, 2.8)');
// running: b = 1.2 ו-c = 0.9 < 1.0 — נדיר ולא מזוהה (allow_running_flat כבוי, עמ' 48–49)
eq(ts_ew_flat_kind([100, 110, 98, 107]), null, 'flat running: b > 1.05 and c < 1.0 — not classified (allow_running_flat off, p.48–49)');
// b = 1.2 ו-c = 1.1 — לא מורחב (c < b), ולא רץ (c ≥ 1.0): לא מסווג לכלום (2.8)
eq(ts_ew_flat_kind([100, 110, 98, 109]), null, 'flat: b > 1.05 with 1.0 <= c < b is not classified (2.8)');
// b בתוך הסבולת (0.95) ו-c < b (0.85): לא רגיל, ולא רץ — לא מסווג לכלום (2.8)
eq(ts_ew_flat_kind([100, 110, 100.5, 109]), null, 'flat: b in 0.9–1.05 with c < b is not running (2.8)');
// B קטן מ-90% מ-A (b = 0.6): לא שטוח — כלל (עמ' 89)
eq(ts_ew_flat_kind([100, 110, 104, 110.5]), null, 'flat: B must retrace at least 90% of A (p.89)');
// B לא יורד (עולה מעל סוף A): לא שטוח
eq(ts_ew_flat_kind([100, 110, 111, 118]), null, 'flat: B must retrace A');
// רגיל בלי C מעבר לסוף A (b = 0.95, c = 0.85 < b): לא נכנס לסוג
eq(ts_ew_flat_kind([100, 110, 100.5, 109]), null, 'flat regular needs C at or beyond end of A (c >= b)');
// ירידה — אותו דבר במראה (A יורד): b = 0.95, c = 1.0
eq(ts_ew_flat_kind([200, 190, 199.5, 189.5]), 'regular', 'flat regular, downward (mirror)');

// ── משולש (עמ' 49–50) ───────────────────────────────
// מתכנס: C מתחת לסוף A (109 < 110), D מעל סוף B (103.5 > 102)
eq(ts_ew_triangle_kind([100, 110, 102, 109, 103.5, 107]), 'contracting', 'triangle contracting: C below end of A, D above end of B (p.49)');
// מתפשט: C מעל סוף A, D מתחת לסוף B
eq(ts_ew_triangle_kind([100, 110, 102, 112, 100.5, 115]), 'expanding', 'triangle expanding (p.49)');
// C מעל סוף A, D מעל סוף B — לא משולש
eq(ts_ew_triangle_kind([100, 110, 102, 112, 103, 115]), null, 'triangle: mixed converge/diverge is not classified');
// ── סוף E: נסיגה נטו של הגל הקודם (עמ' 49, מצילום הבעלים; 17.1.2, 2.9) ──
// הדוגמה של האפיון: גל 3 עולה נגמר ב-100, משולש בגל 4 (A יורד). מתפשט, E מסתיים ב-99 < 100 — משולש
eq(ts_ew_triangle_kind([100, 90, 98, 88, 99.5, 99]), 'expanding', 'triangle end of E: E ends below the end of wave 3 (net retracement, p.49)');
// אותו מתפשט, אבל E מסתיים ב-101 — מעל סוף גל 3: אין נסיגה נטו, לא משולש
eq(ts_ew_triangle_kind([100, 90, 98, 88, 101.5, 101]), null, 'triangle end of E: E ends above the end of wave 3 — not a triangle (p.49)');
// E מסתיים בדיוק בתחילת המשולש — אין נסיגה נטו
eq(ts_ew_triangle_kind([100, 90, 98, 88, 100.5, 100]), null, 'triangle end of E: E exactly at the start — no net retracement');
// running (B חורג מתחילת A, 97 < 100), מתכנס, A עולה — E חייב להסתיים מעל 100
eq(ts_ew_triangle_kind([100, 110, 97, 108, 99, 101]), 'contracting', 'running contracting triangle: E ends beyond the start (p.49)');
eq(ts_ew_triangle_kind([100, 110, 97, 108, 98, 99.5]), null, 'running contracting triangle: E back short of the start — not a triangle (p.49)');
// ── barrier (17.1.2): הקו B-D אופקי — |D − B| ≤ fib_tolerance × |A|, ו-C לפני סוף A ──
// A = 10, סבולת 0.05 ⇒ |D − B| ≤ 0.5. D ב-102.3 מול B ב-102 (0.3) — barrier, גם אם D מעל B (היה עונה גם על מתכנס)
eq(ts_ew_triangle_kind([100, 110, 102, 109, 102.3, 107]), 'barrier', 'triangle barrier: B-D line flat within fib_tolerance × |A|, C below end of A (17.1.2)');
// D מעט מתחת ל-B (101.6, 0.4) — עדיין barrier
eq(ts_ew_triangle_kind([100, 110, 102, 109, 101.6, 107]), 'barrier', 'triangle barrier: D slightly below B is still flat');
// D ב-103 (1.0 > 0.5) — לא אופקי: מתכנס
eq(ts_ew_triangle_kind([100, 110, 102, 109, 103, 107]), 'contracting', 'triangle: B-D not flat beyond the tolerance → contracting');
// סבולת אחרת: 0.2 ⇒ |D − B| ≤ 2 — אותו משולש הוא barrier
eq(ts_ew_triangle_kind([100, 110, 102, 109, 103, 107], 0.2), 'barrier', 'triangle barrier uses the fib_tolerance passed in');
// C מעבר לסוף A (112 > 110) — לא barrier, גם כש-B-D אופקי
eq(ts_ew_triangle_kind([100, 110, 102, 112, 102.2, 107]), null, 'triangle barrier needs C before the end of A');
// במראה (A יורד)
eq(ts_ew_triangle_kind([200, 190, 198, 191, 197.7, 193]), 'barrier', 'triangle barrier, downward (mirror)');

// ── running (עמ' 49, "נפוץ מאוד"): B חורג מתחילת A — מזוהה, לא נפסל ──
// מתכנס רץ: B ב-97, מתחת לתחילת A (100). הסוג נשאר contracting
eq(ts_ew_triangle_kind([100, 110, 97, 108, 99, 101]), 'contracting', 'running triangle (B beyond the start of A) is classified, not rejected (p.49)');
// barrier רץ: B ב-97, D ב-97.3
eq(ts_ew_triangle_kind([100, 110, 97, 108, 97.3, 101]), 'barrier', 'running barrier triangle is classified (p.49)');

// מספר לא נכון של נקודות
eq(ts_ew_triangle_kind([100, 110, 102, 109, 103.5]), null, 'triangle needs 6 pivots');

// ── אלכסוני סוגר (עמ' 36–37) ─────────────────────────
$t = [0, 1, 2, 3, 4, 5];
// טריז מתכנס: חפיפה של 4 בתוך 1 מותרת; גל 3 לא הקצר; הקווים 1–3 ו-2–4 מתכנסים
eq(ts_ew_diagonal_ending([100, 110, 104, 113, 108, 114], $t), true, 'ending diagonal: converging wedge, wave 4 overlaps wave 1 (p.36–37)');
// קווים מתרחבים — לא טריז
eq(ts_ew_diagonal_ending([100, 110, 104, 120, 108, 114], $t), false, 'ending diagonal: diverging lines are not a wedge');
// גל 3 הקצר מבין 1, 3, 5 באחוזים — נפסל גם באלכסוני (עמ' 36)
// גל 3 (1.5 נק', ~1.4%) קצר מגל 1 (10%) ומגל 5 (~9%): נפסל לפי כלל 3 (עמ' 36)
eq(ts_ew_diagonal_ending([100, 110, 109.5, 111, 110, 120], $t), false, 'ending diagonal: wave 3 never the shortest (p.36)');
// חפיפה כפולה — לא מוגדר בבדיקה: נפסל ע"י כלל אחר
eq(ts_ew_diagonal_ending([100, 110, 104, 113, 108, 107], $t), false, 'ending diagonal: wave 5 must be above wave 4 (rule set)');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
