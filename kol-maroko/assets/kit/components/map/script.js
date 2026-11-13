/* רכיב מוכן: מפה (kit 1.0.0) — משפר בלבד: לחיצה על התמונה ← iframe של Google Maps (output=embed, בלי מפתח) לפי data-kol-q
   ו-data-kol-zoom, בארגז חול. הקישור "פתח במפות" נשאר תמיד. */
(() => {
  const init = root => {
    if (root.kolMap) return;
    root.kolMap = true;
    const b = root.querySelector('.kol-map__show'), q = (root.getAttribute('data-kol-q') || '').trim(); if (!b || !q) return;
    root.classList.add('is-ready');
    b.addEventListener('click', () => {
      const z = Math.min(20, Math.max(1, parseInt(root.getAttribute('data-kol-zoom') || '15', 10) || 15));
      const f = document.createElement('iframe');
      f.className = 'kol-map__frame';
      f.src = 'https://www.google.com/maps?q=' + encodeURIComponent(q) + '&z=' + z + '&output=embed';
      f.title = (root.querySelector('.kol-map__label') || b).textContent.trim();
      f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups');
      f.referrerPolicy = 'no-referrer-when-downgrade';
      f.loading = 'lazy';
      b.replaceWith(f);
    });
  };
  const all = () => document.querySelectorAll('.kol-map').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
