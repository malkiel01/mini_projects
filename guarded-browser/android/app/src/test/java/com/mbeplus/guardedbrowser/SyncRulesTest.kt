package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** כללי הסנכרון (SyncRules) — סדר תשובות, ושחרור/הארכה. */
class SyncRulesTest {

    @Test fun olderResponseIsStale() {
        assertTrue(SyncRules.isStale(lastApplied = 2_000, incoming = 1_000))
    }

    @Test fun newerOrSameResponseIsApplied() {
        assertFalse(SyncRules.isStale(lastApplied = 1_000, incoming = 2_000))
        assertFalse(SyncRules.isStale(lastApplied = 1_000, incoming = 1_000))
    }

    @Test fun serverWithoutSequenceIsAlwaysApplied() {
        // שרת ישן שלא שולח issued_at — מתנהגים כמו קודם.
        assertFalse(SyncRules.isStale(lastApplied = 5_000, incoming = 0))
    }

    @Test fun validTokenEntersRelease() {
        assertEquals(SyncRules.Release.ENTER, SyncRules.release(released = false, tokenPresent = true, tokenValid = true))
    }

    @Test fun forgedTokenDoesNotRelease() {
        assertEquals(SyncRules.Release.NONE, SyncRules.release(released = false, tokenPresent = true, tokenValid = false))
    }

    @Test fun answerWithoutTokenRenewsReleasedDevice() {
        assertEquals(SyncRules.Release.RENEW, SyncRules.release(released = true, tokenPresent = false, tokenValid = false))
    }

    @Test fun stillReleasedStaysReleased() {
        assertEquals(SyncRules.Release.NONE, SyncRules.release(released = true, tokenPresent = true, tokenValid = true))
    }

    @Test fun normalDeviceStaysNormal() {
        assertEquals(SyncRules.Release.NONE, SyncRules.release(released = false, tokenPresent = false, tokenValid = false))
    }
}
