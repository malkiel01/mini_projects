package com.mbeplus.guardedbrowser

import org.json.JSONArray
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
 * ישנה, לא להמציא לעצמו חדשה.
 *
 * ‏חריג אחד מכוון: "לאיזה ערוץ שייך הסרטון" אינו ניתן לדעת במכשיר,
 * כי התשובה מגיעה מיוטיוב. במקרה הזה ההכרעה נדחית לשרת (NEEDS_SERVER)
 * במקום להמציא תשובה — היתר מתוך ניחוש אינו היתר.
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
    val posture: String = POSTURE_DENY,
    val blockedTypes: List<String> = emptyList(),
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
        const val MODE_BROWSER = "browser"
        const val POSTURE_DENY = "deny_all"
        const val POSTURE_ALLOW = "allow_all"

        fun from(o: JSONObject) = Policy(
            mode = o.optString("mode", MODE_KIOSK),
            posture = o.optString("posture", POSTURE_DENY),
            blockedTypes = o.optString("blocked_types").split(",")
                .map { it.trim() }.filter { it.isNotEmpty() },
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

/**
 * אריח במסך הפתיחה.
 *
 * נפרד מ-Rule בכוונה: Rule הוא כלל אכיפה, ואריח הוא רק קיצור דרך.
 * ערוץ יוטיוב מאושר הוא אריח בלי שיהיה כלל כתובת — ואילו ערבבנו
 * ביניהם, הוא היה הופך להיתר גורף לכל youtube.com.
 */
data class Tile(val label: String, val url: String, val kind: String) {
    companion object {
        fun from(o: JSONObject) = Tile(
            label = o.optString("label"),
            url = o.optString("url"),
            kind = o.optString("kind", "url"),
        )
    }
}

/** הגדרות פלטפורמה (כרגע יוטיוב) והפריטים שאושרו בה. */
data class PlatformRule(
    val mode: String = "off",           // off | restricted | full
    val allowSearch: Boolean = false,
    val allowShorts: Boolean = false,
) {
    companion object {
        fun from(o: JSONObject) = PlatformRule(
            mode = o.optString("mode", "off"),
            allowSearch = o.optBoolean("allow_search"),
            allowShorts = o.optBoolean("allow_shorts"),
        )
    }
}

/** כל מה שהמנוע צריך על משתמש, במקום אחד — כמו ruleSetFor בשרת. */
data class RuleSet(
    val rules: List<Rule> = emptyList(),
    val categories: Map<String, String> = emptyMap(),
    val domainMap: Map<String, List<String>> = emptyMap(),
    val platforms: Map<String, PlatformRule> = emptyMap(),
    val platformItems: Map<String, Map<String, Map<String, String>>> = emptyMap(),
)

data class Verdict(
    val allow: Boolean,
    val code: String,
    val reason: String = "",
) {
    /** ההכרעה אינה אפשרית במכשיר; יש לשאול את השרת. */
    val needsServer: Boolean get() = code == "needs_server"
}

object PolicyEngine {

    const val PLATFORM_YOUTUBE = "youtube"

    data class Url(val host: String, val path: String, val query: String, val full: String)

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

        return Url(host, path, uri.rawQuery ?: "", s)
    }

    /**
     * הנקודה המפרידה חיונית: בלעדיה "nota.com" היה נחשב תת-דומיין של
     * "a.com", וכל מי שרושם דומיין כזה עוקף את הכלל.
     */
    fun hostMatches(host: String, domain: String): Boolean =
        host == domain || host.endsWith(".$domain")

    /* ── כללי כתובות ────────────────────────────────────────────── */

    fun ruleMatches(rule: Rule, url: Url, isMainFrame: Boolean): Boolean {
        val pattern = normalize(rule.pattern) ?: return false
        if (rule.scope == "domain_plus" && !isMainFrame) return true
        if (!hostMatches(url.host, pattern.host)) return false

        if (rule.scope == "exact") {
            if (url.path != pattern.path) return false
            if (pattern.query.isNotEmpty() && url.query != pattern.query) return false
        }
        return true
    }

    /** מחזיר "deny" / "allow" / "". ‏deny גובר תמיד, בלי קשר לסדר. */
    fun matchUrlRules(rules: List<Rule>, url: Url, isMainFrame: Boolean): String {
        var allowHit = false
        for (r in rules) {
            if (!ruleMatches(r, url, isMainFrame)) continue
            if (r.action == "deny") return "deny"
            allowHit = true
        }
        return if (allowHit) "allow" else ""
    }

    /* ── קטגוריות ───────────────────────────────────────────────── */

    fun categoriesOfHost(host: String, map: Map<String, List<String>>): List<String> =
        map.filterKeys { hostMatches(host, it) }.values.flatten().distinct()

    /** ‏deny גובר: אתר שהוא גם ספורט וגם הימורים נחסם. */
    fun matchCategories(host: String, map: Map<String, List<String>>,
                        rules: Map<String, String>): Pair<String, String> {
        var allowHit = ""
        for (c in categoriesOfHost(host, map)) {
            when (rules[c]) {
                "deny" -> return "deny" to c
                "allow" -> if (allowHit.isEmpty()) allowHit = c
            }
        }
        return if (allowHit.isNotEmpty()) "allow" to allowHit else "" to ""
    }

    /* ── סוגי תוכן ──────────────────────────────────────────────── */

    private val TYPE_EXT = mapOf(
        "video" to listOf("mp4","m4v","mov","avi","mkv","webm","flv","m3u8","mpd","ts"),
        "audio" to listOf("mp3","m4a","aac","ogg","opus","wav","flac"),
        "image" to listOf("jpg","jpeg","png","gif","webp","bmp","svg","avif"),
        "document" to listOf("pdf","doc","docx","xls","xlsx","ppt","pptx","odt"),
        "archive" to listOf("zip","rar","7z","tar","gz","bz2"),
        "executable" to listOf("apk","exe","msi","dmg","deb","rpm","bat","sh"),
    )
    private val TYPE_MIME = mapOf(
        "video" to listOf("video/", "application/vnd.apple.mpegurl", "application/x-mpegurl",
                          "application/dash+xml"),
        "audio" to listOf("audio/"),
        "image" to listOf("image/"),
        "document" to listOf("application/pdf", "application/msword",
                             "application/vnd.openxmlformats-officedocument"),
        "archive" to listOf("application/zip","application/x-rar","application/x-7z",
                            "application/gzip","application/x-tar"),
        "executable" to listOf("application/vnd.android.package-archive",
                               "application/x-msdownload","application/x-apple-diskimage"),
    )

    val contentTypeLabels = mapOf(
        "video" to "ווידאו", "audio" to "שמע", "image" to "תמונות",
        "document" to "מסמכים", "archive" to "קבצי ארכיון", "executable" to "קבצי התקנה",
    )

    /**
     * מחרוזת השאילתה נחתכת לפני בדיקת הסיומת: "movie.mp4?token=abc"
     * הוא ווידאו, והתעלמות מכך הייתה מחמיצה בדיוק את הכתובות שנועדו
     * להסתיר את עצמן.
     */
    fun contentTypeOf(url: String, declared: String = ""): String {
        val mime = declared.substringBefore(';').trim().lowercase()
        if (mime.isNotEmpty()) {
            for ((key, prefixes) in TYPE_MIME) {
                if (prefixes.any { mime.startsWith(it) }) return key
            }
        }
        val path = normalize(url)?.path ?: return ""
        val ext = path.substringAfterLast('.', "").lowercase()
        if (ext.isEmpty() || ext.length > 5) return ""
        return TYPE_EXT.entries.firstOrNull { ext in it.value }?.key ?: ""
    }

    /* ── יוטיוב ─────────────────────────────────────────────────── */

    fun platformOf(host: String): String {
        val h = host.removePrefix("www.")
        for (d in listOf("youtube.com", "youtu.be", "youtube-nocookie.com",
                         "m.youtube.com", "music.youtube.com")) {
            if (h == d || h.endsWith(".$d")) return PLATFORM_YOUTUBE
        }
        return ""
    }

    data class YtRef(val kind: String, val id: String)

    fun parseYouTube(raw: String): YtRef {
        val u = try { java.net.URI(raw) } catch (e: Exception) { return YtRef("other", "") }
        val host = (u.host ?: "").lowercase().removePrefix("www.")
        val path = (u.rawPath ?: "/").trimEnd('/').ifEmpty { "/" }
        val query = (u.rawQuery ?: "").split("&")
            .mapNotNull { val p = it.split("=", limit = 2); if (p.size == 2) p[0] to p[1] else null }
            .toMap()

        if (host == "youtu.be") {
            val id = path.trimStart('/')
            return if (id.isNotEmpty()) YtRef("video", id) else YtRef("home", "")
        }
        if (path == "/watch" && !query["v"].isNullOrEmpty()) return YtRef("video", query["v"]!!)
        Regex("^/(?:embed|v)/([A-Za-z0-9_-]{6,})").find(path)?.let { return YtRef("video", it.groupValues[1]) }
        Regex("^/shorts/([A-Za-z0-9_-]{6,})").find(path)?.let { return YtRef("shorts", it.groupValues[1]) }
        /*
         * חיפוש בתוך דף ערוץ — /@name/search. חייב להיבדק לפני
         * תבניות הערוץ, אחרת הוא נקרא "ערוץ מאושר" ועובר; יוטיוב
         * מציג שם גם תוצאות מערוצים אחרים.
         */
        if (path.endsWith("/search")) return YtRef("search", "")
        Regex("^/channel/(UC[A-Za-z0-9_-]{10,})").find(path)?.let { return YtRef("channel", it.groupValues[1]) }
        Regex("^/@([A-Za-z0-9._-]+)").find(path)?.let { return YtRef("handle", it.groupValues[1].lowercase()) }
        Regex("^/(?:c|user)/([A-Za-z0-9._-]+)").find(path)?.let { return YtRef("handle", it.groupValues[1].lowercase()) }
        if (path == "/playlist" && !query["list"].isNullOrEmpty()) return YtRef("playlist", query["list"]!!)
        if (path == "/results" || path == "/search") return YtRef("search", "")
        if (path == "/" || path.startsWith("/feed")) return YtRef("home", "")
        return YtRef("other", "")
    }

    /**
     * נקודות הקצה שמזינות את החיפוש.
     *
     * חסימת /results לבדה אינה מספיקה: התוצאות וההצעות מגיעות
     * בבקשות רקע, ובלי לחסום אותן המשתמש רואה תוצאות ותמונות
     * ממוזערות גם כשהניווט אליהן ייחסם.
     */
    fun isYouTubeSearchEndpoint(path: String): Boolean =
        listOf("/youtubei/v1/search", "/complete/search", "/search_ajax",
               "/youtubei/v1/get_search_suggestions").any { path.startsWith(it) }

    /**
     * משאבי הנגן אינם ניווט. בלי המעבר הזה, מצב מוגבל היה חוסם את
     * זרם הווידאו של הסרטון שכן אושר, והמשתמש היה רואה מסך שחור.
     */
    fun isYouTubeAsset(url: String): Boolean {
        val host = (normalize(url)?.host ?: "").lowercase()
        if (listOf("googlevideo.com", "ytimg.com", "ggpht.com", "gstatic.com")
                .any { host.contains(it) }) return true
        val path = normalize(url)?.path ?: return false
        return Regex("^/(?:s/|yts/|youtubei/|api/|generate_204|videoplayback)").containsMatchIn(path)
    }

    fun youTubeVerdict(url: Url, rule: PlatformRule,
                       items: Map<String, Map<String, String>>, isMainFrame: Boolean): Verdict {
        if (rule.mode == "off") return Verdict(false, "yt_off", "יוטיוב חסום בחשבון שלך")

        if (!isMainFrame) {
            /*
             * נקודת הקצה של החיפוש נחסמת בנפרד: יוטיוב הוא אתר
             * עמוד-יחיד, והחיפוש אינו ניווט אלא בקשת רקע שמחליפה
             * את תוכן הדף. חסימת /results לבדה אינה עוצרת אותו.
             */
            if (rule.mode == "restricted" && !rule.allowSearch &&
                isYouTubeSearchEndpoint(url.path)) {
                return Verdict(false, "yt_no_search", "החיפוש ביוטיוב חסום עבורך")
            }
            if (isYouTubeAsset(url.full)) return Verdict(true, "yt_asset")
        }

        val ref = parseYouTube(url.full)
        fun action(kind: String, id: String) = items[kind]?.get(id) ?: ""

        if (ref.id.isNotEmpty()) {
            val direct = action(if (ref.kind == "shorts") "video" else ref.kind, ref.id)
            if (direct == "deny") {
                return Verdict(false, "yt_item_denied", "הפריט הזה ביוטיוב חסום עבורך")
            }
        }
        if (rule.mode == "full") return Verdict(true, "yt_full")

        if (ref.kind == "search") {
            return if (rule.allowSearch) Verdict(true, "yt_search")
            else Verdict(false, "yt_no_search", "החיפוש ביוטיוב חסום עבורך")
        }
        if (ref.kind == "home" || ref.kind == "other") {
            return Verdict(false, "yt_no_browse",
                "אפשר לפתוח רק את הערוצים והסרטונים שאושרו לך, לא את יוטיוב עצמו")
        }
        if (ref.kind == "shorts" && !rule.allowShorts) {
            return Verdict(false, "yt_no_shorts", "סרטוני Shorts חסומים בחשבון שלך")
        }
        if (ref.kind in listOf("channel", "handle", "playlist")) {
            return if (action(ref.kind, ref.id) == "allow") Verdict(true, "yt_item_allowed")
            else Verdict(false, "yt_not_approved", "הערוץ הזה אינו ברשימה שאושרה לך")
        }
        if (ref.kind == "video" || ref.kind == "shorts") {
            if (action("video", ref.id) == "allow") return Verdict(true, "yt_video_allowed")
            /*
             * לאיזה ערוץ שייך הסרטון — זו שאלה שהתשובה לה נמצאת אצל
             * יוטיוב, לא במכשיר. במקום להמציא תשובה, ההכרעה נדחית
             * לשרת שיודע לשאול.
             */
            return Verdict(false, "needs_server", "בודק מול השרת…")
        }
        return Verdict(false, "yt_not_approved", "הכתובת הזו ביוטיוב אינה מאושרת")
    }

    /* ── תנאי זמן ───────────────────────────────────────────────── */

    fun withinWindow(p: Policy, now: Calendar): Verdict {
        val dayBit = 1 shl (now.get(Calendar.DAY_OF_WEEK) - 1)
        if (p.daysMask != 0 && (p.daysMask and dayBit) == 0) {
            return Verdict(false, "day_blocked", "היום אינו מהימים שהוגדרו לך")
        }
        if (p.windowStart.isEmpty() || p.windowEnd.isEmpty()) return Verdict(true, "no_window")

        val hhmm = String.format("%02d:%02d",
            now.get(Calendar.HOUR_OF_DAY), now.get(Calendar.MINUTE))
        val inside = if (p.windowStart <= p.windowEnd)
            hhmm >= p.windowStart && hhmm < p.windowEnd
        else hhmm >= p.windowStart || hhmm < p.windowEnd

        return if (inside) Verdict(true, "in_window")
        else Verdict(false, "outside_window", "הגישה מותרת לך בין ${p.windowStart} ל-${p.windowEnd}")
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

    /* ── ההכרעה השלמה ───────────────────────────────────────────── */

    /**
     * אותו סדר כמו בשרת, מהמפורש לכללי:
     * זמן → כלל כתובת → פלטפורמה → סוג תוכן → קטגוריה → ברירת מחדל.
     *
     * הסדר אינו קוסמטי: כלל שהמנהל כתב במפורש חייב לגבור על סיווג
     * אוטומטי, ואיסור חייב לגבור על היתר. צד שמשנה את הסדר מקבל
     * הכרעות שונות מהשרת, וזו בדיוק הפרצה.
     */
    fun evaluate(p: Policy, set: RuleSet, url: String, isMainFrame: Boolean,
                 usedSec: Int, sessionSec: Int, declaredType: String = ""): Verdict {
        val now = Calendar.getInstance(TimeZone.getTimeZone(p.timezone))

        withinWindow(p, now).let { if (!it.allow) return it }
        withinQuota(p, usedSec).let { if (!it.allow) return it }
        withinSession(p, sessionSec).let { if (!it.allow) return it }

        if (url.isEmpty()) return Verdict(true, "session_ok")
        val u = normalize(url) ?: return Verdict(false, "bad_url", "הכתובת אינה תקינה")

        when (matchUrlRules(set.rules, u, isMainFrame)) {
            "deny" -> return Verdict(false, "rule_deny", "הכתובת הזו נחסמה עבורך")
            "allow" -> return Verdict(true, "rule_allow")
        }

        val platform = platformOf(u.host)
        if (platform.isNotEmpty()) {
            set.platforms[platform]?.let {
                return youTubeVerdict(u, it, set.platformItems[platform] ?: emptyMap(), isMainFrame)
            }
        }

        if (p.blockedTypes.isNotEmpty()) {
            val t = contentTypeOf(u.full, declaredType)
            if (t.isNotEmpty() && t in p.blockedTypes) {
                val label = contentTypeLabels[t] ?: t
                return Verdict(false, "type_blocked", "תוכן מסוג \"$label\" חסום בחשבון שלך")
            }
        }

        val (verdict, cat) = matchCategories(u.host, set.domainMap, set.categories)
        if (verdict == "deny") return Verdict(false, "category_deny", "הקטגוריה הזו ($cat) חסומה בחשבון שלך")
        if (verdict == "allow") return Verdict(true, "category_allow")

        return if (p.posture == Policy.POSTURE_ALLOW) Verdict(true, "posture_allow")
        else Verdict(false, "not_listed",
            if (p.mode == Policy.MODE_KIOSK) "הכתובת הזו אינה ברשימה שהוגדרה לך"
            else "הכתובת הזו אינה ברשימת ההיתר שלך")
    }

    /* ── פענוח מטען ה-JSON מהשרת ────────────────────────────────── */

    fun rulesFrom(arr: JSONArray?): List<Rule> =
        (0 until (arr?.length() ?: 0)).map { Rule.from(arr!!.getJSONObject(it)) }

    fun tilesFrom(arr: JSONArray?): List<Tile> =
        (0 until (arr?.length() ?: 0)).map { Tile.from(arr!!.getJSONObject(it)) }

    fun stringMap(o: JSONObject?): Map<String, String> {
        if (o == null) return emptyMap()
        return o.keys().asSequence().associateWith { o.optString(it) }
    }

    fun listMap(o: JSONObject?): Map<String, List<String>> {
        if (o == null) return emptyMap()
        return o.keys().asSequence().associateWith { k ->
            val a = o.optJSONArray(k) ?: JSONArray()
            (0 until a.length()).map { a.optString(it) }
        }
    }

    fun platformsFrom(o: JSONObject?): Map<String, PlatformRule> {
        if (o == null) return emptyMap()
        return o.keys().asSequence().associateWith { PlatformRule.from(o.getJSONObject(it)) }
    }

    fun itemsFrom(o: JSONObject?): Map<String, Map<String, Map<String, String>>> {
        if (o == null) return emptyMap()
        return o.keys().asSequence().associateWith { plat ->
            val kinds = o.getJSONObject(plat)
            kinds.keys().asSequence().associateWith { stringMap(kinds.getJSONObject(it)) }
        }
    }
}
