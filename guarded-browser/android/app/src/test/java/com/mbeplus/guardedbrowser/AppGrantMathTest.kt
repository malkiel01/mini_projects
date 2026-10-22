package com.mbeplus.guardedbrowser

import com.mbeplus.guardedbrowser.AppGrantMath.Grant
import com.mbeplus.guardedbrowser.AppGrantMath.Offer
import org.junit.Assert.assertEquals
import org.junit.Test

/** היתר זמני: מתחיל בהחלה במכשיר, נגמר בשעון של המכשיר, ומבוטל כשההורה משנה (2026-10-22). */
class AppGrantMathTest {

    private val now = 1_800_000_000_000L
    private val min = 60_000L

    @Test fun newGrantStartsNowOnTheDevice() {
        val r = AppGrantMath.reconcile(emptyMap(), listOf(Offer(7, "com.roblox", 30, 0)), now)
        assertEquals(Grant("com.roblox", now + 30 * min), r.grants[7])
        assertEquals(listOf(7), r.started)
    }

    @Test fun runningGrantIsNotRestartedBySync() {
        val local = mapOf(7 to Grant("com.roblox", now + 5 * min))
        val r = AppGrantMath.reconcile(local, listOf(Offer(7, "com.roblox", 30, 0)), now)
        assertEquals(now + 5 * min, r.grants[7]?.until)
        assertEquals(emptyList<Int>(), r.started)
    }

    @Test fun alreadyAppliedElsewhereKeepsServerEnd() {
        val r = AppGrantMath.reconcile(emptyMap(), listOf(Offer(7, "com.roblox", 30, now + 10 * min)), now)
        assertEquals(now + 10 * min, r.grants[7]?.until)
    }

    @Test fun grantMissingFromServerIsRevoked() {
        val local = mapOf(7 to Grant("com.roblox", now + 20 * min))
        val r = AppGrantMath.reconcile(local, emptyList(), now)
        assertEquals(emptyMap<Int, Grant>(), r.grants)
        assertEquals(listOf(7), r.dropped)
    }

    @Test fun pastEndIsDroppedEvenIfServerStillSendsIt() {
        val local = mapOf(7 to Grant("com.roblox", now - 1))
        val r = AppGrantMath.reconcile(local, listOf(Offer(7, "com.roblox", 30, now - 1)), now)
        assertEquals(emptyMap<Int, Grant>(), r.grants)
    }

    @Test fun invalidOfferIgnored() =
        assertEquals(emptyMap<Int, Grant>(), AppGrantMath.reconcile(emptyMap(), listOf(Offer(1, "", 30, 0), Offer(2, "a", 0, 0)), now).grants)

    @Test fun grantedOnlyWhileActive() {
        val g = mapOf(1 to Grant("a", now + min), 2 to Grant("b", now - 1))
        assertEquals(setOf("a"), AppGrantMath.granted(g, now))
        assertEquals(setOf(2), AppGrantMath.expired(g, now).keys)
    }

    @Test fun expiryWorksWithoutServer() {
        // בלי שום תשובה מהשרת — רק הזמן עובר.
        val g = mapOf(1 to Grant("a", now + min))
        assertEquals(setOf("a"), AppGrantMath.granted(g, now))
        assertEquals(emptySet<String>(), AppGrantMath.granted(g, now + min))
    }

    @Test fun nextEndForTimer() {
        val g = mapOf(1 to Grant("a", now + 9 * min), 2 to Grant("b", now + 3 * min), 3 to Grant("c", now - 1))
        assertEquals(now + 3 * min, AppGrantMath.nextEnd(g, now))
        assertEquals(0L, AppGrantMath.nextEnd(emptyMap(), now))
    }
}
