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

    // ── אסימון שחרור (שלב 8): קשור לחשבון ולילד שהוצמדו, ובתוקף ──
    private val now = 1_800_000_000L
    private fun ok(typ: String = "release", acct: Int = 7, child: Int = 3, exp: Long = now + 3600,
                   myAcct: Int = 7, myChild: Int = 3) =
        SyncRules.releaseClaimsOk(typ, acct, child, exp, myAcct, myChild, now)

    @Test fun releaseForThisChildInTimeIsValid() = assertEquals(true, ok())
    @Test fun releaseOfAnotherAccountIsRejected() = assertEquals(false, ok(acct = 8))
    @Test fun releaseOfASiblingIsRejected() = assertEquals(false, ok(child = 4))
    @Test fun expiredReleaseIsRejected() = assertEquals(false, ok(exp = now - 1))
    @Test fun releaseWithoutExpIsRejected() = assertEquals(false, ok(exp = 0))
    @Test fun otherTokenTypeIsNotRelease() = assertEquals(false, ok(typ = "offline"))
    @Test fun deviceWithoutPinnedAccountNeverReleases() = assertEquals(false, ok(acct = 0, myAcct = 0))
    @Test fun childNotYetPinnedStillChecksAccount() = assertEquals(true, ok(myChild = 0))
}
