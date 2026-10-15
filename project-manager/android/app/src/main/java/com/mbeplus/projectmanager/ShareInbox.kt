package com.mbeplus.projectmanager

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.OpenableColumns
import androidx.core.content.pm.ShortcutManagerCompat
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * מה ששותף לאפליקציה ("שיתוף" ← "פרויקטים", או קיצור של תיקייה אחרונה) — מועתק לתיקייה פרטית
 * (cache/share) עד שהאתר מושך אותו: share.js קורא את הרשימה (PMNative.sharedItems), מביא כל קובץ
 * מ-<האתר>/__native_share/<מספר> (MainActivity מגיש אותו מכאן, בלי רשת), ושומר כמו כל שיתוף.
 * target — "פרויקט:תיקייה" כשהשיתוף הגיע דרך קיצור של תיקייה; אז האתר שומר ישר לשם.
 */
object ShareInbox {
    private fun dir(ctx: Context) = File(ctx.cacheDir, "share").apply { mkdirs() }
    private fun meta(ctx: Context) = File(dir(ctx), "meta.json")

    /** קולט שיתוף; מחזיר true אם היה מה לקלוט. שיתוף חדש מצטרף למה שעוד לא נשמר. */
    fun receive(ctx: Context, intent: Intent): Boolean {
        val uris = mutableListOf<Uri>()
        when (intent.action) {
            Intent.ACTION_SEND -> stream(intent)?.let { uris += it }
            Intent.ACTION_SEND_MULTIPLE -> uris += streams(intent)
            else -> return false
        }
        val text = intent.getStringExtra(Intent.EXTRA_TEXT).orEmpty()
        val subject = intent.getStringExtra(Intent.EXTRA_SUBJECT).orEmpty()
        if (uris.isEmpty() && text.isBlank()) return false

        val m = read(ctx)
        val files = m.optJSONArray("files") ?: JSONArray()
        for (uri in uris) {
            val i = (m.optInt("next", 0)).also { m.put("next", it + 1) }
            val out = File(dir(ctx), "f$i")
            try {
                ctx.contentResolver.openInputStream(uri)?.use { input -> out.outputStream().use { input.copyTo(it) } } ?: continue
            } catch (_: Exception) { continue }
            val (name, size) = nameOf(ctx, uri)
            files.put(JSONObject().put("i", i).put("name", name ?: "file-$i").put("size", if (size > 0) size else out.length())
                .put("type", ctx.contentResolver.getType(uri) ?: intent.type ?: "application/octet-stream"))
        }
        m.put("files", files)
        if (text.isNotBlank()) m.put("text", listOf(m.optString("text"), text).filter { it.isNotBlank() }.joinToString("\n"))
        if (subject.isNotBlank()) m.put("subject", subject)
        // קיצור של תיקייה: "f<תיקייה>_p<פרויקט>" (Shortcuts.kt)
        val sid = intent.getStringExtra(ShortcutManagerCompat.EXTRA_SHORTCUT_ID).orEmpty()
        Regex("^f(\\d+)_p(\\d+)$").find(sid)?.let { m.put("target", "${it.groupValues[2]}:${it.groupValues[1]}") } ?: m.remove("target")
        meta(ctx).writeText(m.toString())
        return true
    }

    fun json(ctx: Context): String = read(ctx).toString()

    fun file(ctx: Context, i: Int): Pair<File, String>? {
        val f = File(dir(ctx), "f$i")
        if (!f.isFile) return null
        val arr = read(ctx).optJSONArray("files") ?: return f to "application/octet-stream"
        for (k in 0 until arr.length()) arr.getJSONObject(k).let { if (it.optInt("i") == i) return f to it.optString("type") }
        return f to "application/octet-stream"
    }

    fun clear(ctx: Context) { dir(ctx).listFiles()?.forEach { it.delete() } }

    private fun read(ctx: Context): JSONObject = try { JSONObject(meta(ctx).readText()) } catch (_: Exception) { JSONObject() }

    @Suppress("DEPRECATION")
    private fun stream(i: Intent): Uri? =
        if (Build.VERSION.SDK_INT >= 33) i.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java) else i.getParcelableExtra(Intent.EXTRA_STREAM)

    @Suppress("DEPRECATION")
    private fun streams(i: Intent): List<Uri> =
        (if (Build.VERSION.SDK_INT >= 33) i.getParcelableArrayListExtra(Intent.EXTRA_STREAM, Uri::class.java) else i.getParcelableArrayListExtra(Intent.EXTRA_STREAM)) ?: emptyList()

    private fun nameOf(ctx: Context, uri: Uri): Pair<String?, Long> = try {
        ctx.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) to (if (c.isNull(1)) -1L else c.getLong(1)) else null to -1L
        } ?: (uri.lastPathSegment to -1L)
    } catch (_: Exception) { uri.lastPathSegment to -1L }
}
