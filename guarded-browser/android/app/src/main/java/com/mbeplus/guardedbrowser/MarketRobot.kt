package com.mbeplus.guardedbrowser

import android.accessibilityservice.AccessibilityService
import android.graphics.Color
import android.graphics.PixelFormat
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.view.accessibility.AccessibilityNodeInfo
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast

/**
 * ההתקנה הרובוטית, שלב 3 (בעל המערכת, 2026-10-23): Google Play נפתחת מאחורי מסך מכסה של מגדלור, ומגדלור לוחץ
 * בעצמו "התקנה". הילד רואה רק "מתקין את X…" — אין לו גישה לחנות עצמה, כי המסך המכסה תופס את כל הנגיעות.
 *
 *   הלחיצה היא של שירות הנגישות (ACTION_CLICK) — לא נגיעה, ולכן המסך המכסה לא מפריע לה.
 *   מה נקרא מהמסך: רק בזמן התקנה, רק כש-Play בחזית, ורק חיפוש של המערכת אחרי טקסט הכפתורים
 *   (findAccessibilityNodeInfosByText) — שום טקסט לא נשמר ולא נשלח, כמו windowMentionsSelf.
 *   לא מצליח (אין חשבון, אפליקציה בתשלום, הכפתור לא נמצא, ההורדה נתקעה) — המסך המכסה יורד, ו-Play נשארת
 *   גלויה לשארית החלון של שלב 2, עם כל ההגנות שלו (MarketInstall).
 */
class MarketRobot(private val svc: AccessibilityService) {

    private val store = Store(svc)
    private val timer = android.os.Handler(android.os.Looper.getMainLooper())
    private var overlay: View? = null
    private var label: TextView? = null
    private val poll = object : Runnable {
        override fun run() { step(); if (overlay != null) timer.postDelayed(this, POLL_MS) }
    }

    /** מתחיל: המסך המכסה עולה, והבדיקה רצה כל POLL_MS. ‏false — לא הצלחנו להציג אותו (אז Play גלויה). */
    fun start(name: String): Boolean {
        if (!show(name)) return false
        store.marketRobotAt = System.currentTimeMillis()
        store.marketClickedAt = 0L
        timer.removeCallbacks(poll)
        timer.postDelayed(poll, POLL_MS)
        return true
    }

    fun stop() {
        timer.removeCallbacks(poll)
        store.marketRobotAt = 0L
        hide()
    }

    private fun step() {
        // החלון נסגר (הותקנה, פג, בוטל) — המסך המכסה יורד.
        if (!MarketMath.open(store.marketTarget, store.marketUntil, System.currentTimeMillis()) || store.marketRobotAt == 0L) {
            stop(); return
        }
        val root = try { svc.rootInActiveWindow } catch (e: Exception) { null }
        val inPlay = root?.packageName?.toString() == MarketMath.PLAY
        val button = if (inPlay) installButton(root!!) else null
        val blocker = inPlay && store.marketClickedAt == 0L && hasBlocker(root!!)
        when (MarketMath.robotStep(store.marketRobotAt, store.marketClickedAt, System.currentTimeMillis(), button != null, blocker)) {
            "click" -> {
                if (button!!.performAction(AccessibilityNodeInfo.ACTION_CLICK)) {
                    store.marketClickedAt = System.currentTimeMillis()
                    Trace.log("market.robot_click", store.marketTarget)
                    label?.text = "מוריד ומתקין…"
                }
            }
            "fallback" -> fallback(if (blocker) "צריך להשלים ב-Play (חשבון Google או תשלום)" else "ההתקנה האוטומטית לא הצליחה — אפשר להמשיך ב-Play")
        }
    }

    /** כפתור "התקנה" — התאמה מלאה, והוא (או מי שמעליו) לחיץ ופעיל. */
    private fun installButton(root: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        for (needle in listOf("התקנה", "התקן", "Install")) {
            val nodes = try { root.findAccessibilityNodeInfosByText(needle) } catch (e: Exception) { emptyList() }
            for (n in nodes) {
                val text = (n.text ?: n.contentDescription ?: "").toString()
                if (!MarketMath.isInstall(text)) continue
                clickable(n)?.let { return it }
            }
        }
        return null
    }

