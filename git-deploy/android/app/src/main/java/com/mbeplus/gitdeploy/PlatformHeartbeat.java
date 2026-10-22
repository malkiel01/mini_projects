package com.mbeplus.gitdeploy;

import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Build;

import androidx.annotation.NonNull;
import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;
import androidx.work.Worker;
import androidx.work.WorkerParameters;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.util.concurrent.TimeUnit;

/**
 * דופק יומי לכלים הדיגיטליים (platform/apk.php; האפיון "גרסאות וחשבונות", סעיף 3.4): "אני מותקנת, גרסה N",
 * ואילו מאפליקציות האנדרואיד שלנו מותקנות עוד במכשיר ובאיזו גרסה (רשומות ב-<queries> במניפסט). כך הפלטפורמה
 * יודעת גם על הסרה — מהדופק של אפליקציה אחרת שנשארה. רק מזהה ההתקנה, שמות החבילות והגרסאות; לא מידע אחר.
 */
public class PlatformHeartbeat extends Worker {
    private static final String WORK = "platform-heartbeat";
    private static final String PREFS = "platform";
    /** האפליקציות שלנו — אותה רשימה כמו related_applications במניפסט של הפלטפורמה ו-<queries> כאן. */
    static final String[] PACKAGES = {
        "com.mbeplus.camera", "com.mbeplus.carpentry", "com.mbeplus.catering", "com.mbeplus.claudetasks",
        "com.mbeplus.dataembed", "com.mbeplus.dbanalyzer", "com.mbeplus.doctemplates", "com.mbeplus.filemerger",
        "com.mbeplus.framebrowser", "com.mbeplus.gitdeploy", "com.mbeplus.greetingcards", "com.mbeplus.guardedbrowser",
        "com.mbeplus.orgbudget", "com.mbeplus.pdfmerger", "com.mbeplus.projectmanager", "com.mbeplus.punchlist",
        "com.mbeplus.recipes", "com.mbeplus.sketches3d", "com.mbeplus.sukkah", "com.mbeplus.wascanner",
    };

    public PlatformHeartbeat(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    static void schedule(Context ctx) {
        // הראשון אחרי 2 דקות: קודם הפתיחה מהאייקון קושרת את מזהה ההתקנה למשתמש (בלי זה הדופק לא מוכר לשרת)
        PeriodicWorkRequest req = new PeriodicWorkRequest.Builder(PlatformHeartbeat.class, 24, TimeUnit.HOURS)
            .setConstraints(new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setInitialDelay(2, TimeUnit.MINUTES)
            .build();
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.UPDATE, req);
    }

    /** מזהה קבוע להתקנה הזו — נוצר בפעם הראשונה, ונמחק עם הסרת האפליקציה. */
    static synchronized String installId(Context ctx) {
        SharedPreferences p = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String id = p.getString("install", null);
        if (id == null || !id.matches("[a-f0-9]{32}")) {
            byte[] b = new byte[16];
            new SecureRandom().nextBytes(b);
            StringBuilder sb = new StringBuilder();
            for (byte x : b) sb.append(String.format("%02x", x));
            id = sb.toString();
            p.edit().putString("install", id).apply();
        }
        return id;
    }

    @NonNull
    @Override
    public Result doWork() {
        try {
            Context ctx = getApplicationContext();
            PackageManager pm = ctx.getPackageManager();
            JSONArray apps = new JSONArray();
            for (String pkg : PACKAGES) {
                try {
                    PackageInfo info = pm.getPackageInfo(pkg, 0);
                    long code = Build.VERSION.SDK_INT >= 28 ? info.getLongVersionCode() : info.versionCode;
                    apps.put(new JSONObject().put("p", pkg).put("v", code));
                } catch (PackageManager.NameNotFoundException ignored) {
                    // לא מותקנת
                }
            }
            PackageInfo self = pm.getPackageInfo(ctx.getPackageName(), 0);
            JSONObject body = new JSONObject()
                .put("install", installId(ctx))
                .put("package", ctx.getPackageName())
                .put("version", Build.VERSION.SDK_INT >= 28 ? self.getLongVersionCode() : self.versionCode)
                .put("apps", apps);
            // https://<host>/mini_projects/ — מכתובת הפתיחה, בלי התיקייה של האפליקציה
            String launch = ctx.getString(R.string.launchUrl);
            String path = ctx.getString(R.string.pathPrefix);
            String base = launch.substring(0, launch.indexOf(path)) + path.replaceAll("[^/]+/$", "");
            HttpURLConnection c = (HttpURLConnection) new URL(base + "platform/apk.php").openConnection();
            c.setConnectTimeout(15000);
            c.setReadTimeout(15000);
            c.setRequestMethod("POST");
            c.setDoOutput(true);
            c.setRequestProperty("Content-Type", "application/json");
            try (OutputStream out = c.getOutputStream()) {
                out.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
            int status = c.getResponseCode();
            c.disconnect();
            // 204 — השרת עוד לא מכיר את המזהה (האפליקציה עוד לא נפתחה מהאייקון אצל משתמש מחובר): שוב, בהמתנה הולכת וגדלה
            if (status == 204 && getRunAttemptCount() < 8) return Result.retry();
        } catch (java.io.IOException e) {
            if (getRunAttemptCount() < 8) return Result.retry();   // בלי רשת — שוב בעוד זמן קצר
        } catch (Throwable ignored) {
            // תקלה אחרת — הדופק הבא
        }
        return Result.success();
    }
}
