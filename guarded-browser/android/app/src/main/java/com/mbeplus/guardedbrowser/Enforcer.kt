package com.mbeplus.guardedbrowser

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.os.Build
import android.os.UserManager

/**
 * מנוע האכיפה (שלב 11): מפעיל את האכיפה החזקה ביותר שהמכשיר מאפשר, לכל פרמטר.
 *
 *   כשמגדלור הוא "בעל מכשיר" (Device Owner) — אכיפה הרמטית: השעיית אפליקציות חסומות (הן לא
 *   נפתחות בכלל), חסימת הסרת מגדלור, מניעת איפוס להגדרות יצרן, מניעת מצב בטוח והוספת משתמשים.
 *   בלי בעל מכשיר — שירות הנגישות עושה אכיפה רכה (מסך חסימה), וזו עדיין הגנה אמיתית.
 *
 *   מה *לעולם לא* מושעה: מגדלור, המסך הראשי, המערכת, חייגן (AppPolicy.ALWAYS), וההגדרות — חסימה שלהן
 *   רק בנגישות, כדי שמגדלור יוכל לפתוח אותן להקמה ולעדכון (AppPolicy.openSettings).
 */
object Enforcer {

    /**
     * נעול תמיד במכשיר "בעל מכשיר" — ההגנה על מגדלור עצמו: איפוס, מצב בטוח, משתמש נוסף, אפשרויות מפתח
     * ומקור לא ידוע. לא תלוי בהורה.
     */
    private val ALWAYS = listOf(
        UserManager.DISALLOW_FACTORY_RESET, UserManager.DISALLOW_SAFE_BOOT,
        UserManager.DISALLOW_ADD_USER, UserManager.DISALLOW_DEBUGGING_FEATURES,
        UserManager.DISALLOW_INSTALL_UNKNOWN_SOURCES,
    )

