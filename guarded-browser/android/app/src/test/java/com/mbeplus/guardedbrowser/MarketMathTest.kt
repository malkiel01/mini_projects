package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MarketMathTest {

    private val t = 1_000_000L
    private val until = t + MarketMath.WINDOW_MS

    @Test fun window() {
        assertTrue(MarketMath.open("com.duolingo", until, t))
        assertFalse("עבר הזמן", MarketMath.open("com.duolingo", until, until))
        assertFalse("אין יעד", MarketMath.open("", until, t))
        assertFalse("שעון שחזר אחורה — סגור", MarketMath.open("com.duolingo", until, -1))
    }

    @Test fun installs() {
        assertEquals("done", MarketMath.onInstalled("com.duolingo", "com.duolingo", until, t))
        assertEquals("stray", MarketMath.onInstalled("com.roblox.client", "com.duolingo", until, t))
        assertEquals("none", MarketMath.onInstalled("com.roblox.client", "com.duolingo", until, until + 1))
    }

    @Test fun buttons() {
        assertEquals("open", MarketMath.action(true, "com.duolingo", "", 0, t))
        assertEquals("install", MarketMath.action(false, "com.duolingo", "", 0, t))
        assertEquals("installing", MarketMath.action(false, "com.duolingo", "com.duolingo", until, t))
        assertEquals("install", MarketMath.action(false, "com.waze", "com.duolingo", until, t))
    }
}
