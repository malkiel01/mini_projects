// אפליקציית כרטיסי הברכה: WebView על דף הכלי, עם גשר ל-WhatsApp ולאנשי הקשר.
//
// בזמן בנייה (מ-.github/workflows/greeting-cards-apk.yml):
//   -PwebUrl=…          כתובת הכלי
//   -PversionCode=N     עולה בכל בנייה — כל העלאה לחנות חייבת מספר גבוה מהקודם
//   חתימה: GC_KEYSTORE_FILE + ANDROID_KEYSTORE_PASSWORD (מפתח ההעלאה, alias "upload")

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.mbeplus.greetingcards"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.mbeplus.greetingcards"
        minSdk = 24
        targetSdk = 36
        versionCode = ((findProperty("versionCode") as String?) ?: "1").toInt()
        versionName = "3.2"

        // כתובת האתר נקבעת בזמן בנייה
        buildConfigField("String", "WEB_URL",
            "\"${project.findProperty("webUrl") ?: "https://mbe-plus.com/mini_projects/greeting-cards/editor/"}\"")
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }

    signingConfigs {
        create("upload") {
            val file = System.getenv("GC_KEYSTORE_FILE")
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
            if (System.getenv("GC_KEYSTORE_FILE") != null) signingConfig = signingConfigs.getByName("upload")
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

dependencies {
    implementation("androidx.core:core-ktx:1.16.0")
    implementation("androidx.appcompat:appcompat:1.7.1")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.webkit:webkit:1.14.0")
}
