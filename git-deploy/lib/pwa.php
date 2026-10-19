<?php
/**
 * התגים ב-<head> שהופכים את git-deploy לאפליקציה שאפשר להתקין (PWA):
 * ה-manifest, צבע הכותרת, האייקון לאייפון, ורישום ה-service worker.
 * ה-manifest, ה-service worker והאייקונים ב-"public" של app.json — הדפדפן מבקש אותם בלי עוגייה.
 */
function pwa_head() {
    echo '<link rel="manifest" href="manifest.webmanifest">' . "\n"
       . '<meta name="theme-color" content="#1a1a2e">' . "\n"
       . '<link rel="icon" href="assets/icon.svg" type="image/svg+xml">' . "\n"
       . '<link rel="apple-touch-icon" href="assets/icons/icon-180.png">' . "\n"
       . "<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(function () {});</script>\n";
}
