/* רכיב מוכן: הטמעת פוסט (kit 1.0.0) — משפר בלבד: לחיצה ← iframe של data-kol-embed (רק https), בארגז חול. בלי הקובץ — הקישור לפוסט. */
(() => {
  const init = a => {
    if (a.kolSembed) return;
    a.kolSembed = true;
    a.addEventListener('click', e => {
      let u; try { u = new URL(a.getAttribute('data-kol-embed') || '', location.href); } catch { return; }
      if (u.protocol !== 'https:') return;
      e.preventDefault();
      const f = document.createElement('iframe');
      f.className = 'kol-sembed__frame';
      f.src = u.href;
      f.title = (a.querySelector('.kol-sembed__label') || a).textContent.trim();
      f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-presentation');
      f.referrerPolicy = 'strict-origin-when-cross-origin';
      f.loading = 'lazy';
      a.replaceWith(f);
    });
  };
  const all = () => document.querySelectorAll('.kol-sembed__link').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
