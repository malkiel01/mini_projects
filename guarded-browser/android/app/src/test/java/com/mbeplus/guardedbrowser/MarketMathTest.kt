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

    @Test fun robotLabels() {
        assertTrue(MarketMath.isInstall("התקנה"))
        assertTrue(MarketMath.isInstall(" Install "))
        assertFalse("רק התאמה מלאה", MarketMath.isInstall("התקנה במכשירים נוספים"))
        assertFalse(MarketMath.isInstall("Install on more devices"))
        assertFalse(MarketMath.isInstall("עדכון"))
        assertTrue("אפליקציה בתשלום", MarketMath.isBlocker("₪14.90"))
        assertTrue(MarketMath.isBlocker("Sign in"))
        assertFalse(MarketMath.isBlocker("התקנה"))
        assertFalse(MarketMath.isBlocker(""))
        assertFalse("לא כל טקסט שמכיל \"כניסה\"", MarketMath.isBlocker("כניסה חופשית לכל הגילאים"))
    }

    @Test fun robotSteps() {
        assertEquals("click", MarketMath.robotStep(t, 0, t + 2000, button = true, blocker = false))
        assertEquals("wait", MarketMath.robotStep(t, 0, t + 2000, button = false, blocker = false))
        assertEquals("לא נמצא כפתור", "fallback", MarketMath.robotStep(t, 0, t + MarketMath.ROBOT_FIND_MS + 1, false, false))
        assertEquals("fallback", MarketMath.robotStep(t, 0, t + 1000, button = true, blocker = true))
        assertEquals("אחרי לחיצה — מחכים, לא לוחצים שוב", "wait", MarketMath.robotStep(t, t + 1000, t + 60_000, button = true, blocker = false))
        assertEquals("fallback", MarketMath.robotStep(t, t + 1000, t + 1001 + MarketMath.ROBOT_INSTALL_MS, false, false))
    }

    @Test fun buttons() {
        assertEquals("open", MarketMath.action(true, "com.duolingo", "", 0, t))
        assertEquals("install", MarketMath.action(false, "com.duolingo", "", 0, t))
        assertEquals("installing", MarketMath.action(false, "com.duolingo", "com.duolingo", until, t))
        assertEquals("install", MarketMath.action(false, "com.waze", "com.duolingo", until, t))
    }
}
