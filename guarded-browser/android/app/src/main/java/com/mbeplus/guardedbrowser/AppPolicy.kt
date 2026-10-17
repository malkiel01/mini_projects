package com.mbeplus.guardedbrowser

/**
 * מדיניות האפליקציות כפי שהתקבלה מהשרת (שלב 11), ואליה שירות הנגישות משווה כל אפליקציה שעולה.
 *
 * ‏ALWAYS — מה שלעולם לא נחסם, גם ב"רק מה שאישרתי": מגדלור עצמו, המסך הראשי, שורת המערכת,
 * חייגן והגדרות — כדי לא לנעול את הטלפון ולא לחסום את עצמנו.
 */
object AppPolicy {

    private val ALWAYS = setOf(
        "com.android.systemui", "com.android.settings",
        "com.android.dialer", "com.google.android.dialer", "com.android.phone",
        "com.android.incallui", "com.android.contacts", "com.android.emergency",
    )

    /** האם לחסום את החבילה הזו עכשיו, לפי המדיניות השמורה. */
    fun blocked(store: Store, pkg: String): Boolean {
        if (pkg.isEmpty() || !store.appsManage) return false
        if (pkg == store.ownPackage || pkg in ALWAYS || pkg in store.launchers) return false
        if (pkg in store.appsAllowed) return false
        if (pkg in store.appsBlocked) return true
        return store.appsPosture == "deny_all"
    }
}
