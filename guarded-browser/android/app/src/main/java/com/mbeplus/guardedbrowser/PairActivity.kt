package com.mbeplus.guardedbrowser

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.View
import android.view.inputmethod.EditorInfo
import androidx.appcompat.app.AppCompatActivity
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import com.mbeplus.guardedbrowser.databinding.ActivityPairBinding

/**
 * המסך הראשון: חיבור המכשיר למשפחה (SPEC.md, סעיף 9).
 *
 * ההורה מציג QR באפליקציה שלו; כאן סורקים אותו — או מקלידים את הקוד.
 * אין סיסמה במכשיר של הילד. כניסה בשם משתמש וסיסמה נשארה כגיבוי.
 */
class PairActivity : AppCompatActivity() {

    private lateinit var b: ActivityPairBinding
    private lateinit var store: Store

    private val scan = registerForActivityResult(ScanContract()) { r ->
        val text = r?.contents ?: return@registerForActivityResult
        val link = PairLink.parse(text)
        if (link == null) { say("זה לא קוד של מגדלור. סרקו את הקוד שבאפליקציית ההורה.", true); return@registerForActivityResult }
        pair(link)
    }

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        store = Store(this)

        // כבר מחובר — ישר פנימה. הבדיקה מול השרת נעשית במסך הבית.
        if (store.isLoggedIn) { goHome(); return }

        b = ActivityPairBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.scan.setOnClickListener {
            scan.launch(ScanOptions()
                .setDesiredBarcodeFormats(ScanOptions.QR_CODE)
                .setPrompt("כוונו את המצלמה לקוד שבאפליקציית ההורה")
                .setBeepEnabled(false)
                .setOrientationLocked(false))
        }
        b.connect.setOnClickListener { typed() }
        b.code.setOnEditorActionListener { _, id, _ ->
            if (id == EditorInfo.IME_ACTION_GO) { typed(); true } else false
        }
        b.legacy.setOnClickListener {
            startActivity(Intent(this, LoginActivity::class.java))
        }
    }

    private fun typed() {
        val link = PairLink.parse(b.code.text.toString())
        if (link == null) { say("הקוד הוא 8 אותיות וספרות, כמו שמופיע אצל ההורה.", true); return }
        pair(link)
    }

    private fun pair(link: PairLink) {
        if (link.server.isNotEmpty()) Api.useServer(link.server)
        busy(true)
        say("מתחבר…", false)
        Api.pair(link.code, deviceName(), deviceId()) { r ->
            busy(false)
            val token = r.json?.optString("token") ?: ""
            if (r.ok && token.isNotEmpty()) {
                store.token = token
                store.savePolicy(r.json!!)
                goHome()
            } else say(r.error, true)
        }
    }

    private fun busy(on: Boolean) {
        b.scan.isEnabled = !on
        b.connect.isEnabled = !on
        b.progress.visibility = if (on) View.VISIBLE else View.GONE
    }

    private fun say(text: String, bad: Boolean) {
        b.message.text = text
        b.message.visibility = if (text.isEmpty()) View.GONE else View.VISIBLE
        b.message.setTextColor(getColor(if (bad) R.color.danger else R.color.dim))
    }

    private fun deviceName() = "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    private fun deviceId(): String = try {
        Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID) ?: ""
    } catch (e: Exception) { "" }

    private fun goHome() {
        startActivity(Intent(this, HomeActivity::class.java))
        finish()
    }
}
