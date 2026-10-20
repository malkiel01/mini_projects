package com.mbeplus.guardedbrowser

import android.accessibilityservice.AccessibilityService
import android.content.Intent
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo

/**
 * אוכף את מדיניות האפליקציות (שלב 11): כשאפליקציה חסומה עולה לחזית — פותחים מעליה מסך חסימה
 * וחוזרים הביתה. לרוב די בשם האפליקציה (packageName של האירוע).
 *
 * הגנה מהסרה (removalGuard): בלי "בעל מכשיר" אנדרואיד לא חוסם הסרה באמת — מנהל מכשיר רק הופך את
 * ההסרה לפעולה מכוונת (צריך קודם לבטל את הניהול), והילד יכול לעשות זאת בעצמו. לכן, כל עוד מגדלור
 * מופעל, השירות מזהה את מסך ההסרה של אנדרואיד ואת מסך ביטול "מנהל המכשיר" כשהם על מגדלור עצמו,
 * ומחזיר הביתה. זו הגנה רכה בלבד (safe boot / ADB / איפוס עוקפים אותה) — ההגנה ההרמטית היא
 * setUninstallBlocked של "בעל מכשיר" (Enforcer). רק כאן אנחנו קוראים תוכן חלון, ורק כדי לבדוק אם
 * השם של מגדלור מופיע במסך ההסרה/הניהול.
 *
 * זו אכיפה דרך שירות נגישות — עובדת על כל טלפון בלי איפוס.
 */
class AppGuardService : AccessibilityService() {

    private val store by lazy { Store(this) }
    private var lastBlocked = ""
    private var lastAt = 0L
    private var lastUpdateNag = 0L
    private var lastUpdateCheck = 0L

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event?.eventType != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED) return
        val pkg = event.packageName?.toString() ?: return
        checkUpdate(pkg)
        // עדכון חובה (Updater): מסך העדכון קופץ במעבר בין אפליקציות — לכל היותר פעם בשתי דקות,
        // ולא מעל מגדלור עצמו (שם הוא כבר מוצג) או מעל מסך האישור של ההתקנה.
        if (Updater.required(this) && pkg != store.ownPackage && !pkg.contains("packageinstaller")) {
            val now = System.currentTimeMillis()
            if (now - lastUpdateNag > 120_000) {
                lastUpdateNag = now
                try { Updater.lock(this) } catch (e: Exception) { Trace.log("appguard.update", e.message ?: "") }
            }
        }

        // הגנה מהסרה — מסך ההסרה או ביטול "מנהל המכשיר" של מגדלור עצמו.
        if (guardsRemoval(event, pkg)) {
            bounce(pkg, removal = true)
            return
        }

        AppPolicy.scan(this)
        if (!AppPolicy.blocked(store, pkg)) return
        bounce(pkg, removal = false)
    }

    /**
     * בדיקת עדכון מכאן — לא רק מ-UpdateJob. שירות הנגישות חי כל הזמן (המערכת מחזיקה אותו), ואילו
     * JobScheduler בטלפונים זולים נדחה או נהרג, ואז המכשיר לא ידע שיש גרסה חדשה ומסך העדכון לא קפץ
     * כשמגדלור סגור (בעל המערכת, 2026-10-20). לכל היותר פעם ב-5 דקות, במעבר בין אפליקציות.
     * שירות נגישות רשאי לפתוח מסך מהרקע, ולכן מסך העדכון עולה מיד — בלי תלות בהיתר "מסך מלא".
     */
    private fun checkUpdate(pkg: String) {
        val now = System.currentTimeMillis()
        if (now - lastUpdateCheck < 5 * 60_000 || !store.isLoggedIn) return
        lastUpdateCheck = now
        Api.policy(store.token) { r ->
            if (!r.ok || r.json == null) return@policy
            PolicySync.apply(this, r.json, "guard")
            if (!Updater.required(this)) return@policy
            if (Enforcer.isOwner(this)) { Updater.run(this); return@policy }
            if (pkg == store.ownPackage || pkg.contains("packageinstaller")) return@policy
            lastUpdateNag = System.currentTimeMillis()
            try { Updater.lock(this) } catch (e: Exception) { Trace.log("appguard.update", e.message ?: "") }
        }
    }

    /** חזרה הביתה + מסך חסימה. מונע הצפה: אותו מקור תוך 1.5 שניות — כבר טיפלנו. */
    private fun bounce(pkg: String, removal: Boolean) {
        val now = System.currentTimeMillis()
        val key = if (removal) "removal" else pkg
        if (key == lastBlocked && now - lastAt < 1500) return
        lastBlocked = key; lastAt = now

        performGlobalAction(GLOBAL_ACTION_HOME)
        try {
            startActivity(Intent(this, BlockedActivity::class.java)
                .putExtra("pkg", pkg)
                .putExtra("removal", removal)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP))
        } catch (e: Exception) { Trace.log("appguard.fail", e.message ?: "") }
    }

    /**
     * האם המסך שעלה הוא ניסיון להסיר את מגדלור או לבטל את ניהול המכשיר שלו.
     * ב"בעל מכשיר" ההסרה חסומה ממילא, ולכן לא צריך; במצב "משוחרר" לא אוכפים דבר.
     */
    private fun guardsRemoval(event: AccessibilityEvent, pkg: String): Boolean {
        if (!store.isLoggedIn || store.released || Enforcer.isOwner(this)) return false
        // כשמגדלור עצמו פתח הגדרות לשלב חובה (openSettings) — חלון של 3 דקות, לא חוסמים.
        if (System.currentTimeMillis() <= store.settingsPassUntil) return false
        val cls = event.className?.toString() ?: ""
        val uninstall = pkg.contains("packageinstaller") && cls.contains("Uninstall", true)
        val settings = pkg == "com.android.settings"
        val adminScreen = settings && cls.contains("DeviceAdmin", true)
        val appInfo = settings && (cls.contains("InstalledAppDetails", true) || cls.contains("AppInfoDashboard", true))
        if (!uninstall && !adminScreen && !appInfo) return false
        // לוודא שהמסך הוא על מגדלור ולא על אפליקציה אחרת: השם או שם החבילה מופיעים בחלון.
        return windowMentionsSelf()
    }

    /** האם השם "מגדלור" או שם החבילה מופיעים בחלון הפעיל (סריקה חסומה בעומק ובכמות). */
    private fun windowMentionsSelf(): Boolean {
        val root = try { rootInActiveWindow } catch (e: Exception) { null } ?: return false
        val label = getString(R.string.app_name)
        val self = store.ownPackage
        var seen = 0
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)
        try {
            while (queue.isNotEmpty() && seen < 400) {
                val n = queue.removeFirst(); seen++
                val t = (n.text?.toString() ?: "") + " " + (n.contentDescription?.toString() ?: "")
                if (t.contains(label) || t.contains(self, true)) return true
                for (i in 0 until n.childCount) n.getChild(i)?.let { queue.add(it) }
            }
        } catch (e: Exception) { Trace.log("appguard.scan", e.message ?: "") }
        return false
    }

    override fun onInterrupt() {}
}
