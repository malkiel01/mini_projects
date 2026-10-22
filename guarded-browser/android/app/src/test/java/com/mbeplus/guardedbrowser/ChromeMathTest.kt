package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ChromeMathTest {

    private val spec = ChromeMath.spec(listOf("*", "youtube.com"), listOf("wikipedia.org"), youtube = false)

    @Test fun lockedDown() {
        assertEquals(true, spec["ForceGoogleSafeSearch"])
        assertEquals(1, spec["IncognitoModeAvailability"])
        assertEquals(1, spec["SafeSitesFilterBehavior"])
        assertFalse("יוטיוב חסום — בלי מפתח מצב מוגבל", spec.containsKey("ForceYouTubeRestrict"))
        assertEquals(1, ChromeMath.spec(emptyList(), emptyList(), youtube = true)["ForceYouTubeRestrict"])
    }

    @Test fun readBackMustMatchEveryKey() {
        assertTrue(ChromeMath.matches(spec, spec + ("Other" to 5)))
        assertFalse("רשימה שונה", ChromeMath.matches(spec, spec + ("URLBlocklist" to listOf("*"))))
        assertFalse("מפתח חסר — לא אומת", ChromeMath.matches(spec, spec - "IncognitoModeAvailability"))
        assertFalse("לא הוחל כלום", ChromeMath.matches(spec, emptyMap()))
    }
}
