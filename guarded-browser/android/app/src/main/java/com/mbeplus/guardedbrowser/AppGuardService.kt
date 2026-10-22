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
    private var lastPkg = ""
    private val timer = android.os.Handler(android.os.Looper.getMainLooper())
    private val grantEnd = Runnable { endGrants() }

    /* ── זמן מסך (AppTimeMath) ── */
    private var fgPkg = ""
    private var lastTickMs = 0L
    private var lastUsageSent = 0L
    private var transient: Set<String> = emptySet()
    private val timeTick = object : Runnable {
        override fun run() { tickTime(); timer.postDelayed(this, AppTimeMath.TICK_MS) }
    }

    override fun onServiceConnected() {
        super.onServiceConnected()
        // מקלדות ושורת המערכת — לא מחליפות את האפליקציה שבחזית (AppTimeMath.foreground).
        val ime: Set<String> = try {
            (getSystemService(INPUT_METHOD_SERVICE) as android.view.inputmethod.InputMethodManager)
                .enabledInputMethodList.map { it.packageName }.toSet()
        } catch (e: Exception) { emptySet() }
        transient = ime + "com.android.systemui"
        timer.removeCallbacks(timeTick)
        timer.postDelayed(timeTick, AppTimeMath.TICK_MS)
    }

    override fun onDestroy() { timer.removeCallbacksAndMessages(null); super.onDestroy() }

    /**
     * כל TICK_MS: מוסיפים לאפליקציה שבחזית את הזמן שעבר — רק כשהמסך דלוק. נגמר הזמן כשהיא פתוחה — חוזרים
     * הביתה עם מסך "נגמר הזמן". ופעם ב-5 דקות — דיווח להורה (do=app_usage).
     * ‏enforce=false — במעבר בין אפליקציות: רק סוגרים את הזמן, בלי להקפיץ את האפליקציה שהילד כבר עוזב.
     */
    private fun tickTime(enforce: Boolean = true) {
        if (!store.isLoggedIn || store.released || !store.appsManage) { lastTickMs = 0; return }
        val now = TrustedClock.now(store)
        val on = try { (getSystemService(POWER_SERVICE) as android.os.PowerManager).isInteractive } catch (e: Exception) { true }
        val step = AppTimeMath.step(lastTickMs, now)
        lastTickMs = now
        val today = store.today()
        val before = store.appUsage
        // יום חדש — לפני האיפוס שולחים את הסיכום של אתמול (השרת מקבל גם את אתמול).
        if (before.day.isNotEmpty() && before.day != today && before.ms.isNotEmpty()) Api.appUsage(store.token, before.day, before.ms) {}
        store.appUsage = AppTimeMath.add(before, today, if (on) fgPkg else "", step)
        if (enforce && on && fgPkg.isNotEmpty() && AppPolicy.timeUp(store, fgPkg) && AppPolicy.blocked(store, fgPkg)) bounce(fgPkg, removal = false)
        if (now - lastUsageSent > 5 * 60_000L) reportUsage(now)
    }

    private fun reportUsage(now: Long) {
        val u = store.appUsage
        val sig = u.day + u.ms.toString()
        // לא יותר מפעם ב-5 דקות גם כשנכשל (בלי רשת) — לא בכל טיק.
        lastUsageSent = now
        if (u.ms.isEmpty() || sig == store.appUsageSent) return
        Api.appUsage(store.token, u.day, u.ms) { r -> if (r.ok) store.appUsageSent = sig }
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event?.eventType != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED) return
        val pkg = event.packageName?.toString() ?: return
        lastPkg = pkg
        // זמן מסך: מה נספר מעכשיו. לפני העדכון — סוגרים את הזמן של האפליקציה הקודמת.
        val next = AppTimeMath.foreground(fgPkg, pkg, store.launchable, transient, store.ownPackage)
        if (next != fgPkg) { tickTime(enforce = false); fgPkg = next }
        // היתר זמני שהסתיים — חוסמים מחדש לפני כל בדיקה אחרת (גם בלי שרת).
        AppGrants.tick(this, store)
        armGrantTimer()
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
        // יש בקשת פתיחה ממתינה — בודקים כל חצי דקה, כדי שההחלטה של ההורה תגיע מהר (ולא אחרי 5 דקות).
        val every = if (store.appPending.isNotEmpty()) 30_000 else 5 * 60_000
        if (now - lastUpdateCheck < every || !store.isLoggedIn) return
        lastUpdateCheck = now
        // הגבלות ההתקנה שהוסרו לעדכון חוזרות גם בלי רשת — חלון שפג או עדכון שכבר הותקן (שלב 9).
        Updater.recover(this)
        Api.policy(store.token) { r ->
            if (!r.ok || r.json == null) return@policy
            PolicySync.apply(this, r.json, "guard")
            armGrantTimer()
            if (!Updater.required(this)) return@policy
            if (Enforcer.isOwner(this)) { Updater.run(this); return@policy }
            if (pkg == store.ownPackage || pkg.contains("packageinstaller")) return@policy
            lastUpdateNag = System.currentTimeMillis()
            try { Updater.lock(this) } catch (e: Exception) { Trace.log("appguard.update", e.message ?: "") }
        }
    }

    /**
     * טיימר לסוף ההיתר הזמני הקרוב: אפליקציה שפתוחה ברגע הזה נחסמת שוב בזמן, גם אם הילד לא עבר מסך (ואז אין
     * אירוע נגישות). ב"בעל מכשיר" ההשעיה (Enforcer.apply) סוגרת אותה; בלי — חזרה הביתה ומסך חסימה.
     */
    private fun armGrantTimer() {
        timer.removeCallbacks(grantEnd)
        val next = AppGrantMath.nextEnd(store.appGrants, TrustedClock.now(store))
        if (next > 0) timer.postDelayed(grantEnd, (next - TrustedClock.now(store)).coerceAtLeast(1000))
    }

    private fun endGrants() {
        val ended = AppGrants.tick(this, store)
        if (lastPkg in ended && AppPolicy.blocked(store, lastPkg)) bounce(lastPkg, removal = false)
        armGrantTimer()
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

    /**
     * האם השם "מגדלור" או שם החבילה מופיעים בחלון הפעיל.
     *
     * פרטיות (סקירה, 2026-10-20 — שלב 10): נקרא רק משלושת המסכים שב-guardsRemoval (הסרה, פרטי אפליקציה,
     * מנהלי מכשיר), ורק בלי "בעל מכשיר". החיפוש הוא של המערכת (findAccessibilityNodeInfosByText): אנחנו
     * מקבלים רק את הצמתים שמכילים את השם — לא את שאר הטקסט בחלון — ותשובת כן/לא בלבד. שום טקסט לא נשמר,
     * לא נרשם ולא נשלח. קודם עברנו על כל הטקסט בחלון (עד 400 צמתים), בניגוד ל"לא קוראים תוכן מסך".
     */
    private fun windowMentionsSelf(): Boolean {
        val root = try { rootInActiveWindow } catch (e: Exception) { null } ?: return false
        return try {
            listOf(getString(R.string.app_name), store.ownPackage).any { needle ->
                needle.isNotEmpty() && root.findAccessibilityNodeInfosByText(needle).isNotEmpty()
            }
        } catch (e: Exception) { Trace.log("appguard.scan", e.javaClass.simpleName); false }
    }

    override fun onInterrupt() {}
}
