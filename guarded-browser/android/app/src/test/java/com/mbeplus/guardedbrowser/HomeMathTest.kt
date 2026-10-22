package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Test

class HomeMathTest {

    private val now = 1_792_670_400_000L   // 2026-10-22T12:00:00Z

    @Test fun minutesLeft() {
        assertEquals(30, HomeMath.minutesLeft("2026-10-22T12:30:00Z", now))
        assertEquals("עיגול למעלה — עוד חצי דקה היא דקה", 1, HomeMath.minutesLeft("2026-10-22T12:00:30Z", now))
        assertEquals(0, HomeMath.minutesLeft("2026-10-22T11:00:00Z", now))
        assertEquals(-1, HomeMath.minutesLeft("", now))
        assertEquals(-1, HomeMath.minutesLeft("not a date", now))
    }

    @Test fun lines() {
        assertEquals("נשארו 30 דק׳", HomeMath.taskLine("2026-10-22T12:30:00Z", now))
        assertEquals("נשארו 2 שע׳ ו-5 דק׳", HomeMath.taskLine("2026-10-22T14:05:00Z", now))
        assertEquals("הזמן נגמר", HomeMath.taskLine("2026-10-22T11:00:00Z", now))
        assertEquals("✅ אושר", HomeMath.requestStatus("approved"))
        assertEquals(7, HomeMath.ALL.size)
    }
}
