package com.mbeplus.guardedbrowser

/**
 * זמן מסך לאפליקציות (2026-10-22; השרת: lib/apptime.php) — החלק הטהור, נבדק ב-JVM (AppTimeMathTest).
 *
 *   המדידה (AppGuardService): טיימר כל TICK_MS מוסיף לאפליקציה שבחזית את הזמן שעבר — רק כשהמסך דלוק, ולכל
 *   היותר MAX_STEP_MS בפעם (מכשיר שנרדם, או אירוע שהוחמץ, לא נספרים כשעות).
 *   "בחזית" — האפליקציה האחרונה עם אייקון שעלתה. מקלדת ושורת המערכת (transient) לא מחליפות אותה: בלי זה,
 *   הקלדה בוואטסאפ הייתה עוצרת את הספירה. מסך הבית וכל השאר — עוצרים.
 */
object AppTimeMath {

    const val TICK_MS = 15_000L
    const val MAX_STEP_MS = 30_000L

    /** האפליקציה שנספרת אחרי אירוע חלון של ‏pkg. */
    fun foreground(prev: String, pkg: String, launchable: Set<String>, transient: Set<String>, own: String): String = when {
        pkg in transient -> prev
        pkg == own -> ""
        pkg in launchable -> pkg
        else -> ""
    }

    fun step(lastMs: Long, nowMs: Long): Long =
        if (lastMs <= 0 || nowMs <= lastMs) 0 else minOf(nowMs - lastMs, MAX_STEP_MS)

    /** הזמן שנצבר — מתאפס ביום חדש. */
    data class Usage(val day: String, val ms: Map<String, Long>)

    fun add(u: Usage, today: String, pkg: String, ms: Long): Usage {
        val base = if (u.day == today) u.ms else emptyMap()
        if (pkg.isEmpty() || ms <= 0) return Usage(today, base)
        return Usage(today, base + (pkg to ((base[pkg] ?: 0L) + ms)))
    }

    fun usedMs(u: Usage, today: String, pkg: String): Long = if (u.day == today) u.ms[pkg] ?: 0L else 0L

    /** נגמר הזמן להיום. ‏limitMin=0 — בלי מגבלה. */
    fun over(limitMin: Int, usedMs: Long): Boolean = limitMin > 0 && usedMs >= limitMin * 60_000L

    fun leftMs(limitMin: Int, usedMs: Long): Long = if (limitMin <= 0) Long.MAX_VALUE else maxOf(0L, limitMin * 60_000L - usedMs)
}
