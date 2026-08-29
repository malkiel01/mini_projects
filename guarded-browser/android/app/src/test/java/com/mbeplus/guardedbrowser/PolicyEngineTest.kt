package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.Calendar
import java.util.TimeZone

/**
 * הבדיקות של האכיפה המקומית.
 *
 * הן מכוונות במתכוון לאותם מקרים כמו guarded-browser/tests/policy.php.
 * שני מנועים שמכריעים אותו דבר חייבים להישאר זהים, ורגרסיה כאן היא
 * כתובת שהאפליקציה מתירה והשרת חוסם — או להפך.
 */
class PolicyEngineTest {

    private fun rule(pattern: String, scope: String = "domain", action: String = "allow") =
        Rule("", pattern, scope, action, true)

    private fun at(tz: String, dayOfWeek: Int, hour: Int, minute: Int): Calendar =
        Calendar.getInstance(TimeZone.getTimeZone(tz)).apply {
            set(Calendar.DAY_OF_WEEK, dayOfWeek)
            set(Calendar.HOUR_OF_DAY, hour)
            set(Calendar.MINUTE, minute)
        }

    @Test fun normalizeFillsScheme() {
        assertEquals("example.com", PolicyEngine.normalize("example.com")?.host)
    }

    @Test fun normalizeStripsWww() {
        assertEquals("example.com", PolicyEngine.normalize("https://www.example.com")?.host)
    }

    @Test fun normalizeLowercases() {
        assertEquals("example.com", PolicyEngine.normalize("HTTPS://Example.COM/A")?.host)
    }

    @Test fun normalizeTrimsTrailingSlash() {
        assertEquals("/x", PolicyEngine.normalize("https://a.com/x/")?.path)
    }

    @Test fun normalizeKeepsRoot() {
        assertEquals("/", PolicyEngine.normalize("https://a.com")?.path)
    }

    /** סכימות שאינן http אינן ניתנות להערכה, ולכן אינן מותרות. */
    @Test fun normalizeRejectsOtherSchemes() {
        assertEquals(null, PolicyEngine.normalize("file:///etc/passwd"))
        assertEquals(null, PolicyEngine.normalize("javascript:alert(1)"))
        assertEquals(null, PolicyEngine.normalize("  "))
    }

    @Test fun subdomainMatches() {
        assertTrue(PolicyEngine.hostMatches("cdn.a.com", "a.com"))
        assertTrue(PolicyEngine.hostMatches("a.com", "a.com"))
    }

    /**
     * ‏"nota.com" נגמר ב-"a.com" כמחרוזת. בלי הנקודה המפרידה, כל מי
     * שרושם דומיין כזה עוקף כלל שמתיר a.com.
     */
    @Test fun lookalikeDomainIsNotSubdomain() {
        assertFalse(PolicyEngine.hostMatches("nota.com", "a.com"))
    }

    @Test fun exactScopeMatchesOnlyThatPath() {
        val u = PolicyEngine.normalize("https://liveball.sx/team/772")!!
        assertTrue(PolicyEngine.ruleMatches(rule("liveball.sx/team/772", "exact"), u, true))
        assertFalse(PolicyEngine.ruleMatches(rule("liveball.sx/team/999", "exact"), u, true))
    }

    @Test fun domainScopeMatchesWholeSite() {
        val u = PolicyEngine.normalize("https://liveball.sx/anything")!!
        assertTrue(PolicyEngine.ruleMatches(rule("liveball.sx"), u, true))
    }

    /**
     * ההבחנה שבגללה domain_plus קיים: דף מותר טוען נגן וגופן מדומיינים
     * אחרים, וחסימתם שוברת בדיוק את הדף שהותר.
     */
    @Test fun domainPlusAllowsForeignSubresourcesButNotNavigation() {
        val foreign = PolicyEngine.normalize("https://cdn-player.net/p.js")!!
        assertTrue(PolicyEngine.ruleMatches(rule("liveball.sx", "domain_plus"), foreign, false))
        assertFalse(PolicyEngine.ruleMatches(rule("liveball.sx", "domain_plus"), foreign, true))
        // ‏domain רגיל חוסם גם משאב נלווה.
        assertFalse(PolicyEngine.ruleMatches(rule("liveball.sx", "domain"), foreign, false))
    }

