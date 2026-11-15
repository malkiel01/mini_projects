<?php
/**
 * גלי אליוט — סיווג דפוסים 4א/5 (spec-stage-a 17.1.2, 17.3): flat, triangle ואלכסוני סוגר. טהור: בלי מסד, בלי רשת.
 *
 * מקור: בדיקה מאומתת מול הספר — team/handoff/elliott-4a5-book-check.md (עמודי PDF מצוינים ליד כל כלל).
 * מה שלא בבדיקה הזו — לא ממומש כאן:
 *   - running flat (עמ' 48) ו-leading diagonal (עמ' 40): לא מזוהים אוטומטית (הבדיקה: נדירים מאוד).
 *   - zigzag ו-combinations (W-X-Y): לא ממומשים בשלב זה.
 *   - סף נקודות מפנה באחוזים: שיטת תוכנה, לא מהספר — ייקבע בנפרד.
 *   - יחסי פיבונאצ'י לכל דפוס: לא ממומשים כאן (הבדיקה לא מאשרת אותם בעמוד).
 *
 * כל הפונקציות מקבלות מחירי נקודות מפנה בסדר הזמן, ומחזירות null/false כשהדפוס לא עומד בכללים.
 * מסמנים [שלנו] בכל מה שאינו בלשון הספר.
 */

declare(strict_types=1);

require_once __DIR__ . '/elliott.php';

/**
 * גבולות הסיווג של שטוח לפי גל B (spec-stage-a 17.1.2, 2.8) [שלנו; הספר אומר "בערך" בלי מספר]:
 * b = |B| / |A|. b < TS_EW_FLAT_B_MIN — לא שטוח (כלל, עמ' 89). b ≤ TS_EW_FLAT_B_REG_MAX — רגיל; מעל — מורחב/רץ.
 */
const TS_EW_FLAT_B_MIN = 0.9;
const TS_EW_FLAT_B_REG_MAX = 1.05;

/**
 * מסגור ל-x: כיוון גל A הוא תמיד "למעלה" ב-x (x = dir × p), כדי שכל הכללים יהיו באותה צורה לשני הכיוונים.
 * מחזיר null אם גל A אפס.
 */
function ts_ew_pat_frame(array $p): ?array
{
    $p = array_values(array_map('floatval', $p));
    $dir = $p[1] > $p[0] ? 1 : ($p[1] < $p[0] ? -1 : 0);
    if ($dir === 0) return null;
    return array_map(fn($v) => $dir * $v, $p);
}

/**
 * שטוח (3-3-5) מ-4 נקודות: p0 → A → p1 → B → p2 → C → p3. מחזיר 'regular' | 'expanded' | 'running' | null.
 * [ספר, עמ' 89]: B תמיד מחזיר לפחות 90% מ-A. [ספר, עמ' 45–46, 89]: B בערך בגובה A (רגיל), או מעבר לו (מורחב/רץ);
 * C מסתיים בסוף A או מעבר לו ברגיל ובמורחב.
 * הסיווג לפי B ו-C, כמו בספר [שלנו, 2.8]: b = |B|/|A|, c = |C|/|A|:
 *   b < 0.9 → לא שטוח;  0.9 ≤ b ≤ 1.05 ו-c ≥ b → regular;  b > 1.05 ו-c > b → expanded;  b > 1.05 ו-c < 1.0 → running.
 * running מחזיר null (allow_running_flat כבוי, עמ' 48–49). מקרים אחרים (למשל 0.9 ≤ b ≤ 1.05 עם c < b) — לא מסווגים. אין סף נפרד על המרחק של C מסוף A (הספר לא נותן כזה).
 * לא נבדקים: A אינו triangle; C דחף או אלכסוני (דורש תתי-גלים, לא נקודות מפנה).
 */
