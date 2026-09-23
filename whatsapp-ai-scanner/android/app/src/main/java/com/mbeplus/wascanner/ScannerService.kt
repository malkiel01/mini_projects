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
 * שלושה מצבים:
 *   פסיבי — קורא את מה שגלוי כשמשתמש גולל ידנית.
 *   שלב 1 — גולל את הצ'אט הפתוח לבד עד ההתחלה.
 *   שלב 2 — סריקה מלאה: מנווט על כל הצ'אטים מרשימת השיחות בעצמו —
 *     פותח צ'אט, גולל לסופו, חוזר, ועובר לבא, עד שכולם נסרקו.
 *
 * הקריאה היא סריקת כל הטקסט הגלוי (לא מזהי view ספציפיים), עמידה
 * לשינויי גרסה. הבליעה מסירה כפילויות (ext_id), כך שחזרה בטוחה.
 */
class ScannerService : AccessibilityService() {

    companion object {
        @Volatile var lastStatus: String = "טרם רץ"
        private const val AUTO_MAX_STEPS = 400
        private const val AUTO_IDLE_STOP = 4
        private const val STEP_MS = 750L
        private const val OPEN_MS = 1100L          // המתנה לפתיחת צ'אט
        private const val SWEEP_MAX_CHATS = 3000    // תקרת בטיחות
        private const val LIST_NOPROGRESS_STOP = 3  // גלילות רשימה ללא חדש = סיום
    }

