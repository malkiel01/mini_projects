package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.GridLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.mbeplus.guardedbrowser.databinding.ActivityHomeBinding
import org.json.JSONObject

/**
 * מסך הבית של הילד (SPEC.md, סעיף 9): שורת חיפוש, מועדפים, וכל מה שמותר — כאריחים.
 *
 * המסך הזה הוא גם נקודת האכיפה הראשונה — הוא מרענן מדיניות בכל כניסה,
 * ומשתמש שהושעה או שמכסתו נגמרה אינו מגיע לדפדפן בכלל.
 */
class HomeActivity : AppCompatActivity() {

    private lateinit var b: ActivityHomeBinding
    private lateinit var store: Store
    private var state: JSONObject? = null
    private var allowed = true
    private var query = ""

    /** סריקת קוד מההורה — הגדרות בלי אינטרנט (OfflinePolicy). אחרי הסריקה Enforcer מחזיר את חסימת המצלמה. */
    private val offlineScan = registerForActivityResult(com.journeyapps.barcodescanner.ScanContract()) { r ->
        val text = r?.contents
        val (ok, msg) = when {
            text == null -> false to ""
            !OfflinePolicy.isCode(text) -> false to "זה לא קוד הגדרות. פותחים באפליקציית ההורה: הגדרות המכשיר ← \"📷 העברה בקוד\"."
            else -> OfflinePolicy.apply(this, text)
        }
        if (!ok && !store.released) Enforcer.apply(this, store)   // ביטול או קוד שנדחה — המצלמה חוזרת לחסימה
        if (msg.isNotEmpty()) Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
        if (ok) refresh()
    }

    private fun scanParentCode() {
        Enforcer.allowCamera(this)
        offlineScan.launch(com.journeyapps.barcodescanner.ScanOptions()
            .setDesiredBarcodeFormats(com.journeyapps.barcodescanner.ScanOptions.QR_CODE)
            .setPrompt("כוונו את המצלמה לקוד שבאפליקציית ההורה")
            .setBeepEnabled(false)
            .setOrientationLocked(false))
    }

