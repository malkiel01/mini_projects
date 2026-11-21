/* רכיב מוכן: התראה (kit 1.0.0) — משפר בלבד: ✕ סוגר את ההודעה (is-closed). בלי הקובץ — ההודעה פשוט מוצגת. */
(() => {
  const init = root => {
    if (root.kolAlert) return;
    root.kolAlert = true;
    root.classList.add('is-ready');
    const b = root.querySelector('.kol-alert__close');
    if (b) b.addEventListener('click', () => root.classList.add('is-closed'));
  };
  const all = () => document.querySelectorAll('.kol-alert').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
