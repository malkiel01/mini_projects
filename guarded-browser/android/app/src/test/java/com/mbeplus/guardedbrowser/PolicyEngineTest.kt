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

    private fun at(dayOfWeek: Int, hour: Int, minute: Int): Calendar =
        Calendar.getInstance(TimeZone.getTimeZone("Asia/Jerusalem")).apply {
            set(Calendar.DAY_OF_WEEK, dayOfWeek)
            set(Calendar.HOUR_OF_DAY, hour)
            set(Calendar.MINUTE, minute)
        }

    private val open = Policy(posture = Policy.POSTURE_ALLOW)
    private val shut = Policy(posture = Policy.POSTURE_DENY)

    /* ── נרמול ── */

    @Test fun normalizeMatchesServer() {
        assertEquals("example.com", PolicyEngine.normalize("example.com")?.host)
        assertEquals("example.com", PolicyEngine.normalize("https://www.example.com")?.host)
        assertEquals("example.com", PolicyEngine.normalize("HTTPS://Example.COM/A")?.host)
        assertEquals("/x", PolicyEngine.normalize("https://a.com/x/")?.path)
        assertEquals("/", PolicyEngine.normalize("https://a.com")?.path)
        assertEquals(null, PolicyEngine.normalize("file:///etc/passwd"))
        assertEquals(null, PolicyEngine.normalize("javascript:alert(1)"))
    }

    /**
     * ‏"nota.com" נגמר ב-"a.com" כמחרוזת. בלי הנקודה המפרידה, כל מי
     * שרושם דומיין כזה עוקף כלל שמתיר a.com.
     */
    @Test fun lookalikeDomainIsNotSubdomain() {
        assertTrue(PolicyEngine.hostMatches("cdn.a.com", "a.com"))
        assertFalse(PolicyEngine.hostMatches("nota.com", "a.com"))
    }

    /* ── כללי כתובות ── */

    @Test fun scopesBehaveLikeServer() {
        val u = PolicyEngine.normalize("https://liveball.sx/team/772")!!
        assertTrue(PolicyEngine.ruleMatches(rule("liveball.sx/team/772", "exact"), u, true))
        assertFalse(PolicyEngine.ruleMatches(rule("liveball.sx/team/999", "exact"), u, true))
        assertTrue(PolicyEngine.ruleMatches(rule("liveball.sx"), u, true))

        val foreign = PolicyEngine.normalize("https://cdn-player.net/p.js")!!
        assertTrue(PolicyEngine.ruleMatches(rule("liveball.sx", "domain_plus"), foreign, false))
        assertFalse(PolicyEngine.ruleMatches(rule("liveball.sx", "domain_plus"), foreign, true))
        assertFalse(PolicyEngine.ruleMatches(rule("liveball.sx", "domain"), foreign, false))
    }

    /** איסור שאפשר לנטרל בהוספת היתר אחריו אינו איסור. */
    @Test fun denyWinsRegardlessOfOrder() {
        val u = PolicyEngine.normalize("https://bad.com")!!
        val before = listOf(rule("bad.com", action = "deny"), rule("bad.com"))
        assertEquals("deny", PolicyEngine.matchUrlRules(before, u, true))
        assertEquals("deny", PolicyEngine.matchUrlRules(before.reversed(), u, true))
    }

    /* ── ברירת מחדל ── */

    @Test fun postureIsIndependentOfMode() {
        val set = RuleSet()
        assertEquals("not_listed",
            PolicyEngine.evaluate(shut, set, "https://x.com", true, 0, 0).code)
        assertEquals("posture_allow",
            PolicyEngine.evaluate(open, set, "https://x.com", true, 0, 0).code)
        // קיוסק פתוח ודפדפן סגור — שני הצירופים שהמודל הישן לא ידע להביע.
        assertTrue(PolicyEngine.evaluate(
            open.copy(mode = Policy.MODE_KIOSK), set, "https://x.com", true, 0, 0).allow)
        assertFalse(PolicyEngine.evaluate(
            shut.copy(mode = Policy.MODE_BROWSER), set, "https://x.com", true, 0, 0).allow)
    }

    /* ── קטגוריות ── */

    private val map = mapOf(
        "youtube.com" to listOf("video"),
        "bet365.com" to listOf("gambling"),
        "winner.co.il" to listOf("sports", "gambling"),
        "khanacademy.org" to listOf("education"),
    )

    @Test fun subdomainInheritsCategory() {
        assertEquals(listOf("video"), PolicyEngine.categoriesOfHost("m.youtube.com", map))
    }

    /** אתר בשתי קטגוריות — האוסרת מכריעה. */
    @Test fun denyingCategoryWins() {
        val rules = mapOf("sports" to "allow", "gambling" to "deny")
        assertEquals("deny", PolicyEngine.matchCategories("winner.co.il", map, rules).first)
    }

    @Test fun categoriesDecideWhenNoUrlRule() {
        val set = RuleSet(domainMap = map,
            categories = mapOf("gambling" to "deny", "education" to "allow"))
        assertEquals("category_deny",
            PolicyEngine.evaluate(open, set, "https://bet365.com", true, 0, 0).code)
        assertEquals("category_allow",
            PolicyEngine.evaluate(shut, set, "https://khanacademy.org", true, 0, 0).code)
    }

    /** כלל שהמנהל כתב בידיים חייב לגבור על סיווג אוטומטי מהקטלוג. */
    @Test fun explicitRuleBeatsCategory() {
        val set = RuleSet(rules = listOf(rule("bet365.com")), domainMap = map,
            categories = mapOf("gambling" to "deny"))
        assertEquals("rule_allow",
            PolicyEngine.evaluate(open, set, "https://bet365.com", true, 0, 0).code)
    }

    /* ── סוגי תוכן ── */

    @Test fun contentTypeDetection() {
        assertEquals("video", PolicyEngine.contentTypeOf("https://a.com/clip.mp4"))
        assertEquals("video", PolicyEngine.contentTypeOf("https://a.com/clip.mp4?t=1"))
        assertEquals("video", PolicyEngine.contentTypeOf("https://a.com/x", "video/mp4"))
        assertEquals("video", PolicyEngine.contentTypeOf("https://a.com/s.m3u8"))
        assertEquals("executable", PolicyEngine.contentTypeOf("https://a.com/app.apk"))
        assertEquals("", PolicyEngine.contentTypeOf("https://a.com/page"))
    }

    @Test fun blockedTypeStopsResourceButExplicitRuleWins() {
        val p = open.copy(blockedTypes = listOf("video"))
        assertEquals("type_blocked",
            PolicyEngine.evaluate(p, RuleSet(), "https://a.com/clip.mp4", false, 0, 0).code)
        assertTrue(PolicyEngine.evaluate(p, RuleSet(), "https://a.com/page", true, 0, 0).allow)
        assertEquals("rule_allow", PolicyEngine.evaluate(
            p, RuleSet(rules = listOf(rule("a.com"))), "https://a.com/clip.mp4", false, 0, 0).code)
    }

    /* ── יוטיוב ── */

    @Test fun youTubeUrlParsing() {
        assertEquals(PolicyEngine.YtRef("video", "abc123XYZ"),
            PolicyEngine.parseYouTube("https://www.youtube.com/watch?v=abc123XYZ"))
        assertEquals(PolicyEngine.YtRef("video", "abc123XYZ"),
            PolicyEngine.parseYouTube("https://youtu.be/abc123XYZ"))
        assertEquals("video", PolicyEngine.parseYouTube("https://youtube.com/embed/abc123XYZ").kind)
        assertEquals("shorts", PolicyEngine.parseYouTube("https://youtube.com/shorts/abc123XYZ").kind)
        assertEquals("UCabcdefghijk",
            PolicyEngine.parseYouTube("https://youtube.com/channel/UCabcdefghijk").id)
        assertEquals("hasratim", PolicyEngine.parseYouTube("https://youtube.com/@HaSratim").id)
        assertEquals("search", PolicyEngine.parseYouTube("https://youtube.com/results?search_query=x").kind)
        assertEquals("home", PolicyEngine.parseYouTube("https://youtube.com/").kind)
        assertEquals(PolicyEngine.PLATFORM_YOUTUBE, PolicyEngine.platformOf("m.youtube.com"))
        assertEquals("", PolicyEngine.platformOf("vimeo.com"))
    }

    private val ytItems = mapOf(
        "channel" to mapOf("UCgoodChannel1234567890" to "allow",
                           "UCbadChannel12345678901" to "deny"),
        "video" to mapOf("vid_ok" to "allow"),
        "handle" to mapOf("torah" to "allow"),
    )
    private fun yt(u: String) = PolicyEngine.normalize(u)!!

    @Test fun youTubeModes() {
        assertEquals("yt_off", PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/watch?v=vid_ok"), PlatformRule("off"), ytItems, true).code)
        assertTrue(PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/"), PlatformRule("full"), ytItems, true).allow)
        // איסור מפורש על פריט גובר גם כשהכול פתוח.
        assertEquals("yt_item_denied", PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/channel/UCbadChannel12345678901"),
            PlatformRule("full"), ytItems, true).code)
    }

    @Test fun youTubeRestricted() {
        val r = PlatformRule("restricted")
        assertEquals("yt_no_browse",
            PolicyEngine.youTubeVerdict(yt("https://youtube.com/"), r, ytItems, true).code)
        assertEquals("yt_no_search", PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/results?search_query=x"), r, ytItems, true).code)
        assertTrue(PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/results?search_query=x"),
            PlatformRule("restricted", allowSearch = true), ytItems, true).allow)
        assertTrue(PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/channel/UCgoodChannel1234567890"), r, ytItems, true).allow)
        assertEquals("yt_not_approved", PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/channel/UCnopeChannel123456789"), r, ytItems, true).code)
        assertTrue(PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/@Torah"), r, ytItems, true).allow)
        assertTrue(PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/watch?v=vid_ok"), r, ytItems, true).allow)
        assertEquals("yt_no_shorts", PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/shorts/vid_ok"), r, ytItems, true).code)
    }

    /**
     * ההבדל המכוון היחיד מהשרת: לאיזה ערוץ שייך סרטון הוא ידע שיושב
     * ביוטיוב, לא במכשיר. במקום לנחש — נדחה לשרת.
     */
    @Test fun unknownVideoDefersToServerInsteadOfGuessing() {
        val v = PolicyEngine.youTubeVerdict(
            yt("https://youtube.com/watch?v=unknownVid"), PlatformRule("restricted"), ytItems, true)
        assertFalse(v.allow)
        assertTrue(v.needsServer)
    }


    /**
     * יוטיוב הוא אתר עמוד-יחיד: החיפוש אינו ניווט אלא בקשת רקע
     * שמחליפה את תוכן הדף. חסימת /results לבדה אינה עוצרת אותו.
     */
    @Test fun searchEndpointIsBlockedButPlayerKeepsWorking() {
        val r = PlatformRule("restricted", allowSearch = false)
        assertEquals("yt_no_search", PolicyEngine.youTubeVerdict(
            yt("https://www.youtube.com/youtubei/v1/search?key=x"), r, ytItems, false).code)
        assertEquals("yt_asset", PolicyEngine.youTubeVerdict(
            yt("https://www.youtube.com/youtubei/v1/player?key=x"), r, ytItems, false).code)
        assertEquals("yt_asset", PolicyEngine.youTubeVerdict(
            yt("https://www.youtube.com/youtubei/v1/browse?key=x"), r, ytItems, false).code)
        assertTrue(PolicyEngine.youTubeVerdict(
            yt("https://www.youtube.com/youtubei/v1/search?key=x"),
            PlatformRule("restricted", allowSearch = true), ytItems, false).allow)
    }


    /**
     * חיפוש בתוך דף ערוץ — /@name/search — נקרא קודם "ערוץ מאושר"
     * ועבר, בזמן שיוטיוב מציג שם גם תוצאות מערוצים אחרים.
     */
    @Test fun channelPageSearchCountsAsSearch() {
        assertEquals("search",
            PolicyEngine.parseYouTube("https://www.youtube.com/@Mercaz/search?query=x").kind)
        assertEquals("handle",
            PolicyEngine.parseYouTube("https://www.youtube.com/@Mercaz").kind)
        assertEquals("handle",
            PolicyEngine.parseYouTube("https://www.youtube.com/@Mercaz/videos").kind)
        assertEquals("yt_no_search", PolicyEngine.youTubeVerdict(
            yt("https://www.youtube.com/@Torah/search?query=x"),
            PlatformRule("restricted"), ytItems, true).code)
    }

    @Test fun searchFeedsAreBlockedAtRequestLevel() {
        assertTrue(PolicyEngine.isYouTubeSearchEndpoint("/youtubei/v1/search"))
        assertTrue(PolicyEngine.isYouTubeSearchEndpoint("/complete/search"))
        assertFalse(PolicyEngine.isYouTubeSearchEndpoint("/youtubei/v1/player"))
        assertFalse(PolicyEngine.isYouTubeSearchEndpoint("/youtubei/v1/browse"))
    }

    /** בלי זה, סרטון מאושר היה מוצג כמסך שחור. */
    @Test fun playerAssetsPassThrough() {
        assertEquals("yt_asset", PolicyEngine.youTubeVerdict(
            yt("https://rr3---sn-x.googlevideo.com/videoplayback?x=1"),
            PlatformRule("restricted"), ytItems, false).code)
    }

    @Test fun youTubeStaysRestrictedEvenWhenEverythingElseIsOpen() {
        val set = RuleSet(platforms = mapOf(PolicyEngine.PLATFORM_YOUTUBE to PlatformRule("restricted")),
                          platformItems = mapOf(PolicyEngine.PLATFORM_YOUTUBE to ytItems))
        assertEquals("yt_no_browse",
            PolicyEngine.evaluate(open, set, "https://youtube.com/", true, 0, 0).code)
    }

    /* ── זמן ── */

    @Test fun windowBoundaries() {
        val p = Policy(windowStart = "16:00", windowEnd = "22:00")
        assertTrue(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 18, 0)).allow)
        assertFalse(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 9, 0)).allow)
        assertTrue(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 16, 0)).allow)
        assertFalse(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 22, 0)).allow)
    }

    /** חלון שחוצה חצות מהפך את התנאי — הטעות הקלה ביותר כאן. */
    @Test fun windowCrossingMidnight() {
        val p = Policy(windowStart = "22:00", windowEnd = "02:00")
        assertTrue(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 23, 0)).allow)
        assertTrue(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 1, 0)).allow)
        assertFalse(PolicyEngine.withinWindow(p, at(Calendar.WEDNESDAY, 12, 0)).allow)
    }

    @Test fun dayMaskAndQuotas() {
        assertEquals("day_blocked",
            PolicyEngine.withinWindow(Policy(daysMask = 1), at(Calendar.MONDAY, 12, 0)).code)
        assertEquals("quota_spent", PolicyEngine.withinQuota(Policy(dailyQuotaMin = 120), 7200).code)
        assertEquals("session_over", PolicyEngine.withinSession(Policy(sessionMaxMin = 30), 1800).code)
    }

    /**
     * הסיבה שמוצגת חייבת להיות השורשית. "אינה ברשימה" למשתמש שמכסתו
     * נגמרה שולח אותו לבקש כלל נוסף במקום לומר לו לחזור מחר.
     */
    @Test fun rootCauseWinsOverListCheck() {
        val p = shut.copy(dailyQuotaMin = 10)
        assertEquals("quota_spent", PolicyEngine.evaluate(
            p, RuleSet(rules = listOf(rule("liveball.sx"))),
            "https://other.com", true, 600, 0).code)
    }

    @Test fun emptyUrlChecksSessionOnly() {
        assertEquals("session_ok", PolicyEngine.evaluate(shut, RuleSet(), "", true, 0, 0).code)
        assertEquals("bad_url",
            PolicyEngine.evaluate(shut, RuleSet(), "javascript:alert(1)", true, 0, 0).code)
    }
}
