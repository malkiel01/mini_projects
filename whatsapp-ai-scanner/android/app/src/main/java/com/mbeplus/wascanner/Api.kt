package com.mbeplus.wascanner

import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * הפנייה לשרת. בלי ספריות רשת חיצוניות — HttpURLConnection ו-org.json
 * מספיקים לבקשת POST/JSON אחת, ופחות תלויות פירושו בנייה שלא נשברת.
 *
 * ‏ext_id מחושב כאן ולא בשרת: הוא גיבוב יציב של תוכן ההודעה, כדי
 * שסריקה חוזרת של אותו מסך לא תיצור כפילות (השרת עושה INSERT OR IGNORE
 * על המזהה הזה).
 */
class Api(private val base: String, private val token: String) {

    data class Msg(
        val chatName: String,
        val sender: String,
        val direction: String,   // "in" / "out"
        val body: String,
        val sentAt: Long,        // שניות
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

    /** מזרים אצווה. מחזיר את מספר ההודעות שנקלטו, או -1 בכשל. */
    fun ingest(accountId: Int, messages: List<Msg>): Int {
        if (messages.isEmpty()) return 0
        val payload = JSONObject().apply {
            put("account_id", accountId)
            put("messages", JSONArray().apply { messages.forEach { put(it.toJson()) } })
        }
        val url = URL(base + "api/index.php?action=ingest")
        val conn = url.openConnection() as HttpURLConnection
        return try {
            conn.requestMethod = "POST"
            conn.connectTimeout = 8000
            conn.readTimeout = 15000
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json")
            conn.setRequestProperty("Authorization", "Bearer $token")
            conn.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }

            val code = conn.responseCode
            val text = (if (code in 200..299) conn.inputStream else conn.errorStream)
                ?.bufferedReader()?.readText() ?: ""
            if (code != 200) return -1
            JSONObject(text).optInt("ingested", 0)
        } catch (e: Exception) {
            -1
        } finally {
            conn.disconnect()
        }
    }
}
