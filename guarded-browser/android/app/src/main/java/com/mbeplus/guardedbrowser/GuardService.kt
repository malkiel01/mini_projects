package com.mbeplus.guardedbrowser

import android.app.Notification
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
 * שירות חזית שמחזיק את האפליקציה בחיים כשהיא אינה על המסך.
 *
 * בלעדיו אנדרואיד רשאי להרוג את התהליך ברגע שהמשתמש יוצא, והצפייה
 * נקטעת — אבל חשוב מכך, גם האכיפה נקטעת: הפעימה שסופרת את מכסת
 * הזמן ומקבלת השעיה מהשרת מפסיקה לרוץ. שירות שרק "משמיע מוזיקה"
 * היה מספיק לצפייה; כאן הוא מה שמאפשר להמשיך לאכוף.
 *
 * ההתראה אינה תוספת נוחות אלא דרישה של המערכת — וגם דבר נכון:
 * אפליקציה שממשיכה לרוץ אחרי שסגרו אותה צריכה לומר זאת.
 */
class GuardService : Service() {

    companion object {
        private const val CHANNEL = "gb_guard"
        private const val ID = 4711

        const val ACTION_STOP = "com.mbeplus.guardedbrowser.STOP"

        fun start(context: Context, text: String) {
            val i = Intent(context, GuardService::class.java).putExtra("text", text)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(i)
            } else {
                context.startService(i)
            }
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, GuardService::class.java))
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }
        ensureChannel()
        startForeground(ID, build(intent?.getStringExtra("text") ?: "הצפייה ממשיכה ברקע"))

        /*
         * ‏START_NOT_STICKY: אם המערכת הרגה את התהליך, אין טעם
         * להחיות שירות בלי הדפדפן שהוא נועד לשרת — הוא היה מוצג
         * כהתראה קבועה שאינה עושה דבר.
         */
        return START_NOT_STICKY
    }

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val mgr = getSystemService(NotificationManager::class.java)
        if (mgr.getNotificationChannel(CHANNEL) != null) return

        // ‏LOW: נוכחת ושקטה. התראה שמצלצלת בכל יציאה מהאפליקציה
        // הופכת את הפיצ'ר למטרד.
        mgr.createNotificationChannel(NotificationChannel(
            CHANNEL, "צפייה ברקע", NotificationManager.IMPORTANCE_LOW).apply {
            description = "מוצגת כשהדפדפן ממשיך לרוץ מחוץ לאפליקציה"
            setShowBadge(false)
        })
    }

    private fun build(text: String): Notification {
        val open = PendingIntent.getActivity(this, 0,
            Intent(this, HomeActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_IMMUTABLE)

        val stop = PendingIntent.getService(this, 1,
            Intent(this, GuardService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_IMMUTABLE)

        return NotificationCompat.Builder(this, CHANNEL)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_media_play)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setContentIntent(open)
            .addAction(0, "עצירה", stop)
            .build()
    }

    override fun onDestroy() {
        // סגירת ההתראה עוצרת גם את הצפייה: שירות שנעצר בלי לעצור
        // את מה שהוא החזיק היה משאיר קול מתנגן בלי שום דרך לעצור.
        sendBroadcast(Intent(ACTION_STOP).setPackage(packageName))
        super.onDestroy()
    }
}
