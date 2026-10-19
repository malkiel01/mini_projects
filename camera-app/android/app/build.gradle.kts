// אפליקציית המצלמות לחנות של גוגל — Trusted Web Activity (TWA).
//
// אין כאן קוד משלנו: LauncherActivity של androidbrowserhelper (ספריית גוגל) פותח
// את האתר ב-Chrome, במסך מלא, בלי שורת כתובת — בתנאי שהאתר מאשר את האפליקציה
// ב-/.well-known/assetlinks.json (Digital Asset Links). בלי האישור — נפתח כלשונית
// Chrome רגילה עם שורת כתובת. כל עדכון באתר מגיע לאפליקציה מיד, בלי גרסה חדשה בחנות.
//
// בזמן בנייה (מ-.github/workflows/camera-app-twa.yml):
//   -PappHost=mbe-plus.com                      הדומיין (שם, בשורש, יושב assetlinks.json)
//   -PappPath=/mini_projects/camera-app/        התיקייה של האפליקציה בדומיין. על הדומיין יושבים
//                                               עוד הרבה כלים — האפליקציה פותחת ותופסת קישורים רק כאן
//   -PversionCode=N                   עולה בכל העלאה לחנות
//   חתימה: CAMERA_KEYSTORE_FILE + CAMERA_KEYSTORE_PASSWORD (מפתח ההעלאה, alias "upload")

plugins {
    id("com.android.application")
}

val appHost = (findProperty("appHost") as String?) ?: "mbe-plus.com"
val appPath = (findProperty("appPath") as String?) ?: "/mini_projects/camera-app/"
val launchUrl = "https://$appHost$appPath"

android {
    namespace = "com.mbeplus.camera"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.mbeplus.camera"
        minSdk = 24   // androidbrowserhelper 2.7.4 דורש 24 (Android 7.0)
        targetSdk = 36
        versionCode = ((findProperty("versionCode") as String?) ?: "1").toInt()
        versionName = "1.0"

        resValue("string", "hostName", appHost)
        resValue("string", "launchUrl", launchUrl)
        resValue("string", "pathPrefix", appPath)
        resValue("string", "providerAuthority", "com.mbeplus.camera.fileprovider")
        // האישור ההדדי: האפליקציה מצהירה על האתר, והאתר (assetlinks.json) על האפליקציה
        resValue("string", "assetStatements",
            "[{\\\"relation\\\": [\\\"delegate_permission/common.handle_all_urls\\\"], " +
            "\\\"target\\\": {\\\"namespace\\\": \\\"web\\\", \\\"site\\\": \\\"https://$appHost\\\"}}]")
    }

    signingConfigs {
        create("upload") {
            val file = System.getenv("CAMERA_KEYSTORE_FILE")
            if (file != null) {
                storeFile = file(file)
                storePassword = System.getenv("CAMERA_KEYSTORE_PASSWORD")
                keyAlias = "upload"
                keyPassword = System.getenv("CAMERA_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (System.getenv("CAMERA_KEYSTORE_FILE") != null) signingConfig = signingConfigs.getByName("upload")
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
