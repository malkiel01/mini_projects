package com.mbeplus.projectmanager

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Typeface
import androidx.core.content.pm.ShortcutInfoCompat
import androidx.core.content.pm.ShortcutManagerCompat
import androidx.core.graphics.drawable.IconCompat
import org.json.JSONArray

/**
 * התיקיות האחרונות ששיתפת אליהן (מהאתר: GET share-recent) — כקיצורי שיתוף ישיר. הן מופיעות בשורה
 * העליונה של תפריט "שיתוף" בטלפון, כמו הצ'אטים האחרונים של וואטסאפ. האחרונה ראשונה.
 * הסמל — ראשי התיבות של הפרויקט על צבע שנגזר מהשם, כמו האווטאר באתר.
 */
object Shortcuts {
    private const val CATEGORY = "com.mbeplus.projectmanager.category.FOLDER"

    fun update(ctx: Context, json: String) {
        val arr = try { JSONArray(json) } catch (_: Exception) { return }
        val max = ShortcutManagerCompat.getMaxShortcutCountPerActivity(ctx).coerceAtMost(5)
        val list = (0 until minOf(arr.length(), max)).map { k ->
            val r = arr.getJSONObject(k)
            val pid = r.getInt("project_id"); val fid = r.getInt("folder_id")
            val project = r.optString("project"); val path = r.optString("path")
            val folder = path.substringAfterLast(" / ")
            ShortcutInfoCompat.Builder(ctx, "f${fid}_p$pid")
                .setShortLabel("$project · $folder".take(40))
                .setLongLabel("$project · $path".take(80))
                .setIcon(IconCompat.createWithAdaptiveBitmap(avatar(project)))
                .setCategories(setOf(CATEGORY))
                .setLongLived(true)
                .setRank(k)
                // לחיצה על הקיצור ממסך הבית (לחיצה ארוכה על הסמל) — פותחת את התיקייה
                .setIntent(Intent(ctx, MainActivity::class.java).setAction(Intent.ACTION_VIEW)
                    .putExtra(MainActivity.EXTRA_HASH, "#/p/$pid/files/$fid"))
                .build()
        }
        try {
            ShortcutManagerCompat.removeAllDynamicShortcuts(ctx)
            if (list.isNotEmpty()) ShortcutManagerCompat.addDynamicShortcuts(ctx, list)
        } catch (_: Exception) { /* מגבלת קצב של המערכת — בפעם הבאה */ }
    }

    private fun avatar(name: String): Bitmap {
        val size = 216 // 108dp × 2 — התוכן באמצע 66%
        val bmp = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
        val c = Canvas(bmp)
        val hue = name.fold(0) { h, ch -> (h * 31 + ch.code) and 0x7fffffff } % 360
        c.drawColor(Color.HSVToColor(floatArrayOf(hue.toFloat(), 0.45f, 0.62f)))
        val initials = name.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.take(2).joinToString("") { it.take(1) }.ifEmpty { "📁" }
        val p = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.WHITE; textSize = size * 0.26f; textAlign = Paint.Align.CENTER; typeface = Typeface.DEFAULT_BOLD
        }
        c.drawText(initials, size / 2f, size / 2f - (p.descent() + p.ascent()) / 2, p)
        return bmp
    }
}
