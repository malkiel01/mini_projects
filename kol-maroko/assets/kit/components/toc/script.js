/* רכיב מוכן: תוכן עניינים (kit 1.0.0) — משפר בלבד: מסמן (is-here) את השורה של הכותרת שהגולש קורא, וקופץ ברכות. */
(() => {
  const init = root => {
    if (root.kolToc) return;
    root.kolToc = true;
    const rows = [...root.querySelectorAll('.kol-toc__item')].map(li => { const a = li.querySelector('.kol-toc__link'), raw = a && (a.getAttribute('href') || '').replace(/^#/, ''); let id = raw; try { id = raw && decodeURIComponent(raw); } catch { /* href כמו "#%" — כמו שהוא */ } return { li, h: id ? document.getElementById(id) : null }; }).filter(r => r.h);
    if (!rows.length || !('IntersectionObserver' in window)) return;
    const seen = new Set();
    const io = new IntersectionObserver(es => {
      for (const e of es) { if (e.isIntersecting) seen.add(e.target); else seen.delete(e.target); }
      const top = rows.find(r => seen.has(r.h));
      for (const r of rows) r.li.classList.toggle('is-here', !!top && r === top);
    }, { rootMargin: '0px 0px -60% 0px' });
    rows.forEach(r => io.observe(r.h));
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) root.addEventListener('click', e => { const a = e.target.closest('.kol-toc__link'); const r = a && rows.find(x => x.li.contains(a)); if (!r) return; e.preventDefault(); r.h.scrollIntoView({ behavior: 'smooth' }); history.replaceState(null, '', a.getAttribute('href')); });
  };
  const all = () => document.querySelectorAll('.kol-toc').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
