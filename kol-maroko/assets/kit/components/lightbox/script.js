/* רכיב מוכן: גלריה עם lightbox (kit 1.0.0) — משפר בלבד: לחיצה על תמונה ← <dialog> עם התמונה הגדולה (href של הקישור),
   הכיתוב — ה-alt שבתבנית; חיצים (גם במקלדת), החלקה במגע, Esc. עובד עם כל מספר תמונות. */
(() => {
  const init = root => {
    if (root.kolLb) return;
    root.kolLb = true;
    const dlg = root.querySelector('.kol-lightbox__dlg'); if (!dlg || !dlg.showModal) return;
    const big = dlg.querySelector('.kol-lightbox__big'), cap = dlg.querySelector('.kol-lightbox__cap');
    const items = () => [...root.querySelectorAll('.kol-lightbox__item')];
    let i = 0, x0 = null;
    const show = k => {
      const list = items(); if (!list.length) return;
      i = (k + list.length) % list.length;
      const a = list[i], im = a.querySelector('img');
      if (big) { big.src = a.getAttribute('href') || (im && im.src) || ''; big.alt = im ? im.alt : ''; }
      if (cap) cap.textContent = im ? im.alt : '';
    };
    const rtl = () => getComputedStyle(root).direction === 'rtl';
    root.addEventListener('click', e => { const a = e.target.closest('.kol-lightbox__item'); if (!a || !root.contains(a)) return; e.preventDefault(); show(items().indexOf(a)); dlg.showModal(); });
    dlg.querySelector('.kol-lightbox__prev')?.addEventListener('click', () => show(i - 1));
    dlg.querySelector('.kol-lightbox__next')?.addEventListener('click', () => show(i + 1));
    dlg.querySelector('.kol-lightbox__close')?.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('keydown', e => { if (e.key === 'ArrowLeft') show(i + (rtl() ? 1 : -1)); else if (e.key === 'ArrowRight') show(i + (rtl() ? -1 : 1)); });
    dlg.addEventListener('pointerdown', e => { x0 = e.clientX; });
    dlg.addEventListener('pointerup', e => { if (x0 == null) return; const d = e.clientX - x0; x0 = null; if (Math.abs(d) > 50) show(i + ((d < 0) !== rtl() ? 1 : -1)); });
  };
  const all = () => document.querySelectorAll('.kol-lightbox').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
