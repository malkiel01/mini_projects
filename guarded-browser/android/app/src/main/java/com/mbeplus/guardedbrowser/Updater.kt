package com.mbeplus.guardedbrowser

import android.app.Activity
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import androidx.appcompat.app.AlertDialog
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import org.json.JSONObject

/**
 * עדכון האפליקציה כשההורה מבקש.
 *
 * השרת מחזיר update רק אחרי שההורה לחץ "עדכון" ויש גרסה חדשה. כאן: הצעה לילד ← הורדה
 * (DownloadManager) ← מסך ההתקנה של אנדרואיד. התקנה שקטה, בלי אישור, אפשרית רק לאפליקציה
 * שהיא "בעלת המכשיר" (שלב 11) — עד אז הילד לוחץ "התקנה".
 *
 * החתימה קבועה מ-1.x, ולכן ההתקנה היא מעל הקיים: החיבור וההגדרות נשמרים.
 * כל כישלון באמצע — נפתח הקישור בדפדפן, ומשם מתקינים ידנית.
 */
object Updater {

    /** הוצע כבר בהרצה הזו של האפליקציה — "אחר כך" לא חוזר על כל חזרה למסך הבית. */
    private var offeredCode = 0
    private var downloadId = -1L

    fun offer(act: Activity, u: JSONObject) {
        val code = u.optInt("version_code")
        val url = u.optString("url")
        if (code <= BuildConfig.VERSION_CODE || url.isEmpty() || code == offeredCode) return
        offeredCode = code
        MaterialAlertDialogBuilder(act)
            .setTitle("⬆️ גרסה חדשה של מגדלור")
            .setMessage("ההורה ביקש לעדכן ל-${u.optString("version_name")} (עכשיו מותקנת ${BuildConfig.VERSION_NAME}).\n\n" +
                        "העדכון לוקח רגע, וכל ההגדרות נשמרות.")
            .setNegativeButton("אחר כך", null)
            .setPositiveButton("עדכון עכשיו") { _, _ -> start(act, url, code) }
            .show()
    }

    private fun start(act: Activity, url: String, code: Int) {
        // אנדרואיד 8+: צריך היתר "התקנת אפליקציות לא מוכרות" — פעם אחת, בהגדרות.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !act.packageManager.canRequestPackageInstalls()) {
            MaterialAlertDialogBuilder(act)
                .setTitle("צריך אישור אחד")
                .setMessage("במסך הבא מדליקים \"לאפשר מהמקור הזה\", וחוזרים. אחר כך לוחצים שוב \"עדכון עכשיו\".")
                .setPositiveButton("להגדרות") { _, _ ->
                    offeredCode = 0
                    try {
                        act.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + act.packageName)))
                    } catch (e: Exception) { browser(act, url) }
                }
                .setNegativeButton("ביטול") { _, _ -> offeredCode = 0 }
                .show()
            return
        }
        try {
            val dm = act.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
            val req = DownloadManager.Request(Uri.parse(url))
                .setTitle("מגדלור — עדכון")
                .setMimeType("application/vnd.android.package-archive")
                .setDestinationInExternalFilesDir(act, Environment.DIRECTORY_DOWNLOADS, "migdalor-$code.apk")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)
            downloadId = dm.enqueue(req)
            watch(act, dm, url)
        } catch (e: Exception) {
            Trace.log("update.fail", e.javaClass.simpleName + ": " + e.message)
            browser(act, url)
        }
    }

    /** בודק כל שנייה אם ההורדה הסתיימה — בלי BroadcastReceiver, שדורש הצהרות שונות בכל גרסת אנדרואיד. */
    private fun watch(act: Activity, dm: DownloadManager, url: String) {
        val main = Handler(Looper.getMainLooper())
        val dlg: AlertDialog = MaterialAlertDialogBuilder(act)
            .setTitle("מוריד את העדכון…")
            .setMessage("רגע אחד.")
            .setCancelable(false)
            .setNegativeButton("ביטול") { _, _ -> dm.remove(downloadId); downloadId = -1L; offeredCode = 0 }
            .show()
        val tick = object : Runnable {
            override fun run() {
                if (downloadId < 0 || act.isFinishing) { dlg.dismiss(); return }
                val c = dm.query(DownloadManager.Query().setFilterById(downloadId))
                val (status, done, total) = c.use {
                    if (!it.moveToFirst()) Triple(DownloadManager.STATUS_FAILED, 0L, 0L)
                    else Triple(it.getInt(it.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)),
                                it.getLong(it.getColumnIndexOrThrow(DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR)),
                                it.getLong(it.getColumnIndexOrThrow(DownloadManager.COLUMN_TOTAL_SIZE_BYTES)))
                }
                when (status) {
                    DownloadManager.STATUS_SUCCESSFUL -> { dlg.dismiss(); install(act, dm, url) }
                    DownloadManager.STATUS_FAILED -> { dlg.dismiss(); browser(act, url) }
                    else -> {
                        if (total > 0) dlg.setMessage("${(done * 100 / total)}%")
                        main.postDelayed(this, 1000)
                    }
                }
            }
        }
        main.postDelayed(tick, 800)
    }

    private fun install(act: Activity, dm: DownloadManager, url: String) {
        try {
            val uri = dm.getUriForDownloadedFile(downloadId) ?: return browser(act, url)
            act.startActivity(Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (e: Exception) {
            Trace.log("update.install", e.javaClass.simpleName + ": " + e.message)
            browser(act, url)
        }
    }

    /** נפילה חזרה: הקישור בדפדפן של המכשיר, ומשם — הורדה והתקנה ידנית. */
    private fun browser(act: Activity, url: String) {
        try { act.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
        catch (e: Exception) { android.widget.Toast.makeText(act, "לא הצלחנו לפתוח את העדכון", android.widget.Toast.LENGTH_LONG).show() }
    }
}
