package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.mbeplus.guardedbrowser.databinding.ActivitySetupBinding

/**
 * שער ההקמה (אחרי הצימוד, לפני מסך הבית).
 *
 * כל שלב מאשרים במסך של אנדרואיד עצמו, ומסך זה רק בודק מחדש בכל חזרה. השלבים החיוניים
 * חוסמים את הכפתור "המשך"; המומלצים אפשר לדלג. בכל מעבר נשמר מצב ההרשאות (store.perms),
 * כדי שההורה יראה אותו.
 */
class SetupActivity : AppCompatActivity() {

    private lateinit var b: ActivitySetupBinding
    private lateinit var store: Store

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)
        if (!store.isLoggedIn) { toPair(); return }
        b = ActivitySetupBinding.inflate(layoutInflater)
        setContentView(b.root)
        b.cont.setOnClickListener {
            if (Setup.ready(this)) {
                startActivity(Intent(this, HomeActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP))
                finish()
            }
        }
    }

    override fun onResume() {
        super.onResume()
        if (!::store.isInitialized) return
        store.perms = Setup.flags(this)
        render()
    }

    private fun allFlags() = Setup.steps().fold(0) { f, s -> f or s.key }

    private fun render() {
        b.steps.removeAllViews()
        for (step in Setup.steps()) {
            val done = step.done(this)
            val v = layoutInflater.inflate(R.layout.item_setup, b.steps, false)
            v.findViewById<TextView>(R.id.stIcon).text = step.icon
            v.findViewById<TextView>(R.id.stTitle).text = step.title
            v.findViewById<TextView>(R.id.stDesc).text = step.desc
            v.findViewById<TextView>(R.id.stReq).apply {
                visibility = View.VISIBLE
                if (step.required) { text = "נדרש"; setTextColor(getColor(R.color.danger)) }
                else { text = "מומלץ"; setTextColor(getColor(R.color.dim)) }
            }
            val btn = v.findViewById<MaterialButton>(R.id.stBtn)
            val ok = v.findViewById<TextView>(R.id.stOk)
            btn.visibility = if (done) View.GONE else View.VISIBLE
            ok.visibility = if (done) View.VISIBLE else View.GONE
            btn.setOnClickListener { try { step.open(this) } catch (e: Exception) { } }
            b.steps.addView(v)
        }
        val pending = Setup.pendingRequired(this)
        b.cont.isEnabled = pending.isEmpty()
        b.cont.alpha = if (pending.isEmpty()) 1f else 0.5f
        b.contHint.text = when {
            pending.isNotEmpty() -> "להמשך צריך עוד: " + pending.joinToString("، ") { it.title }
            Setup.flags(this) != allFlags() -> "אפשר להמשיך. את המומלצים אפשר להשלים גם אחר כך."
            else -> "הכול מוגדר 👍"
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        render()
    }

    private fun toPair() {
        startActivity(Intent(this, PairActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        finish()
    }
}
