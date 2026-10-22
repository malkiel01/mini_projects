package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** בדיקת הגנה יזומה — מה המכשיר מודד (2026-10-22). */
class SelfTestMathTest {

    @Test fun runsOnlyOnNewRequest() {
        assertTrue(SelfTestMath.shouldRun("2026-10-22T08:00:00Z-ab12", ""))
        assertFalse(SelfTestMath.shouldRun("2026-10-22T08:00:00Z-ab12", "2026-10-22T08:00:00Z-ab12"))
        assertFalse(SelfTestMath.shouldRun("", ""))
    }

    @Test fun suspendedReadFromSystemWins() =
        assertEquals("suspended", SelfTestMath.appState(installed = true, policyBlocked = true, suspended = true, access = false))

    @Test fun notSuspendedButAccessibilityBlocks() =
        assertEquals("access", SelfTestMath.appState(installed = true, policyBlocked = true, suspended = false, access = true))

    @Test fun policyBlocksButNothingEnforces() =
        // המדיניות במכשיר אומרת "חסום", אבל אין השעיה ואין נגישות — זה בדיוק מה שהבדיקה צריכה לתפוס.
        assertEquals("open", SelfTestMath.appState(installed = true, policyBlocked = true, suspended = null, access = false))

    @Test fun deviceThinksAllowed() =
        assertEquals("open", SelfTestMath.appState(installed = true, policyBlocked = false, suspended = false, access = true))

    @Test fun uninstalledIsMissing() =
        assertEquals("missing", SelfTestMath.appState(installed = false, policyBlocked = true, suspended = null, access = true))

    @Test fun syncAge() {
        assertEquals(-1L, SelfTestMath.syncAgoSec(1_000_000, 0))
        assertEquals(90L, SelfTestMath.syncAgoSec(1_000_000, 910_000))
        assertEquals(0L, SelfTestMath.syncAgoSec(1_000_000, 2_000_000))   // שעון שזז אחורה — לא שלילי
    }
}
