// גשר סורק הוואטסאפ — אפליקציה נייטיב (שירות נגישות), לא WebView.
//
// בזמן בנייה (מ-.github/workflows/wascanner-apk.yml):
//   -PapiBase=…         כתובת השרת
//   -PversionCode=N     עולה בכל בנייה — כל העלאה לחנות חייבת מספר גבוה מהקודם
//   חתימה: WAS_KEYSTORE_FILE + ANDROID_KEYSTORE_PASSWORD (מפתח ההעלאה, alias "upload")

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.mbeplus.wascanner"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.mbeplus.wascanner"
        minSdk = 24
        targetSdk = 36
        versionCode = ((findProperty("versionCode") as String?) ?: "2").toInt()
        versionName = "0.3"

        // כתובת השרת נקבעת בזמן בנייה, כמו ב-guarded-browser.
        buildConfigField("String", "API_BASE",
            "\"${project.findProperty("apiBase") ?: "https://mbe-plus.com/mini_projects/whatsapp-ai-scanner/"}\"")
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }

    // חתימת v1+v2 יחד: כברירת מחדל, כש-minSdk >= 24, ‏AGP משמיט את חתימת v1
    // (JAR) ומשאיר רק v2. על אנדרואיד תקני v2 מספיק, אבל מתקין החבילות של
    // יצרנים מסוימים (Xiaomi/HyperOS) דוחה APK בלי v1 עם "החבילה פגומה /
    // האפליקציה לא הותקנה" — גם בהתקנה נקייה. לכן מכריחים כאן v1+v2 יחד,
    // לתאימות התקנה מרבית — גם ב-debug וגם במפתח ההעלאה.
    signingConfigs {
        getByName("debug") {
            enableV1Signing = true
            enableV2Signing = true
        }
        create("upload") {
            val file = System.getenv("WAS_KEYSTORE_FILE")
            if (file != null) {
                storeFile = file(file)
                storePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                keyAlias = "upload"
                keyPassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                enableV1Signing = true
                enableV2Signing = true
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (System.getenv("WAS_KEYSTORE_FILE") != null) signingConfig = signingConfigs.getByName("upload")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

kotlin {
    compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) }
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
