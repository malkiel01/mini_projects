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

    // מצב סריקה אוטומטית: הודלק מכפתור באפליקציה, נצרך כשהגלילה מסתיימת.
    var autoScan: Boolean
        get() = prefs.getBoolean("autoscan", false)
        set(v) = prefs.edit().putBoolean("autoscan", v).apply()

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
