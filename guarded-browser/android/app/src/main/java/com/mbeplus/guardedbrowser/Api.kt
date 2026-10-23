package com.mbeplus.guardedbrowser

import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

/**
 * פנייה לשרת. ‏HttpURLConnection בלבד — בלי ספריות חיצוניות.
 *
 * כל קריאה רצה ב-thread נפרד ומחזירה לראשי, כי אנדרואיד אוסר רשת
 * ב-thread הראשי ובצדק.
 */
object Api {

    private val pool = Executors.newFixedThreadPool(2)
    private val main = android.os.Handler(android.os.Looper.getMainLooper())

    class Result(val ok: Boolean, val json: JSONObject?, val error: String)

    private var store: Store? = null
    @Volatile private var root: String = BuildConfig.API_BASE
    @Volatile private var moving = ""

    /** נקרא פעם אחת מ-GuardApp: הכתובת השמורה גוברת על זו שנצרבה בבנייה. */
    fun init(s: Store) {
        store = s
        val saved = s.apiBase
        if (isHttps(saved)) root = saved
    }

    /** הכתובת שאליה פנינו — כדי שכשל יצביע על היעד ולא רק על עצמו. */
    private fun hostOf(): String =
        try { java.net.URI(root).host ?: root }
        catch (e: Exception) { root }

    private fun base() = endpoint(root)

    /** כתובת השרת הנוכחית (עם / בסוף) — לאפליקציית ההורה שנפתחת מתוך האפליקציה. */
    fun root(): String = root.trimEnd('/') + "/"

    /**
     * שרת שה-QR של ההורה הצביע עליו. רק לפני צימוד (אין אסימון), ורק https —
     * אחרי הצימוד הכתובת משתנה רק דרך server_url מהשרת עצמו, אחרי ping.
     */
    fun useServer(url: String): Boolean {
        val target = url.trimEnd('/') + "/"
        if (!isHttps(target) || store?.isLoggedIn == true) return false
        root = target
        store?.apiBase = target
        return true
    }

    private fun endpoint(r: String) = r.trimEnd('/') + "/api/index.php?do="

    private fun isHttps(u: String) =
        u.startsWith("https://") && (try { java.net.URI(u).host } catch (e: Exception) { null }) != null

    /**
     * השרת הודיע על כתובת קבועה חדשה (server_url).
     *
     * לא עוברים בעיוורון: קודם שואלים את הכתובת החדשה ב-ping אם היא
     * באמת מגדלור. כתובת שגויה בהגדרות השרת הייתה אחרת מנתקת כל
     * מכשיר בבת אחת — ומכשיר נעול אי אפשר לתקן מרחוק.
     */
    private fun maybeMove(next: String) {
        val target = next.trimEnd('/') + "/"
        if (!isHttps(target) || target == root.trimEnd('/') + "/" || target == moving) return
        moving = target
        pool.execute {
            var conn: HttpURLConnection? = null
            val ok = try {
                conn = (URL(endpoint(target) + "ping").openConnection() as HttpURLConnection).apply {
                    connectTimeout = 10_000
                    readTimeout = 10_000
                    setRequestProperty("Accept", "application/json")
                }
                val text = conn.inputStream.bufferedReader(Charsets.UTF_8).use(BufferedReader::readText)
                val j = JSONObject(text)
                j.optBoolean("ok") && j.optString("app") == "migdalor"
            } catch (e: Exception) { false } finally { conn?.disconnect() }

            if (ok) {
                Trace.log("api.move", root + " -> " + target)
                root = target
                store?.apiBase = target
            } else {
                Trace.log("api.move.fail", target)
            }
            moving = ""
        }
    }

