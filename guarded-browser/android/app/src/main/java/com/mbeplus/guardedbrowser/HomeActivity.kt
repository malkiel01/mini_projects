package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.guardedbrowser.databinding.ActivityHomeBinding

/**
 * מסך הפתיחה: האריחים שהוגדרו למשתמש, ומצבו ברגע זה.
 *
 * המסך הזה הוא גם נקודת האכיפה הראשונה — הוא מרענן מדיניות בכל
 * כניסה, ומשתמש שהושעה או שמכסתו נגמרה אינו מגיע לדפדפן בכלל.
 */
class HomeActivity : AppCompatActivity() {

    private lateinit var b: ActivityHomeBinding
    private lateinit var store: Store

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)
        if (!store.isLoggedIn) { logout(); return }

        b = ActivityHomeBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.logout.setOnClickListener { logout() }
        b.openUrl.setOnClickListener { openTyped() }
    }

    override fun onResume() {
        super.onResume()
        refresh()
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

            if (!r.ok && r.json?.optString("code") == "unauthorized") { logout(); return@policy }
            if (r.ok && r.json != null) store.savePolicy(r.json)

            val state = r.json?.optJSONObject("state")
            val allowed = state?.optBoolean("allowed") ?: true
            val reason = state?.optString("reason") ?: ""

            b.greeting.text = "שלום ${store.displayName()}"
            b.status.text = when {
                !r.ok && r.json == null -> "אין חיבור לשרת — מוצגת הרשימה האחרונה שנשמרה"
                !allowed -> reason
                else -> quotaLine(state)
            }
            b.status.visibility = if (b.status.text.isNullOrEmpty()) View.GONE else View.VISIBLE

            drawTiles(allowed)
        }
    }

    private fun quotaLine(state: org.json.JSONObject?): String {
        val left = state?.optInt("quota_left_sec", -1) ?: -1
        if (left < 0) return ""
        val m = left / 60
        return if (m > 0) "נותרו לך $m דקות צפייה היום" else "מכסת היום נוצלה"
    }

    /**
     * אריח לכל קיצור דרך שהשרת שלח — כללי כתובות שסומנו להצגה,
     * וגם ערוצים וסרטונים שאושרו ביוטיוב.
     *
     * בלי החלק השני, ערוץ מאושר אינו נגיש בכלל במצב קיוסק: אין שורת
     * כתובת, ואין אריח שמוביל אליו.
     */
    private fun drawTiles(enabled: Boolean) {
        b.tiles.removeAllViews()

        val tiles = store.tiles()
        val policy = store.policy()

        // שורת הכתובת קיימת רק כשהמצב מתיר אותה. בקיוסק אין מה להקליד.
        val canType = policy.mode == Policy.MODE_BROWSER
        b.urlRow.visibility = if (canType && enabled) View.VISIBLE else View.GONE

        if (tiles.isEmpty()) {
            b.empty.visibility = View.VISIBLE
            b.empty.text = if (canType) "לא הוגדרו קיצורים. אפשר להקליד כתובת למעלה."
                           else "לא הוגדרו לך קישורים עדיין."
            return
        }
        b.empty.visibility = View.GONE

        for (tile in tiles) {
            val v = layoutInflater.inflate(R.layout.item_tile, b.tiles, false)
            v.findViewById<TextView>(R.id.tileLabel).text =
                (if (tile.kind == "youtube") "▶ " else "") + tile.label.ifEmpty { tile.url }
            v.findViewById<TextView>(R.id.tileUrl).text = tile.url
            v.isEnabled = enabled
            v.alpha = if (enabled) 1f else 0.4f
            if (enabled) v.setOnClickListener { open(tile.url) }
            b.tiles.addView(v)
        }
    }

    private fun openTyped() {
        val url = b.urlInput.text.toString().trim()
        if (url.isNotEmpty()) open(url)
    }

    private fun open(url: String) {
        startActivity(Intent(this, BrowserActivity::class.java).putExtra("url", url))
    }

    private fun logout() {
        store.clear()
        startActivity(Intent(this, LoginActivity::class.java))
        finish()
    }
}
