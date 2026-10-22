package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton

/**
 * הקמה מלאה דרך מחשב (בעל המערכת, 2026-10-22): "בעל מכשיר" בלי לאפס את הטלפון — כפתור "הסרה" של מגדלור אפור.
 *
 *   כאן — שלושת הצעדים שרק אדם יכול לעשות (אנדרואיד לא מאפשר לאפליקציה): הסרת החשבונות, "אפשרויות מפתחים",
 *   ו"ניפוי USB". לכל אחד כפתור שפותח את המסך הנכון, ✅ איפה שאפשר לזהות (מפתחים, ניפוי). את החשבונות בודק המחשב.
 *   במחשב — setup/index.html (WebUSB): בודק, מגדיר בעל מכשיר, ומכבה את ניפוי ה-USB.
 *
 *   ניפוי USB פתוח בלי הקמה מלאה = אפשר להסיר את מגדלור ממחשב. לכן, כל עוד הוא דלוק — כפתור בולט לכבות אותו.
 */
class FullSetupActivity : AppCompatActivity() {

    private val store by lazy { Store(this) }
    private val main = Handler(Looper.getMainLooper())
    private val poll = object : Runnable { override fun run() { refresh(); main.postDelayed(this, 2000) } }
    private var wasOwner = false
    private var shown = ""

