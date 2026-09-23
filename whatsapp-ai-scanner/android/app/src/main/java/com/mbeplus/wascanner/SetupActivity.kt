package com.mbeplus.wascanner

import android.content.Intent
import android.os.Bundle
import android.provider.Settings
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.wascanner.databinding.ActivitySetupBinding

/**
 * מסך ההתחברות של הגשר: כתובת שרת, אסימון צימוד, ומזהה החשבון שאליו
 * לשייך את ההודעות שייסרקו. משם — כפתור שפותח את הגדרות הנגישות של
 * המערכת, שם המשתמש מפעיל את השירות ידנית (אנדרואיד לא מאפשר לאפליקציה
 * להפעיל שירות נגישות לבד — בכוונה).
 */
class SetupActivity : AppCompatActivity() {

    private lateinit var b: ActivitySetupBinding
    private lateinit var store: Store

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivitySetupBinding.inflate(layoutInflater)
        setContentView(b.root)
        store = Store(this)

        b.server.setText(store.serverBase)
        if (store.accountId > 0) b.accountId.setText(store.accountId.toString())

        b.saveBtn.setOnClickListener {
            store.serverBase = b.server.text.toString()
            store.pairToken = b.token.text.toString()
            store.accountId = b.accountId.text.toString().toIntOrNull() ?: 0
            refreshStatus()
        }

        b.openAccessibilityBtn.setOnClickListener {
            startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        }
    }

    override fun onResume() {
        super.onResume()
        refreshStatus()
    }

    private fun refreshStatus() {
        val parts = buildList {
            add(if (store.configured) "מוגדר ✓" else "חסרים אסימון או מזהה חשבון")
            add(if (accessibilityOn()) "שירות הנגישות פעיל ✓" else "שירות הנגישות כבוי")
        }
        b.status.text = parts.joinToString("\n")
    }

    private fun accessibilityOn(): Boolean {
        val enabled = Settings.Secure.getString(
            contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
        ) ?: return false
        return enabled.contains("${packageName}/.ScannerService")
    }
}
