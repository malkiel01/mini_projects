package com.mbeplus.guardedbrowser

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import org.json.JSONArray
import org.json.JSONObject

/**
 * דוח האכיפה לשרת (api do=enforce_report → lib/enforce.php): מה הוחל בפועל ומה נכשל, אילו דפדפנים
 * יש במכשיר ואם כל אחד חסום, נגישות ו"בעל מכשיר" כפי שהם עכשיו, ועל איזו גרסת מדיניות (rev).
 * ההורה רואה "נאכף" רק על סמך הדוח הזה — לא על סמך ביט הרשאה.
 */
object EnforceReport {

    fun send(ctx: Context, store: Store, settings: Map<String, String>, suspendFailed: List<String>) {
        if (!store.isLoggedIn) return
        val access = Setup.accessibilityOn(ctx)
        val owner = Enforcer.isOwner(ctx)
        val body = JSONObject()
            .put("rev", store.rev)
            .put("owner", owner)
            .put("access", access)
            // בלי "בעל מכשיר" אין החלה של הגדרות מכשיר כלל — מדווחים את זה במפורש.
            .put("settings", JSONObject(if (owner) settings else settings.mapValues { "no_owner" }))
            .put("suspend_failed", JSONArray(suspendFailed))
            .put("browsers", browsers(ctx, store, access))
        val hash = body.toString().hashCode().toString()
        val now = TrustedClock.now(store)
        if (!EnforceMath.shouldSend(store.enforceHash, hash, store.enforceAt, now)) return
        Api.enforceReport(store.token, body) { r ->
            if (r.ok) { store.enforceHash = hash; store.enforceAt = now }
        }
    }

    /** כל דפדפן אחר במכשיר, ואם הוא חסום בפועל: לפי המדיניות, וגם אוכף — נגישות פעילה או השעיה. */
    private fun browsers(ctx: Context, store: Store, access: Boolean): JSONArray {
        val out = JSONArray()
        val pm = ctx.packageManager
        val view = Intent(Intent.ACTION_VIEW, Uri.parse("https://example.com")).addCategory(Intent.CATEGORY_BROWSABLE)
        val seen = mutableSetOf<String>()
        try {
            for (ri in pm.queryIntentActivities(view, PackageManager.MATCH_ALL)) {
                val pkg = ri.activityInfo.packageName
                if (pkg == ctx.packageName || !seen.add(pkg)) continue
                val blocked = AppPolicy.blocked(store, pkg, withTime = false) && (access || pkg in store.suspended)
                out.put(JSONObject().put("p", pkg).put("n", ri.loadLabel(pm).toString()).put("blocked", blocked))
            }
        } catch (e: Exception) { Trace.log("report.browsers", e.message ?: "") }
        return out
    }
}
