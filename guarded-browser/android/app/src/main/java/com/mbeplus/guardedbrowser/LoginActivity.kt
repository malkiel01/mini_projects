package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.guardedbrowser.databinding.ActivityLoginBinding

/**
 * כניסה בשם משתמש וסיסמה — גיבוי לצימוד בקוד (מכשיר ישן, או ילד שנוצר
 * לפני שלב 4). אין כאן הרשמה: ילד לא פותח לעצמו חשבון — ההורה מוסיף אותו.
 */
class LoginActivity : AppCompatActivity() {

    private lateinit var b: ActivityLoginBinding
    private lateinit var store: Store

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)
        if (store.isLoggedIn) { goHome(); return }

        b = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.submit.setOnClickListener { submit() }
        b.back.setOnClickListener { finish() }
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

    private fun deviceName() = "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    /**
     * מזהה המכשיר משמש להצגה ולזיהוי חוזר בלבד, לא לאימות — האימות
     * הוא באסימון. ‏ANDROID_ID מתאפס בהתקנה מחדש, וזה בסדר גמור כאן.
     */
    private fun deviceId(): String = try {
        Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID) ?: ""
    } catch (e: Exception) { "" }

    private fun goHome() {
        startActivity(Intent(this, HomeActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        finish()
    }
}
