package com.mbeplus.wascanner

import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * הפנייה לשרת. בלי ספריות רשת חיצוניות — HttpURLConnection ו-org.json
 * מספיקים, ופחות תלויות פירושו בנייה שלא נשברת.
 *
 * האסימון נשלח בשתי כותרות: Authorization ו-X-Pair-Token. שרתי
 * Apache/cPanel רבים משמיטים את Authorization לפני PHP, וה-X-Pair-Token
 * עוקף את זה. השרת בודק את שתיהן.
 *
 * ‏ext_id מחושב כאן: גיבוב יציב של תוכן ההודעה, כדי שסריקה חוזרת של אותו
 * מסך לא תיצור כפילות (השרת עושה INSERT OR IGNORE על המזהה).
 */
class Api(private val base: String, private val token: String) {

    data class Msg(
        val chatName: String,
        val sender: String,
        val direction: String,
        val body: String,
        val sentAt: Long,
    ) {
        fun extId(): String {
            val raw = "$chatName|$sender|$direction|$sentAt|$body"
            return Integer.toHexString(raw.hashCode()) + "-" + raw.length
        }

        fun toJson(): JSONObject = JSONObject().apply {
            put("ext_id", extId())
            put("chat_name", chatName)
            put("sender", sender)
            put("direction", direction)
            put("body", body)
            put("sent_at", sentAt)
        }
    }

    data class Result(val httpCode: Int, val ingested: Int, val error: String?)

    private fun open(action: String): HttpURLConnection {
        val conn = URL(base + "api/index.php?action=$action").openConnection() as HttpURLConnection
        conn.requestMethod = "POST"
        conn.connectTimeout = 8000
        conn.readTimeout = 15000
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json")
        conn.setRequestProperty("Authorization", "Bearer $token")
        conn.setRequestProperty("X-Pair-Token", token)
        return conn
    }

    private fun send(conn: HttpURLConnection, payload: JSONObject): Pair<Int, String> {
        conn.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
        val code = conn.responseCode
        val text = (if (code in 200..299) conn.inputStream else conn.errorStream)
            ?.bufferedReader()?.readText() ?: ""
        return code to text
    }

    /** בדיקת חיבור: מאמת אסימון+כתובת+רשת בלי לגעת בהודעות. */
    fun ping(accountId: Int): Result {
        val conn = open("ping")
        return try {
            val (code, text) = send(conn, JSONObject().put("account_id", accountId))
            if (code != 200) return Result(code, 0, errorOf(text))
            val exists = JSONObject(text).optBoolean("account_exists", false)
            Result(200, 0, if (exists) null else "החשבון לא קיים בשרת")
        } catch (e: Exception) {
            Result(-1, 0, e.message ?: "כשל רשת")
        } finally {
            conn.disconnect()
        }
    }

    data class Account(val id: Int, val label: String, val kind: String, val pkg: String)

    /** שולף את רשימת החשבונות לבורר "חשבון פעיל". ריק בכשל. */
    fun accounts(): List<Account> {
        val conn = open("bridge_accounts")
        return try {
            val (code, text) = send(conn, JSONObject())
            if (code != 200) return emptyList()
            val arr = JSONObject(text).optJSONArray("accounts") ?: JSONArray()
            (0 until arr.length()).map {
                val o = arr.getJSONObject(it)
                Account(o.getInt("id"), o.optString("label"), o.optString("kind"), o.optString("package"))
            }
        } catch (e: Exception) {
            emptyList()
        } finally {
            conn.disconnect()
        }
    }

    /** מעלה קובץ מדיה (PDF/תמונה) לחילוץ בשרת. מחזיר את הסטטוס בשדה error. */
    fun uploadMedia(accountId: Int, filename: String, mime: String, bytes: ByteArray, sentAt: Long): Result {
        val conn = open("media")
        conn.readTimeout = 90000   // חילוץ במודל אורך זמן
        return try {
            val payload = JSONObject().apply {
                put("account_id", accountId)
                put("filename", filename)
                put("mime", mime)
                put("sent_at", sentAt)
                put("data", android.util.Base64.encodeToString(bytes, android.util.Base64.NO_WRAP))
            }
            val (code, text) = send(conn, payload)
            if (code != 200) Result(code, 0, errorOf(text))
            else Result(200, 1, JSONObject(text).optString("status", "processed"))
        } catch (e: Exception) {
            Result(-1, 0, e.message ?: "כשל רשת")
        } finally {
            conn.disconnect()
        }
    }

    /** דוחף שורת אבחון ליומן השרת. best-effort — לא זורק. */
    fun log(tag: String, status: String, detail: String) {
        val conn = open("log")
        try {
            send(conn, JSONObject().put("tag", tag).put("status", status).put("detail", detail))
        } catch (e: Exception) {
            // אבחון לא שובר סריקה.
        } finally {
            conn.disconnect()
        }
    }

    /** מזרים אצווה. */
    fun ingest(accountId: Int, messages: List<Msg>): Result {
        if (messages.isEmpty()) return Result(0, 0, null)
        val payload = JSONObject().apply {
            put("account_id", accountId)
            put("messages", JSONArray().apply { messages.forEach { put(it.toJson()) } })
        }
        val conn = open("ingest")
        return try {
            val (code, text) = send(conn, payload)
            if (code != 200) return Result(code, 0, errorOf(text))
            Result(200, JSONObject(text).optInt("ingested", 0), null)
        } catch (e: Exception) {
            Result(-1, 0, e.message ?: "כשל רשת")
        } finally {
            conn.disconnect()
        }
    }

    private fun errorOf(text: String): String =
        try { JSONObject(text).optString("error", "שגיאה") } catch (e: Exception) { "שגיאת שרת" }
}
