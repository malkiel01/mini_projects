package com.mbeplus.guardedbrowser

/**
 * כללי הסנכרון — טהורים, בלי Android, כדי שאפשר לבדוק אותם (SyncRulesTest).
 */
object SyncRules {

    /**
     * תשובת מדיניות ישנה מזו שכבר הוחלה — נזרקת. ‏issued_at הוא שעון השרת במילישניות; 0 = שרת ישן
     * שלא שולח אותו, ואז מקבלים (כמו קודם).
     */
    fun isStale(lastApplied: Long, incoming: Long): Boolean = incoming in 1 until lastApplied

    enum class Release { ENTER, RENEW, NONE }

    /**
     * מעבר מצב המנוי. נכנסים לשחרור רק באסימון חתום ותקף; יוצאים ממנו רק כשהשרת ענה (תשובה תקינה)
     * ולא שלח אסימון — כלומר החשבון הוארך. תקלת רשת אינה מגיעה לכאן כלל, ולכן אינה שחרור.
     */
    fun release(released: Boolean, tokenPresent: Boolean, tokenValid: Boolean): Release = when {
        tokenPresent && tokenValid && !released -> Release.ENTER
        !tokenPresent && released -> Release.RENEW
        else -> Release.NONE
    }
}
