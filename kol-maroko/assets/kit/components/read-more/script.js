/* רכיב מוכן: קרא עוד (kit 1.0.0) — משפר בלבד: מקפל לגובה של המשתנה ‎--kol-more-h, ופותח / סוגר. טקסט קצר מהגובה — בלי כפתור. */
(() => {
  const init = root => {
    if (root.kolMore) return;
    root.kolMore = true;
    const body = root.querySelector('.kol-more__body'), btn = root.querySelector('.kol-more__btn');
    if (!body || !btn) return;
    root.classList.add('is-ready');
    const fit = () => { if (!root.classList.contains('is-open')) root.classList.toggle('is-short', body.scrollHeight <= body.clientHeight + 2); };
    fit(); addEventListener('resize', fit);
    btn.addEventListener('click', () => { const on = root.classList.toggle('is-open'); btn.setAttribute('aria-expanded', String(on)); if (!on) root.scrollIntoView({ block: 'nearest' }); });
  };
  const all = () => document.querySelectorAll('.kol-more').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
