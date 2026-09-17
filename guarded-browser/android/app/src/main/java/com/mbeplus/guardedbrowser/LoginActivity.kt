package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.guardedbrowser.databinding.ActivityLoginBinding

/** מסך הכניסה, וגם ההרשמה — שני טפסים באותו מסך, בהחלפה. */
class LoginActivity : AppCompatActivity() {

    private lateinit var b: ActivityLoginBinding
    private lateinit var store: Store
    private var registerMode = false

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)

        // אסימון קיים — ישר פנימה. הבדיקה מול השרת נעשית ב-HomeActivity.
        if (store.isLoggedIn) { goHome(); return }

        b = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.submit.setOnClickListener { submit() }
        b.toggle.setOnClickListener { setMode(!registerMode) }
        setMode(false)
    }

    private fun setMode(register: Boolean) {
        registerMode = register
        b.title.text = if (register) "הרשמה" else "כניסה"
        b.submit.text = if (register) "שליחת בקשה" else "כניסה"
        b.toggle.text = if (register) "כבר יש לי חשבון" else "אין לי חשבון — הרשמה"
        b.nameBox.visibility = if (register) View.VISIBLE else View.GONE
        b.emailBox.visibility = if (register) View.VISIBLE else View.GONE
        b.message.text = if (register)
            "החשבון ייפתח רק לאחר שהמנהל יאשר אותו." else ""
    }

    private fun busy(on: Boolean) {
        b.submit.isEnabled = !on
        b.progress.visibility = if (on) View.VISIBLE else View.GONE
    }

    private fun submit() {
        val user = b.username.text.toString().trim()
        val pass = b.password.text.toString()

        if (user.isEmpty() || pass.isEmpty()) {
            b.message.text = "יש למלא שם משתמש וסיסמה"
            return
        }
        busy(true)
        b.message.text = ""

        if (registerMode) {
            Api.register(user, pass, b.name.text.toString().trim(),
                         b.email.text.toString().trim()) { r ->
                busy(false)
                if (r.ok) {
                    setMode(false)
                    b.message.text = r.json?.optString("message")
                        ?: "ההרשמה נקלטה. ממתין לאישור המנהל."
                } else b.message.text = r.error
            }
        } else {
            Api.login(user, pass, deviceName(), deviceId()) { r ->
                busy(false)
                val token = r.json?.optString("token") ?: ""
                if (r.ok && token.isNotEmpty()) {
                    store.token = token
                    store.savePolicy(r.json!!)
                    goHome()
                } else b.message.text = r.error
            }
        }
    }

    private fun deviceName() = "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    /**
     * מזהה המכשיר משמש להצגה ולזיהוי חוזר בלבד, לא לאימות — האימות
     * הוא באסימון. ‏ANDROID_ID מתאפס בהתקנה מחדש, וזה בסדר גמור כאן.
     */
    private fun deviceId(): String = try {
        Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID) ?: ""
    } catch (e: Exception) { "" }

    private fun goHome() {
        startActivity(Intent(this, HomeActivity::class.java))
        finish()
    }
}
