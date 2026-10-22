package com.mbeplus.guardedbrowser

/**
 * כרום מנוהל (lib/chrome.php) — המדיניות שכרום מקבל, טהורה ובלי Android (ChromeMathTest).
 *
 * שמות המפתחות והערכים — מדיניות ארגונית רשמית של Chrome (chromeenterprise.google/policies):
 *   ‏URLBlocklist / URLAllowlist — רשימות כתובות. ‏ForceGoogleSafeSearch — חיפוש בטוח בכוח.
 *   ‏SafeSitesFilterBehavior=1 — סינון תוכן למבוגרים של גוגל. ‏IncognitoModeAvailability=1 — בלי גלישה בסתר.
 *   ‏ForceYouTubeRestrict=1 — "מצב מוגבל" ביוטיוב (כשיוטיוב פתוח בכרום).
 */
object ChromeMath {

    fun spec(block: List<String>, allow: List<String>, youtube: Boolean): Map<String, Any> = buildMap {
        put("URLBlocklist", block)
        put("URLAllowlist", allow)
        put("ForceGoogleSafeSearch", true)
        put("SafeSitesFilterBehavior", 1)
        put("IncognitoModeAvailability", 1)
        if (youtube) put("ForceYouTubeRestrict", 1)
    }

    /** מה שהמערכת מחזיקה עכשיו לכרום תואם את המדיניות — כל מפתח, באותו ערך. */
    fun matches(spec: Map<String, Any>, actual: Map<String, Any?>): Boolean =
        spec.all { (k, v) -> actual[k] == v }
}
