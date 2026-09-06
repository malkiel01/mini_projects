package com.mbeplus.greetingcards

import android.accessibilityservice.AccessibilityService
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo

/**
 * Accessibility Service that automatically taps WhatsApp's send button
 * when a greeting card image is ready to be sent.
 *
 * Only acts when [isWaitingForSend] is true. When not waiting,
 * the service is completely passive and does nothing.
 */
class WhatsAppSendAccessibilityService : AccessibilityService() {

    companion object {
        private const val TAG = "WASendService"
        var instance: WhatsAppSendAccessibilityService? = null
        var isWaitingForSend = false
            private set
        private var onSendResult: ((Boolean) -> Unit)? = null

        // Known send button descriptions across locales
        private val SEND_BUTTON_DESCRIPTIONS = setOf(
            "send", "שלח", "שליחה", "enviar", "senden",
            "envoyer", "отправить", "إرسال", "invia"
        )

        private const val SEND_TIMEOUT_MS = 15_000L
    }

    private val handler = Handler(Looper.getMainLooper())
    private var timeoutRunnable: Runnable? = null
    private var clickedOnce = false

    override fun onServiceConnected() {
        instance = this
        Log.i(TAG, "Accessibility service connected")
    }

    override fun onDestroy() {
        instance = null
        isWaitingForSend = false
        timeoutRunnable?.let { handler.removeCallbacks(it) }
        Log.i(TAG, "Accessibility service destroyed")
        super.onDestroy()
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (!isWaitingForSend || event == null) return

        val packageName = event.packageName?.toString() ?: return
        if (packageName != "com.whatsapp" && packageName != "com.whatsapp.w4b") return

        when (event.eventType) {
            AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED,
            AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED -> {
                if (!clickedOnce) {
                    tryClickSendButton()
                }
            }
        }
    }

    private fun tryClickSendButton() {
        val rootNode = rootInActiveWindow ?: return

        try {
            val sendButton = findSendButton(rootNode)
            if (sendButton != null) {
                Log.i(TAG, "Found send button, clicking...")
                sendButton.performAction(AccessibilityNodeInfo.ACTION_CLICK)
                clickedOnce = true

                // Signal success after a short delay to let WhatsApp process
                timeoutRunnable?.let { handler.removeCallbacks(it) }
                handler.postDelayed({ reportResult(true) }, 800)
            }
        } finally {
            rootNode.recycle()
        }
    }

    private fun findSendButton(node: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        // Check content description
        val desc = node.contentDescription?.toString()?.lowercase() ?: ""
        val className = node.className?.toString() ?: ""

        // Match by content description
        if (SEND_BUTTON_DESCRIPTIONS.any { desc.contains(it) }) {
            if (className.contains("ImageButton") || className.contains("ImageView") ||
                className.contains("Button") || node.isClickable) {
                return AccessibilityNodeInfo.obtain(node)
            }
        }

        // Match by resource ID
        val viewId = node.viewIdResourceName ?: ""
        if ((viewId.contains(":id/send") || viewId.contains("btn_send")) && node.isClickable) {
            return AccessibilityNodeInfo.obtain(node)
        }

        // Recurse children
        for (i in 0 until node.childCount) {
            val child = node.getChild(i) ?: continue
            val result = findSendButton(child)
            if (result != null) {
                child.recycle()
                return result
            }
            child.recycle()
        }

        return null
    }

    /**
     * Called by SendQueueManager to start watching for the send button.
     */
    fun startWaitingForSend(callback: (Boolean) -> Unit) {
        isWaitingForSend = true
        clickedOnce = false
        onSendResult = callback

        // Set timeout — if we can't find the send button, report failure
        timeoutRunnable?.let { handler.removeCallbacks(it) }
        timeoutRunnable = Runnable {
            Log.w(TAG, "Send timeout — button not found")
            reportResult(false)
        }
        handler.postDelayed(timeoutRunnable!!, SEND_TIMEOUT_MS)
    }

    private fun reportResult(success: Boolean) {
        isWaitingForSend = false
        clickedOnce = false
        timeoutRunnable?.let { handler.removeCallbacks(it) }
        Log.i(TAG, "Send result: $success")
        onSendResult?.invoke(success)
        onSendResult = null
    }

    override fun onInterrupt() {
        Log.w(TAG, "Service interrupted")
        isWaitingForSend = false
    }
}