    /** כפתור לחיץ של כניסה לחשבון או של תשלום — את אלה הרובוט לא עובר לבד. */
    private fun hasBlocker(root: AccessibilityNodeInfo): Boolean =
        listOf("₪", "$", "Sign in", "כניסה", "היכנס", "קנייה", "Buy").any { needle ->
            val nodes = try { root.findAccessibilityNodeInfosByText(needle) } catch (e: Exception) { emptyList() }
            nodes.any { MarketMath.isBlocker((it.text ?: it.contentDescription ?: "").toString()) && clickable(it) != null }
        }

    private fun clickable(n: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        var cur: AccessibilityNodeInfo? = n
        var depth = 0
        while (cur != null && depth < 4) {
            if (cur.isClickable && cur.isEnabled && cur.isVisibleToUser) return cur
            cur = cur.parent; depth++
        }
        return null
    }

    private fun fallback(why: String) {
        Trace.log("market.robot_fallback", store.marketTarget)
        stop()
        // Play נשארת גלויה — לשארית החלון, ולא פחות מ-2 דקות מעכשיו.
        store.marketUntil = maxOf(store.marketUntil, System.currentTimeMillis() + 2 * 60_000L)
        Toast.makeText(svc, why, Toast.LENGTH_LONG).show()
    }

    /* ── המסך המכסה: חלון של שירות הנגישות (TYPE_ACCESSIBILITY_OVERLAY) — בלי הרשאה נוספת ── */

    private fun show(name: String): Boolean {
        if (overlay != null) { label?.text = "מתקין את $name…"; return true }
        val wm = svc.getSystemService(android.content.Context.WINDOW_SERVICE) as WindowManager
        val dp = svc.resources.displayMetrics.density
        val box = LinearLayout(svc).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setBackgroundColor(Color.parseColor("#F2101828"))
            setPadding((24 * dp).toInt(), 0, (24 * dp).toInt(), 0)
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        box.addView(TextView(svc).apply { text = "📥"; textSize = 48f; gravity = Gravity.CENTER })
        label = TextView(svc).apply { text = "מתקין את $name…"; textSize = 20f; setTextColor(Color.WHITE); gravity = Gravity.CENTER }
        box.addView(label)
        box.addView(TextView(svc).apply {
            text = "מגדלור מתקין דרך Google Play. זה לוקח דקה או שתיים."
            textSize = 14f; setTextColor(Color.parseColor("#B8C2D6")); gravity = Gravity.CENTER
            setPadding(0, (8 * dp).toInt(), 0, (16 * dp).toInt())
        })
        box.addView(ProgressBar(svc))
        box.addView(Button(svc).apply {
            text = "ביטול"
            setOnClickListener { MarketInstall.cancel(svc); svc.performGlobalAction(AccessibilityService.GLOBAL_ACTION_HOME) }
        }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT)
            .apply { topMargin = (24 * dp).toInt(); gravity = Gravity.CENTER })
        val lp = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.MATCH_PARENT,
            WindowManager.LayoutParams.TYPE_ACCESSIBILITY_OVERLAY,
            // לא לוקח פוקוס — כך Play נשארת החלון הפעיל (rootInActiveWindow), אבל כל הנגיעות נתפסות כאן.
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT)
        return try { wm.addView(box, lp); overlay = box; true }
        catch (e: Exception) { Trace.log("market.robot_overlay", e.message ?: ""); label = null; false }
    }

    private fun hide() {
        val v = overlay ?: return
        overlay = null; label = null
        try { (svc.getSystemService(android.content.Context.WINDOW_SERVICE) as WindowManager).removeView(v) } catch (e: Exception) {}
    }

    companion object {
        const val POLL_MS = 700L
        /** השירות הפעיל (AppGuardService) — null כשהנגישות כבויה, ואז ההתקנה היא דרך Play גלויה (שלב 2). */
        @Volatile var active: MarketRobot? = null
    }
}
