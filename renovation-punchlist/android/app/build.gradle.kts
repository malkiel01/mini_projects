// השלמות לשיפוץ לחנות של גוגל — Trusted Web Activity (TWA).
//
// אין כאן קוד משלנו: LauncherActivity של androidbrowserhelper (ספריית גוגל) פותח
// את האתר ב-Chrome, במסך מלא, בלי שורת כתובת — בתנאי שהאתר מאשר את האפליקציה
// ב-/.well-known/assetlinks.json (Digital Asset Links). בלי האישור — נפתח כלשונית
// Chrome רגילה עם שורת כתובת. כל עדכון באתר מגיע לאפליקציה מיד, בלי גרסה חדשה בחנות.
//
// בזמן בנייה (מ-.github/workflows/renovation-punchlist-twa.yml):
//   -PappHost=mbe-plus.com                      הדומיין (שם, בשורש, יושב assetlinks.json)
//   -PappPath=/mini_projects/renovation-punchlist/ התיקייה של האפליקציה בדומיין. על הדומיין יושבים
//                                               עוד הרבה כלים — האפליקציה פותחת ותופסת קישורים רק כאן
//   -PversionCode=N                   עולה בכל העלאה לחנות
//   חתימה: PUNCHLIST_KEYSTORE_FILE + PUNCHLIST_KEYSTORE_PASSWORD (מפתח ההעלאה, alias "upload")

plugins {
    id("com.android.application")
}

val appHost = (findProperty("appHost") as String?) ?: "mbe-plus.com"
val appPath = (findProperty("appPath") as String?) ?: "/mini_projects/renovation-punchlist/"
val launchUrl = "https://$appHost$appPath"

android {
    namespace = "com.mbeplus.punchlist"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.mbeplus.punchlist"
        minSdk = 24   // androidbrowserhelper 2.7.4 דורש 24 (Android 7.0)
        targetSdk = 36
        versionCode = ((findProperty("versionCode") as String?) ?: "1").toInt()
        versionName = "1.0"

        resValue("string", "hostName", appHost)
        resValue("string", "launchUrl", launchUrl)
        resValue("string", "pathPrefix", appPath)
        resValue("string", "providerAuthority", "com.mbeplus.punchlist.fileprovider")
        // האישור ההדדי: האפליקציה מצהירה על האתר, והאתר (assetlinks.json) על האפליקציה
        resValue("string", "assetStatements",
            "[{\\\"relation\\\": [\\\"delegate_permission/common.handle_all_urls\\\"], " +
            "\\\"target\\\": {\\\"namespace\\\": \\\"web\\\", \\\"site\\\": \\\"https://$appHost\\\"}}]")
    }

    signingConfigs {
        create("upload") {
            val file = System.getenv("PUNCHLIST_KEYSTORE_FILE")
            if (file != null) {
                storeFile = file(file)
                storePassword = System.getenv("PUNCHLIST_KEYSTORE_PASSWORD")
                keyAlias = "upload"
                keyPassword = System.getenv("PUNCHLIST_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (System.getenv("PUNCHLIST_KEYSTORE_FILE") != null) signingConfig = signingConfigs.getByName("upload")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.7.4")
}
