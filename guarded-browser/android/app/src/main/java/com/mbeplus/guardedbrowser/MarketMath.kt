package com.mbeplus.guardedbrowser

/**
 * התקנה מהמרקט (lib/market.php) — החלון שבו Google Play פתוחה, בלי Android (MarketMathTest).
 *
 *   הילד לוחץ "התקנה" על אפליקציה ששוחררה לו ← Play נפתחת בדף שלה, לכל היותר WINDOW_MS. החלון נסגר כשהיא
 *   הותקנה, או כשהזמן עבר. כל אפליקציה *אחרת* שהותקנה בחלון — "זרה": נחסמת (וב"בעל מכשיר" — מוסרת).
 */
object MarketMath {

    const val PLAY = "com.android.vending"
    const val WINDOW_MS = 5 * 60_000L

    fun open(target: String, untilMs: Long, nowMs: Long): Boolean = target.isNotEmpty() && nowMs in 0 until untilMs

    /** מה עושים עם חבילה שהותקנה עכשיו: done — זו שביקשו; stray — אחרת, בתוך החלון; none — מחוץ לחלון. */
    fun onInstalled(pkg: String, target: String, untilMs: Long, nowMs: Long): String = when {
        !open(target, untilMs, nowMs) -> "none"
        pkg == target -> "done"
        else -> "stray"
    }

    /* ── ההתקנה הרובוטית (שלב 3, MarketRobot): מגדלור לוחץ "התקנה" ב-Play מאחורי מסך מכסה ── */

    /** כמה זמן מחפשים את כפתור ההתקנה לפני שמוותרים ועוברים ל-Play גלויה. */
    const val ROBOT_FIND_MS = 45_000L
    /** אחרי הלחיצה — כמה זמן מחכים לסיום ההורדה וההתקנה. */
    const val ROBOT_INSTALL_MS = 4 * 60_000L

    /** הטקסט של כפתור ההתקנה ב-Play — התאמה מלאה בלבד ("התקנה במכשירים נוספים" — לא). */
    private val INSTALL = setOf("התקנה", "התקן", "להתקנה", "install")
    /** כפתורים שהרובוט לא עובר לבד: כניסה לחשבון וקנייה (התאמה מלאה), ומחיר (מטבע בכפתור). */
    private val BLOCKERS = setOf("sign in", "כניסה", "כניסה לחשבון", "היכנס", "קנייה", "buy")

    fun isInstall(text: String): Boolean = text.trim().lowercase() in INSTALL

    fun isBlocker(text: String): Boolean {
        val t = text.trim().lowercase()
        return t in BLOCKERS || t.contains("₪") || t.contains("$")
    }

    /**
     * צעד של הרובוט, כל פעם שמסתכלים על Play: click — ללחוץ על הכפתור שנמצא; wait — עוד לא; fallback — לוותר
     * (Play גלויה לילד, כמו בשלב 2). ‏clickedMs=0 — עוד לא לחצנו.
     */
    fun robotStep(startedMs: Long, clickedMs: Long, nowMs: Long, button: Boolean, blocker: Boolean): String = when {
        blocker -> "fallback"
        clickedMs > 0 -> if (nowMs - clickedMs > ROBOT_INSTALL_MS) "fallback" else "wait"
        button -> "click"
        nowMs - startedMs > ROBOT_FIND_MS -> "fallback"
        else -> "wait"
    }

    /** הכפתור ברובריקה: מותקנת — "פתיחה"; לא — "התקנה"; בהתקנה עכשיו — "⏳". */
    fun action(installed: Boolean, pkg: String, target: String, untilMs: Long, nowMs: Long): String = when {
        installed -> "open"
        open(target, untilMs, nowMs) && pkg == target -> "installing"
        else -> "install"
    }
}
