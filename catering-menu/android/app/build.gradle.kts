// תפריט הקייטרינג "ניחוחות" לחנות של גוגל — Trusted Web Activity (TWA).
//
// הקוד שלנו כאן מצומצם: PlatformLauncherActivity (LauncherActivity של androidbrowserhelper, עם מזהה ההתקנה בכתובת)
// ו-PlatformHeartbeat (דופק יומי לכלים הדיגיטליים). את המסך עצמו פותח LauncherActivity של גוגל
// את האתר ב-Chrome, במסך מלא, בלי שורת כתובת — בתנאי שהאתר מאשר את האפליקציה
// ב-/.well-known/assetlinks.json (Digital Asset Links). בלי האישור — נפתח כלשונית
// Chrome רגילה עם שורת כתובת. כל עדכון באתר מגיע לאפליקציה מיד, בלי גרסה חדשה בחנות.
//
// בזמן בנייה (מ-.github/workflows/catering-menu-twa.yml):
//   -PappHost=mbe-plus.com                      הדומיין (שם, בשורש, יושב assetlinks.json)
//   -PappPath=/mini_projects/catering-menu/     התיקייה של האפליקציה בדומיין. על הדומיין יושבים
//                                               עוד הרבה כלים — האפליקציה פותחת ותופסת קישורים רק כאן
//   -PversionCode=N                   עולה בכל העלאה לחנות
//   חתימה: ANDROID_KEYSTORE_FILE + ANDROID_KEYSTORE_PASSWORD (מפתח ההעלאה, alias "upload")

plugins {
    id("com.android.application")
}

val appHost = (findProperty("appHost") as String?) ?: "mbe-plus.com"
val appPath = (findProperty("appPath") as String?) ?: "/mini_projects/catering-menu/"
val launchUrl = "https://$appHost$appPath"
val appVersionCode = ((findProperty("versionCode") as String?) ?: "1").toInt()
// הפתיחה מהאייקון מודיעה לכלים הדיגיטליים "מותקנת, גרסה N" (platform/lib/gate.php, apk_seen) — השער רושם ומסיר את הפרמטר
val launchUrlWithVersion = "$launchUrl?platform-apk=$appVersionCode"

android {
    namespace = "com.mbeplus.catering"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.mbeplus.catering"
        minSdk = 24   // androidbrowserhelper 2.7.4 דורש 24 (Android 7.0)
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionCode.toString()   // כרום מחזיר אותו ב-getInstalledRelatedApps

        resValue("string", "hostName", appHost)
        resValue("string", "launchUrl", launchUrlWithVersion)
        resValue("string", "pathPrefix", appPath)
        resValue("string", "providerAuthority", "com.mbeplus.catering.fileprovider")
        // האישור ההדדי: האפליקציה מצהירה על האתר, והאתר (assetlinks.json) על האפליקציה
        resValue("string", "assetStatements",
            "[{\\\"relation\\\": [\\\"delegate_permission/common.handle_all_urls\\\"], " +
            "\\\"target\\\": {\\\"namespace\\\": \\\"web\\\", \\\"site\\\": \\\"https://$appHost\\\"}}]")
    }

    signingConfigs {
        create("upload") {
            val file = System.getenv("ANDROID_KEYSTORE_FILE")
            if (file != null) {
                storeFile = file(file)
                storePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                keyAlias = "upload"
                keyPassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (System.getenv("ANDROID_KEYSTORE_FILE") != null) signingConfig = signingConfigs.getByName("upload")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.7.4")
    implementation("androidx.work:work-runtime:2.9.1")   // הדופק היומי לכלים הדיגיטליים (PlatformHeartbeat)
}
