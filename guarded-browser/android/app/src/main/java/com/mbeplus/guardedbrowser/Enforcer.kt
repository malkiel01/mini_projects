package com.mbeplus.guardedbrowser

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.os.UserManager

/**
 * מנוע האכיפה (שלב 11): מפעיל את האכיפה החזקה ביותר שהמכשיר מאפשר, לכל פרמטר.
 *
 *   כשמגדלור הוא "בעל מכשיר" (Device Owner) — אכיפה הרמטית: השעיית אפליקציות חסומות (הן לא
 *   נפתחות בכלל), חסימת הסרת מגדלור, מניעת איפוס להגדרות יצרן, מניעת מצב בטוח והוספת משתמשים.
 *   בלי בעל מכשיר — שירות הנגישות עושה אכיפה רכה (מסך חסימה), וזו עדיין הגנה אמיתית.
 *
 *   מה *לעולם לא* מושעה: מגדלור, המסך הראשי, המערכת, חייגן, הגדרות (AppPolicy.ALWAYS).
 */
object Enforcer {

    fun isOwner(ctx: Context): Boolean =
        dpm(ctx).isDeviceOwnerApp(ctx.packageName)

    private fun dpm(ctx: Context) =
        ctx.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    /** מפעיל את כל האכיפה ההרמטית. בטוח להרצה חוזרת. */
    fun apply(ctx: Context, store: Store) {
        if (!isOwner(ctx)) return
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)

        // הגנה על עצמנו ועל המכשיר.
        trySet { dpm.setUninstallBlocked(admin, ctx.packageName, true) }
        for (r in listOf(UserManager.DISALLOW_FACTORY_RESET, UserManager.DISALLOW_SAFE_BOOT,
                         UserManager.DISALLOW_ADD_USER, UserManager.DISALLOW_DEBUGGING_FEATURES)) {
            trySet { dpm.addUserRestriction(admin, r) }
        }

        if (!store.appsManage) { unsuspendAll(ctx, store); return }

        // רשימת החבילות לחסום: ב-deny_all הכול חוץ מהמאושרות, אחרת רק המסומנות.
        val block = blockList(ctx, store)
        val prev = store.suspended
        suspend(ctx, (block - prev).toList(), true)
        suspend(ctx, (prev - block).toList(), false)
        store.suspended = block
    }

    /** איזה חבילות צריכות להיות מושעות עכשיו. */
    private fun blockList(ctx: Context, store: Store): Set<String> {
        if (store.appsPosture != "deny_all") {
            return store.appsBlocked.filter { AppPolicy.blocked(store, it) }.toSet()
        }
        // deny_all: כל מה שניתן לפתוח, פרט למותר ולמה שלעולם לא נחסם.
        val out = mutableSetOf<String>()
        val main = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        for (ri in ctx.packageManager.queryIntentActivities(main, 0)) {
            val pkg = ri.activityInfo.packageName
            val sys = (ri.activityInfo.applicationInfo.flags and ApplicationInfo.FLAG_SYSTEM) != 0
            // אפליקציות מערכת לא מושעות ב-deny_all (סיכון לנעילת הטלפון); הן נחסמות רק אם סומנו.
            if (!sys && AppPolicy.blocked(store, pkg)) out.add(pkg)
        }
        out.addAll(store.appsBlocked.filter { AppPolicy.blocked(store, it) })
        return out
    }

    private fun suspend(ctx: Context, pkgs: List<String>, on: Boolean) {
        if (pkgs.isEmpty()) return
        trySet { dpm(ctx).setPackagesSuspended(DeviceAdmin.component(ctx), pkgs.toTypedArray(), on) }
    }

    private fun unsuspendAll(ctx: Context, store: Store) {
        suspend(ctx, store.suspended.toList(), false)
        store.suspended = emptySet()
    }

    private inline fun trySet(block: () -> Unit) {
        try { block() } catch (e: Exception) { Trace.log("enforce.fail", e.message ?: "") }
    }
}
