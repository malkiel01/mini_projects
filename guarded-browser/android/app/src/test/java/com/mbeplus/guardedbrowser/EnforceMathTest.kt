package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** חישובי האכיפה (EnforceMath) — השעיה שנכשלה נשארת לניסיון חוזר. */
class EnforceMathTest {

    @Test fun failedSuspensionIsNotRecordedAsSuspended() {
        val next = EnforceMath.nextSuspended(block = setOf("a", "b"), prev = emptySet(), failOn = listOf("b"), failOff = emptyList())
        assertEquals(setOf("a"), next)   // b לא נרשם — בהחלה הבאה (b - prev) כולל אותו שוב
    }

    @Test fun failedUnsuspendStaysSuspendedForRetry() {
        val next = EnforceMath.nextSuspended(block = emptySet(), prev = setOf("c"), failOn = emptyList(), failOff = listOf("c"))
        assertEquals(setOf("c"), next)
    }

    @Test fun allSucceeded() {
        val next = EnforceMath.nextSuspended(block = setOf("a"), prev = setOf("a", "c"), failOn = emptyList(), failOff = emptyList())
        assertEquals(setOf("a"), next)
    }

    @Test fun reportIsResentWhenChangedOrOld() {
        assertTrue(EnforceMath.shouldSend("x", "y", lastAtMs = 1_000, nowMs = 2_000))
        assertFalse(EnforceMath.shouldSend("x", "x", lastAtMs = 1_000, nowMs = 2_000))
        assertTrue(EnforceMath.shouldSend("x", "x", lastAtMs = 1_000, nowMs = 1_000 + 6 * 3600_000L + 1))
    }
}
