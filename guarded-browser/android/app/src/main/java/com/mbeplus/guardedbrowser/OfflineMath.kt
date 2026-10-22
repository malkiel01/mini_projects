package com.mbeplus.guardedbrowser

/**
 * מצב לא מקוון מתוכנן (שלב 12 ו; ההגדרה אצל ההורה — lib/policy.php, ‏OFFLINE_CONTENT). טהור, נבדק ב-JVM
 * (OfflineMathTest).
 *
 *   "בלי חיבור" — לא היה קשר מוצלח עם השרת (מדיניות או פעימה) יותר מ-OFFLINE_AFTER_MS, בשעון האמין.
 *   ‏all      — כל מה שהמדיניות השמורה מתירה (כמו שהיה).
 *   ‏approved — רק מה שההורה אישר במפורש: כלל "מותר" (כולל משימה) ויוטיוב שאושר. משאבים בתוך דף — לפי המדיניות.
 *   ‏none     — שום גלישה.
 *   אחרי graceH שעות בלי חיבור — הכול נחסם, בכל מצב.
 *
 * רק מצמצם: הכרעה שכבר חסומה נשארת חסומה.
 */
object OfflineMath {

    const val OFFLINE_AFTER_MS = 3 * 60_000L
    private val APPROVED = setOf("rule_allow", "yt_item_allowed", "yt_video_allowed", "yt_channel_allowed")

    /** בלי חיבור עכשיו. ‏lastSyncMs=0 — עוד לא היה סנכרון (לפני כניסה) — לא נחשב. */
    fun offline(lastSyncMs: Long, nowMs: Long): Boolean = lastSyncMs > 0 && nowMs - lastSyncMs > OFFLINE_AFTER_MS

    fun graceMs(graceH: Int): Long = graceH.coerceIn(1, 72) * 3600_000L

    /** כמה זמן נשאר עד שהכול נחסם; ‏0 — נגמר. */
    fun leftMs(lastSyncMs: Long, nowMs: Long, graceH: Int): Long =
        if (lastSyncMs <= 0) graceMs(graceH) else maxOf(0L, lastSyncMs + graceMs(graceH) - nowMs)

    fun gate(content: String, offline: Boolean, v: Verdict, isMainFrame: Boolean): Verdict = when {
        !offline || !v.allow -> v
        content == "none" -> Verdict(false, "offline_none", "אין חיבור לשרת של ההורה — בלי חיבור הגלישה סגורה")
        content == "approved" && isMainFrame && v.code !in APPROVED ->
            Verdict(false, "offline_approved", "אין חיבור לשרת — עד שיחזור, רק אתרים שההורה אישר")
        else -> v
    }

    /** שורת המצב לילד (מסך הבית) — ריק כשיש חיבור. */
    fun statusLine(content: String, offline: Boolean, leftMs: Long): String {
        if (!offline) return ""
        if (leftMs <= 0) return "📴 אין חיבור לשרת כבר זמן רב — הגלישה סגורה עד שיחזור החיבור (או קוד מההורה)"
        val h = leftMs / 3600_000L; val m = (leftMs / 60_000L) % 60
        val left = if (h > 0) "$h ש׳ ו-$m דק׳" else "$m דק׳"
        val what = when (content) {
            "none" -> "בלי חיבור הגלישה סגורה"
            "approved" -> "זמינים רק אתרים שההורה אישר"
            else -> "זמין מה שההורה התיר"
        }
        return "📴 אין חיבור לשרת · $what · עוד $left"
    }
}
