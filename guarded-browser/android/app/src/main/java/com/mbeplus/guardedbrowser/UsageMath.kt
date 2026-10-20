package com.mbeplus.guardedbrowser

import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/**
 * חישובי זמן ושימוש — טהורים, בלי Android (UsageMathTest).
 *
 * מה נחשב שימוש: הדפדפן פתוח (על המסך, או ברקע/בחלון צף כשההורה התיר) — כל שנייה שבין שתי "דגימות"
 * (UsageMeter.tick, כל 5 שניות) לפי שעון מונוטוני (elapsedRealtime), כך ששינוי שעת המכשיר אינו משנה.
 * פער ארוך מ-MAX_STEP_MS בין דגימות (התהליך נעצר, הטלפון ישן) אינו נספר.
 *
 * ישיבה: נמשכת כל עוד אין הפסקה ארוכה מ-SESSION_GAP_MS. סגירה ופתיחה מחדש של הדפדפן אינן מאפסות אותה.
 */
object UsageMath {
    const val MAX_STEP_MS = 120_000L
    const val SESSION_GAP_MS = 10 * 60_000L
    /** חלון חסד: בלי סנכרון מוצלח עם השרת יותר מזה — הגלישה נעצרת עד שהשרת זמין שוב. */
    const val GRACE_MS = 24 * 3600_000L

    /** כמה מילישניות של שימוש בין שתי דגימות. */
    fun step(lastMarkElapsed: Long, nowElapsed: Long): Long {
        if (lastMarkElapsed <= 0 || nowElapsed < lastMarkElapsed) return 0
        val d = nowElapsed - lastMarkElapsed
        return if (d > MAX_STEP_MS) 0 else d
    }

    /** היום (yyyy-MM-dd) לפי אזור הזמן של המדיניות — אותו מפתח כמו todayIn בשרת. */
    fun dayKey(ms: Long, tz: String): String =
        SimpleDateFormat("yyyy-MM-dd", Locale.US).apply { timeZone = TimeZone.getTimeZone(tz) }.format(java.util.Date(ms))

    /** האם ההפסקה מאז השימוש האחרון ארוכה מספיק כדי להתחיל ישיבה חדשה. */
    fun sessionReset(lastActiveMs: Long, nowMs: Long): Boolean =
        lastActiveMs <= 0 || nowMs - lastActiveMs > SESSION_GAP_MS || nowMs < lastActiveMs

    /**
     * זמן אמין: שעון השרת בסנכרון האחרון + הזמן המונוטוני שעבר מאז. שינוי שעת המכשיר לא משפיע.
     * אחרי הפעלה מחדש (elapsed קטן מהעוגן) העוגן אינו תקף — נופלים לשעון המכשיר עד הסנכרון הבא.
     */
    fun trustedNow(anchorServerMs: Long, anchorElapsed: Long, nowElapsed: Long, wallNow: Long): Long =
        if (anchorServerMs > 0 && nowElapsed >= anchorElapsed) anchorServerMs + (nowElapsed - anchorElapsed) else wallNow

    /** האם עבר חלון החסד מאז הסנכרון המוצלח האחרון. 0 = עוד לא סונכרן (מכשיר חדש) — לא חוסמים. */
    fun graceExpired(lastSyncMs: Long, nowMs: Long, graceMs: Long = GRACE_MS): Boolean =
        lastSyncMs > 0 && nowMs - lastSyncMs > graceMs

    /** השימוש היום: מה שהשרת ספר (אם הוא של היום) + מה שנצבר כאן ועוד לא אושר. */
    fun usedToday(serverUsed: Int, serverDay: String, today: String, unsyncedToday: Int): Int =
        (if (serverDay == today) serverUsed else 0) + unsyncedToday

    /** ‏ISO של השרת (2026-10-17T20:00:00Z) → מילישניות, או 0. */
    fun isoToMs(iso: String): Long = try {
        SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }
            .parse(iso)?.time ?: 0L
    } catch (e: Exception) { 0L }

    /** התפוגה הקרובה שעוד לא הגיעה, או 0. */
    fun nextExpiry(expiries: List<String>, nowMs: Long): Long =
        expiries.map { isoToMs(it) }.filter { it > nowMs }.minOrNull() ?: 0L
}
