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

    /** הכפתור ברובריקה: מותקנת — "פתיחה"; לא — "התקנה"; בהתקנה עכשיו — "⏳". */
    fun action(installed: Boolean, pkg: String, target: String, untilMs: Long, nowMs: Long): String = when {
        installed -> "open"
        open(target, untilMs, nowMs) && pkg == target -> "installing"
        else -> "install"
    }
}
