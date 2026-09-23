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

        b.testBtn.setOnClickListener { testConnection() }
    }

    override fun onResume() {
        super.onResume()
        refreshStatus()
        b.scanStatus.text = "אבחון סריקה: " + ScannerService.lastStatus
    }

    /** בודק אסימון+כתובת+רשת בנפרד מהסריקה, ומראה את התוצאה. */
    private fun testConnection() {
        if (!store.configured) {
            b.scanStatus.text = "קודם הזן אסימון ומזהה חשבון, ולחץ שמור"
            return
        }
        b.testBtn.isEnabled = false
        b.scanStatus.text = "בודק…"
        Thread {
            val r = Api(store.serverBase, store.pairToken).ping(store.accountId)
            runOnUiThread {
                b.testBtn.isEnabled = true
                b.scanStatus.text = when {
                    r.httpCode == 200 && r.error == null -> "חיבור תקין ✓ — השרת מזהה את החשבון"
                    r.httpCode == 200 -> "חובר, אך: ${r.error}"
                    r.httpCode == -1  -> "כשל רשת: ${r.error}"
                    else              -> "השרת דחה (${r.httpCode}): ${r.error}"
                }
            }
        }.start()
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
        // אנדרואיד שומר את הרשומה בפורמט המלא
        // (com.pkg/com.pkg.ScannerService), לא במקוצר (com.pkg/.ScannerService).
        // הבדיקה הקודמת חיפשה רק את המקוצר, ולכן הציגה "כבוי" גם כשהשירות
        // פעיל. משווים כאן את שני הפורמטים, לכל רשומה ברשימה.
        val short = "$packageName/.ScannerService"
        val full  = "$packageName/${ScannerService::class.java.name}"
        return enabled.split(':').any { it.equals(short, true) || it.equals(full, true) }
    }
}
