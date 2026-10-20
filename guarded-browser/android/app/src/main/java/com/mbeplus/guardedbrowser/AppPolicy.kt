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

    /** האם לחסום את החבילה הזו עכשיו, לפי המדיניות השמורה. */
    fun blocked(store: Store, pkg: String): Boolean {
        // משוחרר (SPEC סעיף 5) — כל המגבלות נפתחות, כולל חסימת אפליקציות אחרות.
        if (store.released) return false
        if (pkg.isEmpty() || !store.appsManage) return false
        if (pkg == store.ownPackage || pkg in ALWAYS || pkg in store.launchers) return false
        if (pkg in SETTINGS) {
            return pkg in store.appsBlocked && System.currentTimeMillis() > store.settingsPassUntil
        }
        if (pkg in store.appsAllowed) return false
        if (pkg in store.appsBlocked) return true
        return store.appsPosture == "deny_all"
    }
}
