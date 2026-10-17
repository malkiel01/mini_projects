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
 * מה שנדרש כאן קיים בכל התקנה רגילה. שליטה מלאה על כל אפליקציות המכשיר = "בעל המכשיר"
 * (שלב 11), שדורש התקנה על מכשיר נקי ולכן אינו חלק מהמסלול הזה.
 */
object Setup {

    /** ביטים לדיווח לשרת (X-Migdalor-Perms), כדי שההורה יראה מה ניתן. */
    const val NOTIF = 1
    const val BATTERY = 2
    const val ADMIN = 4
    const val INSTALL = 8
    const val ACCESS = 16
    const val OWNER = 32

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
            "כדי שמגדלור יוכל להציג שהוא פועל, ולהודיע כשמשהו נחסם.", true,
            { ctx -> Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
                ctx.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED },
            { act ->
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                    act.requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 71)
            }),

        Step(BATTERY, "🔋", "ריצה רציפה",
            "בלי זה אנדרואיד עלול לעצור את מגדלור ברקע — וההגבלות והזמן יפסיקו להיאכף.", true,
            { ctx -> (ctx.getSystemService(Context.POWER_SERVICE) as PowerManager)
                .isIgnoringBatteryOptimizations(ctx.packageName) },
            { act ->
                try {
                    act.startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                        Uri.parse("package:" + act.packageName)))
                } catch (e: Exception) {
                    act.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
                }
            }),

        Step(ADMIN, "🛡️", "הגנה על ההגדרות",
            "מגדיר את מגדלור כמנהל מכשיר: אי אפשר להסיר אותו בלי ההורה, ואפשר לנעול את המסך. מופיע בהגדרות — שקוף לגמרי.", false,
            { ctx -> DeviceAdmin.active(ctx) },
            { act -> act.startActivity(DeviceAdmin.enableIntent(act)) }),

        Step(ACCESS, "📲", "ניהול אפליקציות",
            "כדי שמגדלור יוכל לחסום אפליקציות אחרות שההורה חסם. נדרש בחשבון שמנהל אפליקציות.",
            Store(ctx).appsManage,
            { c -> accessibilityOn(c) },
            { act -> try { act.startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)) } catch (e: Exception) {} }),

        Step(INSTALL, "⬆️", "עדכונים",
            "כדי שמגדלור יוכל להתקין עדכון שההורה שולח, בלי להוריד ידנית.", false,
            { ctx -> Build.VERSION.SDK_INT < Build.VERSION_CODES.O || ctx.packageManager.canRequestPackageInstalls() },
            { act ->
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O)
                    try { act.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:" + act.packageName))) } catch (e: Exception) {}
            }),
    )

    fun pendingRequired(ctx: Context): List<Step> = steps(ctx).filter { it.required && !it.done(ctx) }

    /** מצב כל ההרשאות כמספר ביטים — לדיווח ולזיכרון במכשיר. */
    fun flags(ctx: Context): Int {
        var f = steps(ctx).fold(0) { acc, s -> if (s.done(ctx)) acc or s.key else acc }
        if (Enforcer.isOwner(ctx)) f = f or OWNER   // בעל מכשיר — מדווח גם הוא
        return f
    }

    /** האם כל החיוניות ניתנו — לשער הכניסה. */
    fun ready(ctx: Context): Boolean = pendingRequired(ctx).isEmpty()

    /** האם שירות הנגישות של מגדלור דלוק. */
    fun accessibilityOn(ctx: Context): Boolean {
        val want = ctx.packageName + "/" + AppGuardService::class.java.name
        val flat = Settings.Secure.getString(ctx.contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES) ?: return false
        return flat.split(':').any { it.equals(want, true) ||
            (it.startsWith(ctx.packageName + "/", true) && it.endsWith("AppGuardService", true)) }
    }
}
