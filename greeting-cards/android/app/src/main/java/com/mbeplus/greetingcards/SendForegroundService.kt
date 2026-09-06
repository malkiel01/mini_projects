package com.mbeplus.greetingcards

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat

/**
 * Foreground Service that keeps the app alive while WhatsApp is in the
 * foreground and sends are in progress. Shows a notification with
 * real-time progress.
 */
class SendForegroundService : Service(), SendQueueManager.Listener {

    companion object {
        private const val CHANNEL_ID = "greeting_cards_send"
        private const val NOTIFICATION_ID = 1001
        private const val ACTION_PAUSE = "com.mbeplus.greetingcards.PAUSE"
        private const val ACTION_CANCEL = "com.mbeplus.greetingcards.CANCEL"
        private const val ACTION_RESUME = "com.mbeplus.greetingcards.RESUME"

        var instance: SendForegroundService? = null
            private set
    }

    val queueManager = SendQueueManager(this)
    private lateinit var notificationManager: NotificationManager

    override fun onCreate() {
        super.onCreate()
        instance = this
        notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        createNotificationChannel()
        queueManager.listener = this
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_PAUSE -> queueManager.pause()
            ACTION_RESUME -> queueManager.resume()
            ACTION_CANCEL -> {
                queueManager.cancel()
                stopSelf()
            }
        }
        return START_STICKY
    }

    fun startSending(items: List<SendQueueManager.SendItem>, whatsappPackage: String, delay: Long) {
        queueManager.delayBetweenSends = delay
        val notification = buildNotification("מתחיל שליחה...", 0, items.size, false)
        startForeground(NOTIFICATION_ID, notification)
        queueManager.start(items, whatsappPackage)
    }

    override fun onQueueStarted(total: Int) {
        updateNotification("מתחיל שליחה...", 0, total, false)
    }

    override fun onSendingItem(index: Int, item: SendQueueManager.SendItem) {
        val text = getString(R.string.sending_progress, index + 1, queueManager.queue.size, item.recipientName)
        updateNotification(text, index + 1, queueManager.queue.size, false)
    }

    override fun onItemCompleted(index: Int, item: SendQueueManager.SendItem, success: Boolean) {
        // Notification already updated by onSendingItem for the next item
    }

    override fun onQueueCompleted(sent: Int, failed: Int, total: Int) {
        val text = getString(R.string.send_complete, sent) +
                if (failed > 0) " ($failed נכשלו)" else ""
        updateNotification(text, total, total, true)
        // Stop service after a delay to let user see the completion notification
        android.os.Handler(mainLooper).postDelayed({ stopSelf() }, 5000)
    }

    override fun onQueuePaused() {
        updateNotification(getString(R.string.send_paused), queueManager.currentIndex, queueManager.queue.size, false, paused = true)
    }

    override fun onQueueResumed() {
        val item = queueManager.queue.getOrNull(queueManager.currentIndex)
        val text = if (item != null) getString(R.string.sending_progress, queueManager.currentIndex + 1, queueManager.queue.size, item.recipientName) else "ממשיך..."
        updateNotification(text, queueManager.currentIndex, queueManager.queue.size, false)
    }

    override fun onQueueCancelled() {
        updateNotification(getString(R.string.send_cancelled), queueManager.currentIndex, queueManager.queue.size, true)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                getString(R.string.notification_channel_name),
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = getString(R.string.notification_channel_desc)
            }
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String, progress: Int, max: Int, finished: Boolean, paused: Boolean = false): android.app.Notification {
        val openIntent = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_menu_send)
            .setContentTitle("כרטיסי ברכה")
            .setContentText(text)
            .setContentIntent(openIntent)
            .setOngoing(!finished)
            .setProgress(max, progress, false)

        if (!finished) {
            if (paused) {
                val resumeIntent = PendingIntent.getService(this, 1,
                    Intent(this, SendForegroundService::class.java).setAction(ACTION_RESUME),
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
                builder.addAction(android.R.drawable.ic_media_play, "המשך", resumeIntent)
            } else {
                val pauseIntent = PendingIntent.getService(this, 1,
                    Intent(this, SendForegroundService::class.java).setAction(ACTION_PAUSE),
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
                builder.addAction(android.R.drawable.ic_media_pause, "השהה", pauseIntent)
            }

            val cancelIntent = PendingIntent.getService(this, 2,
                Intent(this, SendForegroundService::class.java).setAction(ACTION_CANCEL),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            builder.addAction(android.R.drawable.ic_delete, "בטל", cancelIntent)
        }

        return builder.build()
    }

    private fun updateNotification(text: String, progress: Int, max: Int, finished: Boolean, paused: Boolean = false) {
        notificationManager.notify(NOTIFICATION_ID, buildNotification(text, progress, max, finished, paused))
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        instance = null
        super.onDestroy()
    }
}
