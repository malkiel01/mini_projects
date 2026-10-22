package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** חישובי שימוש וזמן (UsageMath) — המקרים מאפיון הסקירה, שלב 3. */
class UsageMathTest {

    @Test fun shortOpenUnderThirtySecondsIsCounted() {
        // פתיחה של 12 שניות: שתי דגימות (5+5) ועצירה (2) — כל ה-12 נספרות, לא 0 כמו עם פעימה של 30.
        assertEquals(5_000L, UsageMath.step(1_000, 6_000))
        assertEquals(5_000L, UsageMath.step(6_000, 11_000))
        assertEquals(2_000L, UsageMath.step(11_000, 13_000))
    }

    @Test fun firstSampleAndLongGapsAreNotUsage() {
        assertEquals(0L, UsageMath.step(0, 5_000))                       // אין דגימה קודמת
        assertEquals(0L, UsageMath.step(1_000, 1_000 + UsageMath.MAX_STEP_MS + 1)) // התהליך נעצר / הטלפון ישן
        assertEquals(0L, UsageMath.step(10_000, 5_000))                  // אחרי הפעלה מחדש
    }

    @Test fun wallClockChangeDoesNotAffectTrustedTime() {
        // עוגן: שעון השרת 1,000,000 כש-elapsed היה 500. עברו 60 שניות מונוטוניות — הילד הזיז את השעון שעה אחורה.
        val wallMovedBack = 1_000_000L - 3_600_000
        assertEquals(1_060_000L, UsageMath.trustedNow(1_000_000, 500, 60_500, wallMovedBack))
    }

    @Test fun afterRebootFallsBackToWallClockUntilNextSync() {
        assertEquals(42L, UsageMath.trustedNow(1_000_000, 500_000, 1_000, 42))
        assertEquals(42L, UsageMath.trustedNow(0, 0, 1_000, 42))
    }

    @Test fun midnightBelongsToTheNewDayInPolicyTimezone() {
        // 2026-10-20 21:00:00Z = 00:00 ב-21/10 בירושלים (UTC+3), ועדיין 20/10 ב-UTC.
        val ms = UsageMath.isoToMs("2026-10-20T21:00:00Z")
        assertEquals("2026-10-21", UsageMath.dayKey(ms, "Asia/Jerusalem"))
        assertEquals("2026-10-20", UsageMath.dayKey(ms - 1_000, "Asia/Jerusalem"))
        assertEquals("2026-10-20", UsageMath.dayKey(ms, "UTC"))
    }

    @Test fun serverUsageOfAnotherDayIsNotCountedToday() {
        assertEquals(700, UsageMath.usedToday(600, "2026-10-20", "2026-10-20", 100))
        assertEquals(100, UsageMath.usedToday(600, "2026-10-19", "2026-10-20", 100))   // אחרי חצות
    }

    @Test fun sessionSurvivesQuickReopenButResetsAfterBreak() {
        val now = 10_000_000L
        assertFalse(UsageMath.sessionReset(now - 60_000, now))
        assertTrue(UsageMath.sessionReset(now - UsageMath.SESSION_GAP_MS - 1, now))
        assertTrue(UsageMath.sessionReset(0, now))
        assertTrue(UsageMath.sessionReset(now + 5_000, now))   // הזמן "חזר אחורה" — מתחילים מחדש
    }

    @Test fun graceWindow() {
        val now = 100L * 3_600_000
        assertFalse(UsageMath.graceExpired(0, now))                                   // עוד לא סונכרן
        assertFalse(UsageMath.graceExpired(now - 3_600_000, now))
        assertTrue(UsageMath.graceExpired(now - UsageMath.GRACE_MS - 1, now))
    }

    @Test fun nextExpiryIsTheNearestFutureOne() {
        val now = UsageMath.isoToMs("2026-10-20T12:00:00Z")
        val next = UsageMath.nextExpiry(listOf("2026-10-20T11:00:00Z", "2026-10-20T14:00:00Z", "2026-10-20T13:00:00Z"), now)
        assertEquals(UsageMath.isoToMs("2026-10-20T13:00:00Z"), next)
        assertEquals(0L, UsageMath.nextExpiry(listOf("2026-10-20T11:00:00Z", "garbage"), now))
    }

    @Test fun expiredPermissionIsGoneEvenWithoutServer() {
        val r = Rule(label = "", pattern = "example.org", scope = "domain", action = "allow", showTile = false, expiresAt = "2026-10-20T12:00:00Z")
        assertEquals(1, PolicyEngine.liveRules(listOf(r), "2026-10-20T11:59:59Z").size)
        assertEquals(0, PolicyEngine.liveRules(listOf(r), "2026-10-20T12:00:00Z").size)
    }
}
