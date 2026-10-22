package com.mbeplus.carpentry;

import android.app.Application;

/** מתזמן את הדופק היומי לכלים הדיגיטליים (PlatformHeartbeat). תקלה — בלי דופק, האפליקציה עובדת כרגיל. */
public class PlatformApp extends Application {
    @Override
    public void onCreate() {
        super.onCreate();
        try {
            PlatformHeartbeat.schedule(this);
        } catch (Throwable ignored) {
            // לעולם לא מפיל את האפליקציה
        }
    }
}