    private val store by lazy { Store(this) }
    private val io = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())

    private val seen = HashSet<String>()
    private val pending = ArrayList<Api.Msg>()
    private var flushScheduled = false

    // שלב 1
    private var autoScrolling = false
    private var autoIdle = 0
    private var autoSteps = 0

    // שלב 2
    private enum class Sweep { LIST, CHAT }
    private var sweepRunning = false
    private var sweepState = Sweep.LIST
    private val doneChats = HashSet<String>()
    private var currentChat = ""
    private var chatIdle = 0
    private var chatSteps = 0
    private var chatsProcessed = 0
    private var lastListNames: Set<String> = emptySet()
    private var listNoProgress = 0
    private var sweepMisses = 0

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (!store.configured) { lastStatus = "לא מוגדר — בחר חשבון פעיל"; return }

        val pkg = event.packageName?.toString() ?: return
        if (pkg != "com.whatsapp" && pkg != "com.whatsapp.w4b") return

        val root = rootInActiveWindow ?: return
        val added = collectFromRoot(root)
        if (!sweepRunning) lastStatus = "נראו הודעות · $added חדשות · בתור ${pending.size}"
        scheduleFlush()

        // שלב 2 מנווט בעצמו (לולאת tick); שלב 1 מופעל רק כשאין sweep.
        if (store.fullSweep && !sweepRunning) startSweep()
        else if (store.autoScan && !store.fullSweep && !autoScrolling && isConversationOpen(root)) startAutoScroll()
    }

    /* ── איסוף ──────────────────────────────────────────────────── */

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

    private fun isConversationOpen(root: AccessibilityNodeInfo): Boolean =
        findScrollable(root) != null && hasEditable(root)

    private fun findScrollable(node: AccessibilityNodeInfo?): AccessibilityNodeInfo? {
        if (node == null) return null
        if (node.isScrollable) return node
        for (i in 0 until node.childCount) {
            val r = findScrollable(node.getChild(i)); if (r != null) return r
        }
        return null
    }

    private fun hasEditable(node: AccessibilityNodeInfo?): Boolean {
        if (node == null) return false
        if (node.isEditable) return true
        for (i in 0 until node.childCount) if (hasEditable(node.getChild(i))) return true
        return false
    }

    /* ── שלב 1: גלילת הצ'אט הפתוח ────────────────────────────────── */

    private fun startAutoScroll() {
        autoScrolling = true; autoIdle = 0; autoSteps = 0
        lastStatus = "סריקה אוטומטית התחילה…"
        io.execute { safeLog("autoscan", "start", "") }
        main.post { autoStep() }
    }

    private fun autoStep() {
        if (!store.autoScan) { stopAuto("בוטל"); return }
        val root = rootInActiveWindow
        if (root == null) { main.postDelayed({ autoStep() }, STEP_MS); return }
        val added = collectFromRoot(root)
        if (added == 0) autoIdle++ else autoIdle = 0
        autoSteps++; scheduleFlush()
        if (autoIdle >= AUTO_IDLE_STOP || autoSteps >= AUTO_MAX_STEPS) { stopAuto("הושלמה ($autoSteps צעדים)"); return }
        findScrollable(root)?.performAction(AccessibilityNodeInfo.ACTION_SCROLL_BACKWARD)
        lastStatus = "גלילה אוטומטית: צעד $autoSteps · +$added"
        main.postDelayed({ autoStep() }, STEP_MS)
    }

    private fun stopAuto(reason: String) {
        autoScrolling = false; store.autoScan = false; flush()
        lastStatus = "גלילה אוטומטית $reason"
        io.execute { safeLog("autoscan", reason, "steps=$autoSteps") }
        main.post { Toast.makeText(this, "גלילה אוטומטית הסתיימה", Toast.LENGTH_SHORT).show() }
    }

    /* ── שלב 2: סריקת כל הצ'אטים ─────────────────────────────────── */

    private data class Row(val node: AccessibilityNodeInfo, val name: String)

    private fun startSweep() {
        sweepRunning = true
        sweepState = Sweep.LIST
        doneChats.clear(); currentChat = ""
        chatIdle = 0; chatSteps = 0; chatsProcessed = 0
        lastListNames = emptySet(); listNoProgress = 0; sweepMisses = 0
        lastStatus = "סריקה מלאה התחילה…"
        io.execute { safeLog("sweep", "start", "") }
        main.postDelayed({ sweepTick() }, OPEN_MS)
    }

    private fun sweepTick() {
        if (!store.fullSweep) { stopSweep("בוטל ידנית"); return }
        if (chatsProcessed >= SWEEP_MAX_CHATS) { stopSweep("תקרת צ'אטים"); return }

        val root = rootInActiveWindow
        if (root == null) {
            if (++sweepMisses > 8) { stopSweep("אין מסך וואטסאפ"); return }
            main.postDelayed({ sweepTick() }, STEP_MS); return
        }
        sweepMisses = 0

        if (isConversationOpen(root)) {
            // בתוך צ'אט — גוללים לסופו.
            sweepState = Sweep.CHAT
            val added = collectFromRoot(root)
            if (added == 0) chatIdle++ else chatIdle = 0
            chatSteps++; scheduleFlush()
            if (chatIdle >= AUTO_IDLE_STOP || chatSteps >= AUTO_MAX_STEPS) {
                // סיימנו את הצ'אט — חוזרים לרשימה.
                flush()
                if (currentChat.isNotEmpty()) doneChats.add(currentChat)
                chatsProcessed++
                io.execute { safeLog("sweep", "chat-done", "name=$currentChat steps=$chatSteps total=$chatsProcessed") }
                chatIdle = 0; chatSteps = 0; currentChat = ""
                performGlobalAction(GLOBAL_ACTION_BACK)
                sweepState = Sweep.LIST
                main.postDelayed({ sweepTick() }, OPEN_MS)
                return
            }
            findScrollable(root)?.performAction(AccessibilityNodeInfo.ACTION_SCROLL_BACKWARD)
            lastStatus = "סריקה מלאה: צ'אט '$currentChat' · צעד $chatSteps · עובדו $chatsProcessed"
            main.postDelayed({ sweepTick() }, STEP_MS)
            return
        }

        // ברשימת הצ'אטים — בוחרים את הבא שטרם נסרק.
        sweepState = Sweep.LIST
        val rows = chatRows(root)
        val next = rows.firstOrNull { it.name.isNotEmpty() && it.name !in doneChats }
        if (next != null) {
            currentChat = next.name
            chatIdle = 0; chatSteps = 0
            io.execute { safeLog("sweep", "open", "name=${next.name}") }
            lastStatus = "סריקה מלאה: פותח '${next.name}' (עובדו $chatsProcessed)"
            next.node.performAction(AccessibilityNodeInfo.ACTION_CLICK)
            main.postDelayed({ sweepTick() }, OPEN_MS)
            return
        }

        // אין צ'אט חדש גלוי — גוללים את הרשימה למטה לחשוף עוד.
        val names = rows.map { it.name }.toSet()
        if (names.isNotEmpty() && names == lastListNames) listNoProgress++ else listNoProgress = 0
        lastListNames = names
        if (listNoProgress >= LIST_NOPROGRESS_STOP) { stopSweep("הושלמה — $chatsProcessed צ'אטים"); return }
        findScrollable(root)?.performAction(AccessibilityNodeInfo.ACTION_SCROLL_FORWARD)
        lastStatus = "סריקה מלאה: גולל רשימה (עובדו $chatsProcessed)"
        main.postDelayed({ sweepTick() }, STEP_MS)
    }

    /** שורות הצ'אט ברשימה: לכל שורה — צומת שאפשר להקיש ושם איש הקשר. */
    private fun chatRows(root: AccessibilityNodeInfo): List<Row> {
        val list = findScrollable(root) ?: return emptyList()
        val out = ArrayList<Row>()
        for (i in 0 until list.childCount) {
            val row = list.getChild(i) ?: continue
            val clickable = if (row.isClickable) row else firstClickable(row, 0)
            val name = firstText(row, 0)
            if (clickable != null && name.isNotEmpty()) out.add(Row(clickable, name))
        }
        return out
    }

    private fun firstClickable(node: AccessibilityNodeInfo?, depth: Int): AccessibilityNodeInfo? {
        if (node == null || depth > 12) return null
        if (node.isClickable) return node
        for (i in 0 until node.childCount) {
            val r = firstClickable(node.getChild(i), depth + 1); if (r != null) return r
        }
        return null
    }

    private fun firstText(node: AccessibilityNodeInfo?, depth: Int): String {
        if (node == null || depth > 12) return ""
        val t = node.text?.toString()?.trim()
        if (!t.isNullOrEmpty()) return t
        for (i in 0 until node.childCount) {
            val r = firstText(node.getChild(i), depth + 1); if (r.isNotEmpty()) return r
        }
        return ""
    }

    private fun stopSweep(reason: String) {
        sweepRunning = false; store.fullSweep = false; flush()
        lastStatus = "סריקה מלאה $reason"
        io.execute { safeLog("sweep", reason, "chats=$chatsProcessed") }
        main.post { Toast.makeText(this, "סריקה מלאה הסתיימה ($chatsProcessed צ'אטים)", Toast.LENGTH_LONG).show() }
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
            api.log("scan", "batch=${batch.size}", batch.take(10).joinToString("  ¦  ") { it.body.take(50) })
            val r = api.ingest(account, batch)
            if (!sweepRunning) lastStatus = when {
                r.httpCode == 200 -> "נשלחו ${batch.size} · נקלטו ${r.ingested} · שרת 200 ✓"
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
