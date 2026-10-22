package com.mbeplus.guardedbrowser

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.os.Build
import android.os.Bundle
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
 *
 *   הסתרה (בעל המערכת, 2026-10-22): מה שההורה סימן "מוסתרת" נעלם מהטלפון (setApplicationHidden) — אין אייקון,
 *   לא ברשימת האפליקציות. הנתונים נשמרים, וביטול מחזיר אותה. רק מה שחסום עכשיו (blockList) — לכן היתר זמני
 *   ושחרור מחזירים אותה, ומה שלעולם לא נחסם לעולם לא מוסתר.
 */
object Enforcer {

    /**
     * נעול תמיד במכשיר "בעל מכשיר" — ההגנה על מגדלור עצמו: איפוס, מצב בטוח, משתמש נוסף, אפשרויות מפתח.
     * לא תלוי בהורה.
     *
     * תקלה מדווחת (2026-10-22): גם "מקור לא ידוע" היה כאן — ולכן התקנת APK נחסמה תמיד, גם כשההורה התיר
     * "התקנת אפליקציות (מכל מקור)". עכשיו הוא הגדרה של ההורה (install_unknown), חסומה כברירת מחדל.
     */
    private val ALWAYS = listOf(
        UserManager.DISALLOW_FACTORY_RESET, UserManager.DISALLOW_SAFE_BOOT,
        UserManager.DISALLOW_ADD_USER, UserManager.DISALLOW_DEBUGGING_FEATURES,
    )

