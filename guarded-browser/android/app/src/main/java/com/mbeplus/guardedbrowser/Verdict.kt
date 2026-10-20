package com.mbeplus.guardedbrowser

import org.json.JSONObject

/**
 * קריאה אחידה של הכרעה מהשרת (סקירה, 2026-10-20 — שלב 7).
 *
 *   ‏ALLOW   — השרת ענה ok=true עם allowed=true.
 *   ‏DENY    — השרת ענה וסירב: allowed=false, או ok=false שאינה זמנית (חשבון מושעה, אסימון לא מוכר),
 *             או תשובה בלי allowed בוליאני. **היעדר allowed לעולם אינו היתר** — לפני זה
 *             ‏optBoolean("allowed", true) בפעימה המשיך לגלוש בחשבון מושעה.
 *   ‏UNKNOWN — לא הצלחנו לשאול: אין רשת, תשובה שאינה JSON, או שגיאה זמנית (retry, ‏server, ‏rate_limited).
 *             מה עושים אז — לפי המקום: הכרעה מקומית שכבר התירה נשארת; כתובת שרק השרת מכריע — לא נטענת.
 *
 * ‏of() טהורה (נבדקת ב-JVM, בלי org.json); ‏from()/page() מחלצות את השדות.
 */
object Verdict {
    enum class Kind { ALLOW, DENY, UNKNOWN }
    data class V(val kind: Kind, val code: String, val reason: String) {
        val allow get() = kind == Kind.ALLOW
        val deny get() = kind == Kind.DENY
    }

    /** קודים של "לא הצלחנו לשאול" — גם לגרסת שרת שלא שולחת retry. */
    private val TRANSIENT = setOf("server", "rate_limited")

    /**
     * @param hasJson  התקבל JSON
     * @param ok       ‏ok=true בתשובה (ו-HTTP 2xx)
     * @param allowed  allowed כפי שהוא — null כשחסר או לא בוליאני
     */
    fun of(hasJson: Boolean, ok: Boolean, allowed: Boolean?, code: String, reason: String, retry: Boolean = false): V {
        if (!hasJson) return V(Kind.UNKNOWN, "network", reason)
        if (!ok) {
            if (retry || code in TRANSIENT) return V(Kind.UNKNOWN, code, reason)
            return V(Kind.DENY, code.ifEmpty { "refused" }, reason.ifEmpty { "הגישה נדחתה" })
        }
        return when (allowed) {
            true -> V(Kind.ALLOW, code, reason)
            false -> V(Kind.DENY, code, reason.ifEmpty { "הכתובת אינה מותרת" })
            null -> V(Kind.DENY, "bad_response", "תשובה לא תקינה מהשרת")
        }
    }

    /** הכרעה מתשובת API שלמה (check, heartbeat). */
    fun from(r: Api.Result): V {
        val j = r.json ?: return of(false, false, null, "", r.error)
        return of(true, r.ok, bool(j, "allowed"), j.optString("code"),
                  j.optString("reason").ifEmpty { j.optString("error") }, j.optBoolean("retry"))
    }

    /** הכרעה מקוננת בתוך תשובה תקינה (page בפעימה, state במדיניות). חסר — סירוב. */
    fun nested(j: JSONObject?): V =
        if (j == null) of(true, true, null, "", "")
        else of(true, true, bool(j, "allowed"), j.optString("code"), j.optString("reason"))

    private fun bool(j: JSONObject, key: String): Boolean? = j.opt(key) as? Boolean
}
