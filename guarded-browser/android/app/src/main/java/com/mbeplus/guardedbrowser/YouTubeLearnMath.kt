package com.mbeplus.guardedbrowser

/** מצב למידה ביוטיוב — החלק הטהור (YouTubeLearnMathTest). */
object YouTubeLearnMath {

    /** כמה זמן אחרי סוף סרטון ניווט לסרטון אחר נחשב "ניגון אוטומטי". */
    const val AUTO_ADVANCE_MS = 20_000L

    /**
     * יוטיוב עובר לבד לסרטון הבא — גם כשהוא בתור, זה "ניגון אוטומטי", ובמצב למידה הילד בוחר את הבא במסך
     * הבית. ניווט לסרטון אחר זמן קצר אחרי שהקודם הסתיים — נעצר. ‏sameVideo — אותו סרטון (צפייה חוזרת) מותר.
     */
    fun blocksAutoAdvance(endedVideo: String, endedAtMs: Long, nextVideo: String, nowMs: Long): Boolean =
        endedVideo.isNotEmpty() && nextVideo.isNotEmpty() && nextVideo != endedVideo &&
            nowMs - endedAtMs in 0..AUTO_ADVANCE_MS

    /** שולחים דוח אימות רק כשהשתנה, או פעם ביום. */
    fun shouldReport(last: String, lastAtMs: Long, now: String, nowMs: Long): Boolean =
        now != last || nowMs - lastAtMs > 24 * 3600_000L
}
