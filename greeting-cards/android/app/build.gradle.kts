plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.mbeplus.greetingcards"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.mbeplus.greetingcards"
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"

        // כתובת האתר נקבעת בזמן בנייה
        buildConfigField("String", "WEB_URL",
            "\"${project.findProperty("webUrl") ?: "https://mbe-plus.com/mini_projects/greeting-cards/"}\"")
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

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.webkit:webkit:1.11.0")
}
