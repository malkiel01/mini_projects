package com.mbeplus.guardedbrowser

/**
 * מדיניות האפליקציות כפי שהתקבלה מהשרת (שלב 11), ואליה שירות הנגישות משווה כל אפליקציה שעולה.
 *
 * ‏ALWAYS — מה שלעולם לא נחסם, גם ב"רק מה שאישרתי": מגדלור עצמו, המסך הראשי, שורת המערכת וחייגן —
 * כדי לא לנעול את הטלפון ולא לחסום את עצמנו.
 *
 * ההגדרות — נחסמות **רק כשההורה חסם אותן במפורש** (לא ב"רק מה שאישרתי": בלי הגדרות אי אפשר לחבר Wi‑Fi),
 * ואף פעם לא כשמגדלור עצמו פתח אותן — מסלול ההקמה, היתר העדכון (openSettings: חלון של 3 דקות).
 */
object AppPolicy {

    private val ALWAYS = setOf(
        "com.android.systemui",
        "com.android.dialer", "com.google.android.dialer", "com.android.phone",
        "com.android.incallui", "com.android.contacts", "com.android.emergency",
        // מסך ההתקנה של אנדרואיד — דרכו מגדלור מתקין את העדכון של עצמו (גם השרת שולח אותו כמותר).
        "com.android.packageinstaller", "com.google.android.packageinstaller", "com.samsung.android.packageinstaller",
    )

    /** אפליקציית ההגדרות (גם בסמסונג ובשיאומי זו החבילה). */
    val SETTINGS = setOf("com.android.settings")
    private const val PASS_MS = 3 * 60_000L

    /**
     * מגדלור פותח את ההגדרות בעצמו לשלב חובה (הקמה, היתר העדכון) — הן נשארות פתוחות 3 דקות, גם אם ההורה חסם
     * אותן. לא לשימוש בכפתורי נוחות: בחלון הזה אפשר להגיע לכל מסך הגדרות.
     */
    fun openSettings(ctx: android.content.Context, intent: android.content.Intent) {
        Store(ctx).settingsPassUntil = System.currentTimeMillis() + PASS_MS
        ctx.startActivity(intent)
    }

    @Volatile private var scannedAt = 0L

    /**
     * מה במכשיר לעולם לא נחסם, ועל מה חל "רק מה שאישרתי". נקרא משירות הנגישות לפני כל בדיקה — בפועל
     * לכל היותר פעם בדקה.
     *
     * באג (2026-10-20): מסך הבית נמצא רק כ-launcher *ברירת המחדל*, ובאנדרואיד 11+ בלי <queries> מתאים
     * בכלל לא נמצא — ולכן ב"רק מה שאישרתי" לחיצה על "בית" פתחה את מסך החסימה. עכשיו: כל מסכי הבית
     * וכל המקלדות לעולם לא נחסמים, ו"רק מה שאישרתי" חל רק על אפליקציות עם אייקון (לא על רכיבי מערכת).
     */
    fun scan(ctx: android.content.Context, force: Boolean = false) {
        val now = System.currentTimeMillis()
        if (!force && now - scannedAt < 60_000) return
        scannedAt = now
        try {
            val pm = ctx.packageManager
            val store = Store(ctx)
            val home = android.content.Intent(android.content.Intent.ACTION_MAIN).addCategory(android.content.Intent.CATEGORY_HOME)
            val keep = pm.queryIntentActivities(home, 0).map { it.activityInfo.packageName }.toMutableSet()
            pm.queryIntentServices(android.content.Intent("android.view.InputMethod"), 0)
                .mapTo(keep) { it.serviceInfo.packageName }
            store.launchers = keep - "android"
            val main = android.content.Intent(android.content.Intent.ACTION_MAIN).addCategory(android.content.Intent.CATEGORY_LAUNCHER)
            val launchable = pm.queryIntentActivities(main, 0).map { it.activityInfo.packageName }.toSet()
            store.launchable = launchable
            // אפליקציה חדשה (NewAppMath): הסריקה הראשונה — מה שהיה; חדשה — מדווחים לשרת, שירשום עליה כלל.
            if (store.appsBaseline.isEmpty()) store.appsBaseline = launchable
            val fresh = NewAppMath.fresh(launchable - store.launchers - store.ownPackage, store.appsKnown, store.appsBaseline)
            if (store.appsManage && NewAppMath.shouldReport(fresh, store.newAppsSent, store.newAppsSentAt, now)) {
                store.newAppsSent = fresh; store.newAppsSentAt = now
                AppsReport.send(ctx)
            }
        } catch (e: Exception) { Trace.log("apppolicy.scan", e.message ?: "") }
    }

    /** נגמר זמן המסך היומי של האפליקציה. */
    fun timeUp(store: Store, pkg: String): Boolean {
        val limit = store.appLimits[pkg] ?: return false
        return AppTimeMath.over(limit, AppTimeMath.usedMs(store.appUsage, store.today(), pkg))
    }

    /** האם לחסום את החבילה הזו עכשיו, לפי המדיניות השמורה. */
    /**
     * ‏withTime=false — בלי זמן המסך: לרשימת ההשעיה של "בעל מכשיר" (Enforcer). השעיה בגלל זמן הייתה נשארת אחרי
     * חצות עד הסנכרון הבא; זמן המסך נאכף בשירות הנגישות, ומתאפס בחצות גם בלי רשת.
     */
    fun blocked(store: Store, pkg: String, withTime: Boolean = true): Boolean {
        // משוחרר (SPEC סעיף 5) — כל המגבלות נפתחות, כולל חסימת אפליקציות אחרות.
        if (store.released) return false
        if (pkg.isEmpty() || !store.appsManage) return false
        if (pkg == store.ownPackage || pkg in ALWAYS || pkg in store.launchers) return false
        // היתר זמני מבקשת פתיחה (AppGrants) — עד הסוף שלו בשעון האמין, גם בלי שרת.
        if (pkg in AppGrantMath.granted(store.appGrants, TrustedClock.now(store))) return false
        // זמן מסך (AppTimeMath): נגמר הזמן להיום — חסומה, גם אם ההורה אישר אותה. היתר זמני (למעלה) גובר.
        if (withTime && timeUp(store, pkg)) return true
        if (pkg in SETTINGS) {
            return pkg in store.appsBlocked && System.currentTimeMillis() > store.settingsPassUntil
        }
        if (pkg in store.appsAllowed) return false
        if (pkg in store.appsBlocked) return true
        // אפליקציה חדשה שעוד אין עליה החלטה — לפי מה שההורה קבע לחדשות, מיד (גם בלי רשת).
        if (store.appsNew.isNotEmpty() && pkg in store.launchable && NewAppMath.isNew(pkg, store.appsKnown, store.appsBaseline)) {
            return store.appsNew == "block"
        }
        // "רק מה שאישרתי" — רק על אפליקציות שאפשר לפתוח מאייקון; רכיבי מערכת (חלונות, מקלדת) לא נחסמים.
        return store.appsPosture == "deny_all" && pkg in store.launchable
    }
}
