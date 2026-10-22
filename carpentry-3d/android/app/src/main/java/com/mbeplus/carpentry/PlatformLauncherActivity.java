package com.mbeplus.carpentry;

import android.net.Uri;

import com.google.androidbrowserhelper.trusted.LauncherActivity;

/**
 * הפתיחה מהאייקון — כמו LauncherActivity של גוגל, ובכתובת גם מזהה ההתקנה (?platform-install=…), כדי שהכלים
 * הדיגיטליים יקשרו את ההתקנה הזו למשתמש ולמכשיר (platform/lib/gate.php, apk_seen). הדופק היומי
 * (PlatformHeartbeat) מדווח אחר כך באותו מזהה. כל תקלה כאן — הכתובת המקורית, בלי מזהה.
 */
public class PlatformLauncherActivity extends LauncherActivity {
    @Override
    protected Uri getLaunchingUrl() {
        Uri url = super.getLaunchingUrl();
        try {
            if (url != null && url.getQueryParameter("platform-apk") != null && url.getQueryParameter("platform-install") == null) {
                return url.buildUpon().appendQueryParameter("platform-install", PlatformHeartbeat.installId(this)).build();
            }
        } catch (Throwable ignored) {
            // לעולם לא מפיל את הפתיחה
        }
        return url;
    }
}
