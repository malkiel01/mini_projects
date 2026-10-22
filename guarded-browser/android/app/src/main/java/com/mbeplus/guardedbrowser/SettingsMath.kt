package com.mbeplus.guardedbrowser

/**
 * תוצאת ההחלה של כל הגדרת מכשיר, כפי שהיא נשלחת להורה — טהורה, נבדקת ב-JVM (SettingsMathTest).
 *
 * סקירה, 2026-10-22: ההורה התיר התקנת אפליקציות וההתקנה נשארה חסומה, ובפאנל לא נראה דבר — הדוח בדק רק
 * הגדרות חסומות, ו"ok" פירושו היה "הקריאה למערכת לא זרקה". עכשיו כל הגדרה, חסומה או מותרת, נקראת בחזרה
 * מהמערכת (DevicePolicyManager.getUserRestrictions, ‏getCameraDisabled…) אחרי ההחלה:
 *
 *   ‏verified        — נקרא בחזרה ותואם לבחירת ההורה.
 *   ‏applied         — הקריאה למערכת הצליחה, אבל אי אפשר לבדוק את התוצאה עכשיו (למשל באמצע עדכון).
 *   ‏fail            — הקריאה נכשלה, או שהמצב שנקרא שונה מהבחירה.
 *   ‏no_owner        — בלי "בעל מכשיר" אין החלה בכלל.
 *   ‏unsupported     — אין הגבלה כזו בגרסת האנדרואיד של המכשיר.
 *   ‏needs_device    — הוחל ואומת, ואנדרואיד דורש עוד צעד במכשיר (התקנה מקובץ: היתר לאפליקציה שממנה מתקינים).
 *   ‏blocked_by_apps — הוחל ואומת, אבל אפליקציית החנות חסומה ברשימת האפליקציות — ההתקנה עדיין לא אפשרית.
 */
object SettingsMath {

    /**
     * @param callOk  כל הקריאות למערכת הצליחו
     * @param matches המצב שנקרא בחזרה תואם לבחירה; null — אי אפשר לקרוא
     * @param supported יש בגרסה הזו הגבלה שמבצעת את ההגדרה
     */
    fun result(owner: Boolean, supported: Boolean, callOk: Boolean, matches: Boolean?): String = when {
        !owner -> "no_owner"
        !supported -> "unsupported"
        !callOk -> "fail"
        matches == null -> "applied"
        matches -> "verified"
        else -> "fail"
    }

    /** האם ההגבלות שנקראו בחזרה תואמות: חסום — כולן קיימות; מותר — אף אחת. */
    fun restrictionsMatch(wantBlocked: Boolean, present: List<Boolean>): Boolean =
        if (wantBlocked) present.all { it } else present.none { it }

    /** התקנה מהחנות: מותרת ומאומתת, אבל החנות עצמה חסומה ברשימת האפליקציות. */
    fun installApps(base: String, wantBlocked: Boolean, storeBlocked: Boolean): String =
        if (base == "verified" && !wantBlocked && storeBlocked) "blocked_by_apps" else base

    /**
     * התקנה מקובץ: מותרת ומאומתת — אנדרואיד עדיין מבקש היתר לכל אפליקציה שממנה מתקינים ("התקנת אפליקציות
     * לא מוכרות"). "בעל מכשיר" אינו יכול לתת אותו במקום המשתמש. אומרים את זה, לא "אומת" סתם.
     */
    fun installUnknown(base: String, wantBlocked: Boolean): String =
        if (base == "verified" && !wantBlocked) "needs_device" else base
}
