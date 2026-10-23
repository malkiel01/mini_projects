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

    /** מגדלור הוא מסך הבית של הטלפון (Setup.isHome) — נבדק בכל חזרה למסך. */
    private var isHome = false

    // מסך הבית של הטלפון — "חזרה" לא יוצאת ממנו (כמו כל מסך בית).
    override fun onBackPressed() { if (!isHome) super.onBackPressed() }

    override fun onResume() {
        super.onResume()
        isHome = Setup.isHome(this)
        // שער ההקמה: בלי ההרשאות החיוניות לא נכנסים למגדלור.
        store.perms = Setup.flags(this)
        if (!Setup.ready(this)) { startActivity(Intent(this, SetupActivity::class.java)); return }
        // עדכון חובה — עד ההתקנה אין כניסה (Updater).
        if (Updater.required(this)) { Updater.lock(this); return }
        MarketInstall.tick(this)   // חזרה מ-Play — החלון נסגר כשההתקנה הסתיימה או כשהזמן עבר
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
            // רובריקת החיפוש כבויה: במצב "דפדפן" — שורת כתובת בלבד; אחרת — מסנן של מה שבדף.
            b.search.hint = when {
                has("search") -> (if (store.policy().searchEngine == "google") "🔎 חיפוש בגוגל" else "🔎 חיפוש") + (if (canType) " או כתובת" else "")
                canType -> "🔗 כתובת, או סינון של מה שכאן"
                else -> "🔎 סינון של מה שכאן"
            }
            draw()
            loadApps()
            loadRequests()
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

    // כשמגדלור הוא מסך הבית, רובריקת האפליקציות תמיד מוצגת — אחרת אין לילד דרך לפתוח שום אפליקציה מותרת.
    private fun has(section: String) = section in store.policy().homeSections || (section == "apps" && isHome)

    /**
     * דף הבית ברובריקות (בעל המערכת, 2026-10-22): רק מה שההורה הדליק (Policy.homeSections), לפי הסדר — מועדפים,
     * משימות, אתרים, יוטיוב, אפליקציות, בקשות. החיפוש בשורה למעלה מסנן את כולן.
     */
    private fun draw() {
        val q = query.lowercase()
        val match = { label: String, url: String -> q.isEmpty() || label.lowercase().contains(q) || url.lowercase().contains(q) }
        val shown = allTiles().filter { match(it.label, it.url) }
        val favUrls = if (has("favorites")) store.favorites() else emptyList()
        b.sections.removeAllViews()
        var any = false

        // ועוד מועדפים שנוספו מהדפדפן (⋮ ← ☆) — אותה הכרעה כשנפתחים, כמו כל ניווט.
        val marks = if (has("favorites")) store.bookmarks().filter { (t, u) -> favUrls.none { it == u } && match(t, u) }
            .map { (t, u) -> Tile(t, u, "url") } else emptyList()
        val favs = favUrls.mapNotNull { u -> shown.firstOrNull { it.url == u } } + marks.filter { m -> shown.none { it.url == m.url } }
        if (favs.isNotEmpty()) { section("⭐ מועדפים", favs, true); any = true }
        val rest = shown.filter { it.url !in favUrls }
        val tasks = rest.filter { it.kind == "task" }
        if (has("tasks") && tasks.isNotEmpty()) { section("📝 משימות", tasks, false); any = true }
        val sites = rest.filter { it.kind == "url" }
        if (has("sites") && sites.isNotEmpty()) { section("🌐 אתרים", sites, false); any = true }
        val yt = rest.filter { it.kind == "youtube" || it.kind == "platform" }
        if (has("youtube") && yt.isNotEmpty()) { section("▶️ יוטיוב", yt, false); any = true }
        if (has("apps") && store.appsManage) {
            val apps = appsCache.filter { match(it.second, it.first) }
            val market = marketCache.filter { match(it.second, it.first) }
            if (apps.isNotEmpty() || market.isNotEmpty()) { appsSection(apps, market); any = true }
        }
        if (has("requests") && q.isEmpty() && myRequests.isNotEmpty()) { requestsSection(); any = true }

        b.empty.visibility = if (any) View.GONE else View.VISIBLE
        b.empty.text = when {
            q.isNotEmpty() && store.policy().mode == Policy.MODE_BROWSER -> "לא נמצא כאן. 🔎 Enter — חיפוש, או כתובת לפתיחה."
            q.isNotEmpty() && has("search") -> "לא נמצא כאן. 🔎 Enter — חיפוש."
            q.isNotEmpty() -> "לא נמצא כאן."
            else -> "עוד אין כאן כלום. ההורה מוסיף מהאפליקציה שלו."
        }
    }

    /** כותרת של רובריקה. */
    private fun header(t: String) = b.sections.addView(
        TextView(this, null, 0, R.style.MgSection).apply { text = t },
        // המרווחים של MgSection הם של ה-layout — לא עוברים מסגנון בקוד.
        LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
            .apply { topMargin = dp(16); bottomMargin = dp(8) })

    private fun grid() = GridLayout(this).apply { columnCount = 2 }.also { b.sections.addView(it) }

    private fun section(title: String, tiles: List<Tile>, fav: Boolean) {
        header(title)
        fill(grid(), tiles, fav)
    }

    private fun fill(grid: GridLayout, tiles: List<Tile>, fav: Boolean) {
        val now = TrustedClock.now(store)
        for (t in tiles) {
            val v = tile(grid)
            v.findViewById<TextView>(R.id.tileIcon).text = when (t.kind) {
                "youtube", "platform" -> "▶️"
                "task" -> "📝"
                else -> "🌐"
            }
            v.findViewById<TextView>(R.id.tileLabel).text = t.label.ifEmpty { hostOf(t.url) }
            v.findViewById<TextView>(R.id.tileUrl).text = if (t.kind == "task") HomeMath.taskLine(t.ends, now) else hostOf(t.url)
            v.findViewById<TextView>(R.id.tileStar).visibility = if (fav) View.VISIBLE else View.GONE
            v.setOnClickListener { if (allowed) open(t.url) }
            if (has("favorites")) v.setOnLongClickListener {
                // מועדף מהדפדפן — לחיצה ארוכה מסירה אותו משם.
                if (fav && store.isBookmarked(t.url)) {
                    store.toggleBookmark("", t.url)
                    Toast.makeText(this, "הוסר מהמועדפים", Toast.LENGTH_SHORT).show()
                    draw(); return@setOnLongClickListener true
                }
                val on = store.toggleFavorite(t.url)
                Toast.makeText(this, if (on) "⭐ נוסף למועדפים" else "הוסר מהמועדפים", Toast.LENGTH_SHORT).show()
                draw(); true
            }
        }
    }

    /** אריח ריק בתוך הרשת — לפי item_tile. */
    private fun tile(grid: GridLayout): View {
        val v = layoutInflater.inflate(R.layout.item_tile, grid, false)
        v.isEnabled = allowed
        v.alpha = if (allowed) 1f else 0.45f
        v.layoutParams = GridLayout.LayoutParams(
            GridLayout.spec(GridLayout.UNDEFINED, 1f), GridLayout.spec(GridLayout.UNDEFINED, 1f)
        ).apply { width = 0; setMargins(dp(4), dp(4), dp(4), dp(4)) }
        grid.addView(v)
        return v
    }

    /* ── 📲 אפליקציות: מה שמותר לילד לפתוח במכשיר, ומהמרקט — מה ששוחרר לו ועוד לא מותקן ── */

    /** (חבילה, שם) — האפליקציות שמותר לפתוח, לפי המדיניות. נבנה ברקע (AppPolicy.scan, PackageManager). */
    @Volatile private var appsCache: List<Pair<String, String>> = emptyList()
    /** מהמרקט: שוחררו ולא מותקנות — "📥 התקנה" (MarketInstall). */
    @Volatile private var marketCache: List<Pair<String, String>> = emptyList()

    private fun loadApps() {
        if (!store.appsManage || !has("apps")) { appsCache = emptyList(); marketCache = emptyList(); return }
        Thread {
            try {
                AppPolicy.scan(this)
                val pm = packageManager
                val list = (store.launchable - store.launchers - store.ownPackage)
                    .filter { !AppPolicy.blocked(store, it) }
                    .map { it to (try { pm.getApplicationLabel(pm.getApplicationInfo(it, 0)).toString() } catch (e: Exception) { it }) }
                    .sortedBy { it.second.lowercase() }
                appsCache = list
                val installed = store.launchable
                marketCache = store.market.filter { it.first !in installed && it.first != store.ownPackage }
                runOnUiThread { if (!isFinishing) draw() }
            } catch (e: Exception) { Trace.log("home.apps", e.message ?: "") }
        }.start()
    }

    private fun appsSection(apps: List<Pair<String, String>>, market: List<Pair<String, String>>) {
        header("📲 אפליקציות")
        val g = grid()
        val now = System.currentTimeMillis()
        for ((pkg, label) in market) {
            val v = tile(g)
            v.findViewById<TextView>(R.id.tileIcon).text = "📥"
            v.findViewById<TextView>(R.id.tileLabel).text = label
            val busy = MarketMath.action(false, pkg, store.marketTarget, store.marketUntil, now) == "installing"
            v.findViewById<TextView>(R.id.tileUrl).text = if (busy) "⏳ בהתקנה…" else "התקנה"
            v.setOnClickListener { if (allowed) confirmInstall(pkg, label) }
        }
        for ((pkg, label) in apps) {
            val v = tile(g)
            v.findViewById<TextView>(R.id.tileIcon).apply {
                text = ""
                try { background = packageManager.getApplicationIcon(pkg) } catch (e: Exception) { text = "📱" }
            }
            v.findViewById<TextView>(R.id.tileLabel).text = label
            v.findViewById<TextView>(R.id.tileUrl).visibility = View.GONE
            v.setOnClickListener {
                val i = packageManager.getLaunchIntentForPackage(pkg)
                if (allowed && i != null) startActivity(i)
            }
        }
    }

    /** התקנה מהמרקט: Google Play נפתחת לכמה דקות, רק לאפליקציה הזו. */
    private fun confirmInstall(pkg: String, label: String) {
        MaterialAlertDialogBuilder(this)
            .setTitle("📥 התקנת $label")
            .setMessage("מגדלור יתקין את $label דרך Google Play, ויחזור לכאן כשההתקנה תסתיים. אם יצטרכו אותך — " +
                "Play תיפתח לכמה דקות, רק לאפליקציה הזו.\n\nצריך חשבון Google במכשיר. אפליקציה אחרת שתותקן בזמן הזה — תיחסם עד שההורה יאשר.")
            .setPositiveButton("התקנה") { _, _ ->
                try { MarketInstall.start(this, pkg, label) }
                catch (e: Exception) { Toast.makeText(this, "חנות Google Play לא נמצאה במכשיר", Toast.LENGTH_LONG).show() }
            }
            .setNegativeButton("ביטול", null)
            .show()
    }

    /* ── 🙋 הבקשות שלי: מהיממה האחרונה (api do=requests) ── */

    private var myRequests: List<org.json.JSONObject> = emptyList()

    private fun loadRequests() {
        if (!has("requests")) { myRequests = emptyList(); return }
        Api.requests(store.token) { r ->
            val a = r.json?.optJSONArray("requests") ?: return@requests
            myRequests = (0 until minOf(a.length(), 8)).mapNotNull { a.optJSONObject(it) }
            if (!isFinishing) draw()
        }
    }

    private fun requestsSection() {
        header("🙋 הבקשות שלי")
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundResource(R.drawable.bg_card)
            setPadding(dp(12), dp(4), dp(12), dp(4))
        }
        for (o in myRequests) {
            val what = if (o.optString("kind") == "app") AppGrants.label(this, o.optString("package"))
                       else AccessRequest.hostOf(o.optString("url"))
            box.addView(TextView(this).apply {
                text = "$what — ${HomeMath.requestStatus(o.optString("status"))}"
                textSize = 14f; setTextColor(getColor(R.color.ink)); setPadding(0, dp(8), 0, dp(8))
            })
        }
        b.sections.addView(box)
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
            has("search") -> runSearch(query)
            else -> Toast.makeText(this, "החיפוש סגור. אפשר לבקש מההורה לפתוח אותו.", Toast.LENGTH_SHORT).show()
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
            // מסך הבית (Setup.isHome): בלי "בעל מכשיר" — כך אפליקציות מוסתרות לא מופיעות לילד.
            if (store.appsManage && !store.released && !Setup.isHome(this@HomeActivity)) {
                action("🏠  מגדלור כמסך הבית של הטלפון") { Setup.requestHome(this@HomeActivity) }
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
        // מוסתרת (👻) — לא קיימת מבחינת הילד: לא ברשימה הזו (בלי "בעל מכשיר" היא עדיין מותקנת וחסומה).
        val hidden = store.appsHidden
        val apps = store.launchable.filter { it !in hidden && AppPolicy.blocked(store, it) }
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
