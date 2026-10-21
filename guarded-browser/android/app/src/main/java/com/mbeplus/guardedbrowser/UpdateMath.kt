package com.mbeplus.guardedbrowser

/**
 * כללי העדכון — טהורים, בלי Android, כדי שאפשר לבדוק אותם (UpdateMathTest). סקירה, 2026-10-20 — שלב 9.
 *
 *   ‏installOpen — כמה זמן ההגבלות "מקור לא ידוע" ו"התקנת אפליקציות" מוסרות להתקנת עדכון. קודם הן חזרו
 *   רק בכישלון שדווח (InstallResult): חריגה ביצירת הסשן, תהליך שנהרג או אישור שלא הגיע — השאירו אותן
 *   פתוחות עד ההחלה הבאה של המדיניות, ומי שהחזיק את הטלפון יכול היה להתקין בינתיים כל APK.
 *
 *   ‏checkArchive — לפני שההגבלות מוסרות: החבילה שלנו, גרסה חדשה מהמותקנת ולא ישנה מזו שהוצעה, חתימה
 *   מאותה שושלת, ו-sha256 כמו שהשרת פרסם. קודם נבדק רק שהקובץ מתחיל ב-PK וגדול ממגה.
 */
object UpdateMath {

    /** חלון ההתקנה — הורדה כבר הסתיימה; ההתקנה עצמה לוקחת שניות, אישור ידני — דקות. */
    const val OPEN_MS = 15 * 60_000L

    /** החלון פתוח עכשיו. חלון "ארוך" מהמותר (שעון שזז אחורה) — נחשב סגור. */
    fun installOpen(openUntil: Long, now: Long): Boolean = openUntil > now && openUntil - now <= OPEN_MS

    /** צריך להחזיר את ההגבלות: החלון נפתח, ונסגר בזמן או שהעדכון כבר לא נדרש (הגרסה החדשה רצה). */
    fun shouldRestore(openUntil: Long, now: Long, stillRequired: Boolean): Boolean =
        openUntil != 0L && (!installOpen(openUntil, now) || !stillRequired)

    /**
     * ‏"" — הקובץ הוא העדכון שהוצע; אחרת — הסיבה (לדיווח להורה).
     *
     * @param certs     טביעות SHA-256 של חותמי הקובץ, כולל השושלת (סבב מפתחות)
     * @param myCerts   טביעות החותמים של האפליקציה המותקנת (ריק — לא נקרא; ראו למטה)
     * @param expectSha sha256 מהשרת; ריק — שרת ישן שלא שולח (אז רק שאר הבדיקות)
     */
    fun checkArchive(pkg: String?, myPkg: String, code: Long, offeredCode: Int, installedCode: Int,
                     certs: Set<String>, myCerts: Set<String>, sha: String, expectSha: String): String = when {
        pkg == null -> "הקובץ אינו APK תקין"
        pkg != myPkg -> "חבילה אחרת ($pkg)"
        code <= installedCode -> "הגרסה בקובץ ($code) אינה חדשה מהמותקנת"
        code < offeredCode -> "הגרסה בקובץ ($code) ישנה מזו שהוצעה ($offeredCode)"
        // חתימה שלא הצלחנו לקרוא (getPackageArchiveInfo בחלק מהגרסאות) — נשארת לאנדרואיד, שמסרב בעצמו לעדכן
        // חבילה בחתימה אחרת. נכשל רק כשקראנו את שתיהן והן שונות: לחסום את עדכון עצמנו זה הנזק הגדול יותר.
        certs.isNotEmpty() && myCerts.isNotEmpty() && certs.none { it in myCerts } -> "החתימה אינה של מגדלור"
        expectSha.isNotEmpty() && !expectSha.equals(sha, ignoreCase = true) -> "הקובץ שונה ממה שפורסם (sha256)"
        else -> ""
    }
}
