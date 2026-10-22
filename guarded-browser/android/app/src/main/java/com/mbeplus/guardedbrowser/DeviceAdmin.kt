package com.mbeplus.guardedbrowser

import android.app.admin.DeviceAdminReceiver
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent

/**
 * מנהל מכשיר (שלב ההקמה).
 *
 * הפעלה עוברת תמיד דרך מסך האישור של אנדרואיד — הילד רואה בדיוק מה הוא מאשר, וזה מופיע
 * ב"הגדרות ← אבטחה ← אפליקציות ניהול מכשיר". זו לא הסתרה: זה ההפך — האכיפה גלויה.
 *
 * מה זה נותן כבר עכשיו: ההסרה הופכת לפעולה מכוונת (צריך קודם לבטל את הניהול), ושירות הנגישות
 * מזהה את מסך ההסרה ואת מסך ביטול הניהול ומחזיר הביתה (AppGuardService) — הגנה רכה. חסימת הסרה
 * אמיתית (setUninstallBlocked) ושליטה מלאה על כל האפליקציות שמורות ל"בעל המכשיר" (Device Owner,
 * שלב 11), שדורש התקנה על מכשיר נקי ולכן אינו ניתן להענקה בזמן ריצה.
 */
class MigdalorAdmin : DeviceAdminReceiver() {
    override fun onDisableRequested(context: Context, intent: Intent): CharSequence =
        "ביטול הניהול יסיר את ההגנה של מגדלור מהמכשיר. כדאי לעשות זאת רק בתיאום עם ההורה."
}

object DeviceAdmin {
    fun component(ctx: Context) = ComponentName(ctx, MigdalorAdmin::class.java)

    private fun dpm(ctx: Context) =
        ctx.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    fun active(ctx: Context): Boolean = dpm(ctx).isAdminActive(component(ctx))

    /** מסך האישור של אנדרואיד, עם הסבר למה. */
    fun enableIntent(ctx: Context): Intent =
        Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN)
            .putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, component(ctx))
            .putExtra(DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                "מגדלור צריך הרשאת ניהול כדי לשמור על ההגדרות שההורה קבע, ולנעול את המסך בעת הצורך.")

    /** נעילת המסך — זמינה רק כשהניהול פעיל. */
    fun lock(ctx: Context): Boolean = try {
        if (active(ctx)) { dpm(ctx).lockNow(); true } else false
    } catch (e: Exception) { false }
}
