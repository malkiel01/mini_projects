/* רכיב מוכן: תפריט נפתח (kit 1.0.0) — משפר בלבד: לחיצה מחוץ לתפריט ו-Esc סוגרים. */
(() => {
  const init = root => {
    if (root.kolDrop) return;
    root.kolDrop = true;
    document.addEventListener('click', e => { if (root.open && !root.contains(e.target)) root.open = false; });
    root.addEventListener('keydown', e => { if (e.key === 'Escape' && root.open) { root.open = false; const s = root.querySelector('summary'); if (s) s.focus(); } });
  };
  const all = () => document.querySelectorAll('.kol-drop').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
