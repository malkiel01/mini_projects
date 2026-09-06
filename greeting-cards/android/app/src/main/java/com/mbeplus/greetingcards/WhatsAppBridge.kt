package com.mbeplus.greetingcards

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.provider.Settings
import android.util.Base64
import android.util.Log
import android.webkit.JavascriptInterface
import android.widget.Toast
import org.json.JSONObject
import java.io.File

/**
 * JavaScript bridge — injected into the WebView as `window.GreetingCardsAndroid`.
 * Receives recipient data + images from the web app and starts the send queue.
 */
class WhatsAppBridge(private val context: Context) {

    companion object {
        private const val TAG = "WhatsAppBridge"
    }

    @JavascriptInterface
    fun isWhatsAppAvailable(): Boolean {
        return isPackageInstalled("com.whatsapp") || isPackageInstalled("com.whatsapp.w4b")
    }

    @JavascriptInterface
    fun getWhatsAppPackages(): String {
        val packages = mutableListOf<Map<String, String>>()
        if (isPackageInstalled("com.whatsapp")) {
            packages.add(mapOf("packageName" to "com.whatsapp", "label" to "WhatsApp"))
        }
        if (isPackageInstalled("com.whatsapp.w4b")) {
            packages.add(mapOf("packageName" to "com.whatsapp.w4b", "label" to "WhatsApp Business"))
        }
        return org.json.JSONArray(packages.map { JSONObject(it) }).toString()
    }

    @JavascriptInterface
    fun isAccessibilityServiceEnabled(): Boolean {
        return WhatsAppSendAccessibilityService.instance != null
    }

    @JavascriptInterface
    fun openAccessibilitySettings() {
        try {
            val intent = Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(intent)
        } catch (e: Exception) {
            Log.e(TAG, "Cannot open accessibility settings", e)
        }
    }

    /**
     * Called from JavaScript with a JSON payload:
     * {
     *   "recipients": [{ "name": "...", "phone": "...", "imageBase64": "...", "imageFormat": "jpg" }],
     *   "whatsappPackage": "com.whatsapp",
     *   "delaySeconds": 3
     * }
     */
    @JavascriptInterface
    fun startWhatsAppSend(payloadJson: String) {
        try {
            val payload = JSONObject(payloadJson)
            val recipients = payload.getJSONArray("recipients")
            val whatsappPackage = payload.optString("whatsappPackage", "com.whatsapp")
            val delaySec = payload.optInt("delaySeconds", 3)

            // Ensure cache directory exists
            val cacheDir = File(context.cacheDir, "whatsapp_cards")
            if (!cacheDir.exists()) cacheDir.mkdirs()

            // Build send items
            val items = mutableListOf<SendQueueManager.SendItem>()

            for (i in 0 until recipients.length()) {
                val r = recipients.getJSONObject(i)
                val name = r.getString("name")
                val phone = r.getString("phone")
                val base64 = r.getString("imageBase64")
                val format = r.optString("imageFormat", "jpg")

                // Decode Base64 to file
                val imageBytes = Base64.decode(base64, Base64.DEFAULT)
                val imageFile = File(cacheDir, "card_${i}_${System.currentTimeMillis()}.$format")
                imageFile.writeBytes(imageBytes)

                items.add(SendQueueManager.SendItem(
                    recipientName = name,
                    phoneNumber = phone,
                    imageFile = imageFile
                ))
            }

            if (items.isEmpty()) {
                showToast("אין נמענים לשליחה")
                return
            }

            // Request notification permission on Android 13+
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                if (context is MainActivity) {
                    context.requestNotificationPermission()
                }
            }

            // Start the foreground service
            val serviceIntent = Intent(context, SendForegroundService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(serviceIntent)
            } else {
                context.startService(serviceIntent)
            }

            // Give service time to start, then begin sending
            android.os.Handler(android.os.Looper.getMainLooper()).postDelayed({
                SendForegroundService.instance?.startSending(items, whatsappPackage, delaySec * 1000L)
                    ?: showToast("שגיאה בהפעלת שירות השליחה")
            }, 500)

            showToast("מתחיל שליחה ל-${items.size} נמענים...")

        } catch (e: Exception) {
            Log.e(TAG, "Error starting WhatsApp send", e)
            showToast("שגיאה: ${e.message}")
        }
    }

    private fun isPackageInstalled(packageName: String): Boolean {
        return try {
            context.packageManager.getPackageInfo(packageName, 0)
            true
        } catch (e: PackageManager.NameNotFoundException) {
            false
        }
    }

    private fun showToast(message: String) {
        android.os.Handler(android.os.Looper.getMainLooper()).post {
            Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
        }
    }
}
