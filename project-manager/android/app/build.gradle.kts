// הכתובת של האתר (-PwebUrl), מספר הגרסה (-PversionCode) והחתימה (-PkeystoreFile …) מגיעים מה-workflow
// (.github/workflows/project-manager-apk.yml). בלי חתימה — APK בחתימת debug, לבדיקה בלבד.
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val webUrl = (findProperty("webUrl") as String?) ?: "https://mbe-plus.com/mini_projects/project-manager/"
val vCode = ((findProperty("versionCode") as String?) ?: "1").toInt()
val ksFile = findProperty("keystoreFile") as String?

android {
    namespace = "com.mbeplus.projectmanager"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.mbeplus.projectmanager"
        minSdk = 24
        targetSdk = 34
        versionCode = vCode
        versionName = "1.$vCode"
        buildConfigField("String", "WEB_URL", "\"$webUrl\"")
    }

    signingConfigs {
        if (ksFile != null) create("release") {
            storeFile = file(ksFile)
            storePassword = findProperty("keystorePassword") as String
            keyAlias = "pm"
            keyPassword = findProperty("keystorePassword") as String
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = if (ksFile != null) signingConfigs.getByName("release") else signingConfigs.getByName("debug")
        }
    }

    buildFeatures { buildConfig = true }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }

    // הסמל — אותו קובץ של האתר (../assets/icon-maskable-512.png), מועתק לבנייה; לא מוחזק פעמיים בריפו
    sourceSets["main"].res.srcDir(layout.buildDirectory.dir("generated/pm-icon"))
}

val copyIcon by tasks.registering(Copy::class) {
    from(rootProject.file("../assets/icon-maskable-512.png")) { rename { "pm_icon.png" } }
    into(layout.buildDirectory.dir("generated/pm-icon/drawable-nodpi"))
}
tasks.named("preBuild") { dependsOn(copyIcon) }

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.webkit:webkit:1.11.0")
    implementation("androidx.sharetarget:sharetarget:1.2.0")
}
