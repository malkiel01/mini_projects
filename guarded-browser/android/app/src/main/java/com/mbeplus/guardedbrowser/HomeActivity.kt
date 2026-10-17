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
        b.search.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun onTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun afterTextChanged(s: Editable?) { query = s?.toString()?.trim() ?: ""; draw() }
        })
        b.search.setOnEditorActionListener { _, id, _ ->
            if (id == EditorInfo.IME_ACTION_SEARCH || id == EditorInfo.IME_ACTION_GO) { submitSearch(); true } else false
        }
    }

    override fun onResume() {
        super.onResume()
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
    private fun refresh() {
        b.progress.visibility = View.VISIBLE
        Api.policy(store.token) { r ->
            b.progress.visibility = View.GONE
            if (!r.ok && r.json?.optString("code") == "unauthorized") { unpair(); return@policy }
            if (r.ok && r.json != null) store.savePolicy(r.json)
            // ההורה ביקש לעדכן, ויש גרסה חדשה.
            r.json?.optJSONObject("update")?.let { Updater.offer(this, it) }

            state = r.json?.optJSONObject("state")
            allowed = state?.optBoolean("allowed") ?: true
            val reason = state?.optString("reason") ?: r.json?.optString("error") ?: ""

            b.greeting.text = store.displayName().ifEmpty { "שלום" }.let { if (it == "שלום") it else "שלום $it" }
            b.headSub.text = quotaLine()

            val (text, style) = when {
                !r.ok && r.json == null -> "אין חיבור לשרת — מוצגים האתרים שנשמרו" to R.drawable.bg_status_warn
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
            b.search.hint = if (canType) "חיפוש או כתובת" else "חיפוש באתרים שלי"
            draw()
        }
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
            q.isNotEmpty() && store.policy().mode == Policy.MODE_BROWSER -> "לא נמצא באתרים שלך. Enter — לפתוח כתובת."
            q.isNotEmpty() -> "לא נמצא באתרים שלך."
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
            hits.isEmpty() -> Toast.makeText(this, "חיפוש ברשת — בקרוב. כרגע: רק באתרים שלך.", Toast.LENGTH_SHORT).show()
        }
    }

    private fun open(url: String) {
        startActivity(Intent(this, BrowserActivity::class.java).putExtra("url", url))
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
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(8), dp(24), 0)
            for ((ic, t) in rows) addView(TextView(this@HomeActivity).apply {
                text = "$ic  $t"; textSize = 15f; setPadding(0, dp(8), 0, dp(8))
                setTextColor(getColor(R.color.ink))
            })
        }
        MaterialAlertDialogBuilder(this)
            .setTitle(store.displayName().ifEmpty { "ההגדרות שלי" })
            .setView(box)
            .setPositiveButton("סגירה", null)
            .setNeutralButton("👨‍👩‍👧 כניסת הורה") { _, _ -> startActivity(Intent(this, ParentActivity::class.java)) }
            .show()
    }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()

    /** המכשיר נותק (ההורה ניתק, או אסימון שבוטל) — חזרה למסך הצימוד. */
    private fun unpair() {
        store.clear()
        startActivity(Intent(this, PairActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        finish()
    }
}
