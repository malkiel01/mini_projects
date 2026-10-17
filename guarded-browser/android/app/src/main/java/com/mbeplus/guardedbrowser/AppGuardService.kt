package com.mbeplus.guardedbrowser

import android.accessibilityservice.AccessibilityService
import android.content.Intent
import android.view.accessibility.AccessibilityEvent

/**
 * אוכף את מדיניות האפליקציות (שלב 11): כשאפליקציה חסומה עולה לחזית — פותחים מעליה מסך חסימה
 * וחוזרים הביתה. אנחנו לא קוראים תוכן מסך, רק את שם האפליקציה (packageName של האירוע).
 *
 * זו אכיפה דרך שירות נגישות — עובדת על כל טלפון בלי איפוס. אכיפה הרמטית יותר (חסימת הסרה,
 * הסתרת אפליקציות) מגיעה עם "בעל מכשיר", בשלב נפרד.
 */
class AppGuardService : AccessibilityService() {

    private val store by lazy { Store(this) }
    private var lastBlocked = ""
    private var lastAt = 0L

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event?.eventType != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED) return
        val pkg = event.packageName?.toString() ?: return
        if (!AppPolicy.blocked(store, pkg)) return

        // מונע הצפה: אותה אפליקציה תוך שנייה — כבר טיפלנו.
        val now = System.currentTimeMillis()
        if (pkg == lastBlocked && now - lastAt < 1500) return
        lastBlocked = pkg; lastAt = now

        performGlobalAction(GLOBAL_ACTION_HOME)
        try {
            startActivity(Intent(this, BlockedActivity::class.java)
                .putExtra("pkg", pkg)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP))
        } catch (e: Exception) { Trace.log("appguard.fail", e.message ?: "") }
    }

    override fun onInterrupt() {}
}
