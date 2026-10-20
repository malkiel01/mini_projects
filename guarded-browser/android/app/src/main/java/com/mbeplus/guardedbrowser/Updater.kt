package com.mbeplus.guardedbrowser

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import android.os.Handler
import android.os.Looper
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

/**
 * עדכון חובה.
 *
 * השרת מחזיר update בכל תשובה (מדיניות ופעימה) כשיש גרסה חדשה מזו שמותקנת. מאותו רגע האפליקציה
 * נעולה: כל מסך מפנה ל-UpdateActivity, ואין דרך להמשיך בלי להתקין. הנעילה נשמרת (Store.updateCode),
 * כך שהפעלה מחדש או ניתוק מהרשת לא משחררים אותה. היא משתחררת רק כשהגרסה שרצה היא החדשה.
 *
 *   • "בעל מכשיר" — העדכון שקט, בלי ידיעת הילד: הורדה והתקנה ברקע (PackageInstaller).
 *   • בלי "בעל מכשיר" — אנדרואיד מחייב אישור של מי שמחזיק את הטלפון. מסך העדכון לא נסגר עד שמאשרים.
 *
 * הבדיקה רצה גם כשהאפליקציה סגורה: UpdateJob, בכל פעם שיש רשת (לפחות כל 15 דקות).
 * החתימה קבועה, ולכן ההתקנה היא מעל הקיים: החיבור וההגדרות נשמרים.
 */
object Updater {

    private val pool = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())
    @Volatile private var running = false

    /** העדכון מהשרת (או null — אין). נקרא מכל תשובה של מדיניות ופעימה. */
    fun note(ctx: Context, u: JSONObject?) {
        val store = Store(ctx)
        val code = u?.optInt("version_code") ?: 0
        val url = u?.optString("url").orEmpty()
        if (code > BuildConfig.VERSION_CODE && url.startsWith("https://")) {
            store.updateCode = code
            store.updateUrl = url
        } else if (u == null || code <= BuildConfig.VERSION_CODE) {
            store.updateCode = 0
            UpdateJob.clear(ctx)
        }
    }

    /** האם האפליקציה נעולה עד עדכון. */
    fun required(ctx: Context): Boolean = Store(ctx).updateCode > BuildConfig.VERSION_CODE

    /** מסך העדכון — מעל כל מה שפתוח. נקרא מכל מסך, ומשירות הנגישות. */
    fun lock(ctx: Context) {
        ctx.startActivity(Intent(ctx, UpdateActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP))
    }

    /**
     * הורדה והתקנה. ‏progress — טקסט למסך העדכון (בתהליך הראשי); בלי מסך (UpdateJob) — null.
     * ריצה אחת בכל רגע: לחיצה חוזרת או בדיקת רקע באמצע הורדה לא מתחילות הורדה שנייה.
     */
    fun run(ctx: Context, progress: ((String) -> Unit)? = null) {
        val app = ctx.applicationContext
        val store = Store(app)
        if (!required(app) || running) return
        running = true
        val say = { t: String -> progress?.let { p -> main.post { p(t) } } }
        pool.execute {
            try {
                say("מוריד את הגרסה החדשה…")
                val apk = download(app, store.updateUrl, store.updateCode) { pct -> say("מוריד… $pct%") }
                say("מתקין…")
                install(app, apk)
            } catch (e: Exception) {
                Trace.log("update.fail", e.javaClass.simpleName + ": " + e.message)
                say("העדכון נכשל — בודקים שיש אינטרנט, ולוחצים שוב.")
            } finally {
                running = false
            }
        }
    }

    /** ההורדה — לתיקיית המטמון של האפליקציה. APK הוא ZIP: מתחיל ב-PK. */
    private fun download(ctx: Context, url: String, code: Int, pct: (Int) -> Unit): File {
        val dir = File(ctx.cacheDir, "update").apply { mkdirs() }
        dir.listFiles()?.forEach { if (it.name != "migdalor-$code.apk") it.delete() }
        val file = File(dir, "migdalor-$code.apk")
        if (file.length() > 1_000_000 && isZip(file)) return file

        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 15_000; readTimeout = 30_000; instanceFollowRedirects = true
        }
        try {
            if (conn.responseCode != 200) throw IllegalStateException("HTTP ${conn.responseCode}")
            val total = conn.contentLengthLong
            val tmp = File(dir, file.name + ".part")
            conn.inputStream.use { input ->
                tmp.outputStream().use { out ->
                    val buf = ByteArray(64 * 1024)
                    var done = 0L; var last = -1
                    while (true) {
                        val n = input.read(buf); if (n < 0) break
                        out.write(buf, 0, n); done += n
                        if (total > 0) { val p = (done * 100 / total).toInt(); if (p != last) { last = p; pct(p) } }
                    }
                }
            }
            if (tmp.length() < 1_000_000 || !isZip(tmp)) { tmp.delete(); throw IllegalStateException("לא APK") }
            tmp.renameTo(file)
            return file
        } finally { conn.disconnect() }
    }

    private fun isZip(f: File) = f.inputStream().use { val b = ByteArray(2); it.read(b) == 2 && b[0] == 'P'.code.toByte() && b[1] == 'K'.code.toByte() }

    /**
     * ההתקנה. "בעל מכשיר" — אנדרואיד מתקין בלי לשאול (מ-12 גם מבקשים זאת במפורש). אחרת התוצאה היא
     * STATUS_PENDING_USER_ACTION, ו-InstallResult פותח את מסך האישור של אנדרואיד.
     */
    private fun install(ctx: Context, apk: File) {
        val installer = ctx.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(ctx.packageName)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
        }
        val id = installer.createSession(params)
        installer.openSession(id).use { session ->
            session.openWrite("migdalor.apk", 0, apk.length()).use { out ->
                apk.inputStream().use { it.copyTo(out) }
                session.fsync(out)
            }
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or
                (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0)
            val done = PendingIntent.getBroadcast(ctx, id, Intent(ctx, InstallResult::class.java), flags)
            session.commit(done.intentSender)
        }
        Trace.log("update.commit", "owner=" + Enforcer.isOwner(ctx))
    }

    /** התוצאה של ההתקנה. הצלחה — התהליך מוחלף בגרסה החדשה, ואין מה לעשות. */
    class InstallResult : BroadcastReceiver() {
        override fun onReceive(ctx: Context, intent: Intent) {
            when (val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)) {
                PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                    @Suppress("DEPRECATION")
                    val confirm = intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT) ?: return
                    try { ctx.startActivity(confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                    catch (e: Exception) { Trace.log("update.confirm", e.message ?: "") }
                }
                PackageInstaller.STATUS_SUCCESS -> Trace.log("update.ok", "")
                else -> Trace.log("update.status", "$status " + intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE))
            }
        }
    }
}
