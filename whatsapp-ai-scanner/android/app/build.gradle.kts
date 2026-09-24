plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.mbeplus.wascanner"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.mbeplus.wascanner"
        minSdk = 24
        targetSdk = 34
        versionCode = 2
        versionName = "0.2"

        // כתובת השרת נקבעת בזמן בנייה, כמו ב-guarded-browser.
        buildConfigField("String", "API_BASE",
            "\"${project.findProperty("apiBase") ?: "https://mbe-plus.com/mini_projects/whatsapp-ai-scanner/"}\"")
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }

    // ה-APK יוצא בחתימת debug ל-sideload (במפתח ה-debug האוטומטי של
    // הבנייה). כברירת מחדל, כש-minSdk >= 24, ‏AGP משמיט את חתימת v1
    // (JAR) ומשאיר רק v2. על אנדרואיד תקני v2 מספיק, אבל מתקין
    // החבילות של יצרנים מסוימים (Xiaomi/HyperOS) דוחה APK בלי v1 עם
    // "החבילה פגומה / האפליקציה לא הותקנה" — גם בהתקנה נקייה. לכן
    // מכריחים כאן v1+v2 יחד, לתאימות התקנה מרבית.
    signingConfigs {
        getByName("debug") {
            enableV1Signing = true
            enableV2Signing = true
        }
    }

    buildTypes {
        release { isMinifyEnabled = false }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

/*
 * בלי ספריות רשת חיצוניות: HttpURLConnection ו-org.json בפלטפורמה.
 * ‏security-crypto לשמירת האסימון מוצפן במכשיר.
 */
dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    testImplementation("junit:junit:4.13.2")
}