    /** התקנה מקובץ (APK): ההגבלה הרגילה, ומאנדרואיד 10 גם הגלובלית (חוסמת כל מקור במכשיר). */
    private val UNKNOWN_SOURCES = listOf(UserManager.DISALLOW_INSTALL_UNKNOWN_SOURCES) +
        (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) listOf(UserManager.DISALLOW_INSTALL_UNKNOWN_SOURCES_GLOBALLY) else emptyList())

    /** חנות האפליקציות — אם ההורה התיר התקנה והחנות חסומה ברשימת האפליקציות, מדווחים (blocked_by_apps). */
    private const val STORE = "com.android.vending"

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
        "install_unknown" to (UNKNOWN_SOURCES to true),
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

    /**
     * מחיל כל הגדרה, וקורא בחזרה מהמערכת את מה שבאמת חל — לכל מפתח תוצאה (SettingsMath): verified רק כשהמצב
     * שנקרא תואם לבחירת ההורה, גם כשההורה *התיר* (קודם נבדקו רק החסומות, ו-"ok" = "הקריאה לא זרקה").
     */
    private fun applySettings(ctx: Context, store: Store): Map<String, String> {
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)
        val calls = mutableMapOf<String, Boolean>()
        for ((key, v) in SETTINGS) {
            val on = blocked(store, key, v.second)
            calls[key] = v.first.map { r -> ok { if (on) dpm.addUserRestriction(admin, r) else dpm.clearUserRestriction(admin, r) } }.all { it }
        }
        val camOn = blocked(store, "camera", false)
        val shotOn = blocked(store, "screenshot", false)
        val camOk = ok { dpm.setCameraDisabled(admin, camOn) }
        val shotOk = ok { dpm.setScreenCaptureDisabled(admin, shotOn) }
        return results(ctx, store, calls, camOk, shotOk)
    }

    /**
     * קריאה חוזרת בלבד — בלי להחיל דבר (בדיקת הגנה יזומה, SelfTest): לכל הגדרה, האם מה שהמערכת מחזיקה עכשיו
     * תואם את בחירת ההורה. בלי "בעל מכשיר" — no_owner לכולן.
     */
    fun readBack(ctx: Context, store: Store): Map<String, String> {
        if (!isOwner(ctx)) return (SETTINGS.keys + listOf("camera", "screenshot")).associateWith { "no_owner" }
        return results(ctx, store, SETTINGS.keys.associateWith { true }, camOk = true, shotOk = true)
    }

    /** התוצאה לכל הגדרה מהמצב שנקרא בחזרה (SettingsMath). ‏calls — האם הקריאה למערכת הצליחה. */
    private fun results(ctx: Context, store: Store, calls: Map<String, Boolean>, camOk: Boolean, shotOk: Boolean): Map<String, String> {
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)
        val camOn = blocked(store, "camera", false)
        val shotOn = blocked(store, "screenshot", false)
        // הקריאה בחזרה — מה שהמערכת מחזיקה עכשיו מטעמנו.
        val now = try { dpm.getUserRestrictions(admin) } catch (e: Exception) { null }
        val updating = UpdateMath.installOpen(store.installOpenUntil, System.currentTimeMillis())
        val out = mutableMapOf<String, String>()
        for ((key, v) in SETTINGS) {
            val on = blocked(store, key, v.second)
            // באמצע עדכון של מגדלור הגבלות ההתקנה מוסרות זמנית — אי אפשר לאמת אותן עכשיו.
            val readable = now != null && !(updating && key.startsWith("install"))
            val matches = if (readable) SettingsMath.restrictionsMatch(on, v.first.map { now!!.getBoolean(it, false) }) else null
            out[key] = SettingsMath.result(true, v.first.isNotEmpty(), calls[key] == true, matches)
        }
        out["install_apps"] = SettingsMath.installApps(out["install_apps"] ?: "fail", blocked(store, "install_apps", false),
            storeInstalled(ctx) && AppPolicy.blocked(store, STORE, withTime = false))
        out["install_unknown"] = SettingsMath.installUnknown(out["install_unknown"] ?: "fail", blocked(store, "install_unknown", true))
        out["camera"] = SettingsMath.result(true, true, camOk, try { dpm.getCameraDisabled(admin) == camOn } catch (e: Exception) { null })
        out["screenshot"] = SettingsMath.result(true, true, shotOk, try { dpm.getScreenCaptureDisabled(admin) == shotOn } catch (e: Exception) { null })
        return out
    }

    /** "בעל מכשיר" חוסם את הסרת מגדלור עצמו? null — אי אפשר לבדוק. */
    fun uninstallBlocked(ctx: Context): Boolean? =
        if (!isOwner(ctx)) null else try { dpm(ctx).isUninstallBlocked(DeviceAdmin.component(ctx), ctx.packageName) } catch (e: Exception) { null }

    private fun storeInstalled(ctx: Context): Boolean =
        try { ctx.packageManager.getApplicationInfo(STORE, 0); true } catch (e: Exception) { false }

    /**
     * ההתקנה של עדכון (Updater) — "מקור לא ידוע" ו"התקנת אפליקציות" עלולים לחסום גם אותה, ולכן הם מוסרים
     * לרגע לפני ההתקנה, וחוזרים בכל כישלון (Updater.run, ‏InstallResult), אחרי הגרסה החדשה (Updater.recover),
     * ובכל מקרה תוך UpdateMath.OPEN_MS — גם כשהתהליך נהרג באמצע (שלב 9).
     */
    fun allowInstall(ctx: Context, on: Boolean) {
        // החלון נרשם לפני הפעולה: גם אם התהליך נהרג באמצע, ההחלה הבאה יודעת לסגור אותו (UpdateMath).
        Store(ctx).installOpenUntil = if (on) System.currentTimeMillis() + UpdateMath.OPEN_MS else 0L
        setInstall(ctx, on)
    }

    /** ההגבלות עצמן, בלי לגעת בחלון — apply משאיר פתוח חלון קיים בלי להאריך אותו. */
    private fun setInstall(ctx: Context, on: Boolean) {
        if (!isOwner(ctx)) return
        val store = Store(ctx)
        val admin = DeviceAdmin.component(ctx)
        if (on) {
            for (r in UNKNOWN_SOURCES + UserManager.DISALLOW_INSTALL_APPS) trySet { dpm(ctx).clearUserRestriction(admin, r) }
            return
        }
        // סיום העדכון — חזרה בדיוק לבחירת ההורה. קודם "מקור לא ידוע" חזר תמיד, גם כשההורה התיר אותו.
        val unknownOn = blocked(store, "install_unknown", true)
        val appsOn = blocked(store, "install_apps", false)
        for (r in UNKNOWN_SOURCES) trySet { if (unknownOn) dpm(ctx).addUserRestriction(admin, r) else dpm(ctx).clearUserRestriction(admin, r) }
        trySet { if (appsOn) dpm(ctx).addUserRestriction(admin, UserManager.DISALLOW_INSTALL_APPS) else dpm(ctx).clearUserRestriction(admin, UserManager.DISALLOW_INSTALL_APPS) }
    }

    fun isOwner(ctx: Context): Boolean =
        dpm(ctx).isDeviceOwnerApp(ctx.packageName)

    private fun dpm(ctx: Context) =
        ctx.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    /**
     * מפעיל את כל האכיפה ההרמטית ("בעל מכשיר"), ושולח דוח אמיתי — מה הוחל ומה נכשל (EnforceReport).
     * בטוח להרצה חוזרת. השעיה שנכשלה אינה נרשמת כמושעית, ולכן תנוסה שוב בהחלה הבאה.
     */
    fun apply(ctx: Context, store: Store) {
        if (!isOwner(ctx)) {
            store.chromeResult = if (store.appsManage && store.chromePolicy.optBoolean("managed")) "no_owner" else ""
            EnforceReport.send(ctx, store, emptyMap(), emptyList()); return
        }
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)

        // הגנה על עצמנו ועל המכשיר.
        trySet { dpm.setUninstallBlocked(admin, ctx.packageName, true) }
        // אפליקציה מושעית לא נפתחת, ואנדרואיד מציג "נחסם על ידי המנהל" — עם ההודעה הזו, שמפנה לבקשת פתיחה.
        trySet { dpm.setShortSupportMessage(admin, "ההורה חסם את האפליקציה. לבקשת פתיחה: מגדלור ← ⚙️ ← אפליקציות חסומות") }
        for (r in ALWAYS) trySet { dpm.addUserRestriction(admin, r) }
        val settings = applySettings(ctx, store)
        // עדכון באמצע התקנה — ההחלה לא סוגרת לו את ההתקנה מתחת לרגליים. חלון שפג — כבר נסגר כאן (ALWAYS).
        if (UpdateMath.installOpen(store.installOpenUntil, System.currentTimeMillis())) setInstall(ctx, true)
        else store.installOpenUntil = 0L

        if (!store.appsManage) {
            unsuspendAll(ctx, store)
            unhideAll(ctx, store)
            store.chromeResult = applyChrome(ctx, store)
            EnforceReport.send(ctx, store, settings, emptyList())
            return
        }

        // רשימת החבילות לחסום: ב-deny_all הכול חוץ מהמאושרות, אחרת רק המסומנות.
        val block = blockList(ctx, store)
        // הסתרה — רק מה שחסום עכשיו. הסדר: קודם מחזירים מוסתרות, אחר כך ההשעיה, ובסוף מסתירים — אנדרואיד לא
        // תמיד משעה או מחזיר חבילה מוסתרת. מה שנכשל לא נרשם, וינוסה שוב בהחלה הבאה (כמו ההשעיה).
        val toHide = store.appsHidden intersect block
        val wasHidden = store.hidden
        val showFail = setHidden(ctx, (wasHidden - toHide).toList(), false)

        val prev = store.suspended
        val failOn = suspend(ctx, (block - prev).toList(), true)
        val failOff = suspend(ctx, (prev - block).toList(), false)
        store.suspended = EnforceMath.nextSuspended(block, prev, failOn, failOff)
        if (failOn.isNotEmpty()) Trace.log("enforce.suspend_fail", failOn.joinToString(","))

        val hideFail = setHidden(ctx, (toHide - wasHidden).toList(), true)
        store.hidden = EnforceMath.nextSuspended(toHide, wasHidden, hideFail, showFail)
        if (hideFail.isNotEmpty()) Trace.log("enforce.hide_fail", hideFail.joinToString(","))
        store.chromeResult = applyChrome(ctx, store)
        EnforceReport.send(ctx, store, settings, (failOn + hideFail).distinct())
    }

    /** איזה חבילות צריכות להיות מושעות עכשיו. */
    private fun blockList(ctx: Context, store: Store): Set<String> =
        // ההגדרות לא מושעות לעולם — אחרת מגדלור לא יוכל לפתוח אותן להקמה ולעדכון. החסימה שלהן — בנגישות.
        rawBlockList(ctx, store) - AppPolicy.SETTINGS

    private fun rawBlockList(ctx: Context, store: Store): Set<String> {
        AppPolicy.scan(ctx, force = true)
        if (store.appsPosture != "deny_all") {
            // ועוד אפליקציות חדשות שנחסמות כברירת מחדל (NewAppMath) — עד שהשרת רושם עליהן כלל.
            return (store.appsBlocked + store.launchable.filter { NewAppMath.isNew(it, store.appsKnown, store.appsBaseline) })
                .filter { AppPolicy.blocked(store, it, withTime = false) }.toSet()
        }
        // deny_all: כל מה שניתן לפתוח, פרט למותר ולמה שלעולם לא נחסם.
        val out = mutableSetOf<String>()
        val main = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        for (ri in ctx.packageManager.queryIntentActivities(main, 0)) {
            val pkg = ri.activityInfo.packageName
            val sys = (ri.activityInfo.applicationInfo.flags and ApplicationInfo.FLAG_SYSTEM) != 0
            // אפליקציות מערכת לא מושעות ב-deny_all (סיכון לנעילת הטלפון); הן נחסמות רק אם סומנו.
            if (!sys && AppPolicy.blocked(store, pkg, withTime = false)) out.add(pkg)
        }
        out.addAll(store.appsBlocked.filter { AppPolicy.blocked(store, it, withTime = false) })
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
        unhideAll(ctx, store)
        clearChrome(ctx, store, emptySet())
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

    /**
     * משעה או מחזיר חבילות, ומחזיר את אלה שאנדרואיד לא הצליח (setPackagesSuspended מחזיר אותן —
     * פעם התעלמנו מזה, והן נרשמו כמושעות ולא נוסו שוב). חריגה = כולן נכשלו.
     */
    private fun suspend(ctx: Context, pkgs: List<String>, on: Boolean): List<String> {
        if (pkgs.isEmpty()) return emptyList()
        return try {
            dpm(ctx).setPackagesSuspended(DeviceAdmin.component(ctx), pkgs.toTypedArray(), on)?.toList() ?: emptyList()
        } catch (e: Exception) { Trace.log("enforce.fail", e.message ?: ""); pkgs }
    }

    private fun unsuspendAll(ctx: Context, store: Store) {
        store.suspended = suspend(ctx, store.suspended.toList(), false).toSet()
    }

    /**
     * מסתיר או מחזיר חבילות, ומחזיר את אלה שנכשלו. ‏setApplicationHidden מחזיר אם המצב אכן השתנה — לכן
     * נקרא בחזרה (isApplicationHidden): חבילה שכבר במצב המבוקש אינה כישלון.
     */
    private fun setHidden(ctx: Context, pkgs: List<String>, on: Boolean): List<String> {
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)
        return pkgs.filterNot { pkg ->
            try { dpm.setApplicationHidden(admin, pkg, on); dpm.isApplicationHidden(admin, pkg) == on }
            catch (e: Exception) { Trace.log("enforce.hide", "$pkg: ${e.message}"); false }
        }
    }

    /**
     * כרום מנוהל (lib/chrome.php, ‏ChromeMath): מעביר לכל כרום מותקן את המדיניות, וקורא בחזרה
     * (getApplicationRestrictions) — "verified" רק כשכל מפתח תואם. כבוי — מנקה את מה שהעביר קודם.
     * מחזיר את התוצאה לדוח: verified / fail / not_installed / ריק (לא מנוהל).
     */
    private fun applyChrome(ctx: Context, store: Store): String {
        val c = store.chromePolicy
        if (!store.appsManage || store.released || !c.optBoolean("managed")) { clearChrome(ctx, store, emptySet()); return "" }
        val all = c.optJSONArray("packages")?.let { a -> (0 until a.length()).map { a.optString(it) } } ?: emptyList()
        val pkgs = all.filter { installed(ctx, it) }.toSet()
        clearChrome(ctx, store, pkgs)
        if (pkgs.isEmpty()) return "not_installed"
        val list = { k: String -> c.optJSONArray(k)?.let { a -> (0 until a.length()).map { a.optString(it) } } ?: emptyList() }
        val spec = ChromeMath.spec(list("block"), list("allow"), c.optBoolean("youtube"))
        val bundle = Bundle().apply {
            for ((k, v) in spec) when (v) {
                is List<*> -> putStringArray(k, v.map { it.toString() }.toTypedArray())
                is Int -> putInt(k, v)
                is Boolean -> putBoolean(k, v)
            }
        }
        val dpm = dpm(ctx)
        val admin = DeviceAdmin.component(ctx)
        var allOk = true
        for (p in pkgs) {
            val set = ok { dpm.setApplicationRestrictions(admin, p, bundle) }
            val back = try { dpm.getApplicationRestrictions(admin, p) } catch (e: Exception) { null }
            val actual = spec.mapValues { (k, v) ->
                if (back == null || !back.containsKey(k)) null
                else when (v) { is List<*> -> back.getStringArray(k)?.toList(); is Int -> back.getInt(k); is Boolean -> back.getBoolean(k); else -> null }
            }
            if (!set || !ChromeMath.matches(spec, actual)) { allOk = false; Trace.log("enforce.chrome_fail", p) }
        }
        store.chromeApplied = pkgs
        return if (allOk) "verified" else "fail"
    }

    /** מנקה את מדיניות כרום מכל חבילה שהועברה לה ואינה ב-keep. */
    private fun clearChrome(ctx: Context, store: Store, keep: Set<String>) {
        val drop = store.chromeApplied - keep
        if (drop.isEmpty()) return
        for (p in drop) trySet { dpm(ctx).setApplicationRestrictions(DeviceAdmin.component(ctx), p, Bundle()) }
        store.chromeApplied = store.chromeApplied - drop
    }

    private fun installed(ctx: Context, pkg: String): Boolean =
        try { ctx.packageManager.getApplicationInfo(pkg, 0); true } catch (e: Exception) { false }

    private fun unhideAll(ctx: Context, store: Store) {
        store.hidden = setHidden(ctx, store.hidden.toList(), false).toSet()
    }

    private inline fun trySet(block: () -> Unit) {
        try { block() } catch (e: Exception) { Trace.log("enforce.fail", e.message ?: "") }
    }

    private inline fun ok(block: () -> Unit): Boolean =
        try { block(); true } catch (e: Exception) { Trace.log("enforce.fail", e.message ?: ""); false }
}
