package com.mbeplus.guardedbrowser

/**
 * בדיקת הגנה יזומה (SelfTest, שלב 12 ג) — החלק הטהור, נבדק ב-JVM (SelfTestMathTest). הפסיקה עצמה בשרת
 * (lib/selftest.php); כאן רק מה שהמכשיר מודד.
 */
object SelfTestMath {

    /** בקשה שעוד לא ענינו עליה. */
    fun shouldRun(id: String, done: String): Boolean = id.isNotEmpty() && id != done

    /**
     * אפליקציה שהשרת מצפה שתהיה חסומה — מה קורה לה במכשיר:
     *   ‏missing   — לא מותקנת (אין מה לחסום).
     *   ‏suspended — מושעית במערכת (נקרא מהמערכת, לא מהרשימה שלנו).
     *   ‏access    — לא מושעית, אבל המדיניות חוסמת ושירות הנגישות פעיל — מסך חסימה.
     *   ‏open      — שום דבר לא עוצר אותה.
     * ‏suspended=null — אי אפשר לקרוא (אנדרואיד מתחת ל-10, או בלי "בעל מכשיר").
     */
    fun appState(installed: Boolean, policyBlocked: Boolean, suspended: Boolean?, access: Boolean): String = when {
        !installed -> "missing"
        suspended == true -> "suspended"
        policyBlocked && access -> "access"
        else -> "open"
    }

    /** שניות מאז הסנכרון האחרון; ‏-1 — לא היה. */
    fun syncAgoSec(nowMs: Long, lastSyncMs: Long): Long =
        if (lastSyncMs <= 0) -1 else maxOf(0L, (nowMs - lastSyncMs) / 1000)
}
