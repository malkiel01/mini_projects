package com.mbeplus.wascanner

import android.content.Intent
import android.os.Bundle
import android.provider.Settings
import android.widget.AdapterView
import android.widget.ArrayAdapter
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.wascanner.databinding.ActivitySetupBinding

/**
 * מסך ההתחברות של הגשר: כתובת שרת, אסימון צימוד, ובחירת **החשבון הפעיל**
 * מרשימה שנטענת מהשרת.
 *
 * החשבון הפעיל הוא הליבה של ההפרדה בין אפליקציות וואטסאפ: המשתמש בוחר
 * לאיזה חשבון הסריקה נכנסת, פותח את אפליקציית הוואטסאפ המתאימה, וסורק.
 * כך אין ערבוב — גם כששני עותקים (שיבוט/תיקייה מאובטחת) חולקים את אותה
 * חבילה ואי אפשר להבדיל ביניהם אוטומטית.
 */
class SetupActivity : AppCompatActivity() {

    private lateinit var b: ActivitySetupBinding
    private lateinit var store: Store

    private var accounts: List<Api.Account> = emptyList()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivitySetupBinding.inflate(layoutInflater)
        setContentView(b.root)
        store = Store(this)

        b.server.setText(store.serverBase)

        b.saveBtn.setOnClickListener {
            store.serverBase = b.server.text.toString()
            store.pairToken = b.token.text.toString()
            loadAccounts()
            refreshStatus()
        }

        b.refreshAccountsBtn.setOnClickListener { loadAccounts() }

        b.accountSpinner.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(p: AdapterView<*>?, v: android.view.View?, pos: Int, id: Long) {
                accounts.getOrNull(pos)?.let {
                    store.accountId = it.id
                    b.scanStatus.text = "חשבון פעיל לסריקה: ${it.label} (#${it.id})"
                }
            }
            override fun onNothingSelected(p: AdapterView<*>?) {}
        }

        b.openAccessibilityBtn.setOnClickListener {
            startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        }
        b.testBtn.setOnClickListener { testConnection() }
        b.autoScanBtn.setOnClickListener { startAutoScan() }

        if (store.pairToken.isNotEmpty()) loadAccounts()
    }

    /** טוען את רשימת החשבונות מהשרת וממלא את הבורר. */
    private fun loadAccounts() {
        if (store.serverBase.isEmpty() || store.pairToken.isEmpty()) {
            b.scanStatus.text = "הזן כתובת שרת ואסימון, ולחץ שמור"
            return
        }
        b.refreshAccountsBtn.isEnabled = false
        Thread {
            val list = Api(store.serverBase, store.pairToken).accounts()
            runOnUiThread {
                b.refreshAccountsBtn.isEnabled = true
                accounts = list
                if (list.isEmpty()) {
                    b.accountSpinner.adapter = ArrayAdapter(
                        this, android.R.layout.simple_spinner_dropdown_item,
                        listOf("אין חשבונות — צור באתר או בדוק אסימון"))
                    return@runOnUiThread
                }
                val labels = list.map { "${it.label} (#${it.id})" }
                b.accountSpinner.adapter = ArrayAdapter(
                    this, android.R.layout.simple_spinner_dropdown_item, labels)
                // בוחר מחדש את החשבון ששמור, אם הוא עדיין קיים.
                val idx = list.indexOfFirst { it.id == store.accountId }
                if (idx >= 0) b.accountSpinner.setSelection(idx)
            }
        }.start()
    }

    /** מדליק מצב סריקה אוטומטית ופותח את וואטסאפ. */
    private fun startAutoScan() {
        if (!store.configured) {
            b.scanStatus.text = "בחר חשבון פעיל קודם (שמור אסימון וטען חשבונות)"
            return
        }
        if (store.autoScan) {
            store.autoScan = false
            b.scanStatus.text = "מצב סריקה אוטומטית כובה"
            return
        }
        store.autoScan = true
        b.scanStatus.text = "מצב סריקה דלוק — פתח את אפליקציית הוואטסאפ הרצויה והיכנס לצ'אט. הגלילה תתחיל לבד."
        // פותח את הוואטסאפ הרגיל כנוחות; לשיבוט/עסקי — פתח ידנית את
        // האפליקציה הנכונה (החשבון הפעיל כבר נבחר).
        packageManager.getLaunchIntentForPackage("com.whatsapp")?.let { startActivity(it) }
    }

    override fun onResume() {
        super.onResume()
        refreshStatus()
        b.scanStatus.text = "אבחון סריקה: " + ScannerService.lastStatus
    }

    private fun testConnection() {
        if (!store.configured) {
            b.scanStatus.text = "בחר חשבון פעיל קודם"
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
            add(if (store.configured) "מוגדר ✓" else "בחר חשבון פעיל")
            add(if (accessibilityOn()) "שירות הנגישות פעיל ✓" else "שירות הנגישות כבוי")
        }
        b.status.text = parts.joinToString("\n")
    }

    private fun accessibilityOn(): Boolean {
        val enabled = Settings.Secure.getString(
            contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
        ) ?: return false
        val short = "$packageName/.ScannerService"
        val full  = "$packageName/${ScannerService::class.java.name}"
        return enabled.split(':').any { it.equals(short, true) || it.equals(full, true) }
    }
}
