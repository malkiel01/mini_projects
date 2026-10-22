package com.mbeplus.guardedbrowser

import android.content.Context
import org.json.JSONObject

/**
 * החלת מדיניות — מקום אחד לכל מסלולי הסנכרון (מסך הבית, הדפדפן, UpdateJob, שירות הנגישות, קוד מההורה).
 *
 * סקירה (2026-10-20): כל מסלול החיל חלק אחר — הדפדפן החליף אובייקטים בלי לבדוק מחדש את הדף שעל המסך
 * ובלי לעדכן חסימת צילום מסך, ושחרור והארכה טופלו רק במסך הבית. כאן, בכל תשובת מדיניות:
 *   שמירה (אם אינה ישנה) → שחרור/הארכה → הגדרות המכשיר והאפליקציות (Enforcer) → עדכון חובה →
 *   הודעה למסכים הפתוחים (onChanged), שמחילים על עצמם: הדפדפן בודק מחדש את הדף, עוצר תוכן שנאסר
 *   ומעדכן FLAG_SECURE.
 */
object PolicySync {

    private val listeners = mutableSetOf<() -> Unit>()

    /** מסך פתוח שצריך להחיל מדיניות חדשה על עצמו. נקרא בתהליך הראשי. */
    fun listen(l: () -> Unit) { listeners.add(l) }
    fun unlisten(l: () -> Unit) { listeners.remove(l) }

    /** ‏true — התשובה הוחלה; ‏false — ישנה מזו שכבר יש, ונזרקה. */
    fun apply(ctx: Context, payload: JSONObject, source: String): Boolean {
        val store = Store(ctx)
        val seq = payload.optLong("issued_at", 0L)
        if (SyncRules.isStale(store.policySeq, seq)) {
            Trace.log("sync.stale", "$source seq=$seq last=${store.policySeq}")
            return false
        }
        store.savePolicy(payload)
        if (seq > 0) { store.policySeq = seq; TrustedClock.anchor(store, seq) }
        store.lastSyncMs = TrustedClock.now(store)
        applyRelease(ctx, store, payload)
        // בקשות פתיחה לאפליקציות: היתרים זמניים לפני ההחלה (כדי שההשעיה תוסר), אישור ביצוע אחריה.
        val apps = payload.optJSONObject("apps")
        AppGrants.reconcile(store, apps)
        if (!store.released) Enforcer.apply(ctx, store)
        AppGrants.confirm(ctx, store, apps)
        Updater.note(ctx, payload.optJSONObject("update"))
        // בדיקת הגנה שההורה ביקש — אחרי ההחלה, כדי לבדוק את מה שבאמת חל עכשיו.
        if (!store.released) SelfTest.maybeRun(ctx, store, payload.optJSONObject("selftest"))
        Trace.log("sync.apply", "$source seq=$seq rev=${store.rev}")
        changed()
        return true
    }

    /** שינוי שלא הגיע כתשובת מדיניות מלאה (קוד מההורה) — רק הודעה למסכים. */
    fun changed() { listeners.toList().forEach { it() } }

    private fun applyRelease(ctx: Context, store: Store, payload: JSONObject) {
        val token = payload.optString("release")
        // מול הזהות שהוצמדה בצימוד ומול השעון האמין — לא מול account_id של אותה תשובה.
        val valid = token.isNotEmpty() && Release.isReleaseFor(token, store.signPubKey, store.accountId, store.childId,
                                                                TrustedClock.now(store) / 1000)
        when (SyncRules.release(store.released, token.isNotEmpty(), valid)) {
            SyncRules.Release.ENTER -> {
                store.released = true
                Enforcer.release(ctx, store)
                Trace.log("release", "acct=${store.accountId}")
            }
            SyncRules.Release.RENEW -> {
                store.released = false
                Trace.log("release.renewed", "")
            }
            SyncRules.Release.NONE -> {}
        }
    }
}
