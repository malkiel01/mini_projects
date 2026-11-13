/* רכיב מוכן: תפריט (kit 1.0.0) — משפר בלבד: המבורגר בטלפון (is-ready, is-open), Esc ולחיצה על קישור — סוגרים;
   הקישור לעמוד הנוכחי מקבל aria-current="page". בלי הקובץ — רשימת קישורים רגילה. */
(() => {
  const init = root => {
    if (root.kolNav) return;
    root.kolNav = true;
    const btn = root.querySelector('.kol-nav__toggle');
    root.classList.add('is-ready');
    const here = location.pathname.replace(/\/index\.html?$/, '/');
    for (const a of root.querySelectorAll('.kol-nav__link')) { try { const u = new URL(a.getAttribute('href') || '', location.href); if (u.origin === location.origin && u.pathname.replace(/\/index\.html?$/, '/') === here && !u.hash) a.setAttribute('aria-current', 'page'); } catch { /* */ } }
    const set = on => { root.classList.toggle('is-open', on); if (btn) btn.setAttribute('aria-expanded', String(on)); };
    if (btn) btn.addEventListener('click', () => set(!root.classList.contains('is-open')));
    root.addEventListener('click', e => { if (e.target.closest('.kol-nav__link')) set(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && root.classList.contains('is-open')) { set(false); if (btn) btn.focus(); } });
  };
  const all = () => document.querySelectorAll('.kol-nav').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
