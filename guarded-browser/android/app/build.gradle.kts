plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.mbeplus.guardedbrowser"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.mbeplus.guardedbrowser"
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"

        // כתובת השרת נקבעת בזמן בנייה. שינוי דומיין אינו דורש נגיעה בקוד.
        buildConfigField("String", "API_BASE",
            "\"${project.findProperty("apiBase") ?: "https://mbe-plus.com/mini_projects/guarded-browser/"}\"")
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }

    buildTypes {
        release {
            isMinifyEnabled = false
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
