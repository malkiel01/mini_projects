package com.mbeplus.wascanner

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * הגדרות הגשר, מוצפנות במכשיר.
 *
 * אסימון הצימוד הוא מפתח לשרת של הבעלים, ולכן אינו יושב ב-SharedPreferences
 * רגיל אלא ב-EncryptedSharedPreferences — מוגן במפתח שיושב ב-Keystore
 * החומרתי של המכשיר.
 */
class Store(context: Context) {

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "wascanner_prefs",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    var serverBase: String
        get() = prefs.getString("server", BuildConfig.API_BASE) ?: BuildConfig.API_BASE
        set(v) = prefs.edit().putString("server", v.trim().trimEnd('/') + "/").apply()

    var pairToken: String
        get() = prefs.getString("token", "") ?: ""
        set(v) = prefs.edit().putString("token", v.trim()).apply()

    var accountId: Int
        get() = prefs.getInt("account", 0)
        set(v) = prefs.edit().putInt("account", v).apply()

    var accountLabel: String
        get() = prefs.getString("account_label", "") ?: ""
        set(v) = prefs.edit().putString("account_label", v).apply()

    // חבילת האפליקציה של החשבון הפעיל — לאכיפת הפרדה מוחלטת.
    var accountPackage: String
        get() = prefs.getString("account_package", "") ?: ""
        set(v) = prefs.edit().putString("account_package", v).apply()

    // מצב סריקה אוטומטית של הצ'אט הפתוח (שלב 1).
    var autoScan: Boolean
        get() = prefs.getBoolean("autoscan", false)
        set(v) = prefs.edit().putBoolean("autoscan", v).apply()

    // מצב סריקת כל הצ'אטים (שלב 2): ניווט אוטומטי על רשימת השיחות.
    var fullSweep: Boolean
        get() = prefs.getBoolean("fullsweep", false)
        set(v) = prefs.edit().putBoolean("fullsweep", v).apply()

    // כמה חודשים אחורה לסרוק קבצים (0 = הכל).
    var fileMonths: Int
        get() = prefs.getInt("file_months", 6)
        set(v) = prefs.edit().putInt("file_months", v).apply()

    fun clearMediaSeen() = prefs.edit().remove("media_seen").apply()

    fun mediaSeenCount(): Int = prefs.getStringSet("media_seen", emptySet())!!.size

    // קבצי מדיה שכבר הועלו (מפתח: נתיב+גודל) — כדי לא להעלות שוב.
    fun isMediaSeen(key: String): Boolean =
        prefs.getStringSet("media_seen", emptySet())!!.contains(key)

    fun markMediaSeen(key: String) {
        val s = HashSet(prefs.getStringSet("media_seen", emptySet())!!)
        s.add(key)
        prefs.edit().putStringSet("media_seen", s).apply()
    }

    val configured: Boolean
        get() = pairToken.isNotEmpty() && accountId > 0
}