    /**
     * הגדרות המכשיר שההורה קובע (lib/devicesettings.php — אותם מפתחות): מפתח → [ההגבלות, חסום כברירת מחדל].
     * ברירת המחדל — כשהשרת עוד לא שלח (גרסה ישנה): מה שהיה נעול לפני הפאנל. מצלמה וצילום מסך אינם
     * הגבלות משתמש — הם ב-applySettings.
     */
    private val SETTINGS: Map<String, Pair<List<String>, Boolean>> = mapOf(
        "wifi" to (listOf(UserManager.DISALLOW_CONFIG_WIFI) to false),
        "mobile_data" to (listOf(UserManager.DISALLOW_CONFIG_MOBILE_NETWORKS) to false),
        "tethering" to (listOf(UserManager.DISALLOW_CONFIG_TETHERING) to false),
        "bluetooth" to ((listOf(UserManager.DISALLOW_CONFIG_BLUETOOTH) +
            (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) listOf(UserManager.DISALLOW_BLUETOOTH) else emptyList())) to false),
        "location" to ((listOf(UserManager.DISALLOW_SHARE_LOCATION) +
            (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) listOf(UserManager.DISALLOW_CONFIG_LOCATION) else emptyList())) to true),
        "usb" to (listOf(UserManager.DISALLOW_USB_FILE_TRANSFER) to false),
        "install_apps" to (listOf(UserManager.DISALLOW_INSTALL_APPS) to false),
        "uninstall_apps" to (listOf(UserManager.DISALLOW_UNINSTALL_APPS) to false),
        "date_time" to ((if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) listOf(UserManager.DISALLOW_CONFIG_DATE_TIME) else emptyList()) to true),
        "accounts" to (listOf(UserManager.DISALLOW_MODIFY_ACCOUNTS) to true),
        "vpn" to (listOf(UserManager.DISALLOW_CONFIG_VPN) to true),
    )

    private fun blocked(store: Store, key: String, default: Boolean) = store.deviceSetting(key) ?: default

    /** מפעיל או מסיר כל הגדרה לפי מה שההורה בחר. */
    /**
     * המצלמה פתוחה זמנית — לסריקת קוד מההורה כשההורה חסם את המצלמה (OfflinePolicy). אחרי הסריקה
     * Enforcer.apply מחזיר את החסימה.
     */
    fun allowCamera(ctx: Context) {
        if (!isOwner(ctx)) return
        trySet { dpm(ctx).setCameraDisabled(DeviceAdmin.component(ctx), false) }
    }

    private fun applySettings(ctx: Context, store: Store) {
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)
        for ((key, v) in SETTINGS) {
            val on = blocked(store, key, v.second)
            for (r in v.first) trySet { if (on) dpm.addUserRestriction(admin, r) else dpm.clearUserRestriction(admin, r) }
        }
        trySet { dpm.setCameraDisabled(admin, blocked(store, "camera", false)) }
        trySet { dpm.setScreenCaptureDisabled(admin, blocked(store, "screenshot", false)) }
    }

    /**
     * ההתקנה של עדכון (Updater) — "מקור לא ידוע" ו"התקנת אפליקציות" עלולים לחסום גם אותה, ולכן הם מוסרים
     * לרגע לפני ההתקנה, וחוזרים בכישלון (Updater.InstallResult) או ב-apply הבא אחרי הגרסה החדשה.
     */
    fun allowInstall(ctx: Context, on: Boolean) {
        if (!isOwner(ctx)) return
        val admin = DeviceAdmin.component(ctx)
        val store = Store(ctx)
        val keys = listOf(UserManager.DISALLOW_INSTALL_UNKNOWN_SOURCES) +
            (if (on || blocked(store, "install_apps", false)) listOf(UserManager.DISALLOW_INSTALL_APPS) else emptyList())
        for (r in keys) trySet { if (on) dpm(ctx).clearUserRestriction(admin, r) else dpm(ctx).addUserRestriction(admin, r) }
    }

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
        for (r in ALWAYS) trySet { dpm.addUserRestriction(admin, r) }
        applySettings(ctx, store)

        if (!store.appsManage) { unsuspendAll(ctx, store); return }

        // רשימת החבילות לחסום: ב-deny_all הכול חוץ מהמאושרות, אחרת רק המסומנות.
        val block = blockList(ctx, store)
        val prev = store.suspended
        suspend(ctx, (block - prev).toList(), true)
        suspend(ctx, (prev - block).toList(), false)
        store.suspended = block
    }

    /** איזה חבילות צריכות להיות מושעות עכשיו. */
    private fun blockList(ctx: Context, store: Store): Set<String> =
        // ההגדרות לא מושעות לעולם — אחרת מגדלור לא יוכל לפתוח אותן להקמה ולעדכון. החסימה שלהן — בנגישות.
        rawBlockList(ctx, store) - AppPolicy.SETTINGS

    private fun rawBlockList(ctx: Context, store: Store): Set<String> {
        AppPolicy.scan(ctx, force = true)
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

    /**
     * שחרור (SPEC סעיף 5): המנוי הסתיים — מסירים כל אכיפה הרמטית, אבל נשארים "בעל מכשיר"
     * כדי שהארכה תחזיר אכיפה בלי התקנה מחדש. ‏relinquish() מוותר גם על הבעלות עצמה.
     */
    fun release(ctx: Context, store: Store) {
        if (!isOwner(ctx)) return
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)
        unsuspendAll(ctx, store)
        trySet { dpm.setUninstallBlocked(admin, ctx.packageName, false) }
        for (r in ALWAYS + SETTINGS.values.flatMap { it.first }) trySet { dpm.clearUserRestriction(admin, r) }
        trySet { dpm.setCameraDisabled(admin, false) }
        trySet { dpm.setScreenCaptureDisabled(admin, false) }
    }

    /** ההורה מחק את הילדים: מגדלור מסיר את עצמו כבעל מכשיר, והמכשיר חוזר לטלפון רגיל. */
    fun relinquish(ctx: Context, store: Store) {
        release(ctx, store)
        trySet { if (isOwner(ctx)) dpm(ctx).clearDeviceOwnerApp(ctx.packageName) }
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
