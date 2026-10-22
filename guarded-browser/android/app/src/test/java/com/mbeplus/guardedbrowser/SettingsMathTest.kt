package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** תוצאת ההחלה לכל הגדרה — "אומת" רק כשהמצב נקרא בחזרה ותואם (2026-10-22). */
class SettingsMathTest {

    @Test fun readBackMatchesIsVerified() = assertEquals("verified", SettingsMath.result(true, true, true, true))
    @Test fun callOkButCannotReadIsOnlyApplied() = assertEquals("applied", SettingsMath.result(true, true, true, null))
    @Test fun readBackDiffersIsFail() = assertEquals("fail", SettingsMath.result(true, true, true, false))
    @Test fun callThrewIsFail() = assertEquals("fail", SettingsMath.result(true, true, false, true))
    @Test fun noOwnerIsNoOwner() = assertEquals("no_owner", SettingsMath.result(false, true, true, true))
    @Test fun noRestrictionOnThisAndroidIsUnsupported() = assertEquals("unsupported", SettingsMath.result(true, false, true, null))

    @Test fun blockedNeedsAllRestrictions() {
        assertTrue(SettingsMath.restrictionsMatch(true, listOf(true, true)))
        assertFalse(SettingsMath.restrictionsMatch(true, listOf(true, false)))
    }

    @Test fun allowedNeedsNoRestrictionLeft() {
        // התקלה המדווחת: ההורה התיר, אבל הגבלה אחת נשארה — זה כישלון, לא "הוחל".
        assertTrue(SettingsMath.restrictionsMatch(false, listOf(false, false)))
        assertFalse(SettingsMath.restrictionsMatch(false, listOf(false, true)))
    }

    @Test fun storeAllowedButStoreAppBlocked() =
        assertEquals("blocked_by_apps", SettingsMath.installApps("verified", wantBlocked = false, storeBlocked = true))
    @Test fun storeAllowedAndStoreOpen() =
        assertEquals("verified", SettingsMath.installApps("verified", wantBlocked = false, storeBlocked = false))
    @Test fun storeBlockedIsJustVerified() =
        assertEquals("verified", SettingsMath.installApps("verified", wantBlocked = true, storeBlocked = true))
    @Test fun failStaysFail() =
        assertEquals("fail", SettingsMath.installApps("fail", wantBlocked = false, storeBlocked = true))

    @Test fun apkAllowedStillNeedsAndroidPerSourceGrant() =
        assertEquals("needs_device", SettingsMath.installUnknown("verified", wantBlocked = false))
    @Test fun apkBlockedIsVerified() =
        assertEquals("verified", SettingsMath.installUnknown("verified", wantBlocked = true))
}
