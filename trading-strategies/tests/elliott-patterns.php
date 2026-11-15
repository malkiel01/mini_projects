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
// running: b = 1.2 ו-c = 1.0 < b — נדיר ולא מזוהה (allow_running_flat כבוי, עמ' 48–49)
eq(ts_ew_flat_kind([100, 110, 98, 108]), null, 'flat running: not classified (allow_running_flat off, p.48–49)');
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
