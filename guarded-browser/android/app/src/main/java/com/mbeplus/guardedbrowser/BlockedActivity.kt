package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/**
 * מסך החסימה של אפליקציה (שלב 11). מוצג מעל אפליקציה שההורה חסם, ומסביר למה.
 *
 * בקשת פתיחה (2026-10-22): שם האפליקציה, הסיבה, "🙋 בקש פתיחה מההורה" (עם הערה), ומצב בקשה קיימת — כדי לא
 * לשלוח שוב. כל עוד המסך פתוח ויש בקשה ממתינה, הוא שואל את השרת; כשההורה ענה — מושך מדיניות ומחיל (PolicySync,
 * ‏AppGrants), ומציג "נפתחה" רק אם היא באמת פתוחה עכשיו.
 *
 * נפתח גם מתוך מגדלור (HomeActivity, "אפליקציות חסומות") — ב"בעל מכשיר" אפליקציה מושעית בכלל לא עולה,
 * ושירות הנגישות לא רואה את הניסיון לפתוח אותה. אין כאן דרך לעקוף — רק לבקש או לחזור.
 */
class BlockedActivity : AppCompatActivity() {

    private val store by lazy { Store(this) }
    private val main = Handler(Looper.getMainLooper())
    private val poll = Runnable { pollAnswer() }
    private var pkg = ""

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        render()
    }

    // ‏singleTask — אפליקציה חסומה אחרת פותחת את אותו מסך עם חבילה אחרת.
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        render()
    }

    override fun onResume() { super.onResume(); schedulePoll(1500) }
    override fun onPause() { super.onPause(); main.removeCallbacks(poll) }

    private fun render() {
        pkg = intent?.getStringExtra("pkg").orEmpty()
        val removal = intent?.getBooleanExtra("removal", false) == true
        val name = if (pkg.isEmpty()) "האפליקציה הזו" else AppGrants.label(this, pkg)
        val pad = dp(28)
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(pad, pad, pad, pad)
            setBackgroundColor(getColor(R.color.bg))
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            addView(ImageView(this@BlockedActivity).apply {
                setImageResource(R.drawable.logo)
                layoutParams = LinearLayout.LayoutParams(dp(72), dp(72))
            })
            addView(text(if (removal) "אי אפשר להסיר את מגדלור" else "$name חסומה", 22f, R.color.ink, dp(16)))
            addView(text(if (removal) "הסרת מגדלור וביטול ניהול המכשיר אפשריים רק בתיאום עם ההורה." else reason(), 15f, R.color.dim, 0))
            if (!removal && canAsk()) {
                val pending = store.appPending[pkg]
                if (pending != null) {
                    addView(text("🕓 ביקשת לפתוח ב-${hhmm(pending.second)} — הבקשה אצל ההורה. נודיע כשיענה.", 15f, R.color.ink, dp(18)))
                } else {
                    addView(button("🙋 בקש פתיחה מההורה", true) { ask() })
                }
            }
            addView(button("חזרה למסך הבית", false) { goHome() })
        }
        setContentView(box)
    }

    /** למה חסומה — חסימה מפורשת, או "רק מה שאישרתי" בלי אישור. */
    private fun reason(): String = when {
        pkg in store.appsBlocked -> "ההורה חסם את האפליקציה הזו במגדלור. אפשר לבקש ממנו לפתוח אותה."
        else -> "במכשיר הזה פתוחות רק אפליקציות שההורה אישר, ואת זו הוא עוד לא אישר. אפשר לבקש."
    }

    private fun canAsk() = pkg.isNotEmpty() && store.isLoggedIn && store.appsManage && !store.released

    private fun ask() {
        val note = EditText(this).apply {
            hint = "למה? (לא חובה)"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_SENTENCES
            maxLines = 3
        }
        val box = FrameLayout(this).apply { setPadding(dp(20), dp(10), dp(20), 0); addView(note) }
        MaterialAlertDialogBuilder(this)
            .setTitle("🙋 לבקש לפתוח את ${AppGrants.label(this, pkg)}?")
            .setMessage("ההורה יקבל הודעה, ויוכל לפתוח אותה לתמיד או לזמן מסוים.")
            .setView(box)
            .setNegativeButton("ביטול", null)
            .setPositiveButton("שליחה") { _, _ ->
                Api.appRequest(store.token, pkg, note.text.toString().trim()) { r ->
                    val id = r.json?.optInt("id", 0) ?: 0
                    if (r.ok && id > 0) {
                        store.appPending = store.appPending + (pkg to (id to System.currentTimeMillis()))
                        Toast.makeText(this, r.json?.optString("message") ?: "נשלח", Toast.LENGTH_LONG).show()
                        render(); schedulePoll(8000)
                    } else {
                        Toast.makeText(this, r.json?.optString("reason")?.ifEmpty { null } ?: r.error.ifEmpty { "השליחה נכשלה" },
                                       Toast.LENGTH_LONG).show()
                    }
                }
            }
            .show()
    }

    private fun schedulePoll(ms: Long) {
        main.removeCallbacks(poll)
        if (store.appPending.containsKey(pkg)) main.postDelayed(poll, ms)
    }

    /** ההורה ענה? — מושכים מדיניות, מחילים, ומציגים מה *באמת* קרה במכשיר. */
    private fun pollAnswer() {
        val mine = store.appPending[pkg] ?: return
        Api.requests(store.token) { r ->
            val list = r.json?.optJSONArray("requests")
            val st = (0 until (list?.length() ?: 0)).map { list!!.getJSONObject(it) }.firstOrNull { it.optInt("id") == mine.first }
            if (st == null || st.optString("status") == "pending") { schedulePoll(8000); return@requests }
            Api.policy(store.token) { p ->
                if (p.ok && p.json != null) PolicySync.apply(this, p.json, "blocked")
                if (!AppPolicy.blocked(store, pkg)) {
                    Toast.makeText(this, "✅ ${AppGrants.label(this, pkg)} נפתחה", Toast.LENGTH_LONG).show()
                    packageManager.getLaunchIntentForPackage(pkg)?.let { startActivity(it); finish(); return@policy }
                }
                render(); schedulePoll(8000)
            }
        }
    }

    private fun text(t: String, size: Float, color: Int, top: Int) = TextView(this).apply {
        text = t; textSize = size; setTextColor(getColor(color)); gravity = Gravity.CENTER; setPadding(0, top, 0, dp(6))
    }

    private fun button(t: String, primary: Boolean, onClick: () -> Unit): View =
        (if (primary) MaterialButton(this) else MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle)).apply {
            text = t
            setOnClickListener { onClick() }
            layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                .apply { topMargin = dp(if (primary) 24 else 12) }
        }

    private fun hhmm(ms: Long) = java.text.SimpleDateFormat("HH:mm", java.util.Locale("he")).format(java.util.Date(ms))

    private fun goHome() {
        startActivity(Intent(Intent.ACTION_MAIN)
            .addCategory(Intent.CATEGORY_HOME)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        finish()
    }

    override fun onBackPressed() { goHome() }
    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
