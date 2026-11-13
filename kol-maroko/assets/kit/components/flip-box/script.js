/* רכיב מוכן: קופסה מתהפכת (kit 1.0.0) — משפר בלבד: במגע, הקשה הופכת (is-flipped); Enter / רווח במקלדת. */
(() => {
  const init = root => {
    if (root.kolFlip) return;
    root.kolFlip = true;
    const flip = () => root.classList.toggle('is-flipped');
    root.addEventListener('click', e => { if (e.target.closest('a, button')) return; if (matchMedia('(hover: none)').matches) flip(); });
    root.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target === root) { e.preventDefault(); flip(); } });
  };
  const all = () => document.querySelectorAll('.kol-flip').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
