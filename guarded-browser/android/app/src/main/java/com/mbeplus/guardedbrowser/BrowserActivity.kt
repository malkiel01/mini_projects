package com.mbeplus.guardedbrowser

import android.annotation.SuppressLint
import android.app.AlertDialog
import android.graphics.Bitmap
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import android.view.WindowManager
import android.webkit.*
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.guardedbrowser.databinding.ActivityBrowserBinding
import java.io.ByteArrayInputStream

/**
 * הדפדפן.
 *
 * ‏WebView נייטיב טוען את הדף כמסמך עליון, ולכן X-Frame-Options
 * ו-frame-ancestors אינם חלים עליו כלל — הם חוסמים הטמעה, ואין כאן
 * הטמעה. זו הסיבה שאתר שסירב להיפתח בתוך iframe נפתח כאן.
 *
 * שתי נקודות אכיפה, ולא אחת:
 *   • shouldOverrideUrlLoading — ניווט. מה שהמשתמש הולך אליו.
 *   • shouldInterceptRequest   — כל משאב. תמונה, סקריפט, נגן.
 * בלי השנייה, דף מותר יכול להיות מעטפת שטוענת בתוכה תוכן אסור.
 */
class BrowserActivity : AppCompatActivity() {

    private lateinit var b: ActivityBrowserBinding
    private lateinit var store: Store
    private lateinit var policy: Policy
    private var ruleSet: RuleSet = RuleSet()

    private var usedSec = 0          // נוצל היום, לפי השרת
    private var sessionSec = 0       // משך הישיבה הנוכחית
    private var closing = false
    private var lastAllowed = ""      // הכתובת המותרת האחרונה, לחזרה אליה

    private val ticker = Handler(Looper.getMainLooper())
    private val beat = object : Runnable {
        override fun run() {
            sessionSec += BEAT_SEC
            sendBeat()
            ticker.postDelayed(this, BEAT_SEC * 1000L)
        }
    }

