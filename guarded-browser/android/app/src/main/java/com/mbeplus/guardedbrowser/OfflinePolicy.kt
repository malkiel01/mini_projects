package com.mbeplus.guardedbrowser

import android.content.Context
import org.json.JSONObject

/**
 * העברה בלי אינטרנט: ההורה מציג QR (‏migdalor:policy?t=TOKEN), והמכשיר סורק ומפעיל — גם כשאין לו רשת.
 *
 *   האסימון חתום בשרת (lib/signing.php, offlineToken), ומאומת כאן מול המפתח שהוצמד בצימוד (Release.claims).
 *   מתקבל רק אם: typ="offline", החשבון והילד של המכשיר, exp עוד לא עבר, ו-iat חדש מהקוד האחרון שהופעל
 *   (כך אי אפשר להחזיר קוד ישן שצולם). מפעיל את הגדרות המכשיר ואת מדיניות האפליקציות, בדיוק כמו
 *   מדיניות מהשרת — וכשהמכשיר חוזר לרשת, המדיניות מהשרת זהה.
 */
object OfflinePolicy {

    private const val PREFIX = "migdalor:policy?t="

    /** האם זה קוד של העברה בלי אינטרנט (ולא קוד צימוד). */
    fun isCode(text: String) = text.trim().startsWith(PREFIX)

    /** מפעיל את הקוד. מחזיר הודעה לילד; ‏ok=false — הקוד נדחה ולא שונה דבר. */
    fun apply(ctx: Context, text: String, now: Long = System.currentTimeMillis() / 1000): Pair<Boolean, String> {
        val store = Store(ctx)
        val c = Release.claims(text.trim().removePrefix(PREFIX), store.signPubKey)
            ?: return false to "הקוד לא תקין — פתחו אותו שוב באפליקציית ההורה."
        if (c.optString("typ") != "offline") return false to "זה לא קוד הגדרות של מגדלור."
        if (store.accountId <= 0 || c.optInt("acct") != store.accountId ||
            (store.childId > 0 && c.optInt("child") != store.childId)) {
            return false to "הקוד הזה שייך לילד אחר."
        }
        if (c.optLong("exp") < now) return false to "פג תוקף הקוד — פתחו אותו שוב באפליקציית ההורה."
        val iat = c.optLong("iat")
        if (iat <= store.offlineIat) return false to "הקוד הזה כבר הופעל. לשינוי נוסף — פותחים קוד חדש."

        c.optJSONObject("dev")?.let { store.saveDevice(it) }
        c.optJSONObject("apps")?.let { a ->
            // קוד חלקי (רשימות ארוכות מדי ל-QR): רק ברירת המחדל משתנה, והרשימות שכבר במכשיר נשארות.
            if (a.optBoolean("partial")) {
                store.saveApps(JSONObject().put("manage", a.optBoolean("manage")).put("posture", a.optString("posture"))
                    .put("blocked", org.json.JSONArray(store.appsBlocked.toList()))
                    .put("allowed", org.json.JSONArray(store.appsAllowed.toList())))
            } else store.saveApps(a)
        }
        store.offlineIat = iat
        if (!store.released) Enforcer.apply(ctx, store)
        Trace.log("offline.apply", "iat=$iat")
        return true to "ההגדרות מההורה הופעלו ✓"
    }
}
