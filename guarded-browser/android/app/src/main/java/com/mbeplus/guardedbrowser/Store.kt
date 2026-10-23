package com.mbeplus.guardedbrowser

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONArray
import org.json.JSONObject

/**
 * שמירה מקומית של האסימון והמדיניות.
 *
 * ‏EncryptedSharedPreferences ולא רגיל: האסימון הוא המפתח לחשבון,
 * ובמכשיר עם הרשאת root קובץ XML רגיל נקרא בלי מאמץ. אם ההצפנה אינה
 * זמינה — נפילה חזרה לאחסון רגיל, כי חוסר גישה גרוע מאחסון חלש.
 */
class Store(context: Context) {

    /** שם החבילה שלנו — כדי לא לחסום את מגדלור עצמו. */
    val ownPackage: String = context.packageName

    private val prefs: SharedPreferences = try {
        val key = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context, "gb_secure", key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (e: Exception) {
        context.getSharedPreferences("gb_plain", Context.MODE_PRIVATE)
    }

    var token: String
        get() = prefs.getString("token", "") ?: ""
        set(v) { prefs.edit().putString("token", v).apply() }

    val isLoggedIn: Boolean get() = token.isNotEmpty()

    /**
     * כתובת השרת שהשרת עצמו הורה לעבור אליה (server_url), או ריק —
     * ואז הכתובת שנצרבה בבנייה. כך מעבר דומיין לא מחייב התקנה מחדש.
     */
    var apiBase: String
        get() = prefs.getString("api_base", "") ?: ""
        set(v) { prefs.edit().putString("api_base", v).apply() }

    /** יציאה מהחשבון. כתובת השרת נשמרת — היא של המכשיר, לא של המשתמש. */
    fun clear() {
        val base = apiBase
        prefs.edit().clear().apply()
        if (base.isNotEmpty()) apiBase = base
    }

    /*
     * המדיניות נשמרת כ-JSON גולמי כפי שהתקבלה.
     *
     * כך האפליקציה עובדת גם בלי רשת רגעית — עם המדיניות האחרונה
     * שאושרה, ולא עם שום גישה. הרענון הבא יחליף אותה.
     */
    fun savePolicy(payload: JSONObject) {
        prefs.edit()
            .putString("policy", payload.optJSONObject("policy")?.toString() ?: "{}")
            .putString("rules", payload.optJSONArray("rules")?.toString() ?: "[]")
            .putString("tiles", payload.optJSONArray("tiles")?.toString() ?: "[]")
            .putString("categories", payload.optJSONObject("categories")?.toString() ?: "{}")
            .putString("domain_map", payload.optJSONObject("domain_map")?.toString() ?: "{}")
            .putString("platforms", payload.optJSONObject("platforms")?.toString() ?: "{}")
            .putString("platform_items", payload.optJSONObject("platform_items")?.toString() ?: "{}")
            .putString("ad_hosts", payload.optJSONArray("ad_hosts")?.toString() ?: "[]")
            .putString("ad_css", payload.optString("ad_css"))
            .putString("name", payload.optJSONObject("user")?.optString("name") ?: "")
            .putString("rev", payload.optString("rev"))
            .putLong("policy_at", System.currentTimeMillis())
            .apply()
        pinIdentity(payload.optInt("account_id"), payload.optInt("child_id"))
        // הגדרות המכשיר שההורה קבע ({מפתח: חסום?}) — Enforcer מפעיל אותן ב"בעל מכשיר".
        prefs.edit().putString("device", payload.optJSONObject("device")?.toString() ?: "{}").apply()
        pinSignKey(payload.optString("sign_pubkey"))
        saveApps(payload.optJSONObject("apps"))
    }

    /**
     * המפתח הציבורי של השרת לאימות הודעות חתומות (שחרור, server_url).
     * מוצמד בפעם הראשונה (TOFU, ברגע הצימוד המהימן) ואינו משתנה — כך MITM מאוחר לא יחליף אותו.
     */
    var signPubKey: String
        get() = prefs.getString("sign_pubkey", "") ?: ""
        private set(v) { prefs.edit().putString("sign_pubkey", v).apply() }

    fun pinSignKey(k: String) { if (k.isNotEmpty() && signPubKey.isEmpty()) signPubKey = k }

    /**
     * זהות החשבון והילד של המכשיר — לאימות אסימון השחרור וקוד ההורה. מוצמדת בפעם הראשונה (בצימוד), כמו
     * המפתח, ואינה נדרסת מתשובה מאוחרת (סקירה, 2026-10-20 — שלב 8): קודם נכתבה מכל תשובה, ואסימון השחרור
     * אומת מול account_id מאותה תשובה — כלומר מול עצמו. ניתוק (clear) מאפס אותה.
     */
    val accountId: Int get() = prefs.getInt("account_id", 0)

    private fun pinIdentity(acct: Int, child: Int) {
        val e = prefs.edit()
        if (accountId <= 0 && acct > 0) e.putInt("account_id", acct)
        else if (acct > 0 && acct != accountId) Trace.log("identity.mismatch", "acct=$acct pinned=$accountId")
        if (childId <= 0 && child > 0) e.putInt("child_id", child)
        else if (child > 0 && child != childId) Trace.log("identity.mismatch", "child=$child pinned=$childId")
        e.apply()
    }

    /** ‏issued_at של תשובת המדיניות האחרונה שהוחלה (PolicySync) — תשובה ישנה ממנה נזרקת. */
    var policySeq: Long
        get() = prefs.getLong("policy_seq", 0L)
        set(v) { prefs.edit().putLong("policy_seq", v).apply() }

    /** מזהה הילד — לקוד "העברה בלי אינטרנט" (OfflinePolicy). 0 — שרת ישן שעוד לא שלח. */
    val childId: Int get() = prefs.getInt("child_id", 0)

    /** ‏iat של הקוד האחרון שהופעל בלי אינטרנט — קוד ישן ממנו (או אותו קוד שוב) נדחה. */
    var offlineIat: Long
        get() = prefs.getLong("offline_iat", 0L)
        set(v) { prefs.edit().putLong("offline_iat", v).apply() }

    /** הגדרות המכשיר מקוד בלי אינטרנט — כמו "device" במדיניות מהשרת. */
    fun saveDevice(d: JSONObject) { prefs.edit().putString("device", d.toString()).apply() }

    /**
     * המכשיר קיבל שחרור חתום: המנוי הסתיים, כל המגבלות פתוחות, ומוצג "המנוי של ההורים הסתיים".
     * נשמר כך שגם בלי רשת המכשיר נשאר משוחרר — עד שיתקבל שוב חשבון פעיל (הארכה).
     */
    var released: Boolean
        get() = prefs.getBoolean("released", false)
        set(v) { prefs.edit().putBoolean("released", v).apply() }

    /** מדיניות האפליקציות (שלב 11) כפי שהתקבלה מהשרת. */
    fun saveApps(a: JSONObject?) {
        prefs.edit()
            .putBoolean("apps_manage", a?.optBoolean("manage") ?: false)
            .putString("apps_posture", a?.optString("posture", "allow_all") ?: "allow_all")
            .putString("apps_blocked", a?.optJSONArray("blocked")?.toString() ?: "[]")
            .putString("apps_allowed", a?.optJSONArray("allowed")?.toString() ?: "[]")
            // אפליקציה חדשה (NewAppMath): allow / block, ומה שההורה כבר ראה.
            .putString("apps_new", a?.optString("new_apps", "") ?: "")
            .putString("apps_known", a?.optJSONArray("known")?.toString() ?: "[]")
            // מוסתרות (בעל מכשיר): נעלמות מהטלפון. השרת שולח אותן גם ב-blocked.
            .putString("apps_hidden", a?.optJSONArray("hidden")?.toString() ?: "[]")
            // כרום מנוהל (lib/chrome.php, Enforcer.applyChrome).
            .putString("apps_chrome", a?.optJSONObject("chrome")?.toString() ?: "{}")
            // המרקט (lib/market.php): מה ששוחרר — [{p, n, c, icon, note}].
            .putString("apps_market", a?.optJSONArray("market")?.toString() ?: "[]")
            // מה עוד אפשר לבקש מהמרקט — [{p, n, c, note}].
            .putString("apps_market_more", a?.optJSONArray("market_more")?.toString() ?: "[]")
            // זמן מסך: {package: דקות ליום} (AppTimeMath).
            .putString("app_limits", a?.optJSONObject("limits")?.toString() ?: "{}")
            .apply()
    }

    /** זמן מסך — {package: דקות ליום}. */
    val appLimits: Map<String, Int> get() = (obj("app_limits") ?: JSONObject()).let { o ->
        o.keys().asSequence().associateWith { o.optInt(it) }.filterValues { it > 0 }
    }

    /** הזמן שנצבר היום לכל אפליקציה (AppGuardService). */
    var appUsage: AppTimeMath.Usage
        get() = (obj("app_usage") ?: JSONObject()).let { o ->
            val ms = o.optJSONObject("ms") ?: JSONObject()
            AppTimeMath.Usage(o.optString("day"), ms.keys().asSequence().associateWith { ms.optLong(it) })
        }
        set(v) { prefs.edit().putString("app_usage", JSONObject().put("day", v.day).put("ms", JSONObject(v.ms as Map<*, *>)).toString()).apply() }

    /** הדיווח האחרון לשרת (do=app_usage): מתי, ומה. */
    var appUsageSent: String
        get() = prefs.getString("app_usage_sent", "") ?: ""
        set(v) { prefs.edit().putString("app_usage_sent", v).apply() }

    /** היום של הילד (אזור הזמן שלו, השעון האמין) — למגבלות זמן המסך. */
    fun today(): String = UsageMath.dayKey(TrustedClock.now(this), policy().timezone)

    val appsManage: Boolean get() = prefs.getBoolean("apps_manage", false)
    val appsPosture: String get() = prefs.getString("apps_posture", "allow_all") ?: "allow_all"
    val appsBlocked: Set<String> get() = strSet("apps_blocked")
    val appsAllowed: Set<String> get() = strSet("apps_allowed")
    val appsNew: String get() = prefs.getString("apps_new", "") ?: ""
    val appsKnown: Set<String> get() = strSet("apps_known")

    /** מה שהיה מותקן בסריקה הראשונה — לא "חדש" (NewAppMath). */
    var appsBaseline: Set<String>
        get() = strSet("apps_baseline")
        set(v) { prefs.edit().putString("apps_baseline", JSONArray(v.toList()).toString()).apply() }

    /** הדיווח האחרון על אפליקציות חדשות: מה ומתי. */
    var newAppsSent: Set<String>
        get() = strSet("new_apps_sent")
        set(v) { prefs.edit().putString("new_apps_sent", JSONArray(v.toList()).toString()).apply() }
    var newAppsSentAt: Long
        get() = prefs.getLong("new_apps_sent_at", 0L)
        set(v) { prefs.edit().putLong("new_apps_sent_at", v).apply() }
    val appsHidden: Set<String> get() = strSet("apps_hidden")
    val chromePolicy: JSONObject get() = obj("apps_chrome") ?: JSONObject()

    /** המרקט: מה ששוחרר לילד — (חבילה, שם). */
    val market: List<Pair<String, String>> get() = try {
        val a = JSONArray(prefs.getString("apps_market", "[]") ?: "[]")
        (0 until a.length()).mapNotNull { a.optJSONObject(it) }.map { it.optString("p") to it.optString("n") }.filter { it.first.isNotEmpty() }
    } catch (e: Exception) { emptyList() }

    /** מה עוד אפשר לבקש מהמרקט (marketRequestCreate) — (חבילה, שם, קטגוריה). */
    val marketMore: List<Triple<String, String, String>> get() = try {
        val a = JSONArray(prefs.getString("apps_market_more", "[]") ?: "[]")
        (0 until a.length()).mapNotNull { a.optJSONObject(it) }
            .map { Triple(it.optString("p"), it.optString("n"), it.optString("c")) }.filter { it.first.isNotEmpty() }
    } catch (e: Exception) { emptyList() }

    /** בקשות מהמרקט שנשלחו מהמכשיר הזה — "🕓 ממתין להורה" ברשימה. */
    var marketAsked: Set<String>
        get() = strSet("market_asked")
        set(v) { prefs.edit().putString("market_asked", JSONArray(v.toList()).toString()).apply() }

    /** חלון ההתקנה מהמרקט (MarketMath): מה מתקינים, ועד מתי Play פתוחה. */
    var marketTarget: String
        get() = prefs.getString("market_target", "") ?: ""
        set(v) { prefs.edit().putString("market_target", v).apply() }
    var marketUntil: Long
        get() = prefs.getLong("market_until", 0L)
        set(v) { prefs.edit().putLong("market_until", v).apply() }
    /** ההתקנה הרובוטית (MarketRobot): מתי התחילה (0 — לא פעילה), ומתי לחצנו "התקנה" (0 — עוד לא). */
    var marketRobotAt: Long
        get() = prefs.getLong("market_robot_at", 0L)
        set(v) { prefs.edit().putLong("market_robot_at", v).apply() }
    var marketClickedAt: Long
        get() = prefs.getLong("market_clicked_at", 0L)
        set(v) { prefs.edit().putLong("market_clicked_at", v).apply() }

    /** "זרות": הותקנו בחלון ולא אושרו — חסומות עד שההורה יחליט (AppPolicy). */
    var marketStrays: Set<String>
        get() = strSet("market_strays")
        set(v) { prefs.edit().putString("market_strays", JSONArray(v.toList()).toString()).apply() }

    /** חבילות כרום שמגדלור העביר להן מדיניות — כדי לנקות אותה כשההורה מכבה "כרום מנוהל". */
    var chromeApplied: Set<String>
        get() = strSet("chrome_applied")
        set(v) { prefs.edit().putString("chrome_applied", JSONArray(v.toList()).toString()).apply() }

    /** התוצאה האחרונה (verified / fail / no_owner / not_installed / ריק — לא מנוהל) — לדוח האכיפה. */
    var chromeResult: String
        get() = prefs.getString("chrome_result", "") ?: ""
        set(v) { prefs.edit().putString("chrome_result", v).apply() }

    /** עד מתי ההגדרות פתוחות למרות חסימה — כי מגדלור עצמו פתח אותן (AppPolicy.openSettings). */
    var settingsPassUntil: Long
        get() = prefs.getLong("settings_pass", 0L)
        set(v) { prefs.edit().putLong("settings_pass", v).apply() }

    /** חבילות שמגדלור השעה (בעל מכשיר) — כדי לבטל השעיה כשהן כבר לא חסומות. */
    var suspended: Set<String>
        get() = strSet("suspended")
        set(v) { prefs.edit().putString("suspended", JSONArray(v.toList()).toString()).apply() }

    /** חבילות שמגדלור הסתיר (setApplicationHidden) — כדי להחזיר אותן כשההורה ביטל את ההסתרה. */
    var hidden: Set<String>
        get() = strSet("hidden_now")
        set(v) { prefs.edit().putString("hidden_now", JSONArray(v.toList()).toString()).apply() }

    /** חבילות ה-launcher (מסכי הבית) והמקלדות במכשיר — לא נחסמות לעולם. AppPolicy.scan. */
    var launchers: Set<String>
        get() = strSet("launchers")
        set(v) { prefs.edit().putString("launchers", JSONArray(v.toList()).toString()).apply() }

    /** האפליקציות שיש להן אייקון לפתיחה — רק עליהן חל "רק מה שאישרתי". AppPolicy.scan. */
    var launchable: Set<String>
        get() = strSet("launchable")
        set(v) { prefs.edit().putString("launchable", JSONArray(v.toList()).toString()).apply() }

    private fun strSet(key: String): Set<String> = try {
        val a = JSONArray(prefs.getString(key, "[]") ?: "[]")
        (0 until a.length()).map { a.getString(it) }.toSet()
    } catch (e: Exception) { emptySet() }

    fun policy(): Policy {
        // משוחרר — אין אכיפה: דפדפן פתוח לגמרי, גם במצב לא מקוון.
        if (released) return Policy(mode = Policy.MODE_BROWSER, posture = Policy.POSTURE_ALLOW)
        return try { Policy.from(JSONObject(prefs.getString("policy", "{}") ?: "{}")) }
        catch (e: Exception) { Policy() }
    }

    /** כל הכללים, כולל כאלה שתוקפם יפוג — לחישוב התפוגה הקרובה. */
    fun ruleExpiries(): List<String> = try {
        PolicyEngine.rulesFrom(JSONArray(prefs.getString("rules", "[]") ?: "[]")).map { it.expiresAt }.filter { it.isNotEmpty() }
    } catch (e: Exception) { emptyList() }

    fun rules(): List<Rule> = try {
        // תפוגה לפי זמן אמין (TrustedClock), לא לפי שעון המכשיר שהילד יכול להזיז.
        PolicyEngine.liveRules(PolicyEngine.rulesFrom(JSONArray(prefs.getString("rules", "[]") ?: "[]")),
                               PolicyEngine.nowIso(TrustedClock.now(this)))
    } catch (e: Exception) { emptyList() }

    /** טביעת האצבע של המדיניות השמורה; הפעימה משווה אליה (שלב 6). */
    val rev: String get() = prefs.getString("rev", "") ?: ""

    /*
     * בקשות גישה שנשלחו ועוד לא נענו: מזהה → כתובת. המכשיר שואל עליהן
     * (RequestWatcher) עד שההורה עונה, ואז פותח את הדף לבד.
     */
    fun pendingRequests(): Map<Int, String> = try {
        val o = JSONObject(prefs.getString("pending_req", "{}") ?: "{}")
        o.keys().asSequence().associate { it.toInt() to o.getString(it) }
    } catch (e: Exception) { emptyMap() }

    /** היתרים זמניים לאפליקציות שהוחלו במכשיר (AppGrants): מזהה הבקשה → חבילה ועד מתי. */
    var appGrants: Map<Int, AppGrantMath.Grant>
        get() = try {
            val o = JSONObject(prefs.getString("app_grants", "{}") ?: "{}")
            o.keys().asSequence().associate { k -> o.getJSONObject(k).let { k.toInt() to AppGrantMath.Grant(it.optString("p"), it.optLong("u")) } }
        } catch (e: Exception) { emptyMap() }
        set(m) {
            val o = JSONObject(); for ((k, g) in m) o.put(k.toString(), JSONObject().put("p", g.pkg).put("u", g.until))
            prefs.edit().putString("app_grants", o.toString()).apply()
        }

    /** בקשות פתיחה לאפליקציות שנשלחו ועוד לא נענו: חבילה → מזהה הבקשה ומתי נשלחה. */
    var appPending: Map<String, Pair<Int, Long>>
        get() = try {
            val o = JSONObject(prefs.getString("app_pending", "{}") ?: "{}")
            o.keys().asSequence().associate { k -> o.getJSONObject(k).let { k to (it.optInt("id") to it.optLong("at")) } }
        } catch (e: Exception) { emptyMap() }
        set(m) {
            val o = JSONObject(); for ((k, v) in m) o.put(k, JSONObject().put("id", v.first).put("at", v.second))
            prefs.edit().putString("app_pending", o.toString()).apply()
        }

    /** החלטות שכבר טופלו במכשיר (אישור ביצוע נשלח, הילד קיבל הודעה) — כדי לא לחזור עליהן. */
    var appHandled: Set<Int>
        get() = (prefs.getString("app_handled", "") ?: "").split(",").mapNotNull { it.toIntOrNull() }.toSet()
        set(v) { prefs.edit().putString("app_handled", v.sorted().takeLast(100).joinToString(",")).apply() }

    fun addPendingRequest(id: Int, url: String) = savePending(pendingRequests() + (id to url))
    fun removePendingRequest(id: Int) = savePending(pendingRequests() - id)

    private fun savePending(m: Map<Int, String>) {
        val o = JSONObject(); for ((k, v) in m) o.put(k.toString(), v)
        prefs.edit().putString("pending_req", o.toString()).apply()
    }

    /**
     * האריחים שהשרת שלח.
     *
     * שרת ישן אינו שולח tiles, ולכן יש נפילה חזרה לכללי הכתובות —
     * אחרת עדכון של האפליקציה לפני עדכון השרת היה מוחק את המסך.
     */
    fun tiles(): List<Tile> = try {
        val t = PolicyEngine.tilesFrom(JSONArray(prefs.getString("tiles", "[]") ?: "[]"))
        t.ifEmpty {
            rules().filter { it.showTile && it.action == "allow" }
                .map { Tile(it.label.ifEmpty { it.pattern }, it.pattern, "url") }
        }
    } catch (e: Exception) { emptyList() }

    /** כל מה שהמנוע צריך, כפי שהתקבל מהשרת. משוחרר — אין כללים, הכול פתוח. */
    fun ruleSet(): RuleSet = if (released) RuleSet() else try {
        RuleSet(
            rules = rules(),
            categories = PolicyEngine.stringMap(obj("categories")),
            domainMap = PolicyEngine.listMap(obj("domain_map")),
            platforms = PolicyEngine.platformsFrom(obj("platforms")),
            platformItems = PolicyEngine.itemsFrom(obj("platform_items")),
            adHosts = PolicyEngine.stringList(
                JSONArray(prefs.getString("ad_hosts", "[]") ?: "[]")),
            adCss = prefs.getString("ad_css", "") ?: "",
        )
    } catch (e: Exception) { RuleSet(rules = rules()) }

    private fun obj(key: String): JSONObject? =
        try { JSONObject(prefs.getString(key, "{}") ?: "{}") } catch (e: Exception) { null }

    fun displayName(): String = prefs.getString("name", "") ?: ""

    /**
     * מועדפים — כתובות של אריחים, בסדר שהילד בחר. נשמרים במכשיר בלבד:
     * זה סידור אישי, לא הרשאה; אריח שההורה הסיר פשוט לא יוצג.
     */
    fun favorites(): List<String> = try {
        val a = JSONArray(prefs.getString("favs", "[]") ?: "[]")
        (0 until a.length()).map { a.getString(it) }
    } catch (e: Exception) { emptyList() }

    /* ── הדפדפן (BrowserActivity): גודל טקסט, תצוגת מחשב, ומועדפים מכל דף ── */

    var browserZoom: Int
        get() = prefs.getInt("browser_zoom", 100)
        set(v) { prefs.edit().putInt("browser_zoom", v).apply() }
    var browserDesktop: Boolean
        get() = prefs.getBoolean("browser_desktop", false)
        set(v) { prefs.edit().putBoolean("browser_desktop", v).apply() }

    /** מועדפים מהדפדפן — (שם, כתובת). נפתחים כמו כל ניווט: מותר רק מה שמותר. */
    fun bookmarks(): List<Pair<String, String>> = try {
        val a = JSONArray(prefs.getString("bookmarks", "[]") ?: "[]")
        (0 until a.length()).mapNotNull { a.optJSONObject(it) }.map { it.optString("t") to it.optString("u") }.filter { it.second.isNotEmpty() }
    } catch (e: Exception) { emptyList() }

    fun isBookmarked(url: String): Boolean = bookmarks().any { it.second == url }

    /** מוסיף או מסיר. ‏true — נוסף. עד 100. */
    fun toggleBookmark(title: String, url: String): Boolean {
        val list = bookmarks().toMutableList()
        val had = list.removeAll { it.second == url }
        if (!had) list.add(0, title.ifEmpty { url } to url)
        val arr = JSONArray()
        for ((t, u) in list.take(100)) arr.put(JSONObject().put("t", t.take(120)).put("u", u))
        prefs.edit().putString("bookmarks", arr.toString()).apply()
        return !had
    }

    fun toggleFavorite(url: String): Boolean {
        val list = favorites().toMutableList()
        val on = !list.remove(url)
        if (on) list.add(url)
        prefs.edit().putString("favs", JSONArray(list).toString()).apply()
        return on
    }

    /**
     * עדכון חובה שהשרת הודיע עליו (Updater): הגרסה והקישור. נשמר — כך שגם אחרי הפעלה מחדש, ובלי
     * רשת, האפליקציה נשארת נעולה עד שהגרסה החדשה מותקנת.
     */
    var updateCode: Int
        get() = prefs.getInt("update_code", 0)
        set(v) { prefs.edit().putInt("update_code", v).apply() }
    var updateUrl: String
        get() = prefs.getString("update_url", "") ?: ""
        set(v) { prefs.edit().putString("update_url", v).apply() }
    /** ‏sha256 של ה-APK כפי שהשרת פרסם (latest.json). ריק — שרת ישן. */
    var updateSha: String
        get() = prefs.getString("update_sha", "") ?: ""
        set(v) { prefs.edit().putString("update_sha", v).apply() }
    /** עד מתי הגבלות ההתקנה מוסרות לעדכון (UpdateMath.installOpen). 0 — סגור. */
    var installOpenUntil: Long
        get() = prefs.getLong("install_open_until", 0L)
        set(v) { prefs.edit().putLong("install_open_until", v).apply() }

    /** הגדרות המכשיר מהמדיניות: חסום? לפי מפתח (lib/devicesettings.php). null — השרת לא שלח (ישן). */
    fun deviceSetting(key: String): Boolean? = try {
        val o = JSONObject(prefs.getString("device", "{}") ?: "{}")
        if (o.has(key)) o.getBoolean(key) else null
    } catch (e: Exception) { null }

    /* ── מונה השימוש (UsageMeter) וזמן אמין (TrustedClock) ── */
    var usageOpen: String
        get() = prefs.getString("usage_open", "{}") ?: "{}"
        set(v) { prefs.edit().putString("usage_open", v).apply() }
    var usageInflight: String
        get() = prefs.getString("usage_inflight", "[]") ?: "[]"
        set(v) { prefs.edit().putString("usage_inflight", v).apply() }
    var usageServerUsed: Int
        get() = prefs.getInt("usage_server_used", 0)
        set(v) { prefs.edit().putInt("usage_server_used", v).apply() }
    var usageServerDay: String
        get() = prefs.getString("usage_server_day", "") ?: ""
        set(v) { prefs.edit().putString("usage_server_day", v).apply() }
    var sessionMs: Long
        get() = prefs.getLong("session_ms", 0L)
        set(v) { prefs.edit().putLong("session_ms", v).apply() }
    var sessionLast: Long
        get() = prefs.getLong("session_last", 0L)
        set(v) { prefs.edit().putLong("session_last", v).apply() }
    var clockServerMs: Long
        get() = prefs.getLong("clock_server_ms", 0L)
        set(v) { prefs.edit().putLong("clock_server_ms", v).apply() }
    var clockElapsed: Long
        get() = prefs.getLong("clock_elapsed", 0L)
        set(v) { prefs.edit().putLong("clock_elapsed", v).apply() }
    /** זמן אמין של הסנכרון המוצלח האחרון עם השרת — לחלון החסד (UsageMath.graceExpired). */
    var lastSyncMs: Long
        get() = prefs.getLong("last_sync_ms", 0L)
        set(v) { prefs.edit().putLong("last_sync_ms", v).apply() }

    /** הדוח האחרון שנשלח (EnforceReport) — לא שולחים שוב אותו דוח, אלא אם התיישן. */
    var enforceHash: String
        get() = prefs.getString("enforce_hash", "") ?: ""
        set(v) { prefs.edit().putString("enforce_hash", v).apply() }
    var enforceAt: Long
        get() = prefs.getLong("enforce_at", 0L)
        set(v) { prefs.edit().putLong("enforce_at", v).apply() }

    /** דוח האימות האחרון של מצב הלמידה ביוטיוב שהשרת קיבל (YouTubeLearn). */
    var ytLearnSent: String
        get() = prefs.getString("yt_learn_sent", "") ?: ""
        set(v) { prefs.edit().putString("yt_learn_sent", v).apply() }
    var ytLearnSentAt: Long
        get() = prefs.getLong("yt_learn_sent_at", 0L)
        set(v) { prefs.edit().putLong("yt_learn_sent_at", v).apply() }

    /** בדיקת ההגנה האחרונה שהשרת קיבל את התוצאות שלה (SelfTest). */
    var selftestDone: String
        get() = prefs.getString("selftest_done", "") ?: ""
        set(v) { prefs.edit().putString("selftest_done", v).apply() }

    /** ביטים של ההרשאות שניתנו במכשיר (Setup.flags) — נשלח לשרת כדי שההורה יראה. */
    var perms: Int
        get() = prefs.getInt("perms", 0)
        set(v) { prefs.edit().putInt("perms", v).apply() }

}
