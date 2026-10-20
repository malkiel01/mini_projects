package com.mbeplus.guardedbrowser

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.Gravity
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton

/**
 * מסך העדכון — עדכון חובה (Updater). כל עוד הגרסה שמותקנת ישנה, כל מסך של מגדלור מפנה לכאן,
 * ואין ממנו יציאה פנימה: "חזרה" מוליכה למסך הבית של הטלפון, וכל פתיחה מחדש חוזרת לכאן.
 *
 * "בעל מכשיר" — ההתקנה שקטה ומתחילה לבד. בלי — הילד לוחץ "עדכון עכשיו" ומאשר במסך של אנדרואיד.
 */
class UpdateActivity : AppCompatActivity() {

    private lateinit var msg: TextView
    private lateinit var go: MaterialButton

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        val pad = dp(28)
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(pad, pad, pad, pad)
            setBackgroundColor(getColor(R.color.bg))
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            addView(ImageView(this@UpdateActivity).apply {
                setImageResource(R.drawable.logo)
                layoutParams = LinearLayout.LayoutParams(dp(72), dp(72))
            })
            addView(TextView(this@UpdateActivity).apply {
                text = "⬆️ צריך לעדכן את מגדלור"
                textSize = 22f; setTextColor(getColor(R.color.ink))
                gravity = Gravity.CENTER; setPadding(0, dp(16), 0, dp(6))
            })
            msg = TextView(this@UpdateActivity).apply {
                textSize = 15f; setTextColor(getColor(R.color.dim)); gravity = Gravity.CENTER
            }
            addView(msg)
            go = MaterialButton(this@UpdateActivity).apply {
                text = "עדכון עכשיו"
                setOnClickListener { start() }
                layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                    .apply { topMargin = dp(24) }
            }
            addView(go)
        }
        setContentView(box)

        // "חזרה" לא מחזירה פנימה — רק למסך הבית של הטלפון.
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                startActivity(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }
        })
    }

    override fun onResume() {
        super.onResume()
        // הגרסה החדשה כבר מותקנת (או שהשרת ביטל) — חוזרים למגדלור.
        if (!Updater.required(this)) {
            startActivity(Intent(this, HomeActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP))
            finish()
            return
        }
        msg.text = "יש גרסה חדשה של מגדלור. עד שהיא מותקנת אי אפשר להשתמש באפליקציה.\nהעדכון לוקח רגע, וכל ההגדרות נשמרות."
        // בעל מכשיר — מתחילים לבד, בלי לחיצה.
        if (Enforcer.isOwner(this)) start()
    }

    private fun start() {
        // בלי בעל מכשיר, אנדרואיד 8+ דורש היתר "התקנת אפליקציות לא מוכרות" — פעם אחת, בהגדרות.
        if (!Enforcer.isOwner(this) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
            msg.text = "במסך הבא מדליקים \"לאפשר מהמקור הזה\", חוזרים, ולוחצים שוב \"עדכון עכשיו\"."
            try { AppPolicy.openSettings(this, Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName"))) }
            catch (e: Exception) { Trace.log("update.settings", e.message ?: "") }
            return
        }
        Updater.run(this) { t -> msg.text = t }
    }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
