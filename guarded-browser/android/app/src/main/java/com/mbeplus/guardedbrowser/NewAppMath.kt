package com.mbeplus.guardedbrowser

/**
 * אפליקציה חדשה שהותקנה (lib/appcontrol.php, new_apps) — טהור, בלי Android (NewAppMathTest).
 *
 *   "חדשה" — יש לה אייקון, היא לא ברשימה שההורה כבר ראה (known, מהשרת), ולא הייתה מותקנת כשמגדלור התחיל
 *   לעקוב (baseline — הסריקה הראשונה). בלי baseline עוד אין ידיעה מה חדש — אף אחת לא נחשבת חדשה.
 */
object NewAppMath {

    fun isNew(pkg: String, known: Set<String>, baseline: Set<String>): Boolean =
        baseline.isNotEmpty() && pkg !in known && pkg !in baseline

    fun fresh(launchable: Set<String>, known: Set<String>, baseline: Set<String>): Set<String> =
        launchable.filter { isNew(it, known, baseline) }.toSet()

    /** לדווח לשרת: יש חדשה שעוד לא דווחה, או שעברו 6 שעות מהדיווח (השרת עוד לא רשם אותה). */
    fun shouldReport(fresh: Set<String>, lastSent: Set<String>, lastAtMs: Long, nowMs: Long): Boolean =
        fresh.isNotEmpty() && (!lastSent.containsAll(fresh) || nowMs - lastAtMs > 6 * 3600_000L || nowMs < lastAtMs)
}
