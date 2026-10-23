package com.mbeplus.guardedbrowser

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings

/**
 * מסלול ההקמה הראשוני (שער אחרי הצימוד).
 *
 * כל שלב מבקש הרשאה דרך מסך האישור של אנדרואיד עצמו — הילד, עם ההורה לידו, רואה ומאשר.
 * שלבים חיוניים (required) חוסמים את הכניסה למגדלור עד שהם ניתנו; מומלצים — מוצגים אבל
 * אפשר להמשיך בלעדיהם, וההורה רואה בצד שלו מה חסר.
 *
 * כל ההרשאות שאפליקציה יכולה לבקש — חובה (בעל המערכת, 2026-10-20): בלי כולן אין כניסה למגדלור.
 * שליטה מלאה, גם בהגדרות המערכת = "בעל המכשיר" (Enforcer), שאנדרואיד מאפשר רק בהקמה של מכשיר מאופס
 * (QR מההורה) — ולכן אינו שלב כאן. במכשיר כזה ניהול המכשיר והעדכונים כבר מכוסים.
 */
object Setup {

    /** ביטים לדיווח לשרת (X-Migdalor-Perms), כדי שההורה יראה מה ניתן. */
    const val NOTIF = 1
    const val BATTERY = 2
    const val ADMIN = 4
    const val INSTALL = 8
    const val ACCESS = 16
    const val OWNER = 32
    /** מגדלור הוא מסך הבית של הטלפון (isHome) — לא חובה; כך 👻 הסתרה עובדת גם בלי "בעל מכשיר". */
    const val HOME = 64

    class Step(
        val key: Int,
        val icon: String,
        val title: String,
        val desc: String,
        val required: Boolean,
        val done: (Context) -> Boolean,
        val open: (Activity) -> Unit,
    )

