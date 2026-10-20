package com.mbeplus.guardedbrowser

import android.os.Bundle
import android.view.Gravity
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton

/**
 * מסך החסימה של אפליקציה (שלב 11). מוצג מעל אפליקציה שההורה חסם, ומסביר למה.
 * אין כאן דרך לעקוף — רק לחזור הביתה.
 */
class BlockedActivity : AppCompatActivity() {
    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        val pad = (28 * resources.displayMetrics.density).toInt()
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(pad, pad, pad, pad)
            setBackgroundColor(getColor(R.color.bg))
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            addView(ImageView(this@BlockedActivity).apply {
                setImageResource(R.drawable.logo)
                layoutParams = LinearLayout.LayoutParams(dp(72), dp(72))
            })
            val removal = intent?.getBooleanExtra("removal", false) == true
            addView(TextView(this@BlockedActivity).apply {
                text = if (removal) "אי אפשר להסיר את מגדלור" else "האפליקציה הזו חסומה"
                textSize = 22f; setTextColor(getColor(R.color.ink))
                gravity = Gravity.CENTER; setPadding(0, dp(16), 0, dp(6))
            })
            addView(TextView(this@BlockedActivity).apply {
                text = if (removal) "הסרת מגדלור וביטול ניהול המכשיר אפשריים רק בתיאום עם ההורה."
                       else "ההורה חסם את האפליקציה הזו במגדלור. אפשר לבקש ממנו לפתוח אותה."
                textSize = 15f; setTextColor(getColor(R.color.dim)); gravity = Gravity.CENTER
            })
            addView(MaterialButton(this@BlockedActivity).apply {
                text = "חזרה למסך הבית"
                setOnClickListener { goHome() }
                layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                    .apply { topMargin = dp(24) }
            })
        }
        setContentView(box)
    }

    private fun goHome() {
        startActivity(android.content.Intent(android.content.Intent.ACTION_MAIN)
            .addCategory(android.content.Intent.CATEGORY_HOME)
            .addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK))
        finish()
    }

    override fun onBackPressed() { goHome() }
    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