    @Test fun kioskBlocksUnlisted() {
        val rules = listOf(rule("liveball.sx"))
        val u = PolicyEngine.normalize("https://other.com")!!
        assertFalse(PolicyEngine.matchRules(rules, Policy.MODE_KIOSK, u, true).allow)
        assertFalse(PolicyEngine.matchRules(rules, Policy.MODE_ALLOWLIST, u, true).allow)
        assertTrue(PolicyEngine.matchRules(rules, Policy.MODE_FREE, u, true).allow)
    }

    /** איסור שאפשר לנטרל בהוספת היתר אחריו אינו איסור. */
    @Test fun denyWinsRegardlessOfOrder() {
        val u = PolicyEngine.normalize("https://bad.com")!!
        val before = listOf(rule("bad.com", action = "deny"), rule("bad.com"))
        val after = listOf(rule("bad.com"), rule("bad.com", action = "deny"))
        assertEquals("rule_deny", PolicyEngine.matchRules(before, Policy.MODE_FREE, u, true).code)
        assertEquals("rule_deny", PolicyEngine.matchRules(after, Policy.MODE_FREE, u, true).code)
    }

    @Test fun windowInsideAndOutside() {
        val p = Policy(windowStart = "16:00", windowEnd = "22:00")
        assertTrue(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 18, 0)).allow)
        assertFalse(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 9, 0)).allow)
        // הגבול: ההתחלה בפנים, הסיום כבר בחוץ.
        assertTrue(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 16, 0)).allow)
        assertFalse(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 22, 0)).allow)
    }

    /** חלון שחוצה חצות מהפך את התנאי — הטעות הקלה ביותר כאן. */
    @Test fun windowCrossingMidnight() {
        val p = Policy(windowStart = "22:00", windowEnd = "02:00")
        assertTrue(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 23, 0)).allow)
        assertTrue(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 1, 0)).allow)
        assertFalse(PolicyEngine.withinWindow(p, at("Asia/Jerusalem", Calendar.WEDNESDAY, 12, 0)).allow)
    }

    @Test fun dayMaskBlocks() {
        // ‏Calendar.SUNDAY=1 → ביט 1. מסכה 1 מתירה ראשון בלבד.
        val onlySunday = Policy(daysMask = 1)
        assertTrue(PolicyEngine.withinWindow(onlySunday, at("UTC", Calendar.SUNDAY, 12, 0)).allow)
        assertEquals("day_blocked",
            PolicyEngine.withinWindow(onlySunday, at("UTC", Calendar.MONDAY, 12, 0)).code)
    }

    @Test fun quotaAndSessionCaps() {
        assertTrue(PolicyEngine.withinQuota(Policy(), 999_999).allow)
        assertTrue(PolicyEngine.withinQuota(Policy(dailyQuotaMin = 120), 3600).allow)
        assertEquals("quota_spent", PolicyEngine.withinQuota(Policy(dailyQuotaMin = 120), 7200).code)
        assertEquals("session_over", PolicyEngine.withinSession(Policy(sessionMaxMin = 30), 1800).code)
    }

    /**
     * הסיבה שמוצגת חייבת להיות השורשית. "הכתובת אינה ברשימה" למשתמש
     * שמכסתו נגמרה שולח אותו לבקש כלל נוסף במקום לומר לו לחכות למחר.
     */
    @Test fun rootCauseWinsOverListCheck() {
        val p = Policy(dailyQuotaMin = 10)
        val v = PolicyEngine.evaluate(p, listOf(rule("liveball.sx")),
            "https://other.com", true, usedSec = 600, sessionSec = 0)
        assertEquals("quota_spent", v.code)
    }

    @Test fun emptyUrlChecksSessionOnly() {
        assertEquals("session_ok",
            PolicyEngine.evaluate(Policy(), emptyList(), "", true, 0, 0).code)
    }

    @Test fun badUrlRejected() {
        assertEquals("bad_url",
            PolicyEngine.evaluate(Policy(), emptyList(), "javascript:alert(1)", true, 0, 0).code)
    }
}
