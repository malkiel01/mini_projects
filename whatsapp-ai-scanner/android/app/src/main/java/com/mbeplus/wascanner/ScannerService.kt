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
 * שני מצבים:
 *   פסיבי — קורא את מה שגלוי כרגע כשמשתמש גולל ידנית.
 *   אוטומטי (שלב 1) — כשהמשתמש מדליק את המצב ופותח צ'אט, השירות גולל
 *     את הצ'אט למעלה בעצמו עד ההתחלה ואוסף את כל ההיסטוריה, ללא ידיים.
 *
 * הקריאה היא סריקת כל הטקסט הגלוי (ולא מזהי view ספציפיים), עמידה
 * לשינויי גרסה. הבליעה מסירה כפילויות (ext_id), כך שגלילה חוזרת בטוחה.
 */
class ScannerService : AccessibilityService() {

    companion object {
        @Volatile var lastStatus: String = "טרם רץ — פתח וואטסאפ וגלול"
        private const val AUTO_MAX_STEPS = 400   // תקרת בטיחות
        private const val AUTO_IDLE_STOP = 4     // צעדים ללא חדש = הגענו לראש
        private const val AUTO_DELAY_MS = 750L   // המתנה בין גלילות, לרינדור
    }

    private val store by lazy { Store(this) }
    private val io = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())

    private val seen = HashSet<String>()
    private val pending = ArrayList<Api.Msg>()
    private var flushScheduled = false

    // מצב הגלילה האוטומטית
    private var autoScrolling = false
    private var autoIdle = 0
    private var autoSteps = 0

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (!store.configured) { lastStatus = "לא מוגדר — הזן אסימון ומזהה חשבון"; return }

        val pkg = event.packageName?.toString() ?: return
        if (pkg != "com.whatsapp" && pkg != "com.whatsapp.w4b") return

        val root = rootInActiveWindow ?: return

        // איסוף פסיבי תמיד — גם בזמן גלילה אוטומטית, וגם כשגוללים ביד.
        val added = collectFromRoot(root)
        lastStatus = "נראו הודעות · ${added} חדשות · בתור ${pending.size}"
        scheduleFlush()

        // מתחילים גלילה אוטומטית רק כשהמצב דלוק וצ'אט אכן פתוח.
        if (store.autoScan && !autoScrolling && isConversationOpen(root)) {
            startAutoScroll()
        }
    }

    /** אוסף את כל צמתי הטקסט הגלויים. מחזיר כמה חדשים נוספו. */
    private fun collectFromRoot(root: AccessibilityNodeInfo): Int {
        val chat = chatTitle(root)
        val texts = ArrayList<String>()
        walk(root, texts, 0)
        var added = 0
        for (body in texts) {
            if (body.isEmpty()) continue
            val msg = Api.Msg(chat, chat, "in", body, System.currentTimeMillis() / 1000)
            if (seen.add(msg.extId())) { pending.add(msg); added++ }
        }
        return added
    }

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

    /* ── גלילה אוטומטית (שלב 1) ─────────────────────────────────── */

    /** צ'אט פתוח = יש רשימה שאפשר לגלול + תיבת קלט (צומת ניתן לעריכה).
     *  שתי הבדיקות אינן תלויות במזהי view, ולכן עמידות לגרסאות. */
    private fun isConversationOpen(root: AccessibilityNodeInfo): Boolean {
        return findScrollable(root) != null && hasEditable(root)
    }

    private fun findScrollable(node: AccessibilityNodeInfo?): AccessibilityNodeInfo? {
        if (node == null) return null
        if (node.isScrollable) return node
        for (i in 0 until node.childCount) {
            val r = findScrollable(node.getChild(i))
            if (r != null) return r
        }
        return null
    }

    private fun hasEditable(node: AccessibilityNodeInfo?): Boolean {
        if (node == null) return false
        if (node.isEditable) return true
        for (i in 0 until node.childCount) {
            if (hasEditable(node.getChild(i))) return true
        }
        return false
    }

    private fun startAutoScroll() {
        autoScrolling = true
        autoIdle = 0
        autoSteps = 0
        lastStatus = "סריקה אוטומטית התחילה…"
        io.execute { safeLog("autoscan", "start", "") }
        main.post { autoStep() }
    }

    private fun autoStep() {
        if (!store.autoScan) { stopAuto("בוטל ידנית"); return }

        val root = rootInActiveWindow
        if (root == null) { main.postDelayed({ autoStep() }, AUTO_DELAY_MS); return }

        val added = collectFromRoot(root)
        if (added == 0) autoIdle++ else autoIdle = 0
        autoSteps++
        scheduleFlush()

        if (autoIdle >= AUTO_IDLE_STOP || autoSteps >= AUTO_MAX_STEPS) {
            stopAuto("הושלמה (${autoSteps} צעדים)")
            return
        }

        // גלילה אחורה = חשיפת הודעות ישנות יותר (למעלה).
        findScrollable(root)?.performAction(AccessibilityNodeInfo.ACTION_SCROLL_BACKWARD)
        lastStatus = "סריקה אוטומטית: צעד $autoSteps · +$added · בתור ${pending.size}"
        main.postDelayed({ autoStep() }, AUTO_DELAY_MS)
    }

    private fun stopAuto(reason: String) {
        autoScrolling = false
        store.autoScan = false
        flush()
        lastStatus = "סריקה אוטומטית $reason"
        io.execute { safeLog("autoscan", reason, "steps=$autoSteps") }
        main.post { Toast.makeText(this, "סריקה אוטומטית הסתיימה", Toast.LENGTH_SHORT).show() }
    }

    private fun safeLog(tag: String, status: String, detail: String) {
        try { Api(store.serverBase, store.pairToken).log(tag, status, detail) } catch (e: Exception) {}
    }

    /* ── שליחה ──────────────────────────────────────────────────── */

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
