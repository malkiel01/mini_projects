package com.mbeplus.greetingcards

import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import androidx.core.content.FileProvider
import java.io.File

/**
 * Manages the WhatsApp send queue — sends greeting card images
 * one by one with configurable delay between sends.
 */
class SendQueueManager(private val context: Context) {

    enum class State { IDLE, RUNNING, PAUSED, CANCELLING }
    enum class ItemStatus { PENDING, SENDING, SENT, FAILED, SKIPPED }

    data class SendItem(
        val recipientName: String,
        val phoneNumber: String,
        val imageFile: File,
        var status: ItemStatus = ItemStatus.PENDING
    )

    interface Listener {
        fun onQueueStarted(total: Int)
        fun onSendingItem(index: Int, item: SendItem)
        fun onItemCompleted(index: Int, item: SendItem, success: Boolean)
        fun onQueueCompleted(sent: Int, failed: Int, total: Int)
        fun onQueuePaused()
        fun onQueueResumed()
        fun onQueueCancelled()
    }

    var state = State.IDLE
        private set
    var queue: List<SendItem> = emptyList()
        private set
    var currentIndex = 0
        private set
    var targetPackage = "com.whatsapp"
    var delayBetweenSends = 3000L // ms
    var listener: Listener? = null

    private val handler = Handler(Looper.getMainLooper())

    fun start(items: List<SendItem>, whatsappPackage: String) {
        queue = items
        currentIndex = 0
        targetPackage = whatsappPackage
        state = State.RUNNING
        listener?.onQueueStarted(items.size)
        sendNext()
    }

    fun pause() {
        if (state == State.RUNNING) {
            state = State.PAUSED
            listener?.onQueuePaused()
        }
    }

    fun resume() {
        if (state == State.PAUSED) {
            state = State.RUNNING
            listener?.onQueueResumed()
            sendNext()
        }
    }

    fun cancel() {
        state = State.CANCELLING
        handler.removeCallbacksAndMessages(null)
        // Mark remaining as skipped
        for (i in currentIndex until queue.size) {
            if (queue[i].status == ItemStatus.PENDING || queue[i].status == ItemStatus.SENDING) {
                queue[i].status = ItemStatus.SKIPPED
            }
        }
        state = State.IDLE
        listener?.onQueueCancelled()
        cleanup()
    }

    fun onSendCompleted(success: Boolean) {
        if (currentIndex >= queue.size) return

        val item = queue[currentIndex]
        item.status = if (success) ItemStatus.SENT else ItemStatus.FAILED
        listener?.onItemCompleted(currentIndex, item, success)

        currentIndex++

        if (currentIndex >= queue.size || state == State.CANCELLING) {
            finishQueue()
            return
        }

        if (state == State.PAUSED) return

        // Delay before next send
        handler.postDelayed({ sendNext() }, delayBetweenSends)
    }

    private fun sendNext() {
        if (state != State.RUNNING || currentIndex >= queue.size) return

        val item = queue[currentIndex]

        // Validate phone number
        val formattedPhone = formatPhoneForWhatsApp(item.phoneNumber)
        if (formattedPhone == null) {
            item.status = ItemStatus.SKIPPED
            listener?.onItemCompleted(currentIndex, item, false)
            currentIndex++
            if (currentIndex < queue.size) {
                handler.postDelayed({ sendNext() }, 500)
            } else {
                finishQueue()
            }
            return
        }

        item.status = ItemStatus.SENDING
        listener?.onSendingItem(currentIndex, item)

        // Create content URI via FileProvider
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            item.imageFile
        )

        // Build WhatsApp intent
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "image/jpeg"
            setPackage(targetPackage)
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra("jid", formattedPhone)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }

        // Tell accessibility service to watch for send button
        WhatsAppSendAccessibilityService.instance?.startWaitingForSend { success ->
            handler.post { onSendCompleted(success) }
        }

        try {
            context.startActivity(intent)
        } catch (e: Exception) {
            item.status = ItemStatus.FAILED
            listener?.onItemCompleted(currentIndex, item, false)
            currentIndex++
            if (currentIndex < queue.size) {
                handler.postDelayed({ sendNext() }, 1000)
            } else {
                finishQueue()
            }
        }
    }

    private fun finishQueue() {
        state = State.IDLE
        val sent = queue.count { it.status == ItemStatus.SENT }
        val failed = queue.count { it.status == ItemStatus.FAILED || it.status == ItemStatus.SKIPPED }
        listener?.onQueueCompleted(sent, failed, queue.size)
        cleanup()
    }

    private fun cleanup() {
        // Clean up cached image files
        val cacheDir = File(context.cacheDir, "whatsapp_cards")
        if (cacheDir.exists()) {
            cacheDir.listFiles()?.forEach { it.delete() }
        }
    }

    companion object {
        /**
         * Normalize phone number to WhatsApp JID format: <number>@s.whatsapp.net
         * Returns null if the number is invalid.
         */
        fun formatPhoneForWhatsApp(phone: String): String? {
            // Strip everything except digits and leading +
            var cleaned = phone.replace(Regex("[^\\d+]"), "")
            if (cleaned.length < 7) return null

            // Convert Israeli local format (05x) to international
            if (cleaned.startsWith("05") || cleaned.startsWith("07") || cleaned.startsWith("02") || cleaned.startsWith("03") || cleaned.startsWith("04") || cleaned.startsWith("08") || cleaned.startsWith("09")) {
                cleaned = "+972" + cleaned.substring(1)
            }

            // Remove leading +
            if (cleaned.startsWith("+")) {
                cleaned = cleaned.substring(1)
            }

            // Must be at least 7 digits
            if (cleaned.length < 7 || cleaned.length > 15) return null

            return "$cleaned@s.whatsapp.net"
        }
    }
}
