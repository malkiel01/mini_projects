package com.mbeplus.guardedbrowser

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.job.JobInfo
import android.app.job.JobParameters
import android.app.job.JobScheduler
import android.app.job.JobService
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat

/**
 * בדיקת עדכון ברקע — גם כשמגדלור סגור. אנדרואיד מריץ אותה רק כשיש רשת (לפחות כל 15 דקות),
 * ושומר אותה גם אחרי הפעלה מחדש של הטלפון.
 *
 * המדיניות (הגדרות המכשיר נאכפות מיד) וה-update. יש עדכון — "בעל מכשיר" מעדכן מיד, בשקט; אחרת קופצת הודעה
 * במסך מלא שפותחת את מסך העדכון, ושירות הנגישות מקפיץ אותו גם בכל מעבר בין אפליקציות.
 */
class UpdateJob : JobService() {

    override fun onStartJob(params: JobParameters): Boolean {
        val store = Store(this)
        if (!store.isLoggedIn) return false
        // המדיניות המלאה ולא פעימה: כך גם הגדרות המכשיר שההורה שינה נאכפות ב"בעל מכשיר" בלי שהילד יפתח
        // את מגדלור (Enforcer), והתשובה כוללת גם את העדכון.
        Api.policy(store.token) { r ->
            if (r.ok && r.json != null) PolicySync.apply(this, r.json, "job")
            if (Updater.required(this)) {
                if (Enforcer.isOwner(this)) Updater.run(this) else notifyUpdate(this)
            }
            jobFinished(params, false)
        }
        return true
    }

    override fun onStopJob(params: JobParameters): Boolean = true

    companion object {
        private const val JOB_ID = 4711
        private const val CHANNEL = "update"

        /** נקרא מ-GuardApp. לא מחליף משימה שכבר מתוזמנת — אחרת כל פתיחה הייתה מאפסת את המונה. */
        fun schedule(ctx: Context) {
            val js = ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE) as JobScheduler
            if (js.getPendingJob(JOB_ID) != null) return
            val job = JobInfo.Builder(JOB_ID, ComponentName(ctx, UpdateJob::class.java))
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setPeriodic(15 * 60 * 1000L)
                .setPersisted(true)
                .build()
            try { js.schedule(job) } catch (e: Exception) { Trace.log("update.job", e.message ?: "") }
        }

        /** העדכון הותקן (או בוטל) — ההודעה יורדת. */
        fun clear(ctx: Context) {
            (ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager).cancel(JOB_ID)
        }

        /** הודעה שקופצת מעל הכול ופותחת את מסך העדכון. */
        fun notifyUpdate(ctx: Context) {
            val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "עדכון מגדלור", NotificationManager.IMPORTANCE_HIGH))
            val open = PendingIntent.getActivity(ctx, 0,
                Intent(ctx, UpdateActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
            val n = NotificationCompat.Builder(ctx, CHANNEL)
                .setSmallIcon(android.R.drawable.stat_sys_download)
                .setContentTitle("⬆️ צריך לעדכן את מגדלור")
                .setContentText("עד שהעדכון מותקן אי אפשר להשתמש באפליקציה")
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setCategory(NotificationCompat.CATEGORY_REMINDER)
                .setContentIntent(open)
                .setFullScreenIntent(open, true)
                .setOngoing(true)
                .build()
            try { nm.notify(JOB_ID, n) } catch (e: SecurityException) { Trace.log("update.notify", e.message ?: "") }
        }
    }
}
