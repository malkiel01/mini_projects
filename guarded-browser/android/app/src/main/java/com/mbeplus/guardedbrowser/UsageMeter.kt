package com.mbeplus.guardedbrowser

import android.os.SystemClock
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

/**
 * מונה השימוש של המכשיר — נשמר מקומית, כך שסגירה בכוח, פעימה שנכשלה או ניתוק אינם מוחקים זמן.
 *
 *   ‏tick — כל 5 שניות כשהדפדפן פתוח: מוסיף את מה שעבר (UsageMath.step) ל"פתוח" של היום, ולישיבה.
 *   ‏batches — לפני פעימה: ה"פתוח" הופך למנות עם מזהה, ונשמר עד שהשרת מאשר (acked). מנה שנשלחה שוב
 *   אחרי כישלון נושאת אותו מזהה, והשרת סופר אותה פעם אחת (lib/heartbeat.php).
 *   ‏usedToday — מה שהשרת ספר + מה שעוד לא אושר: המכסה נאכפת גם כשהשרת לא זמין.
 */
object UsageMeter {

    private var lastMark = 0L

    fun start(store: Store) {
        lastMark = SystemClock.elapsedRealtime()
        val now = TrustedClock.now(store)
        if (UsageMath.sessionReset(store.sessionLast, now)) store.sessionMs = 0
        store.sessionLast = now
    }

    fun stop(store: Store) { tick(store); lastMark = 0 }

    fun tick(store: Store) {
        val nowE = SystemClock.elapsedRealtime()
        val ms = UsageMath.step(lastMark, nowE)
        lastMark = nowE
        if (ms <= 0) return
        val now = TrustedClock.now(store)
        val day = UsageMath.dayKey(now, store.policy().timezone)
        val open = obj(store.usageOpen)
        open.put(day, open.optLong(day) + ms)
        store.usageOpen = open.toString()
        store.sessionMs = store.sessionMs + ms
        store.sessionLast = now
    }

    /** משך הישיבה הנוכחית בשניות — נמשכת גם אחרי סגירה ופתיחה (UsageMath.sessionReset). */
    fun sessionSec(store: Store): Int = (store.sessionMs / 1000).toInt()

    /** המנות לשליחה: ה"פתוח" (שניות שלמות) הופך למנות חדשות, יחד עם אלה שעוד לא אושרו. */
    fun batches(store: Store): JSONArray {
        val open = obj(store.usageOpen)
        val inflight = arr(store.usageInflight)
        val left = JSONObject()
        for (day in open.keys()) {
            val ms = open.optLong(day)
            val sec = ms / 1000
            if (sec > 0) inflight.put(JSONObject().put("id", UUID.randomUUID().toString()).put("day", day).put("sec", sec))
            if (ms % 1000 > 0) left.put(day, ms % 1000)
        }
        store.usageOpen = left.toString()
        store.usageInflight = inflight.toString()
        return inflight
    }

    /** תשובת הפעימה: מנות שאושרו נמחקות; מה שהשרת ספר להיום נשמר. */
    fun ack(store: Store, acked: JSONArray?, serverUsed: Int, serverDay: String) {
        val ok = (0 until (acked?.length() ?: 0)).map { acked!!.optString(it) }.toSet()
        val inflight = arr(store.usageInflight)
        val keep = JSONArray()
        for (i in 0 until inflight.length()) inflight.optJSONObject(i)?.let { if (it.optString("id") !in ok) keep.put(it) }
        store.usageInflight = keep.toString()
        if (serverDay.isNotEmpty()) { store.usageServerUsed = serverUsed; store.usageServerDay = serverDay }
    }

    /** השימוש היום לפי מה שידוע כאן — השרת + מה שעוד לא אושר. */
    fun usedToday(store: Store): Int {
        val today = UsageMath.dayKey(TrustedClock.now(store), store.policy().timezone)
        var unsynced = obj(store.usageOpen).optLong(today) / 1000
        val inflight = arr(store.usageInflight)
        for (i in 0 until inflight.length()) inflight.optJSONObject(i)?.let { if (it.optString("day") == today) unsynced += it.optLong("sec") }
        return UsageMath.usedToday(store.usageServerUsed, store.usageServerDay, today, unsynced.toInt())
    }

    private fun obj(s: String) = try { JSONObject(s) } catch (e: Exception) { JSONObject() }
    private fun arr(s: String) = try { JSONArray(s) } catch (e: Exception) { JSONArray() }
}

/** זמן אמין (UsageMath.trustedNow): עוגן משעון השרת + elapsedRealtime. */
object TrustedClock {
    fun anchor(store: Store, serverMs: Long) {
        if (serverMs <= 0) return
        store.clockServerMs = serverMs
        store.clockElapsed = SystemClock.elapsedRealtime()
    }

    fun now(store: Store): Long = UsageMath.trustedNow(store.clockServerMs, store.clockElapsed,
        SystemClock.elapsedRealtime(), System.currentTimeMillis())
}
