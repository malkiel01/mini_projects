// אפליקציית האנדרואיד של ניהול הפרויקטים — עטיפה דקה לאתר, עם שיתוף ישיר לתיקיות האחרונות.
// README של האפליקציה: ../README.md, "אפליקציית אנדרואיד".
pluginManagement {
    repositories { google(); mavenCentral(); gradlePluginPortal() }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories { google(); mavenCentral() }
}
rootProject.name = "project-manager"
include(":app")
