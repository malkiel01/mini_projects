package com.mbeplus.guardedbrowser

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity

/**
 * כניסת הורה מתוך מכשיר הילד — אפליקציית ההורה עצמה, בתוך האפליקציה.
 *
 * אותו פאנל ולא ממשק שני: ההורה נכנס בחשבון הכלים הדיגיטליים שלו, כמו בכל מקום (parent/ מאחורי שער
 * הפלטפורמה). ה-WebView הזה לא עובר במנוע האכיפה, ולכן הוא סגור לנתיב parent/ ולדף הכניסה של הפלטפורמה
 * בלבד — כל ניווט אחר נחסם. ביציאה העוגיות של ההורה נמחקות: המכשיר נשאר של הילד.
 */
class ParentActivity : AppCompatActivity() {

    private lateinit var web: WebView
    private val home by lazy { Api.root() + "parent/" }
    /** שורש האתר (מעל guarded-browser/) — שם דף הכניסה של הפלטפורמה, ועוגיית הכניסה שלה. */
    private val site by lazy { Api.root().trimEnd('/').substringBeforeLast('/') + "/" }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        title = "כניסת הורה"
        web = WebView(this)
        setContentView(web)
        web.settings.javaScriptEnabled = true
        web.settings.domStorageEnabled = true
        web.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(v: WebView, req: WebResourceRequest): Boolean {
                val url = req.url.toString()
                // הכניסה לפלטפורמה: דף הבית של האתר (עם ?next= או #invite=) ו-platform/ — ולא שום כלי אחר.
                val ok = url.startsWith(home) || url.startsWith(site + "platform/") ||
                    url == site || url.startsWith("$site?") || url.startsWith("$site#")
                if (!ok) Toast.makeText(this@ParentActivity, "כאן אפשר רק לנהל — לא לגלוש", Toast.LENGTH_SHORT).show()
                return !ok
            }
        }
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() { if (web.canGoBack()) web.goBack() else finish() }
        })
        web.loadUrl(home)
    }

    override fun onDestroy() {
        // היציאה מהפאנל היא יציאה מהחשבון: הילד לא יחזור לכאן מחובר.
        try {
            val path = java.net.URI(home).path
            CookieManager.getInstance().setCookie(home, "mg_parent=; Max-Age=0; Path=$path")
            // וגם הכניסה לפלטפורמה — אחרת הילד יוכל לפתוח את "כניסת הורה" ולהיכנס בלי סיסמה.
            CookieManager.getInstance().setCookie(site, "mp_session=; Max-Age=0; Path=" + java.net.URI(site).path)
            CookieManager.getInstance().flush()
        } catch (e: Exception) { /* אין מה לעשות */ }
        web.destroy()
        super.onDestroy()
    }
}