    /** בונים מחדש רק כשמשהו השתנה — אחרת הגלילה קופצת כל שתי שניות. */
    private fun refresh() {
        val now = "${Enforcer.isOwner(this)}|${devOn()}|${adbOn()}"
        if (now != shown) { shown = now; render() }
    }

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        wasOwner = Enforcer.isOwner(this)
        refresh()
    }

    override fun onResume() { super.onResume(); main.post(poll) }
    override fun onPause() { super.onPause(); main.removeCallbacks(poll) }

    private fun global(key: String) = try { Settings.Global.getInt(contentResolver, key, 0) == 1 } catch (e: Exception) { false }
    private fun devOn() = global(Settings.Global.DEVELOPMENT_SETTINGS_ENABLED)
    private fun adbOn() = global(Settings.Global.ADB_ENABLED)

    private fun render() {
        val owner = Enforcer.isOwner(this)
        // המחשב הגדיר אותנו עכשיו — מושכים מדיניות ומחילים מיד (הגנה מהסרה, הגבלות), בלי לחכות לסנכרון.
        if (owner && !wasOwner) {
            wasOwner = true
            if (store.isLoggedIn) Api.policy(store.token) { r -> if (r.ok && r.json != null) PolicySync.apply(this, r.json, "fullsetup") }
            else Enforcer.apply(this, store)
        }
        val pad = dp(24)
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(pad, pad, pad, pad)
            addView(text(if (owner) "✅ הקמה מלאה" else "🛡️ הגנה מלאה — דרך מחשב", 22f, R.color.ink, 0))
            if (owner) {
                addView(text("מגדלור הוא \"בעל המכשיר\": כפתור ההסרה שלו אפור, והגדרות המכשיר שההורה קבע נאכפות.", 15f, R.color.dim, dp(8)))
                if (adbOn()) addView(warnAdb())
                addView(text("להחזרת חשבון גוגל: באפליקציית ההורה ← מכשירים ← ⚙️ הגדרות המכשיר ← 👤 חשבונות ← מותר. " +
                    "מוסיפים את החשבון כאן בטלפון, ואז ההורה חוסם שוב.", 14f, R.color.dim, dp(16)))
                addView(button("סגירה", false) { finish() })
                return@apply
            }
            addView(text("כמו באפליקציות האכיפה של ארגונים: כפתור \"הסרה\" של מגדלור אפור, והגדרות הטלפון נאכפות — " +
                "בלי לאפס את הטלפון. עושים את זה יחד עם ההורה, עם מחשב וכבל.", 15f, R.color.dim, dp(8)))
            if (adbOn()) addView(warnAdb())
            addView(step("1", "מסירים את כל החשבונות", "גוגל, סמסונג, וואטסאפ וכל השאר. אחרי ההקמה מחזירים אותם. את הרשימה המדויקת יראה המחשב.",
                null, "פתיחת החשבונות") { open(Intent(Settings.ACTION_SYNC_SETTINGS)) })
            addView(step("2", "מפעילים \"אפשרויות מפתחים\"",
                "במסך שייפתח — נוגעים 7 פעמים ב\"מספר Build\" (בסמסונג: מידע תוכנה ← מספר Build; בשיאומי: \"גרסת MIUI/HyperOS\").",
                devOn(), "פתיחת \"אודות הטלפון\"") { open(Intent(Settings.ACTION_DEVICE_INFO_SETTINGS)) })
            addView(step("3", "מדליקים \"ניפוי USB\"", "בתוך \"אפשרויות מפתחים\". בשיאומי — גם \"ניפוי USB (הגדרות אבטחה)\".",
                adbOn(), "פתיחת אפשרויות מפתחים") { open(Intent(Settings.ACTION_APPLICATION_DEVELOPMENT_SETTINGS)) })
            addView(step("4", "במחשב, בכרום — פותחים את הכתובת:", Api.root() + "setup/", null, null, selectable = true) {})
            addView(text("מחברים כבל, לוחצים שם \"חיבור לטלפון\", ומאשרים כאן \"לאפשר ניפוי באגים\". הכול השאר — אוטומטי, " +
                "והמסך הזה יתעדכן לבד.", 14f, R.color.dim, dp(12)))
            addView(button("חזרה", false) { finish() })
        }
        setContentView(ScrollView(this).apply { setBackgroundColor(getColor(R.color.bg)); addView(box) })
    }

    /** ניפוי USB דלוק — אפשר להסיר את מגדלור ממחשב. כפתור לכבות (המסך בהגדרות — שם המתג). */
    private fun warnAdb(): View = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        setPadding(0, dp(16), 0, 0)
        addView(text("⚠️ ניפוי USB דלוק. אם לא ממשיכים עכשיו להקמה — מכבים אותו: דרכו אפשר להסיר את מגדלור ממחשב.", 15f, R.color.danger, 0))
        addView(button("כיבוי ניפוי USB", true) { open(Intent(Settings.ACTION_APPLICATION_DEVELOPMENT_SETTINGS)) })
    }

    private fun step(n: String, title: String, desc: String, done: Boolean?, action: String?, selectable: Boolean = false, onClick: () -> Unit): View =
        LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, dp(20), 0, 0)
            addView(text("${if (done == true) "✅" else n + "."} $title", 17f, R.color.ink, 0).apply { gravity = Gravity.START })
            addView(text(desc, 14f, R.color.dim, dp(4)).apply {
                gravity = Gravity.START
                if (selectable) { setTextIsSelectable(true); textSize = 16f; textDirection = View.TEXT_DIRECTION_LTR; setTextColor(getColor(R.color.ink)) }
            })
            if (action != null && done != true) addView(button(action, false, onClick))
        }

    /** מסכי ההגדרות — דרך openSettings: גם כשההורה חסם את ההגדרות, מגדלור עצמו פותח אותן לשלב חובה. */
    private fun open(intent: Intent) {
        try { AppPolicy.openSettings(this, intent) }
        catch (e: Exception) { try { AppPolicy.openSettings(this, Intent(Settings.ACTION_SETTINGS)) } catch (e2: Exception) {} }
    }

    private fun text(t: String, size: Float, color: Int, top: Int) = TextView(this).apply {
        text = t; textSize = size; setTextColor(getColor(color)); setPadding(0, top, 0, dp(4))
    }

    private fun button(t: String, primary: Boolean, onClick: () -> Unit): View =
        (if (primary) MaterialButton(this) else MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle)).apply {
            text = t
            setOnClickListener { onClick() }
            layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                .apply { topMargin = dp(8) }
        }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
