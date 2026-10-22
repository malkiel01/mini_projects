package com.mbeplus.guardedbrowser

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * בקשת פתיחה לאפליקציה — הצד של המכשיר (2026-10-22; השרת: lib/apprequests.php).
 *
 *   בכל תשובת מדיניות (PolicySync):
 *     1. reconcile — לפני Enforcer.apply: היתרים זמניים (apps.temp) נכנסים ל-store.appGrants, כך ש-AppPolicy
 *        מתיר אותם וה"בעל מכשיר" מסיר את ההשעיה. היתר שבוטל יורד (AppGrantMath).
 *     2. confirm — אחרי Enforcer.apply: לכל החלטה בודקים שהאפליקציה *באמת* פתוחה עכשיו (לא חסומה במדיניות, לא
 *        מושעה), שולחים אישור ביצוע לשרת (או כישלון עם הסיבה), ורק בהצלחה הילד מקבל הודעה. סירוב — הודעה לילד.
 *   ‏tick — סוף היתר זמני: נאכף בשעון האמין של המכשיר, גם בלי שרת (נקרא משירות הנגישות, גם בטיימר).
 */
object AppGrants {

    private const val CHANNEL = "app_requests"

    /** היתרים זמניים מהמדיניות. מחזיר את אלה שהתחילו עכשיו. שרת ישן (בלי temp) — לא נוגעים בקיים. */
    fun reconcile(store: Store, apps: JSONObject?): List<Int> {
        val arr = apps?.optJSONArray("temp") ?: return emptyList()
        val offers = (0 until arr.length()).map { i ->
            arr.getJSONObject(i).let {
                AppGrantMath.Offer(it.optInt("id"), it.optString("package"), it.optInt("minutes"),
                                   UsageMath.isoToMs(it.optString("ends_at")))
            }
        }
        val r = AppGrantMath.reconcile(store.appGrants, offers, TrustedClock.now(store))
        store.appGrants = r.grants
        if (r.dropped.isNotEmpty()) Trace.log("grants.drop", r.dropped.joinToString(","))
        return r.started
    }

    /** אחרי ההחלה: אישור ביצוע לכל החלטה שעוד לא אושרה, והודעה לילד. */
    fun confirm(ctx: Context, store: Store, apps: JSONObject?) {
        val list = apps?.optJSONArray("decisions") ?: return
        val acked = store.appHandled
        for (i in 0 until list.length()) {
            val d = list.getJSONObject(i)
            val id = d.optInt("id")
            val pkg = d.optString("package")
            if (id <= 0 || pkg.isEmpty() || id in acked) continue
            val status = d.optString("status")
            val state = d.optString("apply_state")
            when {
                status == "denied" -> {
                    if (store.appPending[pkg]?.first == id) notify(ctx, id, "🚫 ההורה לא אישר את ${label(ctx, pkg)}", "אפשר לבקש שוב מאוחר יותר")
                    done(store, id, pkg)
                }
                status == "approved" && state == "pending" -> {
                    val grant = store.appGrants[id]
                    // זמני שלא נמצא אצלנו (הסתיים / בוטל) — אין מה לאשר.
                    if (d.optString("scope") == "temporary" && grant == null) { done(store, id, pkg); continue }
                    val why = openNow(ctx, store, pkg)
                    if (why.isNotEmpty() && grant != null) store.appGrants = store.appGrants - id   // לא החלנו — לא שומרים היתר
                    ack(store, id, why, grant?.until ?: 0L)
                    if (why.isEmpty()) {
                        notify(ctx, id, "✅ ${label(ctx, pkg)} נפתחה",
                               if (grant != null) "עד ${hhmm(grant.until)} — ואז תיחסם שוב" else "ההורה פתח אותה")
                        store.appPending = store.appPending - pkg
                    }
                }
                else -> done(store, id, pkg)   // כבר הוחל / נכשל / בוטל
            }
        }
    }

    /** ‏"" — האפליקציה באמת פתוחה עכשיו; אחרת למה לא (לדיווח להורה). */
    fun openNow(ctx: Context, store: Store, pkg: String): String {
        if (AppPolicy.blocked(store, pkg)) return "עדיין חסומה במדיניות שבמכשיר"
        if (pkg in store.suspended) return "ההשעיה לא הוסרה (setPackagesSuspended)"
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            try { if (ctx.packageManager.isPackageSuspended(pkg)) return "האפליקציה עדיין מושעית במערכת" }
            catch (e: PackageManager.NameNotFoundException) { return "האפליקציה לא מותקנת במכשיר" }
            catch (e: Exception) { /* אי אפשר לבדוק — לא מכריזים כישלון */ }
        }
        return ""
    }

    /** היתרים שהסתיימו — יורדים, והחסימה חוזרת (גם השעיה ב"בעל מכשיר"). מחזיר את החבילות שנחסמו שוב. */
    fun tick(ctx: Context, store: Store): Set<String> {
        val ended = AppGrantMath.expired(store.appGrants, TrustedClock.now(store))
        if (ended.isEmpty()) return emptySet()
        store.appGrants = store.appGrants - ended.keys
        Trace.log("grants.end", ended.values.joinToString(",") { it.pkg })
        if (!store.released) Enforcer.apply(ctx, store)
        return ended.values.map { it.pkg }.toSet()
    }

    private fun ack(store: Store, id: Int, why: String, endsMs: Long) {
        Api.appRequestApplied(store.token, id, why.isEmpty(), why, endsMs) { r ->
            // אושר — או שהשרת כבר לא מחכה לזה (בוטל / נדחה): לא לשלוח שוב. רשת — ננסה בסנכרון הבא.
            if (r.ok || r.json?.optString("code") == "request_inactive") store.appHandled = store.appHandled + id
        }
    }

    private fun done(store: Store, id: Int, pkg: String) {
        store.appHandled = store.appHandled + id
        if (store.appPending[pkg]?.first == id) store.appPending = store.appPending - pkg
    }

    fun label(ctx: Context, pkg: String): String = try {
        val pm = ctx.packageManager
        pm.getApplicationLabel(pm.getApplicationInfo(pkg, 0)).toString()
    } catch (e: Exception) { pkg }

    private fun hhmm(ms: Long) = SimpleDateFormat("HH:mm", Locale("he")).format(Date(ms))

    private fun notify(ctx: Context, id: Int, title: String, text: String) {
        try {
            val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                nm.createNotificationChannel(NotificationChannel(CHANNEL, "תשובות מההורה", NotificationManager.IMPORTANCE_HIGH))
            }
            nm.notify(50_000 + id, NotificationCompat.Builder(ctx, CHANNEL)
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentTitle(title).setContentText(text)
                .setPriority(NotificationCompat.PRIORITY_HIGH).setAutoCancel(true).build())
        } catch (e: Exception) { Trace.log("grants.notify", e.javaClass.simpleName) }
    }
}
