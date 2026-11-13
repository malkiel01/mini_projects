/* רכיב מוכן: חזרה לראש (kit 1.0.1) — משפר בלבד: מופיע אחרי גלילה של data-kol-after פיקסלים; לחיצה — גלילה רכה לראש. */
(() => {
  const init = root => {
    if (root.kolTop) return;
    root.kolTop = true;
    const after = parseInt(root.getAttribute('data-kol-after') || '400', 10) || 0;
    root.classList.add('is-ready');
    // לא לכסות: כשמתחת לכפתור (במקומו הרגיל) יש קישור / כפתור / שדה של העמוד — למשל חץ של קרוסלה בפינה — הכפתור עולה מעליו
    const HIT = 'a[href], button, input, select, textarea, summary, [role="button"], [tabindex]:not([tabindex="-1"])';
    const room = () => {
      const lift = parseFloat(root.style.getPropertyValue('--kol-top-lift')) || 0, r = root.getBoundingClientRect();
      if (!root.classList.contains('is-shown') || !r.width) { root.style.removeProperty('--kol-top-lift'); return; }
      const top = r.top + lift, bottom = r.bottom + lift, pts = [[r.left + r.width / 2, top + r.height / 2], [r.left + 6, top + 6], [r.right - 6, top + 6], [r.left + 6, bottom - 6], [r.right - 6, bottom - 6]];
      let need = 0;
      for (const [x, y] of pts) for (const el of document.elementsFromPoint(x, y)) {
        if (el === root || root.contains(el)) continue;
        const hit = el.closest(HIT); if (!hit) continue;
        need = Math.max(need, bottom - hit.getBoundingClientRect().top + 8); break;
      }
      need = Math.min(need, innerHeight * 0.4);
      if (need > 0) root.style.setProperty('--kol-top-lift', Math.round(need) + 'px'); else root.style.removeProperty('--kol-top-lift');
    };
    let raf = 0;
    const draw = () => { root.classList.toggle('is-shown', scrollY > after); cancelAnimationFrame(raf); raf = requestAnimationFrame(room); };
    draw(); addEventListener('scroll', draw, { passive: true }); addEventListener('resize', draw, { passive: true });
    root.addEventListener('click', e => { e.preventDefault(); scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); const f = document.querySelector('h1, main, body'); if (f) { f.setAttribute('tabindex', '-1'); f.focus({ preventScroll: true }); } });
  };
  const all = () => document.querySelectorAll('.kol-top').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
