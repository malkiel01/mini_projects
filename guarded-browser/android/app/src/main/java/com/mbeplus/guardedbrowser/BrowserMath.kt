package com.mbeplus.guardedbrowser

/**
 * חישובים של סרגל הדפדפן והכלים שלו — בלי Android (BrowserMathTest).
 *
 *   • רכיבים שנחסמו בדף: כל משאב שנחסם (נגן, שרת סטרימינג, סקריפט) נספר לפי שרת — כדי שאתר "שלא עובד" יראה
 *     *מה* נחסם בו, ואפשר יהיה לבקש מההורה את השרת הזה. בלי זה, נגן ששרת הווידאו שלו אינו ברשימה פשוט נשאר שחור.
 *   • גודל טקסט, תצוגת מחשב, ושרתי התרגום.
 */
object BrowserMath {

    /** רכיב שנחסם: השרת, כמה פעמים, הסיבה (קוד ההכרעה), וכתובת לדוגמה (לבקשה). */
    data class Part(val host: String, val count: Int, val code: String, val sample: String)

    /** מוסיף משאב שנחסם לרשימה של הדף. פרסומות לא נספרות — הן נחסמות בכוונה, ואין מה לבקש. */
    fun addBlocked(parts: Map<String, Part>, url: String, code: String): Map<String, Part> {
        if (code == "ad_blocked") return parts
        val host = hostOf(url)
        if (host.isEmpty()) return parts
        val old = parts[host]
        return parts + (host to Part(host, (old?.count ?: 0) + 1, old?.code ?: code, old?.sample ?: "https://$host/"))
    }

    /** הסדר בתצוגה: מה שנחסם הכי הרבה — קודם (בדרך כלל שרת הווידאו). */
    fun sorted(parts: Map<String, Part>): List<Part> = parts.values.sortedWith(compareByDescending<Part> { it.count }.thenBy { it.host })

    fun hostOf(url: String): String = try {
        java.net.URI(url).host?.lowercase()?.removePrefix("www.") ?: ""
    } catch (e: Exception) { "" }

    /* ── גודל טקסט (WebSettings.textZoom) ── */

    val ZOOMS = listOf(70, 85, 100, 115, 130, 150, 175, 200)

    fun zoomStep(current: Int, up: Boolean): Int {
        val i = ZOOMS.indexOfFirst { it >= current }.let { if (it < 0) ZOOMS.size - 1 else it }
        val at = if (ZOOMS.getOrNull(i) == current) i else if (up) i - 1 else i
        return ZOOMS[(at + if (up) 1 else -1).coerceIn(0, ZOOMS.size - 1)]
    }

    /* ── תצוגת מחשב: אותו דפדפן, בלי "Android" ו-"Mobile" — האתר מגיש את הגרסה למחשב ── */

    fun desktopUa(mobileUa: String): String =
        mobileUa.replace(Regex("\\(Linux; Android [^)]*\\)"), "(X11; Linux x86_64)")
            .replace(" Mobile", "")
            .replace("; wv", "")

    /*
     * ── תרגום (BrowserTools) ──
     * הסקריפט של Google Translate נטען מהשרתים האלה, ורק כמשאב בתוך הדף ורק כשהמשתמש ביקש תרגום. הם מחזירים
     * טקסט מתורגם וקבצי עיצוב — לא דף אחר. *לא* translate.goog: זה פרוקסי שמגיש כל אתר, כלומר עקיפה של הסינון.
     */
    private val TRANSLATE = setOf("translate.googleapis.com", "translate-pa.googleapis.com", "translate.google.com", "gstatic.com")

    fun isTranslateHost(host: String): Boolean {
        val h = host.lowercase()
        if (h.endsWith("translate.goog")) return false
        return TRANSLATE.any { h == it || h.endsWith(".$it") }
    }
}
