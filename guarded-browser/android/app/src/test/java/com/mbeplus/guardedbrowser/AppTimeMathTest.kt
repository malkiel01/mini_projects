package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** זמן מסך לאפליקציות (2026-10-22): מה נספר, ומתי נגמר. */
class AppTimeMathTest {

    private val apps = setOf("com.whatsapp", "com.game")
    private val transient = setOf("com.android.systemui", "com.google.android.inputmethod.latin")

    @Test fun keyboardDoesNotStopCounting() =
        assertEquals("com.whatsapp", AppTimeMath.foreground("com.whatsapp", "com.google.android.inputmethod.latin", apps, transient, "me"))

    @Test fun notificationShadeDoesNotStopCounting() =
        assertEquals("com.game", AppTimeMath.foreground("com.game", "com.android.systemui", apps, transient, "me"))

    @Test fun homeStopsCounting() =
        assertEquals("", AppTimeMath.foreground("com.game", "com.android.launcher3", apps, transient, "me"))

    @Test fun migdalorItselfIsNotCounted() =
        assertEquals("", AppTimeMath.foreground("com.game", "me", apps + "me", transient, "me"))

    @Test fun stepIsCapped() {
        assertEquals(15_000L, AppTimeMath.step(1_000, 16_000))
        // המכשיר נרדם שעה — נספרות לכל היותר 30 שניות, לא שעה.
        assertEquals(AppTimeMath.MAX_STEP_MS, AppTimeMath.step(1_000, 3_600_000))
        assertEquals(0L, AppTimeMath.step(0, 16_000))
        assertEquals(0L, AppTimeMath.step(16_000, 1_000))    // שעון שזז אחורה
    }

    @Test fun accumulatesAndResetsOnNewDay() {
        var u = AppTimeMath.Usage("2026-10-22", emptyMap())
        u = AppTimeMath.add(u, "2026-10-22", "com.game", 60_000)
        u = AppTimeMath.add(u, "2026-10-22", "com.game", 30_000)
        assertEquals(90_000L, AppTimeMath.usedMs(u, "2026-10-22", "com.game"))
        assertEquals(0L, AppTimeMath.usedMs(u, "2026-10-23", "com.game"))
        u = AppTimeMath.add(u, "2026-10-23", "com.game", 15_000)
        assertEquals(15_000L, AppTimeMath.usedMs(u, "2026-10-23", "com.game"))
    }

    @Test fun limit() {
        assertFalse(AppTimeMath.over(0, 10 * 3_600_000L))          // בלי מגבלה
        assertFalse(AppTimeMath.over(30, 29 * 60_000L))
        assertTrue(AppTimeMath.over(30, 30 * 60_000L))
        assertEquals(60_000L, AppTimeMath.leftMs(30, 29 * 60_000L))
    }
}
