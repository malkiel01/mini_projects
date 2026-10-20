package com.mbeplus.projectmanager

import android.annotation.SuppressLint
import android.app.Activity
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.ContentValues
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.MediaStore
import android.util.Base64
import android.view.Gravity
import android.view.View
import android.view.WindowInsets
import android.window.OnBackInvokedDispatcher
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.core.content.FileProvider
import androidx.webkit.ServiceWorkerClientCompat
import androidx.webkit.ServiceWorkerControllerCompat
import androidx.webkit.WebViewFeature
import java.io.File
import java.io.FileInputStream

/**
 * האתר של ניהול הפרויקטים בתוך WebView — אותם מסכים, אותה התחברות (עוגייה נפרדת מהדפדפן).
 * מה שהאפליקציה מוסיפה על האתר:
 *   • שיתוף אליה מכל אפליקציה (ShareInbox) — נפתח מסך "לאן לשמור?" של האתר (#/share).
 *   • קיצורי שיתוף ישיר לתיקיות האחרונות (Shortcuts) — האתר מעדכן אותם דרך PMNative.setRecent.
 *   • צילום / בחירת קבצים לשדות הקובץ, הורדות, קישורים חיצוניים (וואטסאפ, טלפון, מייל) ו"חזרה".
 *   • קישור לאתר (App Link) נטען כמו שהוא — כולל ?platform-handoff של הכלים הדיגיטליים (platform/docs/auth.md).
 */
class MainActivity : Activity() {
    companion object {
        const val EXTRA_HASH = "pm.hash"
        private const val PICK = 7
        private const val NATIVE_PATH = "__native_share/"
    }