    /** בקשה שנשלחה מהדפדפן ונענתה כשהילד כבר במסך הבית (שלב 6). */
    private val watcher by lazy {
        RequestWatcher(store) { url, ok ->
            val host = AccessRequest.hostOf(url)
            if (ok) {
                Toast.makeText(this, "✅ ההורה אישר את $host", Toast.LENGTH_LONG).show()
                refresh(); open(url)
            } else {
                MaterialAlertDialogBuilder(this).setTitle("ההורה לא אישר הפעם")
                    .setMessage("הבקשה ל-$host נדחתה.").setPositiveButton("הבנתי", null).show()
            }
        }
    }

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)
        if (!store.isLoggedIn) { unpair(); return }

        b = ActivityHomeBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.settings.setOnClickListener { showSettings() }
        b.sync.setOnClickListener { syncNow() }
        b.search.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun onTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun afterTextChanged(s: Editable?) {
                query = s?.toString()?.trim() ?: ""
                if (query.isEmpty()) showResults(null)
                draw()
            }
        })
        b.search.setOnEditorActionListener { _, id, _ ->
            if (id == EditorInfo.IME_ACTION_SEARCH || id == EditorInfo.IME_ACTION_GO) { submitSearch(); true } else false
        }
    }

    override fun onResume() {
        super.onResume()
        // שער ההקמה: בלי ההרשאות החיוניות לא נכנסים למגדלור.
        store.perms = Setup.flags(this)
        if (!Setup.ready(this)) { startActivity(Intent(this, SetupActivity::class.java)); return }
        // עדכון חובה — עד ההתקנה אין כניסה (Updater).
        if (Updater.required(this)) { Updater.lock(this); return }
        refresh()
        if (store.pendingRequests().isNotEmpty()) watcher.start()
    }

    override fun onPause() {
        super.onPause()
        if (::store.isInitialized) watcher.stop()
    }

    /**
     * רענון מדיניות מהשרת.
     *
     * כישלון רשת אינו נועל את המשתמש: הוא ממשיך עם המדיניות השמורה.
     * סירוב מהשרת כן נועל — שם ההבחנה בין "לא הצלחנו לשאול" לבין
     * "נשאלנו ונענינו לא".
     */
    private fun refresh(done: ((Boolean) -> Unit)? = null) {
        b.progress.visibility = View.VISIBLE
        Api.policy(store.token) { r ->
            b.progress.visibility = View.GONE
            if (!r.ok && r.json?.optString("code") == "unauthorized") { unpair(); return@policy }
            if (r.ok && r.json != null) {
                PolicySync.apply(this, r.json, "home")
                reportApps()
            }
            done?.invoke(r.ok)   // 🔄 — אחרי ההחלה
            // יש גרסה חדשה — עדכון חובה: נועלים עד ההתקנה.
            if (r.ok && Updater.required(this)) { Updater.lock(this); return@policy }

            state = r.json?.optJSONObject("state")
            // תשובה תקינה — לפי state (חסר = סירוב); סירוב מהשרת (חשבון מושעה) — חסום; בלי רשת — כמו שהיה.
            val v = if (r.ok) ServerVerdict.nested(state) else ServerVerdict.from(r)
            if (v.kind != ServerVerdict.Kind.UNKNOWN) allowed = v.allow
            val reason = state?.optString("reason") ?: r.json?.optString("error") ?: ""

            b.greeting.text = store.displayName().ifEmpty { "שלום" }.let { if (it == "שלום") it else "שלום $it" }
            b.headSub.text = quotaLine()

            val (text, style) = when {
                store.released -> "המנוי של ההורים הסתיים — אין יותר הגבלה במכשיר" to R.drawable.bg_status_warn
                !r.ok && r.json == null -> store.policy().let { p ->
                    val now = TrustedClock.now(store)
                    // מה זמין בלי חיבור, ועד מתי (OfflineMath) — לא "מוצגים האתרים שנשמרו" סתם.
                    OfflineMath.statusLine(p.offlineContent, true, OfflineMath.leftMs(store.lastSyncMs, now, p.offlineGraceH))
                        .ifEmpty { "אין חיבור לשרת" }
                } to R.drawable.bg_status_warn
                !r.ok -> reason to R.drawable.bg_status_danger
                !allowed -> reason.ifEmpty { "הגלישה חסומה כרגע" } to R.drawable.bg_status_danger
                store.pendingRequests().isNotEmpty() -> "🙋 מחכים לתשובה מההורה: " +
                    store.pendingRequests().values.joinToString(", ") { AccessRequest.hostOf(it) } to R.drawable.bg_status_warn
                else -> "" to 0
            }
            b.status.text = text
            b.status.visibility = if (text.isEmpty()) View.GONE else View.VISIBLE
            if (style != 0) b.status.setBackgroundResource(style)

            val canType = store.policy().mode == Policy.MODE_BROWSER
            b.search.hint = (if (store.policy().searchEngine == "google") "🔎 חיפוש בגוגל" else "🔎 חיפוש") + (if (canType) " או כתובת" else "")
            draw()
        }
    }

    /** מדווח לשרת אילו אפליקציות (עם מסך פתיחה) מותקנות — פעם אחת בכל ריצה, רק כשמנהלים. */
    private var appsReported = false
    private fun reportApps() {
        if (appsReported || !store.appsManage) return
        appsReported = true
        Thread { AppPolicy.scan(this, force = true) }.start()   // מסכי הבית והמקלדות — לא נחסמים
        AppsReport.send(this)
    }

    private fun quotaLine(): String {
        val left = state?.optInt("quota_left_sec", -1) ?: -1
        if (left < 0) return "מגדלור"
        val m = left / 60
        return if (m > 0) "נשארו לך $m דקות היום" else "הזמן של היום נגמר"
    }

    /** כל האריחים: מה שההורה התיר, ויוטיוב כשהוא פתוח לגמרי. */
    private fun allTiles(): List<Tile> {
        val list = store.tiles().toMutableList()
        val yt = store.ruleSet().platforms[PolicyEngine.PLATFORM_YOUTUBE]
        if (yt?.mode == "full" && list.none { it.url.contains("youtube.com") && it.kind == "platform" }) {
            list.add(0, Tile("יוטיוב", "https://m.youtube.com/", "platform"))
        }
        return list
    }

    private fun draw() {
        val tiles = allTiles()
        val q = query.lowercase()
        val shown = if (q.isEmpty()) tiles else tiles.filter {
            it.label.lowercase().contains(q) || it.url.lowercase().contains(q)
        }
        val favUrls = store.favorites()
        val favs = favUrls.mapNotNull { u -> shown.firstOrNull { it.url == u } }
        val rest = shown.filter { it.url !in favUrls }

        fill(b.favGrid, favs, true)
        b.favTitle.visibility = if (favs.isEmpty()) View.GONE else View.VISIBLE
        b.favGrid.visibility = b.favTitle.visibility

        fill(b.grid, rest, false)
        b.allTitle.visibility = if (rest.isEmpty() || favs.isEmpty()) View.GONE else View.VISIBLE

        b.empty.visibility = if (shown.isEmpty()) View.VISIBLE else View.GONE
        b.empty.text = when {
            q.isNotEmpty() && store.policy().mode == Policy.MODE_BROWSER -> "לא באריחים. 🔎 Enter — חיפוש, או כתובת לפתיחה."
            q.isNotEmpty() -> "לא באריחים. 🔎 Enter — חיפוש."
            else -> "עוד אין לך אתרים. ההורה מוסיף אותם מהאפליקציה שלו."
        }
    }

    private fun fill(grid: GridLayout, tiles: List<Tile>, fav: Boolean) {
        grid.removeAllViews()
        for (t in tiles) {
            val v = layoutInflater.inflate(R.layout.item_tile, grid, false)
            v.findViewById<TextView>(R.id.tileIcon).text = when {
                t.kind == "youtube" || t.kind == "platform" -> "▶️"
                else -> "🌐"
            }
            v.findViewById<TextView>(R.id.tileLabel).text = t.label.ifEmpty { hostOf(t.url) }
            v.findViewById<TextView>(R.id.tileUrl).text = hostOf(t.url)
            v.findViewById<TextView>(R.id.tileStar).visibility = if (fav) View.VISIBLE else View.GONE
            v.isEnabled = allowed
            v.alpha = if (allowed) 1f else 0.45f
            v.setOnClickListener { if (allowed) open(t.url) }
            v.setOnLongClickListener {
                val on = store.toggleFavorite(t.url)
                Toast.makeText(this, if (on) "⭐ נוסף למועדפים" else "הוסר מהמועדפים", Toast.LENGTH_SHORT).show()
                draw(); true
            }
            v.layoutParams = GridLayout.LayoutParams(
                GridLayout.spec(GridLayout.UNDEFINED, 1f), GridLayout.spec(GridLayout.UNDEFINED, 1f)
            ).apply { width = 0; setMargins(dp(4), dp(4), dp(4), dp(4)) }
            grid.addView(v)
        }
    }

    private fun hostOf(url: String): String =
        try { java.net.URI(if (url.contains("://")) url else "https://$url").host?.removePrefix("www.") ?: url }
        catch (e: Exception) { url }

    /** Enter בשורת החיפוש: תוצאה יחידה — נפתחת; במצב דפדפן — כתובת שהוקלדה. */
    private fun submitSearch() {
        if (query.isEmpty() || !allowed) return
        val q = query.lowercase()
        val hits = allTiles().filter { it.label.lowercase().contains(q) || it.url.lowercase().contains(q) }
        when {
            hits.size == 1 -> open(hits[0].url)
            store.policy().mode == Policy.MODE_BROWSER && query.contains('.') && !query.contains(' ') -> open(query)
            else -> runSearch(query)
        }
    }

    /** חיפוש בשרת: אינדקס האתרים שאושרו, או הרשת — לפי מה שההורה קבע. התוצאות כבר מסוננות. */
    private fun runSearch(q: String) {
        // חיפוש גוגל (ההורה בחר): דף החיפוש של גוגל בדפדפן של מגדלור, עם חיפוש בטוח; כל תוצאה נבדקת כשנפתחת.
        if (store.policy().searchEngine == "google") { open(PolicyEngine.googleSearchUrl(q)); return }
        b.progress.visibility = View.VISIBLE
        (getSystemService(INPUT_METHOD_SERVICE) as android.view.inputmethod.InputMethodManager)
            .hideSoftInputFromWindow(b.search.windowToken, 0)
        Api.search(store.token, q) { r ->
            b.progress.visibility = View.GONE
            if (query != q) return@search          // הוקלד משהו אחר בינתיים
            val j = r.json
            if (!r.ok || j == null) { Toast.makeText(this, r.error.ifEmpty { "החיפוש לא הצליח" }, Toast.LENGTH_LONG).show(); return@search }
            showResults(j)
        }
    }

    private fun showResults(j: JSONObject?) {
        b.results.removeAllViews()
        if (j == null) { b.results.visibility = View.GONE; b.resultsTitle.visibility = View.GONE; return }
        val list = j.optJSONArray("results")
        val n = list?.length() ?: 0
        b.resultsTitle.visibility = View.VISIBLE
        b.resultsTitle.text = when {
            n == 0 -> "🔎 " + j.optString("note").ifEmpty { "לא נמצא. אפשר לנסות מילה אחרת." }
            j.optString("engine") == "web" -> "🔎 תוצאות מהרשת"
            else -> "🔎 תוצאות באתרים שלך"
        }
        for (i in 0 until n) {
            val o = list!!.getJSONObject(i)
            val url = o.optString("url")
            val v = layoutInflater.inflate(R.layout.item_result, b.results, false)
            v.findViewById<TextView>(R.id.resIcon).text = when (o.optString("kind")) {
                "video", "channel", "playlist" -> "▶️"
                else -> "🌐"
            }
            v.findViewById<TextView>(R.id.resTitle).text = o.optString("title")
            v.findViewById<TextView>(R.id.resHost).text = o.optString("host")
            v.findViewById<TextView>(R.id.resSnippet).apply {
                text = o.optString("snippet"); visibility = if (text.isNullOrEmpty()) View.GONE else View.VISIBLE
            }
            v.setOnClickListener { if (allowed) open(url) }
            b.results.addView(v)
        }
        b.results.visibility = if (n > 0) View.VISIBLE else View.GONE
    }

    private fun open(url: String) {
        startActivity(Intent(this, BrowserActivity::class.java).putExtra("url", url))
    }

    /**
     * 🔄 רענון עכשיו — בלי לחכות לסנכרון: מושך מההורה את ההגדרות ומחיל אותן (כולל אכיפת האפליקציות ובדיקת
     * עדכון), ומדווח מחדש את האפליקציות המותקנות — כך אפליקציה שהותקנה עכשיו מגיעה להורה מיד.
     */
    private fun syncNow() {
        if (b.progress.visibility == View.VISIBLE) return
        b.sync.isEnabled = false
        appsReported = false
        refresh { ok ->
            b.sync.isEnabled = true
            Toast.makeText(this, if (ok) "✅ עודכן — ההגדרות מההורה הוחלו" else "⚠️ אין חיבור לשרת — ממשיכים עם ההגדרות השמורות",
                Toast.LENGTH_SHORT).show()
        }
    }

    /** "ההגדרות שלי": מה מותר, מתי, וכניסת הורה. */
    private fun showSettings() {
        val p = store.policy()
        val days = listOf("א", "ב", "ג", "ד", "ה", "ו", "ש").filterIndexed { i, _ -> (p.daysMask shr i) and 1 == 1 }
        val tiles = store.tiles()
        val rows = listOf(
            "⏱️" to quotaLine().let { if (it == "מגדלור") "אין מכסה יומית" else it },
            "📅" to if (days.size == 7) "כל השבוע" else "ימים: " + days.joinToString(" "),
            "🕒" to if (p.windowStart.isNotEmpty()) "שעות: ${p.windowStart}–${p.windowEnd}" else "כל שעות היום",
            "🌐" to "${tiles.count { it.kind != "youtube" }} אתרים מאושרים",
            "▶️" to "${tiles.count { it.kind == "youtube" }} ערוצים וסרטונים ביוטיוב",
            "⭐" to "לחיצה ארוכה על אריח — מוסיפה למועדפים",
            "ℹ️" to "גרסה ${BuildConfig.VERSION_NAME}",
        )
        /*
         * תקלה מדווחת (2026-10-22): במסך קטן החלון לא נגלל, ושלושת הכפתורים בתחתית דחקו זה את זה החוצה — לא
         * הייתה גישה ל"הגנה מלאה", ל"כניסת הורה" ול"קוד מההורה". עכשיו: הכול בתוך גלילה, הפעולות למעלה (שורות
         * ללחיצה), והמידע אחריהן; בתחתית רק "סגירה".
         */
        var dialog: androidx.appcompat.app.AlertDialog? = null
        val tap = android.util.TypedValue().also { theme.resolveAttribute(android.R.attr.selectableItemBackground, it, true) }.resourceId
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(4), dp(24), dp(8))
            fun action(t: String, go: () -> Unit) = addView(TextView(this@HomeActivity).apply {
                text = "$t  ›"; textSize = 16f; setPadding(0, dp(12), 0, dp(12))
                setTextColor(getColor(R.color.brand)); setBackgroundResource(tap)
                setOnClickListener { dialog?.dismiss(); go() }
            })
            // בקשת פתיחה גם מכאן: ב"בעל מכשיר" אפליקציה מושעית לא עולה בכלל, ומסך החסימה לא מופיע (2026-10-22).
            if (store.appsManage && !store.released) action("🔒  אפליקציות חסומות — לבקש פתיחה") { showBlockedApps() }
            // הגנה מלאה דרך מחשב (FullSetupActivity) — רק כשעוד אין "בעל מכשיר".
            if (!Enforcer.isOwner(this@HomeActivity) && !store.released) {
                action("🛡️  הגנה מלאה — דרך מחשב, עם ההורה") { startActivity(Intent(this@HomeActivity, FullSetupActivity::class.java)) }
            }
            action("👨‍👩‍👧  כניסת הורה") { startActivity(Intent(this@HomeActivity, ParentActivity::class.java)) }
            // הגדרות מההורה בלי אינטרנט — QR חתום שההורה מציג (OfflinePolicy).
            action("📷  קוד מההורה (בלי אינטרנט)") { scanParentCode() }
            addView(View(this@HomeActivity).apply {
                setBackgroundColor(getColor(R.color.line))
                layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(1)).apply { topMargin = dp(8); bottomMargin = dp(8) }
            })
            for ((ic, t) in rows) addView(TextView(this@HomeActivity).apply {
                text = "$ic  $t"; textSize = 15f; setPadding(0, dp(6), 0, dp(6))
                setTextColor(getColor(R.color.ink))
            })
        }
        dialog = MaterialAlertDialogBuilder(this)
            .setTitle(store.displayName().ifEmpty { "ההגדרות שלי" })
            .setView(android.widget.ScrollView(this).apply { addView(box) })
            .setPositiveButton("סגירה", null)
            .show()
    }

    /** האפליקציות החסומות במכשיר — לחיצה פותחת את מסך החסימה שלה, עם "בקש פתיחה" ומצב הבקשה. */
    private fun showBlockedApps() {
        AppPolicy.scan(this, force = true)
        val apps = store.launchable.filter { AppPolicy.blocked(store, it) }
            .map { it to AppGrants.label(this, it) }.sortedBy { it.second.lowercase() }
        if (apps.isEmpty()) {
            MaterialAlertDialogBuilder(this).setTitle("🔒 אפליקציות חסומות")
                .setMessage("אין כרגע אפליקציות חסומות במכשיר.").setPositiveButton("סגירה", null).show()
            return
        }
        val pending = store.appPending
        MaterialAlertDialogBuilder(this)
            .setTitle("🔒 אפליקציות חסומות")
            .setItems(apps.map { (p, l) -> if (p in pending) "$l — 🕓 ממתין להורה" else l }.toTypedArray()) { _, i ->
                startActivity(Intent(this, BlockedActivity::class.java).putExtra("pkg", apps[i].first))
            }
            .setPositiveButton("סגירה", null)
            .show()
    }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()

    /** המכשיר נותק (ההורה ניתק, או אסימון שבוטל) — חזרה למסך הצימוד. */
    private fun unpair() {
        // כבר משוחרר ועכשיו נותק = ההורה מחק את הילדים: מגדלור מוותר על בעלות המכשיר
        // והמכשיר חוזר לטלפון רגיל. (ניתוק בלי שחרור קודם = מכשיר שאבד — נשארים בעלים.)
        if (store.released) Enforcer.relinquish(this, store)
        store.clear()
        startActivity(Intent(this, PairActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        finish()
    }
}
