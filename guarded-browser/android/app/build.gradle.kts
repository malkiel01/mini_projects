// מספר הגרסה (-PversionCode) והחתימה (-PkeystoreFile, -PkeystorePassword) מגיעים מה-workflow
// (.github/workflows/android.yml). בלי חתימה — APK בחתימת debug, לבדיקה בלבד.
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val ksFile = findProperty("keystoreFile") as String?
val buildNo = (findProperty("versionCode") as String?)?.toIntOrNull() ?: 1

android {
    namespace = "com.mbeplus.guardedbrowser"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.mbeplus.guardedbrowser"
        minSdk = 24
        targetSdk = 34
        // עולה בכל בנייה (מספר ההרצה ב-Actions) — אחרת אנדרואיד מסרב לעדכן.
        versionCode = buildNo
        versionName = "1.$buildNo"

        // כתובת השרת נקבעת בזמן בנייה. שינוי דומיין אינו דורש נגיעה בקוד.
        buildConfigField("String", "API_BASE",
            "\"${project.findProperty("apiBase") ?: "https://mbe-plus.com/mini_projects/guarded-browser/"}\"")
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }

    /*
     * חתימה קבועה: עדכון מותקן מעל הגרסה הקודמת רק אם החתימה זהה.
     * חתימת debug נוצרת מחדש בכל מכונת בנייה — ולכן כל APK היה "אפליקציה
     * אחרת" שדורשת הסרה. המפתח עצמו — מוצפן בריפו, ראו android.yml.
     */
    signingConfigs {
        if (ksFile != null) create("release") {
            storeFile = file(ksFile)
            storePassword = findProperty("keystorePassword") as String
            keyAlias = "mg"
            keyPassword = findProperty("keystorePassword") as String
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = if (ksFile != null) signingConfigs.getByName("release")
                            else signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

/*
 * בלי ספריות רשת חיצוניות: HttpURLConnection ו-org.json נמצאים
 * בפלטפורמה. פחות תלויות פירושו בנייה שלא נשברת ו-APK קטן.
 */
dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    testImplementation("junit:junit:4.13.2")
}
