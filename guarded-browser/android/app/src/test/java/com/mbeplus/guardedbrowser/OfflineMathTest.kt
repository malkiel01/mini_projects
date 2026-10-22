package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** מצב לא מקוון מתוכנן (2026-10-22): רק מצמצם, ובסוף החלון — הכול נחסם. */
class OfflineMathTest {

    private val now = 100 * 3600_000L
    private val allowedRule = Verdict(true, "rule_allow")
    private val allowedByPosture = Verdict(true, "posture_allow")

    @Test fun onlineIsUntouched() {
        assertFalse(OfflineMath.offline(now - 60_000, now))
        assertEquals(allowedByPosture, OfflineMath.gate("none", false, allowedByPosture, true))
    }

    @Test fun neverSyncedIsNotOffline() = assertFalse(OfflineMath.offline(0, now))

    @Test fun offlineAfterThreeMinutes() = assertTrue(OfflineMath.offline(now - 4 * 60_000, now))

    @Test fun allKeepsPolicy() = assertTrue(OfflineMath.gate("all", true, allowedByPosture, true).allow)

    @Test fun approvedOnlyExplicit() {
        assertTrue(OfflineMath.gate("approved", true, allowedRule, true).allow)
        assertTrue(OfflineMath.gate("approved", true, Verdict(true, "yt_video_allowed"), true).allow)
        assertEquals("offline_approved", OfflineMath.gate("approved", true, allowedByPosture, true).code)
        // משאב בתוך דף מאושר — לפי המדיניות, אחרת הדף לא נטען.
        assertTrue(OfflineMath.gate("approved", true, allowedByPosture, false).allow)
    }

    @Test fun noneBlocksEverything() = assertEquals("offline_none", OfflineMath.gate("none", true, allowedRule, true).code)

    @Test fun neverWidens() {
        val denied = Verdict(false, "rule_deny")
        assertEquals(denied, OfflineMath.gate("all", true, denied, true))
    }

    @Test fun graceWindow() {
        assertEquals(2 * 3600_000L, OfflineMath.leftMs(now - 4 * 3600_000L, now, 6))
        assertEquals(0L, OfflineMath.leftMs(now - 7 * 3600_000L, now, 6))
        assertEquals(3600_000L, OfflineMath.graceMs(0))       // לא פחות משעה
        assertEquals(72 * 3600_000L, OfflineMath.graceMs(500))
    }

    @Test fun childSeesWhatIsAvailableAndHowLong() {
        val line = OfflineMath.statusLine("approved", true, 90 * 60_000L)
        assertTrue(line.contains("רק אתרים שההורה אישר"))
        assertTrue(line.contains("1 ש׳ ו-30 דק׳"))
        assertEquals("", OfflineMath.statusLine("approved", false, 90 * 60_000L))
        assertTrue(OfflineMath.statusLine("all", true, 0).contains("סגורה"))
    }
}
