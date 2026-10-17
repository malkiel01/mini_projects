package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** בקש גישה (שלב 6): מתי מוצע הכפתור, והיתר "לשעה" שפג — כמו tests/requests.php. */
class AccessRequestTest {

    @Test fun askOnlyWhenTheSiteIsTheReason() {
        assertTrue(AccessRequest.canAsk("not_listed", "https://he.wikipedia.org/wiki/x"))
        assertTrue(AccessRequest.canAsk("category_deny", "https://tiktok.com/"))
        assertTrue(AccessRequest.canAsk("yt_not_approved", "https://m.youtube.com/watch?v=abcdefghijk"))
        // הורה שחסם במפורש, זמן, ופרסומות — אין על מה לבקש
        assertFalse(AccessRequest.canAsk("rule_deny", "https://evil.com/"))
        assertFalse(AccessRequest.canAsk("quota_spent", "https://a.com/"))
        assertFalse(AccessRequest.canAsk("outside_window", "https://a.com/"))
        assertFalse(AccessRequest.canAsk("ad_blocked", "https://ads.com/"))
        // בלי כתובת אמיתית — אין כפתור
        assertFalse(AccessRequest.canAsk("not_listed", ""))
        assertFalse(AccessRequest.canAsk("not_listed", "about:blank"))
    }

    @Test fun hostForTheDialog() {
        assertEquals("nationalgeographic.com", AccessRequest.hostOf("https://www.nationalgeographic.com/animals"))
    }

    @Test fun expiredHourRuleIsGone() {
        val hour = Rule("", "scratch.mit.edu", "domain", "allow", false, "2026-10-17T21:00:00Z")
        val forever = Rule("", "khanacademy.org", "domain", "allow", true)
        assertEquals(listOf(hour, forever), PolicyEngine.liveRules(listOf(hour, forever), "2026-10-17T20:59:59Z"))
        assertEquals(listOf(forever), PolicyEngine.liveRules(listOf(hour, forever), "2026-10-17T21:00:00Z"))
        // ואחרי שפג — המנוע חוסם שוב
        val shut = Policy(posture = Policy.POSTURE_DENY)
        val set = RuleSet(rules = PolicyEngine.liveRules(listOf(hour), "2026-10-17T22:00:00Z"))
        assertFalse(PolicyEngine.evaluate(shut, set, "https://scratch.mit.edu/", true, 0, 0).allow)
    }

    @Test fun nowIsoMatchesServerFormat() {
        assertEquals("1970-01-01T00:00:00Z", PolicyEngine.nowIso(0))
        assertTrue(Regex("""^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$""").matches(PolicyEngine.nowIso()))
    }
}
