/* רכיב מוכן: לשוניות (kit 1.0.0) — משפר בלבד. בלי הקובץ הזה: הראשונה פתוחה, וכל קישור פותח את הלוח שלו (:target).
   כאן: is-ready על השורש, is-active על הפריט הפתוח, aria-expanded, חיצים במקלדת. עובד עם כל מספר פריטים. */
(() => {
  const init = root => {
    if (root.kolTabs) return;
    root.kolTabs = true;
    const items = () => [...root.querySelectorAll(':scope > .kol-tabs__item')];
    const open = (it, focus) => {
      for (const x of items()) {
        const on = x === it, t = x.querySelector('.kol-tabs__tab'), p = x.querySelector('.kol-tabs__panel');
        x.classList.toggle('is-active', on);
        if (t) { t.setAttribute('aria-expanded', String(on)); if (p && p.id) t.setAttribute('aria-controls', p.id); }
      }
      if (focus) { const t = it.querySelector('.kol-tabs__tab'); if (t) t.focus(); }
    };
    const hash = location.hash && items().find(x => { const p = x.querySelector('.kol-tabs__panel'); return p && p.id && '#' + p.id === location.hash; });
    open(hash || items()[0]);
    root.classList.add('is-ready');
    root.addEventListener('click', e => {
      const t = e.target.closest('.kol-tabs__tab'); if (!t || !root.contains(t)) return;
      e.preventDefault(); open(t.closest('.kol-tabs__item'));
    });
    root.addEventListener('keydown', e => {
      const t = e.target.closest('.kol-tabs__tab'); if (!t) return;
      const list = items(), i = list.indexOf(t.closest('.kol-tabs__item')), rtl = getComputedStyle(root).direction === 'rtl';
      const next = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1, ArrowDown: 1, ArrowUp: -1, Home: -i, End: list.length - 1 - i }[e.key];
      if (next === undefined) return;
      e.preventDefault(); open(list[(i + next + list.length) % list.length], true);
    });
  };
  const all = () => document.querySelectorAll('.kol-tabs').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
