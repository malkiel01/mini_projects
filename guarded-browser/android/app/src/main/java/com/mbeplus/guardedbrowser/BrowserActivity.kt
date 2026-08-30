package com.mbeplus.guardedbrowser

import android.annotation.SuppressLint
import android.app.AlertDialog
import android.app.PictureInPictureParams
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.util.Rational
import android.webkit.JavascriptInterface
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
    private var policy: Policy = Policy()
    private var ruleSet: RuleSet = RuleSet()

    private var usedSec = 0          // נוצל היום, לפי השרת
    private var sessionSec = 0       // משך הישיבה הנוכחית
    private var closing = false
    private var lastAllowed = ""      // הכתובת המותרת האחרונה, לחזרה אליה
    private var background = false    // ממשיכים לרוץ מחוץ לאפליקציה
    private var onScreen = false      // האם יש מסך להציג עליו דיאלוג
    @Volatile private var mediaPlaying = false
    private var pipRequested = false          // ביקשנו PiP; המצב עוד לא התעדכן
    private var customView: View? = null      // הווידאו במסך מלא
    private var customCallback: WebChromeClient.CustomViewCallback? = null

    /**
     * הדף מדווח מתי מתנגן משהו.
     *
     * הגרסה הקודמת שאלה את הדף ברגע היציאה, דרך evaluateJavascript
     * — קריאה אסינכרונית. עד שהתשובה חזרה, אנדרואיד כבר העביר את
     * המסך למצב מושהה, ו-enterPictureInPictureMode נדחה שם תמיד.
     * לכן המצב נשמר מראש, וההחלטה ביציאה היא מיידית.
     *
     * הממשק חושף בוליאני יחיד. דף עוין יכול לשקר עליו, ואז המשתמש
     * יקבל חלון צף על דף בלי וידאו — מטרד, לא פרצה.
     */
    inner class MediaBridge {
        @JavascriptInterface
        fun setPlaying(playing: Boolean) { mediaPlaying = playing }
    }

    /*
     * סגירת ההתראה חייבת לעצור גם את הצפייה.
     *
     * שירות שנעצר בלי לעצור את מה שהחזיק היה משאיר קול מתנגן בלי
     * שום דרך לעצור אותו — האפליקציה כבר לא על המסך.
     */
    private val stopFromNotification = object : BroadcastReceiver() {
        override fun onReceive(c: Context?, i: Intent?) {
            background = false
            b.web.evaluateJavascript(
                "(function(){try{document.querySelectorAll('video,audio')" +
                ".forEach(function(m){m.pause();});}catch(e){}})()", null)
            finish()
        }
    }

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
                // יציאה ממסך מלא קודמת לניווט אחורה: אחרת "אחורה"
                // מחליף דף בזמן שהמשתמש רק רצה לצאת מהווידאו.
                if (customView != null) {
                    b.web.evaluateJavascript(
                        "(function(){try{(document.exitFullscreen||" +
                        "document.webkitExitFullscreen||function(){}).call(document);}catch(e){}})()",
                        null)
                    return
                }
                if (b.web.canGoBack()) b.web.goBack() else finish()
            }
        })
        b.close.setOnClickListener { finish() }
        b.reload.setOnClickListener { b.web.reload() }
        b.pip.setOnClickListener { onPipButton() }
        b.pip.visibility = View.VISIBLE

        /*
         * הרשאת התראות נדרשת מאנדרואיד 13 ומעלה.
         *
         * בלעדיה שירות החזית עדיין רץ, אבל ההתראה אינה מוצגת —
         * כלומר האפליקציה ממשיכה לרוץ בלי שהמשתמש יודע, וזה בדיוק
         * מה שלא רוצים. נשאלת רק כשההרשאה בפועל ניתנה.
         */
        if ((policy.allowPip || policy.allowBackground) &&
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS)
                != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 11)
        }

        val filter = IntentFilter(GuardService.ACTION_STOP)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(stopFromNotification, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            @Suppress("UnspecifiedRegisterReceiverFlag")
            registerReceiver(stopFromNotification, filter)
        }
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

        b.web.addJavascriptInterface(MediaBridge(), "GBMedia")

        b.web.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, p: Int) {
                b.progress.progress = p
                b.progress.visibility = if (p in 1..99) View.VISIBLE else View.GONE
            }

            /*
             * ‏WebView אינו מציג וידאו במסך מלא בעצמו — הוא מוסר את
             * התצוגה לאפליקציה. בלי המימוש הזה כפתור המסך המלא של
             * יוטיוב אינו עושה דבר, ובחלון צף מוצג כל הדף מוקטן
             * במקום הווידאו בלבד.
             */
            override fun onShowCustomView(view: View, cb: CustomViewCallback) {
                if (customView != null) { cb.onCustomViewHidden(); return }
                customView = view
                customCallback = cb
                b.fullscreen.addView(view)
                b.fullscreen.visibility = View.VISIBLE
                b.web.visibility = View.GONE
                b.bar.visibility = View.GONE
            }

            override fun onHideCustomView() {
                b.fullscreen.removeAllViews()
                b.fullscreen.visibility = View.GONE
                b.web.visibility = View.VISIBLE
                if (!isInPictureInPictureMode) b.bar.visibility = View.VISIBLE
                customView = null
                customCallback?.onCustomViewHidden()
                customCallback = null
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
                // דף חדש — עדיין לא מתנגן בו דבר. בלי האיפוס, יציאה
                // מדף טקסט הייתה פותחת חלון צף על סמך הדף הקודם.
                mediaPlaying = false
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
                hideAds()
                watchMedia()
            }

            override fun onPageFinished(view: WebView, url: String) {
                b.address.text = url
                enforceCurrent(url)
                styleRestrictedYouTube(url)
                hideAds()
                watchMedia()
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
        // ‏true = האפליקציה התירה. השרת משווה, וחוסר הסכמה הוא ההתרעה.
        Api.check(store.token, url, true, clientAllowed = true) { r ->
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
        if (url == lastAllowed) { hideCover(); return }

        /*
         * הכיסוי עולה לפני ההכרעה, לא אחריה.
         *
         * ההכרעה על סרטון לא מוכר דורשת פנייה לשרת, ובזמן הזה יוטיוב
         * כבר מציג את הסרטון ומשמיע אותו. חסימה שמגיעה חצי שנייה
         * אחר כך היא חסימה שהמשתמש כבר ראה מה היה מאחוריה.
         */
        showCover()

        val v = PolicyEngine.evaluate(policy, ruleSet, url, true, usedSec, sessionSec)
        when {
            v.allow -> { lastAllowed = url; hideCover(); verifyWithServer(url) }
            v.needsServer -> Api.check(store.token, url, true) { r ->
                val j = r.json
                when {
                    j == null -> revert("אין חיבור לשרת, ולא ניתן לוודא שהכתובת מותרת")
                    j.optBoolean("allowed") -> { lastAllowed = url; hideCover() }
                    else -> revert(j.optString("reason", "הכתובת אינה מותרת"))
                }
            }
            else -> revert(v.reason)
        }
    }

    /**
     * מכסה את המסך ומשתיק כל ניגון.
     *
     * ‏pause על תגיות ה-video ולא onPause של ה-WebView: השני עוצר גם
     * את הטעינה של הדף שאליו נחזור, והמשתמש היה נתקע על כיסוי לבן.
     */
    private fun showCover() {
        b.web.evaluateJavascript(
            "(function(){try{document.querySelectorAll('video,audio')" +
            ".forEach(function(m){m.pause();});}catch(e){}})()", null)

        if (b.cover.visibility != View.VISIBLE) {
            b.coverText.text = "בודק…"
            b.cover.visibility = View.VISIBLE
        }

        /*
         * רשת ביטחון: כיסוי אטום שנתקע הוא דפדפן בלתי שמיש.
         *
         * אם התשובה לא הגיעה בזמן סביר — רשת שנפלה, שרת שלא ענה —
         * המסך נפתח עם הודעה, במקום להשאיר את המשתמש מול ריבוע ריק
         * בלי שום דרך להבין מה קרה.
         */
        ticker.removeCallbacks(coverTimeout)
        ticker.postDelayed(coverTimeout, 8000)
    }

    private val coverTimeout = Runnable {
        if (b.cover.visibility == View.VISIBLE && !closing) {
            b.coverText.text = "הבדיקה לא הסתיימה. חוזר אחורה."
            revert("לא הצלחנו לוודא שהכתובת מותרת")
        }
    }

    private fun hideCover() {
        ticker.removeCallbacks(coverTimeout)
        b.cover.visibility = View.GONE
    }

    /** מחזיר לכתובת המותרת האחרונה, ומסביר למה. */
    private fun revert(reason: String) {
        if (closing) return
        ticker.removeCallbacks(coverTimeout)
        b.web.stopLoading()
        toast(reason.ifEmpty { "הכתובת אינה מותרת בחשבון שלך" })

        val back = lastAllowed
        if (back.isEmpty()) { refuse(reason); return }
        // הכיסוי נשאר עד שהדף המותר נטען, אחרת נראה שוב מה שנחסם.
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
     * מאזין לאירועי ניגון ומדווח לאפליקציה.
     *
     * ‏MutationObserver כי יוטיוב מחליף את תגית הווידאו תוך כדי,
     * ומאזין שנרשם פעם אחת מפסיק לדעת מה קורה.
     */
    private fun watchMedia() {
        /*
         * מותקן תמיד, גם כשההרשאה כבויה.
         *
         * בגרסה הקודמת הוא רץ רק כשחלון צף אושר — ולכן מסך האבחון
         * הראה "לא מזוהה סרטון מתנגן" בכל פעם שההרשאה הייתה כבויה,
         * כלומר הציג תוצאה של הבעיה כאילו הייתה בעיה נוספת. אבחון
         * שסותר את עצמו גרוע מאין אבחון.
         */
        b.web.evaluateJavascript(
            "(function(){try{" +
            "function report(){var v=document.querySelector('video,audio');" +
            "GBMedia.setPlaying(!!(v&&!v.paused&&!v.ended&&v.readyState>2));}" +
            "function hook(){document.querySelectorAll('video,audio').forEach(function(m){" +
            "if(m.__gbHooked)return;m.__gbHooked=1;" +
            "['play','pause','ended','playing','emptied'].forEach(function(e){" +
            "m.addEventListener(e,report);});});report();}" +
            "hook();" +
            "if(!window.__gbMediaObs){window.__gbMediaObs=new MutationObserver(hook);" +
            "window.__gbMediaObs.observe(document.documentElement,{childList:true,subtree:true});}" +
            "if(!window.__gbMediaT)window.__gbMediaT=setInterval(report,1000);" +
            "}catch(e){}})()", null)
    }

    /**
     * הסתרת שטחי פרסום, ודילוג על פרסומות יוטיוב.
     *
     * חסימת הרשת מונעת את הטעינה, אבל המסגרת הריקה נשארת ומשאירה
     * חור בדף — ההסתרה היא השלמה, לא תחליף. ופרסומת ביוטיוב מוגשת
     * מאותו מקור כמו הסרטון, ולכן אי אפשר לחסום אותה ברשת בלי
     * לחסום את הסרטון: היא מדולגת.
     */
    private fun hideAds() {
        val cosmetic = "cosmetic" in policy.adBlock && ruleSet.adCss.isNotEmpty()
        val ytAds = "youtube" in policy.adBlock
        if (!cosmetic && !ytAds) return

        val css = if (cosmetic) ruleSet.adCss.replace("'", "") else ""
        val js = "(function(){try{" +
            (if (cosmetic)
                "var css='" + css + "{display:none!important}';" +
                "function put(){var s=document.getElementById('gb-ads');" +
                "if(!s){s=document.createElement('style');s.id='gb-ads';" +
                "(document.head||document.documentElement).appendChild(s);}" +
                "if(s.textContent!==css)s.textContent=css;}put();"
             else "function put(){}") +
            (if (ytAds)
                // דילוג: לחיצה על כפתור הדילוג, והרצה מהירה של פרסומת
                // שאין לה כפתור כזה.
                "function skip(){try{" +
                "var b=document.querySelector('.ytp-ad-skip-button,.ytp-skip-ad-button," +
                ".ytp-ad-skip-button-modern');if(b)b.click();" +
                "if(document.querySelector('.ad-showing,.ytp-ad-player-overlay')){" +
                "var v=document.querySelector('video');" +
                "if(v&&v.duration){v.currentTime=v.duration;v.muted=true;}}" +
                "}catch(e){}}skip();" +
                "if(!window.__gbAdT)window.__gbAdT=setInterval(function(){put();skip();},700);"
             else
                "if(!window.__gbAdT)window.__gbAdT=setInterval(put,1500);") +
            "}catch(e){}})()"
        b.web.evaluateJavascript(js, null)
    }

    /**
     * שואל את השרת על כתובת שהמכשיר אינו יכול להכריע עליה, ורק אז
     * טוען. זה הקישור בין האכיפה המקומית לבין הידע שיושב בשרת.
     */
    private fun askServerThenLoad(url: String) {
        showCover()
        Api.check(store.token, url, true) { r ->
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
        background = false
        GuardService.stop(this)
        hideCover()
        b.web.stopLoading()
        b.web.loadUrl("about:blank")

        /*
         * דיאלוג עובד רק כשיש מסך.
         *
         * חסימה ברקע — מכסה שנגמרה, השעיה מהשרת — קורית כשהאפליקציה
         * אינה מוצגת, ודיאלוג שם קורס או נבלע. ההודעה עוברת אז
         * להתראה, שהיא המקום היחיד שבו המשתמש יראה אותה.
         */
        if (!onScreen) {
            GuardService.start(this, reason.ifEmpty { "הצפייה הופסקה" })
            ticker.postDelayed({ GuardService.stop(this); finish() }, 6000)
            return
        }

        AlertDialog.Builder(this)
            .setTitle("הגישה נחסמה")
            .setMessage(reason.ifEmpty { "הכתובת אינה מותרת בחשבון שלך" })
            .setCancelable(false)
            .setPositiveButton("סגירה") { _, _ -> finish() }
            .show()
    }

    private fun toast(s: String) =
        android.widget.Toast.makeText(this, s, android.widget.Toast.LENGTH_SHORT).show()

    /**
     * המשתמש יוצא מהאפליקציה — הרגע להיכנס לחלון צף.
     *
     * רק כשההרשאה ניתנה וכשבאמת מתנגן משהו: חלון צף על דף טקסט הוא
     * מטרד, לא תכונה.
     */
    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        if (closing || !policy.allowPip || !mediaPlaying) return
        // סינכרוני, בלי שום המתנה: זה הרגע האחרון שבו אנדרואיד מרשה.
        enterPip()
    }

    /** האם המערכת בכלל מרשה לאפליקציה הזו חלון צף. */
    private fun pipAllowed(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return false
        if (!packageManager.hasSystemFeature(
                android.content.pm.PackageManager.FEATURE_PICTURE_IN_PICTURE)) return false
        // ‏unsafeCheckOpNoThrow קיים רק מ-API 29; בגרסאות ישנות יותר
        // אין דרך לשאול, ולכן פשוט מנסים והמערכת מכריעה.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return true
        return try {
            val ops = getSystemService(android.app.AppOpsManager::class.java)
            ops.unsafeCheckOpNoThrow(android.app.AppOpsManager.OPSTR_PICTURE_IN_PICTURE,
                android.os.Process.myUid(), packageName) == android.app.AppOpsManager.MODE_ALLOWED
        } catch (e: Throwable) { true }
    }

    private fun enterPip(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return false

        /*
         * מבקשים מהדף להעביר את הווידאו למסך מלא לפני הכניסה.
         *
         * חלון צף מציג את מה שה-Activity מציגה. בלי זה הוא מקבל את
         * כל הדף — כותרות, המלצות ורקע שחור — במקום את הווידאו.
         */
        b.web.evaluateJavascript(
            "(function(){try{var v=document.querySelector('video');" +
            "if(v&&!document.fullscreenElement){" +
            "(v.requestFullscreen||v.webkitRequestFullscreen||function(){}).call(v);}" +
            "}catch(e){}})()", null)

        pipRequested = true
        return try {
            enterPictureInPictureMode(
                PictureInPictureParams.Builder().setAspectRatio(Rational(16, 9)).build())
        } catch (e: Exception) { pipRequested = false; false }
    }

    /**
     * כפתור מפורש לחלון צף.
     *
     * בלעדיו הדרך היחידה להיכנס היא לחיצה על "בית" — ואז כשזה לא
     * עובד אין שום דרך לדעת אם התכונה כבויה, אם המערכת חוסמת, או אם
     * פשוט לא זוהה ניגון. כפתור שאומר בדיוק מה חסר פותר את זה.
     */
    private fun onPipButton() {
        // אם הכול תקין פשוט נכנסים, ואין מה להסביר.
        if (policy.allowPip && pipAllowed() && mediaPlaying && enterPip()) return

        /*
         * וכשנכשל — אומרים בדיוק מה חסר.
         *
         * "לא עובד" הוא הדיווח הנפוץ ביותר וחסר התועלת ביותר: יש
         * כאן ארבעה תנאים, וכל אחד מהם דורש פעולה אחרת לגמרי. מסך
         * שמראה את כל הארבעה חוסך סבב שלם של ניחושים.
         */
        val sdk = Build.VERSION.SDK_INT
        val hasFeature = packageManager.hasSystemFeature(
            android.content.pm.PackageManager.FEATURE_PICTURE_IN_PICTURE)
        val systemOk = pipAllowed()

        val lines = listOf(
            pipRow("מאושר בחשבון שלך", policy.allowPip,
                   "המנהל מפעיל זאת באזור \u0022מכשיר והתנהגות\u0022"),
            pipRow("המכשיר תומך", hasFeature && sdk >= Build.VERSION_CODES.O,
                   "נדרש אנדרואיד 8 ומעלה. כאן: API " + sdk +
                   (if (hasFeature) "" else ", ואין תמיכה בחומרה")),
            pipRow("אנדרואיד מרשה לאפליקציה", systemOk,
                   "הגדרות ← אפליקציות ← גישה מיוחדת ← תמונה בתוך תמונה"),
            pipRow("מזוהה סרטון מתנגן", mediaPlaying,
                   "הפעילו סרטון, ובזמן שהוא רץ לחצו כאן שוב"),
        )

        val dialog = AlertDialog.Builder(this)
            .setTitle("חלון צף — מה חסר")
            .setMessage(lines.joinToString("\n\n"))
            .setPositiveButton("סגירה", null)

        if (policy.allowPip && !systemOk) {
            dialog.setNeutralButton("פתיחת ההגדרות") { _, _ -> openPipSettings() }
        }
        dialog.show()
    }

    private fun pipRow(label: String, ok: Boolean, hint: String): String =
        (if (ok) "\u2713 " else "\u2717 ") + label + (if (ok) "" else "\n     " + hint)

    private fun openPipSettings() {
        try {
            startActivity(Intent("android.settings.PICTURE_IN_PICTURE_SETTINGS")
                .setData(android.net.Uri.parse("package:$packageName")))
        } catch (e: Exception) {
            try {
                startActivity(Intent(
                    android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    android.net.Uri.parse("package:$packageName")))
            } catch (e2: Exception) { toast("לא נמצא מסך ההגדרות במכשיר הזה") }
        }
    }

    override fun onPictureInPictureModeChanged(inPip: Boolean, config: android.content.res.Configuration) {
        super.onPictureInPictureModeChanged(inPip, config)

        // בחלון צף אין מקום לשורת כתובת ולכפתורים; רק הווידאו.
        b.bar.visibility = if (inPip || customView != null) View.GONE else View.VISIBLE
        b.address.visibility = if (inPip) View.GONE else View.VISIBLE

        if (inPip) {
            // הכיסוי אטום; אילו נשאר, החלון הצף היה מציג אותו במקום הווידאו.
            hideCover()
            b.web.onResume()
        } else {
            pipRequested = false
            // חזרה מהחלון הצף — יוצאים גם ממסך מלא, אחרת נשארים
            // בווידאו בלי שום דרך לחזור לדפדפן.
            if (customView != null) b.web.evaluateJavascript(
                "(function(){try{(document.exitFullscreen||document.webkitExitFullscreen" +
                "||function(){}).call(document);}catch(e){}})()", null)
        }
    }

    override fun onResume() {
        super.onResume()
        onScreen = true
        if (!closing) ticker.postDelayed(beat, BEAT_SEC * 1000L)
        b.web.onResume()
        // חזרנו למסך — אין עוד סיבה להתראה.
        if (!background) GuardService.stop(this)
        refreshPolicy()
    }

    /**
     * מושך מדיניות עדכנית מהשרת.
     *
     * בלי זה, שינוי שהמנהל עשה בפאנל נכנס לתוקף רק אחרי חזרה למסך
     * הפתיחה — והמשתמש רואה "לא מאושר" על משהו שאושר לו לפני רגע.
     * שגיאת רשת אינה משנה דבר: ממשיכים עם מה שכבר יש.
     */
    private fun refreshPolicy() {
        Api.policy(store.token) { r ->
            val j = r.json ?: return@policy
            if (!j.optBoolean("ok")) return@policy

            store.savePolicy(j)
            policy = store.policy()
            ruleSet = store.ruleSet()
            b.pip.visibility = View.VISIBLE
        }
    }

    override fun onPause() {
        super.onPause()
        onScreen = false

        /*
         * ‏ברקע הזמן ממשיך להיספר, וזו החלטה ולא פרט טכני.
         *
         * מי שהתיר צפייה ברקע התיר צפייה — ומכסת זמן שנעצרת ברגע
         * שהמסך מתכבה היא מכסה שאפשר לעקוף בלחיצה אחת. הפעימה גם
         * ממשיכה לקבל השעיה מהשרת, ולכן האכיפה חיה גם כאן.
         */
        /*
         * ‏pipRequested ולא isInPictureInPictureMode בלבד.
         *
         * ‏onPause רץ *לפני* שהמצב מתעדכן ל-PiP, ולכן הבדיקה הישנה
         * החזירה false בדיוק ברגע המעבר — ה-WebView הושהה, והחלון
         * הצף נפתח על וידאו עצור ומסך שחור.
         */
        val keepGoing = policy.allowBackground || pipRequested || isInPictureInPictureMode

        if (keepGoing && !closing) {
            background = true
            GuardService.start(this, "הצפייה ממשיכה. מכסת הזמן נספרת.")
        } else {
            background = false
            ticker.removeCallbacks(beat)
            b.web.onPause()
            GuardService.stop(this)
        }
    }

    override fun onDestroy() {
        ticker.removeCallbacks(beat)
        ticker.removeCallbacks(coverTimeout)
        background = false
        GuardService.stop(this)
        try { unregisterReceiver(stopFromNotification) } catch (e: Exception) { }
        if (!policy.keepHistory) {
            b.web.clearHistory()
            b.web.clearCache(true)
            CookieManager.getInstance().removeAllCookies(null)
        }
        b.web.destroy()
        super.onDestroy()
    }
}