    private lateinit var web: WebView
    private lateinit var offline: View
    private val base: String = BuildConfig.WEB_URL
    private var filePick: ValueCallback<Array<Uri>>? = null
    private var cameraOut: Uri? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        web = WebView(this)
        offline = offlineView()
        val root = FrameLayout(this).apply { addView(web); addView(offline) }
        setContentView(root)
        // targetSdk 35 ומעלה: אנדרואיד 15 מצייר את האפליקציה מתחת לשורת המצב, לשורת הניווט ולמקלדת (edge-to-edge,
        // ו-adjustResize כבר לא מקטין את החלון). מרווח לפי ה-insets — כך האתר לא נחתך, והשדה נשאר מעל המקלדת.
        if (Build.VERSION.SDK_INT >= 30) root.setOnApplyWindowInsetsListener { v, insets ->
            val bars = insets.getInsets(WindowInsets.Type.systemBars() or WindowInsets.Type.ime() or WindowInsets.Type.displayCutout())
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            WindowInsets.CONSUMED
        }
        // targetSdk 36: "חזרה" כבר לא מגיעה ל-onBackPressed (predictive back) — נרשמים אליה כאן
        if (Build.VERSION.SDK_INT >= 33) {
            onBackInvokedDispatcher.registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT) { back() }
        }

        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            mediaPlaybackRequiresUserGesture = false
            userAgentString = "$userAgentString PMAndroid/${BuildConfig.VERSION_CODE}"
        }
        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false)
        web.addJavascriptInterface(Bridge(), "PMNative")
        web.webViewClient = Client()
        web.webChromeClient = Chrome()
        web.setDownloadListener { url, ua, disposition, mime, _ -> download(url, ua, disposition, mime) }
        // הבקשות שעוברות דרך ה-service worker של האתר (sw.js) — גם אותן מגישים מהשיתוף המקומי
        if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_BASIC_USAGE)) {
            ServiceWorkerControllerCompat.getInstance().setServiceWorkerClient(object : ServiceWorkerClientCompat() {
                override fun shouldInterceptRequest(request: WebResourceRequest) = native(request.url)
            })
        }

        val shared = ShareInbox.receive(this, intent)
        web.loadUrl(link(intent) ?: (base + (if (shared) "#/share" else intent.getStringExtra(EXTRA_HASH) ?: "")))
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        link(intent)?.let { web.loadUrl(it); return }
        val hash = if (ShareInbox.receive(this, intent)) "#/share" else intent.getStringExtra(EXTRA_HASH) ?: return
        // האתר כבר פתוח — רק מעבירים מסך (אם כבר ב-#/share — מכריחים ציור מחדש)
        web.evaluateJavascript("(function(h){ if (location.hash === h) dispatchEvent(new HashChangeEvent('hashchange')); else location.hash = h; })('$hash')", null)
    }

    /** קישור לאתר שנפתח באפליקציה (VIEW) — הכתובת כמו שהיא, עם ?platform-handoff אם יש; אחרת null. */
    private fun link(intent: Intent?): String? {
        val url = intent?.takeIf { it.action == Intent.ACTION_VIEW }?.dataString ?: return null
        return url.takeIf { it.startsWith(base) }
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
    }

    @Deprecated("Deprecated in Java")
    @Suppress("DEPRECATION")
    override fun onBackPressed() = back() // אנדרואיד 12 ומטה

    private fun back() {
        if (offline.visibility == View.VISIBLE) { finish(); return }
        // "חזרה" של האתר: במסך פנימי — למסך הקודם; במסך הראשי — יוצאים
        web.evaluateJavascript("(function(){ var b = document.getElementById('back'); if (b && !b.hidden && location.hash && location.hash !== '#/') { b.click(); return 1; } return 0; })()") {
            if (it != "1") { if (web.canGoBack()) web.goBack() else finish() }
        }
    }

    /** קובץ ששותף — מוגש לאתר כאילו הגיע מהשרת, באותו מקור (בלי CORS ובלי רשת). */
    private fun native(url: Uri): WebResourceResponse? {
        val s = url.toString()
        if (!s.startsWith(base + NATIVE_PATH)) return null
        val i = s.removePrefix(base + NATIVE_PATH).substringBefore('?').toIntOrNull() ?: return null
        val (f, type) = ShareInbox.file(this, i) ?: return WebResourceResponse("text/plain", "utf-8", 404, "Not Found", emptyMap(), null)
        return WebResourceResponse(type, null, 200, "OK", mapOf("Cache-Control" to "no-store"), FileInputStream(f))
    }

    // ---- הגשר לאתר: window.PMNative ----

    inner class Bridge {
        /** מה ששותף ועוד לא נשמר: {files:[{i,name,type,size}], text, subject, target, base} */
        @JavascriptInterface fun sharedItems(): String =
            org.json.JSONObject(ShareInbox.json(this@MainActivity)).put("base", base + NATIVE_PATH).toString()
        @JavascriptInterface fun clearShared() = ShareInbox.clear(this@MainActivity)
        /** התיקיות האחרונות (מערך מ-share-recent) — לקיצורי השיתוף הישיר */
        @JavascriptInterface fun setRecent(json: String) = Shortcuts.update(this@MainActivity, json)
        @JavascriptInterface fun version(): Int = BuildConfig.VERSION_CODE
        /** הורדה של קובץ שנוצר בדפדפן (blob:) — מגיע כ-data URL ונשמר בהורדות */
        @JavascriptInterface fun saveFile(name: String, mime: String, dataUrl: String) {
            runOnUiThread { saveBytes(name, mime, Base64.decode(dataUrl.substringAfter(","), Base64.DEFAULT)) }
        }
    }

    private inner class Client : WebViewClient() {
        override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest) = native(request.url)

        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val url = request.url
            val inside = (url.scheme == "https" || url.scheme == "http") && url.host == Uri.parse(base).host
            // הכלים הדיגיטליים (platform/) — בדפדפן, שם המשתמש מחובר; משם "כניסה עם הכלים הדיגיטליים" חוזרת לכאן
            // עם ?platform-handoff. sso.php נשאר בפנים: הכניסה המאוחדת של האתר חוזרת אליו עם token באותה עוגייה.
            val platform = inside && (url.path ?: "").startsWith("/mini_projects/platform/") &&
                !(url.path ?: "").startsWith("/mini_projects/platform/sso.php")
            if (inside && !platform) return false
            // וואטסאפ, טלפון, מייל, ניווט ואתרים אחרים — באפליקציה המתאימה
            try {
                val i = if (url.scheme == "intent") Intent.parseUri(url.toString(), Intent.URI_INTENT_SCHEME) else Intent(Intent.ACTION_VIEW, url)
                startActivity(i.apply { addCategory(Intent.CATEGORY_BROWSABLE); component = null; selector = null })
            } catch (_: Exception) { /* אין אפליקציה מתאימה */ }
            return true
        }

        override fun onPageFinished(view: WebView, url: String) { if (url.startsWith(base)) offline.visibility = View.GONE }

        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            if (request.isForMainFrame) offline.visibility = View.VISIBLE
        }
    }

    private inner class Chrome : WebChromeClient() {
        override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
            filePick?.onReceiveValue(null)
            filePick = callback
            val accept = params.acceptTypes.filter { it.isNotBlank() }.joinToString(",")
            val wantsVideo = accept.contains("video")
            val wantsImage = accept.isEmpty() || accept.contains("image") || accept.contains("*")
            // מצלמה: תמונה לקובץ זמני (FileProvider); סרטון — המצלמה מחזירה בעצמה
            val camera = mutableListOf<Intent>()
            if (wantsImage) {
                val f = File(File(cacheDir, "camera").apply { mkdirs() }, "photo-${System.currentTimeMillis()}.jpg")
                cameraOut = FileProvider.getUriForFile(this@MainActivity, "$packageName.files", f)
                camera += Intent(MediaStore.ACTION_IMAGE_CAPTURE).putExtra(MediaStore.EXTRA_OUTPUT, cameraOut)
                    .addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
            } else cameraOut = null
            if (wantsVideo) camera += Intent(MediaStore.ACTION_VIDEO_CAPTURE)
            val pick = params.createIntent().apply {
                if (params.mode == FileChooserParams.MODE_OPEN_MULTIPLE) putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true)
            }
            // capture באתר (למשל "📷 צילום" בסיור) — ישר למצלמה
            val intent = if (params.isCaptureEnabled && camera.isNotEmpty()) camera.first()
                else Intent.createChooser(pick, getString(R.string.pick)).putExtra(Intent.EXTRA_INITIAL_INTENTS, camera.toTypedArray())
            return try { startActivityForResult(intent, PICK); true } catch (_: ActivityNotFoundException) { filePick = null; false }
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != PICK) return
        val cb = filePick ?: return
        filePick = null
        if (resultCode != RESULT_OK) { cb.onReceiveValue(null); return }
        val uris = mutableListOf<Uri>()
        data?.clipData?.let { c -> for (k in 0 until c.itemCount) uris += c.getItemAt(k).uri }
        data?.data?.let { if (it !in uris) uris += it }
        // תמונה מהמצלמה — בקובץ שהכנו (אין data)
        if (uris.isEmpty()) cameraOut?.let { out -> if (File(File(cacheDir, "camera"), out.lastPathSegment ?: "").length() > 0) uris += out }
        cb.onReceiveValue(if (uris.isEmpty()) null else uris.toTypedArray())
    }

    // ---- הורדות ----

    private fun download(url: String, ua: String, disposition: String?, mime: String?) {
        val name = URLUtil.guessFileName(url, disposition, mime)
        if (url.startsWith("blob:")) {
            // קובץ שנוצר בדף (למשל דוח CSV) — מבקשים מהדף את התוכן
            web.evaluateJavascript("""
                fetch('$url').then(r => r.blob()).then(b => new Promise(ok => { const f = new FileReader(); f.onload = () => ok([b.type, f.result]); f.readAsDataURL(b); }))
                  .then(([t, d]) => PMNative.saveFile(document.querySelector('a[href="$url"]')?.download || '$name', t, d));
            """.trimIndent(), null)
            return
        }
        try {
            val req = DownloadManager.Request(Uri.parse(url))
                .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
                .addRequestHeader("User-Agent", ua)
                .setMimeType(mime)
                .setTitle(name)
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
            (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).enqueue(req)
            Toast.makeText(this, getString(R.string.saved_download, name), Toast.LENGTH_SHORT).show()
        } catch (_: Exception) {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        }
    }

    private fun saveBytes(name: String, mime: String, bytes: ByteArray) {
        try {
            if (Build.VERSION.SDK_INT >= 29) {
                val v = ContentValues().apply {
                    put(MediaStore.Downloads.DISPLAY_NAME, name)
                    put(MediaStore.Downloads.MIME_TYPE, mime.ifBlank { "application/octet-stream" })
                }
                val uri = contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v) ?: return
                contentResolver.openOutputStream(uri)?.use { it.write(bytes) }
            } else {
                @Suppress("DEPRECATION")
                File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), name).writeBytes(bytes)
            }
            Toast.makeText(this, getString(R.string.saved_download, name), Toast.LENGTH_SHORT).show()
        } catch (_: Exception) { }
    }

    // ---- בלי אינטרנט ----

    private fun offlineView(): View = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        gravity = Gravity.CENTER
        setPadding(64, 64, 64, 64)
        setBackgroundColor(0xFFF4F5F7.toInt())
        visibility = View.GONE
        addView(TextView(context).apply { text = getString(R.string.offline); textSize = 17f; gravity = Gravity.CENTER; setTextColor(0xFF16192B.toInt()) })
        addView(Button(context).apply { text = getString(R.string.retry); setOnClickListener { web.reload() } })
    }
}
