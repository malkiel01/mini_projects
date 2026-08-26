package com.mbeplus.guardedbrowser

import org.json.JSONObject
import java.util.Calendar
import java.util.TimeZone

/**
 * אכיפה מקומית — תאום של lib/policy.php בצד המכשיר.
 *
 * למה בכלל כפילות: כל לחיצה על קישור בתוך דף היא הכרעה, ופנייה לשרת
 * על כל אחת מהן הייתה הופכת גלישה לבלתי שמישה. לכן האפליקציה אוכפת
 * מיד לפי המדיניות שקיבלה, והשרת אוכף שוב בכל רענון ופעימה.
 *
 * ‏ההכרעה כאן אינה מקור הסמכות. לקוח שנפרץ יכול להיתקע על מדיניות
 * ישנה, לא להמציא לעצמו חדשה — כי גם ההיתר וגם רשימת הכללים מגיעים
 * מהשרת, ופעימה שנדחית סוגרת את הדפדפן.
 */

data class Rule(
    val label: String,
    val pattern: String,
    val scope: String,      // exact | domain | domain_plus
    val action: String,     // allow | deny
    val showTile: Boolean,
) {
    companion object {
        fun from(o: JSONObject) = Rule(
            label = o.optString("label"),
            pattern = o.optString("pattern"),
            scope = o.optString("scope", "domain"),
            action = o.optString("action", "allow"),
            showTile = o.optBoolean("show_tile", true),
        )
    }
}

data class Policy(
    val mode: String = MODE_KIOSK,
    val timezone: String = "Asia/Jerusalem",
    val daysMask: Int = 127,
    val windowStart: String = "",
    val windowEnd: String = "",
    val dailyQuotaMin: Int = 0,
    val sessionMaxMin: Int = 0,
    val allowDownloads: Boolean = false,
    val blockScreenshots: Boolean = false,
    val keepHistory: Boolean = true,
) {
    companion object {
        const val MODE_KIOSK = "kiosk"
        const val MODE_ALLOWLIST = "allowlist"
        const val MODE_FREE = "free"

        fun from(o: JSONObject) = Policy(
            mode = o.optString("mode", MODE_KIOSK),
            timezone = o.optString("timezone", "Asia/Jerusalem"),
            daysMask = o.optInt("days_mask", 127),
            windowStart = o.optString("window_start"),
            windowEnd = o.optString("window_end"),
            dailyQuotaMin = o.optInt("daily_quota_min"),
            sessionMaxMin = o.optInt("session_max_min"),
            allowDownloads = o.optBoolean("allow_downloads"),
            blockScreenshots = o.optBoolean("block_screenshots"),
            keepHistory = o.optBoolean("keep_history", true),
        )
    }
}

data class Verdict(val allow: Boolean, val code: String, val reason: String = "")

object PolicyEngine {

    /** כתובת מפורקת לצורה שאפשר להשוות. null = כתובת שאין להתיר בשום מצב. */
    data class Url(val host: String, val path: String, val query: String)

    /**
     * מנרמל בדיוק כמו normalizeUrl ב-PHP: www מוסר, אותיות מוקטנות,
     * סלאש בסוף מושמט. הבדל בין הצדדים כאן היה מייצר כתובת שהאפליקציה
     * מתירה והשרת חוסם, או להפך.
     */
    fun normalize(raw: String): Url? {
        var s = raw.trim()
        if (s.isEmpty()) return null

        if (!Regex("^[a-zA-Z][a-zA-Z0-9+.\\-]*:").containsMatchIn(s)) s = "https://$s"

        val uri = try { java.net.URI(s) } catch (e: Exception) { return null }
        val scheme = uri.scheme?.lowercase() ?: return null
        if (scheme != "http" && scheme != "https") return null

        var host = uri.host?.lowercase() ?: return null
        if (host.isEmpty()) return null
        if (host.startsWith("www.")) host = host.substring(4)

        var path = uri.rawPath ?: "/"
        if (path.isEmpty()) path = "/"
        if (path != "/") path = path.trimEnd('/')

        return Url(host, path, uri.rawQuery ?: "")
    }

    /**
     * הנקודה המפרידה חיונית: בלעדיה "nota.com" היה נחשב תת-דומיין של
     * "a.com", וכל מי שרושם דומיין כזה עוקף את הכלל.
     */
    fun hostMatches(host: String, domain: String): Boolean =
        host == domain || host.endsWith(".$domain")

