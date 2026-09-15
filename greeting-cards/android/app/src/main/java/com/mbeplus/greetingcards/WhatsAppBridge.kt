package com.mbeplus.greetingcards

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.provider.ContactsContract
import android.util.Base64
import android.util.Log
import android.webkit.JavascriptInterface
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * JavaScript bridge — injected into the WebView as `window.GreetingCardsAndroid`.
 * Reads the phone's contacts, and opens a WhatsApp chat per recipient with the
 * rendered card attached. The queue itself lives in the web app.
 */
class WhatsAppBridge(private val context: Context) {

    companion object {
        private const val TAG = "WhatsAppBridge"
    }

    // ─── Contacts ────────────────────────────────────
    //
    // ‏Contact Picker API של הדפדפן אינו קיים ב-WebView, ולכן אנשי
    // הקשר נקראים כאן ישירות מספר הטלפונים של המכשיר.

    @JavascriptInterface
    fun hasContactsPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) ==
            PackageManager.PERMISSION_GRANTED

    /**
     * מבקש את ההרשאה. התשובה חוזרת ל-JS דרך window.onContactsPermissionResult,
     * כי דיאלוג ההרשאות אינו חוסם ואי אפשר להחזיר את התוצאה מכאן.
     */
    @JavascriptInterface
    fun requestContactsPermission() {
        (context as? MainActivity)?.requestContactsPermission()
    }

    /** נדרש כשההרשאה נדחתה לצמיתות — אז אין יותר דיאלוג לבקש. */
    @JavascriptInterface
    fun openAppSettings() {
        (context as? MainActivity)?.openAppSettings()
    }

    /** מאפשר לדף לדעת שהוא רץ בגרסת אפליקציה שיודעת לקרוא אנשי קשר. */
    @JavascriptInterface
    fun appVersion(): String = BuildConfig.VERSION_NAME

    /**
     * ‏JSON: {"contacts":[{"name":…,"phone":…}]} או {"error":…}.
     *
     * טבלת הטלפונים מחזיקה שורה לכל מספר, ולכן איש קשר עם נייד ובית
     * מופיע פעמיים. הכפילויות מסוננות לפי שם + הספרות של המספר, כדי
     * ש"050-1234567" ו-"0501234567" ייחשבו לאותו מספר.
     */
    @JavascriptInterface
    fun readContacts(): String {
        if (!hasContactsPermission()) {
            return JSONObject().put("error", "permission_denied").toString()
        }

        val contacts = JSONArray()
        val seen = mutableSetOf<String>()

        try {
            context.contentResolver.query(
                ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
                arrayOf(
                    ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                    ContactsContract.CommonDataKinds.Phone.NUMBER,
                ),
                null, null,
                "${ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME} COLLATE LOCALIZED ASC",
            )?.use { cursor ->
                val nameCol = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
                val numCol = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NUMBER)
                if (nameCol < 0 || numCol < 0) {
                    return JSONObject().put("error", "columns_missing").toString()
                }

                while (cursor.moveToNext()) {
                    val name = cursor.getString(nameCol)?.trim().orEmpty()
                    if (name.isEmpty()) continue
                    val phone = cursor.getString(numCol)?.trim().orEmpty()

                    if (!seen.add("$name|${phone.filter(Char::isDigit)}")) continue

                    contacts.put(JSONObject().put("name", name).put("phone", phone))
                }
            } ?: return JSONObject().put("error", "query_failed").toString()
        } catch (e: Exception) {
            Log.e(TAG, "Failed to read contacts", e)
            return JSONObject().put("error", e.message ?: "read_failed").toString()
        }

        Log.i(TAG, "Read ${contacts.length()} contacts")
        return JSONObject().put("contacts", contacts).toString()
    }

    @JavascriptInterface
    fun isWhatsAppAvailable(): Boolean {
        return isPackageInstalled("com.whatsapp") || isPackageInstalled("com.whatsapp.w4b")
    }

    @JavascriptInterface
    fun getWhatsAppPackages(): String {
        val packages = mutableListOf<Map<String, String>>()
        if (isPackageInstalled("com.whatsapp")) {
            packages.add(mapOf("packageName" to "com.whatsapp", "label" to "WhatsApp"))
        }
        if (isPackageInstalled("com.whatsapp.w4b")) {
            packages.add(mapOf("packageName" to "com.whatsapp.w4b", "label" to "WhatsApp Business"))
        }
        return org.json.JSONArray(packages.map { JSONObject(it) }).toString()
    }

    // ─── Sending ─────────────────────────────────────
    //
    // ‏כרטיס אחד בכל קריאה: הדף מנהל את התור, וכאן רק נפתח הצ'אט
    // עם התמונה מצורפת. הלחיצה על "שלח" היא של המשתמש — זה מה
    // שמאפשר לוותר על שירות הנגישות, ואיתו על החסימות שהוא גורר.

    /**
     * ‏JSON נכנס: {"phone":…,"imageBase64":…,"imageFormat":"jpg",
     * ‏"whatsappPackage":"com.whatsapp"}.
     * ‏JSON חוזר: {"ok":true} או {"ok":false,"error":…}.
     */
    @JavascriptInterface
    fun openWhatsAppChat(payloadJson: String): String {
        return try {
            val payload = JSONObject(payloadJson)
            val phone = payload.getString("phone")
            val base64 = payload.getString("imageBase64")
            val format = payload.optString("imageFormat", "jpg")
            val whatsappPackage = payload.optString("whatsappPackage", "com.whatsapp")

            val jid = formatPhoneForWhatsApp(phone)
                ?: return JSONObject().put("ok", false).put("error", "bad_phone").toString()

            val cacheDir = File(context.cacheDir, "whatsapp_cards")
            if (!cacheDir.exists()) cacheDir.mkdirs()

            val imageFile = File(cacheDir, "card_${System.currentTimeMillis()}.$format")
            imageFile.writeBytes(Base64.decode(base64, Base64.DEFAULT))

            val uri = FileProvider.getUriForFile(
                context, "${context.packageName}.fileprovider", imageFile,
            )

            // ‏jid אינו מתועד, אבל הוא מה שפותח את הצ'אט של המספר
            // עצמו. בלעדיו WhatsApp היה מציג בורר נמענים בכל כרטיס.
            val intent = Intent(Intent.ACTION_SEND).apply {
                type = if (format == "png") "image/png" else "image/jpeg"
                setPackage(whatsappPackage)
                putExtra(Intent.EXTRA_STREAM, uri)
                putExtra("jid", jid)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }

            context.startActivity(intent)
            JSONObject().put("ok", true).toString()
        } catch (e: Exception) {
            Log.e(TAG, "Cannot open WhatsApp chat", e)
            JSONObject().put("ok", false).put("error", e.message ?: "launch_failed").toString()
        }
    }

    /** התמונות נכתבות ל-cache לפני השליחה; בסוף התור הן מיותרות. */
    @JavascriptInterface
    fun clearSentCards() {
        try {
            File(context.cacheDir, "whatsapp_cards").listFiles()?.forEach { it.delete() }
        } catch (e: Exception) {
            Log.w(TAG, "Cannot clear card cache", e)
        }
    }

    private fun isPackageInstalled(packageName: String): Boolean {
        return try {
            context.packageManager.getPackageInfo(packageName, 0)
            true
        } catch (e: PackageManager.NameNotFoundException) {
            false
        }
    }

    /**
     * ‏מספר → JID בפורמט <מספר>@s.whatsapp.net, או null אם אינו תקין.
     * מספר ישראלי מקומי (05x, 03 וכו') מומר לבינלאומי, כי WhatsApp
     * מזהה רק מספרים עם קידומת מדינה.
     */
    private fun formatPhoneForWhatsApp(phone: String): String? {
        var cleaned = phone.replace(Regex("[^\\d+]"), "")
        if (cleaned.length < 7) return null

        if (Regex("^0[2-9]").containsMatchIn(cleaned)) {
            cleaned = "+972" + cleaned.substring(1)
        }
        if (cleaned.startsWith("+")) cleaned = cleaned.substring(1)

        if (cleaned.length < 7 || cleaned.length > 15) return null
        return "$cleaned@s.whatsapp.net"
    }
}
