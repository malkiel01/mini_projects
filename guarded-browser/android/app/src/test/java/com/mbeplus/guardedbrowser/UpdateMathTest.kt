package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** עדכון: חלון ההתקנה נסגר בכל מסלול, והקובץ נבדק לפני שהוא נפתח (שלב 9). */
class UpdateMathTest {

    private val now = 1_800_000_000_000L
    private val me = "com.mbeplus.guardedbrowser"
    private val sha = "a".repeat(64)

    private fun check(pkg: String? = me, code: Long = 98, offered: Int = 98, installed: Int = 97,
                      certs: Set<String> = setOf("K1"), mine: Set<String> = setOf("K1"),
                      got: String = sha, expect: String = sha) =
        UpdateMath.checkArchive(pkg, me, code, offered, installed, certs, mine, got, expect)

    // ── חלון ההתקנה ──
    @Test fun openWindowIsOpen() = assertTrue(UpdateMath.installOpen(now + 60_000, now))
    @Test fun expiredWindowIsClosed() = assertFalse(UpdateMath.installOpen(now - 1, now))
    @Test fun neverOpenedIsClosed() = assertFalse(UpdateMath.installOpen(0, now))
    @Test fun windowLongerThanAllowedIsClosed() =
        // שעון שזז אחורה לא משאיר את ההתקנה פתוחה לשעות.
        assertFalse(UpdateMath.installOpen(now + UpdateMath.OPEN_MS + 60_000, now))

    @Test fun restoreAfterExpiry() = assertTrue(UpdateMath.shouldRestore(now - 1, now, stillRequired = true))
    @Test fun restoreWhenNewVersionRuns() = assertTrue(UpdateMath.shouldRestore(now + 60_000, now, stillRequired = false))
    @Test fun keepOpenDuringInstall() = assertFalse(UpdateMath.shouldRestore(now + 60_000, now, stillRequired = true))
    @Test fun nothingToRestoreWhenNeverOpened() = assertFalse(UpdateMath.shouldRestore(0, now, stillRequired = true))

    // ── בדיקת הקובץ ──
    @Test fun theOfferedUpdatePasses() = assertEquals("", check())
    @Test fun newerThanOfferedPasses() = assertEquals("", check(code = 99))
    @Test fun notAnApk() = assertTrue(check(pkg = null).isNotEmpty())
    @Test fun otherPackage() = assertTrue(check(pkg = "com.evil.app").isNotEmpty())
    @Test fun sameOrOlderThanInstalled() = assertTrue(check(code = 97).isNotEmpty())
    @Test fun olderThanOffered() = assertTrue(check(code = 98, offered = 99, installed = 90).isNotEmpty())
    @Test fun foreignSignature() = assertTrue(check(certs = setOf("EVIL")).isNotEmpty())
    @Test fun rotatedKeyWithLineagePasses() = assertEquals("", check(certs = setOf("K2", "K1")))
    @Test fun unreadableSignaturesAreLeftToAndroid() {
        assertEquals("", check(mine = emptySet()))
        assertEquals("", check(certs = emptySet()))
    }
    @Test fun shaMismatch() = assertTrue(check(got = "b".repeat(64)).isNotEmpty())
    @Test fun shaCaseInsensitive() = assertEquals("", check(got = sha.uppercase()))
    @Test fun oldServerWithoutShaStillChecksTheRest() = assertEquals("", check(expect = ""))
}
