/* רכיב מוכן: חזרה לראש (kit 1.0.0) — משפר בלבד: מופיע אחרי גלילה של data-kol-after פיקסלים; לחיצה — גלילה רכה לראש. */
(() => {
  const init = root => {
    if (root.kolTop) return;
    root.kolTop = true;
    const after = parseInt(root.getAttribute('data-kol-after') || '400', 10) || 0;
    root.classList.add('is-ready');
    const draw = () => root.classList.toggle('is-shown', scrollY > after);
    draw(); addEventListener('scroll', draw, { passive: true });
    root.addEventListener('click', e => { e.preventDefault(); scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); const f = document.querySelector('h1, main, body'); if (f) { f.setAttribute('tabindex', '-1'); f.focus({ preventScroll: true }); } });
  };
  const all = () => document.querySelectorAll('.kol-top').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
