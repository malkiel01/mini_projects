package com.mbeplus.guardedbrowser

import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/**
 * דף הבית ברובריקות (lib/policy.php, HOME_SECTIONS) — החישובים, בלי Android (HomeMathTest).
 */
object HomeMath {

    /** הרובריקות, לפי הסדר הקבוע. גרסה ישנה של השרת (אין home_sections) — כולן. */
    val ALL = listOf("search", "sites", "youtube", "apps", "favorites", "tasks", "requests")

    /** כמה דקות נשארו עד ‏ends (ISO ב-UTC, כמו בשרת); ‏-1 — לא ידוע. */
    fun minutesLeft(ends: String, nowMs: Long): Int {
        if (ends.isEmpty()) return -1
        return try {
            val f = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }
            val ms = f.parse(ends)?.time ?: return -1
            maxOf(0, ((ms - nowMs + 59_999) / 60_000).toInt())
        } catch (e: Exception) { -1 }
    }

    /** שורת הזמן של משימה. */
    fun taskLine(ends: String, nowMs: Long): String = when (val m = minutesLeft(ends, nowMs)) {
        -1 -> "משימה"
        0 -> "הזמן נגמר"
        in 1..59 -> "נשארו $m דק׳"
        else -> "נשארו ${m / 60} שע׳ ו-${m % 60} דק׳"
    }

    /** מצב בקשה, כמו ששולח השרת (requestsOfChild). */
    fun requestStatus(status: String): String = when (status) {
        "pending" -> "⏳ מחכה להורה"
        "approved" -> "✅ אושר"
        "denied" -> "❌ לא אושר"
        else -> status
    }
}
