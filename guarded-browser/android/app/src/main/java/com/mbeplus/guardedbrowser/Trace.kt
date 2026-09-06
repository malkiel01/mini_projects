package com.mbeplus.guardedbrowser

import android.os.SystemClock

/**
 * רישום אירועים לאבחון.
 *
 * הבעיות שנתקלנו בהן — חלון צף שנפתח שחור, ניגון שנעצר — קורות
 * ברצף של קריאות מערכת שנמשך פחות משנייה, ואי אפשר לראות אותן
 * מבחוץ: כל מה שנשאר הוא "לא עובד". ניחוש על סמך זה הוא ניחוש.
 *
 * כאן נרשם מה קרה ובאיזה סדר, עם מצב הדגלים בכל נקודה, והרשימה
 * נשלחת לשרת בלחיצה אחת. תקלה שקורית פעם אחת מספיקה כדי להבין
 * אותה.
 *
 * מוגבל בגודל: זהו מאגר אבחון, לא יומן קבוע.
 */
object Trace {

    private const val MAX = 300
    private val lines = ArrayDeque<String>()
    private var t0 = SystemClock.elapsedRealtime()

    @Synchronized
    fun reset() {
        lines.clear()
        t0 = SystemClock.elapsedRealtime()
    }

    /**
     * ‏$tag הוא מה קרה, $detail הוא המצב באותו רגע.
     *
     * הזמן יחסי לתחילת המעקב ולא שעון קיר: מה שחשוב הוא המרווח בין
     * אירועים, לא השעה שבה קרו.
     */
    @Synchronized
    fun log(tag: String, detail: String = "") {
        val ms = SystemClock.elapsedRealtime() - t0
        lines.addLast(String.format("%6d  %-26s %s", ms, tag, detail))
        while (lines.size > MAX) lines.removeFirst()
    }

    @Synchronized
    fun dump(): String = lines.joinToString("\n")

    @Synchronized
    fun isEmpty(): Boolean = lines.isEmpty()
}
