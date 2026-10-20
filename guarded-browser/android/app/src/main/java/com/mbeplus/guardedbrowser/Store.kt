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
            .putInt("account_id", payload.optInt("account_id"))
            .putLong("policy_at", System.currentTimeMillis())
            .apply()
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

    /** זהות החשבון של המכשיר — לאימות ש-acct של אסימון השחרור תואם. */
    val accountId: Int get() = prefs.getInt("account_id", 0)

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
            .apply()
    }

    val appsManage: Boolean get() = prefs.getBoolean("apps_manage", false)
    val appsPosture: String get() = prefs.getString("apps_posture", "allow_all") ?: "allow_all"
    val appsBlocked: Set<String> get() = strSet("apps_blocked")
    val appsAllowed: Set<String> get() = strSet("apps_allowed")

    /** חבילות שמגדלור השעה (בעל מכשיר) — כדי לבטל השעיה כשהן כבר לא חסומות. */
    var suspended: Set<String>
        get() = strSet("suspended")
        set(v) { prefs.edit().putString("suspended", JSONArray(v.toList()).toString()).apply() }

    /** חבילות ה-launcher במכשיר — לא נחסמות. נשמר כשמדווחים את האפליקציות. */
    var launchers: Set<String>
        get() = strSet("launchers")
        set(v) { prefs.edit().putString("launchers", JSONArray(v.toList()).toString()).apply() }

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

    fun rules(): List<Rule> = try {
        PolicyEngine.liveRules(PolicyEngine.rulesFrom(JSONArray(prefs.getString("rules", "[]") ?: "[]")),
                               PolicyEngine.nowIso())
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

    /** ביטים של ההרשאות שניתנו במכשיר (Setup.flags) — נשלח לשרת כדי שההורה יראה. */
    var perms: Int
        get() = prefs.getInt("perms", 0)
        set(v) { prefs.edit().putInt("perms", v).apply() }

    /** גיל המדיניות השמורה. מדיניות עתיקה מדי אינה בסיס להמשך גלישה. */
    fun policyAgeMinutes(): Long {
        val at = prefs.getLong("policy_at", 0L)
        if (at == 0L) return Long.MAX_VALUE
        return (System.currentTimeMillis() - at) / 60_000L
    }
}
