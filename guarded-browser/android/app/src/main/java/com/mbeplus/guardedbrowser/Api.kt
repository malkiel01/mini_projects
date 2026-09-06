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

    /** הכתובת שאליה פנינו — כדי שכשל יצביע על היעד ולא רק על עצמו. */
    private fun hostOf(): String =
        try { java.net.URI(BuildConfig.API_BASE).host ?: BuildConfig.API_BASE }
        catch (e: Exception) { BuildConfig.API_BASE }

    private fun base() = BuildConfig.API_BASE.trimEnd('/') + "/api/index.php?do="

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
                    json.optBoolean("ok") -> Result(true, json, "")
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

    /** שולח את רישום האבחון לשרת, לצפייה בפאנל. */
    fun trace(token: String, label: String, body: String, cb: (Result) -> Unit) =
        call("trace", JSONObject().apply {
            put("label", label)
            put("body", body)
            put("device", "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}")
            put("sdk", android.os.Build.VERSION.SDK_INT)
        }, token, cb)

    fun heartbeat(token: String, seconds: Int, sessionSec: Int, cb: (Result) -> Unit) =
        call("heartbeat", JSONObject().apply {
            put("seconds", seconds); put("session_sec", sessionSec)
        }, token, cb)
}
