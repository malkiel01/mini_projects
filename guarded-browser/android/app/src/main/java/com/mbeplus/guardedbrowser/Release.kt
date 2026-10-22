package com.mbeplus.guardedbrowser

import android.util.Base64
import org.json.JSONObject

/**
 * אימות אסימון השחרור החתום מהשרת (SPEC.md, סעיף 5 ו-13).
 *
 *   האסימון: b64u(JSON של התביעות) "." b64u(חתימת Ed25519). ההודעה שנחתמה היא בתי ה-JSON
 *   הגולמיים — לכן מפענחים את החלק הראשון ומאמתים אותו כפי שהוא, בלי לסדֵר מחדש.
 *
 *   שחרור מתקבל רק אם: החתימה תקפה מול המפתח שהוצמד, typ="release", ו-acct תואם לחשבון של
 *   המכשיר. כך "אין חיבור" אינו "פג תוקף", ואי אפשר לזייף שחרור או להחזיר שחרור של חשבון אחר.
 */
object Release {

    private fun b64u(s: String): ByteArray =
        Base64.decode(s, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP)

    /** התביעות אם החתימה תקפה מול pubKeyB64u, אחרת null. */
    fun claims(token: String, pubKeyB64u: String): JSONObject? {
        if (token.isEmpty() || pubKeyB64u.isEmpty()) return null
        val parts = token.split(".")
        if (parts.size != 2) return null
        return try {
            val jsonBytes = b64u(parts[0])
            val sig = b64u(parts[1])
            val pub = b64u(pubKeyB64u)
            if (!Ed25519.verify(pub, jsonBytes, sig)) return null
            JSONObject(String(jsonBytes, Charsets.UTF_8))
        } catch (e: Exception) { null }
    }

    /** ‏true אם זהו שחרור חתום, בתוקף, לחשבון ולילד שהוצמדו במכשיר (SyncRules.releaseClaimsOk). */
    fun isReleaseFor(token: String, pubKeyB64u: String, accountId: Int, childId: Int, nowSec: Long): Boolean {
        val c = claims(token, pubKeyB64u) ?: return false
        return SyncRules.releaseClaimsOk(c.optString("typ"), c.optInt("acct"), c.optInt("child"), c.optLong("exp"),
                                         accountId, childId, nowSec)
    }
}
