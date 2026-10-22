package com.mbeplus.guardedbrowser

/**
 * היתרים זמניים לאפליקציות (בקשת פתיחה, 2026-10-22) — טהור, נבדק ב-JVM (AppGrantMathTest).
 *
 *   השרת שולח את ההיתרים שעדיין בתוקף (apps.temp: מזהה, חבילה, דקות, וסוף — אם כבר הוחל). המכשיר:
 *     • היתר חדש — מתחיל עכשיו (חלון זמן מרגע ההחלה במכשיר, לא מכסת שימוש). שהוחל כבר (סוף מהשרת) — אותו סוף.
 *     • היתר שהשרת כבר לא שולח — בוטל (ההורה שינה את הכלל) — יורד מיד.
 *     • סוף שעבר — יורד, גם כשאין תשובה מהשרת: האכיפה בשעון האמין של המכשיר.
 *   תשובה ישנה לא מגיעה לכאן (PolicySync.isStale), ולכן לא מחזירה היתר שבוטל.
 */
object AppGrantMath {

    data class Grant(val pkg: String, val until: Long)
    data class Offer(val id: Int, val pkg: String, val minutes: Int, val endsMs: Long)
    data class Result(val grants: Map<Int, Grant>, val started: List<Int>, val dropped: List<Int>)

    fun reconcile(local: Map<Int, Grant>, offers: List<Offer>, now: Long): Result {
        val out = mutableMapOf<Int, Grant>()
        val started = mutableListOf<Int>()
        for (o in offers) {
            if (o.pkg.isEmpty() || o.minutes <= 0) continue
            val have = local[o.id]
            val until = when {
                have != null -> have.until                                  // כבר רץ — לא מתחילים מחדש
                o.endsMs > 0 -> o.endsMs                                     // הוחל קודם (מכשיר שהתאפס) — אותו סוף
                else -> now + o.minutes * 60_000L
            }
            if (until <= now) continue
            out[o.id] = Grant(o.pkg, until)
            if (have == null) started += o.id
        }
        val dropped = local.keys.filter { it !in out }
        return Result(out, started, dropped)
    }

    /** החבילות שפתוחות עכשיו בזכות היתר זמני. */
    fun granted(grants: Map<Int, Grant>, now: Long): Set<String> =
        grants.values.filter { it.until > now }.map { it.pkg }.toSet()

    /** ההיתרים שהסתיימו (להסרה ולחסימה מחדש). */
    fun expired(grants: Map<Int, Grant>, now: Long): Map<Int, Grant> = grants.filterValues { it.until <= now }

    /** מתי הסוף הבא — לטיימר שחוסם מחדש גם אפליקציה שפתוחה כרגע. 0 — אין. */
    fun nextEnd(grants: Map<Int, Grant>, now: Long): Long =
        grants.values.map { it.until }.filter { it > now }.minOrNull() ?: 0L
}
