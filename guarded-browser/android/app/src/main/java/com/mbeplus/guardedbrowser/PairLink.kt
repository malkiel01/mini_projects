package com.mbeplus.guardedbrowser

import java.net.URLDecoder

/**
 * מה שה-QR של ההורה מכיל: ‏migdalor:pair?c=CODE&s=https://server/
 *
 * מופרד מהמסך כדי שאפשר יהיה לבדוק אותו בלי מצלמה. קוד שהוקלד ביד עובר
 * באותו מקום — עם מקף, רווחים או אותיות גדולות, כמו שאנשים מקלידים.
 */
data class PairLink(val code: String, val server: String) {
    companion object {
        private val CODE = Regex("^[a-z0-9]{8}$")

        /** הקוד כפי שהשרת מצפה לו, או "" אם אינו קוד. */
        fun cleanCode(raw: String): String {
            val c = raw.lowercase().replace(Regex("[\\s-]+"), "")
            return if (CODE.matches(c)) c else ""
        }

        /** מתוכן QR או מקלט ידני. null — לא זוהה קוד. */
        fun parse(raw: String): PairLink? {
            val text = raw.trim()
            if (!text.startsWith("migdalor:pair?")) {
                val c = cleanCode(text)
                return if (c.isEmpty()) null else PairLink(c, "")
            }
            val params = text.substringAfter('?').split('&').mapNotNull {
                val i = it.indexOf('=')
                if (i <= 0) null else it.substring(0, i) to
                    try { URLDecoder.decode(it.substring(i + 1), "UTF-8") } catch (e: Exception) { "" }
            }.toMap()
            val code = cleanCode(params["c"] ?: "")
            if (code.isEmpty()) return null
            val s = params["s"] ?: ""
            // רק https — QR שמפנה לשרת לא מוצפן אינו מגדלור.
            val server = if (s.startsWith("https://") && !s.contains(' ')) s else ""
            return PairLink(code, server)
        }
    }
}
