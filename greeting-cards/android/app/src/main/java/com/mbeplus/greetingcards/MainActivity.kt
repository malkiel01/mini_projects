package com.mbeplus.greetingcards

import android.Manifest
import android.app.Activity
import android.app.DownloadManager
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import android.provider.Settings
import android.webkit.*
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.IntentCompat
import org.json.JSONArray
import org.json.JSONObject
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.mbeplus.greetingcards.databinding.ActivityMainBinding

/**
 * WebView wrapper for the Greeting Cards web app.
 *
 * Features:
 * - Full-screen WebView loading the greeting cards site
 * - File upload support (images, fonts, ZIP files)
 * - Download support (ZIP export)
 * - WhatsApp auto-send via JavaScript bridge
 * - Back navigation within the web app
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var bridge: WhatsAppBridge
    private var fileUploadCallback: ValueCallback<Array<Uri>>? = null

    companion object {
        private const val FILE_CHOOSER_REQUEST = 1001
        private const val CONTACTS_PERMISSION_REQUEST = 1002
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // מ-targetSdk 35 האפליקציה מצוירת מתחת לשורת המצב ולסרגל הניווט (edge-to-edge). הריווח מחזיר
        // את הדף לשטח הגלוי — וגם מעל המקלדת, כדי ששדה טקסט בתחתית הדף לא יוסתר.
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { v, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime())
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            WindowInsetsCompat.CONSUMED
        }

        // "חזרה" חוזרת בתוך הדף. onBackPressed כבר לא נקרא באנדרואיד 16 עם targetSdk 36 (predictive back).
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (binding.webView.canGoBack()) {
                    binding.webView.goBack()
                } else {
                    isEnabled = false
                    onBackPressedDispatcher.onBackPressed()
                }
            }
        })

        setupWebView()
        val shared = captureShare(intent)
        binding.webView.loadUrl(launchUrl(intent) ?: (BuildConfig.WEB_URL + if (shared) "#shared" else ""))
    }

    // ‏launchMode="singleTask": "פתיחה" כשהאפליקציה כבר פתוחה מגיעה לכאן ולא ל-onCreate.
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        launchUrl(intent)?.let { binding.webView.loadUrl(it) }
        if (captureShare(intent)) binding.webView.loadUrl(BuildConfig.WEB_URL + "#shared")
    }

    /**
     * ‏"שיתוף" מאפליקציה אחרת: התמונות והטקסט נקראים כאן (לדף אין גישה ל-content://) ונשמרים בגשר, והדף
     * לוקח אותם ב-takeShared(). עד 10 קבצים, עד 25MB כל אחד — כמו בייבוא רגיל.
     */
    private fun captureShare(intent: Intent?): Boolean {
        if (intent == null || (intent.action != Intent.ACTION_SEND && intent.action != Intent.ACTION_SEND_MULTIPLE)) return false
        val uris = mutableListOf<Uri>()
        if (intent.action == Intent.ACTION_SEND) {
            IntentCompat.getParcelableExtra(intent, Intent.EXTRA_STREAM, Uri::class.java)?.let { uris.add(it) }
        } else {
            IntentCompat.getParcelableArrayListExtra(intent, Intent.EXTRA_STREAM, Uri::class.java)?.let { uris.addAll(it) }
        }
        val files = JSONArray()
        for ((i, uri) in uris.take(10).withIndex()) {
            try {
                val mime = contentResolver.getType(uri) ?: "application/octet-stream"
                val bytes = contentResolver.openInputStream(uri)?.use { input ->
                    val buf = input.readBytes()
                    if (buf.size > 25 * 1024 * 1024) null else buf
                } ?: continue
                val ext = when { mime.contains("png") -> "png"; mime.contains("webp") -> "webp"; mime.contains("json") -> "json"; mime.contains("zip") || mime.contains("octet") -> "gcard"; mime.startsWith("image/") -> "jpg"; else -> "gcard" }
                files.put(JSONObject().put("name", uri.lastPathSegment?.substringAfterLast('/')?.takeIf { it.contains('.') } ?: "shared-${i + 1}.$ext")
                    .put("mime", mime).put("base64", android.util.Base64.encodeToString(bytes, android.util.Base64.NO_WRAP)))
            } catch (e: Exception) {
                // קובץ שאי אפשר לקרוא — מדלגים; השאר נכנסים.
            }
        }
        val text = intent.getStringExtra(Intent.EXTRA_TEXT) ?: ""
        if (files.length() == 0 && text.isBlank()) return false
        bridge.pendingShare = JSONObject().put("files", files).put("text", text).toString()
        return true
    }

    /**
     * ‏כתובת הכלי שהאפליקציה נפתחה בה (intent של VIEW), כמו שהיא — כולל ?platform-handoff=<קוד>,
     * שבו השער מחבר את המשתמש. כל כתובת אחרת, או פתיחה מהאייקון — null.
     */
    private fun launchUrl(intent: Intent?): String? {
        val uri = intent?.data ?: return null
        if (intent.action != Intent.ACTION_VIEW) return null
        val ok = uri.scheme == "https" && uri.host == "mbe-plus.com" &&
            (uri.path ?: "").startsWith("/mini_projects/greeting-cards/")
        return if (ok) uri.toString() else null
    }

    private fun setupWebView() {
        val webView = binding.webView

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = true
            allowContentAccess = true
            loadWithOverviewMode = true
            useWideViewPort = true
            setSupportZoom(true)
            builtInZoomControls = true
            displayZoomControls = false
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            cacheMode = WebSettings.LOAD_DEFAULT
        }

        // Add JavaScript bridge for WhatsApp sending
        bridge = WhatsAppBridge(this)
        webView.addJavascriptInterface(bridge, "GreetingCardsAndroid")

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                // ‏רק כתובות האפליקציה עצמה נשארות כאן. הכלים הדיגיטליים (/mini_projects/) הם מעטפת
                // הפיתוח, לא חלק מהאפליקציה — נפתחים בחוץ. גם ההתחברות לשער: הוא מפנה את ה-WebView
                // ל-platform/handoff.php, שנפתח בדפדפן ומחזיר לכאן כתובת עם ?platform-handoff=<קוד>.
                val path = request.url.path ?: ""
                val inApp = request.url.scheme == "https" && request.url.host == "mbe-plus.com" &&
                    path.startsWith("/mini_projects/greeting-cards/")
                return if (inApp) {
                    false
                } else {
                    startActivity(Intent(Intent.ACTION_VIEW, request.url))
                    true
                }
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                fileUploadCallback?.onReceiveValue(null)
                fileUploadCallback = filePathCallback

                // בחירת תמונות בלבד — הבורר הרגיל (גלריה). כל השאר — כל סוגי הקבצים: קובץ ‎.gcard מהצ'אט
                // מגיע כ-application/octet-stream, ובורר שמסנן לפי סוג פשוט לא מציג אותו.
                val types = fileChooserParams.acceptTypes.filter { it.isNotBlank() }
                val intent = if (types.isNotEmpty() && types.all { it.startsWith("image/") }) {
                    fileChooserParams.createIntent()
                } else {
                    Intent(Intent.ACTION_GET_CONTENT).apply {
                        addCategory(Intent.CATEGORY_OPENABLE)
                        type = "*/*"
                        putExtra(Intent.EXTRA_ALLOW_MULTIPLE, fileChooserParams.mode == FileChooserParams.MODE_OPEN_MULTIPLE)
                    }
                }
                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST)
                } catch (e: Exception) {
                    fileUploadCallback?.onReceiveValue(null)
                    fileUploadCallback = null
                    Toast.makeText(this@MainActivity, "לא ניתן לפתוח בוחר קבצים", Toast.LENGTH_SHORT).show()
                }
                return true
            }
        }

        // Download support (ZIP export)
        webView.setDownloadListener { url, _, contentDisposition, mimeType, _ ->
            try {
                val request = DownloadManager.Request(Uri.parse(url))
                val fileName = URLUtil.guessFileName(url, contentDisposition, mimeType)
                request.setTitle(fileName)
                request.setDescription("מוריד כרטיסי ברכה...")
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
                request.setMimeType(mimeType)

                val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
                dm.enqueue(request)
                Toast.makeText(this, "ההורדה החלה: $fileName", Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                Toast.makeText(this, "שגיאה בהורדה", Toast.LENGTH_SHORT).show()
            }
        }
    }

    /**
     * ‏החזרה מ-WhatsApp היא הסימן שהכרטיס טופל — נשלח או נזנח.
     * אין דרך אחרת לדעת זאת בלי שירות נגישות, ולכן התור בדף ממתין
     * בדיוק לרגע הזה כדי להתקדם לנמען הבא.
     *
     * ‏onResume רץ גם בפתיחה רגילה של האפליקציה; הדף מתעלם כשאין
     * תור פעיל.
     */
    override fun onResume() {
        super.onResume()
        binding.webView.evaluateJavascript(
            "window.onReturnedFromWhatsApp && window.onReturnedFromWhatsApp()", null
        )
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == FILE_CHOOSER_REQUEST) {
            // כמה קבצים מגיעים ב-clipData; ‏parseResult קורא רק קובץ אחד.
            val result = if (resultCode == Activity.RESULT_OK && data != null) {
                data.clipData?.let { c -> Array(c.itemCount) { c.getItemAt(it).uri } }
                    ?: WebChromeClient.FileChooserParams.parseResult(resultCode, data)
            } else null
            fileUploadCallback?.onReceiveValue(result)
            fileUploadCallback = null
        }
    }

    /**
     * ‏runOnUiThread הוא העיקר כאן: מתודות @JavascriptInterface רצות
     * על thread רקע פרטי של ה-WebView, ו-requestPermissions שנקרא
     * משם אינו פותח את הדיאלוג — הלחיצה פשוט לא עושה דבר.
     */
    fun requestContactsPermission() {
        runOnUiThread {
            ActivityCompat.requestPermissions(
                this,
                arrayOf(Manifest.permission.READ_CONTACTS),
                CONTACTS_PERMISSION_REQUEST
            )
        }
    }

    /** מסך ההגדרות של האפליקציה — היעד היחיד אחרי סירוב לצמיתות. */
    fun openAppSettings() {
        runOnUiThread {
            startActivity(
                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    .setData(Uri.fromParts("package", packageName, null))
            )
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)

        // דיאלוג ההרשאות אינו חוסם, ולכן דף הווב אינו יכול לחכות לתשובה
        // בקריאה שפתחה אותו. התשובה מוזרקת אליו כאן.
        if (requestCode == CONTACTS_PERMISSION_REQUEST) {
            val granted = grantResults.isNotEmpty() &&
                grantResults[0] == PackageManager.PERMISSION_GRANTED

            // סירוב עם "אל תשאל שוב" מחזיר DENIED מיד ובלי דיאלוג.
            // בלי להבחין בו, כל לחיצה נוספת נראית כאילו כלום לא קורה;
            // הדף צריך לדעת להפנות להגדרות במקום לבקש שוב.
            val permanentlyDenied = !granted && !ActivityCompat
                .shouldShowRequestPermissionRationale(this, Manifest.permission.READ_CONTACTS)

            binding.webView.evaluateJavascript(
                "window.onContactsPermissionResult && " +
                    "window.onContactsPermissionResult($granted, $permanentlyDenied)",
                null
            )
        }

        // הרשאת ההתראות אינה נדרשת לשליחה עצמה — בלעדיה רק לא תוצג
        // התקדמות ב-notification, ולכן אין מה לדווח לדף.
    }
}
