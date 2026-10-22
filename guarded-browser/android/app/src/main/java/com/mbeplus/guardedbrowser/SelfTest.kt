package com.mbeplus.guardedbrowser

import android.content.Context
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject

/**
 * בדיקת הגנה יזומה (שלב 12 ג; השרת: lib/selftest.php). ההורה מבקש, והמדיניות הבאה מביאה את הבקשה.
 *
 * בטוחה לילד: שום כתובת לא נטענת ושום אפליקציה לא נפתחת. הכתובות מוכרעות במנוע שהדפדפן משתמש בו
 * (PolicyEngine.evaluate על המדיניות השמורה ובשעון האמין); האפליקציות נקראות מהמערכת (isPackageSuspended);
 * ההגדרות נקראות בחזרה מ-DevicePolicyManager בלי להחיל דבר (Enforcer.readBack). נשלחים נתונים גולמיים —
 * השרת פוסק.
 */
object SelfTest {

    fun maybeRun(ctx: Context, store: Store, test: JSONObject?) {
        val id = test?.optString("id") ?: return
        if (!SelfTestMath.shouldRun(id, store.selftestDone) || !store.isLoggedIn) return
        val body = try { run(ctx, store, test, id) } catch (e: Exception) {
            Trace.log("selftest.fail", e.message ?: ""); return
        }
        Api.selftest(store.token, body) { r ->
            // נשמר רק כשהשרת קיבל — אחרת המדיניות הבאה תביא את אותה בקשה, וננסה שוב.
            if (r.ok) store.selftestDone = id else Trace.log("selftest.send", r.error)
        }
    }

    private fun run(ctx: Context, store: Store, test: JSONObject, id: String): JSONObject {
        val owner = Enforcer.isOwner(ctx)
        val access = Setup.accessibilityOn(ctx)
        val now = TrustedClock.now(store)

        val web = JSONArray()
        val cases = test.optJSONArray("web") ?: JSONArray()
        val policy = store.policy()
        val set = store.ruleSet()
        for (i in 0 until minOf(cases.length(), 10)) {
            val c = cases.optJSONObject(i) ?: continue
            val url = c.optString("url")
            // זמן שנוצל — 0, כמו בשרת: בודקים את הכללים; השעה עצמה כן נכנסת (‏SELFTEST_TIME_CODES בשרת).
            val v = PolicyEngine.evaluate(policy, set, url, true, 0, 0, "", now)
            web.put(JSONObject().put("url", url).put("what", c.optString("what"))
                .put("want_allow", c.optBoolean("allow")).put("want_code", c.optString("code"))
                .put("got_allow", v.allow).put("got_code", v.code))
        }

        val apps = JSONArray()
        val list = test.optJSONArray("apps") ?: JSONArray()
        val pm = ctx.packageManager
        for (i in 0 until minOf(list.length(), 10)) {
            val a = list.optJSONObject(i) ?: continue
            val pkg = a.optString("p")
            val installed = try { pm.getApplicationInfo(pkg, 0); true } catch (e: Exception) { false }
            val suspended = if (installed && Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q)
                try { pm.isPackageSuspended(pkg) } catch (e: Exception) { null } else null
            apps.put(JSONObject().put("p", pkg).put("n", a.optString("n"))
                .put("state", SelfTestMath.appState(installed, AppPolicy.blocked(store, pkg, withTime = false), suspended, access)))
        }

        return JSONObject()
            .put("id", id)
            .put("owner", owner)
            .put("access", access)
            .put("uninstall", Enforcer.uninstallBlocked(ctx) ?: JSONObject.NULL)
            .put("sync_ago_sec", SelfTestMath.syncAgoSec(now, store.lastSyncMs))
            .put("version", BuildConfig.VERSION_CODE)
            .put("web", web)
            .put("apps", apps)
            .put("settings", JSONObject(Enforcer.readBack(ctx, store)))
    }
}