    fun ruleMatches(rule: Rule, url: Url, isMainFrame: Boolean): Boolean {
        val pattern = normalize(rule.pattern) ?: return false

        // משאב נלווה תחת domain_plus מותר מכל מקור: דף לגיטימי טוען
        // נגן, גופן ותמונה מדומיינים אחרים, וחסימתם שוברת את הדף.
        if (rule.scope == "domain_plus" && !isMainFrame) return true

        if (!hostMatches(url.host, pattern.host)) return false

        if (rule.scope == "exact") {
            if (url.path != pattern.path) return false
            if (pattern.query.isNotEmpty() && url.query != pattern.query) return false
        }
        return true
    }

    /** ‏deny גובר על allow תמיד, בלי קשר לסדר. */
    fun matchRules(rules: List<Rule>, mode: String, url: Url, isMainFrame: Boolean): Verdict {
        var allowHit = false

        for (r in rules) {
            if (!ruleMatches(r, url, isMainFrame)) continue
            if (r.action == "deny") {
                val extra = if (r.label.isNotEmpty()) " (${r.label})" else ""
                return Verdict(false, "rule_deny", "הכתובת הזו נחסמה עבורך$extra")
            }
            allowHit = true
        }

        if (allowHit) return Verdict(true, "rule_allow")
        if (mode == Policy.MODE_FREE) return Verdict(true, "free_mode")

        return Verdict(false, "not_listed",
            if (mode == Policy.MODE_KIOSK) "הכתובת הזו אינה ברשימה שהוגדרה לך"
            else "הכתובת הזו אינה ברשימת ההיתר שלך")
    }

    /**
     * חלון הזמן היומי. חלון שההתחלה בו מאוחרת מהסיום חוצה חצות, ואז
     * התנאי מתהפך — זו הטעות הקלה ביותר לעשות כאן.
     */
    fun withinWindow(p: Policy, now: Calendar): Verdict {
        val dayBit = 1 shl (now.get(Calendar.DAY_OF_WEEK) - 1)   // ראשון=1 באנדרואיד
        if (p.daysMask != 0 && (p.daysMask and dayBit) == 0) {
            return Verdict(false, "day_blocked", "היום אינו מהימים שהוגדרו לך")
        }
        if (p.windowStart.isEmpty() || p.windowEnd.isEmpty()) return Verdict(true, "no_window")

        val hhmm = String.format("%02d:%02d",
            now.get(Calendar.HOUR_OF_DAY), now.get(Calendar.MINUTE))

        val inside = if (p.windowStart <= p.windowEnd)
            hhmm >= p.windowStart && hhmm < p.windowEnd
        else
            hhmm >= p.windowStart || hhmm < p.windowEnd

        return if (inside) Verdict(true, "in_window")
        else Verdict(false, "outside_window",
            "הגישה מותרת לך בין ${p.windowStart} ל-${p.windowEnd}")
    }

    fun withinQuota(p: Policy, usedSec: Int): Verdict {
        if (p.dailyQuotaMin <= 0) return Verdict(true, "no_quota")
        return if (usedSec >= p.dailyQuotaMin * 60)
            Verdict(false, "quota_spent", "מכסת הצפייה היומית שלך (${p.dailyQuotaMin} דקות) נוצלה")
        else Verdict(true, "quota_left")
    }

    fun withinSession(p: Policy, sessionSec: Int): Verdict {
        if (p.sessionMaxMin <= 0) return Verdict(true, "no_session_cap")
        return if (sessionSec >= p.sessionMaxMin * 60)
            Verdict(false, "session_over", "משך הישיבה המותר (${p.sessionMaxMin} דקות) הסתיים")
        else Verdict(true, "session_left")
    }

    /**
     * ההכרעה השלמה, באותו סדר כמו בשרת: מהכללי לפרטני, כדי שהסיבה
     * שתוצג תהיה השורשית. "הכתובת אינה ברשימה" למשתמש שמכסתו נגמרה
     * היא תשובה נכונה טכנית ומטעה לחלוטין.
     */
    fun evaluate(
        p: Policy, rules: List<Rule>, url: String,
        isMainFrame: Boolean, usedSec: Int, sessionSec: Int,
    ): Verdict {
        val now = Calendar.getInstance(TimeZone.getTimeZone(p.timezone))

        withinWindow(p, now).let { if (!it.allow) return it }
        withinQuota(p, usedSec).let { if (!it.allow) return it }
        withinSession(p, sessionSec).let { if (!it.allow) return it }

        if (url.isEmpty()) return Verdict(true, "session_ok")

        val u = normalize(url) ?: return Verdict(false, "bad_url", "הכתובת אינה תקינה")
        return matchRules(rules, p.mode, u, isMainFrame)
    }
}