function ts_ew_flat_kind(array $p): ?string
{
    if (count($p) !== 4) return null;
    $x = ts_ew_pat_frame($p);
    if ($x === null) return null;
    $a = $x[1] - $x[0];                       // גל A, למעלה
    if (!($x[2] < $x[1]) || !($x[3] > $x[2])) return null;   // B יורד, C עולה
    $b = ($x[1] - $x[2]) / $a;
    $c = ($x[3] - $x[2]) / $a;
    if ($b < TS_EW_FLAT_B_MIN) return null;
    if ($b <= TS_EW_FLAT_B_REG_MAX) return $c >= $b ? 'regular' : null;
    return $c > $b ? 'expanded' : null;       // b > 1.05 ו-c < 1.0: running — כבוי; בין לבין — לא מסווג
}

/**
 * משולש (3-3-3-3-3) מ-6 נקודות: p0 → A → p1 → B → p2 → C → p3 → D → p4 → E → p5.
 * [ספר, עמ' 49–50]: חמישה גלים חופפים; קווי A-C ו-B-D. מתכנס — C נשאר מתחת לסוף A ו-D נשאר מעל סוף B;
 * מתפשט — ההפך. [שלנו] הניסוח הזה הוא המשמעות של "הקווים מתכנסים/מתפשטים" במונחי נקודות מפנה.
 * שלושה סוגים בספר (מתכנס, חסם, מתפשט) — רק מתכנס ומתפשט מזוהים; חסם (barrier) ו-running — לא.
 */
function ts_ew_triangle_kind(array $p): ?string
{
    if (count($p) !== 6) return null;
    $x = ts_ew_pat_frame($p);
    if ($x === null) return null;
    // מתחלפים: A עולה, B יורד, C עולה, D יורד, E עולה
    if (!($x[1] > $x[0] && $x[2] < $x[1] && $x[3] > $x[2] && $x[4] < $x[3] && $x[5] > $x[4])) return null;
    if ($x[3] < $x[1] && $x[4] > $x[2]) return 'contracting';
    if ($x[3] > $x[1] && $x[4] < $x[2]) return 'expanding';
    return null;
}

/**
 * אלכסוני סוגר (3-3-3-3-3, טריז) מ-6 נקודות ועם זמני הנקודות: [ספר, עמ' 36–37].
 * חוקי הדחף נשמרים חוץ מחפיפה (גל 4 חופף לגל 1 מותר) — ולכן הבדיקה היא: בלי הפרות חוץ מכלל 5, ולא גל 3 הקצר
 * מבין 1, 3, 5, והקווים 1–3 ו-2–4 מתכנסים (הטריז). "מתכנס" נמדד כפער בין הקווים שקטן מהפער בנקודה 2 [שלנו].
 * אלכסוני מוביל (leading, עמ' 40) לא מזוהה אוטומטית.
 */
function ts_ew_diagonal_ending(array $p, array $t): bool
{
    if (count($p) !== 6 || count($t) !== 6) return false;
    $x = ts_ew_pat_frame($p);
    if ($x === null) return false;
    $dir = $p[1] > $p[0] ? 'up' : 'down';
    // כלל 3 ושאר הכללים, בלי כלל 5 (חפיפה מותרת באלכסוני)
    if (ts_ew_impulse_violations(['dir' => $dir, 'p' => $p, 'diagonal' => true])) return false;
    // קווים: עליון דרך 1 ו-3, תחתון דרך 2 ו-4 — בערכי x ובזמנים
    $line = fn(int $t1, float $x1, int $t2, float $x2) => fn(int $tt) => $x1 + ($x2 - $x1) * ($tt - $t1) / ($t2 - $t1);
    $upper = $line($t[1], $x[1], $t[3], $x[3]);
    $lower = $line($t[2], $x[2], $t[4], $x[4]);
    $gap2 = $upper($t[2]) - $lower($t[2]);
    $gap5 = $upper($t[5]) - $lower($t[5]);
    return $x[5] > $x[4] && $t[3] > $t[1] && $t[4] > $t[2] && $gap5 > 0 && $gap5 < $gap2;
}
