package com.mbeplus.guardedbrowser

import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import org.json.JSONArray
import org.json.JSONObject

/**
 * דיווח האפליקציות המותקנות (עם מסך פתיחה) לשרת (api do=apps) — כדי שההורה יכריע עליהן. נשלח בפתיחת מגדלור
 * (HomeActivity), וכשהותקנה אפליקציה חדשה (AppPolicy.scan) — כך היא מופיעה אצל ההורה, כבר מוכרעת לפי new_apps.
 */
object AppsReport {

    fun send(ctx: Context) {
        val store = Store(ctx)
        if (!store.isLoggedIn || !store.appsManage) return
        Thread {
            try {
                val pm = ctx.packageManager
                val main = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
                val arr = JSONArray()
                for (ri in pm.queryIntentActivities(main, 0)) {
                    val pkg = ri.activityInfo.packageName
                    if (pkg == store.ownPackage) continue
                    val sys = (ri.activityInfo.applicationInfo.flags and ApplicationInfo.FLAG_SYSTEM) != 0
                    arr.put(JSONObject().put("p", pkg).put("n", ri.loadLabel(pm).toString()).put("s", if (sys) 1 else 0))
                }
                if (arr.length() > 0) Api.apps(store.token, arr) { }
            } catch (e: Exception) { Trace.log("reportApps.fail", e.message ?: "") }
        }.start()
    }
}