    companion object { private const val BEAT_SEC = 30 }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)
        policy = store.policy()
        ruleSet = store.ruleSet()

        // חסימת צילום מסך חייבת לקרות לפני setContentView כדי לחול
        // גם על התצוגה המקדימה במחליף האפליקציות.
        if (policy.blockScreenshots) {
            window.setFlags(WindowManager.LayoutParams.FLAG_SECURE,
                            WindowManager.LayoutParams.FLAG_SECURE)
        }

        b = ActivityBrowserBinding.inflate(layoutInflater)
        setContentView(b.root)

        val start = intent.getStringExtra("url") ?: ""
        setupWebView()

        // ההכרעה הראשונה לפני שנטען משהו: גם הכניסה עצמה נבדקת.
        val v = PolicyEngine.evaluate(policy, ruleSet, start, true, usedSec, sessionSec)
        if (!v.allow && !v.needsServer) { refuse(v.reason.ifEmpty { "הכתובת אינה מותרת" }); return }

        val first = PolicyEngine.normalize(start)?.let { normalizedToUrl(start) } ?: start
        lastAllowed = first
        b.web.loadUrl(first)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (b.web.canGoBack()) b.web.goBack() else finish()
            }
        })
        b.close.setOnClickListener { finish() }
        b.reload.setOnClickListener { b.web.reload() }
    }

    /** משלים סכימה כשהמשתמש הקליד "example.com" בלי אחת. */
    private fun normalizedToUrl(raw: String): String =
        if (Regex("^[a-zA-Z][a-zA-Z0-9+.\\-]*:").containsMatchIn(raw.trim())) raw.trim()
        else "https://" + raw.trim()

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() = with(b.web.settings) {
        javaScriptEnabled = true
        domStorageEnabled = true
        mediaPlaybackRequiresUserGesture = false
        useWideViewPort = true
        loadWithOverviewMode = true
        builtInZoomControls = true
        displayZoomControls = false
        // ‏UA של דפדפן אמיתי: אתרים מאחורי שירותי הגנה מגישים מסך
        // אתגר לכל מי שאינו נראה כדפדפן.
        userAgentString = userAgentString.replace("; wv", "")

        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(b.web, true)

        b.web.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, p: Int) {
                b.progress.progress = p
                b.progress.visibility = if (p in 1..99) View.VISIBLE else View.GONE
            }
        }

        b.web.webViewClient = object : WebViewClient() {

            /** ניווט — מה שהמשתמש הולך אליו. */
            override fun shouldOverrideUrlLoading(
                view: WebView, request: WebResourceRequest,
            ): Boolean {
                val url = request.url.toString()
                val v = PolicyEngine.evaluate(policy, ruleSet, url, true, usedSec, sessionSec)

                if (v.allow) {
                    verifyWithServer(url)
                    return false
                }
                /*
                 * ‏needsServer אינו סירוב: זו שאלה שהתשובה לה אינה
                 * במכשיר (לאיזה ערוץ שייך הסרטון). נבלע כאן, נשאל
                 * את השרת, ואם הוא מתיר — נטען.
                 */
                if (v.needsServer) {
                    askServerThenLoad(url)
                    return true
                }
                refuse(v.reason)
                return true      // הניווט נבלע; הדף הנוכחי נשאר
            }

            /**
             * כל משאב. חוסם בתשובה ריקה ולא ב-null — null פירושו
             * "טען כרגיל", כלומר בדיוק ההפך ממה שהתכוונו.
             */
            override fun shouldInterceptRequest(
                view: WebView, request: WebResourceRequest,
            ): WebResourceResponse? {
                val url = request.url.toString()
                if (!url.startsWith("http")) return null

                val v = PolicyEngine.evaluate(
                    policy, ruleSet, url, request.isForMainFrame, usedSec, sessionSec)

                // משאב שדורש הכרעת שרת אינו נחסם: חסימה שקטה של
                // משאב היא מסך שבור בלי הסבר. הניווט כבר נבדק.
                return if (v.allow || v.needsServer) null
                else WebResourceResponse("text/plain", "utf-8",
                                         ByteArrayInputStream(ByteArray(0)))
            }

            override fun onPageStarted(view: WebView?, url: String?, icon: Bitmap?) {
                b.address.text = url ?: ""
            }

            /*
             * ‏אתרי עמוד-יחיד — יוטיוב בראשם — מחליפים דף בלי לנווט.
             *
             * לחיצה על סרטון בתוך יוטיוב אינה קוראת ל-
             * shouldOverrideUrlLoading בכלל: הדף מריץ history.pushState
             * ומחליף את התוכן בעצמו. אכיפה שיושבת רק על ניווט פשוט לא
             * רצה שם, והמשתמש חיפש והגיע לכל סרטון שרצה.
             *
             * ‏doUpdateVisitedHistory נקרא גם על pushState, ולכן הוא
             * נקודת האכיפה האמיתית באתרים כאלה.
             */
            override fun doUpdateVisitedHistory(view: WebView, url: String, isReload: Boolean) {
                b.address.text = url
                enforceCurrent(url)
            }

            override fun onPageFinished(view: WebView, url: String) {
                b.address.text = url
                enforceCurrent(url)
                styleRestrictedYouTube(url)
            }
        }

        // הורדות לפי ההרשאה. בלי היתר — הודעה, ולא כישלון שקט.
        b.web.setDownloadListener { url, _, _, _, _ ->
            if (policy.allowDownloads) {
                startActivity(android.content.Intent(
                    android.content.Intent.ACTION_VIEW, android.net.Uri.parse(url)))
            } else {
                toast("הורדת קבצים אינה מותרת בחשבון שלך")
            }
        }

        if (!policy.keepHistory) {
            CookieManager.getInstance().removeAllCookies(null)
            WebStorage.getInstance().deleteAllData()
        }
    }

    /**
     * אימות מול השרת אחרי שהאכיפה המקומית התירה.
     *
     * מאשר את ההכרעה בדיעבד ומזין את היומן. אם השרת חולק — הדף נסגר.
     * זה מה שהופך את האכיפה המקומית לנוחות ולא למקור הסמכות.
     */
    private fun verifyWithServer(url: String) {
        Api.check(store.token, url, true) { r ->
            if (r.json?.optString("code") == "unauthorized") { refuse("נדרשת כניסה מחדש"); return@check }
            // שגיאת רשת אינה סירוב: r.json ריק פירושו שלא הצלחנו לשאול.
            if (r.json != null && !r.json.optBoolean("allowed", true)) {
                refuse(r.json.optString("reason", "הכתובת אינה מותרת"))
            }
        }
    }

    /** פעימה: צוברת זמן, ומביאה את המצב. זה גם ערוץ הניתוק מרחוק. */
    private fun sendBeat() {
        Api.heartbeat(store.token, BEAT_SEC, sessionSec) { r ->
            val j = r.json ?: return@heartbeat        // אין רשת — ממשיכים
            if (j.optString("code") == "unauthorized") { refuse("נדרשת כניסה מחדש"); return@heartbeat }

            usedSec = j.optInt("used_today_sec", usedSec)
            if (!j.optBoolean("allowed", true)) {
                refuse(j.optString("reason", "הגישה הופסקה"))
            }
        }
    }

    /**
     * אכיפה על הכתובת שמוצגת עכשיו, יהיה אשר יהיה מה שהביא אותה.
     *
     * ‏חזרה לכתובת המותרת האחרונה ולא סגירת הדפדפן: המשתמש לא עשה
     * דבר אסור — הוא לחץ על מה שיוטיוב הציע לו. לסגור עליו את הכול
     * בגלל זה זו ענישה על ממשק של מישהו אחר.
     */
    private fun enforceCurrent(url: String) {
        if (closing || url.isEmpty() || url == "about:blank") return
        if (url == lastAllowed) return

        val v = PolicyEngine.evaluate(policy, ruleSet, url, true, usedSec, sessionSec)
        when {
            v.allow -> { lastAllowed = url; verifyWithServer(url) }
            v.needsServer -> Api.check(store.token, url, true) { r ->
                val j = r.json
                when {
                    j == null -> revert("אין חיבור לשרת, ולא ניתן לוודא שהכתובת מותרת")
                    j.optBoolean("allowed") -> lastAllowed = url
                    else -> revert(j.optString("reason", "הכתובת אינה מותרת"))
                }
            }
            else -> revert(v.reason)
        }
    }

    /** מחזיר לכתובת המותרת האחרונה, ומסביר למה. */
    private fun revert(reason: String) {
        if (closing) return
        b.web.stopLoading()
        toast(reason.ifEmpty { "הכתובת אינה מותרת בחשבון שלך" })

        val back = lastAllowed
        if (back.isEmpty()) { refuse(reason); return }
        b.web.post { b.web.loadUrl(back) }
    }

    /**
     * במצב יוטיוב מוגבל, מסתיר את מה שמזמין לצאת מהערוץ — חיפוש,
     * תפריט צדדי, והמלצות בסוף סרטון.
     *
     * זה שכבת נוחות בלבד: האכיפה היא enforceCurrent, והסתרה בלבד
     * הייתה עקיפה של שורת כתובת אחת. אבל ממשק שמציע כל הזמן מה
     * שייחסם הוא ממשק מתסכל.
     */
    private fun styleRestrictedYouTube(url: String) {
        val yt = ruleSet.platforms[PolicyEngine.PLATFORM_YOUTUBE] ?: return
        if (yt.mode != "restricted") return
        if (PolicyEngine.platformOf(PolicyEngine.normalize(url)?.host ?: "") !=
            PolicyEngine.PLATFORM_YOUTUBE) return

        val hide = buildString {
            if (!yt.allowSearch) {
                append("ytd-searchbox,#search,#search-form,#center.ytd-masthead,")
                append("ytd-search-header-renderer,")
            }
            append("#guide,#guide-button,ytd-mini-guide-renderer,tp-yt-app-drawer,")
            append("#related,ytd-compact-video-renderer,ytd-watch-next-secondary-results-renderer,")
            append(".ytp-endscreen-content,.ytp-ce-element,.ytp-pause-overlay,")
            append("ytd-reel-shelf-renderer,ytd-rich-shelf-renderer")
        }
        /*
         * ‏MutationObserver ולא הזרקה חד-פעמית: יוטיוב מרנדר את הדף
         * אחרי onPageFinished ומחליף חלקים ממנו תוך כדי, ולכן סגנון
         * שהוזרק פעם אחת נעלם. הצופה מחזיר אותו בכל שינוי.
         */
        val js = "(function(){try{" +
            "var css='" + hide + "{display:none!important}';" +
            "function put(){var s=document.getElementById('gb-style');" +
            "if(!s){s=document.createElement('style');s.id='gb-style';" +
            "(document.head||document.documentElement).appendChild(s);}" +
            "if(s.textContent!==css)s.textContent=css;}" +
            "put();" +
            "if(!window.__gbObs){window.__gbObs=new MutationObserver(put);" +
            "window.__gbObs.observe(document.documentElement," +
            "{childList:true,subtree:true});}" +
            "}catch(e){}})()"
        b.web.evaluateJavascript(js, null)
    }

    /**
     * שואל את השרת על כתובת שהמכשיר אינו יכול להכריע עליה, ורק אז
     * טוען. זה הקישור בין האכיפה המקומית לבין הידע שיושב בשרת.
     */
    private fun askServerThenLoad(url: String) {
        b.progress.visibility = View.VISIBLE
        Api.check(store.token, url, true) { r ->
            b.progress.visibility = View.GONE
            val j = r.json
            when {
                j == null -> refuse("אין חיבור לשרת, ולא ניתן לוודא שהכתובת מותרת")
                j.optBoolean("allowed") -> { lastAllowed = url; b.web.loadUrl(url) }
                else -> refuse(j.optString("reason", "הכתובת אינה מותרת"))
            }
        }
    }

    /** מסך סירוב אחד לכל הסיבות. סוגר את הדפדפן — לא רק את הדף. */
    private fun refuse(reason: String) {
        if (closing) return
        closing = true

        ticker.removeCallbacks(beat)
        b.web.stopLoading()
        b.web.loadUrl("about:blank")

        AlertDialog.Builder(this)
            .setTitle("הגישה נחסמה")
            .setMessage(reason.ifEmpty { "הכתובת אינה מותרת בחשבון שלך" })
            .setCancelable(false)
            .setPositiveButton("סגירה") { _, _ -> finish() }
            .show()
    }

    private fun toast(s: String) =
        android.widget.Toast.makeText(this, s, android.widget.Toast.LENGTH_SHORT).show()

    override fun onResume() {
        super.onResume()
        if (!closing) ticker.postDelayed(beat, BEAT_SEC * 1000L)
        b.web.onResume()
    }

    override fun onPause() {
        super.onPause()
        // הזמן נספר רק כשהמסך באמת פתוח: אפליקציה ברקע אינה צפייה.
        ticker.removeCallbacks(beat)
        b.web.onPause()
    }

    override fun onDestroy() {
        ticker.removeCallbacks(beat)
        if (!policy.keepHistory) {
            b.web.clearHistory()
            b.web.clearCache(true)
            CookieManager.getInstance().removeAllCookies(null)
        }
        b.web.destroy()
        super.onDestroy()
    }
}
