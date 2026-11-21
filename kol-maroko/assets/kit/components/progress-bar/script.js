/* רכיב מוכן: פס התקדמות (kit 1.0.0) — משפר בלבד: מתחיל ריק (is-ready) ומתמלא כשנכנס למסך (is-in). */
(() => {
  const init = root => {
    if (root.kolProg) return;
    root.kolProg = true;
    if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    root.classList.add('is-ready');
    const io = new IntersectionObserver(es => { if (es.some(e => e.isIntersecting)) { io.disconnect(); requestAnimationFrame(() => root.classList.add('is-in')); } }, { threshold: 0.3 });
    io.observe(root);
  };
  const all = () => document.querySelectorAll('.kol-progress').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