    /**
     * ‏POST עם JSON. token ריק = בקשה לא מזוהה (הרשמה, כניסה).
     *
     * שגיאת רשת אינה זהה לסירוב מהשרת: הראשונה משאירה את המשתמש עם
     * המדיניות השמורה, השנייה מחליפה אותה. הקורא מבדיל לפי ok.
     */
    fun call(action: String, body: JSONObject, token: String = "", cb: (Result) -> Unit) {
        pool.execute {
            var conn: HttpURLConnection? = null
            val res = try {
                conn = (URL(base() + action).openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    connectTimeout = 12_000
                    readTimeout = 15_000
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json; charset=utf-8")
                    setRequestProperty("Accept", "application/json")
                    if (token.isNotEmpty()) setRequestProperty("Authorization", "Bearer $token")
                    // הגרסה — כדי שההורה יראה מה מותקן, ויוכל לבקש עדכון.
                    setRequestProperty("X-Migdalor-App", BuildConfig.VERSION_CODE.toString())
                    // ההרשאות שניתנו במכשיר (מסלול ההקמה) — כדי שההורה יראה מה חסר.
                    setRequestProperty("X-Migdalor-Perms", (store?.perms ?: 0).toString())
                }
                conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }

                val code = conn.responseCode
                // גוף השגיאה נושא את הסיבה בעברית; בלעדיו נציג "שגיאה" סתמית.
                val stream = if (code in 200..299) conn.inputStream else conn.errorStream
                val text = stream?.bufferedReader(Charsets.UTF_8)
                    ?.use(BufferedReader::readText) ?: ""

                val json = try { JSONObject(text) } catch (e: Exception) { null }
                when {
                    json == null -> {
                        Trace.log("api.badjson", action + " HTTP " + code + ", " + text.length + "b")
                        Result(false, null, "תשובה לא תקינה מהשרת (HTTP " + code + ")")
                    }
                    json.optBoolean("ok") -> {
                        val next = json.optString("server_url")
                        if (next.isNotEmpty()) maybeMove(next)
                        Result(true, json, "")
                    }
                    else -> Result(false, json, json.optString("error", "הבקשה נדחתה"))
                }
            } catch (e: Exception) {
                /*
                 * שם החריגה, לא רק "אין חיבור".
                 *
                 * ‏UnknownHostException, SSLException, SocketTimeout
                 * ו-"CLEARTEXT not permitted" הן תקלות שונות לגמרי
                 * שדורשות פעולות שונות — ו"אין חיבור לשרת" מכסה את
                 * כולן באותה מידה של חוסר תועלת.
                 */
                val why = when (e) {
                    is java.net.UnknownHostException -> "לא נמצא השרת (DNS או אין רשת)"
                    is java.net.SocketTimeoutException -> "השרת לא ענה בזמן"
                    is javax.net.ssl.SSLException -> "בעיית אבטחה בחיבור (SSL)"
                    is java.net.ConnectException -> "לא ניתן להתחבר לשרת"
                    else -> e.javaClass.simpleName + ": " + (e.message ?: "")
                }
                Trace.log("api.fail", action + " -> " + why)
                Result(false, null, why + "\n" + hostOf())
            } finally {
                conn?.disconnect()
            }
            main.post { cb(res) }
        }
    }

    fun register(username: String, password: String, name: String, email: String,
                 cb: (Result) -> Unit) =
        call("register", JSONObject().apply {
            put("username", username); put("password", password)
            put("name", name); put("email", email)
        }, cb = cb)

    fun login(username: String, password: String, deviceName: String, deviceId: String,
              cb: (Result) -> Unit) =
        call("login", JSONObject().apply {
            put("username", username); put("password", password)
            put("device_name", deviceName); put("device_id", deviceId)
        }, cb = cb)

    /** צימוד בקוד מאפליקציית ההורה — במקום שם משתמש וסיסמה. */
    fun pair(code: String, deviceName: String, deviceId: String, cb: (Result) -> Unit) =
        call("pair", JSONObject().apply {
            put("code", code); put("device_name", deviceName); put("device_id", deviceId)
        }, cb = cb)

    fun policy(token: String, cb: (Result) -> Unit) =
        call("policy", JSONObject(), token, cb)

    /**
     * ‏clientAllowed הוא מה שהאפליקציה עצמה החליטה.
     *
     * השרת משווה: אם האפליקציה התירה משהו שהוא אוסר, האכיפה במכשיר
     * נעקפה — וזו ההתרעה. לקוח שנפרץ לגמרי פשוט לא ישלח את השדה,
     * ולכן זו אינה ראיה; אבל פריצה חלקית נתפסת כאן מיד.
     */
    fun check(token: String, url: String, mainFrame: Boolean,
              clientAllowed: Boolean? = null, cb: (Result) -> Unit) =
        call("check", JSONObject().apply {
            put("url", url); put("main_frame", if (mainFrame) "1" else "0")
            if (clientAllowed != null) put("client_allowed", clientAllowed)
        }, token, cb)

    /** אילו מהסרטונים שבדף יוטיוב מערוץ מאושר — לסינון ההצעות (שלב 5). */
    fun ytAllowed(token: String, ids: List<String>, cb: (Result) -> Unit) =
        call("yt_allowed", JSONObject().apply { put("ids", org.json.JSONArray(ids)) }, token, cb)

    /** שולח את רישום האבחון לשרת, לצפייה בפאנל. */
    fun trace(token: String, label: String, body: String, cb: (Result) -> Unit) =
        call("trace", JSONObject().apply {
            put("label", label)
            put("body", body)
            put("device", "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}")
            put("sdk", android.os.Build.VERSION.SDK_INT)
        }, token, cb)

    /** בקש גישה (שלב 6): ההורה מקבל התראה. ‏note — "למה?", לא חובה. */
    fun request(token: String, url: String, note: String, cb: (Result) -> Unit) =
        call("request", JSONObject().apply { put("url", url); put("note", note) }, token, cb)

    /** הבקשות של הילד מהיממה האחרונה, עם המצב שלהן. */
    fun requests(token: String, cb: (Result) -> Unit) =
        call("requests", JSONObject(), token, cb)

    /** ‏url — מה שעל המסך עכשיו: ההורה רואה כמה זמן בכל אתר (שלב 7), לפי רמת הדיווח שבחר. */
    /** דיווח האפליקציות המותקנות (שלב 11), כדי שההורה יכריע עליהן. */
    fun apps(token: String, list: org.json.JSONArray, cb: (Result) -> Unit) =
        call("apps", JSONObject().apply { put("apps", list) }, token, cb)

    /** מצב העדכון (שלב 9) — ההורה רואה עדכון שנכשל או נתקע. בלי תשובה נדרשת. */
    fun updateState(token: String, state: String, target: Int, error: String = "") =
        call("update_state", JSONObject().apply {
            put("state", state); put("target", target); put("error", error.take(120))
        }, token) {}

    /** בקשת פתיחה לאפליקציה חסומה (2026-10-22). */
    fun appRequest(token: String, pkg: String, note: String, cb: (Result) -> Unit) =
        call("app_request", JSONObject().apply { put("package", pkg); put("note", note) }, token, cb)

    /** בקשה מהמרקט: אפליקציה מהמלאי שעוד לא שוחררה (lib/apprequests.php, marketRequestCreate). */
    fun marketRequest(token: String, pkg: String, note: String, cb: (Result) -> Unit) =
        call("market_request", JSONObject().apply { put("package", pkg); put("note", note) }, token, cb)

    /** אישור ביצוע: ההחלטה הוחלה במכשיר (או נכשלה, עם הסיבה). ‏endsMs — סוף היתר זמני כפי שהמכשיר חישב. */
    fun appRequestApplied(token: String, id: Int, ok: Boolean, error: String, endsMs: Long, cb: (Result) -> Unit = {}) =
        call("app_request_applied", JSONObject().apply {
            put("id", id); put("ok", ok); put("error", error.take(160)); put("ends_ms", endsMs)
        }, token, cb)

    /** חיפוש (שלב 8): השרת בוחר מנוע לפי הילד ומסנן כל תוצאה. */
    fun search(token: String, q: String, cb: (Result) -> Unit) =
        call("search", JSONObject().apply { put("q", q) }, token, cb)

    /** דוח האכיפה: מה הוחל ומה נכשל (EnforceReport). */
    fun enforceReport(token: String, body: JSONObject, cb: (Result) -> Unit) = call("enforce_report", body, token, cb)

    /** תוצאות בדיקת ההגנה היזומה (SelfTest) — נתונים גולמיים; השרת פוסק. */
    fun selftest(token: String, body: JSONObject, cb: (Result) -> Unit) = call("selftest", body, token, cb)

    /** מצב למידה ביוטיוב: מה הסקריפט בדף אימת (lib/ytlearn.php). */
    fun ytLearnReport(token: String, body: JSONObject, cb: (Result) -> Unit) = call("yt_learn_report", body, token, cb)

    /** זמן מסך: הזמן שנצבר היום לכל אפליקציה, בשניות (lib/apptime.php). */
    fun appUsage(token: String, day: String, apps: Map<String, Long>, cb: (Result) -> Unit) =
        call("app_usage", JSONObject().put("day", day).put("apps", JSONObject(apps.mapValues { it.value / 1000 } as Map<*, *>)), token, cb)

    fun heartbeat(token: String, seconds: Int, sessionSec: Int, url: String = "",
                  batches: org.json.JSONArray? = null, cb: (Result) -> Unit) =
        call("heartbeat", JSONObject().apply {
            put("seconds", seconds); put("session_sec", sessionSec); put("url", url)
            // מנות שימוש עם מזהה (UsageMeter) — השרת סופר כל אחת פעם אחת.
            if (batches != null) put("batches", batches)
        }, token, cb)
}
