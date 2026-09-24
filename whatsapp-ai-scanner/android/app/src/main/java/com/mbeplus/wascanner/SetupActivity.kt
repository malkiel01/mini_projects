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

    // תקופות סריקת קבצים: תווית → מספר חודשים אחורה (0 = הכל).
    private val periodMonths = listOf(1, 3, 6, 12, 24, 0)
    private val periodLabels = listOf("חודש אחרון", "3 חודשים", "חצי שנה", "שנה", "שנתיים", "הכל")

    @Volatile private var harvesting = false
    @Volatile private var stopHarvest = false

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
                    store.accountLabel = it.label
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
        b.sweepBtn.setOnClickListener { startFullSweep() }
        b.scanFilesBtn.setOnClickListener { harvestFiles() }
        b.resetFilesBtn.setOnClickListener {
            store.clearMediaSeen()
            b.scanStatus.text = "זיכרון סריקת הקבצים אופס — הסריקה הבאה תסרוק מחדש הכל"
        }

        // בורר תקופת סריקת הקבצים
        b.filePeriodSpinner.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, periodLabels)
        val curIdx = periodMonths.indexOf(store.fileMonths).let { if (it >= 0) it else 2 }
        b.filePeriodSpinner.setSelection(curIdx)
        b.filePeriodSpinner.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(p: AdapterView<*>?, v: android.view.View?, pos: Int, id: Long) {
                store.fileMonths = periodMonths[pos]
            }
            override fun onNothingSelected(p: AdapterView<*>?) {}
        }

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
        if (!ensureOverlay()) return
        store.autoScan = true
        b.scanStatus.text = "מצב סריקה דלוק — פתח את אפליקציית הוואטסאפ הרצויה והיכנס לצ'אט. הגלילה תתחיל לבד. (עצירה: הכפתור המרחף)"
        // פותח את הוואטסאפ הרגיל כנוחות; לשיבוט/עסקי — פתח ידנית את
        // האפליקציה הנכונה (החשבון הפעיל כבר נבחר).
        packageManager.getLaunchIntentForPackage("com.whatsapp")?.let { startActivity(it) }
    }

    /* ── סריקת קבצי מדיה (PDF/תמונות) ───────────────────────────── */

    private fun harvestFiles() {
        // לחיצה בזמן ריצה = עצירה. הזיכרון (media_seen) נשמר, כך שהמשך
        // ידלג על מה שכבר נסרק.
        if (harvesting) {
            stopHarvest = true
            b.scanStatus.text = "עוצר סריקת קבצים…"
            return
        }
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

        harvesting = true; stopHarvest = false
        b.scanFilesBtn.text = "⏹ עצור סריקת קבצים"
        b.scanStatus.text = "מחפש קבצים…"
        val account = store.accountId
        val months = store.fileMonths
        // חלון הזמן: קבצים ששונו מאז cutoff. 0 = הכל.
        val cutoff = if (months > 0) System.currentTimeMillis() - months.toLong() * 30L * 24 * 3600 * 1000 else 0L

        Thread {
            val api = Api(store.serverBase, store.pairToken)
            // מהחדש לישן, כדי שהתקדמות אחורה תהיה טבעית ועצירה שומרת את
            // החדשים שכבר נסרקו.
            val files = collectWhatsappFiles()
                .filter { it.lastModified() >= cutoff }
                .sortedByDescending { it.lastModified() }
            var processed = 0; var skipped = 0; var failed = 0; var i = 0
            var stopped = false
            for (f in files) {
                if (stopHarvest) { stopped = true; break }
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
            harvesting = false; stopHarvest = false
            runOnUiThread {
                b.scanFilesBtn.text = "סרוק קבצים ותמונות (PDF/תמונות)"
                b.scanStatus.text = (if (stopped) "נעצר. " else "הושלם. ") +
                    "עובדו $processed · דולגו $skipped · נכשלו $failed" +
                    (if (stopped) " — לחיצה נוספת תמשיך מהמקום שנעצר." else "")
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

    /** מדליק סריקה מלאה של כל הצ'אטים ופותח את וואטסאפ ברשימת השיחות. */
    private fun startFullSweep() {
        if (!store.configured) { b.scanStatus.text = "בחר חשבון פעיל קודם"; return }
        if (store.fullSweep) {
            store.fullSweep = false
            b.scanStatus.text = "סריקה מלאה בוטלה"
            return
        }
        if (!ensureOverlay()) return
        store.fullSweep = true
        store.autoScan = false   // לא לערבב עם שלב 1
        b.scanStatus.text = "סריקה מלאה דלוקה — פותח את וואטסאפ. שים אותו במסך רשימת הצ'אטים, והוא ינווט לבד. עצירה/השהיה: הכפתור המרחף."
        packageManager.getLaunchIntentForPackage("com.whatsapp")?.let { startActivity(it) }
    }

    /** מוודא הרשאת "הצגה מעל אפליקציות אחרות" — נדרשת לכפתור העצירה
     *  המרחף. בלעדיה אין דרך לעצור סריקה אוטומטית מתוך וואטסאפ. */
    private fun ensureOverlay(): Boolean {
        if (Build.VERSION.SDK_INT >= 23 && !Settings.canDrawOverlays(this)) {
            b.scanStatus.text = "אשר 'הצגה מעל אפליקציות אחרות' כדי שיהיה כפתור עצירה מרחף, ואז לחץ שוב"
            try {
                startActivity(Intent(
                    Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                    Uri.parse("package:$packageName")))
            } catch (e: Exception) {
                startActivity(Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION))
            }
            return false
        }
        return true
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
