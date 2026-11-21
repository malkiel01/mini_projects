/* רכיב מוכן: ספירה לאחור (kit 1.0.0) — משפר בלבד: data-kol-until (תאריך ISO) ← המספרים בכל שנייה; בסוף — is-done. */
(() => {
  const init = root => {
    if (root.kolCd) return;
    root.kolCd = true;
    const end = Date.parse(root.getAttribute('data-kol-until') || '');
    if (!isFinite(end)) return;
    const n = u => root.querySelector('[data-kol-unit="' + u + '"]'), pad = v => String(v).padStart(2, '0');
    root.classList.add('is-ready');
    let t = 0;
    const tick = () => {
      const left = Math.max(0, end - Date.now()), s = Math.floor(left / 1000);
      const v = { d: Math.floor(s / 86400), h: Math.floor(s / 3600) % 24, m: Math.floor(s / 60) % 60, s: s % 60 };
      for (const u in v) { const e = n(u); if (e) e.textContent = pad(v[u]); }
      if (!left) { root.classList.add('is-done'); clearInterval(t); }
    };
    tick(); t = setInterval(tick, 1000);
  };
  const all = () => document.querySelectorAll('.kol-countdown').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
