package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class NewAppMathTest {

    private val known = setOf("com.whatsapp")
    private val baseline = setOf("com.whatsapp", "com.waze")

    @Test fun newOnlyWhenNotKnownAndNotInBaseline() {
        assertTrue(NewAppMath.isNew("com.tiktok", known, baseline))
        assertFalse("ההורה כבר ראה", NewAppMath.isNew("com.whatsapp", known, baseline))
        assertFalse("הייתה מותקנת כשהתחלנו", NewAppMath.isNew("com.waze", known, baseline))
        assertFalse("בלי baseline — לא יודעים מה חדש", NewAppMath.isNew("com.tiktok", known, emptySet()))
        assertEquals(setOf("com.tiktok"), NewAppMath.fresh(setOf("com.tiktok", "com.waze"), known, baseline))
    }

    @Test fun reportOncePerNewAppThenEverySixHours() {
        val t = 1_000_000_000L
        val fresh = setOf("com.tiktok")
        assertTrue(NewAppMath.shouldReport(fresh, emptySet(), 0, t))
        assertFalse("כבר דווחה", NewAppMath.shouldReport(fresh, fresh, t, t + 60_000))
        assertTrue("עוד אחת", NewAppMath.shouldReport(fresh + "com.roblox", fresh, t, t + 60_000))
        assertTrue("6 שעות", NewAppMath.shouldReport(fresh, fresh, t, t + 6 * 3600_000L + 1))
        assertFalse("אין חדשות", NewAppMath.shouldReport(emptySet(), emptySet(), 0, t))
    }
}
