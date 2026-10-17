package com.mbeplus.guardedbrowser

import android.app.Activity
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.Toast
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/**
 * בקש גישה (SPEC.md · שלב 6).
 *
 * הילד נחסם → "🙋 בקש גישה" → שדה "למה?" (לא חובה) → השרת שולח להורה התראה.
 * ההורה מאשר → RequestWatcher מגלה את זה תוך כמה שניות, מושך מדיניות, והדף נפתח.
 */
object AccessRequest {

    /** כמו REQUESTABLE בשרת: רק "עוד לא החליטו", לא "החליטו שלא" ולא זמן. */
    private val REQUESTABLE = setOf("not_listed", "category_deny", "yt_not_approved")

    fun canAsk(code: String, url: String) =
        code in REQUESTABLE && (url.startsWith("http://") || url.startsWith("https://"))

    fun hostOf(url: String): String =
        try { java.net.URI(url).host?.removePrefix("www.") ?: url } catch (e: Exception) { url }

    /** הדיאלוג: למה? → שליחה. ‏onSent מקבל את מזהה הבקשה; ‏onCancel — ביטול או כישלון. */
    fun ask(act: Activity, store: Store, url: String, onCancel: () -> Unit = {}, onSent: (Int) -> Unit = {}) {
        val note = EditText(act).apply {
            hint = "למה? (לא חובה)"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_SENTENCES
            maxLines = 3
        }
        val pad = (20 * act.resources.displayMetrics.density).toInt()
        val box = FrameLayout(act).apply { setPadding(pad, pad / 2, pad, 0); addView(note) }
        MaterialAlertDialogBuilder(act)
            .setTitle("🙋 לבקש את ${hostOf(url)}?")
            .setMessage("ההורה יקבל הודעה, ואם יאשר — הדף ייפתח לך לבד.")
            .setView(box)
            .setNegativeButton("ביטול") { _, _ -> onCancel() }
            .setOnCancelListener { onCancel() }
            .setPositiveButton("שליחה") { _, _ ->
                Api.request(store.token, url, note.text.toString().trim()) { r ->
                    val id = r.json?.optInt("id", 0) ?: 0
                    if (r.ok && id > 0) {
                        store.addPendingRequest(id, url)
                        Toast.makeText(act, r.json?.optString("message") ?: "נשלח", Toast.LENGTH_LONG).show()
                        onSent(id)
                    } else {
                        Toast.makeText(act, r.error.ifEmpty { "השליחה נכשלה" }, Toast.LENGTH_LONG).show()
                        onCancel()
                    }
                }
            }
            .show()
    }
}

/**
 * שואל את השרת על בקשות ממתינות, רק כל עוד יש כאלה ורק כשהמסך פעיל.
 *
 * כשבקשה אושרה — קודם מושכים מדיניות (כדי שהכלל החדש כבר יהיה במכשיר), ורק
 * אז onAnswer: מי שיפתח את הדף מיד לא ייחסם שוב על מדיניות ישנה.
 */
class RequestWatcher(
    private val store: Store,
    private val onAnswer: (url: String, approved: Boolean) -> Unit,
) {
    private val main = Handler(Looper.getMainLooper())
    private var running = false
    private val tick = Runnable { poll() }

    fun start() { if (!running) { running = true; main.postDelayed(tick, 1500) } }
    fun stop() { running = false; main.removeCallbacks(tick) }
    fun kick() { if (running) { main.removeCallbacks(tick); main.postDelayed(tick, 4000) } else start() }

    private fun poll() {
        if (!running) return
        val pending = store.pendingRequests()
        if (pending.isEmpty() || store.token.isEmpty()) { running = false; return }
        Api.requests(store.token) { r ->
            if (!running) return@requests
            val list = r.json?.optJSONArray("requests")
            if (r.ok && list != null) {
                val status = (0 until list.length()).associate {
                    list.getJSONObject(it).let { o -> o.optInt("id") to o.optString("status") }
                }
                val answered = mutableListOf<Pair<String, Boolean>>()
                for ((id, url) in pending) {
                    when (status[id]) {
                        "approved" -> { store.removePendingRequest(id); answered += url to true }
                        "denied" -> { store.removePendingRequest(id); answered += url to false }
                        null -> store.removePendingRequest(id)     // ישנה מיממה — כבר לא רלוונטית
                    }
                }
                if (answered.any { it.second }) {
                    Api.policy(store.token) { p ->
                        if (p.ok && p.json != null) store.savePolicy(p.json)
                        answered.forEach { (u, ok) -> onAnswer(u, ok) }
                    }
                } else answered.forEach { (u, ok) -> onAnswer(u, ok) }
            }
            if (store.pendingRequests().isNotEmpty()) main.postDelayed(tick, 8000) else running = false
        }
    }
}
