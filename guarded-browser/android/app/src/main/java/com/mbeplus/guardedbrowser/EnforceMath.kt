package com.mbeplus.guardedbrowser

/** חישובי האכיפה — טהורים, בלי Android (EnforceMathTest). */
object EnforceMath {

    /**
     * מה מושעה בפועל אחרי ההחלה: מה שצריך להיות מושעה, פחות מה שההשעיה שלו נכשלה (ינוסה שוב
     * בפעם הבאה), ועוד מה שההחזרה שלו נכשלה (עדיין מושעה — ננסה להחזיר שוב).
     */
    fun nextSuspended(block: Set<String>, prev: Set<String>, failOn: List<String>, failOff: List<String>): Set<String> =
        (block - failOn.toSet()) + (failOff.toSet() intersect prev)

    /** האם לשלוח דוח: השתנה, או שהקודם כבר בן יותר מ-6 שעות (כדי שלא ייחשב ישן בשרת). */
    fun shouldSend(lastHash: String, hash: String, lastAtMs: Long, nowMs: Long): Boolean =
        hash != lastHash || nowMs - lastAtMs > 6 * 3600_000L || nowMs < lastAtMs
}
