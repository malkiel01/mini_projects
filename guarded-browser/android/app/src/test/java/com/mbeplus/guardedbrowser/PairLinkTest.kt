package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** פענוח ה-QR של ההורה ושל קוד שמוקלד ביד — אותם מקרים כמו cleanPairCode בשרת. */
class PairLinkTest {

    @Test fun qrWithServer() {
        val p = PairLink.parse("migdalor:pair?c=ab23cd45&s=https%3A%2F%2Fmbe-plus.com%2Fmini_projects%2Fguarded-browser%2F")
        assertEquals("ab23cd45", p?.code)
        assertEquals("https://mbe-plus.com/mini_projects/guarded-browser/", p?.server)
    }

    @Test fun qrWithoutHttpsServerKeepsCodeOnly() {
        val p = PairLink.parse("migdalor:pair?c=ab23cd45&s=http%3A%2F%2Fevil.com%2F")
        assertEquals("ab23cd45", p?.code)
        assertEquals("", p?.server)
    }

    @Test fun typedCodeWithDashAndCaps() {
        assertEquals("ab23cd45", PairLink.parse(" AB23-CD45 ")?.code)
        assertEquals("ab23cd45", PairLink.parse("ab23 cd45")?.code)
    }

    @Test fun rejectsGarbage() {
        assertNull(PairLink.parse(""))
        assertNull(PairLink.parse("hello"))
        assertNull(PairLink.parse("https://example.com/"))
        assertNull(PairLink.parse("migdalor:pair?c=short"))
        assertNull(PairLink.parse("ab23cd4!"))
    }
}
