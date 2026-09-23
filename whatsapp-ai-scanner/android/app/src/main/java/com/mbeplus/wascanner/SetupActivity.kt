package com.mbeplus.wascanner

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.Settings
import android.widget.AdapterView
import android.widget.ArrayAdapter
import androidx.appcompat.app.AppCompatActivity
import com.mbeplus.wascanner.databinding.ActivitySetupBinding
import java.io.File

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
        b.scanFilesBtn.setOnClickListener { harvestFiles() }

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

    /* ── סריקת קבצי מדיה (PDF/תמונות) ───────────────────────────── */

    private fun harvestFiles() {
        if (!store.configured) { b.scanStatus.text = "בחר חשבון פעיל קודם"; return }

        // דורש "גישה לכל הקבצים" — תיקיות המדיה של וואטסאפ מחוץ לאחסון
        // הפרטי של האפליקציה.
        if (Build.VERSION.SDK_INT >= 30 && !Environment.isExternalStorageManager()) {
            b.scanStatus.text = "אשר 'גישה לכל הקבצים' ואז לחץ שוב"
            try {
                startActivity(Intent(
                    Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION,
                    Uri.parse("package:$packageName")))
            } catch (e: Exception) {
                startActivity(Intent(Settings.ACTION_MANAGE_ALL_FILES_ACCESS_PERMISSION))
            }
            return
        }

        b.scanFilesBtn.isEnabled = false
        b.scanStatus.text = "מחפש קבצים…"
        val account = store.accountId
        Thread {
            val api = Api(store.serverBase, store.pairToken)
            val files = collectWhatsappFiles()
            var processed = 0; var skipped = 0; var failed = 0; var i = 0
            for (f in files) {
                i++
                val key = f.absolutePath + ":" + f.length()
                if (store.isMediaSeen(key)) { skipped++; continue }
                val bytes = try { f.readBytes() } catch (e: Exception) { failed++; continue }
                if (bytes.size > 16 * 1024 * 1024) { failed++; continue }
                val r = api.uploadMedia(account, f.name, mimeOf(f.name), bytes, f.lastModified() / 1000)
                if (r.httpCode == 200) { processed++; store.markMediaSeen(key) } else failed++
                val pi = i; val total = files.size
                runOnUiThread { b.scanStatus.text = "קבצים: $pi/$total · עובדו $processed · דולגו $skipped" }
            }
            runOnUiThread {
                b.scanFilesBtn.isEnabled = true
                b.scanStatus.text = "סריקת קבצים הושלמה: עובדו $processed · דולגו $skipped · נכשלו $failed"
            }
        }.start()
    }

    /** אוסף קבצי PDF/תמונות מתיקיות המדיה הידועות של וואטסאפ. */
    private fun collectWhatsappFiles(): List<File> {
        val root = Environment.getExternalStorageDirectory()
        val dirs = listOf(
            "Android/media/com.whatsapp/WhatsApp/Media/WhatsApp Documents",
            "Android/media/com.whatsapp/WhatsApp/Media/WhatsApp Images",
            "Android/media/com.whatsapp.w4b/WhatsApp Business/Media/WhatsApp Business Documents",
            "Android/media/com.whatsapp.w4b/WhatsApp Business/Media/WhatsApp Business Images",
            "WhatsApp/Media/WhatsApp Documents",
            "WhatsApp/Media/WhatsApp Images"
        )
        val exts = setOf("pdf", "jpg", "jpeg", "png", "webp")
        val out = ArrayList<File>()
        for (d in dirs) {
            val dir = File(root, d)
            if (!dir.isDirectory) continue
            dir.walkTopDown().filter { it.isFile && it.extension.lowercase() in exts }.forEach { out.add(it) }
        }
        return out
    }

    private fun mimeOf(name: String): String = when (name.substringAfterLast('.', "").lowercase()) {
        "pdf" -> "application/pdf"
        "png" -> "image/png"
        "webp" -> "image/webp"
        "jpg", "jpeg" -> "image/jpeg"
        else -> "application/octet-stream"
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
