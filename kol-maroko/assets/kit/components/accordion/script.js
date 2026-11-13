/* רכיב מוכן: אקורדיון (kit 1.0.0) — משפר בלבד. בלי הקובץ הזה כל פריט נפתח ונסגר לבד (<details>).
   כאן: במצב "one" (המחלקה kol-acc--one) — פתיחת פריט סוגרת את האחרים. עובד עם כל מספר פריטים. */
(() => {
  const init = root => {
    if (root.kolAcc) return;
    root.kolAcc = true;
    root.addEventListener('toggle', e => {
      const it = e.target;
      if (!it.open || !it.classList.contains('kol-acc__item') || !root.classList.contains('kol-acc--one')) return;
      for (const o of root.querySelectorAll(':scope > .kol-acc__item[open]')) if (o !== it) o.open = false;
    }, true);
  };
  const all = () => document.querySelectorAll('.kol-acc').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
