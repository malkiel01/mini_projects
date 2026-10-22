package com.mbeplus.guardedbrowser

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri

/**
 * התקנה מהמרקט, שלב 2ב (בעל המערכת, 2026-10-22): הילד בוחר אפליקציה ששוחררה לו ← Google Play נפתחת בדף שלה
 * לכל היותר MarketMath.WINDOW_MS, גם כשההורה חסם את Play. ב"בעל מכשיר" — גם "התקנת אפליקציות" נפתחת לחלון.
 *
 *   ההגנות: החלון נסגר ברגע שהאפליקציה הותקנה, או כשהזמן עבר (tick — גם בלי שירות, בכל החלה). אפליקציה *אחרת*
 *   שהותקנה בחלון — "זרה": חסומה (AppPolicy) עד שההורה מאשר, ומדווחת לו מיד. עדכונים שבאו מ-Play לא נחשבים.
 *   ההתקנה הרובוטית (בלי Play גלוי לילד) — שלב 3.
 */
object MarketInstall {

    fun start(ctx: Context, pkg: String) {
        val store = Store(ctx)
        store.marketTarget = pkg
        store.marketUntil = System.currentTimeMillis() + MarketMath.WINDOW_MS
        Trace.log("market.start", pkg)
        // לפני הפתיחה: Play יוצאת מההשעיה, וההתקנה נפתחת (Enforcer.apply קורא את החלון).
        try { if (!store.released) Enforcer.apply(ctx, store) } catch (e: Exception) { Trace.log("market.apply", e.message ?: "") }
        val flags = Intent.FLAG_ACTIVITY_NEW_TASK
        try {
            ctx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=$pkg")).setPackage(MarketMath.PLAY).addFlags(flags))
        } catch (e: ActivityNotFoundException) {
            Trace.log("market.no_play", pkg)
            close(ctx, store)
            throw e
        }
    }

    /** חבילה חדשה הותקנה (AppGuardService). ‏replacing — עדכון של קיימת: לא נוגעים. */
    fun onInstalled(ctx: Context, pkg: String, replacing: Boolean) {
        if (replacing) return
        val store = Store(ctx)
        when (MarketMath.onInstalled(pkg, store.marketTarget, store.marketUntil, System.currentTimeMillis())) {
            "done" -> {
                Trace.log("market.done", pkg)
                close(ctx, store)
                AppsReport.send(ctx)
                try {
                    ctx.startActivity(Intent(ctx, HomeActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP))
                } catch (e: Exception) { Trace.log("market.home", e.message ?: "") }
            }
            "stray" -> {
                Trace.log("market.stray", pkg)
                store.marketStrays = store.marketStrays + pkg
                try { if (!store.released) Enforcer.apply(ctx, store) } catch (e: Exception) { Trace.log("market.apply", e.message ?: "") }
                AppsReport.send(ctx)
            }
        }
    }

    /**
     * בכל טיק של השירות ובכל פתיחה של מגדלור: היעד כבר מותקן (השידור הוחמץ) — סוגרים; הזמן עבר — סוגרים.
     * ‏true — החלון נסגר עכשיו.
     */
    fun tick(ctx: Context): Boolean {
        val store = Store(ctx)
        val target = store.marketTarget
        if (target.isEmpty()) return false
        val installed = try { ctx.packageManager.getPackageInfo(target, 0); true } catch (e: Exception) { false }
        if (!installed && MarketMath.open(target, store.marketUntil, System.currentTimeMillis())) return false
        Trace.log(if (installed) "market.done" else "market.expired", target)
        close(ctx, store)
        return true
    }

    private fun close(ctx: Context, store: Store) {
        store.marketTarget = ""
        store.marketUntil = 0L
        // Play חוזרת להיות חסומה (אם ההורה חסם), וההתקנה — לבחירת ההורה.
        try { if (!store.released) Enforcer.apply(ctx, store) } catch (e: Exception) { Trace.log("market.apply", e.message ?: "") }
    }
}