    fun steps(ctx: Context): List<Step> = listOf(
        Step(NOTIF, "🔔", "התראות",
            "כדי שמגדלור יוכל להציג שהוא פועל, להודיע כשמשהו נחסם, ולהקפיץ את מסך העדכון לבד.", true,
            { ctx -> notifGranted(ctx) && fullScreenGranted(ctx) },
            { act ->
                // קודם ההתראות עצמן, ואז (אנדרואיד 14+) ההיתר להקפיץ מסך מלא — בלעדיו מסך העדכון
                // לא קופץ לבד כשמגדלור סגור, אלא יורד להתראה רגילה.
                if (!notifGranted(act)) {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                        act.requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 71)
                } else if (!fullScreenGranted(act) && Build.VERSION.SDK_INT >= 34) {
                    try {
                        AppPolicy.openSettings(act, Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT,
                            Uri.parse("package:" + act.packageName)))
                    } catch (e: Exception) {}
                }
            }),

        Step(BATTERY, "🔋", "ריצה רציפה",
            "בלי זה אנדרואיד עלול לעצור את מגדלור ברקע — וההגבלות והזמן יפסיקו להיאכף.", true,
            { ctx -> (ctx.getSystemService(Context.POWER_SERVICE) as PowerManager)
                .isIgnoringBatteryOptimizations(ctx.packageName) },
            { act ->
                try {
                    AppPolicy.openSettings(act, Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                        Uri.parse("package:" + act.packageName)))
                } catch (e: Exception) {
                    AppPolicy.openSettings(act, Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
                }
            }),

        Step(ADMIN, "🛡️", "הגנה על ההגדרות",
            "מגדיר את מגדלור כמנהל מכשיר: ההסרה הופכת לפעולה מכוונת (צריך קודם לבטל את הניהול), " +
            "ומגדלור מזהה ומחזיר מהמסך הזה. הגנה מלאה מהסרה דורשת \"בעל מכשיר\". מופיע בהגדרות — שקוף לגמרי.", true,
            { ctx -> Enforcer.isOwner(ctx) || DeviceAdmin.active(ctx) },
            { act -> AppPolicy.openSettings(act, DeviceAdmin.enableIntent(act)) }),

        Step(ACCESS, "📲", "ניהול אפליקציות",
            "כדי שמגדלור יוכל לחסום אפליקציות אחרות שההורה חסם, ולהקפיץ את מסך העדכון.",
            true,
            { c -> accessibilityOn(c) },
            { act -> try { AppPolicy.openSettings(act, Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)) } catch (e: Exception) {} }),

        Step(INSTALL, "⬆️", "עדכונים",
            "כדי שמגדלור יוכל להתקין את העדכונים — עדכון הוא חובה.", true,
            { ctx -> Enforcer.isOwner(ctx) || Build.VERSION.SDK_INT < Build.VERSION_CODES.O || ctx.packageManager.canRequestPackageInstalls() },
            { act ->
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O)
                    try { AppPolicy.openSettings(act, Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:" + act.packageName))) } catch (e: Exception) {}
            }),
    )

    fun pendingRequired(ctx: Context): List<Step> = steps(ctx).filter { it.required && !it.done(ctx) }

    /** מצב כל ההרשאות כמספר ביטים — לדיווח ולזיכרון במכשיר. */
    fun flags(ctx: Context): Int {
        var f = steps(ctx).fold(0) { acc, s -> if (s.done(ctx)) acc or s.key else acc }
        if (Enforcer.isOwner(ctx)) f = f or OWNER   // בעל מכשיר — מדווח גם הוא
        if (isHome(ctx)) f = f or HOME
        return f
    }

    /** האם מגדלור הוא מסך הבית שנבחר (ברירת המחדל של "בית"). */
    fun isHome(ctx: Context): Boolean = try {
        ctx.packageManager.resolveActivity(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME), PackageManager.MATCH_DEFAULT_ONLY)
            ?.activityInfo?.packageName == ctx.packageName
    } catch (e: Exception) { false }

    /**
     * בחירת מגדלור כמסך הבית. מאנדרואיד 10 — חלון הבחירה של המערכת (RoleManager), בלי לפתוח את ההגדרות לילד;
     * לפני כן — מסך "אפליקציית בית" בהגדרות, בחלון הרגיל של מגדלור (AppPolicy.openSettings).
     */
    fun requestHome(act: android.app.Activity) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val rm = act.getSystemService(android.app.role.RoleManager::class.java)
                if (rm != null && rm.isRoleAvailable(android.app.role.RoleManager.ROLE_HOME)) {
                    act.startActivityForResult(rm.createRequestRoleIntent(android.app.role.RoleManager.ROLE_HOME), 7101)
                    return
                }
            }
            AppPolicy.openSettings(act, Intent(android.provider.Settings.ACTION_HOME_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (e: Exception) { Trace.log("setup.home", e.message ?: "") }
    }

    /** האם כל החיוניות ניתנו — לשער הכניסה. */
    fun ready(ctx: Context): Boolean = pendingRequired(ctx).isEmpty()

    /** האם הותרו התראות (מאנדרואיד 13 צריך היתר מפורש). */
    fun notifGranted(ctx: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ctx.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    /**
     * האם מותר להקפיץ התראת מסך מלא. מאנדרואיד 14 זהו היתר נפרד (app-op) שברירת המחדל שלו כבויה
     * לאפליקציות שאינן שיחה/שעון — ובלעדיו מסך העדכון החובה לא קופץ לבד כשמגדלור סגור.
     */
    fun fullScreenGranted(ctx: Context): Boolean {
        if (Build.VERSION.SDK_INT < 34) return true
        return try {
            (ctx.getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager)
                .canUseFullScreenIntent()
        } catch (e: Exception) { true }
    }

    /** האם שירות הנגישות של מגדלור דלוק. */
    fun accessibilityOn(ctx: Context): Boolean {
        val want = ctx.packageName + "/" + AppGuardService::class.java.name
        val flat = Settings.Secure.getString(ctx.contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES) ?: return false
        return flat.split(':').any { it.equals(want, true) ||
            (it.startsWith(ctx.packageName + "/", true) && it.endsWith("AppGuardService", true)) }
    }
}
