package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class BrowserMathTest {

    @Test fun blockedParts() {
        var p = emptyMap<String, BrowserMath.Part>()
        p = BrowserMath.addBlocked(p, "https://cdn.stream.tv/live/1.ts", "not_listed")
        p = BrowserMath.addBlocked(p, "https://cdn.stream.tv/live/2.ts", "not_listed")
        p = BrowserMath.addBlocked(p, "https://www.player.com/embed.js", "category_deny")
        p = BrowserMath.addBlocked(p, "https://ads.example/x", "ad_blocked")
        p = BrowserMath.addBlocked(p, "not a url", "not_listed")
        val list = BrowserMath.sorted(p)
        assertEquals("פרסומת וכתובת שבורה — לא נספרות", 2, list.size)
        assertEquals("השרת שנחסם הכי הרבה — ראשון", "cdn.stream.tv", list[0].host)
        assertEquals(2, list[0].count)
        assertEquals("בלי www, וכתובת לבקשה — השרת", "https://player.com/", list[1].sample)
        assertEquals("category_deny", list[1].code)
    }

    @Test fun zoom() {
        assertEquals(115, BrowserMath.zoomStep(100, up = true))
        assertEquals(85, BrowserMath.zoomStep(100, up = false))
        assertEquals("בקצה — נשאר", 200, BrowserMath.zoomStep(200, up = true))
        assertEquals(70, BrowserMath.zoomStep(70, up = false))
        assertEquals("ערך שלא ברשימה — לשלב הבא", 115, BrowserMath.zoomStep(105, up = true))
        assertEquals(100, BrowserMath.zoomStep(105, up = false))
    }

    @Test fun desktop() {
        val ua = "Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0 Mobile Safari/537.36"
        val d = BrowserMath.desktopUa(ua)
        assertFalse(d.contains("Android")); assertFalse(d.contains("Mobile")); assertFalse(d.contains("; wv"))
        assertTrue(d.contains("(X11; Linux x86_64)")); assertTrue(d.contains("Chrome/130.0"))
    }

    @Test fun translateHosts() {
        assertTrue(BrowserMath.isTranslateHost("translate.googleapis.com"))
        assertTrue(BrowserMath.isTranslateHost("www.gstatic.com"))
        assertFalse("הפרוקסי של התרגום מגיש כל אתר — לא", BrowserMath.isTranslateHost("example-com.translate.goog"))
        assertFalse(BrowserMath.isTranslateHost("evilgstatic.com"))
        assertFalse(BrowserMath.isTranslateHost("google.com"))
    }
}
