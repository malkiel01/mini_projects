/**
 * עיצוב רסטר נפתח גם בלי רשת: הדפים, הקוד, המנוע והגופנים נשמרים במטמון.
 *
 * רשת קודם — מה שחוזר מעדכן את המטמון, והמטמון רק כשהרשת נכשלת. כך אחרי פריסה מקבלים את הגרסה החדשה מיד.
 * api.php לא נשמר: הספרייה תמיד מהשרת. העבודה עצמה נשמרת בטיוטה במכשיר (IndexedDB) גם בלי רשת.
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'raster-studio-v22';

const SHELL = [
  './index.html',
  './editor.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/icon.svg',
  './vendor/fonts/fonts.css',
  './assets/js/app.js',
  './assets/js/editor.js',
  './assets/js/api.js',
  './assets/js/idb.js',
  './assets/js/tools.js',
  './assets/js/layers-panel.js',
  './assets/js/adjust-panel.js',
  './assets/js/fx-panel.js',
  './assets/js/ai-panel.js',
  './assets/js/account-ui.js',
  './assets/js/ui-core/account.js',
  './assets/js/ui-core/files-window.js',
  './assets/js/layer-ai.js',
  './assets/js/drives/drives.js',
  './assets/js/overlay.js',
  './assets/js/export.js',
  './assets/js/draft.js',
  './assets/js/engine/doc.js',
  './assets/js/engine/view.js',
  './assets/js/engine/budget.js',
  './assets/js/engine/history.js',
  './assets/js/engine/webgl2.js',
  './assets/js/engine/webgpu.js',
  './assets/js/engine/fill.js',
  './assets/js/engine/selection.js',
  './assets/js/engine/transform.js',
  './assets/js/engine/text.js',
  './assets/js/engine/png.js',
  './assets/js/engine/zip.js',
  './assets/js/engine/unzip.js',
  './assets/js/engine/formats.js',
  './assets/js/engine/vmask.js',
  './assets/js/engine/warp.js',
  './assets/js/mbe-io.js',
  './assets/js/mbe/handoff.js',
  './assets/css/ui-core/ui.css',
  './assets/js/ui-core/dialog.js',
  './assets/js/ui-core/dom.js',
  './assets/js/ui-core/files.js',
  './assets/js/ui-core/gestures.js',
  './assets/js/ui-core/handles.js',
  './assets/js/ui-core/snap.js',
  './assets/js/ui-core/transform.js',
  './assets/js/ui-core/keys.js',
  './assets/js/ui-core/menu.js',
  './assets/js/ui-core/store.js',
  './assets/js/ui-core/tabs.js',
  './assets/js/ui-core/toast.js',
  './assets/js/ui-core/viewport.js',
  './assets/js/mbe/mbe.js',
  './assets/js/mbe/canonical.js',
  './assets/js/mbe/schema.js',
  './assets/js/mbe/schemas.js',
  './assets/js/mbe/zip.js',
  './assets/js/mbe/vendor/fflate.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('raster-studio-') && k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const scope = new URL(self.registration.scope).pathname;
  if (!url.pathname.startsWith(scope) || url.pathname.endsWith('/api.php')) return;
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
      }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: url.pathname.endsWith('.html') }).then((hit) => hit || Promise.reject(new Error('offline')))),
  );
});
