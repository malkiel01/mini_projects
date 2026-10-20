// יומן אבחון — מה שרק הדף יודע, לשורה ביומן של הפלטפורמה (platform/lib/diag.php; המנהל: ⚙️ ← 📜 יומן).
// השער מוסיף את הסקריפט לכל דף של אפליקציה מחוברת (lib/pwa.php), והדף הראשי של הפלטפורמה טוען אותו.
// באיזו "עטיפה" הדף פתוח: דפדפן רגיל, PWA מותקן (display-mode), APK — WebView ("; wv)") או TWA
// (document.referrer = android-app://<חבילה> בפתיחה, ונזכר לכל אורך החלון). לא משנה שום דבר בדף.
(() => {
    const me = document.currentScript;
    const APP = me?.dataset.app || 'platform';
    const API = me?.dataset.api || 'platform/api.php';
    const UA = navigator.userAgent;

    function device() {
        try {
            let id = localStorage.getItem('platform:device');
            if (!/^[a-f0-9]{32}$/.test(id || '')) {
                id = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
                localStorage.setItem('platform:device', id);
            }
            return id;
        } catch { return null; }
    }

    const mode = ['fullscreen', 'standalone', 'minimal-ui', 'window-controls-overlay', 'browser']
        .find(m => matchMedia(`(display-mode: ${m})`).matches) || '?';
    let opener = '';
    try {
        if (document.referrer.startsWith('android-app://')) sessionStorage.setItem('platform:opener', document.referrer);
        opener = sessionStorage.getItem('platform:opener') || '';
    } catch { /* */ }
    const wrapper = /; wv\)/.test(UA) ? 'webview'
        : opener ? 'android-app' : mode !== 'browser' ? `installed:${mode}` : 'browser';

    function send(event, info = {}) {
        const body = JSON.stringify({
            event, app: APP, device: device(),
            path: location.pathname + location.search + location.hash, referrer: document.referrer,
            info: { wrapper, mode, opener, standalone: navigator.standalone ?? null, ...info },
        });
        try {
            fetch(`${API}?action=diag`, {
                method: 'POST', keepalive: true, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Platform': '1' }, body,
            }).catch(() => {});
        } catch { /* */ }
    }

    window.PlatformDiag = { log: send };
    send('page', {
        sw: Boolean(navigator.serviceWorker?.controller),
        history: history.length,
        nav: performance.getEntriesByType?.('navigation')?.[0]?.type || '',
    });
})();
