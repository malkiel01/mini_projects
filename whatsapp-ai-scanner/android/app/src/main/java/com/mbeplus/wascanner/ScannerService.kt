package com.mbeplus.wascanner

import android.accessibilityservice.AccessibilityService
import android.os.Handler
import android.os.Looper
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import android.widget.Toast
import java.util.concurrent.Executors

/**
 * הגשר. שירות נגישות שקורא את מסך הוואטסאפ ומזרים הודעות לשרת.
 *
 * שיטת הקריאה: **סריקת כל הטקסט הגלוי** על מסך הוואטסאפ, ולא חיפוש
 * מזהי view ספציפיים. מזהי ה-view (message_text וכו') משתנים בין
 * גרסאות וואטסאפ ושברו את הקריאה; סריקת כל צמתי הטקסט עמידה לכך. יש
 * בכך מעט רעש (שם איש קשר, חותמות זמן), אבל המודל שעונה על השאלות
 * סובל אותו, וחותמות הזמן שנקראות כטקסט אף עוזרות לשאלות "מי כתב מתי".
 *
 * מגבלה שנותרה: שירות נגישות רואה רק את הגלוי כרגע, ולכן צריך לגלול
 * כדי לאסוף היסטוריה. הבליעה מסירה כפילויות (ext_id), כך שגלילה חוזרת
 * בטוחה.
 */
class ScannerService : AccessibilityService() {

    companion object {
        // אבחון גלוי: מסך ההגדרות של האפליקציה מציג את השורה הזו, כדי
        // שלא נעבוד בעיוור כשההודעות לא נקלטות.
        @Volatile var lastStatus: String = "טרם רץ — פתח וואטסאפ וגלול"
    }

    private val store by lazy { Store(this) }
    private val io = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())

    private val seen = HashSet<String>()
    private val pending = ArrayList<Api.Msg>()
    private var flushScheduled = false

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (!store.configured) { lastStatus = "לא מוגדר — הזן אסימון ומזהה חשבון"; return }

        val pkg = event.packageName?.toString() ?: return
        if (pkg != "com.whatsapp" && pkg != "com.whatsapp.w4b") return

        val root = rootInActiveWindow ?: return
        val chat = chatTitle(root)

        val texts = ArrayList<String>()
        walk(root, texts, 0)

        var added = 0
        for (body in texts) {
            if (body.length < 1) continue
            val msg = Api.Msg(
                chatName = chat,
                sender = chat,
                direction = "in",
                body = body,
                sentAt = System.currentTimeMillis() / 1000,
            )
            if (seen.add(msg.extId())) { pending.add(msg); added++ }
        }
        lastStatus = "נראו ${texts.size} טקסטים, ${added} חדשים, ממתין לשליחה…"
        scheduleFlush()
    }

    /** אוסף את כל צמתי הטקסט שבעץ, לפי הסדר, עד עומק סביר. */
    private fun walk(node: AccessibilityNodeInfo?, out: ArrayList<String>, depth: Int) {
        if (node == null || depth > 80) return
        val t = node.text?.toString()?.trim()
        if (!t.isNullOrEmpty()) out.add(t)
        for (i in 0 until node.childCount) walk(node.getChild(i), out, depth + 1)
    }

    private fun chatTitle(root: AccessibilityNodeInfo): String {
        val nodes = root.findAccessibilityNodeInfosByViewId("com.whatsapp:id/conversation_contact_name")
        return nodes.firstOrNull()?.text?.toString().orEmpty()
    }

    private fun scheduleFlush() {
        if (flushScheduled) return
        flushScheduled = true
        main.postDelayed({ flush() }, 800)
    }

    private fun flush() {
        flushScheduled = false
        if (pending.isEmpty()) return
        val batch = ArrayList(pending)
        pending.clear()
        val api = Api(store.serverBase, store.pairToken)
        val account = store.accountId
        io.execute {
            // דגימה של מה שנסרק בפועל — כדי לראות ביומן אם נקראו הודעות
            // אמיתיות או רק רכיבי ממשק.
            val sample = batch.take(10).joinToString("  ¦  ") { it.body.take(50) }
            api.log("scan", "batch=${batch.size}", sample)

            val r = api.ingest(account, batch)
            lastStatus = when {
                r.httpCode == 200 -> "נשלחו ${batch.size} · נקלטו ${r.ingested} חדשות · שרת 200 ✓"
                r.httpCode == -1  -> "כשל רשת: ${r.error}"
                else              -> "השרת דחה (${r.httpCode}): ${r.error}"
            }
            if (r.ingested > 0) main.post {
                Toast.makeText(this, "נקלטו ${r.ingested} הודעות", Toast.LENGTH_SHORT).show()
            }
        }
    }

    override fun onInterrupt() {}

    override fun onDestroy() {
        super.onDestroy()
        io.shutdown()
    }
}
