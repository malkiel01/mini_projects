package com.mbeplus.wascanner

import android.accessibilityservice.AccessibilityService
import android.os.Handler
import android.os.Looper
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import java.util.concurrent.Executors

/**
 * הגשר. שירות נגישות שקורא את מסך הוואטסאפ ומזרים הודעות לשרת.
 *
 * מה הוא כן ולא יכול לעשות — הגבלה מהותית, לא פער בקוד:
 *   שירות נגישות רואה רק את מה שמצויר על המסך כרגע. אין לו גישה למסד
 *   ההודעות של וואטסאפ. לכן "לסרוק את כל ההיסטוריה" פירושו לגלול בצ'אט
 *   ולתת לשירות לראות בועה אחרי בועה. הקוד כאן קורא את מה שגלוי; הגלילה
 *   האוטומטית עצמה מסומנת כ-TODO, כי היא הרכיב שהכי תלוי בגרסת וואטסאפ
 *   ומחייב כוונון על מכשיר אמיתי.
 *
 * מזהי ה-view (message_text, date) נכונים לגרסאות וואטסאפ רבות אך לא
 * מובטחים — וואטסאפ משנה אותם בין גרסאות. הם נקודת התחלה לכוונון, לא
 * חוזה. ראו README, פרק "אפליקציית הגשר".
 */
class ScannerService : AccessibilityService() {

    private val store by lazy { Store(this) }
    private val io = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())

    // מזהי הודעות שכבר נשלחו בסשן הזה — מונע שליחה חוזרת של אותו מסך
    // בכל אירוע. השרת ממילא מסנן כפילויות, אבל זה חוסך תעבורה.
    private val seen = HashSet<String>()
    private val pending = ArrayList<Api.Msg>()
    private var flushScheduled = false

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null || !store.configured) return
        val pkg = event.packageName?.toString() ?: return
        if (pkg != "com.whatsapp" && pkg != "com.whatsapp.w4b") return

        val root = rootInActiveWindow ?: return
        val chat = chatTitle(root)
        collectMessages(root, chat)
        scheduleFlush()
    }

    /** כותרת הצ'אט הפעיל — שם איש הקשר או הקבוצה. */
    private fun chatTitle(root: AccessibilityNodeInfo): String {
        // TODO: לכוונן מזהה לפי גרסה. toolbar → כותרת הצ'אט.
        val nodes = root.findAccessibilityNodeInfosByViewId("com.whatsapp:id/conversation_contact_name")
        return nodes.firstOrNull()?.text?.toString().orEmpty()
    }

    /** אוסף בועות הודעה שגלויות כרגע. */
    private fun collectMessages(root: AccessibilityNodeInfo, chat: String) {
        val texts = root.findAccessibilityNodeInfosByViewId("com.whatsapp:id/message_text")
        for (node in texts) {
            val body = node.text?.toString()?.trim().orEmpty()
            if (body.isEmpty()) continue

            // כיוון ההודעה: וואטסאפ מיישר נכנסות לשמאל ויוצאות לימין.
            // TODO: להסיק מהמיקום/מזהה במקום ברירת מחדל.
            val direction = "in"

            val msg = Api.Msg(
                chatName = chat,
                sender = if (direction == "out") "" else chat,
                direction = direction,
                body = body,
                sentAt = System.currentTimeMillis() / 1000, // TODO: לפרסר את בועת התאריך
            )
            val id = msg.extId()
            if (seen.add(id)) pending.add(msg)
        }
    }

    private fun scheduleFlush() {
        if (flushScheduled) return
        flushScheduled = true
        // דחיית איסוף קצרה: אירועי נגישות מגיעים בצרורות בזמן גלילה.
        main.postDelayed({ flush() }, 800)
    }

    private fun flush() {
        flushScheduled = false
        if (pending.isEmpty()) return
        val batch = ArrayList(pending)
        pending.clear()
        val api = Api(store.serverBase, store.pairToken)
        val account = store.accountId
        io.execute { api.ingest(account, batch) }
    }

    override fun onInterrupt() { /* אין מצב מיוחד להשבתה */ }

    override fun onDestroy() {
        super.onDestroy()
        io.shutdown()
    }
}
