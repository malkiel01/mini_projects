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

    fun clear() = prefs.edit().clear().apply()

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
            .putString("categories", payload.optJSONObject("categories")?.toString() ?: "{}")
            .putString("domain_map", payload.optJSONObject("domain_map")?.toString() ?: "{}")
            .putString("platforms", payload.optJSONObject("platforms")?.toString() ?: "{}")
            .putString("platform_items", payload.optJSONObject("platform_items")?.toString() ?: "{}")
            .putString("name", payload.optJSONObject("user")?.optString("name") ?: "")
            .putLong("policy_at", System.currentTimeMillis())
            .apply()
    }

    fun policy(): Policy =
        try { Policy.from(JSONObject(prefs.getString("policy", "{}") ?: "{}")) }
        catch (e: Exception) { Policy() }

    fun rules(): List<Rule> = try {
        PolicyEngine.rulesFrom(JSONArray(prefs.getString("rules", "[]") ?: "[]"))
    } catch (e: Exception) { emptyList() }

    /** כל מה שהמנוע צריך, כפי שהתקבל מהשרת. */
    fun ruleSet(): RuleSet = try {
        RuleSet(
            rules = rules(),
            categories = PolicyEngine.stringMap(obj("categories")),
            domainMap = PolicyEngine.listMap(obj("domain_map")),
            platforms = PolicyEngine.platformsFrom(obj("platforms")),
            platformItems = PolicyEngine.itemsFrom(obj("platform_items")),
        )
    } catch (e: Exception) { RuleSet(rules = rules()) }

    private fun obj(key: String): JSONObject? =
        try { JSONObject(prefs.getString(key, "{}") ?: "{}") } catch (e: Exception) { null }

    fun displayName(): String = prefs.getString("name", "") ?: ""

    /** גיל המדיניות השמורה. מדיניות עתיקה מדי אינה בסיס להמשך גלישה. */
    fun policyAgeMinutes(): Long {
        val at = prefs.getLong("policy_at", 0L)
        if (at == 0L) return Long.MAX_VALUE
        return (System.currentTimeMillis() - at) / 60_000L
    }
}
